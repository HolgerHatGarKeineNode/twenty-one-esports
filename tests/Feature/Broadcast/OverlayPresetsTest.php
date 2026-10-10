<?php

use App\Enums\OverlayVariant;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\OverlayPreset;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Broadcast\OverlaySnapshot;
use App\Support\Prizes\PrizePool;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| OBS overlay presets and their secret URLs (plan "OBS-Broadcast-Overlays", P2)
|--------------------------------------------------------------------------
|
| An admin creates, edits, rotates and deletes presets on /admin/overlays; the URL `/broadcast/{token}` is shown once
| and only the token's SHA-256 is stored. A wrong, rotated or deleted token answers 404, no session needed. The
| snapshot carries public data only (keys asserted), pots only above zero and only with the `pots` module.
|
*/

function overlayAdmin(string $locale = 'en'): User
{
    $user = User::factory()->create(['locale' => $locale]);
    Admin::query()->create(['pubkey' => $user->pubkey]);

    return $user;
}

/** The token at the end of a revealed overlay URL. */
function overlayToken(string $url): string
{
    expect($url)->toMatch('#/broadcast/[A-Za-z0-9]{48}$#');

    return substr($url, -48);
}

/**
 * Every key at every depth of a decoded JSON document.
 *
 * @param  array<mixed>  $data
 * @return list<string>
 */
function overlayKeys(array $data): array
{
    $keys = [];

    foreach ($data as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($value)) {
            $keys = [...$keys, ...overlayKeys($value)];
        }
    }

    return $keys;
}

test('the overlay page is for admins only and listed in the admin nav', function () {
    $this->get(route('admin.overlays'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.overlays'))->assertForbidden();

    $this->actingAs(overlayAdmin())->get(route('admin.overlays'))->assertOk()
        ->assertSee('data-test="admin-overlays"', false)
        ->assertSee('data-test="admin-nav-overlays"', false)
        ->assertSee('Shutdown source when not visible');

    Livewire::actingAs(User::factory()->create())->test('pages::admin.overlays')->assertForbidden();
});

test('creating a preset shows its URL once, stores only the hash, and the URL opens the overlay without a session', function () {
    $admin = overlayAdmin();

    $page = Livewire::actingAs($admin)->test('pages::admin.overlays')
        ->set('name', 'Laptop stream')->set('variant', 'league-live')->set('locale', 'en')
        ->set('modules.cam-frame', true)->set('modules.ads', false)
        ->call('save')->assertHasNoErrors()
        ->assertSee('data-test="overlay-url"', false);

    $url = $page->get('revealedUrl');
    $token = overlayToken($url);
    $preset = OverlayPreset::query()->sole();

    expect($preset->token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode($preset->getAttributes()))->not->toContain($token)
        ->and($preset->toArray())->not->toHaveKey('token_hash')
        ->and($preset->created_by_id)->toBe($admin->id)
        ->and($preset->variant)->toBe(OverlayVariant::LeagueLive)
        ->and($preset->moduleStates())->toMatchArray(['cam-frame' => true, 'ads' => false, 'ticker' => true]);

    // Shown once: the next action clears it, and the list never prints it.
    $page->call('dismiss')->assertSet('revealedUrl', '')->assertDontSee($token);
    $this->actingAs($admin)->get(route('admin.overlays'))->assertOk()->assertDontSee($token)->assertSee('Laptop stream');

    // A guest without any cookie opens it: no session, noindex, no referrer, nothing set.
    auth()->logout();
    $response = $this->get('/broadcast/'.$token)->assertOk()
        ->assertSee('data-test="broadcast-overlay"', false)
        ->assertSee('data-variant="league-live"', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->getCookies())->toBe([])
        ->and($response->getContent())->toContain('/build/assets/overlay-')->not->toContain('csrf-token');
});

test('rotating issues a new URL and the old one answers 404; wrong and deleted tokens too', function () {
    $admin = overlayAdmin();
    $page = Livewire::actingAs($admin)->test('pages::admin.overlays')->set('name', 'Cup night')->call('save');
    $old = overlayToken($page->get('revealedUrl'));
    $preset = OverlayPreset::query()->sole();

    $this->get('/broadcast/'.$old)->assertOk();
    $this->get('/broadcast/'.$old.'/snapshot.json')->assertOk();

    $new = overlayToken($page->call('rotate', $preset->id)->get('revealedUrl'));

    expect($new)->not->toBe($old)->and($preset->refresh()->rotated_at)->not->toBeNull();
    $this->get('/broadcast/'.$old)->assertNotFound();
    $this->get('/broadcast/'.$old.'/snapshot.json')->assertNotFound();
    $this->get('/broadcast/'.$new)->assertOk();
    $this->get('/broadcast/'.str_repeat('A', 48))->assertNotFound();
    $this->get('/broadcast/short')->assertNotFound();

    $page->call('remove', $preset->id);
    expect(OverlayPreset::query()->count())->toBe(0);
    $this->get('/broadcast/'.$new)->assertNotFound();
});

test('editing keeps the URL; a tournament variant needs a public tournament that is not over', function () {
    $admin = overlayAdmin();
    $open = Tournament::factory()->signup()->create(['published_at' => now()]);
    $draft = Tournament::factory()->create();
    $finished = Tournament::factory()->create(['status' => TournamentStatus::Finished, 'published_at' => now()]);

    $page = Livewire::actingAs($admin)->test('pages::admin.overlays')->set('name', 'Cup')->set('variant', 'tournament')
        ->call('save')->assertHasErrors('tournament')
        ->set('tournament', $draft->id)->call('save')->assertHasErrors('tournament')
        ->set('tournament', $finished->id)->call('save')->assertHasErrors('tournament')
        ->set('tournament', $open->id)->call('save')->assertHasNoErrors();
    $token = overlayToken($page->get('revealedUrl'));
    $preset = OverlayPreset::query()->sole();

    expect($preset->tournament_id)->toBe($open->id);

    $page->call('edit', $preset->id)->assertSet('name', 'Cup')->assertSet('tournament', $open->id)
        ->set('name', 'Cup renamed')->set('variant', 'league-live')->set('locale', 'de')->call('save')->assertHasNoErrors()->assertSet('revealedUrl', '');

    expect($preset->refresh()->name)->toBe('Cup renamed')
        ->and($preset->tournament_id)->toBeNull()
        ->and($preset->locale)->toBe('de')
        ->and($preset->token_hash)->toBe(hash('sha256', $token));
});

test('a preset whose tournament finished can still be saved; break and bracket may pick a finished public tournament, never a draft', function () {
    $admin = overlayAdmin();
    $open = Tournament::factory()->signup()->create(['published_at' => now()]);
    $finished = Tournament::factory()->create(['status' => TournamentStatus::Finished, 'published_at' => now()]);
    $unpublished = Tournament::factory()->create(['status' => TournamentStatus::Finished, 'published_at' => null]);
    $preset = OverlayPreset::factory()->create(['name' => 'Cup', 'variant' => OverlayVariant::Tournament, 'tournament_id' => $open->id]);
    $open->forceFill(['status' => TournamentStatus::Finished])->save();

    // Its own tournament, now finished, stays choosable and saves.
    Livewire::actingAs($admin)->test('pages::admin.overlays')->call('edit', $preset->id)
        ->set('name', 'Cup final')->call('save')->assertHasNoErrors();
    expect($preset->refresh()->name)->toBe('Cup final')->and($preset->tournament_id)->toBe($open->id);

    // Another finished tournament is no new pick for the tournament overlay, but is for the full-screen bracket.
    Livewire::actingAs($admin)->test('pages::admin.overlays')->call('edit', $preset->id)
        ->set('tournament', $finished->id)->call('save')->assertHasErrors('tournament')
        ->set('variant', 'bracket')->call('save')->assertHasNoErrors();
    expect($preset->refresh()->tournament_id)->toBe($finished->id);

    foreach (['bracket', 'break'] as $variant) {
        Livewire::actingAs($admin)->test('pages::admin.overlays')->set('name', 'New '.$variant)->set('variant', $variant)
            ->set('tournament', $unpublished->id)->call('save')->assertHasErrors('tournament')
            ->set('tournament', $finished->id)->call('save')->assertHasNoErrors();
        expect(OverlayPreset::query()->where('name', 'New '.$variant)->sole()->tournament_id)->toBe($finished->id);
    }
});

test('the snapshot carries public data only: no key, npub, email, Lightning address, game account or picture ref', function () {
    $player = User::factory()->create(['name' => 'Satoshi Fan', 'gamer_tags' => ['rocket-league' => 'secret-gamer-tag'], 'lud16' => 'secret@wallet.example']);
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $tournament->forceFill(['published_at' => now()])->save();
    playOutAsDirector($tournament);
    $open = Tournament::factory()->signup()->create(['published_at' => now(), 'signup_closes_at' => now()->addDay(), 'name' => 'Open cup']);
    TournamentSignup::query()->create(['tournament_id' => $open->id, 'user_id' => $player->id, 'name' => 'Satoshi Fan', 'members' => [$player->id]]);
    OverlayPreset::factory()->withToken($token = str_repeat('a', 48))->create(['variant' => OverlayVariant::Tournament, 'tournament_id' => $tournament->id, 'locale' => 'en']);

    $data = $this->getJson('/broadcast/'.$token.'/snapshot.json')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->json();
    $json = json_encode($data);
    $keys = overlayKeys($data);

    expect(array_keys($data))->toBe(['generatedAt', 'preset', 'site', 'upcoming', 'nextCup', 'pride', 'stats', 'tournament', 'games', 'recent', 'ticker', 'break'])
        ->and(array_intersect($keys, OverlaySnapshot::PRIVATE_KEYS))->toBe([])
        ->and(array_filter($keys, fn (string $key): bool => str_ends_with($key, 'Ref') || str_ends_with($key, 'Refs')))->toBe([])
        ->and($data['tournament']['name'])->toBe($tournament->name)
        ->and($data['tournament']['stages'])->not->toBe([])
        ->and($data['tournament']['champion'])->not->toBeNull()
        ->and($data['upcoming'][0]['name'])->toBe('Open cup')
        ->and($data['pride']['signups'][0]['name'] ?? null)->toBe('Satoshi Fan')
        ->and($data['site']['qr'])->toContain('<svg')
        ->and($data['ticker'])->not->toBe([]);

    foreach ([$player->pubkey, (string) $player->npub, 'secret-gamer-tag', 'wallet.example', ...User::query()->pluck('pubkey')->all()] as $secret) {
        expect($json)->not->toContain($secret);
    }
});

test('a pot shows only above zero and only with the pots module', function () {
    $tournament = Tournament::factory()->signup()->create(['published_at' => now(), 'signup_closes_at' => now()->addDay(), 'name' => 'Pot cup', 'pot_source' => Tournament::POT_LEAGUE]);
    OverlayPreset::factory()->withToken($token = str_repeat('b', 48))->create(['locale' => 'en']);
    OverlayPreset::factory()->withToken($noPots = str_repeat('c', 48))->create(['locale' => 'en', 'modules' => ['pots' => false] + OverlayPreset::MODULES]);

    // A league pot nothing came into yet: zero, so nothing.
    $zero = $this->getJson('/broadcast/'.$token.'/snapshot.json')->json();
    expect(app(PrizePool::class)->potSats($tournament))->toBe(0)
        ->and($zero['upcoming'][0]['name'])->toBe('Pot cup')
        ->and($zero['upcoming'][0]['pot'])->toBeNull()
        ->and(json_encode($zero['ticker']))->not->toContain('Prize pot');

    cache()->flush();
    $tournament->forceFill(['prize_target_sats' => 21_000])->save();
    $some = $this->getJson('/broadcast/'.$token.'/snapshot.json')->json();
    expect($some['upcoming'][0]['pot'])->toBe(21_000)
        ->and(collect($some['ticker'])->firstWhere('head', 'Prize pot')['text'] ?? null)->toContain('21');

    $off = $this->getJson('/broadcast/'.$noPots.'/snapshot.json')->json();
    expect($off['upcoming'][0]['pot'])->toBeNull()
        ->and($off['pride']['prizes'] ?? null)->toBeNull()
        ->and(json_encode($off['ticker']))->not->toContain('Prize pot');
});

test('the overlay speaks its preset\'s language, whatever the request asks', function () {
    OverlayPreset::factory()->withToken($de = str_repeat('d', 48))->create(['locale' => 'de']);
    OverlayPreset::factory()->withToken($en = str_repeat('e', 48))->create(['locale' => 'en']);

    $this->withHeader('Accept-Language', 'en')->get('/broadcast/'.$de)->assertOk()->assertSee('<html lang="de"', false)->assertSee('Mitspielen');
    $this->withHeader('Accept-Language', 'de')->get('/broadcast/'.$en)->assertOk()->assertSee('<html lang="en"', false)->assertSee('Join in');
    expect(app()->getLocale())->toBe(config('app.locale'));
});
