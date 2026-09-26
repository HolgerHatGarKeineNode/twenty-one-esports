<?php

/*
 * "Add as opponent" (P7e, NIP "Opponent list"): a signed-in player adds or
 * removes a player on their own opponent list, a `30000` with
 * `d` = `esports/<league key>` signed in the browser (here: TestSigner) and
 * submitted through the league; two players who list each other are what
 * rated play needs, and the gate reads the same archived lists.
 */

use App\Jobs\PublishNostrEvent;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use App\Support\Nostr\SignedEventGate;
use App\Support\SeasonChain\AnchoredTrustFacts;
use App\Support\SeasonChain\OpponentListRefused;
use App\Support\SeasonChain\OpponentLists;
use App\Support\SeasonChain\Opponents;
use Illuminate\Database\Events\QueryExecuted;
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

/** Add or remove through the player page's component, signing like the browser does. */
function opponentAction(User $me, TestSigner $signer, User $player, string $action): mixed
{
    $page = Livewire::actingAs($me)->test('opponent-button', ['player' => $player]);
    $templates = $page->instance()->{'prepare'.ucfirst($action)}(app(Opponents::class));

    expect($templates)->toBeArray();

    $page->call($action, json_encode($signer->signTemplates($templates)))->assertHasNoErrors();
    // A new version needs a later second than the last one (no versions dated ahead of now).
    test()->travel(1)->seconds();

    return $page;
}

test('adding an opponent stores a valid signed 30000 of this league, with the player\'s p, and queues it for the league relays', function () {
    opponentAction($this->alice, $this->aliceSigner, $this->bob, 'add');

    $stored = NostrEvent::query()->where('kind', 30000)->sole();
    $event = SignedEvent::fromInput($stored->payload());

    expect($event->hasValidSignature())->toBeTrue()
        ->and($event->pubkey)->toBe($this->alice->pubkey)
        ->and($event->content)->toBe('')
        ->and($stored->d)->toBe('esports/'.$this->league->pubkey)
        ->and($event->tags)->toBe([
            ['d', 'esports/'.$this->league->pubkey],
            ['title', 'TWENTY ONE Esports: opponents'],
            ['p', $this->bob->pubkey],
            ['alt', 'Follow set: opponents for rated games in TWENTY ONE Esports'],
        ])
        ->and(OpponentLists::forLeague()->newest($this->alice->pubkey)->event_id)->toBe($event->id);

    Queue::assertPushed(PublishNostrEvent::class, fn (PublishNostrEvent $job) => $job->event->is($stored));
});

test('a second add appends, a remove takes only that player out, and each version is newer than the last', function () {
    $carol = User::factory()->create();
    $opponents = app(Opponents::class);

    opponentAction($this->alice, $this->aliceSigner, $this->bob, 'add');
    opponentAction($this->alice, $this->aliceSigner, $carol, 'add');

    expect($opponents->entries($this->alice))->toBe([$this->bob->pubkey, $carol->pubkey]);

    opponentAction($this->alice, $this->aliceSigner, $this->bob, 'remove');

    $versions = NostrEvent::query()->where('kind', 30000)->orderBy('id')->pluck('signed_at')->all();

    expect($opponents->entries($this->alice))->toBe([$carol->pubkey])
        ->and($versions)->toHaveCount(3)
        ->and($versions[1])->toBeGreaterThan($versions[0])
        ->and($versions[2])->toBeGreaterThan($versions[1]);
});

test('guests are refused, and see no opponent button on a player page', function () {
    Livewire::test('opponent-button', ['player' => $this->bob])->call('prepareAdd')->assertForbidden();
    Livewire::test('opponent-button', ['player' => $this->bob])->call('add', '[]')->assertForbidden();
    Livewire::test('opponent-button', ['player' => $this->bob])->call('remove', '[]')->assertForbidden();

    $this->get(route('players.show', $this->bob->npub))->assertOk()->assertDontSee('data-test="opponent"', false);
    $this->get(route('settings.opponents'))->assertRedirect(route('login'));

    expect(NostrEvent::query()->where('kind', 30000)->count())->toBe(0);
});

test('you list each other once both have added the other: the page, the card and the gate agree', function () {
    $state = fn (User $viewer, User $player) => Livewire::actingAs($viewer)->test('opponent-button', ['player' => $player])->html();

    expect($state($this->alice, $this->bob))->toContain('data-state="none"')->toContain('Add as opponent');

    opponentAction($this->alice, $this->aliceSigner, $this->bob, 'add');

    expect($state($this->alice, $this->bob))->toContain('data-state="listed"')->toContain('On your opponent list')
        ->and($state($this->bob, $this->alice))->toContain('data-state="lists-you"')->toContain('Add alice back')
        ->and(app(Opponents::class)->listEachOther($this->alice, $this->bob))->toBeFalse();

    opponentAction($this->bob, $this->bobSigner, $this->alice, 'add');

    expect($state($this->alice, $this->bob))->toContain('data-state="mutual"')->toContain('You list each other')
        ->and($state($this->bob, $this->alice))->toContain('data-state="mutual"')
        ->and(app(Opponents::class)->listEachOther($this->alice, $this->bob))->toBeTrue()
        ->and(app(Opponents::class)->mutual($this->alice))->toBe([$this->bob->pubkey])
        // The gate's condition 1 reads the same lists.
        ->and((new AnchoredTrustFacts)->at([], [$this->alice->pubkey, $this->bob->pubkey])['connected'])->toBeTrue();

    $this->actingAs($this->alice)->get(route('players.card', $this->bob->npub))->assertOk()->assertSee('you list each other');
    $this->actingAs($this->alice)->get(route('players.show', $this->bob->npub))->assertOk()->assertSee('data-state="mutual"', false);

    // Bob takes alice off: no longer mutual, for the page and the gate.
    opponentAction($this->bob, $this->bobSigner, $this->alice, 'remove');

    expect($state($this->alice, $this->bob))->toContain('data-state="listed"')
        ->and((new AnchoredTrustFacts)->at([], [$this->alice->pubkey, $this->bob->pubkey])['connected'])->toBeFalse();
});

test('the league refuses adding yourself, adding twice, removing someone not listed, a changed event, and any list before the league key is set', function () {
    $opponents = app(Opponents::class);

    expect(fn () => $opponents->prepareAdd($this->alice, $this->alice))->toThrow(OpponentListRefused::class, 'yourself')
        ->and(fn () => $opponents->prepareRemove($this->alice, $this->bob->pubkey))->toThrow(OpponentListRefused::class, 'not on your opponent list');

    // A signed event that is not the prepared one (an extra entry) is refused.
    $templates = $opponents->prepareAdd($this->alice, $this->bob);
    $templates[0]['tags'][] = ['p', User::factory()->create()->pubkey];
    expect(fn () => $opponents->add($this->alice, $this->bob, $this->aliceSigner->signTemplates($templates)))->toThrow(RejectedEvent::class, 'not_the_prepared_event');

    // Signed by someone else.
    expect(fn () => $opponents->add($this->alice, $this->bob, $this->bobSigner->signTemplates($opponents->prepareAdd($this->alice, $this->bob))))
        ->toThrow(RejectedEvent::class, 'foreign_author');

    $opponents->add($this->alice, $this->bob, $this->aliceSigner->signTemplates($opponents->prepareAdd($this->alice, $this->bob)));
    expect(fn () => $opponents->prepareAdd($this->alice, $this->bob))->toThrow(OpponentListRefused::class, 'already on your opponent list');

    // The page shows a refusal as a message, not an exception.
    Livewire::actingAs($this->alice)->test('opponent-button', ['player' => $this->bob])->call('prepareAdd')->assertHasErrors('opponent');

    config(['esports.league.nsec' => null]);
    expect(fn () => $opponents->prepareAdd($this->alice, User::factory()->create()))->toThrow(OpponentListRefused::class, 'once the league is set up')
        ->and(Livewire::actingAs($this->alice)->test('opponent-button', ['player' => $this->bob])->html())->not->toContain('data-test="opponent"')
        ->and(NostrEvent::query()->where('kind', 30000)->count())->toBe(1);
});

test('NIP rule 18: a list of another league, with content, naming its author or an entry twice is refused at the door', function (string $case, string $reason) {
    $template = app(Opponents::class)->prepareAdd($this->alice, $this->bob)[0];
    $tags = $template['tags']; // d, title, p bob, alt

    $template = match ($case) {
        'other league' => [...$template, 'tags' => [['d', 'esports/'.str_repeat('a', 64)], ...array_slice($tags, 1)]],
        'private items' => [...$template, 'content' => 'encrypted'],
        'names the author' => [...$template, 'tags' => [...array_slice($tags, 0, 3), ['p', $this->alice->pubkey], $tags[3]]],
        'twice' => [...$template, 'tags' => [...array_slice($tags, 0, 3), $tags[2], $tags[3]]],
    };

    expect(fn () => app(SignedEventGate::class)->check($this->aliceSigner->signTemplates([$template])[0], $template, $this->alice))
        ->toThrow(RejectedEvent::class, $reason);
})->with([
    ['other league', 'opponent_list_d'],
    ['private items', 'opponent_list_content'],
    ['names the author', 'opponent_list_entry'],
    ['twice', 'opponent_list_entry'],
]);

test('the settings list shows the entries, who lists you back, who waits for you, and removes and adds back with a signature', function () {
    $carol = User::factory()->create(['name' => 'carol']);
    $daveSigner = new TestSigner;
    $dave = User::factory()->withPubkey($daveSigner->pubkey)->create(['name' => 'dave']);
    $opponents = app(Opponents::class);

    opponentAction($this->alice, $this->aliceSigner, $this->bob, 'add');
    opponentAction($this->alice, $this->aliceSigner, $carol, 'add');
    opponentAction($this->bob, $this->bobSigner, $this->alice, 'add');
    opponentAction($dave, $daveSigner, $this->alice, 'add');

    $page = Livewire::actingAs($this->alice)->test('pages::settings.opponents')
        ->assertOk()
        ->assertSee('2 listed, 1 list you back')
        ->assertSeeInOrder(['bob', 'lists you back', 'carol', 'not listing you yet', 'They list you', 'dave', 'Add back']);

    $page->call('remove', $carol->pubkey, json_encode($this->aliceSigner->signTemplates($page->instance()->prepareRemove($carol->pubkey, $opponents))))->assertHasNoErrors();
    $this->travel(1)->seconds();
    $page->call('add', $dave->pubkey, json_encode($this->aliceSigner->signTemplates($page->instance()->prepareAdd($dave->pubkey, $opponents))))->assertHasNoErrors();

    expect($opponents->entries($this->alice))->toBe([$this->bob->pubkey, $dave->pubkey])
        ->and($opponents->mutual($this->alice))->toBe([$this->bob->pubkey, $dave->pubkey]);

    $this->actingAs($this->alice)->get(route('settings.opponents'))->assertOk()->assertSee('2 listed, 2 list you back')->assertSee('Nobody is waiting for you to add them back.');
});

test('P7e gate, Low: list changes are rate limited per player, with a friendly message, and the limit is per player', function () {
    config(['esports.opponents.changes_per_minute' => 3, 'esports.opponents.changes_per_day' => 5]);
    $opponents = app(Opponents::class);
    $toggle = function (User $me, TestSigner $signer, User $other) use ($opponents): void {
        $templates = $opponents->lists($me, $other) ? $opponents->prepareRemove($me, $other->pubkey) : $opponents->prepareAdd($me, $other);
        $opponents->lists($me, $other)
            ? $opponents->remove($me, $other->pubkey, $signer->signTemplates($templates))
            : $opponents->add($me, $other, $signer->signTemplates($templates));
        $this->travel(1)->seconds();
    };

    foreach (range(1, 3) as $i) {
        $toggle($this->alice, $this->aliceSigner, $this->bob);
    }

    // The fourth change within the minute is refused, before anything is signed.
    expect(fn () => $opponents->prepareAdd($this->alice, User::factory()->create()))->toThrow(OpponentListRefused::class, 'changed your opponent list often')
        ->and(NostrEvent::query()->where('kind', 30000)->count())->toBe(3);
    Livewire::actingAs($this->alice)->test('opponent-button', ['player' => $this->bob])->call('prepareRemove')->assertHasErrors('opponent');

    // Another player is not affected.
    $toggle($this->bob, $this->bobSigner, $this->alice);

    // A minute later two more fit, then the day is used up.
    $this->travel(61)->seconds();
    $toggle($this->alice, $this->aliceSigner, $this->bob);
    $toggle($this->alice, $this->aliceSigner, $this->bob);
    $this->travel(61)->seconds();

    expect(fn () => $opponents->prepareAdd($this->alice, User::factory()->create()))->toThrow(OpponentListRefused::class, 'changed your opponent list often')
        ->and(NostrEvent::query()->where('kind', 30000)->where('pubkey', $this->alice->pubkey)->count())->toBe(5);
});

test('P7e gate, Low: a version is never dated ahead of now: a second change in the same second waits, and a signed version from the future is refused', function () {
    $this->freezeSecond();
    $opponents = app(Opponents::class);
    $carol = User::factory()->create();
    $opponents->add($this->alice, $this->bob, $this->aliceSigner->signTemplates($opponents->prepareAdd($this->alice, $this->bob)));

    // Same second: no banked future timestamp, "wait a moment" instead.
    expect(fn () => $opponents->prepareAdd($this->alice, $carol))->toThrow(OpponentListRefused::class, 'Wait a moment');

    // A version the player signs with a later created_at than now is refused as well.
    $this->travel(1)->seconds();
    $template = $opponents->prepareAdd($this->alice, $carol)[0];
    $ahead = $this->aliceSigner->sign($template['kind'], $template['tags'], $template['content'], now()->getTimestamp() + 60);

    expect(fn () => $opponents->add($this->alice, $carol, [$ahead]))->toThrow(OpponentListRefused::class, 'Wait a moment')
        ->and(NostrEvent::query()->where('kind', 30000)->max('signed_at'))->toBeLessThanOrEqual(now()->getTimestamp());
});

test('P7e gate, Low: the newest list per player is read from one current row, however many versions are archived', function () {
    $d = OpponentLists::forLeague()->d();
    $carol = User::factory()->create();

    // 40 archived versions of alice's list (the newest lists bob), 1 of carol's.
    foreach (range(1, 40) as $i) {
        NostrEvent::fromSigned(SignedEvent::fromInput($this->aliceSigner->sign(30000, [['d', $d], ['p', $i % 2 === 0 ? $this->bob->pubkey : $carol->pubkey], ['alt', 'x']], '', now()->getTimestamp() - 100 + $i)));
    }
    NostrEvent::fromSigned(SignedEvent::fromInput((new TestSigner)->sign(30000, [['d', $d], ['p', $this->bob->pubkey], ['alt', 'x']], '', now()->getTimestamp())));

    $entries = OpponentLists::forLeague()->newestEntries();
    $listedBy = app(Opponents::class)->listedBy($this->bob);

    expect(DB::table('opponent_lists_current')->where('d', $d)->count())->toBe(2)
        ->and($entries[$this->alice->pubkey])->toBe([$this->bob->pubkey])
        ->and($listedBy)->toHaveCount(2)
        ->and(app(Opponents::class)->listedBy($carol))->toBe([])
        // Neither reads the archive of versions.
        ->and(opponentQueriesTouchingArchive(fn () => OpponentLists::forLeague()->newestEntries()))->toBe(0)
        ->and(opponentQueriesTouchingArchive(fn () => app(Opponents::class)->listedBy($this->bob)))->toBe(0);
});

test('P7e gate, Low: the migration fills the current rows from the archived versions, the newest per player', function () {
    $d = OpponentLists::forLeague()->d();
    $at = now()->getTimestamp();
    foreach ([[$at - 20, $this->bob], [$at - 10, User::factory()->create()]] as [$signedAt, $listed]) {
        NostrEvent::fromSigned(SignedEvent::fromInput($this->aliceSigner->sign(30000, [['d', $d], ['p', $listed->pubkey], ['alt', 'x']], '', $signedAt)));
    }
    $newest = NostrEvent::query()->where('kind', 30000)->orderByDesc('signed_at')->first();
    $migration = require database_path('migrations/2026_09_26_075940_create_opponent_lists_current_table.php');

    $migration->down();
    $migration->up();

    expect(DB::table('opponent_lists_current')->where('d', $d)->get(['pubkey', 'event_id'])->map(fn (object $row) => [$row->pubkey, $row->event_id])->all())
        ->toBe([[$this->alice->pubkey, $newest->event_id]]);
});

/** How many queries $call runs against the nostr_events archive with kind 30000. */
function opponentQueriesTouchingArchive(Closure $call): int
{
    $count = 0;
    DB::listen(function (QueryExecuted $query) use (&$count): void {
        if (str_contains($query->sql, 'from "nostr_events"') && ! str_contains($query->sql, '"id" in') && ! str_contains($query->sql, '"nostr_events"."id" =')) {
            $count++;
        }
    });
    $call();

    return $count;
}
