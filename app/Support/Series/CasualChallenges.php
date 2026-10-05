<?php

namespace App\Support\Series;

use App\Enums\Platform;
use App\Enums\SeriesStatus;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Moderation\SiteModeration;
use App\Support\Notifications\CasualNotifications;
use App\Support\Tournaments\CupMatchNow;
use Illuminate\Support\Facades\DB;

/**
 * A scheduled casual 1v1 without a clan (P23 S4): the challenger suggests
 * one to three starts and a reply deadline (the lineup challenge's rules,
 * SeriesService::schedule()); the opponent picks one, or declines; the
 * challenger may withdraw while it is open. Unanswered by the deadline, it
 * expires (`casual:tick`, {@see expireDue()}).
 *
 * Accepted, the match waits for its start. Both check in from
 * `checkin_before_minutes` before it until `checkin_after_minutes` after
 * (CasualMatches::checkIn()); a side that did not forfeits, and neither:
 * void (CasualScheduler). Once both are in, it runs as an instant match
 * from the lobby on: the check-in replaces the ready check.
 *
 * One intent at a time: a scheduled match blocks the queue, invites and
 * live chess only from its check-in window on (CasualMatches::runningMatchOf()).
 *
 * Limits (as `chess.challenges_per_day`): `challenges_per_day` in 24 hours,
 * `challenges_per_recipient_per_day` to the same player. A player locked
 * for no-shows sends no challenge.
 */
final class CasualChallenges
{
    public function __construct(private CasualMatches $matches, private CasualNotifications $notifications) {}

    /**
     * @param  list<int>  $proposals  unix seconds
     *
     * @throws SeriesRuleViolation
     */
    public function challenge(User $challenger, User $opponent, string $game, Platform $platform, bool $crossplay, array $proposals, int $respondBy, ?string $message = null): SeriesMatch
    {
        if ($challenger->is($opponent)) {
            throw CasualMatches::refuse('invite_self');
        }

        // A key banned from the site takes part in nothing (SiteModeration).
        if (SiteModeration::isBanned($opponent->pubkey) || SiteModeration::isBanned($challenger->pubkey)) {
            throw CasualMatches::refuse('not_available');
        }

        $this->matches->assertGame($game);

        $until = $this->matches->lockedUntil($challenger);

        if ($until !== null) {
            throw CasualMatches::refuse('queue_locked', ['time' => $until->copy()->timezone($challenger->timezone ?? config('esports.preseason.display_timezone'))->format('H:i')]);
        }

        // The casual lock (user, 2026-10-03): an open cup match in a running round comes first.
        if (($cup = CupMatchNow::refusal($challenger, $opponent)) !== null) {
            throw CasualMatches::refuse($cup['reason'], ['name' => $opponent->displayName()]);
        }

        ['proposals' => $proposals, 'message' => $message] = SeriesService::schedule($proposals, $respondBy, $message);

        $match = DB::transaction(function () use ($challenger, $opponent, $game, $platform, $crossplay, $proposals, $respondBy, $message): SeriesMatch {
            if ($this->limitReached($challenger, $opponent)) {
                throw CasualMatches::refuse('challenge_limit');
            }

            return $this->matches->createChallenge($challenger, $opponent, $game, $proposals, now()->setTimestamp($respondBy), $message,
                ['platform' => $platform->value, 'crossplay' => $crossplay]);
        });

        $this->notifications->challengeReceived($match);

        return $match;
    }

    /**
     * The opponent picks one of the suggested starts, with the platform
     * they play on; the platforms have to fit (CasualMatches::compatible()).
     * The deadlines are pinned now, the host is drawn at the challenge.
     *
     * @throws SeriesRuleViolation
     */
    public function accept(SeriesMatch $match, User $user, int $start, Platform $platform, bool $crossplay): SeriesMatch
    {
        $match = $this->open($match, $user, 'challenged');

        // The casual lock (user, 2026-10-03): an open cup match in a running round comes first.
        if (CupMatchNow::lockOf($user) !== null) {
            throw CasualMatches::refuse(CupMatchNow::LOCKED);
        }

        if (! in_array($start, $match->proposals, true)) {
            throw CasualMatches::refuse('start_not_proposed');
        }

        if ($start <= now()->getTimestamp()) {
            throw CasualMatches::refuse('start_past');
        }

        $challenger = $match->casual['queue']['challenger'] ?? null;
        $theirs = Platform::tryFrom((string) ($challenger['platform'] ?? ''));

        if ($theirs === null || ! CasualMatches::compatible($match->game, $theirs, (bool) ($challenger['crossplay'] ?? false), $platform, $crossplay)) {
            throw CasualMatches::refuse('platforms_incompatible');
        }

        $pinned = CasualMatches::pinned();
        $casual = [
            ...$pinned,
            'scheduled_at' => $start,
            'queue' => ['challenger' => $challenger, 'challenged' => ['platform' => $platform->value, 'crossplay' => $crossplay]],
        ];

        $accepted = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Open)->where('respond_by', '>', now())
            ->update([
                'status' => SeriesStatus::Accepted,
                'start_at' => now()->setTimestamp($start),
                'ready_by' => now()->setTimestamp($start)->addMinutes($pinned['checkin_after_minutes']),
                'answered_by_id' => $user->id,
                'answered_at' => now(),
                'casual' => json_encode($casual),
            ]);

        $match->refresh();

        if ($accepted !== 1) {
            throw CasualMatches::refuse('challenge_closed');
        }

        $this->notifications->challengeAnswered($match, 'accepted');
        $this->matches->announce($match);

        return $match;
    }

    /**
     * @throws SeriesRuleViolation
     */
    public function decline(SeriesMatch $match, User $user): void
    {
        $this->close($this->open($match, $user, 'challenged'), SeriesStatus::Declined, $user);
    }

    /**
     * @throws SeriesRuleViolation
     */
    public function withdraw(SeriesMatch $match, User $user): void
    {
        $this->close($this->open($match, $user, 'challenger'), SeriesStatus::Withdrawn, $user);
    }

    /**
     * Open challenges nobody answered by their reply deadline end as
     * expired, and the challenger hears of it. Each once: the update takes
     * a row only while it is still open.
     */
    public function expireDue(): int
    {
        $expired = 0;

        foreach (SeriesMatch::query()->where('origin', SeriesMatch::ORIGIN_CHALLENGE)->where('status', SeriesStatus::Open)->where('respond_by', '<=', now())->get() as $match) {
            if ($this->closeIfOpen($match, SeriesStatus::Expired, null)) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * Challenges this player sent in the last 24 hours, in total and to
     * this opponent, against the limits.
     */
    private function limitReached(User $challenger, User $opponent): bool
    {
        $sent = SeriesMatch::query()->where('origin', SeriesMatch::ORIGIN_CHALLENGE)->where('created_by_id', $challenger->id)
            ->where('created_at', '>', now()->subDay());

        return (clone $sent)->count() >= (int) config('esports.casual.challenges_per_day')
            || $sent->whereIn('id', CasualMatches::onSide($opponent, 'challenged'))->count() >= (int) config('esports.casual.challenges_per_recipient_per_day');
    }

    /**
     * The challenge, still open, answered by the given side. One past its
     * reply deadline expires on the spot.
     *
     * @throws SeriesRuleViolation
     */
    private function open(SeriesMatch $match, User $user, string $side): SeriesMatch
    {
        $match = $match->fresh() ?? $match;

        if ($match->origin !== SeriesMatch::ORIGIN_CHALLENGE || ! $match->isRosterSideMember($side, $user)) {
            throw CasualMatches::refuse($side === 'challenged' ? 'not_challenged' : 'not_challenger');
        }

        if ($match->status !== SeriesStatus::Open) {
            throw CasualMatches::refuse('challenge_closed');
        }

        if ($match->respond_by->isPast()) {
            $this->closeIfOpen($match, SeriesStatus::Expired, null);

            throw CasualMatches::refuse('challenge_expired');
        }

        return $match;
    }

    /**
     * @throws SeriesRuleViolation
     */
    private function close(SeriesMatch $match, SeriesStatus $status, User $user): void
    {
        if (! $this->closeIfOpen($match, $status, $user)) {
            throw CasualMatches::refuse('challenge_closed');
        }
    }

    private function closeIfOpen(SeriesMatch $match, SeriesStatus $status, ?User $user): bool
    {
        $closed = SeriesMatch::query()->whereKey($match->id)->where('status', SeriesStatus::Open)
            ->update(['status' => $status, 'answered_by_id' => $user?->id, 'answered_at' => now(), 'finished_at' => now()]);

        if ($closed !== 1) {
            return false;
        }

        $match->refresh();
        $this->matches->announce($match);

        match ($status) {
            SeriesStatus::Declined => $this->notifications->challengeAnswered($match, 'declined'),
            SeriesStatus::Expired => $this->notifications->challengeAnswered($match, 'expired'),
            default => null,
        };

        return true;
    }
}
