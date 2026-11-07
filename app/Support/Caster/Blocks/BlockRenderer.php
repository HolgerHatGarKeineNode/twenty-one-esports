<?php

namespace App\Support\Caster\Blocks;

use App\Models\CasterScript;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\Idents;
use App\Support\Caster\Pcm;
use App\Support\Caster\Programme\TalkShow;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Renders a programme block on the workstation (plan "TWENTY ONE Radio",
 * P6; user 2026-10-12: "Die Composition soll lokal erfolgen und zu einem
 * flüssigeren Radiosender führen"): the scripts in the running order of
 * BlockComposer, voiced in one batch per speaker (Sirius' lines of the whole
 * block first, then Markus': a profile switch costs VoiceStudio seconds,
 * user: "der Wechsel des Stimmprofils dauert"), then assembled in memory:
 *
 *  - the Anchor's jingles (JingleLibrary) `jingles.gain_db` under the speech
 *    level, the next speech starting over their last `crossfade_seconds`;
 *    without a jingle library the bulletin still gets its P4 stinger (Idents);
 *  - the pauses of CasterFetcher::voice() within a script, `pause_seconds`
 *    between two scripts;
 *  - short agreeing blips of the other speaker of a duet (user: "kleine
 *    Blips der Zustimmung des jeweils anderen (ja, lachen, hm, stimmt …)"),
 *    `blips.gain_db` under a turn of at least `min_turn_seconds`, at `at` of
 *    the turn, on about `share` of such turns and `min_gap_seconds` apart;
 *    with three voices (the talk show) the blip is the one the turn answers,
 *    and across the sides (`opponents`) only an `across` sound, a laugh or a doubt;
 *  - the news bed under a bulletin only where Idents::bedFor() says so, and
 *    the bed of its format under a show, roast or rubric (Idents::formatBed(),
 *    P20: the server never dresses a block, so this is the only place);
 *  - the studio (P20, StudioAudience): applause in the pauses of a show, the
 *    talk show's theme around it, the talk show's own breath between turns
 *    (TalkShow::gapFor()); a pause grows to hold its sound, never over a word.
 *
 * A show queued in parts is one show, not one per part (P20 probe 2026-10-13:
 * the 30-minute talk in 39 parts aired 75 theme plays, 51 applause, a bed
 * fading out and in at every part and 39.3 minutes for its 30): the parts of
 * one story in a block are one run under one bed, the turn's breath between
 * them, the theme and applause placed once per episode over all its blocks
 * (studioPlan(), the episode's earlier blocks remembered in the cache).
 *
 * The result is raw PCM with its chunk ends (sentence ends for a cut), the
 * segment boundaries (the end of every script but the last: a reaction may
 * cut in there) and the protected opening (the news of a news block). The
 * caller encodes it in one loudness pass (CasterFetcher::encode()).
 */
final class BlockRenderer
{
    /** @var Closure(): float */
    private Closure $random;

    /**
     * @param  (Closure(): float)|null  $random  a number in [0, 1)
     * @param  (Closure(): void)|null  $between  called after every VoiceStudio call (BlockFetcher answers urgent scripts there)
     */
    public function __construct(
        private readonly CasterFetcher $fetcher,
        private readonly JingleLibrary $jingles,
        private readonly ?Idents $idents = null,
        ?Closure $random = null,
        private readonly ?Closure $between = null,
    ) {
        $this->random = $random ?? fn (): float => mt_rand(0, 999) / 1000;
    }

    /**
     * @param  list<array<array-key, mixed>>  $scripts  claimed scripts in order, the first the lead
     * @param  array<string, string>  $terms
     * @return array{pcm: string, rate: int, ends: list<float>, boundaries: list<float>, protected: float, steps: list<string>, blips: list<array{at: float, speaker: string, file: string}>, studio: list<array{at: float, part: string, file: string, seconds: float}>, batches: list<string>}|null null when VoiceStudio failed
     */
    public function render(array $scripts, array $terms, CarbonImmutable $airAt, bool $evergreen = false): ?array
    {
        $settings = (array) config('tv.commentary.blocks', []);
        $voice = $this->fetcher->settings();

        // Every chunk of the block, then voiced speaker by speaker.
        $chunks = [];
        foreach ($scripts as $index => $script) {
            ['sentences' => $sentences, 'speakers' => $speakers, 'duet' => $duet] = CasterFetcher::lines($script);

            foreach ($this->fetcher->plannedChunks($sentences, $duet ? $speakers : null, $script['kind'] ?? null) as [$speaker, $lines]) {
                $chunks[] = ['script' => $index, 'speaker' => $speaker, 'lines' => $lines];
            }
        }

        if ($chunks === []) {
            return null;
        }

        $order = array_values(array_unique(array_column($chunks, 'speaker')));
        usort($order, fn (string $a, string $b): int => self::batchRank($a) <=> self::batchRank($b));
        $voiced = [];
        $rate = null;

        foreach ($order as $speaker) {
            foreach ($chunks as $key => $chunk) {
                if ($chunk['speaker'] !== $speaker) {
                    continue;
                }

                $result = $this->fetcher->voiceChunk($chunk['lines'], $speaker, $terms);

                if ($result === null || ($rate !== null && $result['rate'] !== $rate)) {
                    return null;
                }

                $rate = $result['rate'];
                $voiced[$key] = $result['pcm'];

                if ($this->between !== null) {
                    ($this->between)();
                }
            }
        }

        $rate = (int) $rate;
        $plan = $this->studioPlan($scripts, $chunks, $voiced, $rate);
        $interruptions = $plan['interruptions'];
        // The bed of a show's format under its whole run (where the station sound plays it at air time; a stock block airs
        // at an unknown hour, by day as well, so it always gets it: P20 probe, a stock block rendered at night had none).
        $bedsOn = $this->idents !== null && ($evergreen || $this->idents->bedsAt($airAt));
        $runStart = null;
        $steps = BlockComposer::plan($scripts, $airAt, (float) ($settings['jingles']['sweeper_share'] ?? 0.5), $this->random, evergreen: $evergreen);
        $crossfade = (int) round((float) ($settings['crossfade_seconds'] ?? 0.45) * $rate);
        $jingleGain = (float) ($settings['jingles']['gain_db'] ?? -3.0);

        $pcm = '';
        $ends = [];
        $boundaries = [];
        $protected = 0.0;
        $afterJingle = false;
        $log = [];
        $blips = [];
        $studio = [];
        $lastBlip = -INF;

        foreach ($steps as $step) {
            if ($step['type'] === BlockComposer::STEP_JINGLE) {
                $jingle = $this->jingle($step['categories'] ?? [], $scripts, $airAt, $rate, $jingleGain);

                if ($jingle === null) {
                    continue;
                }

                if ($pcm !== '') {
                    $pcm .= Pcm::silence($this->between((array) ($settings['pause_seconds'] ?? [])) / 2, $rate);
                }

                $pcm .= $jingle['pcm'];
                $afterJingle = true;
                $log[] = 'jingle:'.$jingle['id'];

                continue;
            }

            $index = (int) ($step['index'] ?? 0);
            $script = $scripts[$index];
            $run = $plan['runs'][$index];
            $speech = $this->speech($index, $chunks, $voiced, $rate, $voice, $script, $settings, $lastBlip, Pcm::seconds($pcm, $rate), $blips, $plan, $interruptions[$run]);

            if ($speech === null) {
                continue;
            }

            $sameStory = $index > 0 && ($plan['runs'][$index - 1] ?? null) === $run;

            if ($afterJingle) {
                $start = max(0, intdiv(strlen($pcm), Pcm::BYTES_PER_SAMPLE) - $crossfade);
            } else {
                if ($pcm !== '') {
                    // The next part of the same show follows like the next turn (its breath), never a gap between shows.
                    $pcm .= Pcm::silence($sameStory ? $this->partGap($index, $chunks, $script, $voice, $interruptions[$run]) : $this->between((array) ($settings['pause_seconds'] ?? [])), $rate);
                }

                $start = intdiv(strlen($pcm), Pcm::BYTES_PER_SAMPLE);
            }

            if (! $sameStory || $runStart === null) {
                $runStart = $start;
            }

            // The news bed under a bulletin, where the station sound asks for it at air time (Idents::bedFor()).
            if (($script['kind'] ?? null) === CasterScript::BULLETIN && $this->idents !== null && $this->idents->bedFor(CasterScript::BULLETIN, $airAt)) {
                $speech['pcm'] = (new Idents((array) config('tv.commentary.idents', []), $rate))->underBed($speech['pcm']) ?? $speech['pcm'];
            }

            $offset = $start / $rate;
            $pcm = Pcm::overlay($pcm, $speech['pcm'], $start);
            $afterJingle = false;
            $log[] = 'script:'.($script['id'] ?? $index);

            // The bed of its format under a show, roast or rubric (P17's `format_beds`; the server never dresses a rendered
            // block, so before P20 no show of a block had one), once under the whole run of its parts: under each part
            // alone it faded out and in again every five lines.
            $formatBed = $bedsOn && ($script['kind'] ?? null) !== CasterScript::BULLETIN ? $this->idents->formatBed($script['topic'] ?? null) : null;

            if ($formatBed !== null && ($plan['runs'][$index + 1] ?? null) !== $run) {
                $head = $runStart * Pcm::BYTES_PER_SAMPLE;
                $bedded = (new Idents((array) config('tv.commentary.idents', []), $rate))->underBed(substr($pcm, $head), $formatBed['file'], $formatBed['gain_db'] + self::speechOffset());

                if ($bedded !== null) {
                    $pcm = substr($pcm, 0, $head).$bedded;
                    $log[] = 'bed:'.basename($formatBed['file']);
                }
            }

            foreach ($speech['ends'] as $end) {
                $ends[] = round($offset + $end, 3);
            }

            foreach ($speech['studio'] as $sound) {
                $studio[] = ['at' => round($offset + $sound['at'], 3), 'part' => $sound['part'], 'file' => $sound['file'], 'seconds' => $sound['seconds']];
            }

            foreach ($speech['blips'] as $blip) {
                $blips[] = ['at' => round($offset + $blip['at'], 3), 'speaker' => $blip['speaker'], 'file' => $blip['file']];
                $lastBlip = $offset + $blip['at'];
            }

            $end = round($offset + Pcm::seconds($speech['pcm'], $rate), 3);
            $boundaries[] = $end;

            if (($script['kind'] ?? null) === CasterScript::BULLETIN && $index === 0) {
                $protected = $end;
            }
        }

        if ($pcm === '' || $ends === []) {
            return null;
        }

        // The block's own end is no boundary inside it.
        array_pop($boundaries);

        return [
            'pcm' => $pcm,
            'rate' => $rate,
            'ends' => $ends,
            'boundaries' => $boundaries,
            'protected' => $protected,
            'steps' => $log,
            'blips' => $blips,
            'studio' => $studio,
            'batches' => $order,
        ];
    }

    /**
     * One script's speech from its voiced chunks with the pauses of
     * CasterFetcher::voice(), and the blips of the other speaker of a duet.
     *
     * @param  list<array{script: int, speaker: string, lines: list<string>}>  $chunks
     * @param  array<int, string>  $voiced
     * @param  array<string, mixed>  $voice  tv.commentary.voicestudio
     * @param  array<array-key, mixed>  $script
     * @param  array<string, mixed>  $settings  tv.commentary.blocks
     * @param  list<array{at: float, speaker: string, file: string}>  $placed  the blips of the block so far
     * @param  array{runs: array<int, string>, applause: array<int, string>, before: array<int, string>, outro: array<int, true>, interruptions: array<string, int>}  $plan  studioPlan()
     * @param  int  $interruptions  the cut-ins its show has left (TalkShow::maxInterruptions() per episode)
     * @return array{pcm: string, ends: list<float>, blips: list<array{at: float, speaker: string, file: string}>, studio: list<array{at: float, part: string, file: string, seconds: float}>}|null
     */
    private function speech(int $index, array $chunks, array $voiced, int $rate, array $voice, array $script, array $settings, float $lastBlip, float $blockOffset, array $placed, array $plan, int &$interruptions): ?array
    {
        $mine = array_values(array_filter(array_keys($chunks), fn (int $key): bool => $chunks[$key]['script'] === $index));

        if ($mine === []) {
            return null;
        }

        $pause = (array) ($voice['pause'] ?? []);
        $topic = $script['topic'] ?? null;
        // The talk show's own breath between turns (TalkShow::gapFor(): a TV talk's pause, a cut-in, the long breath).
        $speakerPause = TalkShow::turnGap($topic) ?? (array) ($voice['speaker_pause'] ?? []);
        $speakers = array_values(array_unique(array_map(fn (int $key): string => $chunks[$key]['speaker'], $mine)));
        $studio = [];
        $overlays = [];
        $duet = count($speakers) > 1;
        $blipSettings = (array) ($settings['blips'] ?? []);
        $pcm = '';
        $ends = [];
        $blips = [];
        $lastFile = $placed === [] ? null : $placed[count($placed) - 1]['file'];

        // The theme before the script's first chunk (the welcome or an act break opening a part): its own lead-in.
        if (isset($plan['before'][$mine[0]])) {
            $pcm = $this->studioGap($pcm, $rate, 0.0, null, $plan['before'][$mine[0]], $studio, $overlays);
        }

        foreach ($mine as $position => $key) {
            $speaker = $chunks[$key]['speaker'];
            $turnStart = intdiv(strlen($pcm), Pcm::BYTES_PER_SAMPLE);
            $turn = $voiced[$key];
            $turnSeconds = Pcm::seconds($turn, $rate);
            $pcm .= $turn;
            $ends[] = round(Pcm::seconds($pcm, $rate), 3);

            // An agreeing sound of the other speaker of the duet under a long enough turn, sparingly.
            if ($duet && $turnSeconds >= (float) ($blipSettings['min_turn_seconds'] ?? 3.5)) {
                $at = $turnStart / $rate + $turnSeconds * $this->within((array) ($blipSettings['at'] ?? []), 0.55, 0.8);

                if ($blockOffset + $at - $lastBlip >= (float) ($blipSettings['min_gap_seconds'] ?? 25) && ($this->random)() < (float) ($blipSettings['share'] ?? 0.35)) {
                    $other = self::blipSpeaker($chunks, $mine, $position, $speaker) ?? DuetDirector::SIRIUS;
                    $opponents = array_values(array_filter((array) ($blipSettings['opponents'] ?? []), 'is_string'));
                    // Across the sides of the talk show only a laugh or a doubting sound (`across`).
                    $across = in_array($speaker, $opponents, true) !== in_array($other, $opponents, true);
                    $files = array_values(array_filter($this->jingles->blips($other, $across ? array_values(array_filter((array) ($blipSettings['across'] ?? []), 'is_string')) : null), fn (string $file): bool => $file !== $lastFile));
                    $file = $files === [] ? null : $files[(int) floor(($this->random)() * count($files)) % count($files)];
                    $blip = $file === null ? null : $this->jingles->pcm($file, $rate);

                    if ($file !== null && $blip !== null) {
                        $pcm = Pcm::overlay($pcm, $blip, (int) round($at * $rate), (float) ($blipSettings['gain_db'] ?? -12.0));
                        $blips[] = ['at' => round($at, 3), 'speaker' => $other, 'file' => $file];
                        $lastBlip = $blockOffset + $at;
                        $lastFile = $file;
                    }
                }
            }

            if ($position === count($mine) - 1) {
                // Behind the last line: the closing applause and the talk show's outro.
                $pcm = $this->studioTail($pcm, $rate, $plan['applause'][$key] ?? null, isset($plan['outro'][$key]), $studio);

                continue;
            }

            $next = $chunks[$mine[$position + 1]];

            if ($next['speaker'] !== $speaker) {
                $kind = TalkShow::gapKind($next['speaker'], $next['lines']);

                if ($kind === TalkShow::GAP_INTERRUPT && $interruptions-- <= 0) {
                    $kind = TalkShow::GAP_TURN;
                }

                $gap = $this->within(TalkShow::gapFor($topic, $kind) ?? $speakerPause, 0.45, 0.7);
            } else {
                $base = CasterFetcher::sentencePause((string) end($chunks[$key]['lines']), $pause, $script['kind'] ?? null);
                $gap = max(0.1, $base + (float) ($pause['jitter'] ?? 0.0) * (2 * ($this->random)() - 1));
            }

            $pcm = $this->studioGap($pcm, $rate, $gap, $plan['applause'][$key] ?? null, $plan['before'][$mine[$position + 1]] ?? null, $studio, $overlays);
        }

        foreach ($overlays as [$sound, $at]) {
            $pcm = Pcm::overlay($pcm, $sound, $at);
        }

        // A blip may reach past the last word: the speech is as long as both.
        return ['pcm' => $pcm, 'ends' => $ends, 'blips' => $blips, 'studio' => $studio];
    }

    /**
     * The pause after a chunk, `$gap` seconds or longer: the applause after the chunk (0.2 s in, the next word only
     * after its last clap) and the theme before the next one (the intro ends `talk_theme.overlap_seconds` into the next
     * voice, that tail 12 dB down and faded out; the sting ends before it).
     *
     * @param  list<array{at: float, part: string, file: string, seconds: float}>  $studio
     * @param  list<array{0: string, 1: int}>  $overlays  the sounds to lay on once the speech is complete, with their sample
     */
    private function studioGap(string $pcm, int $rate, float $gap, ?string $moment, ?string $themePart, array &$studio, array &$overlays): string
    {
        $start = Pcm::seconds($pcm, $rate);
        $clap = $moment === null ? null : $this->applausePcm($moment, $rate, $studio);
        $music = $themePart === null ? null : $this->themePcm($themePart, $rate);
        $overlap = $themePart === StudioAudience::INTRO ? max(0.0, (float) config('tv.commentary.idents.talk_theme.overlap_seconds', 1.5)) : 0.0;

        if ($clap !== null) {
            $gap = max($gap, 0.2 + Pcm::seconds($clap['pcm'], $rate) + 0.25);
        }

        if ($music !== null) {
            $overlap = min($overlap, Pcm::seconds($music['pcm'], $rate) / 2);
            $gap = max($gap, 0.15 + Pcm::seconds($music['pcm'], $rate) - $overlap + ($overlap > 0 ? 0.0 : 0.2));
        }

        $pcm .= Pcm::silence($gap, $rate);

        if ($clap !== null) {
            $overlays[] = [$clap['pcm'], (int) round(($start + 0.2) * $rate)];
            $studio[] = ['at' => round($start + 0.2, 3), 'part' => 'applause-'.$moment, 'file' => $clap['file'], 'seconds' => round(Pcm::seconds($clap['pcm'], $rate), 3)];
        }

        if ($music !== null) {
            $length = Pcm::seconds($music['pcm'], $rate);
            $at = max(0.0, $start + $gap + $overlap - $length);
            $sound = $music['pcm'];

            if ($overlap > 0) {
                // Under the next voice only the fading tail, 12 dB down: about the bed's level.
                $head = (int) round(($length - $overlap) * $rate) * Pcm::BYTES_PER_SAMPLE;
                $tail = substr($sound, $head);
                $sound = substr($sound, 0, $head).Pcm::fadeOut(Pcm::gained($tail, -12.0), intdiv(strlen($tail), Pcm::BYTES_PER_SAMPLE));
            }

            // Laid on after the next chunk is in place: an overlay reaching past the pause would push the next voice back.
            $overlays[] = [$sound, (int) round($at * $rate)];
            $studio[] = ['at' => round($at, 3), 'part' => 'theme-'.$themePart, 'file' => $music['file'], 'seconds' => round($length, 3)];
        }

        return $pcm;
    }

    /**
     * Behind a script's last line: the closing applause (0.2 s after it) and the talk show's outro (0.3 s after it),
     * the speech as long as both.
     *
     * @param  list<array{at: float, part: string, file: string, seconds: float}>  $studio
     */
    private function studioTail(string $pcm, int $rate, ?string $moment, bool $outro, array &$studio): string
    {
        $end = Pcm::seconds($pcm, $rate);
        $clap = $moment === null ? null : $this->applausePcm($moment, $rate, $studio);
        $music = $outro ? $this->themePcm(StudioAudience::OUTRO, $rate) : null;

        if ($clap !== null) {
            $pcm = Pcm::overlay($pcm, $clap['pcm'], (int) round(($end + 0.2) * $rate));
            $studio[] = ['at' => round($end + 0.2, 3), 'part' => 'applause-'.$moment, 'file' => $clap['file'], 'seconds' => round(Pcm::seconds($clap['pcm'], $rate), 3)];
        }

        if ($music !== null) {
            $pcm = Pcm::overlay($pcm, $music['pcm'], (int) round(($end + 0.3) * $rate));
            $studio[] = ['at' => round($end + 0.3, 3), 'part' => 'theme-'.StudioAudience::OUTRO, 'file' => $music['file'], 'seconds' => round(Pcm::seconds($music['pcm'], $rate), 3)];
        }

        return $pcm;
    }

    /**
     * The studio of the block, per show (see the class comment): the scripts in runs (the consecutive parts of one story
     * are one run), and for each run its applause (after a chunk), theme (before a chunk, the outro behind one) and
     * the cut-ins it has left. A run holds its story's first part (`opens`) or last (`closes`) or neither; what the
     * story's earlier blocks placed is read from the cache, keyed by the story and the part the run starts with (a
     * block rendered again overwrites its own entry), and this run's share is written back.
     *
     * @param  list<array<array-key, mixed>>  $scripts
     * @param  list<array{script: int, speaker: string, lines: list<string>}>  $chunks
     * @param  array<int, string>  $voiced
     * @return array{runs: array<int, string>, applause: array<int, string>, before: array<int, string>, outro: array<int, true>, interruptions: array<string, int>}
     */
    private function studioPlan(array $scripts, array $chunks, array $voiced, int $rate): array
    {
        $plan = ['runs' => [], 'applause' => [], 'before' => [], 'outro' => [], 'interruptions' => []];
        $members = [];
        $previous = null;
        $previousGroup = null;

        foreach ($scripts as $index => $script) {
            $group = is_array($script['part'] ?? null) && is_string($script['part']['group'] ?? null) ? $script['part']['group'] : null;
            $run = $group !== null && $group === $previousGroup ? $previous : 'run:'.$index;
            $plan['runs'][$index] = $run;
            $members[$run][] = $index;
            [$previous, $previousGroup] = [$run, $group];
        }

        foreach ($members as $run => $indexes) {
            $first = $scripts[$indexes[0]];
            $last = $scripts[$indexes[count($indexes) - 1]];
            $topic = $first['topic'] ?? null;
            $keys = array_values(array_filter(array_keys($chunks), fn (int $key): bool => in_array($chunks[$key]['script'], $indexes, true)));
            $part = is_array($first['part'] ?? null) && is_string($first['part']['group'] ?? null) ? $first['part'] : null;
            $from = $part === null ? 1 : max(1, (int) ($part['index'] ?? 1));
            $opens = $from <= 1;
            $closes = ! is_array($last['part'] ?? null) || ($last['part']['continues'] ?? false) !== true;
            $memoryKey = $part === null || ($opens && $closes) ? null : 'caster:studio:episode:'.sha1((string) $part['group']);
            $memory = $memoryKey === null ? [] : (array) Cache::get($memoryKey, []);
            $earlier = array_filter($memory, fn (mixed $entry, int|string $at): bool => is_array($entry) && (int) $at < $from, ARRAY_FILTER_USE_BOTH);
            ksort($earlier);
            $latest = $earlier === [] ? [] : (array) end($earlier);
            $left = max(0, TalkShow::maxInterruptions() - (int) array_sum(array_map(fn (array $entry): int => (int) ($entry['interruptions'] ?? 0), $earlier)));
            $plan['interruptions'][$run] = $left;

            if ($keys === []) {
                continue;
            }

            $own = array_map(fn (int $key): array => ['speaker' => $chunks[$key]['speaker'], 'lines' => $chunks[$key]['lines']], $keys);
            $applause = StudioAudience::applause($topic, $own, array_map(fn (int $key): float => Pcm::seconds($voiced[$key], $rate), $keys), 0.1,
                $opens, $closes, (int) array_sum(array_map(fn (array $entry): int => (int) ($entry['applause'] ?? 0), $earlier)));
            $stung = array_values(array_unique(array_merge([], ...array_values(array_map(fn (array $entry): array => array_values(array_filter((array) ($entry['acts'] ?? []), 'is_string')), $earlier)))));
            $theme = StudioAudience::theme($topic, $own, $opens, $closes, $stung, ($latest['welcome_pending'] ?? false) === true);

            foreach ($applause as $position => $moment) {
                $plan['applause'][$keys[$position]] = $moment;
            }

            foreach ($theme['before'] as $position => $sound) {
                $plan['before'][$keys[$position]] = $sound;
            }

            if ($theme['outro'] !== null) {
                $plan['outro'][$keys[$theme['outro']]] = true;
            }

            if ($memoryKey !== null) {
                // The cut-ins of the run (inside a part and between two parts), as many as it may still have.
                $cutIns = 0;
                foreach ($keys as $position => $key) {
                    if ($position > 0 && $chunks[$keys[$position - 1]]['speaker'] !== $chunks[$key]['speaker'] && TalkShow::gapKind($chunks[$key]['speaker'], $chunks[$key]['lines']) === TalkShow::GAP_INTERRUPT) {
                        $cutIns++;
                    }
                }

                $memory[$from] = ['applause' => count($applause), 'acts' => $theme['acts'], 'welcome_pending' => $theme['welcome_pending'], 'interruptions' => min($left, $cutIns)];
                Cache::put($memoryKey, $memory, now()->addDays(2));
            }
        }

        return $plan;
    }

    /**
     * The pause between two parts of one show in a block: the turn's breath (TalkShow::gapFor() in the talk show,
     * `speaker_pause` elsewhere) where the speaker changes, the sentence pause where the same one goes on.
     *
     * @param  list<array{script: int, speaker: string, lines: list<string>}>  $chunks
     * @param  array<array-key, mixed>  $script
     * @param  array<string, mixed>  $voice  tv.commentary.voicestudio
     */
    private function partGap(int $index, array $chunks, array $script, array $voice, int &$interruptions): float
    {
        $before = array_values(array_filter($chunks, fn (array $chunk): bool => $chunk['script'] === $index - 1));
        $next = collect($chunks)->first(fn (array $chunk): bool => $chunk['script'] === $index);

        if ($before === [] || $next === null || $before[count($before) - 1]['speaker'] === $next['speaker']) {
            return (float) ($voice['pause']['sentence'] ?? 0.2);
        }

        $topic = $script['topic'] ?? null;
        $kind = TalkShow::gapKind($next['speaker'], $next['lines']);

        if ($kind === TalkShow::GAP_INTERRUPT && $interruptions-- <= 0) {
            $kind = TalkShow::GAP_TURN;
        }

        return $this->within(TalkShow::gapFor($topic, $kind) ?? (array) ($voice['speaker_pause'] ?? []), 0.45, 0.7);
    }

    /**
     * A variant of the moment's applause at `applause.gain_db`, never the one placed last; null when there is none.
     *
     * @param  list<array{at: float, part: string, file: string, seconds: float}>  $studio
     * @return array{file: string, pcm: string}|null
     */
    private function applausePcm(string $moment, int $rate, array $studio): ?array
    {
        $placed = array_values(array_filter($studio, fn (array $sound): bool => str_starts_with($sound['part'], 'applause-')));
        $file = StudioAudience::applauseFile($moment, $this->soundDir(), $placed === [] ? null : $placed[count($placed) - 1]['file'], ($this->random)());
        $pcm = $file === null ? null : $this->jingles->pcm($file, $rate, (float) config('tv.commentary.idents.applause.gain_db', -20) + self::speechOffset());

        return $file === null || $pcm === null ? null : ['file' => $file, 'pcm' => $pcm];
    }

    /**
     * A part of the talk theme (intro, outro: `talk_theme.gain_db`; the sting: `sting_gain_db`); null when it is missing.
     *
     * @return array{file: string, pcm: string}|null
     */
    private function themePcm(string $part, int $rate): ?array
    {
        $file = StudioAudience::themeFile($part, $this->soundDir());
        $gain = (float) config('tv.commentary.idents.talk_theme.'.($part === StudioAudience::STING ? 'sting_gain_db' : 'gain_db'), -6);
        $pcm = $file === null ? null : $this->jingles->pcm($file, $rate, $gain + self::speechOffset());

        return $file === null || $pcm === null ? null : ['file' => $file, 'pcm' => $pcm];
    }

    /**
     * How far the speech of a block sits under the -16 LUFS the station's sounds are mastered to, in dB: before the
     * block's loudness pass the voice is at `voicestudio.speech_dbfs` RMS, which measured 1.8 dB lower in LUFS (P20
     * probe: -20 dBFS RMS gave -21.8 LUFS). The studio's gains (`format_beds_gain_db`, `applause.gain_db`,
     * `talk_theme.*`) are meant against the voice, so they get this on top; without it the bed sat 18 dB under the
     * voice instead of 24.
     */
    private static function speechOffset(): float
    {
        return (float) config('tv.commentary.voicestudio.speech_dbfs', -20) + 16.0 - 1.8;
    }

    /** Where the station's own sounds lie (`idents.dir`, resources/audio/idents). */
    private function soundDir(): string
    {
        return (string) config('tv.commentary.idents.dir', resource_path('audio/idents'));
    }

    /**
     * The jingle of the first category that has one, as PCM; a bulletin without the library opens with its P4 stinger.
     *
     * @param  list<string>  $categories
     * @param  list<array<array-key, mixed>>  $scripts
     * @return array{id: string, pcm: string}|null
     */
    private function jingle(array $categories, array $scripts, CarbonImmutable $airAt, int $rate, float $gainDb): ?array
    {
        $item = $this->jingles->pick($categories, $airAt);
        $pcm = $item === null ? null : $this->jingles->pcm($item['file'], $rate, $gainDb);

        if ($item !== null && $pcm !== null) {
            return ['id' => $item['id'], 'pcm' => $pcm];
        }

        if (in_array('news', $categories, true) && ($scripts[0]['kind'] ?? null) === CasterScript::BULLETIN && $this->idents !== null) {
            $stinger = (new Idents((array) config('tv.commentary.idents', []), $rate))->stingerPcm((string) ($this->idents->slotFor(CasterScript::BULLETIN) ?? 'news'));

            return $stinger === null ? null : ['id' => 'stinger', 'pcm' => $stinger];
        }

        return null;
    }

    /**
     * Who blips under a chunk: the speaker who spoke last before this turn (the one it answers), else the next one
     * after it; in a duet always the other of the two. null when nobody else speaks in the script.
     *
     * @param  list<array{script: int, speaker: string, lines: list<string>}>  $chunks
     * @param  list<int>  $mine  the chunk keys of the script, in order
     */
    private static function blipSpeaker(array $chunks, array $mine, int $position, string $speaker): ?string
    {
        for ($i = $position - 1; $i >= 0; $i--) {
            if ($chunks[$mine[$i]]['speaker'] !== $speaker) {
                return $chunks[$mine[$i]]['speaker'];
            }
        }

        for ($i = $position + 1; $i < count($mine); $i++) {
            if ($chunks[$mine[$i]]['speaker'] !== $speaker) {
                return $chunks[$mine[$i]]['speaker'];
            }
        }

        return null;
    }

    /** Sirius' lines first, then Markus', then any other voice (the batch order). */
    private static function batchRank(string $speaker): int
    {
        return match ($speaker) {
            DuetDirector::SIRIUS => 0,
            DuetDirector::MARKUS => 1,
            default => 2,
        };
    }

    /**
     * @param  array<array-key, mixed>  $range
     */
    private function between(array $range): float
    {
        return $this->within($range, 0.5, 0.9);
    }

    /**
     * @param  array<array-key, mixed>  $range
     */
    private function within(array $range, float $min, float $max): float
    {
        $low = (float) ($range['min'] ?? $min);
        $high = max($low, (float) ($range['max'] ?? $max));

        return $low + ($high - $low) * ($this->random)();
    }
}
