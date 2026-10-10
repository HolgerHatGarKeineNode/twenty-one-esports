<?php

use App\Enums\ChessEndReason;
use App\Enums\IncomingPaymentStatus;
use App\Enums\StackerRunStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Jobs\VerifyStackerRun;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\ChessMove;
use App\Models\IncomingPayment;
use App\Models\Rating;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Pest\Browser\Support\ComputeUrl;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\BlockfillOn;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\HyperOn;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\PongOn;
use Tests\Support\TestSigner;

/*
| The revamped start page and login (plan "Refactor und Design-Revamp", P3; boards Main, HomeGuest, HomePhone,
| HomeGuestPhone, Login, LoginPhone) in a league that looks like the canvas: every game switched on, a featured
| organizer tournament with a pot, a Blockfill week, casual cups, a running and a finished daily game, Blockli
| games, checked runs, a cup winner and a reserve zap. At 390 and 1440: the console and every response stay quiet,
| nothing runs past the page or out of its box, and the main parts are where the boards put them.
| DESIGN_SHOTS=1 also saves full-page screenshots for the side-by-side with the boards.
*/

beforeEach(function () {
    config(['session.driver' => 'database', 'esports.league.nsec' => (new TestSigner)->secret]);
    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
    $this->app->instance(Verifier::class, new FakeStackerVerifier);
});

/** A user with a display name. */
function revampPlayer(string $name): User
{
    return User::factory()->create(['name' => $name]);
}

/**
 * The canvas league with factory data; returns the admin who plays in it.
 */
function revampLeague(): User
{
    BlockfillOn::play();
    BlockliOn::play();
    NineMensMorrisOn::play();
    HyperOn::play();
    PongOn::play();
    tmnfOn();
    config(['esports.board_games.games.checkers.enabled' => true]);
    app()->forgetInstance(GameRegistry::class);
    leagueWeeksApproved(Blockfill::SLUG);

    $names = ['TickTrickSat', 'Stefan', 'markusturm', 'Nidan21', 'DerCaddy', 'Erdöpfökasbrot', 'floatYaBoat', 'Lotte', 'aHeck13', 'El Presidento Ben'];
    $p = [];
    foreach ($names as $name) {
        $p[$name] = revampPlayer($name);
    }
    $admin = $p['Erdöpfökasbrot'];
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    // The featured organizer tournament with its pot (rule R9).
    $cup = openTournament([
        'name' => 'Blockli Cup #1: Das erste Blockli-Turnier', 'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::RoundRobin,
        'options' => FormatOptions::defaults(GameProfile::for('blockli', 'correspondence'))->toArray(), 'capacity' => 12, 'starts_at' => now()->addDays(4)->setTime(17, 0),
    ]);
    $cup->forceFill(['pool_opened_at' => now(), 'pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 21_000, 'pot_balance_at' => now(), 'prize_target_sats' => 21_000])->save();
    foreach ([...User::factory()->count(6)->create()->all(), $p['markusturm'], $p['DerCaddy']] as $user) {
        TournamentSignup::query()->create(['tournament_id' => $cup->id, 'user_id' => $user->id, 'name' => $user->displayName(), 'members' => [$user->id]]);
    }

    // Casual cups open for sign-up, and the last one's winner.
    $saturday = now()->next('Saturday')->setTime(18, 0);
    foreach ([['chess', 'blitz', 'Chess Casual Cup EU #2', 4, 0], ['age-of-empires-2', '1v1', 'Age of Empires II Casual Cup EU #1', 4, 2], ['rocket-league', '1v1', 'Rocket League Casual Cup EU #2', 8, 4]] as $index => [$game, $mode, $name, $capacity, $taken]) {
        $casual = Tournament::factory()->create([
            'name' => $name, 'game' => $game, 'mode' => $mode, 'format' => TournamentFormat::SingleElimination, 'capacity' => $capacity, 'status' => TournamentStatus::Signup,
            'options' => FormatOptions::defaults(GameProfile::for($game, $mode))->toArray(), 'starts_at' => $saturday->copy()->addDays($index), 'signup_closes_at' => $saturday->copy()->addDays($index)->subMinutes(10),
            'published_at' => now(), 'cup_series' => "{$game}-eu", 'cup_number' => 2, 'created_by_id' => null, 'slug' => "revamp-cup-{$index}",
        ]);
        foreach (User::factory()->count($taken)->create() as $user) {
            TournamentSignup::query()->create(['tournament_id' => $casual->id, 'user_id' => $user->id, 'name' => $user->displayName(), 'members' => [$user->id]]);
        }
    }
    wonCasualCup($p['aHeck13'], $p['Lotte']);

    // Chess: a finished daily (#52) and a running one (#156) with its moves; the admin's own finished game this week.
    ChessGame::factory()->daily()->finished('0-1', ChessEndReason::Checkmate)->create(['number' => 52, 'white_id' => $p['Lotte']->id, 'black_id' => User::factory()->create(['name' => null])->id, 'ended_at' => now()->subMinutes(51)]);
    ChessGame::factory()->daily()->finished('1-0')->create(['white_id' => $admin->id, 'black_id' => $p['El Presidento Ben']->id, 'ended_at' => now()->subDays(2)]);
    $running = ChessGame::factory()->daily()->create(['number' => 156, 'white_id' => $admin->id, 'black_id' => $p['floatYaBoat']->id, 'ply' => 8]);
    foreach (['d4', 'd5', 'Bf4', 'e6', 'Nf3', 'c5', 'e3', 'Nc6'] as $index => $san) {
        ChessMove::query()->create(['chess_game_id' => $running->id, 'ply' => $index + 1, 'uci' => 'a1a1', 'san' => $san, 'fen' => ChessGame::START_FEN, 'spent_ms' => 1000, 'clock_ms' => 86_400_000, 'created_at' => now()->subMinutes(80 - $index * 10)]);
    }

    // Blockli games waiting for their next move.
    foreach ([['markusturm', 'Nidan21', 4], ['DerCaddy', 'El Presidento Ben', 8], ['markusturm', 'DerCaddy', 36]] as [$white, $black, $ply]) {
        mempoolBoard('blockli', ['mode' => 'correspondence', 'white_id' => $p[$white]->id, 'black_id' => $p[$black]->id, 'ply' => $ply,
            'initial_ms' => 86_400_000, 'increment_ms' => 0, 'white_ms' => 86_400_000, 'black_ms' => 86_400_000]);
    }

    // The Blockfill week with checked runs, today's best among them.
    app(BlockfillWeeks::class)->open();
    foreach ([['TickTrickSat', 12_568, 70], ['TickTrickSat', 11_828, 65], ['Stefan', 10_622, 120]] as [$name, $ticks, $ago]) {
        $at = now()->subMinutes($ago);
        $run = StackerRun::factory()->for($p[$name])->create(['status' => StackerRunStatus::Verifying, 'issued_at' => $at, 'started_at' => $at, 'submitted_at' => $at, 'ticks' => $ticks, 'state_hash' => '00000000', 'replay' => 'AAAA']);
        VerifyStackerRun::dispatchSync($run->id);
    }

    // The admin's ladder and a zap into the league reserve.
    Rating::query()->create(['pool' => 'casual', 'season' => '', 'game' => 'chess', 'mode' => 'correspondence', 'subject' => 'user:'.$admin->id, 'user_id' => $admin->id, 'rating' => 1022, 'results' => 3, 'wins' => 2, 'draws' => 0, 'losses' => 1]);
    IncomingPayment::query()->create(['pot' => IncomingPayment::RESERVE, 'source' => 'zap', 'payment_hash' => bin2hex(random_bytes(32)), 'bolt11' => 'lnbc1test', 'late' => false,
        'expires_at' => now()->addHour(), 'amount_sats' => 210, 'status' => IncomingPaymentStatus::Settled, 'settled_at' => now()->subDays(3), 'payer_pubkey' => str_repeat('a', 64), 'zap_verified' => true]);

    return $admin->refresh();
}

/** Opens `$path` at `$width` (German), guest or logged in, with the console collector in place. */
function revampPage(?User $user, string $path, int $width): mixed
{
    $page = visit($user === null ? '/robots.txt' : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from($path));
    $page->waitForLoadState('networkidle');

    return $page;
}

/** Nothing runs past the viewport or out of its own box (the squeeze detector of the canvas sweep). */
const REVAMP_SQUEEZE = <<<'JS'
() => {
    const out = [];
    const width = document.documentElement.clientWidth;
    if (document.documentElement.scrollWidth > width) out.push('page ' + document.documentElement.scrollWidth + ' > ' + width);
    for (const el of document.querySelectorAll('main *, header *, footer *')) {
        if (!el.checkVisibility() || el.closest('.rv-chain-vp, .rv-tick, [aria-hidden=true], .sr-only, #game-hub, #account-menu, #more-sheet')) continue;
        const r = el.getBoundingClientRect();
        if (r.width === 0) continue;
        if (r.right > width + 1 || r.left < -1) out.push((el.dataset.test || el.tagName) + ' x ' + Math.round(r.left) + '..' + Math.round(r.right));
        if (['A', 'BUTTON'].includes(el.tagName) && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflow === 'visible') out.push('spill ' + (el.dataset.test || el.textContent.trim().slice(0, 30)));
    }
    return [...new Set(out)].slice(0, 20);
}
JS;

function revampQuiet(mixed $page, string $label): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses')
        ->and($page->evaluate(REVAMP_SQUEEZE))->toBe([], $label.': squeeze');
}

function revampShot(mixed $page, string $name): void
{
    if (getenv('DESIGN_SHOTS')) {
        $page->screenshot(true, $name);
    }
}

test('the start page and the login stay quiet and inside the page for an admin and a guest, at 390 and 1440', function () {
    $admin = revampLeague();

    foreach ([1440, 390] as $width) {
        $page = revampPage($admin, '/', $width);
        revampShot($page, "home-admin-{$width}");
        expect($page->evaluate('() => document.querySelector("[data-test=home-hero]")?.dataset.tournament ?? null'))->not->toBeNull()
            ->and($page->evaluate('() => document.querySelectorAll("[data-test=chain-cube]").length'))->toBeGreaterThan(5)
            ->and($page->evaluate('() => document.querySelector("[data-test=live-bar]")?.checkVisibility()'))->toBeTrue();
        revampQuiet($page, "admin home {$width}");

        // A Livewire roundtrip on the page (the dock refreshes) keeps it quiet too.
        $page->evaluate('() => window.Livewire?.all().forEach((c) => c.$wire.$refresh())');
        $page->waitForLoadState('networkidle');
        revampQuiet($page, "admin home {$width} after refresh");

        $guest = revampPage(null, '/', $width);
        revampShot($guest, "home-guest-{$width}");
        expect($guest->evaluate('() => document.querySelector("[data-test=home-hero]")?.dataset.hero ?? null'))->toBe('blockfill');
        revampQuiet($guest, "guest home {$width}");

        $login = revampPage(null, '/login', $width);
        revampShot($login, "login-{$width}");
        expect($login->evaluate('() => document.querySelector("[data-test=login-google]")?.checkVisibility()'))->toBeTrue();
        revampQuiet($login, "login {$width}");
    }
});

test('the start page and the login fit every width from 390 to 1920 without a squeeze, for an admin and a guest', function () {
    $admin = revampLeague();
    $seen = [];

    foreach ([390, 768, 1024, 1280, 1440, 1920] as $width) {
        foreach (['admin /' => [$admin, '/'], 'guest /' => [null, '/'], 'guest /login' => [null, '/login']] as $label => [$user, $path]) {
            $page = revampPage($user, $path, $width);
            $seen["{$label} {$width}"] = $page->evaluate(REVAMP_SQUEEZE);
        }
    }

    fwrite(STDERR, "\n[revamp squeeze] ".json_encode($seen));
    expect(array_filter($seen))->toBe([]);
});

test('positive control: the collector and the squeeze detector of this file see a throw, a 404 and a box past the edge', function () {
    $page = revampPage(null, '/', 390);
    $page->evaluate('() => { setTimeout(() => { throw new Error("revamp positive control"); }); fetch("/__revamp-missing"); const d = document.createElement("div"); d.style.cssText = "width:600px;height:10px"; d.dataset.test = "revamp-wide"; document.querySelector("main").append(d); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("revamp positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.endsWith("/__revamp-missing") && e.responseStatus === 404)', 5_000);

    expect($page->evaluate(REVAMP_SQUEEZE))->toContain('page 600 > 390')
        ->and(fn () => revampQuiet($page, 'positive control'))->toThrow(ExpectationFailedException::class);
});

test('the mempool chain moves sideways under a drag and brings the present back', function () {
    revampLeague();
    $page = revampPage(null, '/', 390);

    $before = $page->evaluate('() => document.querySelector(".rv-chain-vp").scrollLeft');
    $page->evaluate(<<<'JS'
        () => {
            const vp = document.querySelector('.rv-chain-vp');
            const r = vp.getBoundingClientRect();
            const y = r.top + 40;
            vp.dispatchEvent(new PointerEvent('pointerdown', { pointerType: 'mouse', button: 0, clientX: r.left + 40, clientY: y, bubbles: true }));
            window.dispatchEvent(new PointerEvent('pointermove', { pointerType: 'mouse', clientX: r.left + 240, clientY: y, bubbles: true }));
            window.dispatchEvent(new PointerEvent('pointerup', { pointerType: 'mouse', bubbles: true }));
        }
        JS);
    $after = $page->evaluate('() => document.querySelector(".rv-chain-vp").scrollLeft');

    expect($after)->toBeLessThan($before);

    // Scrolled to the end farther from the divider, the present is out of view and the button offers it back.
    $page->evaluate('() => { const vp = document.querySelector(".rv-chain-vp"); const d = document.querySelector("[x-ref=divider]").offsetLeft; const max = vp.scrollWidth - vp.clientWidth; vp.scrollLeft = d > vp.scrollWidth / 2 ? 0 : max; vp.dispatchEvent(new Event("scroll")); }');
    // The viewport scrolls smoothly: the button follows its scroll events.
    BrowserWait::until($page, '() => document.querySelector("[data-test=chain-present]").checkVisibility()', 3_000);

    // It brings the divider back into view.
    $page->locator('[data-test=chain-present]')->click();
    BrowserWait::until($page, '() => !document.querySelector("[data-test=chain-present]").checkVisibility()', 3_000);
    expect($page->evaluate('() => { const vp = document.querySelector(".rv-chain-vp"); const x = document.querySelector("[x-ref=divider]").offsetLeft - vp.scrollLeft; return x >= 0 && x <= vp.clientWidth; }'))->toBeTrue();
});
