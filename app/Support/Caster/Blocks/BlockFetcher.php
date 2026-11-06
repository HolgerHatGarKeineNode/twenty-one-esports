<?php

namespace App\Support\Caster\Blocks;

use App\Models\CasterScript;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\Idents;
use App\Support\Caster\Pcm;
use App\Support\Caster\QuietHours;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

/**
 * The workstation's block loop (plan "TWENTY ONE Radio", P6): from the
 * first claimed script on, claims consecutive scripts until the block holds
 * about `blocks.seconds.min` of airtime (estimated), never past
 * `seconds.max` unless a story told in parts still goes on, renders them
 * into one block (BlockRenderer), encodes it in one loudness pass and
 * uploads it to `api/caster/blocks`.
 *
 * What does not join the block:
 *  - a live moment or chat reaction claimed meanwhile: voiced alone and
 *    uploaded at once, it plays next;
 *  - a script the broadcast clock places (`airs_at`): it leads a block of
 *    its own after this one (the block then airs at its slot); a block it
 *    leads takes only the rest of its own story, and after a bulletin the
 *    scripts of `blocks.news_talk_topics` (Sport und Wetter, the news talk),
 *    one claim per topic;
 *  - a later part whose part before it went out in an earlier block: it
 *    leads the next block, so the server plays it right after that one.
 *
 * Below `seconds.min` with nothing left to claim, the scripts go back to the
 * queue (IDLE) while the server still has `starve_seconds` of audio; below
 * that a short block goes out, the stream must not run dry. Any failure
 * releases every script of the block.
 */
final class BlockFetcher
{
    /** Blocks rendered in one run at most (a deferred script starts the next one). */
    private const MAX_BLOCKS_PER_RUN = 4;

    /** @var list<array<string, mixed>> what the last run rendered (tests, the demo) */
    public array $rendered = [];

    /** When the server was last asked for urgent scripts during a render (answerUrgent()). */
    private float $urgentAskedAt = 0.0;

    /**
     * @param  array<array-key, mixed>  $first  the claimed script (the claim's `script`)
     * @param  array<string, string>  $terms
     */
    public function run(CasterFetcher $fetcher, array $first, array $terms, float $spoolSeconds, int $depth = 0): string
    {
        $settings = (array) config('tv.commentary.blocks', []);
        $min = (float) ($settings['seconds']['min'] ?? 180);
        $max = max($min, (float) ($settings['seconds']['max'] ?? 360));
        $maxScripts = max(1, (int) ($settings['max_scripts'] ?? 40));
        $newsTalk = array_values(array_filter((array) ($settings['news_talk_topics'] ?? []), 'is_string'));
        // Music first (user 2026-10-13: "nicht ständig welche hintereinander"): one programme item per block, with the rest
        // of its own story and a bulletin's news talk; the stream puts a complete music track between two blocks.
        $single = config('tv.commentary.music_first.enabled') === true && config('tv.commentary.music_first.one_item_per_block') === true;

        $batch = [$first];
        $deferred = null;
        $exhausted = false;
        $anchored = is_string($first['airs_at'] ?? null);
        $newsTalkTries = 0;

        while (count($batch) < $maxScripts) {
            $last = $batch[count($batch) - 1];
            $continues = is_array($last['part'] ?? null) && ($last['part']['continues'] ?? false) === true;
            $estimate = self::estimate($batch);
            $wantsNewsTalk = $anchored && ($first['kind'] ?? null) === CasterScript::BULLETIN && $newsTalkTries < count($newsTalk);

            if (! $continues && ! $wantsNewsTalk && ($anchored || $single || $estimate >= $min)) {
                break;
            }

            if ($estimate >= $max * 1.5) {
                break;
            }

            $newsTalkTries += $wantsNewsTalk && ! $continues ? 1 : 0;
            // A stop was asked for (P18): what is claimed is released below, nothing more is claimed.
            $claim = $fetcher->stopping() ? null : $fetcher->claim();
            $script = $claim?->json('script');

            if (! is_array($script) || ! is_int($script['id'] ?? null)) {
                $exhausted = true;

                break;
            }

            if (in_array($script['kind'] ?? null, ClipSpool::LIVE_KINDS, true)) {
                $fetcher->voiceAndUpload($script, $terms);

                continue;
            }

            if ($this->fits($batch, $script, $anchored, $newsTalk)) {
                $batch[] = $script;

                continue;
            }

            $deferred = $script;

            break;
        }

        $ids = array_map(fn (array $script): int => (int) $script['id'], $batch);

        if ($fetcher->stopping()) {
            $this->releaseAll($fetcher, [...$ids, ...($deferred === null ? [] : [(int) $deferred['id']])]);

            return CasterFetcher::STOPPED;
        }

        if (! $anchored && ! $single && $deferred === null && $exhausted && self::estimate($batch) < $min && $spoolSeconds >= (float) ($settings['starve_seconds'] ?? 150)) {
            $this->releaseAll($fetcher, $ids);

            return CasterFetcher::IDLE;
        }

        $outcome = $this->renderAndUpload($fetcher, $batch, $terms, CarbonImmutable::now()->addSeconds((int) $spoolSeconds));

        if ($deferred !== null) {
            if ($depth + 1 >= self::MAX_BLOCKS_PER_RUN || $outcome !== CasterFetcher::VOICED || $fetcher->stopping()) {
                $this->releaseAll($fetcher, [(int) $deferred['id']]);

                return $outcome;
            }

            return $this->run($fetcher, $deferred, $terms, $spoolSeconds + self::estimate($batch), $depth + 1);
        }

        return $outcome;
    }

    /**
     * Whether `$script` joins the block in the making.
     *
     * @param  list<array<array-key, mixed>>  $batch
     * @param  array<array-key, mixed>  $script
     * @param  list<string>  $newsTalk
     */
    private function fits(array $batch, array $script, bool $anchored, array $newsTalk): bool
    {
        $part = is_array($script['part'] ?? null) ? $script['part'] : null;
        $ownStory = $part !== null && (int) ($part['index'] ?? 1) > 1
            && collect($batch)->contains(fn (array $earlier): bool => is_array($earlier['part'] ?? null)
                && $earlier['part']['group'] === $part['group'] && (int) $earlier['part']['index'] === (int) $part['index'] - 1);

        if ($ownStory) {
            return true;
        }

        // A later part whose part before it is not in this block leads the next one.
        if ($part !== null && (int) ($part['index'] ?? 1) > 1) {
            return false;
        }

        // Sport und Wetter and the news talk after a bulletin join its block (anchored themselves, they would air right
        // after it anyway).
        if ($anchored) {
            return ($batch[0]['kind'] ?? null) === CasterScript::BULLETIN && in_array($script['topic'] ?? null, $newsTalk, true);
        }

        return ! is_string($script['airs_at'] ?? null);
    }

    /**
     * @param  list<array<array-key, mixed>>  $batch
     * @param  array<string, string>  $terms
     */
    private function renderAndUpload(CasterFetcher $fetcher, array $batch, array $terms, CarbonImmutable $airAt, bool $answerUrgent = true): string
    {
        $ids = array_map(fn (array $script): int => (int) $script['id'], $batch);
        $renderer = new BlockRenderer($fetcher, JingleLibrary::fromConfig(), Idents::fromConfig(), null,
            $answerUrgent ? fn () => $this->answerUrgent($fetcher, $terms) : null);
        $block = $renderer->render($batch, $terms, $airAt);
        $opus = $block === null ? null : $fetcher->encode($block['pcm'], $block['rate']);

        if ($block === null || $opus === null) {
            $fetcher->note('block '.implode(',', $ids).': '.($block === null ? 'rendering failed' : 'encoding failed').', released');
            $this->releaseAll($fetcher, $ids);

            return CasterFetcher::RELEASED;
        }

        $times = fn (array $values): string => implode(',', array_map(fn (float $value): string => sprintf('%.3F', $value), $values));
        $this->rendered[] = ['scripts' => $ids, 'seconds' => round(Pcm::seconds($block['pcm'], $block['rate']), 3)] + array_diff_key($block, ['pcm' => true]);

        // The local archive keeps only the timeless (user 2026-10-16: "News, Sport und Wetter will ich auch nicht
        // haben"): a block whose batch carries a bulletin, the news or a rubric is perishable whatever its topics
        // say - the stock and evergreen programmes stay. The topics are the batch's: the rendered block carries none.
        $kinds = array_values(array_filter(array_map(fn (array $script): ?string => is_string($script['kind'] ?? null) ? $script['kind'] : null, $batch)));
        $topics = array_values(array_filter(array_map(fn (array $script): ?string => is_string($script['topic'] ?? null) ? $script['topic'] : null, $batch)));
        $timeless = CasterFetcher::timelessTopics($topics)
            && ! in_array(CasterScript::BULLETIN, $kinds, true)
            && ! in_array(CasterScript::NEWS, $kinds, true)
            && ! in_array(CasterScript::RUBRIC, $kinds, true);

        try {
            $upload = $fetcher->server()
                ->withHeaders([
                    'X-Caster-Scripts' => implode(',', $ids),
                    'X-Caster-Duration' => sprintf('%.3F', Pcm::seconds($block['pcm'], $block['rate'])),
                    'X-Caster-Sentence-Ends' => $times($block['ends']),
                    'X-Caster-Boundaries' => $times($block['boundaries']),
                    'X-Caster-Protected' => sprintf('%.3F', $block['protected']),
                ])
                ->withBody(CasterFetcher::keepLocal($opus, 'block-'.CasterFetcher::programmeLabel($topics), $timeless), 'audio/ogg')
                ->post($fetcher->settings()['server'].'/api/caster/blocks');
        } catch (ConnectionException) {
            $fetcher->note('block '.implode(',', $ids).': upload failed, server unreachable, released');
            $this->releaseAll($fetcher, $ids);

            return CasterFetcher::OFFLINE;
        }

        $fetcher->letGo($ids);
        $fetcher->note(CasterFetcher::uploadLine('block '.implode(',', $ids), $upload, Pcm::seconds($block['pcm'], $block['rate'])));

        if ($upload->status() === 201) {
            // The day's archive for the quiet hours' best of (P6 replay); whether it may ever come again is decided there.
            if (ReplayArchive::enabled()) {
                ReplayArchive::fromConfig()->store($opus, $batch, ['ends' => $block['ends'], 'boundaries' => $block['boundaries'], 'steps' => $block['steps'],
                    'seconds' => round(Pcm::seconds($block['pcm'], $block['rate']), 3)], CarbonImmutable::now());
            }

            return CasterFetcher::VOICED;
        }

        $this->releaseAll($fetcher, $ids);

        return $upload->status() === 507 ? CasterFetcher::FULL : CasterFetcher::RELEASED;
    }

    /**
     * The quiet hours' best of the day (P6 replay): while the server has
     * less than `replay.below_seconds` ready and nothing new to voice, an
     * evergreen block from the archive (ReplayArchive::pick()) goes up again
     * behind the Anchor's replay jingle, decoded and encoded by ffmpeg only,
     * no VoiceStudio call. Fails closed: replay off, day time, no evergreen
     * block, no replay jingle (the listener must hear that it is a repeat)
     * or a refusal of the server, and nothing is uploaded (null). By day only
     * as the reserve of the hourly airtime budget (`replay.day_reserve`,
     * DayPlanner): the server refuses it beyond its replay share.
     */
    public function replay(CasterFetcher $fetcher, float $spoolSeconds): ?string
    {
        $settings = (array) config('tv.commentary.blocks.replay', []);
        $now = CarbonImmutable::now();

        if (! ReplayArchive::enabled() || (! QuietHours::active($now) && ($settings['day_reserve'] ?? false) !== true)
            || $spoolSeconds >= (float) ($settings['below_seconds'] ?? 120)) {
            return null;
        }

        $archive = ReplayArchive::fromConfig();
        $pick = $archive->pick($now, (float) ($settings['repeat_hours'] ?? 24), (float) ($settings['min_age_hours'] ?? 6));
        $library = JingleLibrary::fromConfig();
        $jingle = $pick === null ? null : $library->pick(['replay'], $now);

        if ($pick === null || $jingle === null) {
            return null;
        }

        $rate = (int) config('tv.commentary.mix.sample_rate', 24000);
        $intro = $library->pcm($jingle['file'], $rate, (float) config('tv.commentary.blocks.jingles.gain_db', -3.0));
        $body = $library->pcm($pick['file'], $rate);

        if ($intro === null || $body === null) {
            return null;
        }

        $start = max(0, intdiv(strlen($intro), Pcm::BYTES_PER_SAMPLE) - (int) round((float) config('tv.commentary.blocks.crossfade_seconds', 0.45) * $rate));
        $pcm = Pcm::overlay($intro, $body, $start);
        $offset = $start / $rate;
        $shift = fn (mixed $times): array => array_map(fn ($time): float => round($offset + (float) $time, 3), array_values(array_filter((array) $times, 'is_numeric')));
        $opus = $fetcher->encode($pcm, $rate);

        if ($opus === null) {
            return null;
        }

        $times = fn (array $values): string => implode(',', array_map(fn (float $value): string => sprintf('%.3F', $value), $values));

        try {
            $upload = $fetcher->server()
                ->withHeaders([
                    'X-Caster-Duration' => sprintf('%.3F', Pcm::seconds($pcm, $rate)),
                    'X-Caster-Sentence-Ends' => $times($shift($pick['meta']['ends'] ?? [])),
                    'X-Caster-Boundaries' => $times($shift($pick['meta']['boundaries'] ?? [])),
                ])
                ->withBody(CasterFetcher::keepLocal($opus, 'item'), 'audio/ogg')
                ->post($fetcher->settings()['server'].'/api/caster/replays');
        } catch (ConnectionException) {
            return CasterFetcher::OFFLINE;
        }

        if ($upload->status() !== 201) {
            return null;
        }

        $archive->markReplayed($pick['name'], $now);
        $this->rendered[] = ['replay' => $pick['name'], 'jingle' => $jingle['id']];

        return CasterFetcher::VOICED;
    }

    /**
     * While a block is being voiced (minutes at VoiceStudio's pace), every
     * `urgent_poll_seconds` the server is asked for what cannot wait for it:
     * a live moment, a chat reaction or a wish is voiced and uploaded alone
     * at once, a bulletin, rubric or teaser rendered as its own block (it airs at its
     * slot). Without this a reaction would wait for the whole block.
     *
     * @param  array<string, string>  $terms
     */
    private function answerUrgent(CasterFetcher $fetcher, array $terms): void
    {
        $every = (float) config('tv.commentary.blocks.urgent_poll_seconds', 15);

        if ($fetcher->stopping() || microtime(true) - $this->urgentAskedAt < $every) {
            return;
        }

        $this->urgentAskedAt = microtime(true);

        for ($answered = 0; $answered < 5; $answered++) {
            $claim = $fetcher->claim(urgent: true);
            $script = $claim?->json('script');

            if (! is_array($script) || ! is_int($script['id'] ?? null)) {
                // Nothing urgent: while this render runs, the air must not run dry (DayPlanner: stock first, a replay last).
                if ($claim !== null) {
                    $this->keepAirFed($fetcher, (float) $claim->json('spool_seconds', 0));
                }

                return;
            }

            // What the clock places (a bulletin, a rubric, a teaser) gets a block of its own, with its jingle.
            if (is_string($script['airs_at'] ?? null) || ($script['kind'] ?? null) === CasterScript::BULLETIN) {
                $this->renderAndUpload($fetcher, [$script], $terms, CarbonImmutable::now(), answerUrgent: false);
            } else {
                $fetcher->voiceAndUpload($script, $terms);
            }
        }
    }

    /**
     * During a render (answerUrgent()): a stock block goes up when the server
     * runs below `stock.low_seconds`, else a replay when it would run dry
     * (DayPlanner); nothing in the quiet hours but the replay of the night.
     */
    private function keepAirFed(CasterFetcher $fetcher, float $spoolSeconds): void
    {
        $quiet = QuietHours::active(CarbonImmutable::now());
        $hasStock = DayStock::enabled() && DayStock::fromConfig()->entries() !== [];

        // The server feeds its own spool from its library (P18): a finished block goes up there instead.
        if (DayPlanner::libraryInUse()) {
            if ($hasStock) {
                $this->uploadLibrary($fetcher);
            }

            return;
        }

        if (DayPlanner::topUpBeforeFresh($spoolSeconds, $hasStock, $quiet) && $this->uploadStock($fetcher) === CasterFetcher::VOICED) {
            return;
        }

        if (! $hasStock) {
            $this->replay($fetcher, $spoolSeconds);
        }
    }

    /**
     * The oldest block of the day stock goes up (`api/caster/stock/blocks`,
     * no VoiceStudio call); once the server took it, it leaves the stock and
     * moves to the replay archive (it aired as new once). Fails closed: no
     * stock, quiet hours, a refusal or no connection, and the block stays.
     */
    public function uploadStock(CasterFetcher $fetcher): ?string
    {
        $stock = DayStock::fromConfig();
        $entry = DayStock::enabled() && ! QuietHours::active(CarbonImmutable::now()) ? $stock->take() : null;

        if ($entry === null) {
            return null;
        }

        $meta = $entry['meta'];
        $times = fn (mixed $values): string => implode(',', array_map(fn ($value): string => sprintf('%.3F', (float) $value), array_values(array_filter((array) $values, 'is_numeric'))));
        $label = 'stock-'.CasterFetcher::programmeLabel((array) ($meta['topics'] ?? []));

        try {
            $upload = $fetcher->server()
                ->withHeaders([
                    'X-Caster-Duration' => sprintf('%.3F', (float) $meta['seconds']),
                    'X-Caster-Sentence-Ends' => $times($meta['ends'] ?? []),
                    'X-Caster-Boundaries' => $times($meta['boundaries'] ?? []),
                ])
                ->withBody(CasterFetcher::keepLocal($entry['opus'], $label), 'audio/ogg')
                ->post($fetcher->settings()['server'].'/api/caster/stock/blocks');
        } catch (ConnectionException) {
            return CasterFetcher::OFFLINE;
        }

        if ($upload->status() !== 201) {
            return $upload->status() === 507 ? CasterFetcher::FULL : null;
        }

        $stock->remove($entry['name']);
        $scripts = array_values(array_filter((array) ($meta['scripts'] ?? []), 'is_array'));

        if (ReplayArchive::enabled() && $scripts !== []) {
            ReplayArchive::fromConfig()->store($entry['opus'], $scripts, ['ends' => array_values(array_map('floatval', (array) ($meta['ends'] ?? []))),
                'boundaries' => array_values(array_map('floatval', (array) ($meta['boundaries'] ?? []))), 'steps' => array_values(array_filter((array) ($meta['steps'] ?? []), 'is_string')),
                'seconds' => (float) $meta['seconds']], CarbonImmutable::now());
        }

        $this->rendered[] = ['stock_upload' => $entry['name'], 'seconds' => (float) $meta['seconds']];

        return CasterFetcher::VOICED;
    }

    /**
     * Renders one block for the day stock: ONE evergreen talk (`?only=stock`)
     * with all its parts and nothing else (P21: filling up to
     * `stock.seconds.min` glued a league explainer and an unrelated history
     * talk into one block, user 2026-10-13: "Das war total sinnlos!"; a
     * claim of another talk goes back to the queue and opens the next
     * block), renders it without a time-of-day jingle, keeps the block in the
     * DayStock and tells the server the scripts are done. Urgent scripts
     * are answered during the render like in any block (answerUrgent()).
     * null when the server had no stock script; any failure releases them.
     */
    public function stock(CasterFetcher $fetcher): ?string
    {
        [$batch, $terms, $orphans] = [[], [], 0];

        while (count($batch) < (int) config('tv.commentary.blocks.max_scripts', 40)) {
            $last = $batch === [] ? null : $batch[count($batch) - 1];

            if ($last !== null && ! self::continues($last)) {
                break;
            }

            $claim = $fetcher->stopping() ? null : $fetcher->claim(stock: true);
            $script = $claim?->json('script');

            if (! is_array($script) || ! is_int($script['id'] ?? null)) {
                break;
            }

            // A later part whose beginning is gone (voiced in an older block, or lost) would open a block mid-talk: it
            // is told done without audio and never airs, and the next claim starts the block.
            if ($last === null && is_int($script['part']['index'] ?? null) && $script['part']['index'] > 1 && $orphans++ < 10) {
                $this->skipOrphan($fetcher, $script['id']);

                continue;
            }

            // Only the next part of the same talk joins the block; anything else waits for a block of its own.
            if ($last !== null && (($script['part']['group'] ?? null) !== $last['part']['group'] || ($script['part']['index'] ?? null) !== $last['part']['index'] + 1)) {
                $fetcher->release($script['id']);

                break;
            }

            $terms = $terms === [] ? array_filter((array) $claim->json('pronunciation', []), 'is_string') : $terms;
            $batch[] = $script;
        }

        if ($batch === []) {
            return null;
        }

        $ids = array_map(fn (array $script): int => (int) $script['id'], $batch);
        $renderer = new BlockRenderer($fetcher, JingleLibrary::fromConfig(), Idents::fromConfig(), null, fn () => $this->answerUrgent($fetcher, $terms));
        $block = $renderer->render($batch, $terms, CarbonImmutable::now(), evergreen: true);
        $opus = $block === null ? null : $fetcher->encode($block['pcm'], $block['rate']);
        $seconds = $block === null ? 0.0 : round(Pcm::seconds($block['pcm'], $block['rate']), 3);
        $name = $block === null || $opus === null ? null : DayStock::fromConfig()->store($opus, $batch,
            ['ends' => $block['ends'], 'boundaries' => $block['boundaries'], 'steps' => $block['steps'], 'seconds' => $seconds], CarbonImmutable::now());

        if ($name === null) {
            $fetcher->note('stock '.implode(',', $ids).': '.($block === null ? 'rendering failed' : ($opus === null ? 'encoding failed' : 'not kept')).', released');
            $this->releaseAll($fetcher, $ids);

            return $fetcher->stopping() ? CasterFetcher::STOPPED : CasterFetcher::RELEASED;
        }

        $fetcher->letGo($ids);
        $fetcher->note(sprintf('stock %s: %.1f s kept as %s', implode(',', $ids), $seconds, $name));

        try {
            $fetcher->server()->withHeaders(['X-Caster-Scripts' => implode(',', $ids)])->post($fetcher->settings()['server'].'/api/caster/stock/stored');
        } catch (ConnectionException) {
            // The claims run out after `claim_seconds`; the block is stocked all the same.
        }

        $this->rendered[] = ['stock' => $name, 'scripts' => $ids, 'seconds' => $seconds, 'steps' => $block['steps']];

        // Continuously into the server's library (P18), not only when its spool runs low.
        if (DayPlanner::libraryInUse()) {
            $this->uploadLibrary($fetcher);
        }

        return CasterFetcher::STOCKED;
    }

    /**
     * The oldest block of the workstation's stock goes up into the server's
     * stock library (P18, `POST api/caster/stock/library/{sha1}`, no
     * VoiceStudio call), with its format, topics and subjects; once the server
     * has it (201 stored, 200 already there) it leaves the local stock. It
     * does not go into the replay archive: the server's library decides about
     * its repeats. 404 or 409 (an older server, the library off) marks the
     * library missing for an hour (DayPlanner::libraryInUse(): the day-stock
     * path as before); any other refusal or no connection keeps the block.
     */
    public function uploadLibrary(CasterFetcher $fetcher): ?string
    {
        $stock = DayStock::fromConfig();
        $entry = DayStock::enabled() ? $stock->take() : null;

        if ($entry === null) {
            return null;
        }

        $meta = $entry['meta'];
        $scripts = array_values(array_filter((array) ($meta['scripts'] ?? []), 'is_array'));
        $topics = array_values(array_unique(array_filter(array_map(fn (array $script): ?string => is_string($script['topic'] ?? null) ? $script['topic'] : null, $scripts))));
        $subjects = array_values(array_unique(array_filter(array_map(fn (array $script): ?string => is_string($script['subject'] ?? null) ? $script['subject'] : null, $scripts))));
        $format = collect($topics)->first(fn (string $topic): bool => str_starts_with($topic, StockScripts::TOPIC_PREFIX));
        $times = fn (mixed $values): string => implode(',', array_map(fn ($value): string => sprintf('%.3F', (float) $value), array_values(array_filter((array) $values, 'is_numeric'))));
        $hash = sha1($entry['opus']);

        try {
            $upload = $fetcher->server()->timeout(60)
                ->withHeaders([
                    'X-Caster-Duration' => sprintf('%.3F', (float) $meta['seconds']),
                    'X-Caster-Sentence-Ends' => $times($meta['ends'] ?? []),
                    'X-Caster-Boundaries' => $times($meta['boundaries'] ?? []),
                    'X-Caster-Stock-Meta' => base64_encode((string) json_encode([
                        'format' => $format === null ? 'talk' : explode(':', substr($format, strlen(StockScripts::TOPIC_PREFIX)))[0],
                        'topics' => $topics,
                        'subjects' => $subjects,
                        'rendered_at' => is_int($meta['rendered_at'] ?? null) ? $meta['rendered_at'] : null,
                        'evergreen' => ReplayArchive::evergreen($scripts, array_values(array_filter((array) ($meta['steps'] ?? []), 'is_string'))),
                        'episode' => StockLibrary::episodeOf($scripts),
                    ], JSON_UNESCAPED_UNICODE)),
                ])
                ->withBody(CasterFetcher::keepLocal($entry['opus'], 'library-'.($format === null ? 'talk' : explode(':', substr($format, strlen(StockScripts::TOPIC_PREFIX)))[0])), 'audio/ogg')
                ->post($fetcher->settings()['server'].'/api/caster/stock/library/'.$hash);
        } catch (ConnectionException) {
            return CasterFetcher::OFFLINE;
        }

        if (in_array($upload->status(), [404, 405, 409], true)) {
            Cache::put(DayPlanner::LIBRARY_MISSING, true, 3600);

            return null;
        }

        if (! in_array($upload->status(), [200, 201], true)) {
            return $upload->status() === 507 ? CasterFetcher::FULL : null;
        }

        $stock->remove($entry['name']);
        $this->rendered[] = ['library_upload' => $entry['name'], 'hash' => $hash, 'seconds' => (float) $meta['seconds'], 'status' => $upload->status()];

        return CasterFetcher::VOICED;
    }

    /** An orphaned later part of a stock talk is told done without audio (`api/caster/stock/stored`): it never airs. */
    private function skipOrphan(CasterFetcher $fetcher, int $id): void
    {
        $fetcher->letGo([$id]);

        try {
            $fetcher->server()->withHeaders(['X-Caster-Scripts' => (string) $id])->post($fetcher->settings()['server'].'/api/caster/stock/stored');
        } catch (ConnectionException) {
            // Its claim runs out; it comes back and is skipped again.
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function releaseAll(CasterFetcher $fetcher, array $ids): void
    {
        foreach ($ids as $id) {
            $fetcher->release($id);
        }
    }

    /**
     * Whether a claimed script is a part of a talk with more parts to come.
     *
     * @param  array<array-key, mixed>  $script
     */
    private static function continues(array $script): bool
    {
        return is_array($script['part'] ?? null) && is_string($script['part']['group'] ?? null) && is_int($script['part']['index'] ?? null)
            && ($script['part']['continues'] ?? false) === true;
    }

    /**
     * @param  list<array<array-key, mixed>>  $batch
     */
    private static function estimate(array $batch): float
    {
        return (float) array_sum(array_map(fn (array $script): float => (float) ($script['estimated_seconds'] ?? 0), $batch));
    }
}
