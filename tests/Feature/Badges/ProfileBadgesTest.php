<?php

use App\Models\NostrEvent;
use App\Models\RankBadge;
use App\Models\Rating;
use App\Models\User;
use App\Support\Badges\ProfileBadges;
use App\Support\Badges\ProfileBadgesRefused;
use App\Support\Badges\RankBadges;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/**
 * "Show on my Nostr profile" (P11, NIP "Rank badges", Profile): the rank
 * badge's (a, e) pair goes into the player's kind 10008, built from the
 * newest list, every existing entry kept. Security gate F1/F2: only after a
 * write relay was read, never from the archive alone, no 30008 resurrecting
 * a removed pair, at most two events and bounded signature checks per call.
 */
beforeEach(function () {
    openSeason(['slug' => 'pre-season']);
    config(['esports.badges.nsec' => (new TestSigner)->secret]);

    $this->signer = new TestSigner;
    $this->user = User::factory()->withPubkey($this->signer->pubkey)->create();
    Rating::query()->create([
        'pool' => Rating::RATED, 'season' => 'pre-season', 'game' => 'chess', 'mode' => 'blitz',
        'subject' => 'user:'.$this->user->id, 'user_id' => $this->user->id, 'rating' => 1060, 'results' => 7,
    ]);
    app(RankBadges::class)->sync($this->user, 'chess', 'blitz');
    $this->badge = RankBadge::query()->sole();
    $this->pair = [['a', $this->badge->address()], ['e', $this->badge->awardEvent->event_id]];
});

const BRAVERY = ['a', '30009:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa:bravery'];

/** Two badges from another issuer, as some other client wrote them, plus a private part. */
function existingProfileList(TestSigner $signer, int $ago = 3600): array
{
    return $signer->sign(ProfileBadges::KIND, [
        BRAVERY, ['e', str_repeat('1', 64), 'wss://relay.example'],
        ['a', '30009:'.str_repeat('b', 64).':honor'], ['e', str_repeat('2', 64)],
    ], 'nip44-private-items', now()->getTimestamp() - $ago);
}

function legacyProfileList(TestSigner $signer, int $ago, string $name = 'bravery'): array
{
    return $signer->sign(ProfileBadges::LEGACY_KIND, [['d', 'profile_badges'], ['a', '30009:'.str_repeat('a', 64).':'.$name], ['e', str_repeat('1', 64)]], '', now()->getTimestamp() - $ago);
}

function signedLike(TestSigner $signer, array $template): array
{
    return $signer->sign($template['kind'], $template['tags'], $template['content'], max($template['created_at'], now()->getTimestamp()));
}

function forged(array $event): array
{
    return [...$event, 'sig' => str_repeat('0', 128)];
}

test('the badge is appended to the newest list and every existing entry, its order and the content stay', function () {
    $older = existingProfileList($this->signer, 7200);
    $newer = existingProfileList($this->signer, 3600);
    $profiles = app(ProfileBadges::class);

    $prepared = $profiles->prepare($this->user, $this->badge, [$older, $newer], true);

    expect($prepared['kept'])->toBe(2)
        ->and($prepared['template']['tags'])->toBe([...$newer['tags'], ...$this->pair, ['alt', 'Profile badges']])
        ->and($prepared['template']['content'])->toBe('nip44-private-items');

    $stored = $profiles->submit($this->user, $this->badge, [$older, $newer], true, signedLike($this->signer, $prepared['template']));

    expect($stored->kind)->toBe(10008)
        ->and($stored->payload()['tags'])->toBe($prepared['template']['tags'])
        ->and($stored->queued_at)->not->toBeNull()
        ->and($profiles->isListed($this->user, $this->badge))->toBeTrue()
        ->and(fn () => $profiles->prepare($this->user, $this->badge, [$stored->payload()], true))->toThrow(ProfileBadgesRefused::class, 'already');
});

test('a relay read that found no list starts a new one', function () {
    expect(app(ProfileBadges::class)->prepare($this->user, $this->badge, [], true)['template']['tags'])->toBe([...$this->pair, ['alt', 'Profile badges']]);
});

test('without a write relay read the league refuses, and never falls back to its archive', function () {
    $profiles = app(ProfileBadges::class);
    NostrEvent::fromSigned(SignedEvent::fromInput(existingProfileList($this->signer)));

    expect(fn () => $profiles->prepare($this->user, $this->badge, [], false))->toThrow(ProfileBadgesRefused::class, 'could not be read')
        ->and(fn () => $profiles->prepare($this->user, $this->badge, [existingProfileList($this->signer, 60)], false))->toThrow(ProfileBadgesRefused::class, 'could not be read');
});

test('a read that comes back empty is refused when the league knows a list (a relay that lost it)', function () {
    $profiles = app(ProfileBadges::class);
    NostrEvent::fromSigned(SignedEvent::fromInput(existingProfileList($this->signer)));

    expect(fn () => $profiles->prepare($this->user, $this->badge, [], true))->toThrow(ProfileBadgesRefused::class, 'returned no badge list');
});

test('a list older than the archived one is not signature-checked, and the archived newer list is kept', function () {
    $profiles = app(ProfileBadges::class);
    $archived = existingProfileList($this->signer, 60);
    NostrEvent::fromSigned(SignedEvent::fromInput($archived));
    $stale = forged(existingProfileList($this->signer, 7200));

    expect($profiles->prepare($this->user, $this->badge, [$stale], true)['template']['tags'])->toBe([...$archived['tags'], ...$this->pair, ['alt', 'Profile badges']])
        ->and(Cache::has('nostr-verified:'.hash('sha256', SignedEvent::fromInput($stale)->toJson())))->toBeFalse();
});

test('a deprecated 30008 is migrated when there is no 10008', function () {
    expect(app(ProfileBadges::class)->prepare($this->user, $this->badge, [legacyProfileList($this->signer, 60)], true)['template']['tags'])
        ->toBe([BRAVERY, ['e', str_repeat('1', 64)], ...$this->pair, ['alt', 'Profile badges']]);
});

test('a pair the player removed in a newer 10008 does not come back from an older 30008', function () {
    $current = $this->signer->sign(ProfileBadges::KIND, [BRAVERY, ['e', str_repeat('1', 64)]], '', now()->getTimestamp() - 30);
    $oldLegacy = legacyProfileList($this->signer, 3600, 'honor');
    $tags = app(ProfileBadges::class)->prepare($this->user, $this->badge, [$current, $oldLegacy], true)['template']['tags'];

    expect(collect($tags)->where(0, 'a')->pluck(1)->values()->all())->toBe([BRAVERY[1], $this->badge->address()]);
});

test('a 30008 newer than the 10008 is merged in', function () {
    $current = $this->signer->sign(ProfileBadges::KIND, [BRAVERY, ['e', str_repeat('1', 64)]], '', now()->getTimestamp() - 30);
    $newLegacy = legacyProfileList($this->signer, 10, 'courage');
    $tags = app(ProfileBadges::class)->prepare($this->user, $this->badge, [$current, $newLegacy], true)['template']['tags'];

    expect(collect($tags)->where(0, 'a')->pluck(1)->values()->all())->toBe([BRAVERY[1], '30009:'.str_repeat('a', 64).':courage', $this->badge->address()]);
});

test('a forged copy of the real list does not hide it, and its cached rejection does not poison the real one', function () {
    $profiles = app(ProfileBadges::class);
    $real = existingProfileList($this->signer);
    $fake = forged($real);

    // Only the forgery: no valid list, so a new one (the browser's own check is tests/js/relayRead.test.mjs).
    expect($profiles->prepare($this->user, $this->badge, [$fake], true)['kept'])->toBe(0)
        ->and(Cache::get('nostr-verified:'.hash('sha256', SignedEvent::fromInput($fake)->toJson())))->toBeFalse();

    // Same id, the real signature: verified on its own digest, kept.
    expect($profiles->prepare($this->user, $this->badge, [$fake, $real], true)['kept'])->toBe(2)
        ->and(NostrEvent::query()->where('event_id', $real['id'])->sole()->payload()['sig'])->toBe($real['sig']);
});

test('at most two events are looked at per call', function () {
    $profiles = app(ProfileBadges::class);
    $real = existingProfileList($this->signer);

    // The real list is the third event: never looked at, so nothing is archived and the list starts empty.
    $prepared = $profiles->prepare($this->user, $this->badge, [forged(existingProfileList($this->signer, 10)), forged(existingProfileList($this->signer, 20)), $real], true);

    expect($prepared['kept'])->toBe(0)
        ->and(NostrEvent::query()->where('kind', 10008)->count())->toBe(0);
});

test('lists of another author, with a broken signature, or of another kind are ignored', function () {
    $stranger = new TestSigner;
    $follows = $this->signer->sign(3, [['p', $stranger->pubkey]]);

    expect(app(ProfileBadges::class)->prepare($this->user, $this->badge, [existingProfileList($stranger), $follows], true)['kept'])->toBe(0)
        ->and(app(ProfileBadges::class)->prepare($this->user, $this->badge, [forged(existingProfileList($this->signer)), 'junk'], true)['kept'])->toBe(0)
        ->and(NostrEvent::query()->whereIn('kind', [3, 10008])->count())->toBe(0);
});

test('a signed list that drops an entry, or a badge of someone else, is refused', function () {
    $list = existingProfileList($this->signer);
    $profiles = app(ProfileBadges::class);
    $template = $profiles->prepare($this->user, $this->badge, [$list], true)['template'];
    $dropped = [...$template, 'tags' => array_values(array_slice($template['tags'], 2))];

    expect(fn () => $profiles->submit($this->user, $this->badge, [$list], true, signedLike($this->signer, $dropped)))->toThrow(RejectedEvent::class)
        ->and(fn () => $profiles->prepare(User::factory()->create(), $this->badge, [], true))->toThrow(ProfileBadgesRefused::class, 'not yours')
        ->and(NostrEvent::query()->where('kind', 10008)->count())->toBe(1);
});

test('prepare and submit are limited per player and minute', function () {
    config(['esports.badges.profile_calls_per_minute' => 3]);
    $profiles = app(ProfileBadges::class);

    foreach (range(1, 3) as $call) {
        $profiles->prepare($this->user, $this->badge, [], true);
    }

    expect(fn () => $profiles->prepare($this->user, $this->badge, [], true))->toThrow(ProfileBadgesRefused::class, 'often')
        ->and(fn () => $profiles->submit($this->user, $this->badge, [], true, []))->toThrow(ProfileBadgesRefused::class, 'often');

    // Another player has their own count.
    $other = User::factory()->create();
    expect(fn () => $profiles->prepare($other, $this->badge, [], true))->toThrow(ProfileBadgesRefused::class, 'not yours');

    $this->travel(61)->seconds();
    expect($profiles->prepare($this->user, $this->badge, [], true)['kept'])->toBe(0);
});

test('one badge call per Livewire request: a batch of calls gets one answer', function () {
    $component = Livewire::actingAs($this->user)->test('rank-badges', ['player' => $this->user]);
    $call = ['method' => 'prepareProfile', 'params' => [$this->badge->id, '[]', true], 'path' => ''];

    $component->update(calls: [$call, $call, $call]);

    expect($component->effects['returns'][0]['kept'] ?? null)->toBe(0)
        ->and(array_slice($component->effects['returns'], 1))->toBe([null, null])
        ->and($component->errors()->get('badges'))->toBe(['One badge change at a time. Try again.']);

    // The next request may call again.
    expect($component->call('prepareProfile', $this->badge->id, '[]', true)->effects['returns'][0]['kept'])->toBe(0);
});
