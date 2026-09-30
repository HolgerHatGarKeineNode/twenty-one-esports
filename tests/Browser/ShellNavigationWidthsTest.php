<?php

use App\Enums\SeriesStatus;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Support\Navigation\ShellNavigation;
use App\Support\TwentyOne\LiveStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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

test('row 1 fits its widest real state: Tournaments with its sign-up count, a four-digit mempool count, 12 open cases on Admin and the LIVE badge with a three-digit count, at 1024, 1280, 1440, 1600, 1680 and 1920 px, in English and German, for a guest and an admin', function () {
    Tournament::factory()->signup()->count(2)->create(['signup_closes_at' => now()->addDays(2)]);
    // The widest counts row 1 must hold (plan "Mempool-Streifen", P4): four digits in the mempool, cached as the header
    // reads it, and two on Admin (12 disputed series). Without the cases an admin's row 1 looked 35 px roomier than it is.
    Cache::put(ShellNavigation::MEMPOOL_KEY, 1234, 3600);
    SeriesMatch::factory()->accepted()->count(12)->create(['status' => SeriesStatus::Disputed]);
    $admin = shellAdmin();
    $problems = [];
    $failures = [];
    $sizes = [];
    // The stream on air with a three-digit count (P20): the real LIVE badge after Clans and Season, at its widest.
    $hls = sys_get_temp_dir().'/esports-shell-live-'.getmypid().'-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($hls);
    config(['twentyone.stream.hls_dir' => $hls, 'twentyone.stream.public_url' => '/__test/live/stream.m3u8']);
    Cache::put(LiveStatus::ANNOUNCED_KEY, ['viewers' => 128], 3600);
    $onAir = function () use ($hls): void {
        file_put_contents($hls.'/stream.m3u8', "#EXTM3U\n");
        Cache::forget(LiveStatus::CACHE_KEY);
    };
    $liveBadge = '() => { const b = [...document.querySelectorAll("[data-test=live-badge]")].find((el) => el.checkVisibility()); if (!b) return null; const r = b.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height), !!b.closest("[data-test=game-tabs]")]; }';

    // The guest first: the pages of one test share their cookies, so a guest after the login would not be one.
    foreach (['guest' => null, 'admin' => $admin] as $role => $user) {
        foreach (['en', 'de'] as $locale) {
            foreach ([1024 => 768, 1280 => 800, 1440 => 900, 1600 => 900, 1680 => 1050, 1920 => 1080] as $width => $height) {
                $onAir();
                $page = shellPage($user, $width, $height);
                $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
                shellOpen($page, '/rules', $problems);
                $m = $page->evaluate(SHELL_MEASURE);
                $row = $page->evaluate(SHELL_ROW1);
                $live = $page->evaluate($liveBadge);
                $badge = $page->evaluate('() => { const el = document.querySelector("[data-test=tournaments-open]"); if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join("")]; }');
                $key = "{$role} {$locale}@{$width}";
                $sizes[$key] = ['squeezed' => $m['squeezed'], 'scroll' => $m['scroll'], 'client' => $m['client'], 'badge' => $badge, 'live' => $live, 'row' => $row];
                fwrite(STDERR, "\n[shell-row1] {$key}: ".json_encode($row));
                // The rail: the count and Casual at every width; the Block 0 tag from 96rem.
                $rail = $row['count'] === '1234' && $row['admin'] === ($role === 'admin' ? '12' : null) && $row['casual'] !== null && ($row['tag'] !== null) === ($width >= 1536);
                if ($m['lang'] !== $locale || $m['scroll'] > $m['client'] || $m['squeezed'] !== [] || $row['problems'] !== [] || ! $rail || $badge === null || $badge[2] !== '2' || $live === null || $live[2] !== true) {
                    $failures[] = "{$key}: ".json_encode($sizes[$key]);
                }
                if ($locale === 'en' && $role === 'admin' && in_array($width, [1024, 1440], true)) {
                    shellShot($page, "shell-admin-{$width}-tournaments");
                }
            }
        }
    }

    // Phones: the Tournaments tab carries the dot, inside its icon's box (a dot past the edge made the label's span scroll).
    $page = shellPage($admin, 375, 667);
    // The pages of one test share their cookies: back to English after the German run.
    $page->goto(ComputeUrl::from(route('locale.switch', 'en', false)));
    shellOpen($page, '/rules', $problems);
    $dot = $page->evaluate('() => { const d = document.querySelector("[data-test=tab-tournaments-dot]"); const t = document.querySelector("[data-test=tab-tournaments]"); if (!d || !d.checkVisibility()) return null; const r = d.getBoundingClientRect(); const b = t.getBoundingClientRect(); return { dot: [Math.round(r.width), Math.round(r.height)], inside: r.left >= b.left && r.right <= b.right && r.top >= b.top, name: t.getAttribute("aria-label"), squeezed: ('.SHELL_MEASURE.')().squeezed }; }');
    fwrite(STDERR, "\n[shell-tournaments] 375 tab dot ".json_encode($dot));
    expect($dot)->toMatchArray(['dot' => [8, 8], 'inside' => true, 'name' => 'Tournaments, 2 open for sign-up', 'squeezed' => []]);
    shellShot($page, 'shell-admin-375-tournaments-dot');

    fwrite(STDERR, "\n[shell-tournaments] ".json_encode($sizes));
    File::deleteDirectory($hls);
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
        'age-of-empires-2' => route('games.series', 'age-of-empires-2', false),
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
