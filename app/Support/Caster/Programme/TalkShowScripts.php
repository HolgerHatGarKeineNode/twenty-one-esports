<?php

namespace App\Support\Caster\Programme;

use App\Support\Caster\Blocks\ReplayArchive;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\ReactionLines;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\SpokenMemory;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceHygiene;
use App\Support\Voice\VoicePrompt;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

/**
 * Writes Turm und Genossin, der Talk (plan "TWENTY ONE Radio", P16; TalkShow)
 * in ONE model call: the duets' cached system prompt (both personas, the
 * TONE), then in the variable half the guest's persona, the dramaturgy, the
 * positions sheet and the headline (live) or the timeless topic (evergreen).
 * Markus and Sirius argue through a free-market, sound-money lens and never
 * name it (user 2026-10-13: no "Anarchokapitalist", no "wir Libertären" in
 * their lines; LABEL).
 *
 * The answer is checked like a long format (ShowScripts) and more:
 *
 * - parsed into the cast's turns (Markus, Sirius, the guest; "Gisela:" counts
 *   as hers, DuetScripts::speakerAliases()), each turn repaired to whole
 *   sentences and cut to `sentences_per_turn` (the crossfire's short turns);
 *   the evergreen version loses every line that names a time of day;
 * - longer than `seconds.max`: whole turns leave from the middle, so the cold
 *   open and the closing round stay; shorter than `seconds.min`: SHORT;
 * - SHAPE: the guest opens (the cold open), the host speaks last (the teaser),
 *   and the fact check is called ("Faktencheck"); TURNS: each of the three
 *   has `min_turns` turns at least;
 * - the free rules, the data guard (ShowScripts::factual(): no digit, number,
 *   year or foreign name outside the data), the quote guard
 *   (TalkShow::quoteViolations()) and LABEL;
 * - repeats (SpokenMemory): a first draft that repeats the station is said
 *   anew.
 *
 * Two tries, the second with the reasons; the last draft airs whatever the
 * checks found (user 2026-10-18: "Nie mehr verwerfen"), counted, and only
 * what never goes on air leaves it (ScriptGuard::airable()). Its own usage
 * channel and `daily_cap`, and the monthly budget.
 *
 * @phpstan-import-type Slot from BroadcastClock
 * @phpstan-import-type ShowMaterial from Shows
 */
class TalkShowScripts
{
    public const CHANNEL = 'caster_talkshow';

    /** No cold open by the guest, Markus does not close, or no fact check. */
    public const SHAPE = 'talkshow_shape';

    /** Markus or Sirius named their own worldview ("Anarchokapitalist", "wir Libertären"). */
    public const LABEL = 'talkshow_label';

    /** Markus or Sirius talked down to the guest as a woman ("Gnädigste", real Sonnet sample 2026-10-13): ideas are roasted, never her gender. */
    public const GENDERED = 'talkshow_gendered';

    /**
     * Fewer than `host_question_share` of the host's turns ask a question (P20; user 2026-10-13: "Und der Moderator
     * stellt keine Fragen … Der Moderator ist Lanz, und der moderiert nicht durch Fragen?").
     */
    public const HOST_QUESTIONS = 'talkshow_host_questions';

    /**
     * Reaction-only turns of the listener ("Genossin: [sigh] Ach, Herr Turm.", P20; user 2026-10-13: "Reaktionen mit sigh
     * oder Stöhnen auf Aussagen fehlen noch") a talk may have below the talk show's `min_words`.
     */
    public const MAX_REACTION_TURNS = 3;

    /**
     * The prompt's own words on air (P20 probe 2026-10-13: "laut Positionsliste" eight times in one episode, the host
     * "reads out the headline" of an evergreen episode that has none): the voices talk about the briefing instead of
     * knowing it.
     */
    public const META = 'talkshow_meta';

    /** The briefing's words the voices never say ("Material" alone stays: "Arbeit, Energie und Material" is her point). */
    private const META_WORDS = '/(?<![\p{L}])(?:positionsliste\p{L}*|positionspapier\p{L}*|gegenargumente mit quelle|wörtlich belegt|(?:im|aus dem|laut|ins|vom|laut dem) material|material (?:steht|sagt|nennt)|(?:laut|auf) (?:der |meiner |ihrer |deiner |unserer |dieser )?liste|wie es hier steht|laut unterlagen|in (?:meinen|ihren|den) unterlagen)(?![\p{L}])/iu';

    /** In an evergreen episode there is no headline to read out. */
    private const HEADLINE_WORDS = '/schlagzeile/iu';

    /** The share of a line's longer words found in one earlier line that makes it a near copy (echoes()). */
    public const ECHO_SHARE = 0.6;

    /** The condescending, gendered forms of address the hosts' side never uses for the guest. */
    private const GENDERED_WORDS = '/(?<![\p{L}])(?:gnädigste|gnädige frau|schätzchen|püppchen|mädchen|mädel|kleine dame|junge dame|süße|hysterisch\p{L}*|weibchen|(?:sagt|meint|glaubt|findet) die frau|die frau, die)(?![\p{L}])/iu';

    /** The self-labels Markus and Sirius never use (user 2026-10-13). */
    private const LABELS = '/(?<![\p{L}])(?:anarcho[\s-]*kapitalist\p{L}*|ankap\p{L}*|libertär\p{L}*|libertarian\p{L}*)/iu';

    /** @var list<string> the repeated sentences of the last draft turned down for CasterVoice::SPOKEN_REPEAT */
    private array $repeated = [];

    /** @var array{text: string, speakers: list<string>}|null the last draft evaluate() checked, turned down or not */
    private ?array $draft = null;

    public function __construct(
        private readonly CasterVoice $voice,
        private readonly DuetScripts $duets,
        private readonly VoiceUsage $usage,
        private readonly ScriptGuard $scriptGuard = new ScriptGuard,
        private readonly VoiceHygiene $hygiene = new VoiceHygiene,
    ) {}

    /**
     * The live talk show for `$slot`: the format's opening line (time and name), then the talk; null when it failed.
     *
     * @param  Slot  $slot
     * @param  ShowMaterial  $material
     * @return array{text: string, speakers: list<string>}|null
     */
    public function write(array $slot, array $material, CarbonImmutable $now): ?array
    {
        $content = $this->run($material, false, $now);

        // The host reads the format's opening line.
        return $content === null ? null : RubricScripts::framed(Shows::CATALOGUE[Shows::STAMMTISCH]['name'], $slot['key'], $content, TalkShow::HOST);
    }

    /**
     * The evergreen talk show for the night stock (TalkShow::evergreen()), without an opening line (the stock's
     * jingle names it); null when it failed.
     *
     * @param  ShowMaterial  $material
     * @return array{text: string, speakers: list<string>}|null
     */
    public function evergreen(array $material): ?array
    {
        return $this->run($material, true, CarbonImmutable::now());
    }

    /**
     * @param  ShowMaterial  $material
     * @return array{text: string, speakers: list<string>}|null
     */
    private function run(array $material, bool $evergreen, CarbonImmutable $now): ?array
    {
        if (! CasterVoice::enabled() || ! TalkShow::available()) {
            return null;
        }

        try {
            if (TalkShow::acts() !== []) {
                return $this->inActs($material, $evergreen, $now);
            }

            return $this->call($material, $evergreen, $now, null);
        } catch (Throwable $exception) {
            if (Cache::add('caster:talkshow-failed-logged', true, 600)) {
                Log::warning('Caster talk show failed', ['exception' => $exception::class]);
            }
        }

        return null;
    }

    /**
     * The talk written act by act (P20; user 2026-10-13: "wenn im Programm steht TalkShow von 19:00 bis 19:30, dann muss
     * die TalkShow auch 30 min gehen!"). One call writes one part of an act, about `part_seconds` on air: measured in
     * the first real runs (2026-10-13) the voice model answered an act asked for seventy to ninety sentences with 21
     * and 28 lines (350 and 576 words) and stopped on its own (finish "stop"), so an act is filled part by part instead,
     * at most `max_parts_per_act`. The acts before the last share the slot's minimum (TalkShow::airWindow()) less one
     * part for the closing round; every part gets the last lines before it and the host's questions so far, a fresh
     * sample of host moves, and may not repeat a line of an earlier part. Before the closing round, while the talk
     * would stay under the minimum, parts of `extra_act` follow (at most `max_extra_acts`). Over the maximum, whole
     * turns leave from the middle; still under the minimum, it airs all the same (counted as SHORT).
     *
     * @param  ShowMaterial  $material
     * @return array{text: string, speakers: list<string>}|null
     */
    private function inActs(array $material, bool $evergreen, CarbonImmutable $now): ?array
    {
        $settings = TalkShow::settings();
        $acts = TalkShow::acts();
        [$minSeconds, $maxSeconds] = TalkShow::airWindow();
        $partSeconds = max(30.0, (float) ($settings['part_seconds'] ?? 150));
        $maxParts = max(1, (int) ($settings['max_parts_per_act'] ?? 4));
        $extras = max(0, (int) ($settings['max_extra_acts'] ?? 2));
        $extra = (array) ($settings['extra_act'] ?? []);
        $closing = array_pop($acts);
        $share = ($minSeconds - TalkShow::studioSeconds() - $partSeconds) / max(1, count($acts));
        [$lines, $speakers] = [[], []];
        $air = function () use (&$lines, &$speakers): float {
            return max(0.0, TalkShow::airSeconds($lines, $speakers) - TalkShow::studioSeconds());
        };
        $write = function (array $act, int $number, int $part, bool $final) use (&$lines, &$speakers, $material, $evergreen, $now, $partSeconds, $acts): bool {
            $content = $this->call($material, $evergreen, $now, $act + ['number' => $number, 'of' => count($acts) + 1, 'part' => $part, 'first' => $number === 1 && $part === 1,
                'final' => $final, 'factcheck' => ($act['factcheck'] ?? false) === true && $part === 1, 'previous' => $lines, 'previous_speakers' => $speakers, 'seconds' => $partSeconds]);

            if ($content === null) {
                return false;
            }

            array_push($lines, ...ScriptGuard::lines($content['text']));
            array_push($speakers, ...$content['speakers']);

            return true;
        };

        foreach ($acts as $index => $act) {
            $goal = ($index + 1) * $share;

            for ($part = 1; $part === 1 || ($air() < $goal && $part <= $maxParts); $part++) {
                if (! $write($act, $index + 1, $part, false)) {
                    return null;
                }
            }
        }

        // Short before the closing round: more crossfire first.
        for ($part = 1; $extra !== [] && $extras > 0 && $air() + $partSeconds < $minSeconds - TalkShow::studioSeconds(); $part++, $extras--) {
            if (! $write($extra + ['extra' => true], count($acts), $part, false)) {
                return null;
            }
        }

        if ($closing === null || ! $write($closing, count($acts) + 1, 1, true)) {
            return null;
        }

        [$lines, $speakers] = self::withinLength($lines, $speakers, $maxSeconds, PHP_INT_MAX, fn (array $kept, array $by): float => TalkShow::airSeconds($kept, $by));

        // Short of the slot's minimum it airs all the same (user 2026-10-18: "Nie mehr verwerfen"), counted.
        if (TalkShow::airSeconds($lines, $speakers) < $minSeconds) {
            $this->usage->recordRejection(self::CHANNEL, [ShowScripts::SHORT]);
        }

        return ['text' => implode("\n", $lines), 'speakers' => $speakers];
    }

    /**
     * One model call and its check, at most two tries (the second with the reasons): the whole talk (`$act` null) or
     * one act of it.
     *
     * @param  ShowMaterial  $material
     * @param  array<string, mixed>|null  $act
     * @return array{text: string, speakers: list<string>}|null
     */
    private function call(array $material, bool $evergreen, CarbonImmutable $now, ?array $act): ?array
    {
        $settings = TalkShow::settings();
        $rejected = [];
        // The host's moves of this episode or act: a fresh sample, none said on air within the repeat window.
        $moves = TalkShow::hostMoves($now, max(1, (int) ($settings['host_moves'] ?? 10)));

        // The closing round gets a third try: it is the one part a failed episode cannot do without.
        $tries = ($act['final'] ?? false) === true ? 3 : 2;

        for ($try = 0; $try < $tries; $try++) {
            if ($this->usage->attemptsToday(self::CHANNEL) >= (int) ($settings['daily_cap'] ?? 0)
                || $this->usage->monthCost() >= (float) config('esports.voice.monthly_budget_usd', 0)) {
                return null;
            }

            $this->usage->recordAttempt(self::CHANNEL);
            $temperature = config('tv.commentary.scripts.temperature');
            $response = (new VoiceAgent($this->duets->systemPrompt(), is_numeric($temperature) ? (float) $temperature : null, max(3_000, (int) ($settings['max_output_tokens'] ?? 9_000)), timeoutOverride: max(30, (int) ($settings['timeout_seconds'] ?? 180)), cacheTtl: CasterVoice::staticCacheTtl(), modelOverride: TalkShow::model()))
                ->prompt(self::prompt($material, $evergreen, $rejected, in_array(CasterVoice::SPOKEN_REPEAT, $rejected, true) ? $this->repeated : [], $moves, $act));
            $this->usage->recordTokens(self::CHANNEL, $response->usage->inputTokens, $response->usage->outputTokens, (int) $response->usage->cacheReadInputTokens, (int) $response->usage->cacheWriteInputTokens);

            $answer = $response->toArray();
            $raw = is_string($answer['text'] ?? null) ? $answer['text'] : '';
            [$content, $rejected] = $this->evaluate($material, $raw, $response->steps->last()?->finishReason === FinishReason::Length, $evergreen, $try >= 1, $now, $act);

            if ($content !== null) {
                $this->usage->recordOutcome(self::CHANNEL, 'accepted');

                return $content;
            }

            $this->usage->recordRejection(self::CHANNEL, $rejected);

            // The last draft airs all the same (user 2026-10-18: "Nie mehr verwerfen"); only what never goes on air leaves it.
            if ($try === $tries - 1 && $this->draft !== null) {
                [$text, $speakers] = ScriptGuard::airable($this->draft['text'], $this->draft['speakers']);

                if ($text !== '' && $speakers !== null) {
                    $this->usage->recordOutcome(self::CHANNEL, 'accepted');

                    return ['text' => $text, 'speakers' => $speakers];
                }
            }

            $this->usage->recordOutcome(self::CHANNEL, 'rejected');
        }

        return null;
    }

    /**
     * A model answer checked (see the class comment): the talk with one speaker per line, or null with the reasons.
     *
     * With `$act` (inActs()) the rules of one act: the guest opens only the first, the host closes only the last, the
     * fact check is in the act that calls it (`factcheck`), the length is the act's share (`seconds`, on air:
     * TalkShow::airSeconds()), each voice has `min_turns` turns in it, and no line of an earlier act (`previous`) comes
     * again (CasterVoice::SPOKEN_REPEAT).
     *
     * @param  ShowMaterial  $material
     * @param  array<string, mixed>|null  $act
     * @return array{0: array{text: string, speakers: list<string>}|null, 1: list<string>}
     */
    public function evaluate(array $material, string $raw, bool $cutOff = false, bool $evergreen = false, bool $last = true, ?CarbonImmutable $now = null, ?array $act = null): array
    {
        $this->draft = null;
        $settings = TalkShow::settings();
        $cast = Shows::cast(Shows::STAMMTISCH);
        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 32);
        $perTurn = max(1, (int) ($settings['sentences_per_turn'] ?? 3));
        // The crossfire's one-liners ("Nein, nein, nein, so nicht!") are shorter than a sentence elsewhere may be.
        $minWords = max(1, (int) ($settings['min_words'] ?? 3));
        [$lines, $speakers] = [[], []];
        $reactions = 0;

        foreach (DuetScripts::parse($this->hygiene->clean($raw, true, 'de')) as $turn) {
            // A voice outside the cast leaves: its lines would be voiced by nobody it was written for.
            if (! in_array($turn['speaker'], $cast, true)) {
                continue;
            }

            $sentences = ScriptGuard::sentenceLines(CasterVoice::withoutHashtags($turn['text']));

            // A listener's reaction alone ("[sigh] Ach komm."): kept under min_words, at most MAX_REACTION_TURNS.
            if ($reactions < self::MAX_REACTION_TURNS && self::reactionOnly($sentences)) {
                $lines[] = trim($sentences);
                $speakers[] = $turn['speaker'];
                $reactions++;

                continue;
            }

            $text = ScriptGuard::trimSentences(self::withoutFragments($sentences, $minWords, $maxWords), $perTurn);

            foreach (ScriptGuard::lines($text) as $line) {
                if (preg_match(ScriptQueue::SIGN_OFF, $line) !== 1 && (! $evergreen || preg_match(ReplayArchive::TIMESTAMPED, $line) !== 1)) {
                    $lines[] = $line;
                    $speakers[] = $turn['speaker'];
                }
            }
        }

        // No line of an earlier part again (the same joke twice in one episode): the model tends to write the last lines
        // it was shown once more before it continues (first real runs, 2026-10-13), so such lines leave, the rest stays.
        // A near copy counts too (P20 run 4: "Gießkanne für alle, bezahlt mit Geld, das morgen weniger wert ist" in three parts).
        $earlier = array_values(array_filter((array) ($act['previous'] ?? []), 'is_string'));
        // On the last try a line with the briefing's words leaves (with its question or answer) instead of the whole part.
        // A line the station said within the repeat window stays (user 2026-10-18: "Nie mehr verwerfen").
        $drop = fn (string $line): bool => preg_match(self::META_WORDS, ScriptGuard::withoutMarkers($line)) === 1 || ($evergreen && preg_match(self::HEADLINE_WORDS, $line) === 1);
        [$lines, $speakers] = self::withoutEchoes($lines, $speakers, $earlier, $last ? $drop : null);

        if ($lines === []) {
            return [null, [ShowScripts::EMPTY]];
        }

        [$lines, $speakers] = $act === null
            ? self::withinLength($lines, $speakers, (float) ($settings['seconds']['max'] ?? 600), max(1, (int) ($settings['sentences']['max'] ?? 110)))
            : self::withinLength($lines, $speakers, 3.0 * (float) ($act['seconds'] ?? 600), PHP_INT_MAX, fn (array $kept, array $by): float => TalkShow::airSeconds($kept, $by) - TalkShow::studioSeconds());
        $text = implode("\n", $lines);
        $spoken = ScriptGuard::withoutMarkers(str_replace("\n", ' ', $text));
        $headline = explode("\n\n", $material['data'])[0];

        $violations = [
            ...CasterVoice::free([
                ...$this->hygiene->check($spoken, 'de', [], null, $cutOff, null, [], $maxWords, ScriptGuard::maxCommas()),
                ...$this->voice->guard()->check($spoken, [], 100_000, null),
                // The reaction-only turns are short on purpose: the sentence rules check the rest, the markers all of it.
                ...$this->scriptGuard->check(implode("\n", array_diff_key($lines, self::reactionTurns($lines, $speakers))), 1, 200, $maxWords, [], $minWords),
                ...(preg_match_all('/\[[^\]]*\]/u', $text) > ScriptGuard::maxMarkers($text) ? [ScriptGuard::MARKER_COUNT] : []),
            ]),
            ...ShowScripts::factual($spoken, $material),
            ...TalkShow::quoteViolations($text, $headline),
        ];

        $minSeconds = (float) ($settings['seconds']['min'] ?? 360);
        // An act is short under `act_short_share` of its share: the extra act before the closing round makes up the rest.
        if ($act === null ? ScriptQueue::estimateSeconds($text) < $minSeconds : TalkShow::airSeconds($lines, $speakers) - TalkShow::studioSeconds() < (float) ($settings['act_short_share'] ?? 0.6) * (float) ($act['seconds'] ?? 0)) {
            $violations[] = ShowScripts::SHORT;
        }

        $turns = DuetDirector::turns($lines, $speakers);
        $counts = array_count_values(array_column($turns, 'speaker'));
        foreach ($cast as $speaker) {
            if (($counts[$speaker] ?? 0) < max(1, (int) ($act === null ? ($settings['min_turns'] ?? 6) : ($act['min_turns'] ?? 3)))) {
                $violations[] = ShowScripts::TURNS;
                break;
            }
        }

        $shapeBroken = $act === null
            ? $turns[0]['speaker'] !== TalkShow::GUEST || $turns[count($turns) - 1]['speaker'] !== TalkShow::HOST || preg_match('/faktencheck/iu', $text) !== 1
            : (($act['first'] ?? false) === true && $turns[0]['speaker'] !== TalkShow::GUEST)
                || (($act['final'] ?? false) === true && $turns[count($turns) - 1]['speaker'] !== TalkShow::HOST)
                || (($act['factcheck'] ?? false) === true && preg_match('/faktencheck/iu', $text) !== 1);

        if ($shapeBroken) {
            $violations[] = self::SHAPE;
        }

        if (self::hostQuestionShare($turns) < (float) ($settings['host_question_share'] ?? 0.6)) {
            $violations[] = self::HOST_QUESTIONS;
        }

        if (preg_match(self::META_WORDS, $spoken) === 1 || ($evergreen && preg_match(self::HEADLINE_WORDS, $spoken) === 1)) {
            $violations[] = self::META;
        }

        foreach ($lines as $index => $line) {
            if ($speakers[$index] !== TalkShow::GUEST && preg_match(self::LABELS, $line) === 1) {
                $violations[] = self::LABEL;
            }

            if ($speakers[$index] !== TalkShow::GUEST && preg_match(self::GENDERED_WORDS, $line) === 1) {
                $violations[] = self::GENDERED;
            }
        }

        if ($violations === []) {
            $repeated = self::repeatedLines($text, $now ?? CarbonImmutable::now());

            if ($repeated !== [] && ! $last) {
                $this->repeated = $repeated;
                $violations[] = CasterVoice::SPOKEN_REPEAT;
            }
        }

        // The draft turned down, for the last try that airs it all the same (call()).
        $this->draft = ['text' => $text, 'speakers' => $speakers];

        return $violations === [] ? [['text' => $text, 'speakers' => $speakers], []] : [null, array_values(array_unique($violations))];
    }

    /**
     * The lines within `$maxSeconds` and `$maxSentences`: whole turns leave from the middle (the crossfire), so the cold
     * open and the closing round stay.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $speakers
     * @param  (Closure(list<string>, list<string>): float)|null  $seconds  how long lines with their speakers air (default: ScriptQueue::estimateSeconds())
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function withinLength(array $lines, array $speakers, float $maxSeconds, int $maxSentences, ?Closure $seconds = null): array
    {
        $seconds ??= fn (array $kept, array $by): float => ScriptQueue::estimateSeconds(implode("\n", $kept));

        // Each turn as its speaker and the indexes of its lines.
        $turns = [];
        foreach ($lines as $index => $line) {
            $last = array_key_last($turns);

            if ($last !== null && $turns[$last][0] === $speakers[$index]) {
                $turns[$last][1][] = $index;
            } else {
                $turns[] = [$speakers[$index], [$index]];
            }
        }

        while (true) {
            $kept = array_merge(...array_map(fn (array $turn): array => $turn[1], $turns));
            $flat = array_map(fn (int $index): string => $lines[$index], $kept);

            $by = array_map(fn (int $index): string => $speakers[$index], $kept);

            if (count($turns) <= 4 || (count($flat) <= $maxSentences && $seconds($flat, $by) <= $maxSeconds)) {
                return [$flat, $by];
            }

            array_splice($turns, intdiv(count($turns), 2), 1);

            // Two turns of one speaker that now meet are one turn.
            $merged = [];
            foreach ($turns as $turn) {
                $last = array_key_last($merged);

                if ($last !== null && $merged[$last][0] === $turn[0]) {
                    $merged[$last][1] = [...$merged[$last][1], ...$turn[1]];
                } else {
                    $merged[] = $turn;
                }
            }
            $turns = $merged;
        }
    }

    /**
     * The share of the host's turns that ask a question (a "?" in the turn), his reaction-only turns left out; 1.0 when
     * he has none.
     *
     * @param  list<array{speaker: string, text: string}>  $turns
     */
    public static function hostQuestionShare(array $turns): float
    {
        $host = array_values(array_filter($turns, fn (array $turn): bool => $turn['speaker'] === TalkShow::HOST && ! self::reactionOnly($turn['text'])));

        return $host === [] ? 1.0 : count(array_filter($host, fn (array $turn): bool => str_contains($turn['text'], '?'))) / count($host);
    }

    /**
     * Whether a turn is a reaction alone: one sentence that opens with a sound marker and has two to five spoken words.
     */
    public static function reactionOnly(string $text): bool
    {
        $lines = ScriptGuard::lines($text);
        $words = count(preg_split('/\s+/u', trim(ScriptGuard::withoutMarkers((string) ($lines[0] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return count($lines) === 1 && preg_match('/^\[(?:'.implode('|', array_map(fn (string $marker): string => preg_quote($marker, '/'), ScriptGuard::MARKERS)).')\]/u', trim($lines[0])) === 1
            && $words >= 2 && $words <= 5;
    }

    /**
     * The lines that are a reaction-only turn of their own (a line between two lines of other speakers), by index.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $speakers
     * @return array<int, true>
     */
    private static function reactionTurns(array $lines, array $speakers): array
    {
        $reactions = [];

        foreach ($lines as $index => $line) {
            $alone = ($speakers[$index - 1] ?? null) !== $speakers[$index] && ($speakers[$index + 1] ?? null) !== $speakers[$index];

            if ($alone && self::reactionOnly($line)) {
                $reactions[$index] = true;
            }
        }

        return $reactions;
    }

    /**
     * One turn's sentences (one per line) without fragments under `$minWords` words; the turn ends before a sentence
     * over `$maxWords` (like ScriptGuard::withoutShort(), with the talk show's own floor for one-liners).
     */
    private static function withoutFragments(string $text, int $minWords, int $maxWords): string
    {
        $kept = [];

        foreach (ScriptGuard::lines($text) as $line) {
            $words = count(preg_split('/\s+/u', trim(ScriptGuard::withoutMarkers($line)), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if ($words > $maxWords) {
                break;
            }

            if ($words >= $minWords) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    /**
     * The act's own order (P20): which act of how many, its brief, its length in sentences, what opens and closes it.
     *
     * @param  array<string, mixed>  $act
     * @param  Closure(int): string  $count
     */
    private static function actBlock(array $act, string $guest, Closure $count, int $min, int $max): string
    {
        $number = (int) ($act['number'] ?? 1);
        $of = (int) ($act['of'] ?? 1);

        $part = (int) ($act['part'] ?? 1);

        return 'Dieser Teil: Akt '.$count($number).' von '.$count($of).($part > 1 ? ', Fortsetzung '.$count($part) : '')."\n\n".(string) ($act['brief'] ?? '')
            .($part > 1 ? "\n- Das ist die Fortsetzung dieses Akts: neue Fragen, neue Seitenhiebe, neue Wendungen zum selben Akt, nichts von oben noch einmal." : '')
            ."\n- Schreib nur diesen Akt, ".$count($min).' bis '.$count($max).' Sätze, im Kreuzfeuer kurze Wortmeldungen von einem bis drei Sätzen.'
            .(($act['first'] ?? false) === true ? "\n- {$guest} spricht als Erste (der kalte Einstieg)." : "\n- Kein neuer Einstieg, keine Begrüßung: es geht nahtlos weiter, wo der letzte Akt aufgehört hat, gern mit einem Rückgriff auf einen Witz von vorhin.")
            .(($act['final'] ?? false) === true ? "\n- Sirius spricht zuletzt: ein Satz Zusammenfassung und neugierig machen auf den nächsten Talk, ohne Termin und ohne Abschiedsfloskel." : "\n- Kein Abschluss, keine Schlussrunde und keine Verabschiedung: der Talk geht nach diesem Akt weiter.");
    }

    /**
     * What was said before this act (P20): the last lines with their speakers, and the host's questions so far, so
     * the act continues the talk and asks nothing twice.
     *
     * @param  array<string, mixed>  $act
     */
    private static function soFarBlock(array $act): ?string
    {
        $lines = array_values(array_filter((array) ($act['previous'] ?? []), 'is_string'));
        $speakers = array_values((array) ($act['previous_speakers'] ?? []));

        if ($lines === []) {
            return null;
        }

        $name = fn (int $index): string => match ($speakers[$index] ?? null) {
            TalkShow::GUEST => 'Genossin',
            TalkShow::HOST => 'Sirius',
            default => 'Markus',
        };
        $last = array_slice(array_keys($lines), -16);
        $questions = array_values(array_filter(array_keys($lines), fn (int $index): bool => ($speakers[$index] ?? null) === TalkShow::HOST && str_contains($lines[$index], '?')));

        return "So weit lief der Talk (nur zur Orientierung: kein Satz, keine Pointe und keine Frage davon noch einmal)\n\nDie letzten Zeilen, dein Teil beginnt direkt danach (schreib sie nicht noch einmal ab):\n"
            .implode("\n", array_map(fn (int $index): string => $name($index).': '.$lines[$index], $last))
            .($questions === [] ? '' : "\n\nSchon gestellte Fragen des Gastgebers:\n".implode("\n", array_map(fn (int $index): string => '- '.$lines[$index], array_slice($questions, -30))));
    }

    /**
     * The lines without the ones that repeat an earlier line (echoes()), a question and its answer kept or dropped
     * together (P20 probe 2026-10-13: dropping a repeated question left its answer standing alone): when a turn's
     * closing question goes, the next turn (its answer) goes with it; when a whole turn goes, the question before it
     * that it answered goes too.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $speakers
     * @param  list<string>  $earlier  the lines of the parts before
     * @param  (Closure(string): bool)|null  $drop  a line that leaves as well (dropped the same way)
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function withoutEchoes(array $lines, array $speakers, array $earlier, ?Closure $drop = null): array
    {
        $dropped = [];
        $kept = [];
        foreach ($lines as $index => $line) {
            if (($drop !== null && $drop($line)) || self::echoes($line, [...$earlier, ...$kept])) {
                $dropped[$index] = true;
            } else {
                $kept[] = $line;
            }
        }

        // The turns: consecutive lines of one speaker.
        $turns = [];
        foreach ($lines as $index => $line) {
            $last = array_key_last($turns);

            if ($last !== null && $speakers[$turns[$last][0]] === $speakers[$index]) {
                $turns[$last][] = $index;
            } else {
                $turns[] = [$index];
            }
        }

        $echoed = $dropped;
        foreach ($turns as $position => $turn) {
            $closing = $turn[count($turn) - 1];
            $next = $turns[$position + 1] ?? null;

            // The question went: its answer goes too.
            if ($next !== null && isset($echoed[$closing]) && str_contains($lines[$closing], '?')) {
                foreach ($next as $index) {
                    $dropped[$index] = true;
                }
            }

            // The whole answer went: the question it answered goes too.
            if ($next !== null && ! isset($dropped[$closing]) && str_contains($lines[$closing], '?')
                && array_filter($next, fn (int $index): bool => ! isset($echoed[$index])) === []) {
                $dropped[$closing] = true;
            }
        }

        $keep = array_values(array_filter(array_keys($lines), fn (int $index): bool => ! isset($dropped[$index])));

        return [array_map(fn (int $index): string => $lines[$index], $keep), array_map(fn (int $index): string => $speakers[$index], $keep)];
    }

    /**
     * Whether a line repeats one of `$earlier`: the same words, or at least `ECHO_SHARE` of its words of four letters
     * and more in one earlier line (a near copy with a word swapped).
     *
     * @param  list<string>  $earlier
     */
    public static function echoes(string $line, array $earlier): bool
    {
        $words = fn (string $text): array => array_values(array_unique(array_filter(explode(' ', self::normalized($text)), fn (string $word): bool => mb_strlen($word) >= 4)));
        $mine = $words($line);

        foreach ($earlier as $other) {
            if (self::normalized($other) === self::normalized($line)) {
                return true;
            }

            $theirs = $words($other);
            $shared = count(array_intersect($mine, $theirs));

            if (count($mine) >= 4 && $shared / max(count($mine), count($theirs)) >= self::ECHO_SHARE) {
                return true;
            }
        }

        return false;
    }

    /** A line as the episode's repeat check compares it: lowercase letters and digits, markers and punctuation gone. */
    private static function normalized(string $line): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(ScriptGuard::withoutMarkers($line))));
    }

    /**
     * The talk's sentences that repeat what the station said; none when the memory cannot be read.
     *
     * @return list<string>
     */
    private static function repeatedLines(string $text, CarbonImmutable $now): array
    {
        try {
            return (new SpokenMemory)->repeated($text, $now);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The variable half of the call: the guest, the dramaturgy, the order of the show, the data.
     *
     * @param  ShowMaterial  $material
     * @param  list<string>  $rejected
     * @param  list<string>  $repeated
     * @param  list<string>  $moves  the host's moves of this episode (TalkShow::hostMoves())
     * @param  array<string, mixed>|null  $act  one act of the talk (inActs()): its brief replaces the running order, the
     *                                          lines before it and the host's questions so far come with it
     */
    public static function prompt(array $material, bool $evergreen = false, array $rejected = [], array $repeated = [], array $moves = [], ?array $act = null): string
    {
        $settings = TalkShow::settings();
        $min = max(1, (int) ($settings['sentences']['min'] ?? 55));
        $max = max($min, (int) ($settings['sentences']['max'] ?? 110));
        $minutes = max(1, (int) round((float) ($settings['seconds']['min'] ?? 360) / 60));
        $maxMinutes = max($minutes, (int) round((float) ($settings['seconds']['max'] ?? 600) / 60));
        $perTurn = max(1, (int) ($settings['sentences_per_turn'] ?? 3));
        $minTurns = max(1, (int) ($settings['min_turns'] ?? 6));
        $guest = TalkShow::stageName();
        $count = fn (int $number): string => ReactionLines::spokenNumber(min(99, $number));

        if ($evergreen) {
            // No headline in an evergreen episode: no move or brief that reads one out.
            $moves = array_values(array_filter($moves, fn (string $move): bool => preg_match(self::HEADLINE_WORDS, $move) !== 1));

            if ($act !== null && is_string($act['evergreen_brief'] ?? null)) {
                $act['brief'] = $act['evergreen_brief'];
            }
        }

        if ($act !== null) {
            // One act's share of the slot in sentences, at the talk's pace (about four seconds a line on air).
            $min = max(10, (int) round(0.85 * (float) ($act['seconds'] ?? 300) / 4.2));
            $max = max($min, (int) round(1.15 * (float) ($act['seconds'] ?? 300) / 4.2));
            [$windowMin, $windowMax] = TalkShow::airWindow();
            $minutes = max(1, (int) round($windowMin / 60));
            $maxMinutes = max($minutes, (int) round($windowMax / 60));
            $minTurns = max(1, (int) ($act['min_turns'] ?? 3));
        }

        return implode("\n\n", array_filter([
            TalkShow::guestBlock(),
            TalkShow::dramaturgyBlock(),
            "Auftrag\n\n„Turm und Genossin, der Talk“ auf TWENTY ONE ESPORTS RADIO, eine Talkshow wie im Fernsehen, etwa {$minutes} bis {$maxMinutes} Minuten lang: dramatisch, laut, voller Energie, weil die Positionen meilenweit auseinander liegen."
                ." Sirius ist der Gastgeber, Markus Turm und {$guest} sind die beiden Gäste und streiten gegeneinander. "
                .($evergreen
                    ? 'Diese Ausgabe läuft im Programmvorrat an einem späteren Tag zu einer unbekannten Uhrzeit: zeitlos, ohne Uhrzeit, Tageszeit, Wochentag oder Datum, nie „heute“, „gestern“, „morgen“, „gerade eben“ oder „vorhin“, keine aktuellen Nachrichten. Das Thema steht im Material.'
                    : 'Die Ansage mit dem Namen ist schon geschrieben (ohne Uhrzeit, und auch ihr nennt nie eine Uhrzeit: die Sendung kann später laufen als geplant); du schreibst nur, was danach kommt. Anlass ist die echte Schlagzeile im Material.')
                ." {$min} bis {$max} Sätze, gesprochen auf Deutsch, höchstens zweiunddreißig Wörter pro Satz; im Kreuzfeuer gern kurze Einzeiler ab drei Wörtern, sonst ganze Sätze.",
            "Die Rollen\n\n- Sirius, der Gastgeber: die Parodie eines bekannten Typs von Fernseh-Talkmaster, ohne je einen echten Namen zu nennen oder jemand Echtes zu sein. Er moderiert durch Fragen: mindestens sieben von zehn seiner Wortmeldungen sind eine zugespitzte Frage oder enden mit einer, gerichtet an einen Gast mit Namen („Herr Turm, ganz konkret: wer bezahlt dann die Straße?“, „Genossin Gisela, ja oder nein?“, „Was heißt das denn jetzt für die Rentnerin an der Kasse?“). Er hakt nach, wenn eine Antwort ausweicht („Das war keine Antwort auf meine Frage“), stellt die Frage anders, fragt „Mal angenommen, …, was dann?“, ".($evergreen ? 'spitzt eine These eines Gastes zu und will eine Reaktion des anderen' : 'liest die Schlagzeile vor und will eine Reaktion')
                .', unterbricht mit „Moment, Moment, lassen Sie ihn ausreden“ und fragt dann den anderen, liest eine Zuschauerfrage vor, stellt Entweder-oder-Fragen und fragt „Was genau meinen Sie damit?“. Feststellungen wie „Ich halte fest“ höchstens zweimal pro Sendung, und danach kommt sofort eine Frage. Er siezt beide Gäste („Herr Turm“, „Genossin Gisela“), fällt ins Wort, würgt Monologe ab und gießt Öl ins Feuer; seine Wendungen stehen unten im eigenen Block.'
                ."\n- Markus Turm, Gast: argumentiert aus der Sicht freier Märkte, guten Geldes und von Bitcoin: Staat, Zentralbanken, Steuern, Regulierung und Fiatgeld sind das Problem, freiwilliger Tausch und Selbstbestimmung über das eigene Leben der Maßstab. Er zeigt das in Argumenten, nie mit einem Etikett: „Anarchokapitalist“, „libertär“ oder „wir Libertären“ sagt er nie. Satire-Technik: ein Fakt aus dem Material, die absurde Konsequenz, die Pointe."
                ."\n- {$guest}, Gast: wie in ihrem Persona-Block oben; wird sie hitzig, werden ihre Sätze kürzer und enden mit Ausrufezeichen."
                ."\n- Geroastet werden Ideen, nie Geschlecht, Aussehen, Alter oder Herkunft, keine herablassende Anrede wie „Gnädigste“, und nie die echten Autorinnen und Autoren als Personen.",
            $act !== null ? self::actBlock($act, $guest, $count, $min, $max) : null,
            $act !== null ? null : "Ablauf\n\n1. Kalter Einstieg: {$guest} beginnt mit ihrer steilsten These, Markus fährt ihr sofort in die Parade."
                ."\n2. Sirius begrüßt die Hörer, stellt beide Gäste mit Namen und Haltung vor und nennt das Thema als zugespitzte Frage".($evergreen ? '.' : ', ausgehend von der Schlagzeile mit ihrer Quelle.')
                ."\n3. Kreuzfeuer in mehreren Runden: sehr kurze Wortmeldungen von einem bis ".$count($perTurn).' Sätzen, die Gäste fallen sich ins Wort, Durcheinanderreden als schnell wechselnde Einzeiler wie „Nein, nein, nein, so nicht!“, Sirius heizt an, unterbricht und fragt nach.'
                ."\n4. Sirius liest eine Zuschauerfrage vor, ohne einen Namen zu erfinden („Ein Zuschauer fragt …“), und zwingt beide zu einer Antwort."
                ."\n5. Einmal ruft Sirius den Faktencheck aus, mit genau diesem Wort, und prüft eine strittige Behauptung nur mit den Tatsachen unten im Material, ausgesprochen als eigenes Wissen (nie „laut Liste“ oder „im Material“)."
                ."\n6. {$guest} landet mindestens einen Treffer, den Markus nicht sofort kontern kann; die Sache eskaliert, bevor Sirius sie einfängt."
                ."\n7. Schlussrunde: Sirius verlangt von jedem genau einen Satz („Ein Satz, Herr Turm, ein Satz!“), {$guest} zuerst, dann Markus."
                ."\n8. Sirius spricht zuletzt: ein Satz Zusammenfassung und neugierig machen auf den nächsten Talk, ohne Termin und ohne Abschiedsfloskel.",
            $act !== null ? self::soFarBlock($act) : null,
            "Reaktionen\n\nWer zuhört, reagiert hörbar auf das eben Gesagte, am Anfang der eigenen Wortmeldung: [sigh] oder [dissatisfaction-hnn] auf eine absurde Behauptung, [surprise-oh] oder [surprise-wa] auf eine Provokation, [laughter] auf einen guten Seitenhieb, [question-ah] als ungläubiges „Hä?“. Jede dritte Wortmeldung beginnt mit so einer Reaktion, verteilt auf alle drei, nie zwei Marker in einem Satz, höchstens ein Marker auf zwei Sätze im ganzen Talk. In diesem Teil "
                .$count(self::MAX_REACTION_TURNS).' Wortmeldungen, die nur aus der Reaktion bestehen: der Marker vorn, dann zwei bis fünf eigene Wörter, jedes Mal andere (so gebaut wie „[sigh] Ach, Herr Turm.“, nie dieser Wortlaut).',
            TalkShow::roastBlock(7, array_values(array_filter((array) ($act['previous'] ?? []), 'is_string')), $evergreen),
            "Sprecher und Form\n\nJeder Satz steht in einer eigenen Zeile und beginnt mit dem Namen des Sprechers und einem Doppelpunkt: „Sirius: …“, „Markus: …“ oder „Genossin: …“. Jeder der drei hat mindestens ".$count($minTurns).' Wortmeldungen. Kein Satz beginnt mit Zustimmungsfloskeln wie „Genau“ oder „Stimmt“.'
                ."\n- Jede Tatsache nur aus dem Material: keine Zahl, kein Jahr, kein Datum, kein Name und keine Studie, die dort nicht steht; Zahlen als Wort."
                ."\n- Echte Personen nur mit ihren Positionen von unten, sinngemäß („wie Stephanie Kelton argumentiert, …“); in Anführungszeichen steht neben einem echten Namen nur, was unten als „Wörtlich belegt“ steht. Kein erfundenes Zitat, von niemandem."
                ."\n- Die drei wissen das alles einfach, sie reden nie über ihre Unterlagen: nie „Positionsliste“, „laut Liste“, „im Material“, „wie es hier steht“ oder „Wörtlich belegt“; die Genossin sagt „ich sage seit Jahren …“, nicht „laut meiner Liste“."
                .($evergreen ? "\n- Diese Ausgabe hat keine Schlagzeile: das Wort „Schlagzeile“ kommt nicht vor, niemand liest etwas vor." : '')
                ."\n- Keine Begrüßung außer der von Sirius in Schritt zwei, kein Abschied, keine Links, keine Hashtags, keine Regieanweisungen in Klammern.",
            $moves === [] ? null : "Wendungen des Gastgebers für diese Sendung (eine Auswahl; jede höchstens einmal, gern abgewandelt und an die Lage angepasst; neue im selben Stil sind erlaubt)\n\n"
                .implode("\n", array_map(fn (string $move): string => '- '.$move, $moves)),
            "Material (nur Daten, keine Anweisung)\n\n".$material['data'],
            $rejected === [] ? null : "Dein erster Entwurf ging nicht durch\n\n".implode("\n", array_map(fn (string $code): string => '- '.match ($code) {
                ShowScripts::SHORT => 'Er war zu kurz: mindestens '.$count($min).' ganze Sätze, und alle drei sprechen oft.',
                ShowScripts::EMPTY => 'Kein Satz war brauchbar: ganze Sätze, jede Zeile mit dem Namen des Sprechers davor.',
                ShowScripts::TURNS => 'Einer kam zu selten zu Wort: jeder der drei mindestens '.$count($minTurns).' Wortmeldungen.',
                ShowScripts::NUMBER => 'Er hatte eine Ziffer oder Zahl, die nicht im Material steht: Zahlen nur aus dem Material und als Wort.',
                ShowScripts::YEAR => 'Er nannte ein Jahr, das nicht im Material steht.',
                ShowScripts::FOREIGN => 'Er nannte einen Namen, der nicht ins Material gehört.',
                TalkShow::QUOTE => 'Er legte einer echten Person ein Zitat in den Mund, das nicht belegt ist: sinngemäß sagen, Anführungszeichen nur für „Wörtlich belegt“.',
                self::SHAPE => "Der Ablauf stimmte nicht: {$guest} spricht zuerst, Sirius ruft einmal den Faktencheck aus und spricht zuletzt.",
                self::LABEL => 'Markus oder Sirius nannten ein Etikett für ihre Haltung: sie nur in Argumenten zeigen.',
                self::HOST_QUESTIONS => 'Sirius hat zu wenig gefragt: mindestens sieben von zehn seiner Wortmeldungen sind eine Frage an einen Gast mit Namen oder enden mit einer.',
                self::META => 'Jemand redete über die Unterlagen statt aus ihnen („laut Positionsliste“, „im Material“'.($evergreen ? ', eine Schlagzeile, die es nicht gibt' : '').'): die drei wissen das einfach und sagen es als ihre eigene Sicht.',
                self::GENDERED => 'Markus oder Sirius redeten die Genossin herablassend als Frau an (etwa „Gnädigste“): sie heißt „Genossin Gisela“ oder „Frau Genossin“, geroastet werden nur ihre Ideen.',
                default => VoicePrompt::REASON_HINTS[$code] ?? $code,
            }, $rejected)),
            CasterVoice::repeatBlock($repeated),
            CasterVoice::varietyBlock(),
            'canon_id ist null. Schreib jetzt den Talk.',
        ]));
    }
}
