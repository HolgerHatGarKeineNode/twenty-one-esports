<?php

use App\Models\Clan;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Clans\ClanLogos;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Clan logos in the lists: chess ladder, clan list, match list
|--------------------------------------------------------------------------
|
| Every clan tag chip shows the clan's uploaded logo in front of the tag
| (<x-clan-tag>). Measured at 375 and 1440 px: no horizontal overflow, every
| visible logo decoded (naturalWidth > 0), a clean console and no response
| >= 400. The collector is the one from ClanEditTest, with its positive
| control below. The public disk points into a throwaway folder under
| public/, because the in-process server serves public/ only.
|
| CLAN_LOGO_SHOTS=<dir> additionally writes the English screenshots there.
|
*/

const CLAN_LOGO_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
    window.addEventListener('error', (e) => push('error: ' + (e.message || (e.target && (e.target.src || e.target.href)) || 'unknown')), true);
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400 || this.status === 0) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

const CLAN_LOGO_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

const CLAN_LOGO_OVERFLOW = '() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]';

/** Visible logos scrolled into view one by one, so lazy loading fetches each. */
const CLAN_LOGO_VISIBLE = <<<'JS'
    async () => {
        const logos = [...document.querySelectorAll('img[data-clan-logo]')].filter((img) => img.checkVisibility());
        for (const img of logos) {
            img.scrollIntoView({ block: 'center' });
            await new Promise((done) => img.complete ? done() : img.addEventListener('load', done, { once: true }) || setTimeout(done, 3000));
        }
        window.scrollTo(0, 0);
        return logos.map((img) => {
            const chip = img.parentElement.getBoundingClientRect();
            const r = img.getBoundingClientRect();
            return { natural: img.naturalWidth, w: Math.round(r.width), h: Math.round(r.height), chipH: Math.round(chip.height), loading: img.loading, alt: img.getAttribute('alt') };
        });
    }
    JS;

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

    $this->logoRoot = public_path('__test-clan-logo-lists-'.bin2hex(random_bytes(4)));
    config(['filesystems.disks.public.root' => $this->logoRoot, 'filesystems.disks.public.url' => '/'.basename($this->logoRoot)]);
    Storage::forgetDisk('public');
});

afterEach(function () {
    File::deleteDirectory($this->logoRoot);
});

function clanLogoPage(User $user, string $to, int $width): Page
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(CLAN_LOGO_COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

/**
 * A distinct logo per clan, stored the way the manage page stores it.
 * Called after the first visit: the URL carries the test server's origin.
 *
 * @param  array{0: int, 1: int, 2: int}  $rgb
 */
function clanLogoStore(array $rgb): string
{
    $image = imagecreatetruecolor(ClanLogos::SIZE, ClanLogos::SIZE);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$rgb));
    imagefilledellipse($image, 256, 256, 300, 300, (int) imagecolorallocate($image, 250, 250, 250));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    $logos = app(ClanLogos::class);
    $logos->store($png);

    return $logos->urlFor($png);
}

function clanLogoShot(Page $page, string $name): void
{
    $dir = getenv('CLAN_LOGO_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('clan logos show in front of the tag on the chess ladder, the clan list and the match list', function () {
    $matches = SeriesMatch::factory()->accepted()->count(2)->create();
    $clans = $matches->flatMap(fn (SeriesMatch $match) => [$match->sideClan('challenger'), $match->sideClan('challenged')])->values();
    $viewer = User::factory()->create();

    foreach ($clans as $index => $clan) {
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$clan->owner_id,
            'user_id' => $clan->owner_id, 'rating' => 1100 - $index * 20, 'results' => 5, 'wins' => 3, 'draws' => 0, 'losses' => 2]);
    }

    // Boots the test server, so the logo URLs carry its origin.
    visit(route('testing.login', ['user' => $viewer, 'to' => '/']));
    $colors = [[247, 147, 26], [40, 90, 200], [30, 150, 90]];
    foreach ($colors as $index => $rgb) {
        $clans[$index]->update(['picture' => clanLogoStore($rgb)]);
    }
    /** @var Clan $plain the fourth clan keeps the plain tag chip */
    $plain = $clans[3];

    foreach (['ladder' => '/ladder/chess/blitz?pool=casual', 'clans' => '/clans', 'matches' => '/matches'] as $name => $path) {
        foreach ([375, 1440] as $width) {
            $page = clanLogoPage($viewer, $path, $width);
            BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("img[data-clan-logo]") !== null', 10_000);

            $logos = $page->evaluate(CLAN_LOGO_VISIBLE);
            $overflow = $page->evaluate(CLAN_LOGO_OVERFLOW);
            fwrite(STDERR, "\n[clan-logo] {$name} {$width}px scrollWidth/clientWidth ".json_encode($overflow).' logos '.json_encode($logos)."\n");

            expect($logos)->not->toBeEmpty()
                ->and(collect($logos)->every(fn (array $logo) => $logo['natural'] > 0 && $logo['loading'] === 'lazy' && $logo['alt'] === ''))->toBeTrue()
                ->and($overflow[0])->toBeLessThanOrEqual($overflow[1])
                ->and($page->evaluate('() => document.body.innerText'))->toContain($plain->clantag);

            clanLogoShot($page, "clan-logo-{$name}-{$width}");

            expect($page->evaluate('() => window.__errors'))->toBe([])
                ->and($page->evaluate(CLAN_LOGO_BAD_RESPONSES))->toBe([]);
        }
    }
});

test('the collectors see a broken logo and a thrown error (positive control)', function () {
    $clan = Clan::factory()->create();
    $viewer = User::factory()->create();
    visit(route('testing.login', ['user' => $viewer, 'to' => '/']));
    // One of ours by its form, but no file behind it.
    $clan->update(['picture' => url('/'.basename($this->logoRoot).'/'.ClanLogos::DIRECTORY.'/'.str_repeat('0', 64).'.png')]);

    $page = clanLogoPage($viewer, '/clans', 1440);
    BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("img[data-clan-logo]") !== null', 10_000);
    $page->evaluate(CLAN_LOGO_VISIBLE);
    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);

    expect(implode("\n", $page->evaluate(CLAN_LOGO_BAD_RESPONSES)))->toMatch('#^404 http://\S+/clan-logos/0{64}\.png$#m');
});
