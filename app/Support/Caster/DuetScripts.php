<?php

namespace App\Support\Caster;

use App\Support\Voice\LeagueVoice;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceHygiene;
use App\Support\Voice\VoicePrompt;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

/**
 * Writes a duet of Markus and Sirius (DuetDirector) in ONE model call: the
 * whole exchange for the drawn turn sequence, every sentence on its own line
 * and led by its speaker ("Markus: …", "Sirius: …"), each later turn
 * reacting to the turn before it. The system prompt is the caster's
 * (CasterVoice::fixedPart(), Markus' persona at its end) with Sirius' persona
 * and the two's interplay behind it (CasterPersonas::sirius()), byte-identical on every duet call, so
 * the provider's prompt cache serves it.
 *
 * Same rules as Markus alone: AI only, the free rules (CasterVoice::FREE_RULES),
 * complete sentences within the word limits, at most two tries (the second
 * with the reasons of the first), the duet's own daily cap
 * (`duet.daily_cap`) and the monthly budget, counted in VoiceUsage under
 * `caster_duet`. A draft whose turns differ from the drawn sequence is kept
 * with the turns it has (consecutive lines of one speaker are one turn, at
 * most `duet.turns.max` turns); only a draft in which one of the two never
 * speaks is turned down (TURNS). Measured on prod 2026-10-12: 18 of 47 duet
 * calls were turned down for a sequence the model did not follow, and
 * Sirius went quiet. Repeats and sign-offs leave it in
 * ScriptQueue::enqueue(), line by line with their speakers.
 */
final class DuetScripts
{
    public const CHANNEL = 'caster_duet';

    /** One of the two never speaks in the draft (no "Sirius:" line, or no "Markus:" line). */
    public const TURNS = 'duet_turns';

    /** @var array<string, string> */
    private const NAMES = [DuetDirector::MARKUS => 'Markus', DuetDirector::SIRIUS => 'Sirius'];

    /** @var array<int, string> the system prompt by variant (1 with the running gags, 0 without) */
    private array $system = [];

    /** @var list<string> the repeated sentences of the last draft turned down for CasterVoice::SPOKEN_REPEAT */
    private array $repeated = [];

    /** @var array{text: string, speakers: list<string>}|null the last draft evaluate() checked (lastDraft()) */
    private ?array $draft = null;

    public function __construct(
        private readonly CasterVoice $voice,
        private readonly VoiceUsage $usage,
        private readonly ScriptGuard $scriptGuard = new ScriptGuard,
        private readonly VoiceHygiene $hygiene = new VoiceHygiene,
    ) {}

    /**
     * @param  list<string>  $sequence  one speaker per turn (DuetDirector::sequence())
     * @param  array<array-key, mixed>  $facts  public facts of the scene
     * @param  list<string>  $topics
     * @param  list<array{speaker: string, text: string}>  $previous  the last turns of the duet before (DuetDirector::lastTurns())
     * @return array{text: string, speakers: list<string>}|null null: off, capped, failed, or no word left in the second draft (it airs whatever the checks found)
     */
    public function write(array $sequence, string $phase, array $facts, array $topics = [], array $previous = []): ?array
    {
        if (! CasterVoice::enabled() || count($sequence) < 2) {
            return null;
        }

        try {
            $facts = LeagueVoice::publicFacts($facts);
            array_walk_recursive($facts, function (mixed &$value): void {
                if (is_string($value)) {
                    $value = CasterVoice::withoutHashtags($value);
                }
            });
            $rejected = [];

            for ($try = 0; $try < 2; $try++) {
                if ($this->usage->attemptsToday(self::CHANNEL) >= (int) config('tv.commentary.duet.daily_cap', 0)
                    || $this->usage->monthCost() >= (float) config('esports.voice.monthly_budget_usd', 0)) {
                    return null;
                }

                [$duet, $rejected] = $this->draft($sequence, $phase, $facts, $topics, $previous, $rejected, $try === 1);

                if ($duet !== null) {
                    return $duet;
                }
            }
        } catch (Throwable $exception) {
            if (Cache::add('caster:voice-failed-logged', true, 600)) {
                Log::warning('Caster voice failed', ['exception' => $exception::class, 'channel' => self::CHANNEL]);
            }
        }

        return null;
    }

    /**
     * One try: the duet, or null with the reason codes.
     *
     * @param  list<string>  $sequence
     * @param  array<array-key, mixed>  $facts
     * @param  list<string>  $topics
     * @param  list<array{speaker: string, text: string}>  $previous
     * @param  list<string>  $rejected
     * @param  bool  $last  the last try: it airs whatever the checks found, only what never goes on air leaves it (ScriptGuard::airable())
     * @return array{0: array{text: string, speakers: list<string>}|null, 1: list<string>}
     */
    private function draft(array $sequence, string $phase, array $facts, array $topics, array $previous, array $rejected, bool $last = true): array
    {
        $this->usage->recordAttempt(self::CHANNEL);
        $temperature = config('tv.commentary.scripts.temperature');
        $response = (new VoiceAgent($this->systemPrompt(), is_numeric($temperature) ? (float) $temperature : null, (int) config('tv.commentary.scripts.max_output_tokens', 1_500), plan: true, cacheTtl: CasterVoice::staticCacheTtl()))
            ->prompt(self::prompt($sequence, $phase, $facts, $topics, $previous, $rejected, in_array(CasterVoice::SPOKEN_REPEAT, $rejected, true) ? $this->repeated : []));
        $this->usage->recordTokens(self::CHANNEL, $response->usage->inputTokens, $response->usage->outputTokens, (int) $response->usage->cacheReadInputTokens, (int) $response->usage->cacheWriteInputTokens);

        $answer = $response->toArray();
        $raw = is_string($answer['text'] ?? null) ? $answer['text'] : '';
        [$duet, $violations] = $this->evaluate($raw, $facts, $response->steps->last()?->finishReason === FinishReason::Length);

        // A first draft that repeats the station is said anew once (P8).
        if ($duet !== null && $violations === [] && ! $last && ($this->repeated = self::repeatedLines($duet['text'])) !== []) {
            $violations = [CasterVoice::SPOKEN_REPEAT];
        }

        if ($violations !== []) {
            $this->usage->recordRejection(self::CHANNEL, $violations);

            // The last draft airs all the same (user 2026-10-18: "Nie mehr verwerfen"); only what never goes on air leaves it.
            $draft = $last ? $this->lastDraft() : null;
            [$text, $speakers] = $draft === null ? ['', null] : ScriptGuard::airable($draft['text'], $draft['speakers'], $facts);

            if ($text === '' || $speakers === null) {
                $this->usage->recordOutcome(self::CHANNEL, 'rejected');

                return [null, $violations];
            }

            $duet = ['text' => $text, 'speakers' => $speakers];
        }

        $this->usage->recordOutcome(self::CHANNEL, 'accepted');

        return [$duet, []];
    }

    /**
     * A model answer checked like draft() does it: parsed into turns,
     * repaired, then the hygiene, fact and script guards over its spoken
     * text. The duet, or null with the reason codes.
     *
     * `$maxTurns` and `$maxSentences` (per turn) override `duet.turns.max` and
     * the speakers' `duet.sentences.*.max` (the day stock's long talks, StockScripts).
     *
     * @param  array<array-key, mixed>  $facts
     * @return array{0: array{text: string, speakers: list<string>}|null, 1: list<string>}
     */
    public function evaluate(string $raw, array $facts, bool $cutOff = false, ?int $maxTurns = null, ?int $maxSentences = null): array
    {
        $turns = self::parse($this->hygiene->clean($raw, true, 'de'));
        $duet = $this->repair($turns, $maxTurns, $maxSentences);
        // What airs when the checks turn it down on the last try (lastDraft()): the repaired duet, or the turns as written.
        $this->draft = $duet ?? self::asWritten($turns);

        if ($duet === null) {
            return [null, [self::TURNS]];
        }

        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 32);
        $spoken = ScriptGuard::withoutMarkers(str_replace("\n", ' ', $duet['text']));
        $violations = CasterVoice::free([
            ...$this->hygiene->check($spoken, 'de', [], null, $cutOff, null, [], $maxWords, ScriptGuard::maxCommas()),
            ...$this->voice->guard()->check($spoken, $facts, 100_000, null),
            ...$this->scriptGuard->check($duet['text'], count(DuetDirector::turns(ScriptGuard::lines($duet['text']), $duet['speakers'])), 100, $maxWords),
        ]);

        return $violations === [] ? [$duet, []] : [null, $violations];
    }

    /**
     * The parsed turns repaired like a solo script, turn by turn (one
     * sentence per line, fragments and overlong sentences dropped while one
     * stays, at most the speaker's `duet.sentences.max`), as text with one
     * speaker per line. The turns the model wrote count, not the drawn
     * sequence: consecutive lines of one speaker are already one turn
     * (parse()), so no speaker ever has two turns in a row (`max_run`), and
     * turns beyond `duet.turns.max` are dropped. null when Markus or Sirius
     * never speaks, or a turn lost every sentence.
     *
     * @param  list<array{speaker: string, text: string}>  $turns
     * @return array{text: string, speakers: list<string>}|null
     */
    public function repair(array $turns, ?int $maxTurns = null, ?int $maxSentences = null): ?array
    {
        $turns = DuetDirector::turns(array_column($turns, 'text'), array_column($turns, 'speaker'));
        $turns = array_slice($turns, 0, max(2, $maxTurns ?? (int) config('tv.commentary.duet.turns.max', 5)));
        $speakers = array_column($turns, 'speaker');

        if (! in_array(DuetDirector::SIRIUS, $speakers, true) || ! in_array(DuetDirector::MARKUS, $speakers, true)) {
            return null;
        }

        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 32);
        [$lines, $speakers] = [[], []];

        foreach ($turns as $turn) {
            $max = max(1, $maxSentences ?? (int) config("tv.commentary.duet.sentences.{$turn['speaker']}.max", 3));
            $text = ScriptGuard::trimSentences(ScriptGuard::withoutShort(ScriptGuard::sentenceLines(CasterVoice::withoutHashtags($turn['text'])), 1, $maxWords), $max);
            $turnLines = ScriptGuard::lines($text);

            if ($turnLines === []) {
                return null;
            }

            foreach ($turnLines as $line) {
                $lines[] = $line;
                $speakers[] = $turn['speaker'];
            }
        }

        return ['text' => implode("\n", $lines), 'speakers' => $speakers];
    }

    /**
     * The duet of the last answer evaluate() checked, turned down or not (null: none had a word): what a writer airs on
     * its last try all the same (user 2026-10-18: "Nie mehr verwerfen").
     *
     * @return array{text: string, speakers: list<string>}|null
     */
    public function lastDraft(): ?array
    {
        return $this->draft;
    }

    /**
     * Parsed turns as they were written, one sentence per line with its speaker; null without a line.
     *
     * @param  list<array{speaker: string, text: string}>  $turns
     * @return array{text: string, speakers: list<string>}|null
     */
    private static function asWritten(array $turns): ?array
    {
        [$lines, $speakers] = [[], []];

        foreach ($turns as $turn) {
            foreach (ScriptGuard::lines(ScriptGuard::sentenceLines(CasterVoice::withoutHashtags($turn['text']))) as $line) {
                $lines[] = $line;
                $speakers[] = $turn['speaker'];
            }
        }

        return $lines === [] ? null : ['text' => implode("\n", $lines), 'speakers' => $speakers];
    }

    /**
     * The model's lines as turns: a line led by a speaker's name ("Markus:",
     * "Sirius:", or any other voice with a profile in
     * `voicestudio.profiles`, so a further voice needs only its config, or
     * one of its `voicestudio.aliases` such as "Gisela:" for the talk show's
     * guest; any case, also bold or with a dash) starts or continues that speaker's
     * turn, a line without a speaker continues the current turn; a line
     * before any speaker counts for Markus. A speaker tag right after a
     * sentence end inside a line starts a new line first: Haiku often writes
     * the whole exchange on one line ("Markus: … . Sirius: … . Markus: …",
     * 3 of 10 real calls on 2026-10-12), which read as Markus alone.
     *
     * @return list<array{speaker: string, text: string}>
     */
    public static function parse(string $text): array
    {
        [$lines, $speakers] = [[], []];
        $current = DuetDirector::MARKUS;
        $aliases = self::speakerAliases();
        $tags = [...self::speakerNames(), ...array_keys($aliases)];
        // The longest first: "genossin gisela" before "genossin".
        usort($tags, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $names = implode('|', array_map(fn (string $speaker): string => str_replace(' ', '\\s+', preg_quote($speaker, '/')), $tags));
        $text = preg_replace('/(?<=[.!?…"“”»)\]])[ \t]+(?=[*_]*(?:'.$names.')(?:\s+turm)?[*_]*\s*[:：])/iu', "\n", $text) ?? $text;

        foreach (ScriptGuard::lines($text) as $line) {
            if (preg_match('/^[\s*_\-–—>]*('.$names.')(?:\s+turm)?[\s*_]*[:：][\s*_]*(.*)$/iu', $line, $match) === 1) {
                $tag = (string) preg_replace('/\s+/u', ' ', mb_strtolower($match[1]));
                $current = $aliases[$tag] ?? $tag;
                $line = trim($match[2]);
            }

            if ($line !== '') {
                $lines[] = $line;
                $speakers[] = $current;
            }
        }

        return DuetDirector::turns($lines, $speakers);
    }

    /**
     * The speakers a script may name: Markus, Sirius and every voice with a
     * VoiceStudio profile (`voicestudio.profiles`), lowercase.
     *
     * @return list<string>
     */
    public static function speakerNames(): array
    {
        $profiles = array_keys(array_filter((array) config('tv.commentary.voicestudio.profiles', []), fn (mixed $profile): bool => is_string($profile) && trim($profile) !== ''));
        $names = array_map(fn (mixed $name): string => mb_strtolower((string) $name), $profiles);

        return array_values(array_unique([DuetDirector::MARKUS, DuetDirector::SIRIUS, ...array_filter($names, fn (string $name): bool => preg_match('/^[a-z]{2,20}$/', $name) === 1)]));
    }

    /**
     * Further names a line may be led with (`voicestudio.aliases`, lowercase name => speaker), for speakers that
     * speakerNames() knows only.
     *
     * @return array<string, string>
     */
    public static function speakerAliases(): array
    {
        $known = self::speakerNames();
        $aliases = [];

        foreach ((array) config('tv.commentary.voicestudio.aliases', []) as $alias => $speaker) {
            $alias = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower((string) $alias)));

            if (is_string($speaker) && in_array($speaker, $known, true) && preg_match('/^[\p{L}]{2,20}(?: [\p{L}]{2,20})?$/u', $alias) === 1) {
                $aliases[$alias] = $speaker;
            }
        }

        return $aliases;
    }

    /**
     * The duet's sentences that repeat what the station said (SpokenMemory::repeated()); none when the memory cannot
     * be read (ScriptQueue::enqueue() checks again).
     *
     * @return list<string>
     */
    private static function repeatedLines(string $text): array
    {
        try {
            return (new SpokenMemory)->repeated($text, CarbonImmutable::now());
        } catch (Throwable) {
            return [];
        }
    }

    /** The system prompt of every duet: the caster's with Markus' persona, then Sirius' (cached as one prefix). */
    /** The duets' cached system prompt; `$gags` false: without the personas' running gags (day stock, long shows; P21). */
    public function systemPrompt(bool $gags = true): string
    {
        return $this->system[(int) $gags] ??= $this->voice->fixedPart($gags)."\n".CasterPersonas::sirius($gags)."\n";
    }

    /**
     * The variable half of a duet call.
     *
     * @param  list<string>  $sequence
     * @param  array<array-key, mixed>  $facts
     * @param  list<string>  $topics
     * @param  list<array{speaker: string, text: string}>  $previous
     * @param  list<string>  $rejected
     * @param  list<string>  $repeated  the first draft's lines that repeated what the station said (CasterVoice::repeatBlock())
     */
    public static function prompt(array $sequence, string $phase, array $facts, array $topics = [], array $previous = [], array $rejected = [], array $repeated = []): string
    {
        $sentences = (array) config('tv.commentary.duet.sentences', []);
        $order = implode("\n", array_map(function (int $index, string $speaker) use ($sentences): string {
            $range = (array) ($sentences[$speaker] ?? []);

            return ($index + 1).'. '.self::NAMES[$speaker].': '.self::count((int) ($range['min'] ?? 1)).' bis '.self::count((int) ($range['max'] ?? 2)).' Sätze'.($index === 0 ? '' : ', reagiert auf die Wortmeldung davor');
        }, array_keys($sequence), $sequence));

        $parts = [
            "Auftrag\n\nEin Wortwechsel auf TWENTY ONE TV für die Programmphase {$phase}: Markus Turm kommentiert, Sirius ist sein Side-Kommentator. Genau ".count($sequence)." Wortmeldungen in dieser Reihenfolge:\n{$order}"
                ."\n\nJeder Satz steht in einer eigenen Zeile und beginnt mit dem Namen des Sprechers und einem Doppelpunkt, also „Markus: …“ oder „Sirius: …“; hat eine Wortmeldung zwei Sätze, beginnen beide Zeilen mit demselben Namen. Gesprochen auf Deutsch. ".CasterVoice::SENTENCE_STYLE
                ."\nDer ganze Wortwechsel dreht sich um EIN Thema: Markus setzt es in seiner ersten Wortmeldung so, dass es auch versteht, wer gerade einschaltet; jede spätere Wortmeldung greift genau den Gedanken direkt davor auf und dreht ihn weiter: frotzeln, widersprechen, zuspitzen oder einen Fakt aus dem Fakten-Block nachlegen. Die letzte Wortmeldung setzt die Pointe, gern als Rückgriff auf den Anfang. Die beiden reden miteinander, duzen sich, und im ganzen Wortwechsel fällt höchstens ein Name."
                ."\nBeide klingen wie in ihren Persona-Blöcken oben und wie ein Comedy-Duo, nicht wie zwei Vorträge: Markus ist meist der Geradlinige, der erzählt und die Vorlage gibt, Sirius der Chaot, der eskaliert, sich künstlich aufregt oder absichtlich falsch versteht und Markus aufzieht; im ganzen Wortwechsel redet Sirius mindestens so viel wie Markus. Die Wortmeldungen sind kurz und schnell, mit Reibung statt höflichem Abwechseln: die beiden sind sich nicht immer einig und ziehen sich gegenseitig auf; fällt einer dem anderen ins Wort, dann mit eigenen Worten und nicht immer mit „Moment mal“. Einmal wird ein echter Fakt Schritt für Schritt zu Ende gedacht, bis er absurd wird, und ein Vergleich muss beim ersten Hören sitzen. Wo der Stoff es hergibt, zielt die Pointe auf Staat, Steuern, Zentralbanken, Regulierung oder Fiatgeld. Eine eigene Geschichte erzählt Sirius nur, wenn sie genau Markus' Punkt trifft."
                ."\nSirius beginnt nie mit Zustimmungsfloskeln wie „Genau“, „Ja“, „Stimmt“, „Richtig“ oder dem Namen Markus, und jede seiner Wortmeldungen fängt anders an als die davor."
                ."\nHöchstens EIN Fakt aus dem Fakten-Block im ganzen Wortwechsel, keine Spielernamen, Laut-Marker nur, wo der Inhalt sie trägt, etwa einer auf zwei bis drei Sätze. Kein Abschied, keine Begrüßung und keine Ansage wie „und jetzt zu …“.",
            "Fakten (die einzige Quelle für Zahlen, Namen und Links)\n\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        // Sirius' own topic this time, drawn from his persona (user 2026-10-12: "Sirius redet immer nur über Bier? Warum?"):
        // beer was his first-ranked topic and a named example in this prompt, so it came up in nearly every duet.
        // Beer stays a running gag, now and then (user 2026-10-12: "Bier darf schon als Witz ab und an vorkommen … nur nicht ständig").
        $beerNow = random_int(1, 100) <= (int) config('tv.commentary.duet.beer_percent', 15);
        $siriusTopics = array_values(array_filter(CasterPersonas::topics(DuetDirector::SIRIUS), fn (array $topic): bool => (preg_match('/bier|alt\b|kölsch/iu', $topic['name'].' '.$topic['what']) === 1) === $beerNow));
        if ($siriusTopics !== []) {
            $pick = $siriusTopics[random_int(0, count($siriusTopics) - 1)];
            $parts[] = "Sirius' Blickwinkel in diesem Wortwechsel (seine Brille auf Markus' Thema, nur wenn sie dazu passt, sonst lass sie weg; kein eigenes zweites Thema".($beerNow ? ', diesmal darf ein Bier-Witz sein' : ', diesmal kein Bier-Thema').")\n\n{$pick['name']}: {$pick['what']}";
        }

        if ($topics !== []) {
            $parts[] = "Themenvorschläge (nimm eins)\n\n".implode("\n", array_map(fn (string $topic): string => '- '.$topic, $topics));
        }

        if ($previous !== []) {
            $parts[] = "So endete der letzte Wortwechsel (nur Daten; aufgreifen darf ihn nur die erste Wortmeldung, nur wenn es passt, und dann mit einem halben Satz, worum es ging, denn viele Hörer waren da noch nicht dabei; nichts davon wiederholen)\n\n"
                .implode("\n", array_map(fn (array $turn): string => (self::NAMES[$turn['speaker']] ?? 'Markus').': '.$turn['text'], $previous));
        }

        if ($rejected !== []) {
            $parts[] = "Dein erster Entwurf ging nicht durch\n\n".implode("\n", array_map(fn (string $code): string => '- '.($code === self::TURNS
                ? 'Einer der beiden kam gar nicht zu Wort: jede Zeile mit „Markus:“ oder „Sirius:“ beginnen, in der Abfolge oben, und beide sprechen.'
                : (VoicePrompt::REASON_HINTS[$code] ?? $code)), $rejected));
        }

        $repeatBlock = CasterVoice::repeatBlock($repeated);
        if ($repeatBlock !== null) {
            $parts[] = $repeatBlock;
        }

        $parts[] = CasterVoice::varietyBlock();
        $parts[] = 'canon_id ist null. Schreib jetzt den Wortwechsel.';

        return implode("\n\n", $parts);
    }

    private static function count(int $number): string
    {
        return ['null', 'ein', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht'][$number] ?? (string) $number;
    }
}
