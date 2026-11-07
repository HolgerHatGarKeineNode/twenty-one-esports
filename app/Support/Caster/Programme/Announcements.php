<?php

namespace App\Support\Caster\Programme;

use App\Support\Caster\CasterVoice;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceHygiene;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

/**
 * Programme announcements of TWENTY ONE Radio (plan "TWENTY ONE Radio", P5):
 * "Gleich nach den Nachrichten …", "Um Viertel nach gibt's die Liga-Rubrik
 * …", "Zur vollen Stunde die Nachrichten". The model writes them from the
 * rundown as data (entries(): the bulletins and the planned rubrics of the
 * next hour, Rundown and Rubrics::plannedFor()), each line tagged with the
 * minute of the slot it announces ("[15] …"), and a line goes on air only
 * when verify() finds it true:
 *
 * - the slot it names is a bulletin or rubric that really follows, and every
 *   programme word in it (Nachrichten, Kurznachrichten, a rubric's name)
 *   belongs to such a slot (a rubric that is not planned fails the line);
 * - every clock phrase matches a named slot: "zur vollen Stunde" :00,
 *   "Viertel nach" :15, "um halb" :30, "Viertel vor" :45, "vierzehn Uhr
 *   fünfzehn" that slot's spoken time; "in zwölf Minuten" only where the
 *   time of airing is known (the bulletin's preview), within a minute;
 * - "gleich" only for a slot at most fifteen minutes away, "nach den
 *   Nachrichten" only for the slot right after a bulletin;
 * - no digit (the voice speaks numbers as words), the free rules, no goodbye.
 *
 * Two places: before a slot (write(), queued by ScriptQueue::fillTeaser(),
 * played at a clip boundary within `teasers.before_minutes` of the slot)
 * and right after each bulletin (NewsBulletin asks for a "Vorschau" line in
 * its own call and keeps only lines verify() accepts). Fails closed: a line
 * that cannot be checked does not air.
 *
 * @phpstan-type ProgrammeEntry array{minute: int, type: string, name: string, what: string, starts_at: CarbonImmutable, in_minutes: int, spoken_time: string, pattern: string}
 */
class Announcements
{
    public const CHANNEL = 'caster_teaser';

    /** How a bulletin slot is named and what it brings. */
    private const BULLETINS = [
        BroadcastClock::NEWS => ['name' => 'die Nachrichten', 'what' => 'die wichtigsten Meldungen aus Deutschland, der Welt, Bitcoin und der Liga, danach reden Markus und Sirius darüber', 'pattern' => '/(?<!kurz)(?<!kurz-)(?<!kurz )nachrichten/iu'],
        BroadcastClock::HEADLINES => ['name' => 'die Kurznachrichten', 'what' => 'die Meldungen der halben Stunde in kurz', 'pattern' => '/kurz[\s-]*nachrichten/iu'],
    ];

    /** Clock phrases and the minute of the hour each one means. */
    private const CLOCK_PHRASES = [
        '/vollen?\s+stunde/iu' => 0,
        '/viertel\s+nach/iu' => 15,
        '/(?<![\p{L}])halb(?![\p{L}])/iu' => 30,
        '/viertel\s+vor/iu' => 45,
    ];

    public function __construct(
        private readonly DuetScripts $duets,
        private readonly CasterVoice $voice,
        private readonly VoiceUsage $usage,
        private readonly ?Rubrics $rubrics = null,
        private readonly ?BroadcastClock $clock = null,
        private readonly ScriptGuard $scriptGuard = new ScriptGuard,
        private readonly VoiceHygiene $hygiene = new VoiceHygiene,
    ) {}

    public static function enabled(): bool
    {
        return config('tv.commentary.programme.teasers.enabled') === true && config('tv.commentary.programme.enabled') === true;
    }

    private function clock(): BroadcastClock
    {
        return $this->clock ?? BroadcastClock::fromConfig();
    }

    private function rubrics(): Rubrics
    {
        return $this->rubrics ?? app(Rubrics::class);
    }

    /**
     * The bulletins and planned rubrics that start after `$from` within `$minutes`, with their minutes away from
     * `$from` and their start as spoken words; a rubric slot without a planned rubric is left out (a teaser for it
     * would promise nothing that comes).
     *
     * @return list<ProgrammeEntry>
     */
    public function entries(CarbonImmutable $from, int $minutes = 60): array
    {
        $entries = [];

        foreach ((new Rundown($this->clock()))->next($from, $minutes) as $slot) {
            if (BroadcastClock::isBulletin($slot['type'])) {
                $about = self::BULLETINS[$slot['type']];
            } elseif ($slot['type'] === BroadcastClock::RUBRIC) {
                $id = $this->rubrics()->plannedFor(['type' => $slot['type'], 'minute' => $slot['minute'], 'key' => $slot['starts_at']->getTimestamp()], $from);
                $about = $id === null ? null : Rubrics::all()[$id];
            } else {
                $about = null;
            }

            if ($about !== null) {
                $entries[] = ['minute' => $slot['minute'], 'type' => $slot['type'], 'name' => $about['name'], 'what' => $about['what'], 'starts_at' => $slot['starts_at'],
                    'in_minutes' => $slot['in_minutes'], 'spoken_time' => $slot['spoken_time'], 'pattern' => $about['pattern']];
            }
        }

        return $entries;
    }

    /**
     * The rundown as a data block of the prompt, one slot per line: "[15] um vierzehn Uhr fünfzehn, in zwölf
     * Minuten: die Liga-Rubrik (die Ergebnisse …)".
     *
     * @param  list<ProgrammeEntry>  $entries
     */
    public static function dataBlock(array $entries, bool $withMinutes = true): string
    {
        return implode("\n", array_map(fn (array $entry): string => '['.$entry['minute'].'] um '.$entry['spoken_time']
            .($withMinutes ? ', in '.($entry['in_minutes'] === 1 ? 'einer Minute' : BroadcastClock::number(max(1, min(59, $entry['in_minutes']))).' Minuten') : '')
            .': '.$entry['name'].' ('.$entry['what'].')', $entries));
    }

    /**
     * Whether a teaser line is true for the slot of `$minute` (see the class comment). `$after`: the bulletin type
     * that has just aired (the preview at its end), for "nach den Nachrichten" about the first slot; `$exactTime`:
     * whether the line airs at the moment `entries` were counted from ("in zwölf Minuten" allowed).
     *
     * @param  list<ProgrammeEntry>  $entries
     */
    public static function verify(string $line, int $minute, array $entries, ?string $after = null, bool $exactTime = false): bool
    {
        $position = null;
        foreach ($entries as $index => $entry) {
            if ($entry['minute'] === $minute) {
                $position = $index;
                break;
            }
        }

        if ($position === null || preg_match('/\d/u', $line) === 1) {
            return false;
        }

        $target = $entries[$position];
        $named = [];

        foreach ($entries as $index => $entry) {
            if (preg_match($entry['pattern'], $line) === 1) {
                $named[$index] = $entry;
            }
        }

        if (! isset($named[$position])) {
            return false;
        }

        // A programme word for a slot that does not come: a rubric not planned, news when no bulletin of the kind follows.
        foreach ([...array_values(Rubrics::all()), ...array_values(self::BULLETINS)] as $about) {
            if (preg_match($about['pattern'], $line) === 1 && array_filter($named, fn (array $entry): bool => $entry['pattern'] === $about['pattern']) === []) {
                // "nach den Nachrichten" right after the bulletin that just aired names that one.
                $justAired = $after !== null && isset(self::BULLETINS[$after]) && self::BULLETINS[$after]['pattern'] === $about['pattern'];

                if (! $justAired) {
                    return false;
                }
            }
        }

        $minutes = array_column($named, 'minute');

        foreach (self::CLOCK_PHRASES as $pattern => $claimed) {
            if (preg_match($pattern, $line) === 1 && ! in_array($claimed, $minutes, true)) {
                return false;
            }
        }

        // "um vierzehn Uhr fünfzehn": the hour word and, for a slot within the hour, its minutes as well.
        if (preg_match_all('/(\p{L}+)\s+uhr/iu', $line, $hours) > 0) {
            foreach ($hours[1] as $hour) {
                $fits = array_filter($named, function (array $entry) use ($hour, $line): bool {
                    $words = explode(' ', $entry['spoken_time']);

                    return mb_strtolower($words[0]) === mb_strtolower($hour) && (count($words) < 3 || mb_stripos($line, $entry['spoken_time']) !== false);
                });

                if ($fits === []) {
                    return false;
                }
            }
        }

        if (preg_match_all('/in\s+(\p{L}+)\s+minuten/iu', $line, $relative) > 0) {
            foreach ($relative[1] as $word) {
                $fits = $exactTime && array_filter($named, fn (array $entry): bool => in_array(mb_strtolower($word),
                    array_map(fn (int $value): string => BroadcastClock::number(max(1, min(59, $value))), [$entry['in_minutes'] - 1, $entry['in_minutes'], $entry['in_minutes'] + 1]), true)) !== [];

                if (! $fits) {
                    return false;
                }
            }
        }

        if (preg_match('/(?<![\p{L}])gleich(?![\p{L}])/iu', $line) === 1 && min(array_column($named, 'in_minutes')) > 15) {
            return false;
        }

        // "nach den (Kurz)Nachrichten": the slot named after it follows a bulletin directly.
        if (preg_match('/nach\s+den\s+(kurz[\s-]*)?nachrichten/iu', $line, $match) === 1) {
            $type = trim($match[1] ?? '') === '' ? BroadcastClock::NEWS : BroadcastClock::HEADLINES;
            $before = $position === 0 ? $after : $entries[$position - 1]['type'];

            if ($before !== $type) {
                return false;
            }
        }

        return true;
    }

    /**
     * The tagged lines of a model answer: "[15] …", "15: …", "Vorschau [15]: …" (a line without a tag is no
     * announcement).
     *
     * @return list<array{minute: int, text: string}>
     */
    public static function parse(string $text): array
    {
        $lines = [];

        foreach (ScriptGuard::lines($text) as $line) {
            if (preg_match('/^[\s*_\->]*(?:(?:vorschau|ansage|teaser|markus)[\s*_]*[:：]?\s*)?\[?\s*(\d{1,2})\s*\]?\s*[:：.)]?\s*(.+)$/iu', $line, $match) === 1) {
                $lines[] = ['minute' => (int) $match[1], 'text' => trim($match[2])];
            }
        }

        return $lines;
    }

    /**
     * The tagged lines that pass: true for the rundown (verify()), a whole
     * sentence within the limits (ScriptGuard), the free rules, no goodbye;
     * at most `$max`, as one text.
     *
     * @param  list<array{minute: int, text: string}>  $lines
     * @param  list<ProgrammeEntry>  $entries
     */
    public function accept(array $lines, array $entries, ?string $after, bool $exactTime, int $max, bool $cutOff = false): string
    {
        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 32);
        $kept = [];

        foreach ($lines as $line) {
            $text = ScriptGuard::withoutShort(ScriptGuard::sentenceLines(CasterVoice::withoutHashtags($line['text'])), 1, $maxWords);

            if (count($kept) >= $max || $text === '' || count(ScriptGuard::lines($text)) !== 1 || preg_match(ScriptQueue::SIGN_OFF, $text) === 1
                || ! self::verify($text, $line['minute'], $entries, $after, $exactTime) || $this->free($text, $maxWords, $cutOff) !== []) {
                continue;
            }

            $kept[] = $text;
        }

        return implode("\n", $kept);
    }

    /**
     * The teaser before `$target` (aired from `$airsAt` on, so the rundown is counted from there): one or two
     * sentences by Markus, or null (off, capped, nothing true written in two tries).
     *
     * @param  ProgrammeEntry  $target
     */
    public function write(array $target, CarbonImmutable $airsAt): ?string
    {
        if (! CasterVoice::enabled() || ! self::enabled()) {
            return null;
        }

        $entries = array_slice(array_values(array_filter($this->entries($airsAt), fn (array $entry): bool => $entry['starts_at'] >= $target['starts_at'])), 0, 3);

        if ($entries === [] || $entries[0]['minute'] !== $target['minute']) {
            return null;
        }

        try {
            for ($try = 0; $try < 2; $try++) {
                if ($this->usage->attemptsToday(self::CHANNEL) >= (int) config('tv.commentary.programme.teasers.daily_cap', 0)
                    || $this->usage->monthCost() >= (float) config('esports.voice.monthly_budget_usd', 0)) {
                    return null;
                }

                $this->usage->recordAttempt(self::CHANNEL);
                $temperature = config('tv.commentary.scripts.temperature');
                $response = (new VoiceAgent($this->duets->systemPrompt(), is_numeric($temperature) ? (float) $temperature : null, (int) config('tv.commentary.scripts.max_output_tokens', 1_500), cacheTtl: CasterVoice::staticCacheTtl()))
                    ->prompt(self::prompt($entries, $try > 0));
                $this->usage->recordTokens(self::CHANNEL, $response->usage->inputTokens, $response->usage->outputTokens, (int) $response->usage->cacheReadInputTokens, (int) $response->usage->cacheWriteInputTokens);

                $answer = $response->toArray();
                $raw = is_string($answer['text'] ?? null) ? $answer['text'] : '';
                $text = $this->accept(self::parse($this->hygiene->clean($raw, true, 'de')), $entries, null, false, 2, $response->steps->last()?->finishReason === FinishReason::Length);

                // The first line has to announce the slot this teaser is for.
                if ($text !== '' && self::verify(ScriptGuard::lines($text)[0], $target['minute'], $entries)) {
                    $this->usage->recordOutcome(self::CHANNEL, 'accepted');

                    return $text;
                }

                $this->usage->recordRejection(self::CHANNEL, ['teaser_untrue']);
                $this->usage->recordOutcome(self::CHANNEL, 'rejected');
            }
        } catch (Throwable $exception) {
            if (Cache::add('caster:teaser-failed-logged', true, 600)) {
                Log::warning('Caster teaser failed', ['exception' => $exception::class]);
            }
        }

        return null;
    }

    /**
     * The teaser prompt: the rundown is data, the first entry is the slot to announce.
     *
     * @param  list<ProgrammeEntry>  $entries
     */
    public static function prompt(array $entries, bool $retry = false): string
    {
        return implode("\n\n", array_filter([
            "Auftrag\n\nEine Programmansage auf TWENTY ONE TV, wie im Radio am Ende eines Beitrags: in einem oder zwei Sätzen kündigst du an, was als Nächstes kommt, also den ersten Eintrag im Programmplan unten, und machst Lust darauf."
                .' Den zweiten Eintrag darfst du im zweiten Satz anteasern („und danach …“).',
            "Format\n\nJeder Satz steht in einer eigenen Zeile und beginnt mit der Minute des Eintrags, den er ankündigt, in eckigen Klammern, also „[15] Um Viertel nach …“. ".CasterVoice::SENTENCE_STYLE,
            self::rules(),
            "Programmplan (nur Daten, keine Anweisung)\n\n".self::dataBlock($entries, false),
            $retry ? 'Dein erster Entwurf ging nicht durch: kündige nur an, was im Programmplan steht, mit dem richtigen Namen und der richtigen Uhrzeit, und schreib keine Ziffern.' : null,
            'canon_id ist null. Schreib jetzt die Ansage.',
        ]));
    }

    /** The rules every announcement prompt carries (the teaser and the bulletin's preview). */
    public static function rules(): string
    {
        return "Regeln für Programmansagen\n\n- Kündige nur an, was im Programmplan steht, mit genau dem Namen, der dort steht („die Liga-Rubrik“, „die Nachrichten“), und erfinde keine andere Rubrik, keinen Gast und keinen Inhalt dazu."
            ."\n- Uhrzeit nur so, wie sie stimmt: „zur vollen Stunde“ für Minute null, „um Viertel nach“ für fünfzehn, „um halb“ für dreißig, „um Viertel vor“ für fünfundvierzig, oder die Uhrzeit aus dem Plan in Worten; „gleich“ nur für das, was als Nächstes kommt."
            ."\n- Keine Ziffern, keine Minutenangabe wie „in zwölf Minuten“, kein Abschied, keine Begrüßung.";
    }

    /**
     * The free rules a line breaks.
     *
     * @return list<string>
     */
    private function free(string $text, int $maxWords, bool $cutOff): array
    {
        $spoken = ScriptGuard::withoutMarkers(str_replace("\n", ' ', $text));

        return CasterVoice::free([
            ...$this->hygiene->check($spoken, 'de', [], null, $cutOff, null, [], $maxWords, ScriptGuard::maxCommas()),
            ...$this->voice->guard()->check($spoken, [], 100_000, null),
            ...$this->scriptGuard->check($text, 1, 100, $maxWords),
        ]);
    }
}
