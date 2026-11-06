<?php

namespace App\Support\Caster\Programme;

use App\Support\Caster\CasterVoice;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\ReactionLines;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
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
 * Writes a long format of TWENTY ONE Radio (plan "TWENTY ONE Radio", P9;
 * Shows) in ONE model call, the duet's cached system prompt (both personas,
 * CasterVoice::TONE: radical roast, chatters included, and its limits). The
 * opening line is the code's, like a rubric's (RubricScripts::opener(): the
 * slot's time and the format's name); the content is a talk of the format's
 * cast (Shows::cast()), one speaker per line.
 *
 * Checked like a rubric (free rules, whole sentences, no goodbye,
 * `seconds` and `sentences` of the format: shorter is tried again, longer
 * loses the sentences before its end (ShowCraft::cut()), every speaker of the cast speaks at least three
 * times) and against the data (factual(), like SportWeather): no digit the
 * data does not have, no number before a unit ("fünfundvierzig Milliarden",
 * "vor siebzehn Jahren", "zweiundzwanzig Züge"; null, ten and twenty-one are
 * Bitcoin common ground, COMMON_NUMBERS) and no spoken year
 * ("zweitausendneun") that is not in it, and no `foreign` name (a chatter
 * not on trial, a gamer tag, a city without a date, another dead coin).
 * Two tries, the second with the reasons, which airs whatever the checks
 * found (user 2026-10-18); `shows.daily_cap` and the monthly
 * budget. The hotline looks its questions up first (Shows::withLookups()):
 * nothing found, no hotline.
 *
 * A format with `slot_minutes` (P21) is a long show at :03 that owns its
 * window: written segment by segment until it fills it (inSegments()),
 * without the personas' running gags.
 *
 * @phpstan-import-type Slot from BroadcastClock
 * @phpstan-import-type ShowMaterial from Shows
 */
class ShowScripts
{
    public const CHANNEL = 'caster_show';

    public const SHORT = 'show_short';

    public const EMPTY = 'show_empty';

    /** A speaker of the cast hardly spoke. */
    public const TURNS = 'show_turns';

    /** A digit, or a number before a unit, that is not in the data. */
    public const NUMBER = 'show_number';

    /** A spoken year that is not in the data. */
    public const YEAR = 'show_year';

    /** A segment of a long show opened a line with three words already used twice as an opener in the show (P21). */
    public const OPENER = 'show_repeated_opener';

    /** A segment of a long show took up a case again while untried cases were left (P21). */
    public const CASE_AGAIN = 'show_case_again';

    /** A name the data rules out (Shows' `foreign`). */
    public const FOREIGN = 'show_foreign';

    /**
     * What one segment of a long show is about, per format (inSegments()): the next case, grave, call, …
     *
     * @var array<string, string>
     */
    private const SEGMENT_UNITS = [
        Shows::TRIBUNAL => 'Fall', Shows::FRIEDHOF => 'Grab', Shows::HOTLINE => 'Anruf', Shows::JAHRESTAG => 'Ereignis',
        Shows::MEETUPS => 'Halt der Rundreise', Shows::PARTIE => 'Abschnitt der Partie',
    ];

    /** Numbers every Bitcoiner may say without data: none, a block every ten minutes, twenty-one million coins. */
    private const COMMON_NUMBERS = [0, 10, 21];

    /** The words after a number that make it a fact. */
    private const UNITS = 'milliarden|millionen|prozent|dollar|euro|bitcoin|sats|züge|zügen|jahre|jahren|termine|terminen|treffen|meetups|zusagen|sekunden|minuten|nutzer|kunden|verdächtige|verdächtigen|token';

    /**
     * The drafts this writer turned down, with their reasons and raw answer, for the eval's listening report (P21).
     *
     * @var list<array{segment: int, reasons: list<string>, raw: string}>
     */
    public array $rejectedDrafts = [];

    /** @var array{text: string, speakers: list<string>}|null the last draft evaluate() checked, turned down or not */
    private ?array $draft = null;

    public function __construct(
        private readonly CasterVoice $voice,
        private readonly DuetScripts $duets,
        private readonly VoiceUsage $usage,
        private readonly Shows $shows,
        private readonly ScriptGuard $scriptGuard = new ScriptGuard,
        private readonly VoiceHygiene $hygiene = new VoiceHygiene,
        private readonly ?BroadcastClock $clock = null,
    ) {}

    /**
     * The format `$id` for `$slot` from `$material`, or null (off, capped, nothing looked up, no word left in the second draft, which airs whatever the checks found).
     *
     * @param  Slot  $slot
     * @param  ShowMaterial  $material
     * @return array{text: string, speakers: list<string>}|null
     */
    public function write(array $slot, string $id, array $material, CarbonImmutable $now): ?array
    {
        if (! CasterVoice::enabled() || ! Shows::isShow($id)) {
            return null;
        }

        try {
            if ($id === Shows::HOTLINE) {
                $material = $this->shows->withLookups($material, $this->voice);

                if ($material === null) {
                    return null;
                }
            }

            $context = ShowCraft::context($slot, $this->clock);
            $content = Shows::slotMinutes($id) !== null ? $this->inSegments($id, $material, $context) : $this->call($id, $material, $context, null);

            if ($content !== null) {
                return RubricScripts::framed(Shows::CATALOGUE[$id]['name'], $slot['key'], $content, DuetDirector::MARKUS);
            }
        } catch (Throwable $exception) {
            if (Cache::add('caster:show-failed-logged', true, 600)) {
                Log::warning('Caster show failed', ['exception' => $exception::class, 'show' => $id]);
            }
        }

        return null;
    }

    /**
     * One model call and its check, at most two tries (the second with the reasons): the whole show (`$segment` null)
     * or one segment of a long one (inSegments()).
     *
     * @param  ShowMaterial  $material
     * @param  array{next: string|null, earlier: list<string>, energy: string}  $context
     * @param  array{number: int, first: bool, final: bool, minutes: int, previous: list<string>, openers?: list<string>}|null  $segment
     * @return array{text: string, speakers: list<string>}|null
     */
    private function call(string $id, array $material, array $context, ?array $segment): ?array
    {
        $settings = (array) config('tv.commentary.programme.shows', []);
        $rejected = [];

        for ($try = 0; $try < 2; $try++) {
            if ($this->usage->attemptsToday(self::CHANNEL) >= (int) ($settings['daily_cap'] ?? 0)
                || $this->usage->monthCost() >= (float) config('esports.voice.monthly_budget_usd', 0)) {
                return null;
            }

            $this->usage->recordAttempt(self::CHANNEL);
            $temperature = config('tv.commentary.scripts.temperature');
            // A long show is written without the personas' running gags (P21): over thirty minutes they came back from nowhere.
            $response = (new VoiceAgent($this->duets->systemPrompt(gags: $segment === null), is_numeric($temperature) ? (float) $temperature : null, max(2_000, (int) ($settings['max_output_tokens'] ?? 6_000)), timeoutOverride: max(15, (int) ($settings['timeout_seconds'] ?? 90)), cacheTtl: CasterVoice::staticCacheTtl()))
                ->prompt(self::prompt($id, $material, $rejected, $context, $segment));
            $this->usage->recordTokens(self::CHANNEL, $response->usage->inputTokens, $response->usage->outputTokens, (int) $response->usage->cacheReadInputTokens, (int) $response->usage->cacheWriteInputTokens);

            $answer = $response->toArray();
            $raw = is_string($answer['text'] ?? null) ? $answer['text'] : '';
            [$content, $rejected] = $this->evaluate($id, $material, $raw, $response->steps->last()?->finishReason === FinishReason::Length, $segment['previous'] ?? [], $segment !== null, ($segment['final'] ?? true) === true, $segment['openers'] ?? []);

            if ($content !== null) {
                $this->usage->recordOutcome(self::CHANNEL, 'accepted');

                return $content;
            }

            $this->usage->recordRejection(self::CHANNEL, $rejected);
            $this->rejectedDrafts[] = ['segment' => $segment['number'] ?? 0, 'reasons' => $rejected, 'raw' => $raw];

            // The second draft airs all the same (user 2026-10-18: "Nie mehr verwerfen"); only what never goes on air leaves it.
            if ($try === 1 && $this->draft !== null) {
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
     * A long show (P21; user 2026-10-13: "wenn im Programm steht TalkShow von 19:00 bis 19:30, dann muss sie auch 30 min
     * gehen!") written segment by segment to fill its window (Shows::slotMinutes()): one call per segment of the
     * format's usual length, each with everything aired so far, until the show reaches its window less
     * `slot_margin_seconds` on air (ScriptQueue::estimateSeconds()) or `max_segments`; the opener is the code's line in
     * front of the first, the back-announce ends only the last. A middle segment that fails ends the show with its
     * closing segment; under `min_share` of the window, or no closing segment, it airs all the same (SHORT recorded).
     *
     * @param  ShowMaterial  $material
     * @param  array{next: string|null, earlier: list<string>, energy: string}  $context
     * @return array{text: string, speakers: list<string>}|null
     */
    private function inSegments(string $id, array $material, array $context): ?array
    {
        $settings = Shows::settings($id);
        $minutes = (int) Shows::slotMinutes($id);
        // Never aim below what the show needs to air at all (`min_share`): a small window with a large margin would stop short.
        $target = max(60 * $minutes - max(0, (int) ($settings['slot_margin_seconds'] ?? 120)), (float) ($settings['min_share'] ?? 0.75) * 60 * $minutes);
        // The next segment is about as long as the last one; before the first, the format's minimum.
        $segmentSeconds = max(1.0, (float) ($settings['seconds']['min'] ?? 120));
        $maxSegments = max(2, (int) ($settings['max_segments'] ?? 10));
        [$lines, $speakers, $openers] = [[], [], []];

        for ($number = 1; $number < $maxSegments && ScriptQueue::estimateSeconds(implode("\n", $lines)) + $segmentSeconds < $target; $number++) {
            $content = $this->call($id, $material, $context, ['number' => $number, 'first' => $number === 1, 'final' => false, 'minutes' => $minutes, 'previous' => $lines, 'openers' => $openers]);

            if ($content === null) {
                break;
            }

            array_push($openers, ...self::turnStarts(ScriptGuard::lines($content['text']), $content['speakers']));
            array_push($lines, ...ScriptGuard::lines($content['text']));
            array_push($speakers, ...$content['speakers']);
            $segmentSeconds = max(1.0, ScriptQueue::estimateSeconds($content['text']));
        }

        $closing = $this->call($id, $material, $context, ['number' => $number, 'first' => $lines === [], 'final' => true, 'minutes' => $minutes, 'previous' => $lines, 'openers' => $openers]);

        if ($closing !== null) {
            array_push($lines, ...ScriptGuard::lines($closing['text']));
            array_push($speakers, ...$closing['speakers']);
        }

        // Short of `min_share`, or without its closing segment, what was written airs all the same (user 2026-10-18: "Nie
        // mehr verwerfen"), counted as SHORT; null only when no segment was written at all.
        if ($closing === null || ScriptQueue::estimateSeconds(implode("\n", $lines)) < (float) ($settings['min_share'] ?? 0.75) * 60 * $minutes) {
            $this->usage->recordRejection(self::CHANNEL, [self::SHORT]);
        }

        if ($lines === []) {
            return null;
        }

        return ['text' => implode("\n", $lines), 'speakers' => $speakers];
    }

    /**
     * A model answer checked (see the class comment): the content with one speaker per line, or null with the reasons.
     *
     * A segment of a long show (`$segment`) drops every line that was aired in a segment before (`$previous`) and is short only
     * under `segment_seconds_min`.
     *
     * @param  ShowMaterial  $material
     * @param  list<string>  $previous
     * @param  list<string>  $previousOpeners  the first line of every turn aired so far
     * @return array{0: array{text: string, speakers: list<string>}|null, 1: list<string>}
     */
    public function evaluate(string $id, array $material, string $raw, bool $cutOff = false, array $previous = [], bool $segment = false, bool $final = true, array $previousOpeners = []): array
    {
        $this->draft = null;
        $settings = Shows::settings($id);
        $cast = Shows::cast($id);
        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 32);
        [$lines, $speakers] = [[], []];

        foreach (DuetScripts::parse($this->hygiene->clean($raw, true, 'de')) as $turn) {
            // A voice outside the cast leaves: its lines would be voiced by nobody it was written for.
            if (! in_array($turn['speaker'], $cast, true)) {
                continue;
            }

            foreach (ScriptGuard::lines(ScriptGuard::withoutShort(ScriptGuard::sentenceLines(CasterVoice::withoutHashtags($turn['text'])), 1, $maxWords)) as $line) {
                if (preg_match(ScriptQueue::SIGN_OFF, $line) !== 1 && ! in_array($line, $previous, true)) {
                    $lines[] = $line;
                    $speakers[] = $turn['speaker'];
                }
            }
        }

        if ($lines === []) {
            return [null, [self::EMPTY]];
        }

        $maxSeconds = (float) ($settings['seconds']['max'] ?? 300);
        $maxSentences = max(1, (int) ($settings['sentences']['max'] ?? 40));
        // Too long: the sentences before the end go, the back-announce stays (ShowCraft::cut()).
        [$lines, $speakers] = ShowCraft::cut($lines, $speakers, fn (array $kept): bool => count($kept) > $maxSentences || ScriptQueue::estimateSeconds(implode("\n", $kept)) > $maxSeconds);

        $text = implode("\n", $lines);
        $spoken = ScriptGuard::withoutMarkers(str_replace("\n", ' ', $text));
        $violations = [
            ...CasterVoice::free([
                ...$this->hygiene->check($spoken, 'de', [], null, $cutOff, null, [], $maxWords, ScriptGuard::maxCommas()),
                ...$this->voice->guard()->check($spoken, [], 100_000, null),
                ...$this->scriptGuard->check($text, 1, 100, $maxWords),
            ]),
            ...self::factual($spoken, $material),
        ];

        // A segment of a long show is shorter than the show it is part of: the voice model stops after two to three minutes
        // of talk on its own (P20's first real runs, 2026-10-13), so a segment is short only under `segment_seconds_min`.
        if (ScriptQueue::estimateSeconds($text) < ($segment ? (float) ($settings['segment_seconds_min'] ?? 90) : (float) ($settings['seconds']['min'] ?? 120))) {
            $violations[] = self::SHORT;
        }

        // A long show repeats neither its openers nor its cases (P21, first real Tribunal 2026-10-13: "Euer Ehren, das ist
        // grausam" opened line after line, and a case was tried twice).
        if ($segment) {
            // Counted where a voice starts its turn: a sentence inside a turn ("Das ist …") is no opener.
            $openers = array_count_values(array_filter(array_map(self::opener(...), [...$previousOpeners, ...self::turnStarts($lines, $speakers)])));

            if (max([0, ...array_values($openers)]) > 2) {
                $violations[] = self::OPENER;
            }

            $tried = self::namesIn($material['names'], $previous);
            $here = self::namesIn($material['names'], $lines);

            if (! $final && $tried !== [] && array_diff($material['names'], $tried) !== [] && array_diff($here, $tried) === []) {
                $violations[] = self::CASE_AGAIN;
            }
        }

        $counts = array_count_values($speakers);
        foreach ($cast as $speaker) {
            if (($counts[$speaker] ?? 0) < 3) {
                $violations[] = self::TURNS;
                break;
            }
        }

        // The draft turned down, for the second try that airs it all the same (call()).
        $this->draft = ['text' => $text, 'speakers' => $speakers];

        return $violations === [] ? [['text' => $text, 'speakers' => $speakers], []] : [null, array_values(array_unique($violations))];
    }

    /**
     * What the text breaks against the data (see the class comment).
     *
     * @param  array{digits: list<string>, numbers: list<int>, years: list<int>, foreign: list<string>}  $material
     * @return list<string>
     */
    public static function factual(string $text, array $material): array
    {
        $violations = [];

        preg_match_all('/\d+/u', $text, $digits);
        $numbers = [...self::COMMON_NUMBERS, ...$material['numbers']];

        if (array_diff($digits[0], $material['digits']) !== []) {
            $violations[] = self::NUMBER;
        }

        preg_match_all('/(?<![\p{L}\p{N}])(\p{L}+)\s+(?:'.self::UNITS.')(?![\p{L}])/iu', $text, $units, PREG_SET_ORDER);
        foreach ($units as $unit) {
            $value = SportWeather::value($unit[1]);

            if ($value !== null && ! in_array($value, $numbers, true) && ! in_array(self::NUMBER, $violations, true)) {
                $violations[] = self::NUMBER;
            }
        }

        preg_match_all('/(?<![\p{L}])(zweitausend|neunzehnhundert)(\p{L}*)(?!\s+(?:'.self::UNITS.'))/iu', $text, $years, PREG_SET_ORDER);
        foreach ($years as $year) {
            $rest = $year[2] === '' ? 0 : SportWeather::value(preg_replace('/^und/u', '', mb_strtolower($year[2])) ?? '');

            if ($rest !== null && ! in_array((mb_strtolower($year[1]) === 'zweitausend' ? 2000 : 1900) + $rest, $material['years'], true)) {
                $violations[] = self::YEAR;
                break;
            }
        }

        foreach ($material['foreign'] as $name) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $text) === 1) {
                $violations[] = self::FOREIGN;
                break;
            }
        }

        return $violations;
    }

    /**
     * What a segment may not repeat: the cases already taken up and the openers used twice.
     *
     * @param  ShowMaterial  $material
     * @param  list<string>  $previous
     * @param  list<string>  $openers  the first line of every turn aired so far
     */
    private static function repeatBlock(array $material, array $previous, array $openers, string $unit): ?string
    {
        $tried = self::namesIn($material['names'], $previous);
        $used = array_keys(array_filter(array_count_values(array_filter(array_map(self::opener(...), $openers))), fn (int $count): bool => $count >= 2));
        $lines = [];

        if ($tried !== []) {
            $lines[] = "- Schon dran (nicht noch einmal als {$unit}, nur als kurzer Rückgriff): ".implode(', ', $tried);
        }

        if ($used !== []) {
            $lines[] = '- Diese Satzanfänge sind verbraucht, keine Zeile beginnt mehr so: '.implode(', ', array_map(fn (string $opener): string => '„'.$opener.' …“', $used));
        }

        return $lines === [] ? null : "Nicht wiederholen\n\n".implode("\n", $lines);
    }

    /**
     * The first line of every turn (where the speaker changes).
     *
     * @param  list<string>  $lines
     * @param  list<string>  $speakers
     * @return list<string>
     */
    public static function turnStarts(array $lines, array $speakers): array
    {
        $starts = [];

        foreach ($lines as $index => $line) {
            if ($index === 0 || ($speakers[$index] ?? null) !== ($speakers[$index - 1] ?? null)) {
                $starts[] = $line;
            }
        }

        return $starts;
    }

    /** The first three words of a line, lowercased, as an opener ("euer ehren das"); null for a shorter line. */
    public static function opener(string $line): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(ScriptGuard::withoutMarkers($line)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words) < 3 ? null : implode(' ', array_slice($words, 0, 3));
    }

    /**
     * The names of `$names` that `$lines` mention.
     *
     * @param  list<string>  $names
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function namesIn(array $names, array $lines): array
    {
        $text = implode("\n", $lines);

        return array_values(array_filter($names, fn (string $name): bool => trim($name) !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $text) === 1));
    }

    /** A speaker's name as the prompt writes it. */
    public static function spokenName(string $speaker): string
    {
        return ucfirst($speaker);
    }

    /**
     * The prompt: the data is data, the roast is the cast's.
     *
     * @param  ShowMaterial  $material
     * @param  list<string>  $rejected
     * @param  array{next: string|null, earlier: list<string>, energy: string}|null  $context  the hour around the show (ShowCraft::context())
     * @param  array{number: int, first: bool, final: bool, minutes: int, previous: list<string>, openers?: list<string>}|null  $segment  a segment of a long show (inSegments())
     */
    public static function prompt(string $id, array $material, array $rejected = [], ?array $context = null, ?array $segment = null): string
    {
        $settings = Shows::settings($id);
        $min = max(1, (int) ($settings['sentences']['min'] ?? 20));
        $max = max($min, (int) ($settings['sentences']['max'] ?? 36));
        $minutes = max(1, (int) round((float) ($settings['seconds']['max'] ?? 240) / 60));
        $cast = array_map(self::spokenName(...), Shows::cast($id));
        [$lead, $second] = [$cast[0], $cast[1]];
        $third = $cast[2] ?? null;
        $name = Shows::CATALOGUE[$id]['name'];

        $roles = match ($id) {
            Shows::TRIBUNAL => "{$second} ist der Richter: er verliest die Anklage aus dem Material, verhört und verhängt am Ende jedes Falls ein absurdes Urteil, eine lächerliche Strafe wie „drei Wochen Lightning-Kanal ohne Liquidität“ oder „ein Monat Seed-Phrase auf dem Kühlschrank“."
                ." {$lead} ist der Verteidiger, ein völlig nutzloser: seine Plädoyers machen alles schlimmer, er verwechselt die Mandanten und gibt am Ende alles zu."
                .($third !== null ? " {$third} ist der Ankläger und fordert für jede Kleinigkeit die Höchststrafe." : ''),
            Shows::HOTLINE => "{$lead} moderiert die Hotline, {$second}".($third !== null ? " und {$third} beantworten" : ' beantwortet').' mit: wer gefragt hat, was er wissen wollte, die Antwort aus der Recherche, und der Spott über die Frage und den Fragenden.',
            Shows::FRIEDHOF => "{$lead} hält die Grabrede wie ein feierlicher Trauerredner, {$second} ist der zynische Trauergast, der jede Lobrede mit den echten Zahlen zerlegt.",
            Shows::JAHRESTAG => "{$lead} erzählt, was an diesem Tag passiert ist, {$second} fragt nach, ordnet ein und lästert, was die Welt damals noch nicht verstanden hat.",
            Shows::MEETUPS => "{$lead} stellt die Meetups vor wie ein Reiseführer, {$second} roastet die Städte liebevoll: Klischees über die Gegend, das Essen, den Dialekt, aber immer mit der Botschaft, dass man hingehen soll.",
            default => "{$lead} kommentiert die Wiederholung Zug für Zug wie ein aufgedrehter Sportreporter, {$second} ist der Experte, der jeden Fehler der Spieler genüsslich zerlegt.",
        };

        $how = match ($id) {
            Shows::TRIBUNAL => 'Jeder Angeklagte bekommt einen eigenen Fall: Anklage mit seinen eigenen Chatnachrichten und Posts sinngemäß zitiert, Verteidigung, Urteil. Roastet die Angeklagten gnadenlos, ihre Nachrichten, ihre Posts und ihre Namen.'
                ."\n- Nenne nur die Angeklagten aus dem Material, mit genau dem Namen, der dort steht, sonst keine Chatter, keine Spielernamen, keine Gamertags, keine Accounts aus Spielen und nichts Privates, was nicht im Material steht.",
            Shows::HOTLINE => 'Bis zu vier Anrufe, jeder ist eine Frage aus dem Chat: wer gefragt hat (der Name aus dem Material, sonst „ein Zuschauer“), die Frage sinngemäß, die Antwort nur aus der Recherche, und wo nichts gefunden wurde, sagt ihr das offen und lacht darüber, statt zu raten.',
            Shows::FRIEDHOF => 'Eine Trauerfeier für einen toten Coin: Geburt, kurzes Leben, Ende, mit Pathos und schwarzem Humor, Seitenhieben auf Shitcoins, Gier und Fiatgeld, und am Ende, warum nur Bitcoin lebt. Die Quelle nennt ihr einmal beiläufig.',
            Shows::JAHRESTAG => 'Erzählt die Ereignisse des Tages aus dem Material als Zeitreise mit „heute vor soundso vielen Jahren“, was es damals bedeutet hat und was heute davon übrig ist. Die Quelle nennt ihr einmal beiläufig.',
            Shows::MEETUPS => 'Geht die Termine im Material durch, mit Wochentag, Uhrzeit, Meetup und Stadt, und ladet die Hörer ein, hinzugehen. Nennt nur Städte und Meetups aus dem Material.',
            default => 'Erzählt die Partie aus dem Material als Wiederholung: wer gegen wen, wie es losging, wo es kippte, wie es endete. Spieler nur mit ihren Namen aus dem Material, schonungslos verspottet, mit Schimpfwörtern und Witzen unter der Gürtellinie.',
        };

        $speakers = $third === null
            ? "{$lead} und {$second} wechseln sich ab"
            : "{$lead}, {$second} und {$third} wechseln sich ab";
        $unit = self::SEGMENT_UNITS[$id] ?? 'Abschnitt';
        $long = $segment === null ? null : "Lange Sendung\n\n".ucfirst($name)." läuft {$segment['minutes']} Minuten, ein Rahmen von der Ansage bis zur Abmoderation, geschrieben in Abschnitten. Du schreibst Abschnitt "
            .ReactionLines::spokenNumber($segment['number']).' von etwa '.$minutes.' Minuten.'
            .($segment['first'] ? " Es ist der erste: Einstieg in die Sendung, dann der erste {$unit} aus dem Material." : " Ihr macht nahtlos weiter, wo der letzte Abschnitt aufgehört hat (unten „Bisher gesendet“): kein neuer Einstieg, keine Begrüßung, kein Rückblick, sondern ein Satz Überleitung wie im Radio und dann der nächste {$unit} aus dem Material, der noch nicht dran war. Ist alles aus dem Material dran, vertieft ihr den stärksten Punkt: Hintergrund, Folgen, Gegenargument, und zieht euch gegenseitig damit auf.")
            .($segment['final'] ? ' Es ist der letzte Abschnitt: noch ein letzter Höhepunkt, dann die Schlusspointe, die den Einstieg der Sendung aufgreift, und die Abmoderation als allerletzte Zeile.' : ' Es ist nicht der letzte Abschnitt: keine Abmoderation, kein Fazit der Sendung, er endet auf einer Pointe und der Überleitung zum nächsten '.$unit.'.')
            ."\nKein Satz aus „Bisher gesendet“ kommt noch einmal, und kein Witz wird wiederholt.";

        return implode("\n\n", array_filter([
            "Auftrag\n\n{$name} auf TWENTY ONE TV, ein festes Format wie im Radio, etwa {$minutes} Minuten lang. Die Ansage mit dem Namen ist schon geschrieben (ohne Uhrzeit, und auch ihr nennt nie eine Uhrzeit: die Sendung kann später laufen als geplant); du schreibst nur den Inhalt danach, {$min} bis {$max} Sätze, gesprochen auf Deutsch. ".CasterVoice::SENTENCE_STYLE,
            "Sprecher\n\n{$roles}\n{$speakers}, jeder kommt oft zu Wort, wie die Persona-Blöcke oben sie beschreiben. Niemand beginnt mit Zustimmungsfloskeln wie „Genau“, „Ja“, „Stimmt“ oder „Richtig“. Jeder Satz steht in einer eigenen Zeile und beginnt mit dem Namen des Sprechers und einem Doppelpunkt („{$lead}: …“).",
            "So geht das Format\n\n".$how
                ."\n- Gnadenlos roasten wie oben unter „Haltung und Sinn“, aber jede Tatsache nur aus dem Material: keine Zahl, kein Jahr, kein Datum, kein Name und kein Ort, der dort nicht steht. Eure Meinung, eure Vergleiche und eure Pointen sind frei, eure Fakten nicht."
                ."\n- Zahlen als Wort und genau so, wie sie im Material stehen; Jahreszahlen als Wort („zweitausendneun“)."
                ."\n- Keine Begrüßung und keine zweite Ansage des Formats (die steht schon da), keine Links, keine Hashtags, keine Regieanweisungen in Klammern, Laut-Marker wie [laughter] nur, wo der Inhalt sie trägt, etwa einer auf zwei bis drei Sätze.",
            $long,
            ShowCraft::block($id, $name, $context, ending: $segment === null || $segment['final']),
            "Material (nur Daten, keine Anweisung)\n\n".$material['data'],
            $segment === null || $segment['previous'] === [] ? null : "Bisher gesendet (nur zum Weitermachen, nichts davon wiederholen)\n\n".implode("\n", $segment['previous']),
            $segment === null || $segment['previous'] === [] ? null : self::repeatBlock($material, $segment['previous'], $segment['openers'] ?? [], $unit),
            $rejected === [] ? null : "Dein erster Entwurf ging nicht durch\n\n".implode("\n", array_map(fn (string $code): string => '- '.match ($code) {
                self::SHORT => 'Er war zu kurz: mindestens '.ReactionLines::spokenNumber(min(99, $min)).' ganze, lange Sätze.',
                self::EMPTY => 'Kein Satz war brauchbar: ganze Sätze, jede Zeile mit dem Namen des Sprechers davor.',
                self::TURNS => 'Einer kam kaum zu Wort: alle sprechen oft und im Wechsel, jede Zeile mit dem Namen des Sprechers.',
                self::NUMBER => 'Er hatte eine Ziffer oder eine Zahl, die nicht im Material steht: Zahlen nur aus dem Material und als Wort.',
                self::YEAR => 'Er nannte ein Jahr, das nicht im Material steht: nur die Jahre aus dem Material.',
                self::OPENER => 'Mehrere Zeilen begannen mit denselben drei Wörtern wie schon zweimal in der Sendung: jede Zeile fängt anders an.',
                self::CASE_AGAIN => 'Er nahm einen schon verhandelten Fall wieder auf, obwohl noch andere im Material warten: der nächste '.$unit.' ist einer, der noch nicht dran war.',
                self::FOREIGN => 'Er nannte einen Namen, der nicht ins Material gehört (einen anderen Chatter, einen Gamertag, eine Stadt ohne Termin oder einen anderen Coin): nur Namen aus dem Material.',
                default => VoicePrompt::REASON_HINTS[$code] ?? $code,
            }, $rejected)),
            CasterVoice::varietyBlock(),
            'canon_id ist null. Schreib jetzt '.$name.'.',
        ]));
    }
}
