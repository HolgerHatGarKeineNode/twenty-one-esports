<?php

use App\Enums\ChessInviteStatus;
use App\Enums\Platform;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\MatchNumber;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualMatches;
use App\Support\Series\CasualQueue;
use App\Support\Series\CasualScheduler;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\DB;

/*
 * One running casual 1v1 per player, under concurrency: the busy check of an
 * accept or a pairing is a read, so the match itself claims both players
 * (`casual_claims`, unique per user) in the transaction that creates it. A
 * claim is taken over once its match stopped running, whichever path ended
 * it. Crossing invites (A -> B and B -> A, as two "Rematch" clicks) close
 * each other: accepting one withdraws the other.
 */

beforeEach(fn () => $this->freezeTime());

/**
 * A casual match between the two, created as a pairing whose busy check
 * passed before the other pairing committed: the write that would follow
 * the stale read in a concurrent run.
 */
function casualRacedPairing(User $challenger, User $challenged, string $origin = SeriesMatch::ORIGIN_INVITE): SeriesMatch
{
    return DB::transaction(fn () => app(CasualMatches::class)->create($challenger, $challenged, 'rocket-league', $origin, []));
}

test('crossing rematch invites: the first accept withdraws the other, and the second accept makes no match', function () {
    [$match, $host, $guest] = casualStarted('rocket-league');
    $match->forceFill(['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'finished_at' => now()])->save();
    $invites = app(CasualInvites::class);

    $fromHost = $invites->rematch($match, $host);
    $fromGuest = $invites->rematch($match, $guest);
    $staleFromGuest = SeriesInvite::query()->findOrFail($fromGuest->id);

    $rematch = $invites->accept($fromHost, $guest, Platform::Pc, true);

    expect($fromGuest->refresh()->status)->toBe(ChessInviteStatus::Withdrawn)
        ->and(casualRefusal(fn () => $invites->accept($staleFromGuest, $host, Platform::Pc, true)))->toBe('invite_closed')
        ->and(SeriesMatch::query()->whereKeyNot($match->id)->pluck('id')->all())->toBe([$rematch->id]);
});

test('a second pairing whose busy check raced the first is refused and makes no match', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create();

    $first = casualRacedPairing($anna, $bert);

    expect(casualRefusal(fn () => casualRacedPairing($bert, $anna)))->toBe('already_playing')
        ->and(casualRefusal(fn () => casualRacedPairing($carl, $anna)))->toBe('already_playing')
        ->and(SeriesMatch::query()->pluck('id')->all())->toBe([$first->id])
        ->and(DB::table('casual_claims')->orderBy('user_id')->pluck('series_match_id', 'user_id')->all())->toBe([$anna->id => $first->id, $bert->id => $first->id]);
});

test('a queue pairing and an invite accept for the same player: whichever commits first wins', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create(['looking_to_play' => 'rocket-league/1v1']);
    $queue = app(CasualQueue::class);
    $invites = app(CasualInvites::class);

    // Paired by the queue first: the invite accept is refused.
    $invite = $invites->invite($carl, $anna, 'rocket-league', Platform::Pc, true);
    $queue->join($anna, 'rocket-league', Platform::Pc);
    $paired = $queue->join($bert, 'rocket-league', Platform::Pc);

    expect(casualRefusal(fn () => $invites->accept($invite, $anna, Platform::Pc, true)))->toBe('accept_while_playing')
        ->and(casualRefusal(fn () => casualRacedPairing($carl, $anna)))->toBe('already_playing')
        ->and(SeriesMatch::query()->pluck('id')->all())->toBe([$paired?->id]);

    // The invite accepted first: a pairing that read the queue before it committed is refused.
    [$dora, $emil, $finn] = User::factory()->count(3)->create(['looking_to_play' => 'rocket-league/1v1']);
    $accepted = $invites->accept($invites->invite($finn, $dora, 'rocket-league', Platform::Pc, true), $dora, Platform::Pc, true);

    expect(casualRefusal(fn () => casualRacedPairing($emil, $dora, SeriesMatch::ORIGIN_QUEUE)))->toBe('already_playing')
        ->and(SeriesMatch::query()->whereIn('id', [$paired?->id, $accepted->id])->count())->toBe(2)
        ->and(SeriesMatch::query()->count())->toBe(2);
});

test('a queue pairing that loses a player between its busy check and its match rolls back whole and waits for the next poll', function () {
    [$anna, $bert, $carl, $dora] = User::factory()->count(4)->create();
    $queue = app(CasualQueue::class);
    $queue->join($anna, 'rocket-league', Platform::Pc);

    // Bert's busy check has passed; before his match is written, Anna is paired elsewhere.
    $rival = false;
    MatchNumber::created(function () use (&$rival, $anna, $carl) {
        if ($rival === false) {
            $rival = true;
            casualRacedPairing($carl, $anna);
        }
    });

    expect($queue->join($bert, 'rocket-league', Platform::Pc))->toBeNull()
        ->and($rival)->toBeTrue()
        ->and(SeriesMatch::query()->count())->toBe(0)
        ->and(DB::table('casual_claims')->count())->toBe(0)
        ->and($queue->entryOf($anna))->not->toBeNull()
        ->and($queue->entryOf($bert))->not->toBeNull();

    // The next poll pairs them.
    expect($queue->pair($bert)?->sides)->toBe(['challenger' => [$anna->id], 'challenged' => [$bert->id]])
        ->and(casualRefusal(fn () => casualRacedPairing($dora, $bert)))->toBe('already_playing');
});

test('a claim holds while its match runs and is taken over once it ended, whatever ended it', function (SeriesStatus $status, bool $running) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = casualRacedPairing($anna, $bert);
    $match->forceFill(['status' => $status])->save();

    $again = casualRefusal(fn () => casualRacedPairing($bert, $anna));

    expect($again)->toBe($running ? 'already_playing' : null)
        ->and(SeriesMatch::query()->count())->toBe($running ? 1 : 2)
        ->and(DB::table('casual_claims')->count())->toBe(2);
})->with(fn () => array_map(fn (SeriesStatus $status) => [$status, in_array($status, [SeriesStatus::Accepted, SeriesStatus::Reported], true)], SeriesStatus::cases()));

test('every path that ends a casual match frees both players for the next one', function (Closure $end) {
    [$match, $anna, $bert] = casualPairing();

    $end($match->refresh(), $this);

    expect($match->refresh()->status->isFinal() || $match->status === SeriesStatus::Disputed)->toBeTrue()
        ->and(casualRefusal(fn () => casualRacedPairing($anna, $bert)))->toBeNull();
})->with([
    'ready check ran out (void)' => function (SeriesMatch $match, $test) {
        $test->travel(61)->seconds();
        app(CasualScheduler::class)->tick();
    },
    'uncontested no-show (forfeit)' => function (SeriesMatch $match, $test) {
        $matches = app(CasualMatches::class);
        [$anna, $bert] = [User::query()->findOrFail($match->rosterSide('challenger')[0]), User::query()->findOrFail($match->rosterSide('challenged')[0])];
        $matches->ready($match, $anna);
        $match = $matches->ready($match, $bert);
        $guest = $match->host_side === 'challenger' ? $bert : $anna;
        $test->travel(5)->minutes();
        $matches->claimNoShow($match, $guest);
        $test->travel(5)->minutes();
        app(CasualScheduler::class)->tick();
    },
    'nobody reported (void)' => function (SeriesMatch $match, $test) {
        $matches = app(CasualMatches::class);
        $matches->ready($match, User::query()->findOrFail($match->rosterSide('challenger')[0]));
        $matches->ready($match, User::query()->findOrFail($match->rosterSide('challenged')[0]));
        $test->travel(60)->minutes();
        app(CasualScheduler::class)->tick();
    },
    'report confirmed by the opponent' => function (SeriesMatch $match) {
        [$anna, $bert] = casualStartedPlayers($match);
        $series = app(SeriesService::class);
        $series->saveLiveGame($match, $anna, 0, 3, 1, null);
        $series->report($match, $anna, []);
        $series->respond($match->refresh(), $bert, 'confirmed', '', []);
    },
    'report confirmed by the league' => function (SeriesMatch $match, $test) {
        [$anna] = casualStartedPlayers($match);
        $series = app(SeriesService::class);
        $series->saveLiveGame($match, $anna, 0, 3, 1, null);
        $series->report($match, $anna, []);
        $test->travel(30)->minutes();
        app(CasualScheduler::class)->tick();
    },
    'report disputed (waits for an admin, blocks nothing)' => function (SeriesMatch $match) {
        [$anna, $bert] = casualStartedPlayers($match);
        $series = app(SeriesService::class);
        $series->saveLiveGame($match, $anna, 0, 3, 1, null);
        $series->report($match, $anna, []);
        $series->respond($match->refresh(), $bert, 'disputed', 'The score was 1:2.', []);
    },
    'forced to a result' => function (SeriesMatch $match) {
        $match->forceFill(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Forfeit, 'winner' => 'challenger', 'finished_at' => now()])->save();
    },
]);

/**
 * Both players ready, so the match started: [challenger, challenged].
 *
 * @return array{0: User, 1: User}
 */
function casualStartedPlayers(SeriesMatch $match): array
{
    $matches = app(CasualMatches::class);
    $players = [User::query()->findOrFail($match->rosterSide('challenger')[0]), User::query()->findOrFail($match->rosterSide('challenged')[0])];
    $matches->ready($match, $players[0]);
    $matches->ready($match, $players[1]);
    $match->refresh();

    return $players;
}
