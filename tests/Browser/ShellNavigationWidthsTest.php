<?php

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

test('on a phone the active game chip shows whole and no chip label is cut, with each game active, at 320 and 375 px', function () {
    $problems = [];
    $failures = [];
    $pages = [
        'chess' => route('chess.lobby', absolute: false),
        'rocket-league' => route('games.rocket-league', absolute: false),
        'ea-sports-fc-27' => route('games.series', 'ea-sports-fc-27', false),
        'ea-sports-fc-26' => route('games.series', 'ea-sports-fc-26', false),
    ];

    foreach ([375 => 667, 320 => 568] as $width => $height) {
        $page = shellPage(null, $width, $height);

        foreach ($pages as $slug => $url) {
            shellOpen($page, $url, $problems);
            $m = $page->evaluate(SHELL_MEASURE);
            $chips = $page->evaluate('() => [...document.querySelectorAll("#game-chips .gchip")].map((c) => `${c.textContent.trim()} ${Math.round(c.getBoundingClientRect().width)}${c.hasAttribute("aria-current") ? " active" : ""}`)');
            fwrite(STDERR, "\n[shell-chips] {$slug} @{$width}: ".json_encode($chips));
            if ($m['squeezed'] !== []) {
                $failures[] = "{$slug} @{$width}: ".json_encode($m['squeezed']);
            }
            if ($width === 375 && $slug === 'rocket-league') {
                shellShot($page, 'shell-guest-375-chips-rocket-league');
            }
        }
    }

    expect($failures)->toBe([])->and($problems)->toBe([]);
});
