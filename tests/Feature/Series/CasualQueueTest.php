<?php

use App\Enums\ChessInviteStatus;
use App\Enums\Platform;
use App\Enums\SeriesStatus;
use App\Events\ChessGameStarted;
use App\Events\SeriesMatchChanged;
use App\Events\UserNotified;
use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualQueue;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
 * The casual 1v1 queue (P23, slice S1): who pairs with whom, one intent at
 * a time, the no-show lock and "Looking to play".
 */

beforeEach(fn () => $this->freezeTime());

test('two searching players are paired into an unrated 1v1 of player sides in the ready check', function () {
    Event::fake([SeriesMatchChanged::class, UserNotified::class]);
    [$anna, $bert] = User::factory()->count(2)->create();
    $queue = app(CasualQueue::class);

    expect($queue->join($anna, 'ea-sports-fc-26', Platform::PlayStation))->toBeNull();

    $match = $queue->join($bert, 'ea-sports-fc-26', Platform::PlayStation);

    expect($match)->toBeInstanceOf(SeriesMatch::class)
        ->and($match->origin)->toBe('queue')
        ->and($match->rated)->toBeFalse()
        ->and($match->status)->toBe(SeriesStatus::Accepted)
        ->and($match->start_at)->toBeNull()
        ->and($match->ready_by->getTimestamp())->toBe(now()->addSeconds(60)->getTimestamp())
        ->and($match->sides)->toBe(['challenger' => [$anna->id], 'challenged' => [$bert->id]])
        ->and([$match->challenger_lineup_id, $match->challenged_lineup_id])->toBe([null, null])
        ->and($match->host_side)->toBeIn(['challenger', 'challenged'])
        ->and($match->best_of)->toBe(1)
        ->and(SeriesQueueEntry::query()->count())->toBe(0)
        // A player who waited finds the match on the next poll.
        ->and($queue->pair($anna)?->id)->toBe($match->id);

    expect(casualAlerts())->toEqualCanonicalizing([[$anna->id, 'casual_match_found'], [$bert->id, 'casual_match_found']]);
    Event::assertDispatched(SeriesMatchChanged::class, fn (SeriesMatchChanged $event) => $event->number === $match->number
        && $event->userIds === [$anna->id, $bert->id]);
});

test('players pair on the same platform, or across platforms only when both allow crossplay', function (string $game, Platform $a, bool $crossA, Platform $b, bool $crossB, bool $pairs) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $queue = app(CasualQueue::class);

    $queue->join($anna, $game, $a, $crossA);

    expect($queue->join($bert, $game, $b, $crossB) !== null)->toBe($pairs);
})->with([
    'same platform, crossplay off' => ['rocket-league', Platform::Xbox, false, Platform::Xbox, false, true],
    'both allow crossplay' => ['rocket-league', Platform::Pc, true, Platform::Switch, true, true],
    'one refuses crossplay' => ['rocket-league', Platform::Pc, true, Platform::PlayStation, false, false],
    'FC on Switch never plays cross-platform' => ['ea-sports-fc-27', Platform::Switch, true, Platform::Pc, true, false],
    'FC on Switch against Switch' => ['ea-sports-fc-27', Platform::Switch, false, Platform::Switch, false, true],
    'FC across consoles with crossplay' => ['ea-sports-fc-27', Platform::PlayStation, true, Platform::Xbox, true, true],
    'AoE2 on PC against Xbox with crossplay' => ['age-of-empires-2', Platform::Pc, true, Platform::Xbox, true, true],
]);

test('a player is paired with the longest waiting fitting opponent, never across games', function () {
    [$anna, $bert, $carl, $dora] = User::factory()->count(4)->create();
    $queue = app(CasualQueue::class);

    $queue->join($anna, 'rocket-league', Platform::Pc);
    $this->travel(5)->seconds();
    $queue->join($bert, 'ea-sports-fc-26', Platform::Pc);
    $this->travel(5)->seconds();
    $queue->join($carl, 'rocket-league', Platform::Pc, crossplay: false);

    expect(SeriesQueueEntry::query()->count())->toBe(1);

    $match = $queue->join($dora, 'ea-sports-fc-26', Platform::Pc);

    expect($match->sides)->toBe(['challenger' => [$bert->id], 'challenged' => [$dora->id]]);
});

test('joining withdraws the player\'s open invites and leaves the blitz queue', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create(['looking_to_play' => 'rocket-league/1v1']);
    $carl->forceFill(['looking_to_play' => 'chess/blitz'])->save();
    $casual = app(CasualInvites::class)->invite($anna, $bert, 'rocket-league', Platform::Pc, true);
    $blitz = app(ChessInvites::class)->invite($anna, $carl);
    ChessQueueEntry::query()->create(['user_id' => $anna->id, 'mode' => 'blitz', 'rated' => false, 'rating' => 1000, 'joined_at' => now()]);

    app(CasualQueue::class)->join($anna, 'rocket-league', Platform::Pc);

    expect($casual->refresh()->status)->toBe(ChessInviteStatus::Withdrawn)
        ->and($blitz->refresh()->status)->toBe(ChessInviteStatus::Withdrawn)
        ->and(ChessQueueEntry::query()->where('user_id', $anna->id)->exists())->toBeFalse()
        ->and(app(CasualQueue::class)->entryOf($anna))->not->toBeNull();
});

test('a live chess game or a running casual 1v1 refuses to join', function () {
    Event::fake([ChessGameStarted::class]);
    [$match, $anna] = casualPairing();
    [$carl, $dora] = User::factory()->count(2)->create();
    app(ChessGameService::class)->start($carl, $dora, 'blitz');
    $queue = app(CasualQueue::class);

    expect(casualRefusal(fn () => $queue->join($anna, 'rocket-league', Platform::Pc)))->toBe('already_playing')
        ->and(casualRefusal(fn () => $queue->join($carl, 'rocket-league', Platform::Pc)))->toBe('already_playing')
        ->and(casualRefusal(fn () => $queue->join($dora, 'chess', Platform::Pc)))->toBe('unknown_game')
        ->and(SeriesQueueEntry::query()->count())->toBe(0);
});

test('two forfeited no-shows within a day lock a player out of casual play for 30 minutes', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $queue = app(CasualQueue::class);
    casualNoShowLoss($anna, now()->subHours(25), $bert);
    casualNoShowLoss($anna, now()->subHours(3), $bert);

    // The first of the two is older than 24 h: one no-show counts, no lock.
    expect(casualRefusal(fn () => $queue->join($anna, 'rocket-league', Platform::Pc)))->toBeNull();
    $queue->leave($anna);

    casualNoShowLoss($anna, now()->subMinutes(10), $bert);

    expect(casualRefusal(fn () => $queue->join($anna, 'rocket-league', Platform::Pc)))->toBe('queue_locked')
        ->and(casualRefusal(fn () => app(CasualInvites::class)->invite($anna, $bert, 'rocket-league', Platform::Pc, true)))->toBe('queue_locked')
        // Bert won those matches by forfeit: the winner is not locked.
        ->and(casualRefusal(fn () => $queue->join($bert, 'rocket-league', Platform::Pc)))->toBeNull();

    $this->travel(20)->minutes();

    expect(casualRefusal(fn () => $queue->join($anna, 'rocket-league', Platform::Pc)))->toBeNull();
});

test('looking to play takes a casual 1v1 game and declines the invites that no longer fit', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $queue = app(CasualQueue::class);

    expect($queue->setLooking($bert, 'rocket-league'))->toBe('rocket-league/1v1')
        ->and(casualRefusal(fn () => $queue->setLooking($bert, 'chess')))->toBe('unknown_game');

    $invite = app(CasualInvites::class)->invite($anna, $bert, 'rocket-league', Platform::Pc, true);
    $queue->setLooking($bert, 'ea-sports-fc-26');

    expect($invite->refresh()->status)->toBe(ChessInviteStatus::Declined)
        ->and($bert->refresh()->looking_to_play)->toBe('ea-sports-fc-26/1v1');
});

test('the blitz switch turned off leaves a casual 1v1 choice alone and shows off for it', function () {
    $bert = User::factory()->create(['looking_to_play' => 'rocket-league/1v1']);

    $html = $this->actingAs($bert)->get(route('chess.lobby'))->assertOk()->getContent();
    expect($html)->toContain('data-looking="false"');

    Livewire::actingAs($bert)->test('pages::chess.lobby')->call('setLookingToPlay', false)->assertReturned(false);
    expect($bert->refresh()->looking_to_play)->toBe('rocket-league/1v1');

    Livewire::actingAs($bert)->test('pages::chess.lobby')->call('setLookingToPlay', true)->assertReturned(true);
    expect($bert->refresh()->looking_to_play)->toBe('chess/blitz');
});

test('an invite that was accepted is not withdrawn by a later join', function () {
    [$anna, $bert] = User::factory()->count(2)->create(['looking_to_play' => 'rocket-league/1v1']);
    $invites = app(CasualInvites::class);
    $invite = $invites->invite($anna, $bert, 'rocket-league', Platform::Pc, true);
    $invites->accept($invite, $bert, Platform::Pc, true);

    expect(casualRefusal(fn () => app(CasualQueue::class)->join($anna, 'rocket-league', Platform::Pc)))->toBe('already_playing')
        ->and(SeriesInvite::query()->sole()->status)->toBe(ChessInviteStatus::Accepted);
});

test('searching or inviting for blitz ends a casual search and withdraws the casual invite sent', function () {
    [$anna, $bert, $carl, $dora, $eve, $finn] = User::factory()->count(6)->create(['looking_to_play' => 'rocket-league/1v1']);
    $eve->forceFill(['looking_to_play' => 'chess/blitz'])->save();
    $casualInvites = app(CasualInvites::class);
    $queue = app(CasualQueue::class);

    // The blitz queue: one player searching casual, one with a casual invite out.
    $queue->join($anna, 'ea-sports-fc-26', Platform::Pc);
    app(ChessQueue::class)->join($anna);
    $fromFinn = $casualInvites->invite($finn, $carl, 'rocket-league', Platform::Pc, true);
    app(ChessQueue::class)->leave($anna);
    app(ChessQueue::class)->join($finn);

    // A blitz invite: the same two cases.
    $fromBert = $casualInvites->invite($bert, $carl, 'rocket-league', Platform::Pc, true);
    app(ChessInvites::class)->invite($bert, $eve);
    $queue->join($dora, 'ea-sports-fc-27', Platform::Pc);
    app(ChessInvites::class)->invite($dora, $eve);

    expect(SeriesQueueEntry::query()->count())->toBe(0)
        ->and($fromFinn->refresh()->status)->toBe(ChessInviteStatus::Withdrawn)
        ->and($fromBert->refresh()->status)->toBe(ChessInviteStatus::Withdrawn);
});

test('a live chess game that starts ends both players\' casual search and withdraws their casual invites, sent and received', function () {
    Event::fake([ChessGameStarted::class]);
    [$anna, $bert, $carl, $dora] = User::factory()->count(4)->create(['looking_to_play' => 'rocket-league/1v1']);
    $casualInvites = app(CasualInvites::class);
    $sent = $casualInvites->invite($anna, $carl, 'rocket-league', Platform::Pc, true);
    $received = $casualInvites->invite($dora, $anna, 'rocket-league', Platform::Pc, true);
    $other = $casualInvites->invite($carl, $dora, 'rocket-league', Platform::Pc, true);
    app(CasualQueue::class)->join($bert, 'ea-sports-fc-26', Platform::Pc);
    $bert->forceFill(['looking_to_play' => 'chess/blitz'])->save();

    // Anna invites Bert to blitz and he accepts: a live game starts.
    $chessInvites = app(ChessInvites::class);
    $chessInvites->accept($chessInvites->invite($anna, $bert), $bert);

    expect(SeriesQueueEntry::query()->count())->toBe(0)
        ->and($sent->refresh()->status)->toBe(ChessInviteStatus::Withdrawn)
        ->and($received->refresh()->status)->toBe(ChessInviteStatus::Withdrawn)
        // An invite between two other players stays open.
        ->and($other->refresh()->status)->toBe(ChessInviteStatus::Pending);
});

test('a daily chess game leaves the casual queue and invites alone', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create(['looking_to_play' => 'rocket-league/1v1']);
    $sent = app(CasualInvites::class)->invite($anna, $carl, 'rocket-league', Platform::Pc, true);
    app(CasualQueue::class)->join($bert, 'ea-sports-fc-26', Platform::Pc);

    app(ChessGameService::class)->start($anna, $bert, 'correspondence');

    expect(SeriesQueueEntry::query()->count())->toBe(1)
        ->and($sent->refresh()->status)->toBe(ChessInviteStatus::Pending);
});

test('a player in a running casual 1v1 starts no live chess game', function () {
    Event::fake([ChessGameStarted::class]);
    [$match, $host, $guest] = casualStarted();
    $other = User::factory()->create(['looking_to_play' => 'chess/blitz']);
    $host->forceFill(['looking_to_play' => 'chess/blitz'])->save();

    // The reviewer's repro: someone waits in the blitz queue, the casual player joins it.
    app(ChessQueue::class)->join($other);
    $chessInvites = app(ChessInvites::class);

    expect(casualChessRefusal(fn () => app(ChessQueue::class)->join($host)))->toBe('casual_playing')
        ->and(casualChessRefusal(fn () => $chessInvites->invite($guest, $other)))->toBe('casual_playing')
        ->and(casualChessRefusal(fn () => $chessInvites->accept($chessInvites->invite($other, $host), $host)))->toBe('casual_playing')
        ->and(casualChessRefusal(fn () => app(ChessGameService::class)->start($other, $guest, 'blitz')))->toBe('casual_playing')
        ->and(ChessGame::query()->count())->toBe(0);

    // A daily game is no live game: it may start.
    expect(app(ChessGameService::class)->start($other, $guest, 'correspondence'))->toBeInstanceOf(ChessGame::class);
});

test('a casual pairing takes both players out of the blitz queue, and a blitz search skips a player in a casual 1v1', function () {
    Event::fake([ChessGameStarted::class]);
    [$anna, $bert] = User::factory()->count(2)->create(['looking_to_play' => 'rocket-league/1v1']);
    ChessQueueEntry::query()->create(['user_id' => $anna->id, 'mode' => 'blitz', 'rated' => false, 'rating' => 1000, 'joined_at' => now()]);

    $invites = app(CasualInvites::class);
    $invites->accept($invites->invite($bert, $anna, 'rocket-league', Platform::Pc, true), $anna, Platform::Pc, true);

    expect(ChessQueueEntry::query()->where('user_id', $anna->id)->exists())->toBeFalse();

    // A stale blitz entry of a casual player (written around the pairing) is dropped, never paired.
    ChessQueueEntry::query()->create(['user_id' => $anna->id, 'mode' => 'blitz', 'rated' => false, 'rating' => 1000, 'joined_at' => now()->subMinute()]);

    expect(app(ChessQueue::class)->join(User::factory()->create()))->toBeNull()
        ->and(ChessQueueEntry::query()->where('user_id', $anna->id)->exists())->toBeFalse()
        ->and(ChessGame::query()->count())->toBe(0);
});
