<?php

namespace App\Support\Voice;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

/**
 * The league voice (plan "KI-Stimme der Liga"): one post from the facts of a
 * moment, written by Claude Haiku and let through only by deterministic
 * checks. say() returns null whenever anything is off, and the caller posts
 * its own template instead (StreamBotCopy and friends); it never throws.
 *
 * null when: the channel is unknown or switched off, its daily cap or the
 * monthly soft budget is reached, the request fails (timeout, 429, 5xx,
 * unreadable answer), the answer was cut off, or a check rejects the text
 * (VoiceMentions, VoiceHygiene, VoiceGuard). Each rejection counts its
 * reason codes (VoiceUsage), never the text.
 *
 * Facts: scalars and lists, public only. Keys that name a gamer tag, a game
 * account or a Lightning address are dropped before anything else (user
 * 2026-09-30: a mention never stands next to a gamer tag). People go in
 * `people` (VoiceMentions); the model sees their names and role tokens,
 * never a key.
 *
 * State between calls lives in the cache as scalars only (the production
 * cache refuses objects, `cache.serializable_classes` = false): the canon
 * IDs and texts of the channel's last posts, for rotation and variety.
 */
final class LeagueVoice
{
    /** The VoiceUsage channel of the fact check's calls. */
    public const FACT_CHECK = 'fact_check';

    /** How many recent posts per channel the near-copy check compares with. */
    public const RECENT_TEXTS = 10;

    /** How long the per-channel memory of recent posts lasts. */
    private const RECENT_TTL_SECONDS = 7 * 86_400;

    private const PRIVATE_FACT_KEYS = '/gamer|game_?account|gametag|lud16|lightning|ln_?address|wallet|email/i';

    private ?VoicePrompt $prompt = null;

    private ?VoiceGuard $guard = null;

    public function __construct(
        private readonly VoiceCanon $canon,
        private readonly VoiceUsage $usage,
        private readonly VoiceHygiene $hygiene = new VoiceHygiene,
        private readonly VoiceMentions $mentions = new VoiceMentions,
    ) {}

    /**
     * `$options` per call: another `language` (a share post in the player's),
     * fewer `max_chars` (room for a link the caller adds), one more
     * `instruction` line for the model, and `must_contain`: fact strings the
     * text has to carry word for word.
     *
     * A text the checks turn down is written once more, with the reasons
     * (user 2026-10-11: "bei Verstoß → neu erzeugen oder Vorlage"); a second
     * rejection is null. Each try counts against the channel's daily cap.
     *
     * @param  array<array-key, mixed>  $facts
     * @param  array{language?: string, max_chars?: int, instruction?: string, must_contain?: list<string>}  $options
     */
    public function say(string $moment, array $facts, string $channel, array $options = []): ?VoiceLine
    {
        try {
            return $this->attempt($moment, $facts, $channel, $options);
        } catch (Throwable $exception) {
            // Class and channel only: an HTTP exception's message can carry the provider's response body.
            Log::warning('League voice failed', ['channel' => $channel, 'exception' => $exception::class]);

            try {
                $this->usage->recordOutcome($channel, 'failed');
            } catch (Throwable) {
                // The counter is down as well; the template goes out either way.
            }

            return null;
        }
    }

    /** The fixed, cached half of every prompt. */
    public function prompt(): VoicePrompt
    {
        return $this->prompt ??= new VoicePrompt($this->canon);
    }

    public function guard(): VoiceGuard
    {
        return $this->guard ??= new VoiceGuard(
            $this->canon,
            array_values(array_map('strval', (array) config('esports.voice.blocklist', []))),
            array_values(array_map('strval', (array) config('esports.voice.persons', []))),
        );
    }

    /**
     * The facts without anything private (see the class comment).
     *
     * @param  array<array-key, mixed>  $facts
     * @return array<array-key, mixed>
     */
    public static function publicFacts(array $facts): array
    {
        $public = [];

        foreach ($facts as $key => $value) {
            if (is_string($key) && preg_match(self::PRIVATE_FACT_KEYS, $key) === 1) {
                continue;
            }

            if (is_array($value)) {
                $public[$key] = self::publicFacts($value);
            } elseif (is_scalar($value) || $value === null) {
                $public[$key] = $value;
            }
        }

        return $public;
    }

    /**
     * @param  array<array-key, mixed>  $facts
     * @param  array{language?: string, max_chars?: int, instruction?: string, must_contain?: list<string>}  $options
     */
    private function attempt(string $moment, array $facts, string $channel, array $options): ?VoiceLine
    {
        $settings = config("esports.voice.channels.{$channel}");

        if (! is_array($settings) || ($settings['enabled'] ?? false) !== true || ! isset(VoiceCanon::MOMENTS[$moment])) {
            return null;
        }

        $rejected = [];

        // Two tries at most: the second one only after the checks turned the first down.
        for ($try = 0; $try < 2; $try++) {
            if ($this->usage->attemptsToday($channel) >= (int) ($settings['daily_cap'] ?? 0)) {
                return null;
            }

            if ($this->usage->monthCost() >= (float) config('esports.voice.monthly_budget_usd', 0)) {
                return null;
            }

            $outcome = $this->write($moment, $facts, $channel, $settings, $options, $rejected);

            if ($outcome instanceof VoiceLine) {
                return $outcome;
            }

            $rejected = $outcome;
        }

        return null;
    }

    /**
     * One try: the guarded line, or the reason codes the checks found.
     *
     * @param  array<array-key, mixed>  $facts
     * @param  array<array-key, mixed>  $settings
     * @param  array{language?: string, max_chars?: int, instruction?: string, must_contain?: list<string>}  $options
     * @param  list<string>  $rejected  the reasons of the previous try
     * @return VoiceLine|list<string>
     */
    private function write(string $moment, array $facts, string $channel, array $settings, array $options, array $rejected): VoiceLine|array
    {
        $maxChars = (int) ($options['max_chars'] ?? $settings['max_chars'] ?? 280);
        $language = (string) ($options['language'] ?? $settings['language'] ?? 'de');
        $instruction = trim(implode(' ', array_filter([
            (string) ($settings['instruction'] ?? ''),
            (string) ($options['instruction'] ?? ''),
            isset($settings['max_sentences']) ? 'Höchstens '.(int) $settings['max_sentences'].' Sätze; ein Ausruf aus einem Wort zählt nicht.' : '',
            $this->openerLine($channel),
            ($options['must_contain'] ?? []) === [] ? '' : 'Diese Fakten stehen wörtlich im Text: '.implode(' · ', array_map(fn (string $fact): string => '„'.$fact.'“', $options['must_contain'])).'.',
        ])));
        $multiline = ($settings['multiline'] ?? false) === true;
        $facts = self::publicFacts($facts);
        $modelFacts = VoiceMentions::forModel($facts);
        $canonSettings = (array) config('esports.voice.canon', []);

        $selection = $this->canon->select(
            $moment,
            $this->recent($channel, 'canon'),
            (int) ($canonSettings['min'] ?? 50),
            (int) ($canonSettings['max'] ?? 100),
            (int) ($canonSettings['token_budget'] ?? 12_000),
            $language,
        );

        $this->usage->recordAttempt($channel);

        $response = (new VoiceAgent($this->prompt()->fixedPart()))
            ->prompt($this->prompt()->variablePart($moment, $modelFacts, $channel, $maxChars, $language, $selection, $instruction, $rejected));

        $this->usage->recordTokens(
            $channel,
            $response->usage->inputTokens,
            $response->usage->outputTokens,
            (int) $response->usage->cacheReadInputTokens,
            (int) $response->usage->cacheWriteInputTokens,
        );

        $answer = $response->toArray();
        $raw = is_string($answer['text'] ?? null) ? $answer['text'] : '';
        $canonId = is_string($answer['canon_id'] ?? null) && trim($answer['canon_id']) !== '' ? trim($answer['canon_id']) : null;
        $mood = is_string($answer['mood'] ?? null) ? $answer['mood'] : '';
        $cutOff = $response->steps->last()?->finishReason === FinishReason::Length;

        $text = $this->hygiene->clean($raw, $multiline, $language);
        [$text, $canonId] = $this->repair($channel, $text, $facts, $settings, $options, $canonId);
        $named = $this->mentions->withNames($text, $facts);

        $violations = [
            ...$this->mentions->check($text, $facts),
            ...$this->hygiene->check($named, $language, $this->recent($channel, 'texts'), $this->canon->find($canonId)['wording'] ?? null, $cutOff, isset($settings['max_sentences']) ? (int) $settings['max_sentences'] : null, VoiceGuard::factStrings($modelFacts)),
            ...$this->guard()->check($named, $modelFacts, $maxChars, $canonId, $language, $options['must_contain'] ?? []),
        ];

        // Only a text every deterministic check passed is read once more, for claims the facts do not support.
        if ($violations === [] && $this->unsupportedClaims($moment, $modelFacts, $named, $this->canon->find($canonId)['wording'] ?? null) !== []) {
            $violations[] = VoiceGuard::UNSUPPORTED_CLAIM;
        }

        if ($violations !== []) {
            $this->usage->recordRejection($channel, $violations);
            $this->usage->recordOutcome($channel, 'rejected');

            return array_values(array_unique($violations));
        }

        [$posted, $tags] = $this->mentions->resolve($text, $facts);

        $this->remember($channel, 'texts', $named, self::RECENT_TEXTS);
        if ($canonId !== null) {
            $this->remember($channel, 'canon', $canonId, (int) ($canonSettings['avoid_recent'] ?? 30));
        }
        $this->usage->recordOutcome($channel, 'accepted');

        return new VoiceLine($posted, $tags, $canonId, $mood);
    }

    /**
     * The claims of the text its facts do not support (VoiceFactCheck), empty when
     * the check is switched off. Its tokens are counted under `fact_check`, so
     * they count against the monthly budget; a failed request throws, and say()
     * sends the template.
     *
     * @param  array<array-key, mixed>  $facts
     * @return list<string>
     */
    private function unsupportedClaims(string $moment, array $facts, string $text, ?string $canonWording): array
    {
        if (! (bool) config('esports.voice.fact_check.enabled', true)) {
            return [];
        }

        $this->usage->recordAttempt(self::FACT_CHECK);
        $response = (new VoiceFactCheck)->prompt(VoiceFactCheck::message($facts, $text, $canonWording, $moment, CarbonImmutable::now()));
        $this->usage->recordTokens(self::FACT_CHECK, $response->usage->inputTokens, $response->usage->outputTokens, (int) $response->usage->cacheReadInputTokens, (int) $response->usage->cacheWriteInputTokens);

        $answer = $response->toArray();

        if (! is_array($answer['claims'] ?? null)) {
            // An unreadable answer is no clearance.
            return ['unreadable fact check'];
        }

        // Every claim with its evidence; the ones it found none for (or an answer of the wrong shape) reject the text.
        $claims = [];
        foreach ($answer['claims'] as $claim) {
            if (! is_array($claim) || ($claim['supported'] ?? null) !== true) {
                $claims[] = is_array($claim) && is_string($claim['claim'] ?? null) ? $claim['claim'] : 'unreadable claim';
            }
        }
        $this->usage->recordOutcome(self::FACT_CHECK, $claims === [] ? 'accepted' : 'rejected');

        return $claims;
    }

    /**
     * The deterministic repairs before the checks (P2 yield round): a person
     * written as token and by name (VoiceMentions::repairDuplicates()), a
     * sentence too many at the end (VoiceHygiene::trimSentences()). Each
     * repair is counted under `<channel>_repaired`; what they cannot repair,
     * the checks reject. A canon entry whose wording went with a dropped
     * sentence is no longer the text's.
     *
     * @param  array<array-key, mixed>  $facts
     * @param  array<array-key, mixed>  $settings
     * @param  array{must_contain?: list<string>}  $options
     * @return array{0: string, 1: ?string}
     */
    private function repair(string $channel, string $text, array $facts, array $settings, array $options, ?string $canonId): array
    {
        $repairs = [];
        $deduplicated = $this->mentions->repairDuplicates($text, $facts, $options['must_contain'] ?? []);

        if ($deduplicated !== $text) {
            $repairs[] = VoiceMentions::REPEATED;
        }

        $trimmed = VoiceHygiene::trimSentences($deduplicated, isset($settings['max_sentences']) ? (int) $settings['max_sentences'] : null, $options['must_contain'] ?? []);

        if ($trimmed !== $deduplicated) {
            $repairs[] = VoiceHygiene::TOO_MANY_SENTENCES;
            $wording = $this->canon->find($canonId)['wording'] ?? null;

            if ($wording !== null && preg_match(VoiceGuard::wordingPattern($wording), $deduplicated) === 1 && preg_match(VoiceGuard::wordingPattern($wording), $trimmed) !== 1) {
                $canonId = null;
            }
        }

        if ($repairs !== []) {
            $this->usage->recordRejection($channel.'_repaired', $repairs);
        }

        return [$trimmed, $canonId];
    }

    /**
     * The openers the channel's last posts used, for the model to avoid
     * (VoiceHygiene rejects them): empty without recent posts.
     */
    private function openerLine(string $channel): string
    {
        $openers = [];
        foreach (array_slice($this->recent($channel, 'texts'), 0, VoiceHygiene::OPENER_POSTS) as $recent) {
            $first = preg_split('/[^\p{L}\p{N}]+/u', trim($recent), -1, PREG_SPLIT_NO_EMPTY)[0] ?? null;

            if (is_string($first)) {
                $openers[$first] = '„'.$first.'“';
            }
        }

        return $openers === [] ? '' : 'Die letzten Posts begannen mit '.implode(', ', $openers).': fang mit einem anderen Wort an, am besten mit dem Fakt.';
    }

    /**
     * @return list<string>
     */
    public function recent(string $channel, string $kind): array
    {
        $stored = Cache::get("voice:recent:{$channel}:{$kind}");

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    private function remember(string $channel, string $kind, string $value, int $keep): void
    {
        $list = array_slice([$value, ...$this->recent($channel, $kind)], 0, max(1, $keep));

        Cache::put("voice:recent:{$channel}:{$kind}", $list, self::RECENT_TTL_SECONDS);
    }
}
