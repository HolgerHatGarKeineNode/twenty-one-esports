<?php

/*
 * Opponent requests (P57): a player whose new list adds you is a request.
 * You hear about it once (in the app, and by DM per your settings), you see
 * a card with what the league knows about them on /settings/opponents, and
 * you accept (the signed add) or decline (hidden, no more notifications,
 * reversible). Lists are signed like the browser does (TestSigner).
 */

use App\Enums\NotificationKind;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\NostrEvent;
use App\Models\OpponentRequest;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\OpponentRequests;
use App\Support\SeasonChain\Opponents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    $this->league = new TestSigner;
    config(['esports.league.nsec' => $this->league->secret]);

    $this->aliceSigner = new TestSigner;
    $this->alice = User::factory()->withPubkey($this->aliceSigner->pubkey)->create(['name' => 'alice']);
    $this->bobSigner = new TestSigner;
    $this->bob = User::factory()->withPubkey($this->bobSigner->pubkey)->create(['name' => 'bob']);
});

/** One signed add or remove through the Opponents service, a second apart. */
function listChange(User $me, TestSigner $signer, User $player, string $action): void
{
    $opponents = app(Opponents::class);

    if ($action === 'add') {
        $opponents->add($me, $player, $signer->signTemplates($opponents->prepareAdd($me, $player)));
    } else {
        $opponents->remove($me, $player->pubkey, $signer->signTemplates($opponents->prepareRemove($me, $player->pubkey)));
    }

    test()->travel(1)->seconds();
}

/** @return list<string> the titles of the player's opponent-request notifications */
function requestNotices(User $user): array
{
    return array_values($user->notifications()->oldest()->get()
        ->filter(fn ($notification) => $notification->data['kind'] === NotificationKind::OpponentRequest->value)
        ->map(fn ($notification) => $notification->data['title'])->all());
}

test('a real addition notifies the added player once, with a link to the requests; re-publishing, removing and adding again do not', function () {
    $carol = User::factory()->create(['name' => 'carol']);

    listChange($this->bob, $this->bobSigner, $this->alice, 'add');

    expect(requestNotices($this->alice))->toBe(['bob added you as an opponent'])
        ->and($this->alice->notifications()->sole()->data['url'])->toEndWith('/settings/opponents#requests');

    // A new version that still names alice (bob adds carol): only carol hears about it.
    listChange($this->bob, $this->bobSigner, $carol, 'add');
    // Removed and added again: alice was told already.
    listChange($this->bob, $this->bobSigner, $this->alice, 'remove');
    listChange($this->bob, $this->bobSigner, $this->alice, 'add');

    expect(requestNotices($this->alice))->toHaveCount(1)
        ->and(requestNotices($carol))->toBe(['bob added you as an opponent'])
        ->and(requestNotices($this->bob))->toBe([]);
});

test('no notification when the added player lists the requester already: that addition makes it mutual', function () {
    listChange($this->alice, $this->aliceSigner, $this->bob, 'add');
    listChange($this->bob, $this->bobSigner, $this->alice, 'add');

    expect(requestNotices($this->bob))->toHaveCount(1)
        ->and(requestNotices($this->alice))->toBe([])
        ->and(app(Opponents::class)->listEachOther($this->alice, $this->bob))->toBeTrue();
});

test('a version older than a day (a list first read from the relays) notifies nobody, and a requester is capped per day', function () {
    $old = $this->bobSigner->sign(30000, [['d', 'esports/'.$this->league->pubkey], ['p', $this->alice->pubkey]], '', now()->subDays(2)->getTimestamp());
    NostrEvent::fromSigned(SignedEvent::fromInput($old));

    expect(requestNotices($this->alice))->toBe([])
        ->and(app(OpponentRequests::class)->of($this->alice)['open'])->toBe([$this->bob->pubkey]);

    config(['esports.opponents.requests_per_day' => 2]);
    $players = User::factory()->count(3)->create();
    foreach ($players as $player) {
        listChange($this->alice, $this->aliceSigner, $player, 'add');
    }

    expect($players->map(fn (User $player) => count(requestNotices($player)))->all())->toBe([1, 1, 0])
        ->and(app(OpponentRequests::class)->of($players[2])['open'])->toBe([$this->alice->pubkey]);
});

test('after a decline, the requester\'s later additions do not notify; without the decline they would (control)', function () {
    $carolSigner = new TestSigner;
    $carol = User::factory()->withPubkey($carolSigner->pubkey)->create(['name' => 'carol']);

    // Both requests arrive while nothing may go out, so neither was told yet.
    config(['esports.opponents.requests_per_day' => 0]);
    listChange($this->bob, $this->bobSigner, $this->alice, 'add');
    listChange($carol, $carolSigner, $this->alice, 'add');
    config(['esports.opponents.requests_per_day' => 20]);

    Livewire::actingAs($this->alice)->test('pages::settings.opponents')->call('decline', $this->bob->pubkey)->assertHasNoErrors();

    foreach ([[$this->bob, $this->bobSigner], [$carol, $carolSigner]] as [$requester, $signer]) {
        listChange($requester, $signer, $this->alice, 'remove');
        listChange($requester, $signer, $this->alice, 'add');
    }

    expect(requestNotices($this->alice))->toBe(['carol added you as an opponent']);
});

test('decline hides the request, shows it under Declined with the honest note, and is reversible; declining a stranger is refused', function () {
    listChange($this->bob, $this->bobSigner, $this->alice, 'add');
    $page = Livewire::actingAs($this->alice)->test('pages::settings.opponents')
        ->assertSee('What opponent lists are for')
        ->assertSee('1 open')
        ->assertSeeInOrder(['Opponent requests', 'bob', 'Accept', 'Decline', 'Your opponent list']);

    $page->call('decline', $this->bob->pubkey)->assertHasNoErrors()
        ->assertSee('0 open')
        ->assertSee('Declined (1)')
        ->assertSee('Their public list still names you, because it is their list.');

    expect(app(OpponentRequests::class)->of($this->alice))->toBe(['open' => [], 'declined' => [$this->bob->pubkey]])
        ->and(app(Opponents::class)->lists($this->bob, $this->alice))->toBeTrue();

    $page->call('restore', $this->bob->pubkey)->assertSee('1 open')->assertDontSee('Declined (1)');

    expect(app(OpponentRequests::class)->of($this->alice))->toBe(['open' => [$this->bob->pubkey], 'declined' => []]);

    $stranger = User::factory()->create();
    $page->call('decline', $stranger->pubkey)->assertHasErrors('opponents');
    expect(OpponentRequest::query()->where('requester_pubkey', $stranger->pubkey)->exists())->toBeFalse();
});

test('accept is the signed add: it makes the list mutual, empties the requests and lifts an earlier decline', function () {
    listChange($this->bob, $this->bobSigner, $this->alice, 'add');
    $page = Livewire::actingAs($this->alice)->test('pages::settings.opponents')->call('decline', $this->bob->pubkey);

    $templates = $page->instance()->prepareAdd($this->bob->pubkey, app(Opponents::class));
    $page->call('add', $this->bob->pubkey, json_encode($this->aliceSigner->signTemplates($templates)))->assertHasNoErrors()
        ->assertSee('0 open')
        ->assertDontSee('Declined (1)')
        ->assertSee('1 listed, 1 list you back');

    expect(app(Opponents::class)->listEachOther($this->alice, $this->bob))->toBeTrue()
        ->and(OpponentRequest::query()->where('user_id', $this->alice->id)->value('declined_at'))->toBeNull()
        ->and(requestNotices($this->bob))->toBe([]);
});

test('the request card shows only league records: joined, finished games, clan, who of your opponents lists them, and flags a new account', function () {
    $carolSigner = new TestSigner;
    $carol = User::factory()->withPubkey($carolSigner->pubkey)->create(['name' => 'carol']);
    Clan::factory()->create(['name' => 'Laser Eyes', 'owner_id' => $this->bob->id]);
    ChessGame::factory()->finished()->create(['white_id' => $this->bob->id]);
    ChessGame::factory()->finished()->create(['black_id' => $this->bob->id]);
    ChessGame::factory()->create(['white_id' => $this->bob->id]);

    // Carol is on alice's list and lists bob too.
    listChange($this->alice, $this->aliceSigner, $carol, 'add');
    listChange($carol, $carolSigner, $this->bob, 'add');
    $this->bob->forceFill(['created_at' => now()->subMonths(2)])->save();
    listChange($this->bob, $this->bobSigner, $this->alice, 'add');

    $newbieSigner = new TestSigner;
    $newbie = User::factory()->withPubkey($newbieSigner->pubkey)->create(['name' => 'newbie']);
    listChange($newbie, $newbieSigner, $this->alice, 'add');

    $signals = app(OpponentRequests::class)->signals($this->alice, User::query()->whereKey([$this->bob->id, $newbie->id])->get());
    $bob = $signals[$this->bob->pubkey];
    expect($bob['games'])->toBe(2)
        ->and($bob['clan'])->toBe('Laser Eyes')
        ->and($bob['same_clan'])->toBeFalse()
        ->and($bob['vouched'])->toBe(1)
        ->and($bob['trusted'])->toBeNull()
        ->and($bob['new'])->toBeFalse()
        ->and($signals[$newbie->pubkey]['new'])->toBeTrue();

    Livewire::actingAs($this->alice)->test('pages::settings.opponents')
        ->assertSee('2 open')
        ->assertSeeInOrder(['bob', 'Games here', '2 finished', 'Clan', 'Laser Eyes', 'Your opponents', '1 on your list lists them too'])
        ->assertSeeInOrder(['newbie', 'none finished yet', 'no clan', 'nobody on your list lists them', 'New account with no games here yet.'])
        ->assertDontSee('Trust</dt>', false);
});

/**
 * Add $n requesters of alice, each with a clan and a finished game (so every
 * fact of the card is read), then render her Opponents page and count its
 * queries. Returns [queries, request cards rendered].
 *
 * @return array{0: int, 1: int}
 */
function requestPageQueries(User $alice, int $n): array
{
    foreach (range(1, $n) as $i) {
        $signer = new TestSigner;
        $requester = User::factory()->withPubkey($signer->pubkey)->create();
        Clan::factory()->create(['owner_id' => $requester->id]);
        ChessGame::factory()->finished()->create(['white_id' => $requester->id]);
        listChange($requester, $signer, $alice, 'add');
    }

    // One render first: the first render of a test also fills per-process lookups; count the second.
    $page = Livewire::actingAs($alice);
    $page->test('pages::settings.opponents');
    DB::flushQueryLog();
    DB::enableQueryLog();
    $html = $page->test('pages::settings.opponents')->html();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$queries, substr_count($html, 'data-test="opponent-request"')];
}

test('P57 review: the Opponents page runs as many queries for 25 requests as for 5 (no query per card)', function () {
    [$five, $cardsAtFive] = requestPageQueries($this->alice, 5);
    [$twentyFive, $cardsAtTwentyFive] = requestPageQueries($this->alice, 20);

    fwrite(STDERR, "\n[p57] opponents page queries: 5 requests {$five}, 25 requests {$twentyFive}\n");

    expect([$cardsAtFive, $cardsAtTwentyFive])->toBe([5, 25])
        ->and($twentyFive)->toBe($five);
});
