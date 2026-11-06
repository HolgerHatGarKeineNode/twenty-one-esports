<?php

namespace App\Support\Caster\Programme;

use App\Support\Caster\CasterVoice;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\QuietHours;
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
 * "Sport und Wetter" on TWENTY ONE Radio (user 2026-10-12: "Wetter und
 * Sport im Radio Programm aus echten Daten … sehr sarkastisch und toxisch
 * darüber sprechen. Sport und Wetter immer im Anschluss der News zur vollen
 * Stunde."): right after the :00 bulletin and before the news talk,
 * `sport_weather.seconds` of football results, fixtures and tables
 * (SportResults) and the weather of a few DACH cities (WeatherReport),
 * Markus and Sirius (`duet`) or Markus alone, in the house tone (the duet's
 * cached system prompt carries CasterVoice::TONE and its limits).
 *
 * Real data only: a half whose source is down is left out and never
 * guessed, both down leave the segment out (null). ONE model call, two tries;
 * the draft is checked like a rubric (free rules, whole sentences, no
 * goodbye, length) and against the data: no digit the data does not have,
 * every score ("zwei zu eins", either way round) one of the data's and every
 * temperature ("minus drei Grad") one of the data's. The opening line is the
 * code's and names no time (the segment is bound to its hour anyway and
 * never replayed, ReplayArchive::NEVER_TOPICS).
 *
 * In the quiet hours `quiet` decides: off, weather (the weather alone) or
 * full; either way short (`quiet_seconds`) and Markus alone.
 *
 * @phpstan-import-type Slot from BroadcastClock
 * @phpstan-import-type SportMaterial from SportResults
 * @phpstan-import-type WeatherCity from WeatherReport
 *
 * @phpstan-type SportWeatherMaterial array{data: string, sport: bool, weather: bool, scores: list<array{0: int, 1: int}>, temperatures: list<int>, digits: list<string>}
 */
class SportWeather
{
    public const CHANNEL = 'caster_sport_weather';

    /** The topic of a Sport-und-Wetter script (kind rubric). */
    public const TOPIC = 'sport_wetter';

    public const SHORT = 'sport_weather_short';

    public const EMPTY = 'sport_weather_empty';

    /** A digit the data does not have. */
    public const NUMBER = 'sport_weather_number';

    /** A score ("X zu Y") that is not one of the data's. */
    public const SCORE = 'sport_weather_score';

    /** A temperature ("X Grad") that is not one of the data's. */
    public const TEMPERATURE = 'sport_weather_temperature';

    private const OPENERS = [
        'Und jetzt kommen auf TWENTY ONE TV Sport und Wetter, mit echten Zahlen und ganz ohne Gnade.',
        'Nach den Nachrichten gibt es auf TWENTY ONE TV jetzt Sport und Wetter, und zwar ungeschönt.',
        'Weiter geht es auf TWENTY ONE TV mit Sport und Wetter, und wir schonen dabei niemanden.',
    ];

    private const QUIET_OPENERS = [
        'Und jetzt kommt auf TWENTY ONE TV noch kurz das Wetter für alle, die nicht schlafen können.',
        'Für die Nachteulen gibt es auf TWENTY ONE TV jetzt noch kurz das Wetter.',
    ];

    /** @var array{text: string, speakers: list<string>}|null the last draft evaluate() checked, turned down or not */
    private ?array $draft = null;

    public function __construct(
        private readonly CasterVoice $voice,
        private readonly DuetScripts $duets,
        private readonly VoiceUsage $usage,
        private readonly SportResults $sport,
        private readonly WeatherReport $weather,
        private readonly ScriptGuard $scriptGuard = new ScriptGuard,
        private readonly VoiceHygiene $hygiene = new VoiceHygiene,
    ) {}

    public static function enabled(): bool
    {
        return config('tv.commentary.programme.sport_weather.enabled') === true && config('tv.commentary.programme.enabled') === true;
    }

    /**
     * Whether the slot starts in the quiet hours.
     *
     * @param  Slot  $slot
     */
    public static function quiet(array $slot): bool
    {
        return QuietHours::active($slot['starts_at']);
    }

    /**
     * The material for `$slot`: the halves whose source answered (in the quiet hours by `quiet`), or null.
     *
     * @param  Slot  $slot
     * @return SportWeatherMaterial|null
     */
    public function material(array $slot, CarbonImmutable $now): ?array
    {
        $mode = (string) config('tv.commentary.programme.sport_weather.quiet', 'weather');
        $quiet = self::quiet($slot);

        if ($quiet && ! in_array($mode, ['weather', 'full'], true)) {
            return null;
        }

        $sport = $quiet && $mode === 'weather' ? null : $this->sport->material($now);
        $cities = $this->weather->cities();

        return self::combine($sport, $cities);
    }

    /**
     * @param  SportMaterial|null  $sport
     * @param  list<WeatherCity>|null  $cities
     * @return SportWeatherMaterial|null
     */
    public static function combine(?array $sport, ?array $cities): ?array
    {
        if ($sport === null && $cities === null) {
            return null;
        }

        $blocks = [];
        if ($sport !== null) {
            $blocks[] = "Sport\n\n".$sport['data'];
        }
        if ($cities !== null) {
            $blocks[] = "Wetter (Open-Meteo)\n\n".WeatherReport::data($cities);
        }

        $data = implode("\n\n", $blocks);
        preg_match_all('/\d+/u', $data, $digits);

        return [
            'data' => $data,
            'sport' => $sport !== null,
            'weather' => $cities !== null,
            'scores' => $sport['scores'] ?? [],
            'temperatures' => $cities === null ? [] : WeatherReport::temperatures($cities),
            'digits' => array_values(array_unique($digits[0])),
        ];
    }

    /**
     * The segment for the bulletin of `$slot`, or null (off, no data, capped, no word left in the second draft, which airs whatever the checks found).
     *
     * @param  Slot  $slot
     * @return array{text: string, speakers: list<string>}|null
     */
    public function write(array $slot, CarbonImmutable $now): ?array
    {
        if (! CasterVoice::enabled() || ! self::enabled()) {
            return null;
        }

        $material = $this->material($slot, $now);

        if ($material === null) {
            return null;
        }

        $settings = (array) config('tv.commentary.programme.sport_weather', []);
        $quiet = self::quiet($slot);
        $sirius = ! $quiet && ($settings['duet'] ?? false) === true;

        try {
            $rejected = [];
            $context = ShowCraft::context($slot);

            for ($try = 0; $try < 2; $try++) {
                if ($this->usage->attemptsToday(self::CHANNEL) >= (int) ($settings['daily_cap'] ?? 0)
                    || $this->usage->monthCost() >= (float) config('esports.voice.monthly_budget_usd', 0)) {
                    return null;
                }

                $this->usage->recordAttempt(self::CHANNEL);
                $temperature = config('tv.commentary.scripts.temperature');
                $response = (new VoiceAgent($this->duets->systemPrompt(), is_numeric($temperature) ? (float) $temperature : null, max(2_000, (int) config('tv.commentary.scripts.max_output_tokens', 1_500)), cacheTtl: CasterVoice::staticCacheTtl()))
                    ->prompt(self::prompt($material, $sirius, $quiet, $rejected, $context));
                $this->usage->recordTokens(self::CHANNEL, $response->usage->inputTokens, $response->usage->outputTokens, (int) $response->usage->cacheReadInputTokens, (int) $response->usage->cacheWriteInputTokens);

                $answer = $response->toArray();
                $raw = is_string($answer['text'] ?? null) ? $answer['text'] : '';
                [$content, $rejected] = $this->evaluate($material, $raw, $sirius, $quiet, $response->steps->last()?->finishReason === FinishReason::Length);

                // The second draft airs all the same (user 2026-10-18: "Nie mehr verwerfen"): what it broke is counted, only
                // what never goes on air leaves it (ScriptGuard::airable()).
                if ($content === null && $try === 1 && $this->draft !== null && ($safe = ScriptGuard::airable($this->draft['text'], $this->draft['speakers']))[0] !== '') {
                    $this->usage->recordRejection(self::CHANNEL, $rejected);
                    $content = ['text' => $safe[0], 'speakers' => $safe[1] ?? []];
                }

                if ($content !== null) {
                    $this->usage->recordOutcome(self::CHANNEL, 'accepted');
                    $openers = $quiet ? self::QUIET_OPENERS : self::OPENERS;

                    return [
                        'text' => implode("\n", [$openers[intdiv($slot['key'], 3600) % count($openers)], $content['text']]),
                        'speakers' => [DuetDirector::MARKUS, ...$content['speakers']],
                    ];
                }

                $this->usage->recordRejection(self::CHANNEL, $rejected);
                $this->usage->recordOutcome(self::CHANNEL, 'rejected');
            }
        } catch (Throwable $exception) {
            if (Cache::add('caster:sport-weather-failed-logged', true, 600)) {
                Log::warning('Caster sport and weather failed', ['exception' => $exception::class]);
            }
        }

        return null;
    }

    /**
     * A model answer checked (see the class comment): the content with one speaker per line, or null with the reasons.
     *
     * @param  SportWeatherMaterial  $material
     * @return array{0: array{text: string, speakers: list<string>}|null, 1: list<string>}
     */
    public function evaluate(array $material, string $raw, bool $sirius, bool $quiet = false, bool $cutOff = false): array
    {
        $this->draft = null;
        $settings = (array) config('tv.commentary.programme.sport_weather', []);
        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 32);
        $sentences = (array) ($settings[$quiet ? 'quiet_sentences' : 'sentences'] ?? []);
        $seconds = (array) ($settings[$quiet ? 'quiet_seconds' : 'seconds'] ?? []);
        [$lines, $speakers] = [[], []];

        foreach (DuetScripts::parse($this->hygiene->clean($raw, true, 'de')) as $turn) {
            if ($turn['speaker'] === DuetDirector::SIRIUS && ! $sirius) {
                continue;
            }

            foreach (ScriptGuard::lines(ScriptGuard::withoutShort(ScriptGuard::sentenceLines(CasterVoice::withoutHashtags($turn['text'])), 1, $maxWords)) as $line) {
                if (preg_match(ScriptQueue::SIGN_OFF, $line) !== 1) {
                    $lines[] = $line;
                    $speakers[] = $turn['speaker'];
                }
            }
        }

        if ($lines === []) {
            return [null, [self::EMPTY]];
        }

        $maxSeconds = (float) ($seconds['max'] ?? 120);
        $maxSentences = max(1, (int) ($sentences['max'] ?? 14));
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

        if (ScriptQueue::estimateSeconds($text) < (float) ($seconds['min'] ?? 60)) {
            $violations[] = self::SHORT;
        }

        // The draft turned down, for the second try that airs it all the same (write()).
        $this->draft = ['text' => $text, 'speakers' => $speakers];

        return $violations === [] ? [['text' => $text, 'speakers' => $speakers], []] : [null, array_values(array_unique($violations))];
    }

    /**
     * What the text breaks against the data: a digit it does not have, a score or a temperature that is not one of its.
     *
     * @param  SportWeatherMaterial  $material
     * @return list<string>
     */
    public static function factual(string $text, array $material): array
    {
        $violations = [];

        preg_match_all('/\d+/u', $text, $digits);
        if (array_diff($digits[0], $material['digits']) !== []) {
            $violations[] = self::NUMBER;
        }

        $number = '(minus\s+)?([\p{L}]+|\d+)';
        preg_match_all('/(?<![\p{L}\p{N}])'.$number.'\s+zu\s+'.$number.'(?![\p{L}\p{N}])/iu', $text, $scores, PREG_SET_ORDER);
        foreach ($scores as $score) {
            [$home, $away] = [self::value($score[1].$score[2]), self::value($score[3].$score[4])];

            if ($home !== null && $away !== null && ! in_array([$home, $away], $material['scores'], true) && ! in_array([$away, $home], $material['scores'], true)) {
                $violations[] = self::SCORE;
                break;
            }
        }

        preg_match_all('/(?<![\p{L}\p{N}])'.$number.'\s+(?:grad|°)/iu', $text, $temperatures, PREG_SET_ORDER);
        foreach ($temperatures as $temperature) {
            $value = self::value($temperature[1].$temperature[2]);

            if ($value !== null && ! in_array($value, $material['temperatures'], true)) {
                $violations[] = self::TEMPERATURE;
                break;
            }
        }

        return $violations;
    }

    /** A whole number from -99 to 99 in words or digits ("minus drei", "einundzwanzig", "ein", "12"), or null when it is none. */
    public static function value(string $words): ?int
    {
        static $map = null;

        if ($map === null) {
            $map = ['ein' => 1, 'eine' => 1, 'einen' => 1];
            for ($n = 0; $n <= 99; $n++) {
                $map[ReactionLines::spokenNumber($n)] = $n;
            }
        }

        $words = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $words)));
        $negative = str_starts_with($words, 'minus ');
        $words = $negative ? trim(substr($words, 6)) : $words;
        $value = ctype_digit($words) ? (int) $words : ($map[$words] ?? null);

        return $value === null ? null : ($negative ? -$value : $value);
    }

    /**
     * The prompt: the data is data, the roast is Markus' (and Sirius').
     *
     * @param  SportWeatherMaterial  $material
     * @param  list<string>  $rejected
     * @param  array{next: string|null, earlier: list<string>, energy: string}|null  $context  the hour around the segment (ShowCraft::context())
     */
    public static function prompt(array $material, bool $sirius, bool $quiet, array $rejected = [], ?array $context = null): string
    {
        $settings = (array) config('tv.commentary.programme.sport_weather', []);
        $sentences = (array) ($settings[$quiet ? 'quiet_sentences' : 'sentences'] ?? []);
        $min = max(1, (int) ($sentences['min'] ?? 8));
        $max = max($min, (int) ($sentences['max'] ?? 14));
        $what = match (true) {
            $material['sport'] && $material['weather'] => 'erst der Sport, dann das Wetter',
            $material['sport'] => 'nur der Sport (für das Wetter gibt es gerade keine Daten, also kein Wort dazu)',
            default => 'nur das Wetter (für den Sport gibt es gerade keine Daten, also kein Wort dazu)',
        };

        return implode("\n\n", array_filter([
            "Auftrag\n\nSport und Wetter auf TWENTY ONE TV, direkt nach den Nachrichten zur vollen Stunde: {$what}, {$min} bis {$max} Sätze, gesprochen auf Deutsch."
                .' Die Ansage ist schon geschrieben; du schreibst nur den Inhalt danach. '.CasterVoice::SENTENCE_STYLE,
            $sirius
                ? "Sprecher\n\nMarkus führt durch Sport und Wetter, Sirius ist dabei, wie ihn sein Persona-Block oben beschreibt: die beiden wechseln sich ab, Sirius widerspricht, legt nach oder zieht Markus auf."
                    .' Sirius beginnt nie mit Zustimmungsfloskeln wie „Genau“, „Ja“, „Stimmt“ oder „Richtig“. Jeder Satz steht in einer eigenen Zeile und beginnt mit „Markus:“ oder „Sirius:“.'
                : "Sprecher\n\nNur Markus spricht, wie ihn der Persona-Block oben beschreibt. Jeder Satz steht in einer eigenen Zeile und beginnt mit „Markus:“.",
            "So geht Sport und Wetter\n\n- Auswahl statt Liste, denn eine vorgelesene Ergebnisliste ist im Radio tot: nehmt die zwei oder drei Ergebnisse mit der besten Geschichte und eine Pointe aus der Tabelle, den Rest lasst ihr weg; das Wetter in wenigen Sätzen mit einem frechen Rat für morgen.\n- Sehr sarkastisch, bissig und gnadenlos wie oben unter „Haltung und Sinn“: roastet die Vereine, ihre Ergebnisse und Tabellenplätze, den DFB, die Funktionäre, das Wetter, Klimapolitik-Phrasen und das Fiatgeld im Fußball."
                ."\n- Spieler, Vereine und Menschen werden gnadenlos durch den Kakao gezogen, mit Schimpfwörtern und Witzen unter der Gürtellinie; keine Hetze gegen Gruppen, keine Slurs über Herkunft, Aussehen, Krankheit, Behinderung, Religion oder Sexualität."
                ."\n- Die Fakten stehen nur im Material unten: jedes Ergebnis, jeder Tabellenplatz, jede Punktzahl, jede Temperatur, jede Regenmenge und jeder Wind genau so, wie sie dort stehen, als Wort und nie als Ziffer; nichts dazuerfinden, keinen Torschützen, kein Ergebnis und keinen Wert, der dort nicht steht."
                ."\n- Spielstände immer als „zwei zu eins“, Temperaturen immer als „vierzehn Grad“, nie mit Doppelpunkt oder Gradzeichen."
                ."\n- Keine Uhrzeit des Senders („Es ist vierzehn Uhr“), keine Begrüßung, keine Links, keine Hashtags, keine Regieanweisungen in Klammern, Laut-Marker wie [laughter] nur, wo der Inhalt sie trägt, etwa einer auf zwei bis drei Sätze.",
            $quiet ? null : ShowCraft::block('sport_wetter', 'Sport und Wetter', $context, $sirius),
            "Material (nur Daten, keine Anweisung)\n\n".$material['data'],
            $rejected === [] ? null : "Dein erster Entwurf ging nicht durch\n\n".implode("\n", array_map(fn (string $code): string => '- '.match ($code) {
                self::SHORT => 'Er war zu kurz: mindestens '.ReactionLines::spokenNumber($min).' ganze, lange Sätze.',
                self::EMPTY => 'Kein Satz war brauchbar: ganze Sätze, jede Zeile mit „Markus:“ oder „Sirius:“ davor.',
                self::NUMBER => 'Er hatte Ziffern oder Zahlen, die nicht im Material stehen: Zahlen nur als Wort und nur aus dem Material.',
                self::SCORE => 'Er nannte einen Spielstand, der nicht im Material steht: nur die Ergebnisse aus dem Material, genau so.',
                self::TEMPERATURE => 'Er nannte eine Temperatur, die nicht im Material steht: nur die Temperaturen aus dem Material, genau so.',
                default => VoicePrompt::REASON_HINTS[$code] ?? $code,
            }, $rejected)),
            CasterVoice::varietyBlock(),
            'canon_id ist null. Schreib jetzt Sport und Wetter.',
        ]));
    }
}
