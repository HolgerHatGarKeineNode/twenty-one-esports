<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The trophies and honours of the player page (2026-10-04)
|--------------------------------------------------------------------------
|
| A player with a 44-character name, two wins (one two days old with its
| prize), a second and a shared third place and a fifth: at 390 and 1440, in
| English and German, as a guest, a signed-in visitor and on the own page,
| nothing is wider than the window, no text box overflows, no name is 0 px,
| the newest win lands (finite animations that finish) and with reduced
| motion nothing moves. A player with one win gets the plate alone, a player
| with none no section. Console, uncaught errors, rejected promises and
| answers >= 400 stay empty on load and after a Livewire roundtrip, with a
| positive control. PRIDE_SHOTS=<dir> writes the screenshots there.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

/** A finished chess cup of `$n` in which `$player` sits on seed `$seed` (seed 1 wins, seed 2 is second, 3 and 4 share third). */
function prideCup(User $player, int $seed, int $n, string $name, CarbonImmutable $startsAt, ?int $prize = null): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, $n);
    TournamentParticipant::query()->where('tournament_id', $tournament->id)->where('seed', $seed)
        ->update(['user_id' => $player->id, 'members' => json_encode([$player->id]), 'name' => $player->displayName()]);
    playOutAsDirector($tournament);
    $tournament->forceFill(['published_at' => $startsAt->subDays(3), 'name' => $name, 'starts_at' => $startsAt])->save();

    if ($prize !== null) {
        TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'pubkey' => $player->pubkey, 'name' => $player->displayName(),
            'place' => $seed, 'amount_sats' => $prize, 'idempotency_key' => TournamentPayout::keyFor($tournament->id, $player->pubkey, $seed), 'status' => PayoutStatus::Paid]);
    }

    return $tournament->refresh();
}

/** Two wins (a recent one with its prize, an old one with a long name), a second and a third place, and a fifth. */
function pridePlayer(string $name = 'Satoshi_Nakamoto_der_Allerlaengste_Name_2140'): User
{
    $player = User::factory()->create(['name' => $name]);
    prideCup($player, 1, 8, '21,000 Sats, Zero Ball Control', CarbonImmutable::now()->subDays(2), 21_000);
    prideCup($player, 1, 4, 'Genesis Blitz Cup at the Halving Party of the Year 2140 in Lugano', CarbonImmutable::now()->subDays(200));
    prideCup($player, 2, 4, 'Halving Blitz Cup', CarbonImmutable::now()->subDays(60), 9_000);
    prideCup($player, 3, 4, 'Mempool Rapid Open', CarbonImmutable::now()->subDays(30));
    prideCup($player, 5, 8, 'Lightning Night Cup', CarbonImmutable::now()->subDays(10));

    return $player;
}

function pridePage(User $player, int $width, int $height, ?User $visitor = null, string $lang = 'en', bool $reducedMotion = false): Page
{
    $page = visit($visitor ? BrowserLogin::url($visitor) : BrowserLogin::LANDING)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);

    if ($reducedMotion) {
        $guid = (new ReflectionProperty(Page::class, 'guid'))->getValue($page);
        iterator_to_array(Client::instance()->execute($guid, 'emulateMedia', ['reducedMotion' => 'reduce']));
    }

    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('players.show', ['npub' => $player->npub, 'lang' => $lang], false)));
    BrowserWait::until($page, '() => window.Alpine && window.Livewire && document.querySelector("[data-test=player-stats]") !== null && document.fonts.status === "loaded"', 10_000);

    return $page;
}

function prideShot(Page $page, string $name): void
{
    $dir = getenv('PRIDE_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

const PRIDE_GEOMETRY = <<<'JS'
    () => {
        const parts = ["[data-test=player-header]", "[data-test=player-trophies]"].map((s) => document.querySelector(s)).filter(Boolean);
        const boxes = parts.flatMap((p) => [...p.querySelectorAll("*")]).filter((el) => el.checkVisibility() && el.children.length === 0 && el.innerText?.trim());
        const overflowing = boxes.filter((el) => el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).textOverflow !== "ellipsis").map((el) => (el.dataset.test || el.tagName) + ": " + el.innerText.slice(0, 30));
        const outside = boxes.filter((el) => { const r = el.getBoundingClientRect(); return r.right > window.innerWidth + 0.5 || r.left < -0.5; }).map((el) => el.innerText.slice(0, 30));
        const zero = [document.querySelector("#ph-name"), ...document.querySelectorAll("[data-test=player-trophy-name]")].filter((el) => { const r = el.getBoundingClientRect(); return r.width < 40 || r.height < 10; }).length;
        const featured = document.querySelector("[data-test=player-trophy-featured]");
        const step = featured?.querySelector(".pt-step");
        return {
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            overflowing, outside, zero,
            honours: [...document.querySelectorAll("[data-test=player-honours] > span")].map((el) => el.innerText.trim()),
            featured: featured ? { place: featured.dataset.place, recent: featured.dataset.recent, words: featured.querySelector("[data-test=player-trophy-place]").innerText, name: featured.querySelector("[data-test=player-trophy-name]").innerText } : null,
            rest: [...document.querySelectorAll("[data-test=player-trophy]")].map((el) => el.dataset.place),
            animations: step ? step.getAnimations().length + featured.querySelector(".pt-words").getAnimations().length : 0,
            finished: step ? step.getAnimations().every((a) => a.playState === "finished") : true,
            trophiesTop: document.querySelector("[data-test=player-trophies]")?.getBoundingClientRect().top ?? null,
            challengeBottom: document.querySelector("[data-test=challenge]")?.getBoundingClientRect().bottom ?? null,
            small: [...document.querySelectorAll("[data-test=player-trophies] a")].filter((a) => a.getBoundingClientRect().height < 44).length,
        };
    }
    JS;

function prideControl(Page $page): void
{
    $page->evaluate('() => { setTimeout(() => { throw new Error("pride-probe"); }); return fetch("/__test/server-error"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("pride-probe")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
}

/** A Livewire roundtrip of the page's first component, then the console and the answers must still be clean. */
function prideRoundtrip(Page $page, string $label): void
{
    $page->evaluate('() => { window.__rt = null; const c = window.Livewire.all()[0]; return c.$wire.$refresh().then(() => { window.__rt = "ok"; }, (e) => { window.__rt = String(e); }); }');
    BrowserWait::until($page, '() => window.__rt !== null', 10_000);
    expect($page->evaluate('() => window.__rt'))->toBe('ok', $label.': roundtrip')
        ->and($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses');
}

test('a player with wins shows honours and trophies at 390 and 1440, English and German, guest, visitor and own page; the newest win lands once', function () {
    $player = pridePlayer();
    $clean = ['overflow' => 0, 'overflowing' => [], 'outside' => [], 'zero' => 0, 'small' => 0];

    $guest = pridePage($player, 1440, 900);
    prideControl($guest);
    BrowserWait::until($guest, '() => document.querySelector("[data-test=player-trophy-featured] .pt-step").getAnimations().every((a) => a.playState === "finished")', 5_000);
    $desk = $guest->evaluate(PRIDE_GEOMETRY);
    prideShot($guest, 'after-1440');
    prideRoundtrip($guest, 'guest 1440');

    $guestPhone = pridePage($player, 390, 844);
    BrowserWait::until($guestPhone, '() => document.querySelector("[data-test=player-trophy-featured] .pt-step").getAnimations().every((a) => a.playState === "finished")', 5_000);
    $guestGeometry = $guestPhone->evaluate(PRIDE_GEOMETRY);
    prideShot($guestPhone, 'after-390');

    $visitor = pridePage($player, 390, 844, User::factory()->create(), 'de');
    $german = $visitor->evaluate(PRIDE_GEOMETRY);
    prideRoundtrip($visitor, 'visitor de 390');

    $own = pridePage($player, 390, 844, $player);
    BrowserWait::until($own, '() => document.querySelector("[data-test=player-trophy-featured] .pt-step").getAnimations().every((a) => a.playState === "finished")', 5_000);
    $phone = $own->evaluate(PRIDE_GEOMETRY);
    prideShot($own, 'after-390-own');
    prideRoundtrip($own, 'own 390');

    $still = pridePage($player, 1440, 900, reducedMotion: true);
    $calm = $still->evaluate(PRIDE_GEOMETRY);

    expect($desk)->toMatchArray($clean)->and($german)->toMatchArray($clean)->and($phone)->toMatchArray($clean)
        ->and($desk['honours'])->toBe(['2 tournament wins', '2× 2nd or 3rd place', "30\u{00A0}000 sats won"])
        ->and($german['honours'])->toBe(['2 Turniersiege', '2× Platz 2 oder 3', "30\u{00A0}000 Sats gewonnen"])
        ->and($desk['featured'])->toMatchArray(['place' => '1', 'recent' => '1', 'name' => '21,000 Sats, Zero Ball Control'])
        ->and($desk['featured']['words'])->toStartWith('Tournament win, 2 days ago')
        ->and($german['featured']['words'])->toStartWith('Turniersieg, vor 2 Tagen')
        // Newest first: the shared third (30 days), the second (60), the old win (200); the fifth place is none.
        ->and($desk['rest'])->toBe(['3', '2', '1'])
        // The landing ran (rise, sheen, words) and finished; nothing loops.
        ->and($desk['animations'])->toBeGreaterThanOrEqual(2)->and($desk['finished'])->toBeTrue()
        ->and($calm['animations'])->toBe(0)
        // The trophies follow the header: at 1440 they start in the first screen.
        ->and($desk['trophiesTop'])->toBeLessThan(900)
        // A guest on a phone still reaches "Challenge" in the first screen, under the honours.
        ->and($guestGeometry)->toMatchArray($clean)
        ->and($guestGeometry['challengeBottom'])->not->toBeNull()->toBeLessThan(844);
});

test('a player with one win gets the plate alone and a player with none no trophies, as a guest at 390', function () {
    $one = User::factory()->create(['name' => 'Uwe']);
    prideCup($one, 1, 4, '21,000 Sats, Zero Ball Control', CarbonImmutable::now()->subDays(1), 21_000);
    $none = User::factory()->create(['name' => 'Fresh Pleb']);

    $single = pridePage($one, 390, 844);
    prideControl($single);
    $m = $single->evaluate(PRIDE_GEOMETRY);
    prideShot($single, 'after-390-one-win');
    prideRoundtrip($single, 'one win');

    $empty = pridePage($none, 390, 844);
    $n = $empty->evaluate(PRIDE_GEOMETRY);
    prideRoundtrip($empty, 'none');

    expect($m)->toMatchArray(['overflow' => 0, 'overflowing' => [], 'outside' => [], 'zero' => 0, 'rest' => [], 'honours' => ['1 tournament win', "21\u{00A0}000 sats won"]])
        ->and($m['featured']['recent'])->toBe('1')
        ->and($n)->toMatchArray(['overflow' => 0, 'overflowing' => [], 'outside' => [], 'featured' => null, 'honours' => [], 'trophiesTop' => null]);
});
