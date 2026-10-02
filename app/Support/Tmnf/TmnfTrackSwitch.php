<?php

namespace App\Support\Tmnf;

use App\Models\LeagueWeek;
use App\Support\Scores\LeagueWeekDrafts;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Puts the league's TMNF server on the track of the week (user 2026-10-02:
 * the admins pick the track before a week starts), from the listener
 * (`tmnf:listen`, every CHECK_SECONDS while connected):
 *
 * - The target is TmnfWeeks::target(): this week's approved plan once it may
 *   start, else the running week's track. Nothing due, nothing is touched.
 * - On another track the server is switched with the stock path of the
 *   week's track (`esports.tmnf.tracks.*.file`, relative to GameData/Tracks):
 *   SetTimeAttackLimit (the week's time limit, from the next track on),
 *   InsertChallenge (unless it is in the selection already),
 *   ChooseNextChallenge, NextChallenge; on the right track with another
 *   time limit, RestartChallenge. The method names and their
 *   signatures are the server's own (system.methodSignature and
 *   system.methodHelp of build 2011-02-21, read on 2026-10-02, and its
 *   ListMethods.html).
 * - Once the server plays the week's track the other tracks leave the
 *   selection (RemoveChallenge), so a round's end loads the same track
 *   again, and a plan that waited for it is marked ready and its week opens
 *   (TmnfWeeks::open()). A week never opens on another track; a finish on
 *   another track never counts anyway (TmnfWeeks::weekOf() compares the UId).
 * - Refused by the server, or the server unreachable (the listener retries
 *   with its backoff): the admins hear it once per week in the bell, after
 *   WARN_AFTER_SECONDS past the planned start, so a switch that only
 *   needed a second try does not ring.
 *
 * Holds the state of one listener process only (when it last checked, which
 * switch it sent), never a cache shared across processes.
 */
final class TmnfTrackSwitch
{
    public const CHECK_SECONDS = 10;

    /** How long a sent switch is given to arrive (BeginChallenge) before it is sent again. */
    public const SWITCH_WAIT_SECONDS = 30;

    public const WARN_AFTER_SECONDS = 120;

    private ?float $checkedAt = null;

    private ?float $switchedAt = null;

    /** Other tracks may still be in the selection: after a switch, and after a (re)connect. */
    private bool $prune = true;

    public function __construct(private TmnfWeeks $weeks, private LeagueWeekDrafts $drafts) {}

    /** A new session: check at once, and tidy the selection again. */
    public function connected(): void
    {
        $this->checkedAt = null;
        $this->switchedAt = null;
        $this->prune = true;
    }

    public function due(?float $at = null): bool
    {
        $at ??= microtime(true);

        return $this->checkedAt === null || $at - $this->checkedAt >= self::CHECK_SECONDS;
    }

    /**
     * One check of the server's track; a line for the log when something happened.
     *
     * @throws GbxUnavailable|GbxProtocolError when the session broke (the listener reconnects with its backoff)
     */
    public function sync(TmnfServer $server, ?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now();
        $this->checkedAt = microtime(true);
        $target = $this->weeks->target($now);

        if ($target === null) {
            return null;
        }

        ['track' => $track, 'limit_ms' => $limit, 'plan' => $plan] = $target;
        $current = $server->currentChallenge();
        $onTrack = $current->uid === $track['uid'];

        if ($onTrack && ($limit === null || $server->timeAttackLimit() === $limit)) {
            $this->switchedAt = null;
            $this->pruneSelection($server, $current);

            return $this->ready($plan, $now, $track);
        }

        if ($this->switchedAt !== null && microtime(true) - $this->switchedAt < self::SWITCH_WAIT_SECONDS) {
            return null;
        }

        if ($track['file'] === '') {
            $this->warn($plan, $now, 'the track has no file path in esports.tmnf.tracks');

            return "cannot switch to {$track['name']} ({$track['uid']}): no file path";
        }

        try {
            if ($limit !== null) {
                $server->setTimeAttackLimit($limit);
            }

            if (! $onTrack && ! $this->inSelection($server, $track['uid'])) {
                $server->insertChallenge($track['file']);
            }

            if ($onTrack) {
                // On the track with another limit: the same track again, which takes the limit.
                $server->restartChallenge();
            } else {
                $server->chooseNextChallenge($track['file']);
                $server->nextChallenge();
            }
        } catch (GbxFault $e) {
            // Refused (no such file, "Change in progress."): tried again after SWITCH_WAIT_SECONDS.
            $this->switchedAt = microtime(true);
            $this->warn($plan, $now, $e->getMessage());

            return "switch to {$track['name']} refused: {$e->getMessage()}";
        }

        $this->switchedAt = microtime(true);
        $this->prune = true;

        return "switching the server to {$track['name']} ({$track['uid']}, {$track['file']})".($limit === null ? '' : ', '.intdiv($limit, 60_000).' min a round');
    }

    /**
     * The server could not be reached or the session broke: the admins hear
     * it once, while a week is due and its track not confirmed.
     */
    public function unreachable(string $reason, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        try {
            $target = $this->weeks->target($now);
            $this->warn($target['plan'] ?? null, $now, $reason);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The week waits for the server and nobody switched it (the listener does
     * not run): the weeks command tells the admins, once.
     */
    public function overdue(?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $target = $this->weeks->target($now);
        $plan = $target['plan'] ?? null;

        if ($plan !== null && $plan->track_ready_at === null) {
            $this->warn($plan, $now, 'the listener (tmnf:listen) has not put the server on the track');
        }
    }

    /**
     * @param  array{uid: string, name: string, author: string, environment: string, author_ms: int, file: string}  $track
     */
    private function ready(?LeagueWeek $plan, CarbonImmutable $now, array $track): ?string
    {
        if ($plan === null || $plan->track_ready_at !== null) {
            return null;
        }

        LeagueWeek::query()->whereKey($plan->id)->whereNull('track_ready_at')->update(['track_ready_at' => $now->utc(), 'updated_at' => $now->utc()]);
        $week = $this->weeks->open($now);

        return $week === null
            ? "on {$track['name']} ({$track['uid']}); the week does not open yet"
            : "on {$track['name']} ({$track['uid']}): {$week->name} is open";
    }

    private function inSelection(TmnfServer $server, string $uid): bool
    {
        foreach ($server->challengeList(200) as $challenge) {
            if (($challenge['UId'] ?? null) === $uid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only the track being played stays in the selection, so the next round runs it again.
     */
    private function pruneSelection(TmnfServer $server, TmnfChallenge $current): void
    {
        if (! $this->prune) {
            return;
        }

        $left = false;

        foreach ($server->challengeList(200) as $challenge) {
            $file = $challenge['FileName'] ?? null;

            if (is_string($file) && $file !== '' && ($challenge['UId'] ?? null) !== $current->uid) {
                try {
                    $server->removeChallenge($file);
                } catch (GbxFault) {
                    // "Change in progress." right after a switch: the next check tries again.
                    $left = true;
                }
            }
        }

        $this->prune = $left;
    }

    private function warn(?LeagueWeek $plan, CarbonImmutable $now, string $reason): void
    {
        if ($plan === null || $plan->track_ready_at !== null || $now->lessThan(LeagueWeekDrafts::startTime($plan)->addSeconds(self::WARN_AFTER_SECONDS))) {
            return;
        }

        $claimed = LeagueWeek::query()->whereKey($plan->id)->whereNull('warned_at')->whereNull('track_ready_at')
            ->update(['warned_at' => $now->utc(), 'updated_at' => $now->utc()]) === 1;

        if ($claimed) {
            $this->drafts->tellAdmins($plan, 'The TMNF server is not on the track of week :week', 'The week starts only once the server runs its track; the listener keeps trying. Reason: :reason', ['reason' => mb_substr($reason, 0, 160)]);
        }
    }
}
