<?php

/*
|--------------------------------------------------------------------------
| Proof of Pong's pages and switch (plan "Proof of Pong", P1)
|--------------------------------------------------------------------------
|
| Off (the default), the game has no route and the registry does not know it. On, the lobby offers the four bot
| levels, and a logged-in player gets the full-screen game page with its seed, level, rules and texts; a guest is
| sent to the login. Every text of the page has a German translation.
|
*/

use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Games\ProofOfPong;
use App\Http\Controllers\PongController;
use App\Models\PongInvite;
use App\Models\User;
use App\Support\Invites\InviteGames;
use App\Support\Pong\PongBot;
use App\Support\Pong\PongCast;
use App\Support\Pong\PongInvites;
use Illuminate\Support\Facades\Route;
use Tests\Support\PongOn;

beforeEach(function () {
    $this->withoutVite();
});

test('switched off, the game has no route and is not in the registry', function () {
    expect(config('esports.pong.enabled'))->toBeFalse()
        ->and(app(GameRegistry::class)->find(ProofOfPong::SLUG))->toBeNull()
        ->and(Route::has('pong.index'))->toBeFalse()
        ->and(Route::has('pong.bot'))->toBeFalse();

    $this->get('/proof-of-pong')->assertNotFound();
    $this->actingAs(User::factory()->create())->get('/proof-of-pong/bot?level=2')->assertNotFound();
});

test('switched on, the registry knows the game as its own kind, and no invite or other kind\'s list takes it', function () {
    PongOn::play();
    $registry = app(GameRegistry::class);

    expect($registry->find(ProofOfPong::SLUG))->toBeInstanceOf(ProofOfPong::class)
        ->and($registry->get(ProofOfPong::SLUG)->kind())->toBe(GameKind::Arcade)
        ->and($registry->boards())->not->toHaveKey(ProofOfPong::SLUG)
        ->and($registry->series())->not->toHaveKey(ProofOfPong::SLUG)
        ->and($registry->scores())->not->toHaveKey(ProofOfPong::SLUG)
        ->and(app(InviteGames::class)->all())->not->toHaveKey(ProofOfPong::SLUG);
});

test('the lobby offers the five bots and every figure, and opens the game in a new tab', function () {
    PongOn::play();

    $this->get(route('pong.index'))->assertOk()
        ->assertSee('data-test="pong-play-form"', false)
        ->assertSee('target="_blank"', false)
        ->assertSeeInOrder(['Nocoiner Uncle', 'Shitcoiner', 'Goldbug Peter Schiff', 'Madame CBDC Schnabel', 'Madame Brrr Lagarde'])
        ->assertSeeInOrder(array_map(fn (string $id): string => 'data-test="pong-figure-'.$id.'"', PongCast::playerIds()), false)
        ->assertSee(route('pong.bot'), false);
});

test('on a phone the lobby opens on the live 1v1 when something waits there for the viewer, else on the bot (P9)', function () {
    PongOn::play();
    $live = 'data-test="pong-way-live"';
    $segment = fn (string $html): string => (string) preg_replace('/.*(<button[^>]*'.preg_quote($live, '/').'[^>]*>).*/s', '$1', $html);

    $guest = (string) $this->get(route('pong.index'))->assertOk()->getContent();
    $quiet = (string) $this->actingAs(User::factory()->create())->get(route('pong.index'))->getContent();
    $looking = (string) $this->actingAs(User::factory()->create(['looking_to_play' => PongInvites::LOOKING]))->get(route('pong.index'))->getContent();
    $invited = User::factory()->create();
    PongInvite::factory()->create(['invitee_id' => $invited->id]);
    $waiting = (string) $this->actingAs($invited)->get(route('pong.index'))->getContent();

    expect(array_map(fn (string $html): bool => str_contains($segment($html), 'aria-pressed="true"'), [$guest, $quiet, $looking, $waiting]))->toBe([false, false, true, true]);
});

test('a logged-in player gets the full-screen game page with seed, level, rules and texts; a guest logs in first', function () {
    PongOn::play();

    $this->get(route('pong.bot', ['level' => 3]))->assertRedirect(route('login'));

    $html = (string) $this->actingAs(User::factory()->create())->get(route('pong.bot', ['level' => 4, 'seed' => 4242]))
        ->assertOk()
        ->assertSee('data-test="pong-match"', false)
        ->assertDontSee('data-test="shell', false)
        ->assertSee('/hyper/vendor/three.min.js', false)
        ->getContent();
    preg_match('#<script type="application/json" id="pong-config">(.*?)</script>#s', $html, $found);
    $config = json_decode($found[1], true, flags: JSON_THROW_ON_ERROR);

    expect($config['seed'])->toBe(4242)
        ->and($config['level'])->toBe(4)
        ->and($config['bot'])->toBe('Madame Brrr Lagarde')
        ->and($config['rules'])->toBe(['points_to_win' => 21, 'win_by' => 2, 'event_block_rallies' => 21])
        ->and(array_keys($config['texts']))->toBe(PongController::TEXTS);

    // Without a seed every game draws its own; a level out of range is refused.
    $this->get(route('pong.bot'))->assertOk()->assertSee('data-level="1"', false);
    $this->get(route('pong.bot', ['level' => 5]))->assertRedirect();
    $this->get(route('pong.bot', ['seed' => -1]))->assertRedirect();
});

test('every text of the pages is a key the scripts use and has a German translation', function () {
    PongOn::play();
    $keys = [];
    $live = [];
    foreach (glob(resource_path('js/pong/*.js')) ?: [] as $file) {
        preg_match_all("/(?<![\\w.$])t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $found);
        // The live match (P2) has texts of its own: its page carries PongController::LIVE_TEXTS.
        if (basename($file) === 'live.js') {
            array_push($live, ...$found[1]);
        } else {
            array_push($keys, ...$found[1]);
        }
    }
    $blade = [];
    foreach ([resource_path('views/pong/match.blade.php'), resource_path('views/pong/live.blade.php'), resource_path('views/pages/pong/index.blade.php'), resource_path('views/components/⚡pong-lobby.blade.php'), resource_path('views/pages/matches/partials/pong-row.blade.php'), resource_path('views/pong/partials/figures.blade.php'), resource_path('views/pong/partials/settings.blade.php'), resource_path('views/pong/partials/settings-button.blade.php')] as $file) {
        preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $found);
        array_push($blade, ...$found[1]);
    }
    $german = json_decode((string) file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);
    $names = array_column(PongBot::LEVELS, 'name');

    expect(array_values(array_unique($keys)))->toEqualCanonicalizing(PongController::TEXTS)
        ->and(array_values(array_unique([...$keys, ...$live])))->toEqualCanonicalizing(PongController::LIVE_TEXTS)
        ->and(array_values(array_diff([...PongController::LIVE_TEXTS, ...$blade, ...$names, ...array_keys(PongCast::texts())], array_keys($german))))->toBe([]);

    app()->setLocale('de');
    $this->actingAs(User::factory()->create())->get(route('pong.bot', ['level' => 1]))->assertOk()
        ->assertSee('Nocoiner-Onkel')->assertSee('Spiel starten')->assertSee('Ballwechsel :n');
});
