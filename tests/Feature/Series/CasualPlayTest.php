<?php

use App\Enums\ChessInviteStatus;
use App\Enums\Platform;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\MatchNumber;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use App\Support\Dock\OpenMatches;
use App\Support\Series\CasualChallenges;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualLobby;
use App\Support\Series\CasualMatches;
use App\Support\Series\CasualQueue;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The visible casual 1v1 (P23 S3): the "Play 1v1 casual" module of the game
 * pages and /play (components/⚡casual-play), the ready prompt of every page
 * (components/⚡casual-ready) and the lobby reads behind them (CasualLobby).
 */

beforeEach(function () {
    $this->freezeTime();
    config(['esports.chat.relays' => ['wss://relay.example']]);
});

test('the Rocket League and both EA FC pages carry the module, the chess lobby and /play for guests stay honest', function () {
    foreach (['rocket-league' => '/games/rocket-league', 'ea-sports-fc-26' => '/games/ea-sports-fc-26', 'ea-sports-fc-27' => '/games/ea-sports-fc-27'] as $game => $url) {
        $this->get($url)->assertOk()->assertSee('data-test="casual-play"', false)->assertSee('data-game="'.$game.'"', false)->assertSee('Log in to play');
    }

    $this->get('/play')->assertOk()->assertSee('data-test="casual-games"', false);
    $this->get('/chess')->assertOk()->assertDontSee('data-test="casual-play"', false);
});

test('with no chat relay the module offers nothing and the server refuses the search too', function () {
    config(['esports.chat.relays' => []]);
    $anna = User::factory()->create();

    Livewire::actingAs($anna)->test('casual-play', ['game' => 'rocket-league'])
        ->assertSee('Casual 1v1 is off here')
        ->assertDontSee('data-test="casual-tiles"', false)
        ->call('find')
        ->assertSet('error', 'Casual 1v1 is off here: it needs the encrypted match chat.');

    expect(SeriesQueueEntry::query()->count())->toBe(0);
});

test('find opponent joins the queue with the remembered platform, shows the search, and cancel leaves', function () {
    $anna = User::factory()->create(['platform' => Platform::PlayStation]);

    $module = Livewire::actingAs($anna)->test('casual-play', ['game' => 'rocket-league'])
        ->assertSee('PlayStation')
        ->call('setPlatform', 'xbox')
        ->call('setCrossplay', false)
        ->call('find')
        ->assertDispatched('casual-changed')
        ->assertSee('data-test="casual-searching"', false)
        ->assertSee('You are searching');

    $entry = SeriesQueueEntry::query()->sole();
    expect($entry->platform)->toBe(Platform::Xbox)
        ->and($entry->crossplay)->toBeFalse()
        ->and($anna->refresh()->casual_settings)->toBe(['rocket-league' => ['platform' => 'xbox', 'crossplay' => false]])
        // Another game keeps its own choice.
        ->and(app(CasualLobby::class)->settings($anna, 'ea-sports-fc-26'))->toBe(['platform' => Platform::PlayStation, 'crossplay' => true]);

    // Changing the platform while searching moves the search along.
    $module->call('setPlatform', 'pc');
    expect(SeriesQueueEntry::query()->sole()->platform)->toBe(Platform::Pc);

    $module->call('cancel')->assertDontSee('data-test="casual-searching"', false);
    expect(SeriesQueueEntry::query()->count())->toBe(0);
});

test('a second player finding an opponent is paired, and both get the ready prompt', function () {
    [$anna, $bert] = User::factory()->count(2)->create();

    Livewire::actingAs($anna)->test('casual-play', ['game' => 'ea-sports-fc-26'])->call('find');
    Livewire::actingAs($bert)->test('casual-play', ['game' => 'ea-sports-fc-26'])->call('find');

    $match = SeriesMatch::query()->sole();
    expect($match->awaitsReady())->toBeTrue();

    foreach ([$anna, $bert] as $player) {
        Livewire::actingAs($player)->test('casual-ready')
            ->assertSee('data-test="ready-prompt"', false)
            ->assertSee('Match found')
            ->assertSee('data-polling="1"', false)
            ->assertSet('prompted', $match->number);
    }
});

test('ready from the prompt: the first waits, the second moves both into the room', function () {
    [$match, $anna, $bert] = casualPairing('rocket-league');

    Livewire::actingAs($anna)->test('casual-ready')
        ->call('ready')
        ->assertNoRedirect()
        ->assertSee('data-test="ready-done"', false)
        ->assertSee('data-ready="1"', false);

    $annaPrompt = Livewire::actingAs($anna)->test('casual-ready');
    // The same prompt state a second time: a push and a poll in flight at once carry the same snapshot.
    $annaEcho = Livewire::actingAs($anna)->test('casual-ready')->assertSet('prompted', $match->number);

    Livewire::actingAs($bert)->test('casual-ready')
        ->call('ready')
        ->assertRedirect(route('matches.room', $match));

    // The first one's prompt moves on at its next look.
    $this->actingAs($anna);
    $annaPrompt->call('check')->assertRedirect(route('matches.room', $match));
    // Once per match: a second look that was in flight with the first (push and poll) does not reload the room it opened.
    $annaEcho->call('check')->assertNoRedirect();
    expect($match->refresh()->start_at)->not->toBeNull();
});

test('a ready check that runs out says who missed it: the ready one searches again, first in line', function () {
    [$match, $anna, $bert] = casualPairing('rocket-league');
    app(CasualMatches::class)->ready($match, $anna);

    $this->travel(61)->seconds();

    Livewire::actingAs($anna)->test('casual-ready')
        ->call('check')
        ->assertSee('data-test="ready-missed"', false)
        ->assertSee(e($match->sideName(casualSideOf($match, $bert))).' did not press Ready in time. You are back in the queue, first in line.', false)
        ->assertSee('data-test="missed-stop"', false);

    expect($match->refresh()->resolution)->toBe(SeriesResolution::Void)
        ->and(SeriesQueueEntry::query()->where('user_id', $anna->id)->exists())->toBeTrue();

    Livewire::actingAs($bert)->test('casual-ready')
        ->assertSee('You did not press Ready in time, so the match is off and you left the queue.')
        ->call('dismiss')
        ->assertDontSee('data-test="ready-missed"', false);

    // Dismissed stays dismissed; "Stop searching" leaves the queue.
    Livewire::actingAs($bert)->test('casual-ready')->assertDontSee('data-test="ready-missed"', false);
    Livewire::actingAs($anna)->test('casual-ready')->call('dismiss', true);
    expect(SeriesQueueEntry::query()->where('user_id', $anna->id)->exists())->toBeFalse();
});

test('the ready prompt stays out of the room of its own match and polls only while something waits', function () {
    [$match, $anna] = casualPairing('rocket-league');
    $idle = User::factory()->create();

    Livewire::actingAs($idle)->test('casual-ready')->assertSee('data-polling="0"', false)->assertDontSee('data-test="ready-prompt"', false);

    $this->actingAs($anna)->get(route('matches.room', $match))->assertOk()
        ->assertSee('data-test="casual-ready-root"', false)
        ->assertDontSee('data-test="ready-prompt"', false)
        ->assertSee('data-test="casual-ready"', false);
});

test('the looking list shows players with the switch on for this game and online by their session', function () {
    [$anna, $bert, $cleo, $dora] = User::factory()->count(4)->create();
    $queue = app(CasualQueue::class);
    $queue->setLooking($bert, 'rocket-league');
    $queue->setLooking($cleo, 'rocket-league');
    $queue->setLooking($dora, 'ea-sports-fc-26');

    expect(app(CasualLobby::class)->lookingPlayers('rocket-league', $anna)->pluck('id')->all())->toBe([$bert->id, $cleo->id]);

    // With database sessions only a recent request counts as online.
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert([
        ['id' => 'b', 'user_id' => $bert->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->subMinutes(2)->getTimestamp()],
        ['id' => 'c', 'user_id' => $cleo->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->subMinutes(CasualLobby::ONLINE_MINUTES + 1)->getTimestamp()],
    ]);

    expect(app(CasualLobby::class)->lookingPlayers('rocket-league', $anna)->pluck('id')->all())->toBe([$bert->id]);
});

test('looking to play switches on for this game only, and off leaves another game alone', function () {
    $anna = User::factory()->create();
    $module = Livewire::actingAs($anna)->test('casual-play', ['game' => 'rocket-league']);

    expect($module->instance()->setLooking(true))->toBeTrue()
        ->and($anna->refresh()->looking_to_play)->toBe('rocket-league/1v1');

    $anna->forceFill(['looking_to_play' => 'ea-sports-fc-26/1v1'])->save();
    expect($module->instance()->setLooking(false))->toBeFalse()
        ->and($anna->refresh()->looking_to_play)->toBe('ea-sports-fc-26/1v1');
});

test('invite from the list, the invitee accepts with their own platform, and both get the ready prompt', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    app(CasualQueue::class)->setLooking($bert, 'rocket-league');
    app(CasualLobby::class)->remember($bert, 'rocket-league', Platform::Switch, true);

    Livewire::actingAs($anna)->test('casual-play', ['game' => 'rocket-league'])
        ->assertSee('data-test="casual-player"', false)
        ->call('invite', $bert->id)
        ->assertSee('data-test="casual-waiting"', false)
        ->assertSee('Waiting for '.e($bert->displayName()), false)
        ->assertSee('data-test="casual-invited"', false);

    $invite = SeriesInvite::query()->sole();

    Livewire::actingAs($bert)->test('casual-play', ['game' => 'rocket-league'])
        ->assertSee('data-test="casual-incoming"', false)
        ->assertSee(e($anna->displayName()).' invites you', false)
        ->call('accept', $invite->id)
        ->assertDispatched('casual-changed');

    $match = SeriesMatch::query()->sole();
    expect($match->origin)->toBe(SeriesMatch::ORIGIN_INVITE)
        ->and($match->casual['queue']['challenged'])->toBe(['platform' => 'switch', 'crossplay' => true]);

    Livewire::actingAs($anna)->test('casual-ready')->assertSee('data-test="ready-prompt"', false);
});

test('decline and withdraw close the invite, and a refusal is shown in the module', function () {
    [$anna, $bert] = User::factory()->count(2)->create();

    Livewire::actingAs($anna)->test('casual-play', ['game' => 'rocket-league'])
        ->call('invite', $bert->id)
        ->assertSee(e($bert->displayName()).' is not looking for a game right now.', false);

    app(CasualQueue::class)->setLooking($bert, 'rocket-league');
    Livewire::actingAs($anna)->test('casual-play', ['game' => 'rocket-league'])->call('invite', $bert->id)->call('withdraw');
    expect(SeriesInvite::query()->latest('id')->first()->status)->toBe(ChessInviteStatus::Withdrawn);

    Livewire::actingAs($anna)->test('casual-play', ['game' => 'rocket-league'])->call('invite', $bert->id);
    $invite = SeriesInvite::query()->latest('id')->first();
    Livewire::actingAs($bert)->test('casual-play', ['game' => 'rocket-league'])->call('decline', $invite->id)->assertDontSee('data-test="casual-incoming"', false);
    expect($invite->refresh()->status)->toBe(ChessInviteStatus::Declined);
});

test('a player locked out after two no-shows sees when they can play again, and the search is refused', function () {
    $anna = User::factory()->create(['timezone' => 'UTC']);
    casualNoShowLoss($anna, now()->subMinutes(20));
    $last = casualNoShowLoss($anna, now()->subMinutes(5));
    $until = $last->finished_at->copy()->addMinutes(30)->format('H:i');

    Livewire::actingAs($anna)->test('casual-play', ['game' => 'rocket-league'])
        ->assertSee('data-test="casual-locked"', false)
        ->assertSee('You can play again at '.$until.'.')
        ->call('find')
        ->assertSee('You can play casual 1v1 again at '.$until.'.');

    expect(SeriesQueueEntry::query()->count())->toBe(0);
});

test('a player with a running match is sent to its room instead of searching', function () {
    [$match, $host] = casualStarted('rocket-league');

    Livewire::actingAs($host)->test('casual-play', ['game' => 'rocket-league'])
        ->assertSee('data-test="casual-running"', false)
        ->assertSee(route('matches.room', $match), false);
});

test('/play lets the player pick the game, and incoming invites of every game show there', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    app(CasualQueue::class)->setLooking($anna, 'ea-sports-fc-27');
    app(CasualInvites::class)->invite($bert, $anna, 'ea-sports-fc-27', Platform::Pc, true);

    Livewire::actingAs($anna)->test('casual-play')
        ->assertSet('game', 'ea-sports-fc-27')
        ->assertSee('data-test="casual-incoming"', false)
        ->call('pickGame', 'rocket-league')
        ->assertSet('game', 'rocket-league')
        ->assertSee('data-test="casual-incoming"', false)
        ->call('pickGame', 'chess')
        ->assertSet('game', 'rocket-league');

    // On a game page only that game's invites.
    Livewire::actingAs($anna)->test('casual-play', ['game' => 'rocket-league'])->assertDontSee('data-test="casual-incoming"', false);
});

test('the module round trips answer 200', function () {
    $anna = User::factory()->create();

    Livewire::actingAs($anna)->test('casual-play', ['game' => 'rocket-league'])->call('$refresh')->assertOk();
    Livewire::actingAs($anna)->test('casual-ready')->call('check')->assertOk();
    Livewire::actingAs($anna)->test('casual-play')->call('$refresh')->assertOk();
});

test('the room of a finished casual match offers a rematch, which the opponent accepts into a new ready check', function () {
    [$match, $host, $guest] = casualStarted('rocket-league');
    $match->forceFill(['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'finished_at' => now()])->save();

    Livewire::actingAs($host)->test('pages::matches.room', ['match' => $match])
        ->assertSee('data-test="casual-rematch"', false)
        ->call('casualRematch')
        ->assertSee('data-test="casual-rematch-waiting"', false);

    $invite = SeriesInvite::query()->sole();
    expect($invite->invitee_id)->toBe($guest->id)
        ->and($invite->expires_at->getTimestamp() - now()->getTimestamp())->toBe((int) config('esports.casual.rematch_seconds'));

    Livewire::actingAs($guest)->test('pages::matches.room', ['match' => $match])
        ->assertSee('data-test="casual-rematch-incoming"', false)
        ->call('casualAcceptRematch', $invite->id)
        ->assertDispatched('casual-changed');

    $rematch = SeriesMatch::query()->whereKeyNot($match->id)->sole();
    expect($rematch->awaitsReady())->toBeTrue()
        ->and($rematch->origin)->toBe(SeriesMatch::ORIGIN_INVITE);
});

test('a rematch is refused after a void match and once its time is over', function () {
    [$match, $host] = casualStarted('rocket-league');
    $match->forceFill(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Void, 'winner' => 'none', 'finished_at' => now()])->save();

    expect(casualRefusal(fn () => app(CasualInvites::class)->rematch($match, $host)))->toBe('rematch_closed');

    $match->forceFill(['status' => SeriesStatus::Confirmed, 'resolution' => null, 'winner' => 'challenger'])->save();
    $this->travel((int) config('esports.casual.rematch_minutes') + 1)->minutes();

    expect(casualRefusal(fn () => app(CasualInvites::class)->rematch($match, $host)))->toBe('rematch_closed');
});

/*
 * The match dock (OpenMatches): roster sides count, not only lineups.
 */

test('the dock lists a casual ready check, then the running match with its step, for both players', function () {
    [$match, $anna, $bert] = casualPairing('rocket-league');
    $dock = app(OpenMatches::class);

    $item = $dock->for($anna)->sole();
    expect($item->key)->toBe('series-'.$match->number)
        ->and($item->phase)->toBe('ready')
        ->and($item->needsYou)->toBeTrue()
        ->and($item->title)->toBe('Casual 1v1')
        ->and($item->face?->id)->toBe($bert->id)
        ->and($item->tick['endsAt'])->toBe((int) $match->ready_by->getTimestampMs());

    app(CasualMatches::class)->ready($match, $anna);
    expect($dock->for($anna)->sole()->needsYou)->toBeFalse()
        ->and($dock->for($bert)->sole()->needsYou)->toBeTrue();

    $match = app(CasualMatches::class)->ready($match, $bert);
    [$host, $guest] = $match->host_side === casualSideOf($match, $anna) ? [$anna, $bert] : [$bert, $anna];

    $hostItem = $dock->for($host)->sole();
    $guestItem = $dock->for($guest)->sole();
    expect([$hostItem->group, $hostItem->state, $hostItem->needsYou])->toBe(['live', 'Share the lobby', true])
        ->and([$guestItem->state, $guestItem->needsYou])->toBe(['Lobby coming', false])
        // The room of the match itself leaves it out.
        ->and($dock->for($host, excludeSeries: $match->number))->toBeEmpty();
});

test('the dock lists a tournament Rocket League 1v1 of roster sides, which it used to miss', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    // A tournament's 1v1 as SeriesService builds it: player sides, no lineups, no origin.
    $match = SeriesMatch::factory()->create([
        'challenger_lineup_id' => null, 'challenged_lineup_id' => null,
        'number' => MatchNumber::query()->create(['user_id' => $anna->id, 'used_at' => now()])->id,
        'created_by_id' => null, 'game' => 'rocket-league', 'mode' => '1v1',
        'challenger_name' => $anna->displayName(), 'challenged_name' => $bert->displayName(), 'challenger_tag' => 'ANNA', 'challenged_tag' => 'BERT',
        'challenger_lineup_address' => '', 'challenged_lineup_address' => '',
        'sides' => ['challenger' => [$anna->id], 'challenged' => [$bert->id]],
        'status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(5),
    ]);

    expect(app(OpenMatches::class)->for($anna)->map->key->all())->toBe(['series-'.$match->number])
        ->and(app(OpenMatches::class)->for($bert)->sole()->group)->toBe('live');
});

test('a casual invite received is on the dock, leading to the module of its game', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    app(CasualQueue::class)->setLooking($bert, 'ea-sports-fc-26');
    $invite = app(CasualInvites::class)->invite($anna, $bert, 'ea-sports-fc-26', Platform::Pc, true);

    $item = app(OpenMatches::class)->for($bert)->sole();
    expect($item->key)->toBe('casual-invite-'.$invite->id)
        ->and($item->kind)->toBe('casual_invite')
        ->and($item->href)->toBe(route('games.series', ['slug' => 'ea-sports-fc-26']).'#casual')
        ->and($item->gameMark())->toBe('FC')
        ->and(app(OpenMatches::class)->for($anna))->toBeEmpty();

    Livewire::actingAs($bert)->test('match-dock')->assertSee(e($anna->displayName()), false)->call('$refresh')->assertOk();
});

test('a casual 1v1 room shows no Elo facts, since it is never rated; a clan series room keeps them', function () {
    [$match, $host] = casualStarted('rocket-league');

    $this->actingAs($host)->get(route('matches.room', $match))->assertOk()
        ->assertDontSee('data-test="elo-fact"', false)->assertDontSee('At stake')->assertDontSee('Elo before');

    $series = SeriesMatch::factory()->create(['status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(5)]);
    $captain = $series->challengerLineup->clan->owner;

    $this->actingAs($captain)->get(route('matches.room', $series))->assertOk()
        ->assertSee('data-test="elo-fact"', false)->assertSee('At stake')->assertSee('Elo before');
});

/*
 * Scheduled 1v1 (P23 S4) in the same screens: the room's timeline, the dock, the links to the form.
 */

/**
 * A scheduled Rocket League 1v1 from Anna to Bert, accepted, the clock `$before` minutes before its start.
 *
 * @return array{0: SeriesMatch, 1: User, 2: User}
 */
function casualPlayScheduled(int $before): array
{
    [$anna, $bert] = User::factory()->count(2)->create();
    $at = now()->addDay()->setTime(20, 0)->getTimestamp();
    $match = app(CasualChallenges::class)->challenge($anna, $bert, 'rocket-league', Platform::Pc, true, [$at], now()->addDay()->setTime(12, 0)->getTimestamp(), '');
    $match = app(CasualChallenges::class)->accept($match, $bert, $at, Platform::Pc, true);
    test()->travelTo(now()->setTimestamp($at)->subMinutes($before));

    return [$match->refresh(), $anna, $bert];
}

test('a scheduled match: step 1 is "Checked in", the clock counts to the opening and then to the close, and Check in is the primary action inside the window', function () {
    [$match, $anna, $bert] = casualPlayScheduled(before: 30);

    Livewire::actingAs($anna)->test('pages::matches.room', ['match' => $match])
        ->assertSee('Checked in')->assertSee('0 of 2 checked in')
        ->assertSee('data-kind="checkin"', false)
        ->assertSee('The check-in opens at')
        ->assertSee('casualClock('.$match->checkInOpensAt()->getTimestamp().')', false)
        ->assertDontSee('data-test="casual-checkin"', false)
        ->assertDontSee('data-test="casual-share"', false);

    $this->travel(21)->minutes();

    $room = Livewire::actingAs($anna)->test('pages::matches.room', ['match' => $match])
        ->assertSee('Both check in by')
        ->assertSee('casualClock('.$match->ready_by->getTimestamp().')', false);

    // The big primary button of the step, as Ready.
    expect(preg_match('/<button[^>]*class="[^"]*min-h-14[^"]*"[^>]*data-test="casual-checkin"/', $room->html()))->toBe(1);
    $room->call('casualCheckIn')->assertSee('1 of 2 checked in');

    Livewire::actingAs($bert)->test('pages::matches.room', ['match' => $match])->call('casualCheckIn')
        ->assertSee('data-kind="lobby"', false)
        ->assertSee('data-test="casual-step-ready" data-done="1"', false);
});

test('the dock shows a scheduled match as starting, then as check-in on the player until they are in', function () {
    [$match, $anna] = casualPlayScheduled(before: 30);
    $dock = app(OpenMatches::class);

    $item = $dock->for($anna)->sole();
    expect([$item->phase, $item->state, $item->needsYou, $item->tick])->toBe(['ready', 'Starts', false, null]);

    $this->travel(21)->minutes();
    $item = $dock->for($anna)->sole();
    expect([$item->state, $item->needsYou, $item->tick['endsAt']])->toBe(['Check in', true, (int) $match->ready_by->getTimestampMs()]);

    app(CasualMatches::class)->checkIn($match, $anna);
    expect($dock->for($anna)->sole()->needsYou)->toBeFalse();
});

test('"Schedule a 1v1" leads to the challenge form from the module, each looking player, and a player page', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    app(CasualQueue::class)->setLooking($bert, 'ea-sports-fc-26');

    Livewire::actingAs($anna)->test('casual-play', ['game' => 'ea-sports-fc-26'])
        ->assertSee(e(route('challenges.casual', ['game' => 'ea-sports-fc-26'])), false)
        ->assertSee(e(route('challenges.casual', ['to' => $bert->id, 'game' => 'ea-sports-fc-26'])), false);

    $this->actingAs($anna)->get(route('players.show', $bert->npub))->assertOk()
        ->assertSee('data-test="schedule-1v1"', false)
        ->assertSee(e(route('challenges.casual', ['to' => $bert->id, 'game' => 'ea-sports-fc-26'])), false);

    // The link opens the form with the player filled in.
    $this->actingAs($anna)->get(route('challenges.casual', ['to' => $bert->id, 'game' => 'ea-sports-fc-26']))->assertOk()->assertSee(e($bert->displayName()), false);
    // Not on one's own page.
    $this->actingAs($bert)->get(route('players.show', $bert->npub))->assertOk()->assertDontSee('data-test="schedule-1v1"', false);

    // A guest never learns what a player looks for: the public profile's link carries no game.
    auth()->logout();
    $this->get(route('players.show', $bert->npub))->assertOk()
        ->assertSee(e(route('challenges.casual', ['to' => $bert->id])), false)
        ->assertDontSee(e(route('challenges.casual', ['to' => $bert->id, 'game' => 'ea-sports-fc-26'])), false);
});
