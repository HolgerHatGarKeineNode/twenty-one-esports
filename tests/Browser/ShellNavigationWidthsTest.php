<?php

use App\Models\Tournament;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Shell navigation (header concept B): every width, every role
|--------------------------------------------------------------------------
|
| Row 1 and row 2 from lg, the top bar, game chips and tab bar below it, at
| 1440, 1280, 1024, 768, 375 and 320 px for guest, player and admin, and the
| tightest desktop widths once more in German: no sideways scroll, nothing
| squeezed under its neighbour, the chrome height, every control at least
| 44 px below lg (24 px from lg). The helpers are tests/Support/shell.php;
| hub, context bar, phone sheets and /play are in ShellNavigationTest.php.
|
| SHELL_SHOTS=<dir> writes the English screenshots there.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

test('row 1 and row 2 fit 1024, 1280 and 1440 px and the phone bars fit 320, 375 and 768 px, for guest, player and admin', function () {
    $users = ['guest' => null, 'player' => shellPlayer(), 'admin' => shellAdmin()];
    $problems = [];
    $failures = [];

    foreach ($users as $role => $user) {
        foreach ([1440 => 900, 1280 => 800, 1024 => 768, 768 => 1024, 375 => 667, 320 => 568] as $width => $height) {
            $page = shellPage($user, $width, $height);
            shellOpen($page, '/rules', $problems);
            $m = $page->evaluate(SHELL_MEASURE);
            fwrite(STDERR, "\n[shell] {$role} @{$width}: ".json_encode($m));

            if ($m['scroll'] > $m['client']) {
                $failures[] = "{$role} @{$width}: document {$m['scroll']} px wide in {$m['client']} px";
            }
            if ($m['squeezed'] !== []) {
                $failures[] = "{$role} @{$width}: squeezed ".json_encode($m['squeezed']);
            }
            if ($m['small'] !== []) {
                $failures[] = "{$role} @{$width}: small targets ".json_encode($m['small']);
            }
            if ($width >= 1024) {
                // Row 1 (64) and row 2 (48); a guest's "New here?" strip comes on top until dismissed.
                $rows = array_sum(array_map(fn (string $row): int => (int) substr(strrchr($row, ' '), 1), array_filter($m['rows'], fn (string $row) => ! str_starts_with($row, 'first-steps'))));
                expect($rows)->toBeLessThanOrEqual(112, "{$role} @{$width}: chrome rows ".json_encode($m['rows']));
                expect($m['tabbar'])->toBeNull();
            } else {
                expect($m['tabbar'])->not->toBeNull()
                    ->and($m['tabbar']['bottom'])->toBe($height)
                    ->and($m['tabbar']['height'])->toBe(64);
            }
            if (in_array($width, [375, 1440], true) || ($width === 1024 && $role === 'admin')) {
                shellShot($page, "shell-{$role}-{$width}-closed");
            }
        }
    }

    // German labels run longer ("Einstellungen", "Herausfordern"): the tightest desktop widths once more, as the admin.
    foreach ([1024 => 768, 1280 => 800, 1440 => 900] as $width => $height) {
        $page = shellPage($users['admin'], $width, $height);
        $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
        shellOpen($page, '/rules', $problems);
        $m = $page->evaluate(SHELL_MEASURE);
        fwrite(STDERR, "\n[shell] admin de @{$width}: ".json_encode($m));
        expect($m['lang'])->toBe('de')
            ->and($m['scroll'])->toBeLessThanOrEqual($m['client'])
            ->and($m['squeezed'])->toBe([]);
    }

    expect($failures)->toBe([])->and($problems)->toBe([]);
});

test('row 1 fits Tournaments with its sign-up count and room for the LIVE badge (94 px with its count) at 1024, 1280, 1440, 1680 and 1920 px, in English and German', function () {
    Tournament::factory()->signup()->count(2)->create(['signup_closes_at' => now()->addDays(2)]);
    $admin = shellAdmin();
    $problems = [];
    $failures = [];
    $sizes = [];
    // P20 puts a LIVE badge after Clans and Season (64 x 44, 94 x 44 with a count): a stand-in of the wider one, so this row keeps room for it.
    $placeholder = '() => { const season = document.querySelector("[data-test=nav-mining]"); const live = Object.assign(document.createElement("span"), { textContent: "LIVE" }); live.style.cssText = "flex: none; width: 94px; height: 44px; align-self: center"; live.dataset.test = "live-placeholder"; season.after(live); }';

    foreach (['en', 'de'] as $locale) {
        foreach ([1024 => 768, 1280 => 800, 1440 => 900, 1680 => 1050, 1920 => 1080] as $width => $height) {
            $page = shellPage($admin, $width, $height);
            if ($locale === 'de') {
                $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
            }
            shellOpen($page, '/rules', $problems);
            $page->evaluate($placeholder);
            $m = $page->evaluate(SHELL_MEASURE);
            $badge = $page->evaluate('() => { const el = document.querySelector("[data-test=tournaments-open]"); if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join("")]; }');
            $sizes["{$locale}@{$width}"] = ['squeezed' => $m['squeezed'], 'scroll' => $m['scroll'], 'client' => $m['client'], 'badge' => $badge];
            if ($m['lang'] !== $locale || $m['scroll'] > $m['client'] || $m['squeezed'] !== [] || $badge === null || $badge[2] !== '2') {
                $failures[] = "{$locale} @{$width}: ".json_encode($sizes["{$locale}@{$width}"]);
            }
            if ($locale === 'en' && in_array($width, [1024, 1440], true)) {
                shellShot($page, "shell-admin-{$width}-tournaments");
            }
        }
    }

    fwrite(STDERR, "\n[shell-tournaments] ".json_encode($sizes));
    expect($failures)->toBe([])->and($problems)->toBe([]);
});

test('on a phone the active game chip shows whole and no chip label is cut, with each game active, at 320 and 375 px, and in German at 320 px for a guest and a player', function () {
    $problems = [];
    $failures = [];
    $pages = [
        'chess' => route('chess.lobby', absolute: false),
        'rocket-league' => route('games.rocket-league', absolute: false),
        'ea-sports-fc-27' => route('games.series', 'ea-sports-fc-27', false),
        'ea-sports-fc-26' => route('games.series', 'ea-sports-fc-26', false),
    ];
    // The guests first: the pages of one test share their cookies, so a guest after the login would not be one.
    $runs = [['guest', null, 'en', 375, 667], ['guest', null, 'en', 320, 568], ['guest', null, 'de', 320, 568], ['player', shellPlayer(), 'de', 320, 568]];

    foreach ($runs as [$role, $user, $locale, $width, $height]) {
        $page = shellPage($user, $width, $height);
        if ($locale === 'de') {
            // German "Schach" (73 px) did not fit the 67 px a German guest's chip row had at 320 px.
            $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
        }

        foreach ($pages as $slug => $url) {
            shellOpen($page, $url, $problems);
            $m = $page->evaluate(SHELL_MEASURE);
            $chips = $page->evaluate('() => [...document.querySelectorAll("#game-chips .gchip")].map((c) => `${c.textContent.trim()} ${Math.round(c.getBoundingClientRect().width)}${c.hasAttribute("aria-current") ? " active" : ""}`)');
            fwrite(STDERR, "\n[shell-chips] {$role} {$locale} {$slug} @{$width}: ".json_encode($chips));
            if ($m['lang'] !== $locale || $m['squeezed'] !== [] || $m['small'] !== []) {
                $failures[] = "{$role} {$locale} {$slug} @{$width}: ".json_encode(['lang' => $m['lang'], 'squeezed' => $m['squeezed'], 'small' => $m['small']]);
            }
            if ($width === 375 && $slug === 'rocket-league') {
                shellShot($page, 'shell-guest-375-chips-rocket-league');
            }
            if ($locale === 'de' && $slug === 'chess') {
                shellShot($page, "shell-{$role}-320-de-chips-chess");
            }
        }
    }

    expect($failures)->toBe([])->and($problems)->toBe([]);
});
