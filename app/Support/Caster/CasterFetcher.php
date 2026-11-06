<?php

namespace App\Support\Caster;

use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\Blocks\BlockFetcher;
use App\Support\Caster\Blocks\DayPlanner;
use App\Support\Caster\Blocks\DayStock;
use App\Support\Caster\Programme\TalkShow;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Throwable;

/**
 * The workstation side of TWENTY ONE TV (`php artisan caster:fetch` on Markus
 * Turm's machine): claims the next script from the server, voices it in
 * chunks of two or three sentences (chunks()) with the local VoiceStudio
 * (`voicestudio.profiles`, a tempo per speaker), trims each chunk to its
 * speech with at least `pad_ms` of quiet left at both ends (Pcm::trimEdges()),
 * puts real pauses between the chunks (≈0.2 s, ≈0.35 s after ! ? : or a
 * marker, 0.45 to 0.7 s on a speaker change, slightly random),
 * normalises to -16 LUFS and encodes Opus with ffmpeg (stdin to stdout), and
 * uploads the clip.
 *
 * No audio ever lands on the workstation's disk (user 2026-10-11: "Mein PC
 * soll natürlich nicht zugemüllt werden mit lauter Audiodaten"): VoiceStudio's
 * answer, the trimmed PCM and the Opus clip stay in memory, and every
 * generation's VoiceStudio history entry (VoiceStudio keeps each /generate,
 * GET /history) is deleted by the id VoiceStudio named for it (`x-audio-id`),
 * never any other.
 *
 * VoiceStudio hiccups (it crashed once on 2026-10-11) are retried
 * `retries` times with a growing wait; after that the script goes back to the
 * server's queue (release) and the fetcher waits.
 */
class CasterFetcher
{
    public const VOICED = 'voiced';

    public const IDLE = 'idle';

    public const FULL = 'full';

    public const RELEASED = 'released';

    public const OFFLINE = 'offline';

    /** A block went into the workstation's day stock (DayStock). */
    public const STOCKED = 'stocked';

    /** VoiceStudio does not answer (not started yet after a boot, crashed): nothing claimed, the server told (P18). */
    public const VOICE_DOWN = 'voicestudio_down';

    /** A stop was asked for (SIGTERM, SIGINT): nothing claimed any more. */
    public const STOPPED = 'stopped';

    /** Whether a stop was asked for (stop()): no claim, no VoiceStudio call any more; what is half voiced is released. */
    private bool $stopping = false;

    /** Whether VoiceStudio could not be reached at all on the last call (every try a connection error). */
    private bool $voiceDown = false;

    /** @var Closure(float): void */
    private Closure $sleep;

    /** @var array<int, true> the scripts claimed and not yet uploaded or released: their claims are renewed (keepClaims()) */
    private array $held = [];

    /** When the held claims were last renewed (clock()). */
    private float $renewedAt = 0.0;

    /**
     * Where a line about an upload or a VoiceStudio failure goes (`caster:fetch` prints it to the journal; prod
     * 2026-10-13: runs of four to twenty minutes ended "released" and nothing said why).
     *
     * @var (Closure(string): void)|null
     */
    public ?Closure $log = null;

    /**
     * @param  (Closure(float): void)|null  $sleep
     */
    public function __construct(?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * Asks the fetcher to stop (P18; user 2026-10-13: "den Laptop ohne Probleme
     * runterfahren … das Runterfahren macht nichts kaputt"): from now on no
     * claim and no VoiceStudio call; the chunk in VoiceStudio's hands ends on
     * its own (one call, seconds), the block in the making is abandoned and
     * every script it held is released to the server, the local stock stays as
     * it was (written atomically). The command exits after the current run.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    /** @phpstan-impure the signal handler sets it at any moment */
    public function stopping(): bool
    {
        return $this->stopping;
    }

    /**
     * Whether VoiceStudio can be used: GET /profiles answers within three seconds, asked on every run. It used to be
     * assumed up until a voicing call failed, so an idle fetcher started without VoiceStudio (or one that lost it while
     * idle) told the server "up" on every claim, and the stream showed the hosts ON AIR with VoiceStudio off (user
     * 2026-10-18: "Da steht on air für Markus und Sirius immer noch, obwohl mein VoiceStudio aus ist").
     */
    public function voiceStudioUp(): bool
    {
        try {
            $this->voiceDown = ! Http::connectTimeout(2)->timeout(3)->get(rtrim((string) $this->settings()['url'], '/').'/profiles')->successful();
        } catch (ConnectionException) {
            $this->voiceDown = true;
        }

        return ! $this->voiceDown;
    }

    /**
     * Tells the server the workstation is alive but its VoiceStudio is down (`POST api/caster/heartbeat`): the
     * autopilot takes over until a claim says it is up again. false when the server is unreachable.
     */
    public function heartbeat(bool $voiceUp): bool
    {
        try {
            return $this->server()->timeout(10)->withHeaders([AutoPilot::HEADER => $voiceUp ? 'up' : 'down'])
                ->post($this->settings()['server'].'/api/caster/heartbeat')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function runOnce(): string
    {
        if ($this->stopping) {
            return self::STOPPED;
        }

        // VoiceStudio not up yet (the fetcher starts at boot) or crashed: nothing is claimed, the server runs the autopilot,
        // and the stock already rendered still goes up to the server's library (no GPU needed).
        if (! $this->voiceStudioUp()) {
            if (! $this->heartbeat(false)) {
                return self::OFFLINE;
            }

            if (config('tv.commentary.blocks.enabled') === true && DayPlanner::libraryInUse()) {
                app(BlockFetcher::class)->uploadLibrary($this);
            }

            return self::VOICE_DOWN;
        }

        $claim = $this->claim();

        if ($claim === null) {
            return self::OFFLINE;
        }

        $script = $claim->json('script');
        $spool = (float) $claim->json('spool_seconds', 0);
        $full = $claim->json('full') === true;

        if (! is_array($script) || ! is_int($script['id'] ?? null)) {
            // A podcast replay on air (P12): the server says what the GPU does meanwhile, stock or idle.
            $podcast = $claim->json('podcast.gpu');

            return config('tv.commentary.blocks.enabled') === true
                ? $this->whenIdle($spool, $full, is_string($podcast) ? $podcast : null, $claim->json('stock_library.full') === true)
                : ($full ? self::FULL : self::IDLE);
        }

        /** @var array<string, string> $terms */
        $terms = array_filter((array) $claim->json('pronunciation', []), 'is_string');

        // The hourly airtime budget (DayPlanner): a fresh script keeps the GPU, but a server running low gets a stock block first.
        if (config('tv.commentary.blocks.enabled') === true && DayStock::enabled() && ! DayPlanner::libraryInUse()
            && DayPlanner::topUpBeforeFresh($spool, DayStock::fromConfig()->entries() !== [], QuietHours::active(CarbonImmutable::now()))) {
            app(BlockFetcher::class)->uploadStock($this);
        }

        // Programme blocks (plan "TWENTY ONE Radio", P6): everything but a live moment or a chat reaction is rendered
        // into a block of several scripts with jingles; those two are still voiced alone, to play next.
        if (config('tv.commentary.blocks.enabled') === true && ! in_array($script['kind'] ?? null, ClipSpool::LIVE_KINDS, true)) {
            return app(BlockFetcher::class)->run($this, $script, $terms, (float) $claim->json('spool_seconds', 0));
        }

        return $this->voiceAndUpload($script, $terms);
    }

    /**
     * Nothing to voice (DayPlanner::whenIdle()): a stock block goes up, else
     * a replay of the archive when the air would run dry, else the GPU
     * renders day stock, else the fetcher waits. A refused step falls
     * through to the next.
     */
    private function whenIdle(float $spool, bool $full, ?string $podcastGpu = null, bool $libraryFull = false): string
    {
        $now = CarbonImmutable::now();
        $stock = DayStock::enabled() ? DayStock::fromConfig() : null;
        $blocks = app(BlockFetcher::class);

        foreach (DayPlanner::whenIdle($spool, $stock !== null && $stock->entries() !== [], $stock === null || $stock->isFull(), QuietHours::active($now), DayStock::windowActive($now), $podcastGpu, $libraryFull) as $step) {
            $outcome = match ($step) {
                DayPlanner::UPLOAD_LIBRARY => $blocks->uploadLibrary($this),
                DayPlanner::UPLOAD_STOCK => $blocks->uploadStock($this),
                DayPlanner::REPLAY => $blocks->replay($this, $spool),
                DayPlanner::RENDER_STOCK => $blocks->stock($this),
                default => $full ? self::FULL : self::IDLE,
            };

            if ($outcome !== null) {
                return $outcome;
            }
        }

        return $full ? self::FULL : self::IDLE;
    }

    /**
     * One claim call: the server's answer, or null when it is unreachable or refuses. `$urgent`: only a live moment,
     * a reaction, a wish or a bulletin (asked while a block is being rendered); `$stock`: an evergreen talk for the
     * day stock (the server may write it first, so it gets a longer timeout). While the day stock runs, every claim
     * tells the server how much stock there is (DayStock::HEADER).
     */
    public function claim(bool $urgent = false, bool $stock = false): ?Response
    {
        if ($this->stopping) {
            return null;
        }

        // A claim means VoiceStudio is up (runOnce() checked): the server ends its autopilot (P18).
        $request = $this->server()->withHeaders([AutoPilot::HEADER => 'up']);

        if (DayStock::enabled()) {
            $request = $request->withHeaders([DayStock::HEADER => DayStock::fromConfig()->header()]);
        }

        try {
            $claim = ($stock ? $request->timeout(150) : $request)
                ->post($this->settings()['server'].'/api/caster/claim'.($urgent ? '?only=urgent' : ($stock ? '?only=stock' : '')));
        } catch (ConnectionException) {
            return null;
        }

        if (! $claim->successful()) {
            $this->note("claim refused: {$claim->status()}");

            return null;
        }

        $id = $claim->json('script.id');

        if (is_int($id)) {
            // The first renewal is due `renew_seconds` after the first claim held.
            $this->renewedAt = $this->held === [] ? self::clock() : $this->renewedAt;
            $this->held[$id] = true;
        }

        return $claim;
    }

    /** Seconds on the app clock (it travels in tests). */
    private static function clock(): float
    {
        return (float) CarbonImmutable::now()->format('U.u');
    }

    /** A line for the journal (`$log`), nothing without one. */
    public function note(string $line): void
    {
        if ($this->log !== null) {
            ($this->log)($line);
        }
    }

    /**
     * The scripts are done with (uploaded, refused or released): their claims are no longer renewed.
     *
     * @param  list<int>  $ids
     */
    public function letGo(array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->held[$id]);
        }
    }

    /**
     * The claim heartbeat: every `fetch.renew_seconds` while scripts are held, the server renews their claims
     * (`POST api/caster/claims/renew`), so a block voiced for longer than `slot_claim_seconds` is still accepted.
     * Asked before every VoiceStudio call; a failed renewal never stops the voicing, it only costs the next try.
     */
    public function keepClaims(): void
    {
        if ($this->held === [] || $this->stopping || self::clock() - $this->renewedAt < (float) config('tv.commentary.fetch.renew_seconds', 60)) {
            return;
        }

        $this->renewedAt = self::clock();

        try {
            $renewal = $this->server()->timeout(10)->withHeaders(['X-Caster-Scripts' => implode(',', array_keys($this->held))])
                ->post($this->settings()['server'].'/api/caster/claims/renew');

            if (! $renewal->successful()) {
                $this->note("claim renewal refused: {$renewal->status()}");
            }
        } catch (Throwable $exception) {
            // The voicing goes on: at worst the claim lapses as it did before the heartbeat.
            $this->note('claim renewal failed: '.$exception->getMessage());
        }
    }

    /**
     * The sentences and their speakers of a claimed script (the claim's `script`), Markus for a line without one;
     * `duet` when the server named speakers.
     *
     * @param  array<array-key, mixed>  $script
     * @return array{sentences: list<string>, speakers: list<string>, duet: bool}
     */
    public static function lines(array $script): array
    {
        $given = is_array($script['speakers'] ?? null) ? array_values($script['speakers']) : [];
        [$sentences, $speakers] = [[], []];

        foreach (array_values((array) ($script['sentences'] ?? [])) as $index => $line) {
            if (is_string($line) && trim($line) !== '') {
                $sentences[] = $line;
                $speakers[] = is_string($given[$index] ?? null) ? $given[$index] : DuetDirector::MARKUS;
            }
        }

        return ['sentences' => $sentences, 'speakers' => $speakers, 'duet' => $given !== []];
    }

    /**
     * Voices one claimed script alone and uploads its clip (a live moment, a
     * chat reaction, or every script while blocks are off).
     *
     * @param  array<array-key, mixed>  $script
     * @param  array<string, string>  $terms
     */
    public function voiceAndUpload(array $script, array $terms): string
    {
        $settings = $this->settings();
        $id = (int) $script['id'];
        ['sentences' => $sentences, 'speakers' => $speakers, 'duet' => $duet] = self::lines($script);

        $voiced = $sentences === [] ? null : $this->voice($sentences, $terms, $duet ? $speakers : null, TalkShow::turnGap($script['topic'] ?? null), $script['kind'] ?? null);
        $opus = $voiced === null ? null : $this->encode($voiced['pcm'], $voiced['rate']);

        if ($voiced === null || $opus === null) {
            $this->note("script {$id}: ".($voiced === null ? 'voicing failed' : 'encoding failed').', released');
            $this->release($id);

            return self::RELEASED;
        }

        try {
            $upload = $this->server()
                ->withHeaders([
                    'X-Caster-Duration' => sprintf('%.3F', Pcm::seconds($voiced['pcm'], $voiced['rate'])),
                    'X-Caster-Sentence-Ends' => implode(',', array_map(fn (float $end): string => sprintf('%.3F', $end), $voiced['ends'])),
                ])
                ->withBody(CasterFetcher::keepLocal($opus, 'clip', timeless: false), 'audio/ogg')
                ->post($settings['server'].'/api/caster/scripts/'.$id.'/clip');
        } catch (ConnectionException) {
            $this->note("script {$id}: upload failed, server unreachable, released");
            $this->release($id);

            return self::OFFLINE;
        }

        $this->letGo([$id]);
        $this->note(self::uploadLine("script {$id}", $upload, Pcm::seconds($voiced['pcm'], $voiced['rate'])));

        if ($upload->status() === 201) {
            return self::VOICED;
        }

        $this->release($id);

        return $upload->status() === 507 ? self::FULL : self::RELEASED;
    }

    /**
     * The sentences in chunks of one thought for one /generate call each: at
     * most `chunk.max_sentences` sentences and `chunk.max_words` spoken words.
     * A sentence led by a marker ("[laughter] …") starts a chunk, and one that
     * ends on a marker ("… [laughter]") ends it, so the sound sits at the
     * chunk's edge. One sentence per call made the voice choppy (user
     * 2026-10-11: "Und er spricht zu abgehackt"): every call trimmed its tail
     * and got a pause, the model's own prosody never carried a sentence into
     * the next.
     *
     * @param  list<string>  $sentences
     * @return list<list<string>>
     */
    public static function chunks(array $sentences, int $maxSentences, int $maxWords): array
    {
        $chunks = [];
        $current = [];
        $words = 0;
        $markerLed = '/^\s*\[[a-z-]+\]/u';

        foreach ($sentences as $sentence) {
            $count = count(preg_split('/\s+/u', ScriptGuard::withoutMarkers($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if ($current !== [] && (preg_match($markerLed, $sentence) === 1 || count($current) >= $maxSentences || $words + $count > $maxWords)) {
                $chunks[] = $current;
                [$current, $words] = [[], 0];
            }

            $current[] = $sentence;
            $words += $count;

            if (preg_match('/\[[a-z-]+\]\s*$/u', $sentence) === 1) {
                $chunks[] = $current;
                [$current, $words] = [[], 0];
            }
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * The sentences voiced chunk by chunk (chunks()), joined with pauses
     * between the chunks only, in memory. `ends` are the chunk ends.
     *
     * A duet (`$speakers`, one per sentence, DuetDirector): a chunk never
     * spans two speakers, each is voiced with its speaker's profile
     * (`voicestudio.profiles`, Markus' `profile_id` for a speaker without
     * one), and a speaker change gets a breath of `speaker_pause` seconds
     * (`$turnGap` instead: the talk show's quicker turns, TalkShow::turnGap()).
     *
     * @param  list<string>  $sentences
     * @param  array<string, string>  $terms  the pronunciation dictionary
     * @param  list<string>|null  $speakers  the speaker of each sentence; null: Markus alone
     * @param  array{min: float, max: float}|null  $turnGap
     * @return array{pcm: string, rate: int, ends: list<float>}|null null when VoiceStudio failed past its retries
     */
    public function voice(array $sentences, array $terms = [], ?array $speakers = null, ?array $turnGap = null, ?string $kind = null): ?array
    {
        $settings = $this->settings();
        $pause = (array) $settings['pause'];
        $speakerPause = $turnGap ?? (array) ($settings['speaker_pause'] ?? []);
        $chunks = $this->plannedChunks($sentences, $speakers, $kind);
        $pcm = '';
        $rate = null;
        $ends = [];

        foreach ($chunks as $index => [$speaker, $lines]) {
            $sentence = (string) end($lines);
            $voiced = $this->voiceChunk($lines, $speaker, $terms);

            if ($voiced === null || ($rate !== null && $voiced['rate'] !== $rate)) {
                return null;
            }

            $rate = $voiced['rate'];
            $pcm .= $voiced['pcm'];
            $ends[] = round(Pcm::seconds($pcm, $rate), 3);

            if ($index < count($chunks) - 1 && $chunks[$index + 1][0] !== $speaker) {
                $min = (float) ($speakerPause['min'] ?? 0.45);
                $pcm .= Pcm::silence(max(0.1, $min + max(0.0, (float) ($speakerPause['max'] ?? 0.7) - $min) * (mt_rand(0, 1000) / 1000)), $rate);
            } elseif ($index < count($chunks) - 1) {
                $base = self::sentencePause($sentence, $pause, $kind);
                $jitter = (float) ($pause['jitter'] ?? 0.0);
                $pcm .= Pcm::silence(max(0.1, $base + $jitter * (mt_rand(-1000, 1000) / 1000)), $rate);
            }
        }

        return $rate === null || $pcm === '' ? null : ['pcm' => $pcm, 'rate' => $rate, 'ends' => $ends];
    }

    /**
     * One chunk (the lines of one speaker for one /generate call) in the
     * speaker's voice and tempo, at the common speech level (Sirius' clone
     * was much louder than Markus', user 2026-10-12; `levels` fine-tunes a
     * speaker in dB) and trimmed to its speech. null when VoiceStudio failed
     * past its retries or answered no WAV.
     *
     * @param  list<string>  $lines
     * @param  array<string, string>  $terms
     * @return array{pcm: string, rate: int}|null
     */
    public function voiceChunk(array $lines, string $speaker, array $terms = [], ?string $profileId = null, ?float $speed = null): ?array
    {
        $settings = $this->settings();
        $wav = $this->generate(Pronunciation::spoken(implode(' ', $lines), $terms), $profileId ?? $this->profile($speaker), $speed ?? $this->speed($speaker, self::heated($lines)));

        if ($wav === null) {
            return null;
        }

        try {
            ['pcm' => $part, 'rate' => $rate] = Pcm::fromWav($wav);
        } catch (InvalidArgumentException) {
            return null;
        }

        $level = (float) ($settings['speech_dbfs'] ?? -20.0) + (float) ($settings['levels'][$speaker] ?? 0.0);

        return ['pcm' => Pcm::trimEdges(Pcm::levelled($part, $level), $rate, (int) ($settings['silence_threshold'] ?? 150), (int) ($settings['pad_ms'] ?? 140), (int) ($settings['fade_ms'] ?? 100)), 'rate' => $rate];
    }

    /**
     * The chunks of a script in speaking order: consecutive sentences of one
     * speaker form a run, and the chunks (chunks()) are cut within a run only.
     *
     * @param  list<string>  $sentences
     * @param  list<string>|null  $speakers  null: Markus alone
     * @return list<array{0: string, 1: list<string>}> speaker and lines of each chunk
     */
    public function plannedChunks(array $sentences, ?array $speakers = null, ?string $kind = null): array
    {
        $chunk = (array) ($this->settings()['chunk'] ?? []);
        $maxSentences = self::readsSentenceBySentence($kind) ? 1 : max(1, (int) ($chunk['max_sentences'] ?? 3));
        $runs = [];

        foreach ($sentences as $index => $sentence) {
            $speaker = $speakers[$index] ?? DuetDirector::MARKUS;
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][0] === $speaker) {
                $runs[$last][1][] = $sentence;
            } else {
                $runs[] = [$speaker, [$sentence]];
            }
        }

        $chunks = [];

        foreach ($runs as [$speaker, $lines]) {
            foreach (self::chunks($lines, $maxSentences, max(1, (int) ($chunk['max_words'] ?? 25))) as $part) {
                $chunks[] = [$speaker, $part];
            }
        }

        return $chunks;
    }

    /** Whether a script kind is voiced one sentence per call, so every sentence gets its own pause (the news). */
    public static function readsSentenceBySentence(?string $kind): bool
    {
        return $kind !== null && in_array($kind, (array) config('tv.commentary.voicestudio.chunk.one_sentence_kinds', []), true);
    }

    /**
     * The pause after a sentence of one speaker: the news' own, else longer after ! ? : than after a full stop.
     *
     * @param  array<string, mixed>  $pause  `voicestudio.pause`
     */
    public static function sentencePause(string $sentence, array $pause, ?string $kind): float
    {
        if (self::readsSentenceBySentence($kind)) {
            return (float) ($pause['news'] ?? 0.55);
        }

        return ScriptGuard::accented($sentence) ? (float) ($pause['accent'] ?? 0.35) : (float) ($pause['sentence'] ?? 0.2);
    }

    /**
     * Loudness to -16 LUFS and Opus at speech bitrate, ffmpeg from stdin to
     * stdout (no file). null when ffmpeg fails.
     */
    public function encode(string $pcm, int $rate): ?string
    {
        $settings = $this->settings();

        try {
            // A programme block of six minutes takes a few seconds; a single clip well under one.
            $result = Process::timeout(240)->env(ChildEnvironment::withoutSecrets())->input($pcm)->run([
                (string) $settings['ffmpeg'], '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-f', 's16le', '-ar', (string) $rate, '-ac', '1', '-i', 'pipe:0',
                '-af', 'loudnorm=I='.(int) $settings['loudness_lufs'].':TP=-1.5:LRA=11',
                '-ar', '48000', '-c:a', 'libopus', '-b:a', ((int) $settings['opus_kbps']).'k', '-application', 'voip',
                '-f', 'ogg', 'pipe:1',
            ]);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() && str_starts_with($result->output(), 'OggS') ? $result->output() : null;
    }

    /**
     * One sentence from VoiceStudio as WAV bytes in the voice of
     * `$profileId` at `$speed` (and, given, a total `$duration` in seconds:
     * VoiceStudio stretches the speech to it, the Anchor's slow jingles),
     * retried on connection errors and 5xx; its history entry deleted right after.
     */
    /**
     * A copy of an uploaded block in `voicestudio.keep_local_dir` (user 2026-10-13: "die lass ausnahmsweise für
     * eine Abnahme auch lokal hier auf dem Rechner liegen"; user 2026-10-15: only the timeless programmes —
     * "Turnier-Breakings brauche ich da nicht, nur die zeitlosen"): clips are never kept (everything a clip
     * carries is perishable - a breaking, a chat reply, a day's scene), and a block is kept only when none of
     * its topics is time-critical (a live tournament, the news talk, sport and weather). Off when the
     * directory is empty. Returns the audio unchanged, so it wraps an upload body.
     */
    public static function keepLocal(string $opus, string $label, bool $timeless = true): string
    {
        $dir = (string) config('tv.commentary.voicestudio.keep_local_dir', '');

        if ($dir !== '' && $opus !== '' && $timeless) {
            try {
                if (! is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                file_put_contents(rtrim($dir, '/').'/'.date('Ymd-His').'-'.$label.'-'.substr(sha1($opus), 0, 8).'.ogg', $opus);
            } catch (Throwable) {
                // Listening copies never hold up the broadcast.
            }
        }

        return $opus;
    }

    /**
     * Whether a block's topics are all timeless (user 2026-10-15: nothing that carries a clock; user 2026-10-16:
     * "News, Sport und Wetter will ich auch nicht haben"): a live tournament, the news talk, sport and weather,
     * a bulletin, the news itself and every programme the clock places are perishable - only the evergreen
     * programmes stay in the local render archive.
     *
     * @param  array<mixed>  $topics
     */
    public static function timelessTopics(array $topics): bool
    {
        $perishable = [Programme\TournamentLive::TOPIC, Programme\NewsTalk::TOPIC, Programme\SportWeather::TOPIC, Programme\TournamentLive::TOPIC];

        return collect($topics)->filter(fn ($topic): bool => is_string($topic) && $topic !== '')
            ->every(fn (string $topic): bool => ! in_array($topic, $perishable, true));
    }

    /**
     * The programme name of a block's topics for its file name (user 2026-10-16: "im Dateinamen mit ihrem
     * Programmnamen benennen, dann weiß ich was drinnen steckt"): a stock topic names its format
     * ("stock:talk:..." -> "talk"), anything else stays "talk" - the file always says what is inside.
     *
     * @param  array<mixed>  $topics
     */
    public static function programmeLabel(array $topics): string
    {
        foreach ($topics as $topic) {
            if (is_string($topic) && str_starts_with($topic, Blocks\StockScripts::TOPIC_PREFIX)) {
                $format = explode(':', substr($topic, strlen(Blocks\StockScripts::TOPIC_PREFIX)))[0];

                if ($format !== '') {
                    return $format;
                }
            }
        }

        return 'talk';
    }

    public function generate(string $text, string $profileId, float $speed, ?string $language = null, ?float $duration = null): ?string
    {
        $settings = $this->settings();
        $attempts = max(1, (int) $settings['retries'] + 1);
        $unreachable = 0;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            // A stop was asked for: no new VoiceStudio call, the block in the making is abandoned and released.
            if ($this->stopping) {
                return null;
            }

            // Minutes of voicing for one block: the claims it holds are renewed meanwhile.
            $this->keepClaims();

            try {
                $response = Http::asMultipart()->timeout((int) $settings['timeout_seconds'])->post(rtrim((string) $settings['url'], '/').'/generate', [
                    'text' => $text,
                    'profile_id' => $profileId,
                    'speed' => (string) $speed,
                    'language' => $language ?? (string) $settings['language'],
                    ...($duration === null ? [] : ['duration' => sprintf('%.1F', $duration)]),
                    // The generation settings the user chose in VoiceStudio (2026-10-13: 64 steps, CFG 2, t_shift 0.1, …).
                    ...array_map('strval', array_filter((array) ($settings['generation'] ?? []), fn (mixed $value): bool => $value !== null && $value !== '')),
                ]);

                $this->forget($response);

                if ($response->successful() && str_starts_with($response->body(), 'RIFF')) {
                    return $response->body();
                }

                $this->note("VoiceStudio answered {$response->status()} (try {$attempt} of {$attempts})");

                if ($response->clientError()) {
                    return null;
                }
            } catch (ConnectionException) {
                // VoiceStudio is restarting or busy: wait and try again.
                $unreachable++;
                $this->note("VoiceStudio unreachable (try {$attempt} of {$attempts})");
            }

            if ($attempt < $attempts && ! $this->stopping) {
                ($this->sleep)((float) $settings['retry_seconds'] * $attempt);
            }
        }

        // Not one try reached it: VoiceStudio is down (P18); the next run asks /profiles before it claims again.
        $this->voiceDown = $this->voiceDown || $unreachable === $attempts;

        return null;
    }

    /** Deletes the history entry VoiceStudio named for this generation, and only that one. */
    private function forget(Response $response): void
    {
        $id = (string) $response->header('x-audio-id');

        if (preg_match('/^[A-Za-z0-9_-]{4,64}$/', $id) !== 1) {
            return;
        }

        try {
            Http::timeout(10)->delete(rtrim((string) $this->settings()['url'], '/').'/history/'.$id);
        } catch (ConnectionException) {
            // Left in VoiceStudio's history (its own retention cap still applies).
        }
    }

    /** The VoiceStudio profile of a speaker (`voicestudio.profiles`); Markus' `profile_id` for one without a profile. */
    private function profile(string $speaker): string
    {
        $settings = $this->settings();
        $profile = ((array) ($settings['profiles'] ?? []))[$speaker] ?? null;

        return is_string($profile) && trim($profile) !== '' ? trim($profile) : (string) $settings['profile_id'];
    }

    /**
     * The tempo of a speaker (`voicestudio.speeds`), Markus' `speed` for one without its own; a heated chunk takes
     * the speaker's `heated_speeds` where there is one (the talk show's guest, P16).
     */
    private function speed(string $speaker, bool $heated = false): float
    {
        $settings = $this->settings();
        $hot = $heated ? (((array) ($settings['heated_speeds'] ?? []))[$speaker] ?? null) : null;
        $speed = is_numeric($hot) ? $hot : (((array) ($settings['speeds'] ?? []))[$speaker] ?? null);

        return is_numeric($speed) ? (float) $speed : (float) ($settings['speed'] ?? 1.0);
    }

    /**
     * Whether a chunk is heated: its last sentence ends with an exclamation mark (markers aside).
     *
     * @param  list<string>  $lines
     */
    public static function heated(array $lines): bool
    {
        return $lines !== [] && str_ends_with(rtrim(ScriptGuard::withoutMarkers((string) end($lines))), '!');
    }

    /** One journal line for an upload: what, how long, the status and the server's reason when it refused. */
    public static function uploadLine(string $what, Response $upload, float $seconds): string
    {
        $error = $upload->status() === 201 ? '' : ' '.(is_string($upload->json('error')) ? $upload->json('error') : 'refused')
            .(is_numeric($upload->json('script')) ? ' (script '.$upload->json('script').')' : '');

        return sprintf('%s: %.1f s uploaded, %d%s', $what, $seconds, $upload->status(), $error);
    }

    public function release(int $id): void
    {
        unset($this->held[$id]);

        try {
            // While stopping, every release must get through within the stop timeout.
            $this->server()->timeout($this->stopping ? 5 : 30)->post($this->settings()['server'].'/api/caster/scripts/'.$id.'/release');
        } catch (ConnectionException) {
            // The server frees an unanswered claim after `claim_seconds` anyway.
        }
    }

    public function server(): PendingRequest
    {
        return Http::withToken((string) config('tv.commentary.fetch.token'))->acceptJson()->timeout(30);
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = (array) config('tv.commentary.voicestudio', []);
        $settings['server'] = rtrim((string) ($settings['server'] ?? ''), '/');

        return $settings;
    }
}
