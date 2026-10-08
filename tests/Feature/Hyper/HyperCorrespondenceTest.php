<?php

use App\Jobs\SendNostrDm;
use App\Jobs\SendWebPush;
use App\Models\HyperAction;
use App\Models\HyperMatch;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Dock\DockItem;
use App\Support\Dock\OpenMatches;
use App\Support\Hyper\HyperMatches;
use App\Support\Notifications\WebPush;
use Illuminate\Support\Facades\Bus;
use Tests\Support\HyperOn;

/*
| Hyperbitcoinization correspondence matches (plan "Hyperbitcoinization", P3): a day per turn, the player whose
| turn starts hears about it (push, the "your move" kind; never a bot seat, never a spectator), a missed
| deadline lets a bot play that turn for the player (the seat stays theirs), and the match dock lists the
| match as a daily game: "your turn" needs the player, another seat's turn waits.
*/

beforeEach(function () {
    HyperOn::play();
    $this->freezeTime();
    Bus::fake([SendWebPush::class, SendNostrDm::class]);

    [$public, $private] = WebPush::generateKeyPair();
    config([
        'esports.webpush.public_key' => WebPush::base64UrlEncode($public),
        'esports.webpush.private_key' => WebPush::base64UrlEncode($private),
        'esports.webpush.subject' => 'https://esports.test',
        // Every turn start counts here: no hour between two pushes, no "at the board".
        'esports.notifications.your_move' => ['at_board_minutes' => 0, 'per_game_minutes' => 0],
    ]);
});

function hyperPushPlayer(): User
{
    [$browserKey] = WebPush::generateKeyPair();
    $user = User::factory()->create(['chess_settings' => ['push' => true, 'dm' => true]]);
    PushSubscription::query()->create([
        'user_id' => $user->id,
        'endpoint' => 'https://push.example.test/'.$user->id,
        'public_key' => WebPush::base64UrlEncode($browserKey),
        'auth_token' => WebPush::base64UrlEncode(random_bytes(16)),
    ]);

    return $user;
}

/**
 * @return list<array<string, mixed>>
 */
function hyperPushesTo(User $user): array
{
    return array_values(Bus::dispatched(SendWebPush::class)->filter(fn (SendWebPush $job): bool => $job->subscription->user_id === $user->id)->map(fn (SendWebPush $job): array => $job->payload)->all());
}

function hyperCorrespondence(User $anna, ?User $bert = null, int $seed = 7): HyperMatch
{
    return app(HyperMatches::class)->create([
        ['user' => $anna, 'faction' => 'bitcoiner'],
        $bert === null ? ['bot' => true, 'faction' => 'fed'] : ['user' => $bert, 'faction' => 'fed'],
        ['bot' => true, 'faction' => 'ezb'],
    ], seed: $seed, creator: $anna, mode: HyperMatch::CORRESPONDENCE);
}

test('a correspondence turn has a day, and only the player whose turn starts is told: never a bot seat or a spectator', function () {
    [$anna, $bert, $carl] = [hyperPushPlayer(), hyperPushPlayer(), hyperPushPlayer()];
    $match = hyperCorrespondence($anna, $bert);

    expect($match->deadline_ms)->toBe((int) now()->getTimestampMs() + 24 * 3_600_000)
        ->and(app(HyperMatches::class)->snapshot($match, $anna)['turn_seconds'])->toBe(86_400)
        ->and(hyperPushesTo($anna))->toHaveCount(1)
        ->and(hyperPushesTo($anna)[0]['url'])->toEndWith('/hyperbitcoinization/m/'.$match->ulid)
        ->and(hyperPushesTo($bert))->toBe([]);

    // Anna ends her turn: Bert's begins, and he is told.
    $this->actingAs($anna)->postJson(route('hyper.act', $match), ['action' => ['type' => 'end_turn'], 'ply' => 1])->assertOk();
    expect(hyperPushesTo($bert))->toHaveCount(1)
        ->and(hyperPushesTo($anna))->toHaveCount(1);

    // Bert ends his: the bot plays its turn on the server, and Anna's next one starts.
    $this->actingAs($bert)->postJson(route('hyper.act', $match), ['action' => ['type' => 'end_turn'], 'ply' => $match->refresh()->ply + 1])->assertOk();
    expect($match->refresh()->current_seat)->toBe(0)
        ->and(hyperPushesTo($anna))->toHaveCount(2)
        ->and(hyperPushesTo($bert))->toHaveCount(1)
        // Carl only watches; "your move" is never a DM.
        ->and(hyperPushesTo($carl))->toBe([])
        ->and(Bus::dispatched(SendNostrDm::class))->toHaveCount(0);
});

test('a live match tells nobody about a turn', function () {
    [$anna, $bert] = [hyperPushPlayer(), hyperPushPlayer()];
    HyperOn::versus($anna, $bert, bots: 1);

    expect(Bus::dispatched(SendWebPush::class))->toHaveCount(0);
});

test('a missed correspondence deadline: a bot plays that turn for the player, and the seat stays theirs', function () {
    $anna = hyperPushPlayer();
    $match = hyperCorrespondence($anna);

    $this->travel(24 * 3600 - 1)->seconds();
    $this->artisan('hyper:check-clocks')->assertSuccessful();
    expect($match->refresh()->actions()->where('source', HyperAction::TIMER)->count())->toBe(0);

    $this->travel(1)->seconds();
    $this->artisan('hyper:check-clocks')->assertSuccessful();
    $match->refresh();
    $timer = $match->actions()->where('source', HyperAction::TIMER)->get();
    $types = $timer->pluck('action.type')->all();

    // A whole turn, as a bot plays it: troops placed, the phases ended, all for seat 0.
    expect($timer->pluck('seat')->unique()->all())->toBe([0])
        ->and($types)->toContain('deploy')
        ->and(count(array_filter($types, fn (string $type): bool => $type === 'end_phase')))->toBeGreaterThanOrEqual(2)
        ->and($match->seats[0]->bot)->toBeFalse()
        ->and($match->seats[0]->takeover)->toBeNull()
        ->and($match->seats[0]->timeouts)->toBe(1)
        // The bots answered, it is Anna's turn again with a new day, and she was told.
        ->and($match->current_seat)->toBe(0)
        ->and($match->deadline_ms)->toBe((int) now()->getTimestampMs() + 24 * 3_600_000)
        ->and(hyperPushesTo($anna))->toHaveCount(2);
});

test('an untouched live turn that runs out only ends: no bot plays it', function () {
    $anna = hyperPushPlayer();
    $match = app(HyperMatches::class)->create([['user' => $anna, 'faction' => 'fed'], ['bot' => true, 'faction' => 'ezb']], seed: 7);

    $this->travel(91)->seconds();
    $this->artisan('hyper:check-clocks')->assertSuccessful();

    expect($match->refresh()->actions()->where('source', HyperAction::TIMER)->pluck('action')->all())->toBe([['type' => 'end_turn']]);
});

test('a correspondence turn the player had begun ends as live when its day runs out', function () {
    $anna = hyperPushPlayer();
    $match = hyperCorrespondence($anna);
    $mine = app(HyperMatches::class)->snapshot($match, $anna)['legal']['deploy'][0];
    $this->actingAs($anna)->postJson(route('hyper.act', $match), ['action' => ['type' => 'deploy', 'territory' => $mine, 'unit' => 'pleb', 'qty' => 1], 'ply' => 1])->assertOk();

    $this->travel(24 * 3600 + 1)->seconds();
    $this->artisan('hyper:check-clocks')->assertSuccessful();

    expect($match->refresh()->actions()->where('source', HyperAction::TIMER)->pluck('action')->all())->toBe([['type' => 'end_turn']]);
});

test('the match dock lists a correspondence match: the player to move is needed, the others wait; a live match has no tab', function () {
    [$anna, $bert] = [hyperPushPlayer(), hyperPushPlayer()];
    $match = hyperCorrespondence($anna, $bert);
    HyperOn::versus($anna, $bert);

    $annas = app(OpenMatches::class)->for($anna)->filter(fn (DockItem $item): bool => $item->kind === 'hyper')->values();
    $berts = app(OpenMatches::class)->for($bert)->filter(fn (DockItem $item): bool => $item->kind === 'hyper')->values();

    expect($annas)->toHaveCount(1)
        ->and($annas[0]->needsYou)->toBeTrue()
        ->and($annas[0]->group)->toBe('need')
        ->and($annas[0]->href)->toBe(route('hyper.match', $match))
        ->and($annas[0]->tick['endsAt'])->toBe($match->deadline_ms)
        ->and($annas[0]->gameMark())->toBe('Hy')
        ->and($berts)->toHaveCount(1)
        ->and($berts[0]->needsYou)->toBeFalse()
        ->and($berts[0]->group)->toBe('wait');

    // The dock renders it on a shell page.
    $this->withoutVite();
    $this->actingAs($anna)->get(route('hyper.index'))->assertOk()->assertSee('data-key="hyper-'.$match->id.'"', false);
});
