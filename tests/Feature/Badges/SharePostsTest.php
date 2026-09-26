<?php

use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Cards\SharePosts;
use App\Support\Cards\ShareRefused;
use App\Support\Nostr\RejectedEvent;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestSigner;

/**
 * The share button (P11): a kind 1 note, signed by the player, with the share
 * card's URL and a NIP-92 imeta; only the player's own moments.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->season = openSeason(['slug' => 'pre-season']);
    $this->signer = new TestSigner;
    $this->user = User::factory()->withPubkey($this->signer->pubkey)->create(['name' => 'satsjäger']);
    $this->moments = shareMoments($this->user, $this->season);
});

function signShare(TestSigner $signer, array $template): array
{
    return $signer->sign($template['kind'], $template['tags'], $template['content'], max($template['created_at'], now()->getTimestamp()));
}

test('each moment becomes a signed kind 1 with the card in the content and in an imeta', function () {
    $posts = app(SharePosts::class);
    $moments = [
        ['rank-up', (string) $this->moments['versions'][1]->id, 'Ranked up to Gold II in Chess blitz on TWENTY ONE Esports.'],
        ['block', (string) $this->moments['block']->id, 'Mined block 2 on the TWENTY ONE Esports season chain: +5'."\u{00A0}".'000 sats.'],
        ['tournament', (string) $this->moments['tournament']->id, 'Won Testnet Cup on TWENTY ONE Esports.'],
        ['wrapped', 'pre-season', 'My Pre-Season on TWENTY ONE Esports: 2 blocks mined, 10'."\u{00A0}".'000 sats.'],
    ];

    foreach ($moments as [$type, $id, $sentence]) {
        $template = $posts->prepare($this->user, $type, $id);
        $card = $posts->card($this->user, $type, $id)->url('wide');
        $stored = $posts->submit($this->user, $type, $id, signShare($this->signer, $template));

        expect($stored->kind)->toBe(1)
            ->and($stored->pubkey)->toBe($this->user->pubkey)
            ->and($stored->queued_at)->not->toBeNull()
            ->and($stored->payload()['content'])->toBe($sentence."\n\n".$card."\n".config('app.url').'/players/'.$this->user->npub)
            ->and($stored->payload()['tags'][0])->toBe(['imeta', 'url '.$card, 'm image/png', 'dim 1200x630', 'alt '.$sentence]);

        // The card the post links is there for everyone.
        $this->get(substr($card, strlen(config('app.url'))))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    expect(NostrEvent::query()->where('kind', 1)->count())->toBe(4);
});

test('someone else\'s moment, a changed text and a note by another key are refused', function () {
    $stranger = User::factory()->create();
    $posts = app(SharePosts::class);
    $template = $posts->prepare($this->user, 'block', (string) $this->moments['block']->id);

    expect(fn () => $posts->prepare($stranger, 'block', (string) $this->moments['block']->id))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare($stranger, 'rank-up', (string) $this->moments['versions'][1]->id))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare($stranger, 'tournament', (string) $this->moments['tournament']->id))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare($this->user, 'nonsense', '1'))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->submit($this->user, 'block', (string) $this->moments['block']->id, signShare($this->signer, [...$template, 'content' => 'gm'])))->toThrow(RejectedEvent::class)
        ->and(fn () => $posts->submit($this->user, 'block', (string) $this->moments['block']->id, signShare(new TestSigner, $template)))->toThrow(RejectedEvent::class)
        ->and(NostrEvent::query()->where('kind', 1)->count())->toBe(0);
});

test('shares are limited per hour', function () {
    config(['esports.badges.shares_per_hour' => 2]);
    $posts = app(SharePosts::class);
    $share = fn () => $posts->submit($this->user, 'wrapped', 'pre-season', signShare($this->signer, $posts->prepare($this->user, 'wrapped', 'pre-season')));

    $share();
    $this->travel(1)->seconds();
    $share();
    $this->travel(1)->seconds();

    expect($share)->toThrow(ShareRefused::class, 'a lot')
        ->and(NostrEvent::query()->where('kind', 1)->count())->toBe(2);
});
