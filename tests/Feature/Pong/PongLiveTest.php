<?php

/*
|--------------------------------------------------------------------------
| Live Proof of Pong (plan "Proof of Pong", P2)
|--------------------------------------------------------------------------
|
| Two players invite and accept, both pages open the match, the defending page reports every contact and the server's
| referee decides: a fake hit (too fast or beside the ball) is a goal for the attacker, nobody can report a goal for
| themselves, a report sent twice counts once. A player gone for 30 s loses, one back in time plays on. A finished
| match moves both players' Elo and shows once in the mempool and on /matches, with its winner alone.
|
*/

use App\Enums\BoardInviteStatus;
use App\Enums\PongEndReason;
use App\Enums\PongMatchStatus;
use App\Events\PongMatchStarted;
use App\Models\PongInvite;
use App\Models\PongMatch;
use App\Models\PongRating;
use App\Models\User;
use App\Support\Matches\MempoolStrip;
use App\Support\Pong\PongGame;
use App\Support\Pong\PongInvites;
use App\Support\Pong\PongMatches;
use App\Support\Pong\PongPhysics;
use App\Support\Pong\PongReferee;
use App\Support\Pong\PongRules;
use App\Support\Pong\PongRuleViolation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\PongLive;
use Tests\Support\PongOn;

beforeEach(function () {
    $this->withoutVite();
    $this->freezeTime();
});

/** The first contact of the match's rally in play: [defender side, ball, contact]. */
function pongContact(PongMatch $match): array
{
    $match->refresh();
    $referee = PongReferee::fromArray($match->seed, PongRules::fromConfig((array) config('esports.pong')), $match->state['ref']);

    foreach (array_keys($referee->balls) as $index) {
        $contact = $referee->contact($index);

        if ($contact !== null) {
            return [$contact['side'], $index, $contact];
        }
    }

    throw new RuntimeException('No contact ahead.');
}

test('switched off, no live route exists', function () {
    expect(Route::has('pong.match'))->toBeFalse()
        ->and(Route::has('pong.report'))->toBeFalse()
        ->and(Route::has('pong.sync'))->toBeFalse()
        ->and(Route::has('pong.accept'))->toBeFalse();

    $match = PongMatch::factory()->create();
    $this->actingAs($match->left)->get('/proof-of-pong/m/'.$match->ulid)->assertNotFound();
    // Only the site's GET fallback answers there: a report is refused (405), nothing reaches a referee.
    expect($this->actingAs($match->left)->postJson('/proof-of-pong/m/'.$match->ulid.'/report', [])->status())->toBeIn([404, 405]);
});

test('an invite to a player who is looking, accepted, opens one match for both: the invitee\'s tab and the inviter\'s by push', function () {
    PongOn::play();
    Event::fake([PongMatchStarted::class]);
    $inviter = User::factory()->create();
    $invitee = User::factory()->create(['looking_to_play' => PongInvites::LOOKING]);
    $bystander = User::factory()->create();

    Livewire::actingAs($inviter)->test('pong-lobby')->call('invite', $bystander->id)->assertSet('error', __(':name is not looking for a game right now.', ['name' => $bystander->displayName()]));
    Livewire::actingAs($inviter)->test('pong-lobby')->call('invite', $invitee->id)->assertSet('error', '')->assertSet('invitedUserId', $invitee->id);

    $invite = PongInvite::query()->sole();
    expect($invite->status)->toBe(BoardInviteStatus::Pending);

    // Only the invitee accepts; the answer is the match page, in the tab the form opened.
    $this->actingAs($inviter)->post(route('pong.accept', $invite))->assertRedirect(route('pong.index'));
    $response = $this->actingAs($invitee)->post(route('pong.accept', $invite));
    $match = PongMatch::query()->sole();
    $response->assertRedirect(route('pong.match', $match));

    expect($match->status)->toBe(PongMatchStatus::Waiting)
        ->and([$match->left_id, $match->right_id])->toEqualCanonicalizing([$inviter->id, $invitee->id])
        ->and($match->rated)->toBeTrue()
        ->and($invite->refresh()->status)->toBe(BoardInviteStatus::Accepted)
        ->and($invite->pong_match_id)->toBe($match->id);
    Event::assertDispatched(PongMatchStarted::class, fn (PongMatchStarted $event): bool => $event->userIds === [$inviter->id] && $event->url === route('pong.match', $match));

    // One live game at a time: a player in a match neither invites nor is invited into a second.
    expect(fn () => app(PongInvites::class)->invite($inviter, User::factory()->create(['looking_to_play' => PongInvites::LOOKING])))
        ->toThrow(PongRuleViolation::class, 'already_playing');

    // Both pages open it: the match starts with its first serve a moment later.
    $this->actingAs($inviter)->get(route('pong.match', $match))->assertOk()->assertSee('data-test="pong-live"', false)->assertSee('pong.'.$match->ulid === '' ? '' : $match->ulid);
    $this->actingAs($inviter)->postJson(route('pong.sync', $match))->assertOk()->assertJsonPath('status', 'waiting');
    $this->actingAs($invitee)->postJson(route('pong.sync', $match))->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('ref.rally', 1);
    expect($match->refresh()->started_at)->not->toBeNull();
});

test('the lobby component renders and survives a roundtrip, with and without a running match', function () {
    PongOn::play();
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pong-lobby')->assertOk()->assertSee(__('Looking to play'))->call('$refresh')->assertOk()
        ->call('setLookingToPlay', true)->assertOk();
    expect($user->refresh()->looking_to_play)->toBe(PongInvites::LOOKING);

    [$match, $left] = PongLive::started();
    Livewire::actingAs($left)->test('pong-lobby')->assertOk()->assertSee('data-test="pong-active-match"', false)->assertSee(route('pong.match', $match), false)
        ->call('$refresh')->assertOk()->call('poll')->assertOk();

    $this->get(route('pong.index'))->assertOk()->assertSee('data-test="pong-lobby"', false);
});

test('a fake hit is a goal for the attacker: a paddle beside the ball (geometry) and one that jumped faster than a paddle moves (speed)', function () {
    PongOn::play();
    [$match, $left, $right] = PongLive::started();
    $players = [$left, $right];

    // Geometry: on the field and reachable, but more than half a paddle plus the ball's radius from the ball.
    [$side, $ball, $contact] = pongContact($match);
    $ballY = $contact['ball'][1];
    $beside = $ballY > PongPhysics::HEIGHT >> 1 ? $ballY - PongPhysics::PADDLE_HALF - PongPhysics::BALL_RADIUS - 1 : $ballY + PongPhysics::PADDLE_HALF + PongPhysics::BALL_RADIUS + 1;

    $this->actingAs($players[$side])->postJson(route('pong.report', $match), ['rally' => 1, 'ball' => $ball, 'tick' => $contact['tick'], 'kind' => 'hit', 'y' => $beside])
        ->assertOk()->assertJsonPath('result', 'miss');
    $match->refresh();
    expect($match->score()[1 - $side])->toBe(1)
        ->and($match->score()[$side])->toBe(0)
        ->and(collect($match->log)->last(fn (array $line): bool => $line[0] === 'goal'))->toBe(['goal', 1, $contact['tick'], $side, $ball, $beside, 'geometry']);

    // Speed: the side's last accepted paddle a few ticks before the contact (two balls at one face, Pizza Day), the
    // claim beside it by one unit more than those ticks at top speed allow; the claim itself meets the ball.
    [$side, $ball, $contact] = pongContact($match);
    $y = max(PongPhysics::PADDLE_HALF, min(PongPhysics::HEIGHT - PongPhysics::PADDLE_HALF, $contact['ball'][1]));
    $jump = PongPhysics::PLAYER_SPEED * 4 + 1;
    $state = $match->state;
    $state['ref']['paddles'][$side] = [$y > PongPhysics::HEIGHT >> 1 ? $y - $jump : $y + $jump, $contact['tick'] - 4];
    $match->forceFill(['state' => $state])->save();
    $before = $match->score();

    $this->actingAs($players[$side])->postJson(route('pong.report', $match), ['rally' => 2, 'ball' => $ball, 'tick' => $contact['tick'], 'kind' => 'hit', 'y' => $y])
        ->assertOk()->assertJsonPath('result', 'miss');
    $match->refresh();
    expect($match->score()[1 - $side])->toBe($before[1 - $side] + 1)
        ->and($match->score()[$side])->toBe($before[$side])
        ->and(collect($match->log)->last(fn (array $line): bool => $line[0] === 'goal')[6])->toBe('speed');
});

test('nobody can report a goal for themselves, a duplicate counts once, and only the two players reach the referee', function () {
    PongOn::play();
    [$match, $left, $right] = PongLive::started();
    $players = [$left, $right];
    [$side, $ball, $contact] = pongContact($match);
    $attacker = $players[1 - $side];
    $report = ['rally' => 1, 'ball' => $ball, 'tick' => $contact['tick'], 'kind' => 'goal'];
    $state = $match->refresh()->state;

    // The attacker says the defender missed: refused, nothing changes.
    $this->actingAs($attacker)->postJson(route('pong.report', $match), $report)->assertOk()->assertJsonPath('result', 'not_defender');
    expect($match->refresh()->score())->toBe([0, 0])
        ->and($match->state['ref'])->toBe($state['ref']);

    // The defender's hit, sent twice: the second answer is the first decision, nothing moves again.
    $y = max(PongPhysics::PADDLE_HALF, min(PongPhysics::HEIGHT - PongPhysics::PADDLE_HALF, $contact['ball'][1]));
    $hit = [...$report, 'kind' => 'hit', 'y' => $y];
    $this->actingAs($players[$side])->postJson(route('pong.report', $match), $hit)->assertOk()->assertJsonPath('result', 'hit');
    $after = $match->refresh()->state['ref'];
    $this->actingAs($players[$side])->postJson(route('pong.report', $match), $hit)->assertOk()->assertJsonPath('result', 'duplicate');
    $this->actingAs($players[$side])->postJson(route('pong.report', $match), $report)->assertOk()->assertJsonPath('result', 'duplicate');
    expect($match->refresh()->state['ref'])->toBe($after)
        ->and($match->score())->toBe([0, 0])
        ->and(collect($match->log)->where(0, 'hit'))->toHaveCount(1);

    // Anybody else: refused at the door; a malformed report too.
    $this->actingAs(User::factory()->create())->postJson(route('pong.report', $match), $hit)->assertForbidden();
    $this->actingAs($left)->postJson(route('pong.report', $match), ['rally' => 1, 'ball' => 5, 'tick' => 0, 'kind' => 'win'])->assertUnprocessable();
});

test('a whole match reported honestly ends with PongGame::bots()\' score, moves both players\' Elo once, and shows once with the winner alone', function () {
    PongOn::play();
    [$match, $left, $right] = PongLive::started();
    $expected = PongGame::bots($match->seed, [3, 2]);

    $reports = PongLive::play($match, [3, 2]);
    $match->refresh();
    $winner = $expected['winner'] === 0 ? $left : $right;
    $loser = $expected['winner'] === 0 ? $right : $left;

    expect($reports)->toBeGreaterThan(40)
        ->and($match->status)->toBe(PongMatchStatus::Finished)
        ->and($match->end_reason)->toBe(PongEndReason::Score)
        ->and($match->score())->toBe($expected['score'])
        ->and($match->winner_id)->toBe($winner->id)
        ->and([$match->left_rating_before, $match->right_rating_before])->toBe([1000, 1000])
        ->and(PongRating::query()->where('user_id', $winner->id)->value('rating'))->toBe(1020)
        ->and(PongRating::query()->where('user_id', $loser->id)->value('rating'))->toBe(980)
        ->and($match->winner_id === $match->left_id ? $match->left_rating_after : $match->right_rating_after)->toBe(1020);

    // A report after the end changes nothing, and the result is never rated twice.
    $this->actingAs($left)->postJson(route('pong.sync', $match))->assertOk()->assertJsonPath('status', 'finished')->assertJsonPath('ratings.0.0', 1000);
    expect(PongRating::query()->sum('results'))->toBe(2);

    // The mempool and /matches: once, the winner alone, the winner's points first. A running match and an aborted one never.
    [$running] = PongLive::started();
    PongMatch::factory()->aborted()->create();
    $strip = MempoolStrip::build();
    $pongBlocks = array_values(array_filter([...$strip['finished'], ...$strip['running']], fn (array $block): bool => $block['slug'] === 'proof-of-pong'));
    $points = max($expected['score']).':'.min($expected['score']);

    expect($pongBlocks)->toHaveCount(1)
        ->and($pongBlocks[0]['key'])->toBe('pong-'.$match->id)
        ->and(array_column($pongBlocks[0]['sides'], 'name'))->toBe([$winner->displayName()])
        ->and($pongBlocks[0]['score'])->toBe($points)
        ->and($pongBlocks[0]['href'])->toBe(route('pong.match', $match))
        ->and($pongBlocks[0]['casual'])->toBeTrue()
        ->and(array_filter(MempoolStrip::build(null, 'season')['finished'], fn (array $block): bool => $block['slug'] === 'proof-of-pong'))->toBe([]);

    $page = $this->get(route('matches.index', ['game' => 'proof-of-pong']))->assertOk();
    expect(substr_count((string) $page->getContent(), 'data-test="pong-row"'))->toBe(1);
    $page->assertSee(route('pong.match', $match), false)->assertDontSee(route('pong.match', $running), false)->assertSee($points);
    $this->get(route('matches.index'))->assertOk()->assertSee(route('pong.match', $match), false);
    Livewire::test('pages::matches.index')->call('$refresh')->assertOk();
});

test('a player gone for more than 30 seconds loses by forfeit and both Elo move; one back within 30 seconds plays on', function () {
    PongOn::play();
    $matches = app(PongMatches::class);
    [$match, $left, $right] = PongLive::started();

    // The right player's page goes quiet: after a few seconds the match pauses, the clock held.
    $this->travel(6)->seconds();
    $snapshot = $matches->sync($match, $left);
    expect($snapshot['away'])->toBe(1)
        ->and($snapshot['ref']['pausedAt'])->not->toBeNull();

    // Back after 20 s in all: it goes on, the serve moved by the pause.
    $servedAt = $snapshot['ref']['servedAt'];
    $this->travel(14)->seconds();
    $matches->sync($match, $left);
    $snapshot = $matches->sync($match, $right);
    expect($snapshot['status'])->toBe('active')
        ->and($snapshot['away'])->toBeNull()
        ->and($snapshot['ref']['pausedAt'])->toBeNull()
        ->and($snapshot['ref']['servedAt'])->toBeGreaterThan($servedAt);

    // Gone again, this time for good: 31 s after its last sign the absent player has lost.
    $this->travel(20)->seconds();
    expect($matches->sync($match, $left)['status'])->toBe('active');
    $this->travel(11)->seconds();
    $snapshot = $matches->sync($match, $left);
    $match->refresh();

    expect($snapshot['status'])->toBe('finished')
        ->and($match->end_reason)->toBe(PongEndReason::Forfeit)
        ->and($match->winner_id)->toBe($left->id)
        ->and($match->left_rating_after)->toBeGreaterThan(1000)
        ->and($match->right_rating_after)->toBeLessThan(1000);
});

test('the clock alone ends a match nobody asks about: forfeit, a match never started within a minute, and resignation', function () {
    PongOn::play();
    $matches = app(PongMatches::class);

    // Both players gone for over 30 s: nobody to award it to, aborted. A match never opened by both within a minute too.
    [$abandoned] = PongLive::started();
    $waiting = $matches->create(User::factory()->create(), User::factory()->create());
    $this->travel(61)->seconds();

    $this->artisan('pong:check-clocks')->assertSuccessful();

    expect($abandoned->refresh()->status)->toBe(PongMatchStatus::Aborted)
        ->and($abandoned->end_reason)->toBe(PongEndReason::Abort)
        ->and($waiting->refresh()->status)->toBe(PongMatchStatus::Aborted)
        ->and($waiting->left_rating_after)->toBeNull()
        ->and(PongRating::query()->count())->toBe(0);

    [$match, $left, $right] = PongLive::started();
    $this->actingAs($right)->postJson(route('pong.resign', $match))->assertOk()->assertJsonPath('status', 'finished')->assertJsonPath('endReason', 'resign')->assertJsonPath('winner', 0);
    expect($match->refresh()->left_rating_after)->toBeGreaterThan(1000);
});

test('a rematch both players want starts a new match with the sides swapped', function () {
    PongOn::play();
    [$match, $left, $right] = PongLive::started();
    $this->actingAs($right)->postJson(route('pong.resign', $match))->assertOk();

    $this->actingAs($left)->postJson(route('pong.rematch', $match))->assertOk()->assertJsonPath('rematch', [true, false])->assertJsonPath('next', null);
    $next = $this->actingAs($right)->postJson(route('pong.rematch', $match))->assertOk()->json('next');
    $rematch = PongMatch::query()->whereKeyNot($match->id)->sole();

    expect($next)->toBe(route('pong.match', $rematch))
        ->and([$rematch->left_id, $rematch->right_id])->toBe([$right->id, $left->id])
        ->and($rematch->rematch_of_id)->toBe($match->id)
        ->and($rematch->status)->toBe(PongMatchStatus::Waiting);
});

test('resigning a match nobody started calls it off: no winner, no Elo, no entry in the mempool or on /matches', function () {
    // Review 2026-10-10: it paid the other side a win and Elo for a game never played (win-trading without play).
    PongOn::play();
    [$left, $right] = User::factory()->count(2)->create();
    $match = app(PongMatches::class)->create($left, $right);

    $this->actingAs($left)->postJson(route('pong.resign', $match))->assertOk()->assertJsonPath('status', 'aborted');

    expect($match->refresh()->status)->toBe(PongMatchStatus::Aborted)
        ->and($match->winner_id)->toBeNull()
        ->and($match->end_reason)->toBe(PongEndReason::Abort)
        ->and(PongRating::query()->count())->toBe(0)
        ->and(collect(MempoolStrip::build()['finished'] ?? [])->where('kind', 'pong'))->toHaveCount(0);

    // Even a finished match without a start (as a tournament no-show) stays off the lists.
    PongMatch::factory()->finished()->create(['started_at' => null]);
    $this->get(route('matches.index'))->assertOk()->assertDontSee('data-test="pong-row"', false);
});

test('a rematch while one of them is already in another match lapses for both, and either may offer again later', function () {
    PongOn::play();
    [$match, $left, $right] = PongLive::started();
    $matches = app(PongMatches::class);
    $matches->resign($match, $right);
    $other = $matches->create($left, User::factory()->create());

    $this->actingAs($left)->postJson(route('pong.rematch', $match))->assertOk();
    $this->actingAs($right)->postJson(route('pong.rematch', $match))->assertOk()->assertJsonPath('rematch', [false, false])->assertJsonPath('next', null);

    // The other match is over: a new offer works again.
    $matches->resign($other, $left);
    $this->actingAs($left)->postJson(route('pong.rematch', $match))->assertOk()->assertJsonPath('rematch', [true, false]);
    $next = $this->actingAs($right)->postJson(route('pong.rematch', $match))->assertOk()->json('next');

    expect($next)->not->toBeNull();
});

test('a match page shows its two players the live match and anybody else its score', function () {
    PongOn::play();
    [$match, $left] = PongLive::started();
    app(PongMatches::class)->resign($match, $left);

    $html = (string) $this->get(route('pong.match', $match))->assertOk()->assertSee('data-test="pong-live"', false)->getContent();
    preg_match('#<script type="application/json" id="pong-config">(.*?)</script>#s', $html, $found);
    $config = json_decode($found[1], true, flags: JSON_THROW_ON_ERROR);

    expect($config['me'])->toBeNull()
        ->and($config['snapshot']['status'])->toBe('finished')
        ->and($config['snapshot']['winner'])->toBe(1);
});

test('a finished match from the factory is won by the side it names, among the players it was given', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $users = User::query()->count();

    $rightWon = PongMatch::factory()->finished(1, [17, 21])->create(['left_id' => $anna->id, 'right_id' => $bert->id]);
    $leftWon = PongMatch::factory()->finished(0)->create(['left_id' => $anna->id, 'right_id' => $bert->id]);

    expect($rightWon->winner_id)->toBe($bert->id)
        ->and($leftWon->winner_id)->toBe($anna->id)
        ->and(User::query()->count())->toBe($users);

    $ownPlayers = PongMatch::factory()->finished(1)->create();
    expect($ownPlayers->winner_id)->toBe($ownPlayers->right_id);
});

test('every snapshot names the rules version, which follows the rules and fingerprints the code both sides play with', function () {
    PongOn::play();
    $match = PongMatch::factory()->create();
    $version = (new PongRules)->version();

    expect(app(PongMatches::class)->snapshot($match, $match->left)['rules'])->toBe($version)
        ->and($version)->toMatch('/^[0-9a-f]{16}$/')
        ->and((new PongRules(eventBlock: 22))->version())->not->toBe($version)
        ->and((new PongRules(pointsToWin: 11))->version())->not->toBe($version);

    // Every file of the fingerprint is there: a renamed one would drop out of it without a word.
    foreach (PongRules::CODE as $file) {
        expect(base_path($file))->toBeFile();
    }

    // The match page carries it in its first snapshot, the reference a page compares later snapshots with.
    $this->actingAs($match->left)->get(route('pong.match', $match))->assertOk()->assertSee('"rules":"'.$version.'"', false);
});
