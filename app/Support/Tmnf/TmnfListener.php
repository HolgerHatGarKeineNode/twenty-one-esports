<?php

namespace App\Support\Tmnf;

use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Models\ScoreRun;
use App\Models\ScoreServer;
use App\Support\Scores\FinishEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What the league does with the callbacks of its TMNF server (plan
 * "Trackmania und Restposten", P1), one connection at a time
 * (`tmnf:listen` opens it, start() reads the track it is on):
 *
 * - `TrackMania.BeginChallenge`: the track changed; finishes count for it.
 * - `TrackMania.PlayerFinish` (PlayerUid, Login, TimeOrScore): a time above 0
 *   is a finish (0 means the player retired or respawned at the start). It
 *   is stored as a run of the server source on the current track
 *   (TmnfIngest): verified as read (or held for an admin, TmnfOutliers),
 *   mapped to a player only through a linked login, idempotent per finish;
 *   a linked player's finish enters them into the week of its track (TmnfWeeks).
 * - `TrackMania.PlayerCheckpoint`: read, nothing stored (a finish carries the time).
 * - `TrackMania.PlayerChat` (PlayerUid, Login, Text, IsRegistredCmd): a
 *   `link <code>` line links the login (TmnfLinks); the player gets an
 *   answer in their own chat. Lines of the server itself (PlayerUid 0) are skipped.
 * - `TrackMania.PlayerConnect` / `PlayerDisconnect` (Login, ...): the
 *   in-game overlay for that login (TmnfOverlay); a session's start, a new
 *   track and a finish mark it too. tick() sends what is due.
 *
 * Holds the track and the overlay of its connection only, never a process-wide cache.
 */
final class TmnfListener
{
    /** The listener's own row in score_servers: finishes are idempotent per (server, finish id). */
    public const SERVER_NAME = 'TMNF league server (XML-RPC listener)';

    private ?TmnfChallenge $challenge = null;

    public function __construct(private TmnfOverlay $overlay) {}

    /**
     * Reads the track the server is on as a session starts; the overlay is shown to everyone again.
     */
    public function start(TmnfServer $server): TmnfChallenge
    {
        $this->overlay->reset();

        return $this->challenge = $server->currentChallenge();
    }

    /**
     * Sends the overlay that is due (the command calls it after every batch of callbacks).
     *
     * @throws GbxException when the connection broke
     */
    public function tick(TmnfServer $server, ?CarbonImmutable $now = null): void
    {
        $this->overlay->tick($server, $now);
    }

    public function challenge(): ?TmnfChallenge
    {
        return $this->challenge;
    }

    /**
     * Handles one callback; returns a line for the log, or null for one the league does not act on.
     */
    public function handle(TmnfCallback $callback, TmnfServer $server, ?CarbonImmutable $at = null): ?string
    {
        $params = $callback->params;

        return match ($callback->method) {
            'TrackMania.BeginChallenge' => $this->begin($params),
            'TrackMania.PlayerFinish' => $this->finish($params, $at ?? CarbonImmutable::now()),
            'TrackMania.PlayerChat' => $this->chat($params, $server),
            'TrackMania.PlayerConnect' => $this->connect($params),
            'TrackMania.PlayerDisconnect' => $this->disconnect($params),
            default => null,
        };
    }

    /**
     * @param  list<mixed>  $params
     */
    private function begin(array $params): string
    {
        $this->challenge = TmnfChallenge::fromStruct($params[0] ?? null);
        $this->overlay->began();

        return "track {$this->challenge->name} ({$this->challenge->uid})";
    }

    /**
     * @param  list<mixed>  $params
     */
    private function finish(array $params, CarbonImmutable $at): ?string
    {
        [$playerUid, $login, $time] = [$params[0] ?? null, $params[1] ?? null, $params[2] ?? null];

        if (! is_int($playerUid) || ! is_string($login) || $login === '' || ! is_int($time) || $time <= 0 || $this->challenge === null) {
            return null;
        }

        $game = app(GameRegistry::class)->find(TrackmaniaNationsForever::SLUG);

        if (! $game instanceof TrackmaniaNationsForever) {
            return null;
        }

        $challenge = $this->challenge;
        $event = new FinishEvent(
            sha1(implode('|', [$challenge->uid, $login, $time, $at->format('U.u')])),
            TrackmaniaNationsForever::MODE,
            $challenge->uid,
            $login,
            $time,
            $at,
            ['player_uid' => $playerUid, 'track' => $challenge->name, 'author_ms' => $challenge->authorTime],
        );

        $server = self::server();
        $summary = app(TmnfIngest::class)->ingestEvents($server, [$event]);
        $run = $summary['accepted'] > 0 ? ScoreRun::query()->where(['score_server_id' => $server->id, 'external_id' => $event->id])->first() : null;
        // A linked player's finish enters them into the week of its track (P2): no sign-up.
        $week = $run === null ? null : app(TmnfWeeks::class)->record($run);
        $outcome = match (true) {
            $run?->isHeld() === true => 'held for an admin (faster than the author time allows)',
            $summary['accepted'] > 0 => $week === null ? 'stored, in no running week' : "stored, {$week->name}",
            $summary['pending'] > 0 => 'pending (login not linked)',
            $summary['duplicate'] > 0 => 'duplicate',
            default => 'refused: '.implode(',', $summary['refused']),
        };
        $this->overlay->finished($login, $run, $week, $summary['pending'] > 0);

        return "finish {$time} ms on {$challenge->name}: {$outcome}";
    }

    /**
     * @param  list<mixed>  $params
     */
    private function connect(array $params): null
    {
        if (is_string($params[0] ?? null)) {
            $this->overlay->connected($params[0]);
        }

        return null;
    }

    /**
     * @param  list<mixed>  $params
     */
    private function disconnect(array $params): null
    {
        if (is_string($params[0] ?? null)) {
            $this->overlay->left($params[0]);
        }

        return null;
    }

    /**
     * @param  list<mixed>  $params
     */
    private function chat(array $params, TmnfServer $server): ?string
    {
        [$playerUid, $login, $text] = [$params[0] ?? null, $params[1] ?? null, $params[2] ?? null];

        // PlayerUid 0 is the server's own message (ChatSendServerMessage comes back as a PlayerChat).
        if (! is_int($playerUid) || $playerUid === 0 || ! is_string($login) || ! is_string($text)) {
            return null;
        }

        $result = TmnfLinks::fromChat($login, $text);

        if ($result === null) {
            return null;
        }

        $server->chatTo($login, match ($result) {
            'linked' => '$0f0Linked: your times on this server now count for your league account.',
            'login' => '$f80This code belongs to another login. Type it with the login you saved on the site.',
            'taken' => '$f80This login is linked to another league account. Ask an admin.',
            'off' => '$f80The league does not take TMNF times right now.',
            default => '$f80Unknown or expired code. Get a new one on the site.',
        });

        return "link by chat: {$result}";
    }

    /**
     * The listener's score server row, made once. Its token hash belongs to no token, so nobody can post as it.
     */
    public static function server(): ScoreServer
    {
        return ScoreServer::query()->firstOrCreate(
            ['game' => TrackmaniaNationsForever::SLUG, 'name' => self::SERVER_NAME],
            ['token_hash' => ScoreServer::hashToken('listener-'.Str::random(64))],
        );
    }
}
