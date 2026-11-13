<?php

use App\Enums\PayoutStatus;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Payouts\PayoutOverview;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

/*
| The overview of the admin payouts page (user, 2026-11-13: "eine weltbeste Filterfunktion und eine Übersicht der
| Turniere … beste UX und UI"), measured as an admin in German with the longest real values (a name of 60
| characters, a nine-digit pot, thirteen tournaments in every payout state, three games) at 390 to 1920: nothing
| runs past the page or is squeezed, the filters and the first waiting tournament stand on the first screen, every
| control is 44 px, focusable and works by keyboard, and the console and every response stay quiet on the first load,
| after a chip, after a search and after opening a tournament. DESIGN_SHOTS=1 also saves the screenshots.
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
});

// 80 characters: the longest name the tournament form accepts.
const PAYOUTS_LONG_NAME = 'Einundzwanzig Winterpokal 2026: Das große Blitzturnier am See mit Donaudampfschi';

/**
 * Thirteen tournaments with a pot, every payout state, three games, the longest values.
 *
 * @return array{0: User, 1: array<string, Tournament>}
 */
function payoutsOverviewLeague(): array
{
    $admin = anAdmin();
    $admin->forceFill(['locale' => 'de', 'profile_event_at' => now()])->save();
    $rocket = ['game' => 'rocket-league', 'mode' => '3v3'];
    $aoe = ['game' => 'age-of-empires-2', 'mode' => '1v1'];

    $tournaments = [
        'paid-1' => potTournamentIn(PayoutOverview::PAID, ['name' => 'Blitz Night Wien']),
        'paid-2' => potTournamentIn(PayoutOverview::PAID, ['name' => 'RL Sunday #4', ...$rocket]),
        'paid-3' => potTournamentIn(PayoutOverview::PAID, ['name' => 'Schwarzwald Open']),
        'not-due-1' => potTournamentIn(PayoutOverview::NOT_DUE, ['name' => 'Age of Empires Winterliga', ...$aoe]),
        'not-due-2' => potTournamentIn(PayoutOverview::NOT_DUE, ['name' => 'Blitz Night Zürich']),
        'paying' => potTournamentIn(PayoutOverview::PAYING, ['name' => 'RL Sunday #5', ...$rocket]),
        'open' => potTournamentIn(PayoutOverview::OPEN, ['name' => 'Stammtisch-Pokal Leipzig']),
        'unchecked-1' => potTournamentIn(PayoutOverview::UNCHECKED, ['name' => 'Blockzeit-Cup Hamburg', 'prize_target_sats' => 210_000]),
        'unchecked-2' => potTournamentIn(PayoutOverview::UNCHECKED, ['name' => 'AoE2 Herbstturnier', 'prize_target_sats' => 2_100_000, ...$aoe]),
        'ready-1' => potTournamentIn(PayoutOverview::READY, ['name' => 'RL Sunday #6', ...$rocket]),
        'ready-2' => potTournamentIn(PayoutOverview::READY, ['name' => 'Blitz Night Köln']),
        'failed' => potTournamentIn(PayoutOverview::FAILED, ['name' => PAYOUTS_LONG_NAME, 'prize_target_sats' => 123_456_789],
            [[PayoutStatus::Failed, 61_728_395, 'wallet_error'], [PayoutStatus::Pending, 37_037_036], [PayoutStatus::Open, 24_691_358, 'no_lud16']]),
        'needs-check' => potTournamentIn(PayoutOverview::NEEDS_CHECK, ['name' => 'Halving-Party Blitz']),
    ];

    return [$admin, $tournaments];
}

function payoutsOverviewPage(User $admin, int $width, int $height, array $query = []): mixed
{
    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from(route('admin.payouts', $query, false)));
    $page->waitForLoadState('networkidle');
    BrowserWait::until($page, '() => window.Livewire !== undefined && document.querySelector("[data-test=admin-payouts]") !== null', 10_000);

    return $page;
}

/** A sentence of more than six words in a box under 240 px wide and over 60 px high (user 2026-10-25). */
const PAYOUTS_SENTENCE_SQUEEZE = <<<'JS'
() => [...document.querySelectorAll('main p, main span, main li, main b, main a, main td, main th, main dd, main dt, main label')]
    .filter((el) => { const r = el.getBoundingClientRect(), own = [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join(' ').trim();
        return el.checkVisibility() && r.width > 0 && r.width < 240 && r.height > 60 && own.split(/\s+/).filter(Boolean).length > 6; })
    .map((el) => Math.round(el.getBoundingClientRect().width) + 'x' + Math.round(el.getBoundingClientRect().height) + ': ' + el.textContent.trim().slice(0, 40))
JS;

/** Buttons, chips, tags and the pager on more than one line, or with their label cut (R11). */
const PAYOUTS_WRAPPED = <<<'JS'
() => [...document.querySelectorAll('main .rv-chip, main .rv-b, main .rv-b2, main .rv-tag, main button, main th')].filter((el) => el.checkVisibility()).flatMap((el) => {
    const range = document.createRange(); range.selectNodeContents(el);
    const rects = [...range.getClientRects()].filter((r) => r.width > 1), out = [];
    const line = Math.max(0, ...rects.map((r) => r.height)), span = Math.max(0, ...rects.map((r) => r.bottom)) - Math.min(...rects.map((r) => r.top));
    if (rects.length > 0 && span > line * 1.6) out.push('wrapped: ' + el.textContent.trim().slice(0, 30));
    if (el.scrollWidth > el.clientWidth + 1 && ! el.querySelector('.truncate')) out.push('cut: ' + el.textContent.trim().slice(0, 30));
    return out;
})
JS;

/** Controls of the page's own content under 44 px (a table row counts as the target of its link). */
const PAYOUTS_SMALL_TARGETS = <<<'JS'
() => [...document.querySelectorAll('[data-test=payouts-overview] a, [data-test=payouts-overview] button, [data-test=payouts-overview] input, [data-test=payouts-detail-bar] a')]
    .filter((el) => el.checkVisibility()).map((el) => el.closest('tr') ?? el)
    .filter((el) => el.getBoundingClientRect().height < 44)
    .map((el) => Math.round(el.getBoundingClientRect().height) + 'px: ' + (el.dataset.test || el.textContent.trim().slice(0, 30)))
JS;

/** Whether the element is on the first screen and not covered: its centre is hit by itself or a descendant. */
const PAYOUTS_ON_FIRST_SCREEN = <<<'JS'
([selector]) => { const el = [...document.querySelectorAll(selector)].find((e) => e.checkVisibility()); if (! el) return 'missing ' + selector;
    const r = el.getBoundingClientRect(); if (r.bottom > window.innerHeight) return selector + ' ends at ' + Math.round(r.bottom) + ' > ' + window.innerHeight;
    const hit = document.elementFromPoint(Math.min(r.left + r.width / 2, window.innerWidth - 2), r.top + r.height / 2);
    return hit && (el.contains(hit) || hit.contains(el)) ? true : selector + ' is covered by ' + (hit?.dataset?.test || hit?.tagName); }
JS;

/** Everything loud on the page: console errors and failed responses. */
function payoutsOverviewLoud(mixed $page): array
{
    return array_merge(
        array_map(fn (string $error): string => 'console '.$error, $page->evaluate('() => window.__errors')),
        array_map(fn (mixed $response): string => 'response '.json_encode($response), $page->evaluate(BrowserConsole::BAD_RESPONSES)),
    );
}

/** What does not fit: the page's overflow, squeezed text, spilled text, wrapped controls, small targets. */
function payoutsOverviewMisfits(mixed $page): array
{
    [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);

    return array_merge(
        $scroll > $client ? ["page {$scroll} > {$client}"] : [],
        array_map(fn (string $hit): string => 'squeezed '.$hit, $page->evaluate(BrowserConsole::SQUEEZED, ['main'])),
        array_map(fn (string $hit): string => 'sentence '.$hit, $page->evaluate(PAYOUTS_SENTENCE_SQUEEZE)),
        array_map(fn (string $hit): string => 'spilled '.$hit, $page->evaluate(BrowserConsole::SPILLED, ['[data-test=payouts-overview], [data-test=payouts-detail-bar], [data-test=admin-crumbs]'])),
        $page->evaluate(PAYOUTS_WRAPPED),
        array_map(fn (string $hit): string => 'target '.$hit, $page->evaluate(PAYOUTS_SMALL_TARGETS)),
    );
}

test('the overview and an opened tournament fit every width from 390 to 1920, in German, with the longest values', function () {
    [$admin, $tournaments] = payoutsOverviewLeague();
    $problems = [];
    $measured = [];

    foreach ([390, 1024, 1280, 1440, 1600, 1920] as $width) {
        foreach (['overview' => [], 'all' => ['state' => 'all'], 'no-hits' => ['q' => 'zzzz'], 'paying' => ['state' => 'paying'], 'detail' => ['tournament' => $tournaments['failed']->id]] as $view => $query) {
            $page = payoutsOverviewPage($admin, $width, 900, $query);
            $misfits = payoutsOverviewMisfits($page);
            $loud = payoutsOverviewLoud($page);
            $measured["{$view} {$width}"] = ['widths' => $page->evaluate(BrowserConsole::WIDTHS), 'misfits' => count($misfits), 'loud' => count($loud)];

            if ($misfits !== [] || $loud !== []) {
                $problems["{$view} {$width}"] = [...$misfits, ...$loud];
            }

            if (getenv('DESIGN_SHOTS') && in_array($width, [390, 1440], true) && in_array($view, ['overview', 'detail'], true)) {
                $page->screenshot(true, "payouts-{$view}-{$width}-de");
            }
        }
    }

    // German, the longest name and the nine-digit pot are really on the page.
    $page = payoutsOverviewPage($admin, 1440, 900);
    expect($page->evaluate('() => document.documentElement.lang'))->toBe('de')
        ->and($page->evaluate('() => document.querySelector("[data-test=payouts-overview]").innerText'))->toContain(PAYOUTS_LONG_NAME)->toContain('123.456.789')->toContain('Wartet');

    // Positive control of the detectors: a squeezed text, a squeezed sentence, a chip on two lines and a wide element are reported.
    $page = payoutsOverviewPage($admin, 390, 844);
    expect(payoutsOverviewMisfits($page))->toBe([]);
    $page->evaluate('() => { const list = document.querySelector("[data-test=payouts-cards]");
        list.insertAdjacentHTML("beforebegin", "<p style=\"width:100px\">Ein Turniername mit mehr als fünfundzwanzig Zeichen</p><p style=\"width:150px\">Dieser Satz hat deutlich mehr als sechs Wörter und steht in einer schmalen Spalte</p>");
        const chip = document.querySelector("[data-test=payouts-states] .rv-chip"); chip.style.whiteSpace = "normal"; chip.style.width = "44px"; chip.style.height = "auto"; chip.style.flexWrap = "wrap";
        list.style.width = "600px"; }');
    $control = payoutsOverviewMisfits($page);
    foreach (['page ', 'squeezed ', 'sentence ', 'wrapped: '] as $kind) {
        expect(collect($control)->contains(fn (string $hit): bool => str_starts_with($hit, $kind)))->toBeTrue("the detector '{$kind}' did not fire: ".json_encode($control, JSON_UNESCAPED_UNICODE));
    }
    $control = array_values(array_unique(array_map(fn (string $hit): string => explode(' ', $hit)[0], $control)));

    if (getenv('DESIGN_SHOTS')) {
        fwrite(STDERR, "\nPAYOUTS-MEASURED ".json_encode($measured)."\nPAYOUTS-CONTROL ".json_encode($control, JSON_UNESCAPED_UNICODE)."\n");
    }

    expect($problems)->toBe([]);
});

test('the filters and the first waiting tournament stand on the first screen', function (int $width, int $height) {
    [$admin] = payoutsOverviewLeague();
    $page = payoutsOverviewPage($admin, $width, $height);
    $row = $width >= 1280 ? '[data-test=payouts-row]' : '[data-test=payouts-card]';

    expect($page->evaluate('() => document.querySelector("[data-test=payouts-overview]").dataset.filter'))->toBe('waiting')
        ->and($page->evaluate(PAYOUTS_ON_FIRST_SCREEN, ['[data-test=payouts-search]']))->toBeTrue()
        ->and($page->evaluate(PAYOUTS_ON_FIRST_SCREEN, ['[data-test=payouts-states] .rv-chip']))->toBeTrue()
        ->and($page->evaluate(PAYOUTS_ON_FIRST_SCREEN, ['[data-test=payouts-games] .rv-chip']))->toBeTrue()
        ->and($page->evaluate(PAYOUTS_ON_FIRST_SCREEN, [$row]))->toBeTrue()
        ->and($page->evaluate("() => [...document.querySelectorAll('{$row}')].find((e) => e.checkVisibility()).dataset.state"))->toBe(PayoutOverview::NEEDS_CHECK);

    if (getenv('DESIGN_SHOTS')) {
        fwrite(STDERR, "\nPAYOUTS-FOLD {$width}x{$height} ".json_encode($page->evaluate("() => { const r = [...document.querySelectorAll('{$row}')].find((e) => e.checkVisibility()).getBoundingClientRect(); return [Math.round(r.top), Math.round(r.bottom), window.innerHeight]; }"))."\n");
    }
})->with([[1280, 720], [1440, 900], [1920, 1080], [390, 844]]);

test('chips, search and rows work by mouse and keyboard with a visible focus, and the console and every response stay quiet', function () {
    [$admin, $tournaments] = payoutsOverviewLeague();
    $page = payoutsOverviewPage($admin, 1440, 900);
    $filter = '() => document.querySelector("[data-test=payouts-overview]")?.dataset.filter';
    $rows = '() => [...document.querySelectorAll("[data-test=payouts-row]")].map((row) => Number(row.dataset.tournament))';

    // First load: what waits, quiet.
    expect($page->evaluate($filter))->toBe('waiting')
        ->and($page->evaluate($rows))->toHaveCount(7)
        ->and(payoutsOverviewLoud($page))->toBe([])
        ->and($page->evaluate('() => document.querySelectorAll("main select, main input[type=checkbox], main input[type=radio]").length'))->toBe(0);

    // Mouse: a chip filters without leaving the page, and the URL keeps the filter.
    $page->locator('[data-test=payouts-states-paid]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=payouts-overview]")?.dataset.filter === "paid"', 5_000);
    expect($page->evaluate($rows))->toHaveCount(3)
        ->and($page->evaluate('() => new URL(location.href).searchParams.get("state")'))->toBe('paid')
        ->and($page->evaluate('() => document.querySelector("[data-test=payouts-states-paid]").getAttribute("aria-current")'))->toBe('page')
        ->and(payoutsOverviewLoud($page))->toBe([]);

    // Keyboard: the chip takes the focus with a visible ring, Enter filters.
    $page->locator('[data-test=payouts-states-all]')->press('Shift+Tab');
    $page->locator(':focus')->press('Tab');
    expect($page->evaluate('() => document.activeElement.dataset.test'))->toBe('payouts-states-all')
        ->and($page->evaluate('() => document.activeElement.matches(":focus-visible")'))->toBeTrue()
        ->and($page->evaluate('() => parseFloat(getComputedStyle(document.activeElement).outlineWidth)'))->toBeGreaterThanOrEqual(2.0)
        ->and($page->evaluate('() => getComputedStyle(document.activeElement).outlineStyle'))->toBe('solid');
    $page->locator(':focus')->press('Enter');
    BrowserWait::until($page, '() => document.querySelector("[data-test=payouts-overview]")?.dataset.filter === "all"', 5_000);
    expect($page->evaluate($rows))->toHaveCount(12);

    // The search: typing narrows the list, the URL keeps the term.
    $page->locator('[data-test=payouts-search]')->fill('winterpokal');
    BrowserWait::until($page, '() => document.querySelectorAll("[data-test=payouts-row]").length === 1', 5_000);
    expect($page->evaluate($rows))->toBe([$tournaments['failed']->id])
        ->and($page->evaluate('() => new URL(location.href).searchParams.get("q")'))->toBe('winterpokal')
        ->and(payoutsOverviewLoud($page))->toBe([]);

    // Keyboard: the row's link takes the focus with a ring, Enter opens the tournament; the URL names it.
    $page->locator('[data-test=payouts-open]')->press('Shift+Tab');
    $page->locator(':focus')->press('Tab');
    expect($page->evaluate('() => document.activeElement.dataset.test'))->toBe('payouts-open')
        ->and($page->evaluate('() => parseFloat(getComputedStyle(document.activeElement).outlineWidth)'))->toBeGreaterThanOrEqual(2.0);
    $page->locator(':focus')->press('Enter');
    BrowserWait::until($page, '() => document.querySelector("[data-test=payouts-tournament-panel]") !== null', 5_000);
    expect($page->evaluate('() => document.querySelector("[data-test=payouts-overview]")'))->toBeNull()
        ->and($page->evaluate('() => Number(new URL(location.href).searchParams.get("tournament"))'))->toBe($tournaments['failed']->id)
        ->and($page->evaluate('() => document.querySelector("[data-test=payouts-detail-state]").dataset.state'))->toBe(PayoutOverview::FAILED)
        ->and($page->evaluate('() => document.querySelectorAll("[data-test=admin-payout-row]").length'))->toBe(3)
        ->and(payoutsOverviewLoud($page))->toBe([]);

    // Back: the overview with the same search, the tournament marked as opened last.
    $page->locator('[data-test=payouts-back]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=payouts-overview]") !== null', 5_000);
    expect($page->evaluate('() => document.querySelector("[data-test=payouts-row]").classList.contains("is-seen")'))->toBeTrue()
        ->and($page->evaluate('() => document.querySelector("[data-test=payouts-search]").value'))->toBe('winterpokal')
        ->and($page->evaluate('() => new URL(location.href).searchParams.get("tournament")'))->toBeNull();

    // Mouse: a click anywhere on a row opens it once.
    $page->locator('[data-test=payouts-row] [data-test=payouts-row-pot]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=payouts-tournament-panel]") !== null', 5_000);

    // The browser's back button returns to the overview.
    BrowserWait::until($page, '() => new URL(location.href).searchParams.has("tournament")', 5_000);
    $page->evaluate('() => history.back()');
    BrowserWait::until($page, '() => document.querySelector("[data-test=payouts-overview]") !== null', 5_000);
    expect(payoutsOverviewLoud($page))->toBe([]);

    // Five re-renders keep everything quiet.
    $page->evaluate('async () => { const wire = Livewire.find(document.querySelector("[data-test=payouts-overview]").closest("[wire\\\\:id]").getAttribute("wire:id")); for (let i = 0; i < 5; i++) { await wire.$refresh(); } }');
    expect(payoutsOverviewLoud($page))->toBe([]);

    // Positive control, same collector: a thrown error and a failed response are caught.
    $page->evaluate('() => { setTimeout(() => { throw new Error("payouts overview control"); }); fetch("/__payouts-overview-missing"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("payouts overview control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
    expect(count(payoutsOverviewLoud($page)))->toBeGreaterThanOrEqual(2);
});

test('a phone opens a tournament from its card and finds the way back', function () {
    [$admin, $tournaments] = payoutsOverviewLeague();
    $page = payoutsOverviewPage($admin, 390, 844);

    expect($page->evaluate('() => document.querySelector("[data-test=payouts-table]").checkVisibility()'))->toBeFalse()
        ->and($page->evaluate('() => document.querySelectorAll("[data-test=payouts-card]").length'))->toBe(7);

    $page->locator('[data-test=payouts-card][data-tournament="'.$tournaments['needs-check']->id.'"]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=payouts-tournament-panel]") !== null', 5_000);
    expect($page->evaluate(PAYOUTS_ON_FIRST_SCREEN, ['[data-test=payouts-back]']))->toBeTrue()
        ->and(payoutsOverviewMisfits($page))->toBe([]);

    $page->locator('[data-test=payouts-next]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=payouts-detail-state]")?.dataset.state === "failed"', 5_000);
    $page->locator('[data-test=payouts-back]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=payouts-card].is-seen") !== null', 5_000);

    expect(payoutsOverviewLoud($page))->toBe([]);
});

test('with nothing waiting the page lands on every tournament, and the waiting filter says calmly that all is paid', function () {
    $admin = anAdmin();
    $admin->forceFill(['locale' => 'de', 'profile_event_at' => now()])->save();
    potTournamentIn(PayoutOverview::PAID, ['name' => PAYOUTS_LONG_NAME]);
    potTournamentIn(PayoutOverview::NOT_DUE, ['name' => 'Blitz Night Zürich']);

    foreach ([390, 1440] as $width) {
        $page = payoutsOverviewPage($admin, $width, 900);
        expect($page->evaluate('() => document.querySelector("[data-test=payouts-overview]").dataset.filter'))->toBe('all')
            ->and($page->evaluate('() => Number(document.querySelector("[data-test=readout-waiting]").dataset.count)'))->toBe(0);

        $page->locator('[data-test=payouts-states-waiting]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=payouts-all-paid]") !== null', 5_000);
        expect($page->evaluate(PAYOUTS_ON_FIRST_SCREEN, ['[data-test=payouts-show-all]']))->toBeTrue()
            ->and(payoutsOverviewMisfits($page))->toBe([])
            ->and(payoutsOverviewLoud($page))->toBe([]);
    }
});
