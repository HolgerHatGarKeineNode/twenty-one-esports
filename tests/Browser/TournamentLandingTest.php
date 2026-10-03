<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The tournament landing page and the sign-up moment in the browser
|--------------------------------------------------------------------------
|
| The public tournament page at 375, 768 and 1440 px in four states (sign-up
| open with a few players, full, running with results, finished with a
| winner) and the sign-up page before signing, while the signer is asked and
| once signed up. Every view: no horizontal page overflow (a bracket may
| scroll inside its own container), a clean console (console.error/warn,
| uncaught errors, rejected promises), no fetch/XHR and no loaded resource
| answering 400 or above. A thrown error and a missing image at the end
| prove the collectors see both. LANDING_SHOTS=<dir> writes the English
| screenshots there, and the German ones of the open and running page.
|
*/

const LANDING_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    for (const level of ['error', 'warn']) {
        const original = console[level];
        console[level] = function (...args) { push('console.' + level + ': ' + args.map(String).join(' ')); original.apply(console, args); };
    }
    window.addEventListener('error', (e) => push('error: ' + (e.message || 'unknown')));
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

const LANDING_STATE = <<<'JS'
    () => {
        const rect = (selector) => { const el = document.querySelector(selector); if (!el) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top + scrollY), height: Math.round(r.height), width: Math.round(r.width) }; };
        const scroller = document.querySelector('[data-test=bracket-scroll]');
        return {
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            errors: window.__errors ?? ['collector missing'],
            bad: performance.getEntries().filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400).map((e) => e.responseStatus + ' ' + e.name),
            cta: document.querySelector('[data-test=tournament-show]')?.dataset.cta ?? null,
            hero: rect('[data-test=tournament-hero]'),
            button: rect('[data-test=signup-cta] a, [data-test=signup-cta] [data-test=cta-closed]'),
            cover: rect('[data-test=game-cover]'),
            seats: document.querySelectorAll('.tl-seat').length,
            taken: document.querySelectorAll('.tl-seat.is-taken').length,
            roster: document.querySelectorAll('[data-test=roster-entry]').length,
            open: document.querySelectorAll('[data-test=open-seat]').length,
            live: document.querySelectorAll('[data-live]').length,
            bracketScroll: scroller ? { scroll: scroller.scrollWidth, client: scroller.clientWidth } : null,
            countdown: document.querySelector('[data-test=countdown]')?.textContent.trim() ?? null,
            lang: document.documentElement.lang,
        };
    }
    JS;

/** Wait for the load sequence (poster, seats, count-up) to finish before a screenshot. */
const LANDING_SETTLE = '() => new Promise((resolve) => setTimeout(() => requestAnimationFrame(() => resolve(true)), 1600))';

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

function landingShots(): ?string
{
    $dir = getenv('LANDING_SHOTS');

    return is_string($dir) && $dir !== '' ? $dir : null;
}

function landingShot(Page $page, string $name): void
{
    $dir = landingShots();

    if ($dir === null) {
        return;
    }

    $page->evaluate(LANDING_SETTLE);
    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::copy(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

function landingPage(User $user): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(LANDING_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));

    return $page;
}

/** A Rocket League 1v1 tournament with sign-up open, the way the user's screenshot had it. */
function landingRocketLeague(string $name, int $capacity, TournamentFormat $format = TournamentFormat::TwoStage): Tournament
{
    $tournament = Tournament::factory()->rocketLeague($format)->create([
        'name' => $name,
        'mode' => '1v1',
        'options' => FormatOptions::defaults(GameProfile::for('rocket-league', '1v1'))->toArray(),
        'capacity' => $capacity,
        'created_by_id' => organizer()->id,
        'description' => 'Sixteen seats, one evening, one final on the big screen. Bring your own controller.',
        'starts_at' => now()->addDays(3)->setTime(19, 0),
    ]);

    return app(TournamentPublisher::class)->publish($tournament, $tournament->creator, CarbonImmutable::now()->addDays(2)->addHours(4));
}

/** `$count` keyed players with a casual Rocket League 1v1 Elo, signed up solo. */
function landingEntries(Tournament $tournament, array $names): void
{
    foreach ($names as $index => $name) {
        [$user, $signer] = keyedPlayer();
        $user->forceFill(['name' => $name, 'locale' => 'en'])->save();
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'rocket-league', 'mode' => '1v1', 'subject' => 'user:'.$user->id,
            'user_id' => $user->id, 'rating' => 1320 - 37 * $index, 'results' => 12]);
        soloSignup($tournament, $user, $signer);
    }
}

test('the tournament page works as a landing page in every state at 375, 768 and 1440 px', function () {
    $names = ['Wels', 'markusturm', 'hodlqueen', 'satsjaeger', 'rbf_rita', 'nakamoto_nick', 'blockbauer', 'zapzap'];

    $open = landingRocketLeague('Einundzwanzig Fifa 2026', 16);
    landingEntries($open, array_slice($names, 0, 5));

    $full = landingRocketLeague('Halving Cup', 8, TournamentFormat::SingleElimination);
    landingEntries($full, $names);

    $running = runningChess(TournamentFormat::SingleElimination, 8);
    $running->forceFill(['name' => 'Blitz Night Munich', 'description' => 'Eight boards, one bracket, pizza at the break.', 'published_at' => now()->subDay(), 'draw_height' => 915000, 'draw_hash' => str_repeat('ab', 32)])->save();
    $director = $running->creator;
    $runner = app(TournamentRunner::class);

    foreach (TournamentMatch::query()->where('tournament_id', $running->id)->where('status', 'ready')->orderBy('id')->limit(2)->get() as $match) {
        $runner->enterResult($match, $director, ['result' => '1-0']);
    }

    $finished = runningChess(TournamentFormat::SingleElimination, 4);
    $finished->forceFill(['name' => 'Genesis Open', 'published_at' => now()->subDays(2)])->save();
    playOutAsDirector($finished);

    $viewer = User::factory()->create(['name' => 'visitor', 'locale' => 'en']);
    $page = landingPage($viewer);
    $measured = [];

    foreach ([[375, 812], [768, 1024], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);

        foreach (['open' => $open, 'full' => $full, 'running' => $running, 'finished' => $finished] as $state => $tournament) {
            $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
            BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-hero]") !== null && window.Alpine !== undefined', 10_000);
            $measured[$state][$width] = $page->evaluate(LANDING_STATE);
            landingShot($page, "landing-{$state}-{$width}");

            expect([$state, $width, $measured[$state][$width]['overflow']])->toBe([$state, $width, 0])
                ->and([$state, $width, $measured[$state][$width]['errors']])->toBe([$state, $width, []])
                ->and([$state, $width, $measured[$state][$width]['bad']])->toBe([$state, $width, []]);
        }
    }

    expect($measured['open'][375]['cta'])->toBe('open')
        ->and($measured['open'][375]['seats'])->toBe(16)
        ->and($measured['open'][375]['taken'])->toBe(5)
        ->and($measured['open'][375]['roster'])->toBe(5)
        ->and($measured['open'][375]['open'])->toBe(6)
        ->and($measured['open'][375]['countdown'])->toMatch('/^2 days \d\d:\d\d:\d\d$/')
        ->and($measured['full'][768]['cta'])->toBe('full')
        ->and($measured['full'][768]['open'])->toBe(0)
        ->and($measured['running'][1440]['cta'])->toBe('live')
        ->and($measured['running'][1440]['live'])->toBeGreaterThan(0)
        // A phone scrolls the bracket inside its container, never the page.
        ->and($measured['running'][375]['bracketScroll']['scroll'])->toBeGreaterThan($measured['running'][375]['bracketScroll']['client'])
        ->and($measured['finished'][1440]['cta'])->toBe('finished');

    // The countdown ticks in the browser.
    $page->goto(ComputeUrl::from(route('tournaments.show', $open)));
    BrowserWait::until($page, '() => document.querySelector("[data-test=countdown]") !== null', 10_000);
    $first = $page->evaluate('() => document.querySelector("[data-test=countdown]").textContent.trim()');
    BrowserWait::until($page, '() => document.querySelector("[data-test=countdown]").textContent.trim() !== '.json_encode($first), 3_000);

    // German, for the eye and the console.
    $german = User::factory()->create(['name' => 'besucher', 'locale' => 'de']);
    $page = landingPage($german);

    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);

        foreach (['open' => $open, 'running' => $running] as $state => $tournament) {
            $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
            BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-hero]") !== null', 10_000);
            $de = $page->evaluate(LANDING_STATE);
            landingShot($page, "landing-de-{$state}-{$width}");

            expect([$state, $width, $de['lang'], $de['overflow'], $de['errors'], $de['bad']])->toBe([$state, $width, 'de', 0, [], []]);
        }
    }

    // Positive control: a thrown error and a missing image reach the collectors.
    $page->evaluate('() => { const img = new Image(); img.src = "/images/games/not-there.webp"; document.body.append(img); setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0 && performance.getEntries().some((e) => e.name.includes("not-there"))', 5_000);
    $control = $page->evaluate(LANDING_STATE);

    expect(implode(' | ', $control['errors']))->toContain('probe')
        ->and(implode(' | ', $control['bad']))->toContain('404')->toContain('not-there.webp');

    fwrite(STDERR, "\n[landing] ".json_encode($measured)."\n");
});

test('signing up feels like taking a seat: before, while the signer is asked, and signed up, at 375, 768 and 1440 px', function () {
    $tournament = landingRocketLeague('Einundzwanzig Fifa 2026', 16);
    landingEntries($tournament, ['Wels', 'markusturm', 'hodlqueen', 'satsjaeger', 'rbf_rita']);
    $measured = [];
    // 1250 Elo sits behind 1320 and 1283; each newcomer before signed up earlier with the same Elo.
    $seeds = [375 => 3, 768 => 4, 1440 => 5];

    foreach ([[375, 812], [768, 1024], [1440, 900]] as [$width, $height]) {
        $user = User::factory()->create(['name' => 'newcomer'.$width, 'locale' => 'en']);
        TestSigner::forBrowser($user);
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'rocket-league', 'mode' => '1v1', 'subject' => 'user:'.$user->id,
            'user_id' => $user->id, 'rating' => 1250, 'results' => 12]);

        $page = landingPage($user);
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.signup', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=enter-solo]") !== null && window.Alpine !== undefined', 10_000);
        $before = $page->evaluate(LANDING_STATE);
        landingShot($page, "signup-before-{$width}");

        // Hold the signer until the "while signing" state is measured.
        $page->evaluate('() => { const sign = window.nostr.signEvent; window.nostr.signEvent = (draft) => new Promise((resolve) => { window.__release = () => resolve(sign(draft)); }); }');
        // Rocket League is played in the player's own copy: the sign-up needs the ownership tick.
        $page->locator('[data-test=owns-game]')->check();
        $page->locator('[data-test=enter-solo]')->click();
        BrowserWait::until($page, '() => typeof window.__release === "function"', 10_000);
        $signing = $page->evaluate('() => ({ button: document.querySelector("[data-test=enter-solo]").innerText.replace(/\s+/g, " ").trim(), disabled: document.querySelector("[data-test=enter-solo]").disabled, seat: getComputedStyle(document.querySelector("[data-test=your-seat-slot] .tl-slide-in")).display })');
        landingShot($page, "signup-signing-{$width}");

        $page->evaluate('() => window.__release()');
        BrowserWait::until($page, '() => document.querySelector("[data-test=my-entry]") !== null', 10_000);
        $after = $page->evaluate(LANDING_STATE);
        $confirmation = $page->evaluate('() => ({ text: document.querySelector("[data-test=my-entry]").textContent.replace(/\s+/g, " ").trim(), burst: document.querySelector("[data-test=my-entry]").hasAttribute("data-just-entered"), share: document.querySelector("[data-test=tournament-share]") !== null })');
        landingShot($page, "signup-done-{$width}");

        $measured[$width] = compact('before', 'signing', 'after', 'confirmation');

        expect([$width, $before['overflow'], $before['errors'], $before['bad']])->toBe([$width, 0, [], []])
            ->and([$width, $after['overflow'], $after['errors'], $after['bad']])->toBe([$width, 0, [], []])
            ->and($signing['button'])->toBe('Signing…')
            ->and($signing['disabled'])->toBeTrue()
            ->and($signing['seat'])->not->toBe('none')
            ->and($confirmation['text'])->toContain('You’re in!')->toContain('Seed '.$seeds[$width].' if sign-up closed now.')->toContain('Round 1 starts')
            ->and($confirmation['burst'])->toBeTrue()
            ->and($confirmation['share'])->toBeTrue();
    }

    expect($tournament->signups()->whereNull('withdrawn_at')->count())->toBe(8)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Signup);

    fwrite(STDERR, "\n[landing-signup] ".json_encode($measured)."\n");
});
