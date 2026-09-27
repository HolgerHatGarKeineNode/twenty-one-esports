<?php

/*
 * Helpers of the casual 1v1 tests (P23), shared by the Casual* files in
 * tests/Feature/Series (loaded from tests/Pest.php).
 */

use App\Enums\Platform;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Events\UserNotified;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Series\CasualMatches;
use App\Support\Series\CasualQueue;
use App\Support\Series\SeriesRuleViolation;
use Illuminate\Support\Facades\Event;

/**
 * Two players paired by the queue, in the ready check.
 *
 * @return array{0: SeriesMatch, 1: User, 2: User} the match, the challenger, the challenged
 */
function casualPairing(string $game = 'ea-sports-fc-26'): array
{
    [$anna, $bert] = User::factory()->count(2)->create();
    $queue = app(CasualQueue::class);

    $queue->join($anna, $game, Platform::Pc);
    $match = $queue->join($bert, $game, Platform::Pc) ?? throw new LogicException('The queue did not pair.');

    return [$match, $anna, $bert];
}

/**
 * A paired match with both players ready: started now.
 *
 * @return array{0: SeriesMatch, 1: User, 2: User} the match, its host, its guest
 */
function casualStarted(string $game = 'ea-sports-fc-26'): array
{
    [$match, $anna, $bert] = casualPairing($game);
    $matches = app(CasualMatches::class);
    $matches->ready($match, $anna);
    $match = $matches->ready($match, $bert);

    return $match->host_side === 'challenger' ? [$match, $anna, $bert] : [$match, $bert, $anna];
}

/** A casual match this player lost by a no-show forfeit, finished at `$at`. */
function casualNoShowLoss(User $loser, DateTimeInterface $at): SeriesMatch
{
    $match = app(CasualMatches::class)->create(User::factory()->create(), $loser, 'rocket-league', SeriesMatch::ORIGIN_QUEUE, []);
    $match->forceFill(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Forfeit, 'winner' => 'challenger', 'finished_at' => $at])->save();

    return $match;
}

/** The `reason` of the SeriesRuleViolation the action throws, null if none. */
function casualRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (SeriesRuleViolation $refused) {
        return $refused->reason;
    }

    return null;
}

/**
 * @return list<array{0: int, 1: string}> [user id, kind] of every notification pushed to an open page
 */
function casualAlerts(): array
{
    return Event::dispatched(UserNotified::class)->map(fn (array $args) => [$args[0]->userId, $args[0]->alert['kind']])->values()->all();
}

/** The side this player plays in the match. */
function casualSideOf(SeriesMatch $match, User $user): string
{
    return $match->isRosterSideMember('challenger', $user) ? 'challenger' : 'challenged';
}
