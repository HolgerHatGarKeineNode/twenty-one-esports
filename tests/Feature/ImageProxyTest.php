<?php

use App\Models\User;
use App\Support\ImageProxy;
use App\Support\Nostr\PlayerProfile;
use App\Support\Seo\StructuredData;

/*
|--------------------------------------------------------------------------
| Foreign avatars through the group image proxy (performance plan P5)
|--------------------------------------------------------------------------
|
| `esports.image_proxy_url` set: a kind-0 picture is served through the
| proxy, the 96 px `avatar` cut up to 48 px and the 192 px `avatar-lg` above. Empty: the
| browser loads it from its own host, as before. Our uploads and the
| generated Blockpile never go through it.
|
*/

const IMAGE_PROXY_TEST_BASE = 'https://group.example.test/img';

test('without a proxy a foreign avatar is loaded from its own host', function () {
    config(['esports.image_proxy_url' => '']);
    $player = User::factory()->create(['name' => 'max', 'picture' => 'https://example.com/max.png']);

    $html = (string) $this->blade('<x-avatar :user="$user" :size="28" />', ['user' => $player]);

    expect($html)->toContain('src="https://example.com/max.png"')
        ->not->toContain('group.example.test');
    $this->get(route('rules'))->assertOk()
        ->assertDontSee('name="image-proxy"', false)
        ->assertDontSee('rel="preconnect" href="https://group.example.test"', false);
});

test('with a proxy a foreign avatar comes through it, cut for its size, and keeps the Blockpile fallback', function () {
    config(['esports.image_proxy_url' => IMAGE_PROXY_TEST_BASE]);
    $player = User::factory()->create(['name' => 'max', 'picture' => 'https://example.com/max face.png?x=1&y=2']);
    $encoded = rawurlencode('https://example.com/max face.png?x=1&y=2');

    $small = (string) $this->blade('<x-avatar :user="$user" :size="48" />', ['user' => $player]);
    $large = (string) $this->blade('<x-avatar :user="$user" :size="96" />', ['user' => $player]);

    expect($small)->toContain('src="'.IMAGE_PROXY_TEST_BASE.'/avatar?src='.$encoded.'"')
        ->toContain('data-fallback="'.route('avatars.generated', ['pubkey' => $player->pubkey, 'v' => 1]).'"')
        ->toContain('onerror="this.onerror=null;this.src=this.dataset.fallback;');
    expect($large)->toContain('src="'.IMAGE_PROXY_TEST_BASE.'/avatar-lg?src='.$encoded.'"');
    $huge = (string) $this->blade('<x-avatar :user="$user" :size="128" />', ['user' => $player]);
    expect($huge)->toContain('src="'.IMAGE_PROXY_TEST_BASE.'/msg?src='.$encoded.'"');
    // The browser sizes the late pictures itself (profiles.js): the store hands it the original.
    expect($player->avatarUrl())->toBe(IMAGE_PROXY_TEST_BASE.'/avatar?src='.$encoded)
        ->and($player->avatarSource())->toBe('https://example.com/max face.png?x=1&y=2');
    $this->get(route('rules'))->assertOk()
        ->assertSee('<meta name="image-proxy" content="'.IMAGE_PROXY_TEST_BASE.'">', false)
        ->assertSee('<link rel="preconnect" href="https://group.example.test">', false);
});

test('our own upload, the generated avatar and an old http picture never go through the proxy', function () {
    config(['esports.image_proxy_url' => IMAGE_PROXY_TEST_BASE, 'app.url' => 'https://esports.example.test']);
    $uploaded = User::factory()->create(['picture' => 'https://example.com/max.png', 'avatar_path' => 'avatars/max.webp']);
    $none = User::factory()->create(['picture' => null]);
    $http = User::factory()->create(['picture' => 'http://example.com/max.png']);

    expect($uploaded->avatarUrl())->not->toContain('group.example.test')->toEndWith('avatars/max.webp')
        ->and($none->avatarUrl())->toBeNull()
        ->and($http->avatarUrl())->toBeNull()
        ->and(ImageProxy::avatar('https://esports.example.test/storage/avatars/a.webp'))->toBe('https://esports.example.test/storage/avatars/a.webp')
        ->and(ImageProxy::avatar('https://cdn.example.com/a.webp'))->toStartWith(IMAGE_PROXY_TEST_BASE.'/avatar?src=')
        ->and(ImageProxy::avatar(IMAGE_PROXY_TEST_BASE.'/avatar?src=x'))->toBe(IMAGE_PROXY_TEST_BASE.'/avatar?src=x');

    $html = (string) $this->blade('<x-avatar :user="$user" :size="28" />', ['user' => $none]);
    expect($html)->not->toContain('group.example.test');
});

test('the profile page tells crawlers the original picture, not the small proxy cut', function () {
    config(['esports.image_proxy_url' => IMAGE_PROXY_TEST_BASE]);
    $player = User::factory()->create(['name' => 'max', 'picture' => 'https://example.com/max.png']);

    $person = StructuredData::profilePage(PlayerProfile::for($player), route('players.show', $player->npub));

    expect(json_encode($person))->toContain('"image":"https:\/\/example.com\/max.png"')->not->toContain('group.example.test');
});
