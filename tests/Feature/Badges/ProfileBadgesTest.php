<?php

use App\Models\NostrEvent;
use App\Models\RankBadge;
use App\Models\Rating;
use App\Models\User;
use App\Support\Badges\ProfileBadges;
use App\Support\Badges\ProfileBadgesRefused;
use App\Support\Badges\RankBadges;
use App\Support\Nostr\RejectedEvent;
use Tests\Support\TestSigner;

/**
 * "Show on my Nostr profile" (P11, NIP "Rank badges", Profile): the rank
 * badge's (a, e) pair goes into the player's kind 10008, built from the
 * newest list, every existing entry kept.
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

/** Two badges from another issuer, as some other client wrote them, plus a private part. */
function existingProfileList(TestSigner $signer, int $ago = 3600): array
{
    return $signer->sign(ProfileBadges::KIND, [
        ['a', '30009:'.str_repeat('a', 64).':bravery'], ['e', str_repeat('1', 64), 'wss://relay.example'],
        ['a', '30009:'.str_repeat('b', 64).':honor'], ['e', str_repeat('2', 64)],
    ], 'nip44-private-items', now()->getTimestamp() - $ago);
}

function signedLike(TestSigner $signer, array $template): array
{
    return $signer->sign($template['kind'], $template['tags'], $template['content'], max($template['created_at'], now()->getTimestamp()));
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
        // A second time is refused: one entry per definition.
        ->and(fn () => $profiles->prepare($this->user, $this->badge, [], true))->toThrow(ProfileBadgesRefused::class, 'already');
});

test('without any list the new one holds only this badge, and a deprecated 30008 is merged in', function () {
    $profiles = app(ProfileBadges::class);

    expect($profiles->prepare($this->user, $this->badge, [], true)['template']['tags'])->toBe([...$this->pair, ['alt', 'Profile badges']]);

    $legacy = $this->signer->sign(ProfileBadges::LEGACY_KIND, [['d', 'profile_badges'], ['a', '30009:'.str_repeat('c', 64).':old'], ['e', str_repeat('3', 64)]]);

    expect($profiles->prepare($this->user, $this->badge, [$legacy], true)['template']['tags'])
        ->toBe([['a', '30009:'.str_repeat('c', 64).':old'], ['e', str_repeat('3', 64)], ...$this->pair, ['alt', 'Profile badges']]);
});

test('no relay reached and no archived list: refused, so nothing can overwrite the real list', function () {
    $profiles = app(ProfileBadges::class);

    expect(fn () => $profiles->prepare($this->user, $this->badge, [], false))->toThrow(ProfileBadgesRefused::class, 'could not be read');

    // A list the league archived earlier is enough.
    $profiles->prepare($this->user, $this->badge, [existingProfileList($this->signer)], true);
    expect($profiles->prepare($this->user, $this->badge, [], false)['kept'])->toBe(2);
});

test('lists of another author, with a broken signature, or of another kind are ignored', function () {
    $stranger = new TestSigner;
    $forged = existingProfileList($this->signer);
    $forged['sig'] = str_repeat('0', 128);
    $follows = $this->signer->sign(3, [['p', $stranger->pubkey]]);

    $prepared = app(ProfileBadges::class)->prepare($this->user, $this->badge, [existingProfileList($stranger), $forged, $follows, 'junk'], true);

    expect($prepared['kept'])->toBe(0)
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
