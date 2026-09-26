<?php

use App\Enums\LineupRole;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanLogos;
use App\Support\Clans\ClanService;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;
use Tests\Support\ParseMultipartBody;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Edit clan: logo upload, preview, signed save
|--------------------------------------------------------------------------
|
| The owner opens the "Edit clan" card, picks a logo file (a real multipart
| upload through Livewire), sees the preview, signs with the stubbed
| window.nostr and then finds the new logo on the manage page and on the
| public clan page. The public disk points into a throwaway folder under
| public/ for this test, because the in-process server serves public/ only
| (no `storage:link` in a test run).
|
| Collected on every page: console.error, uncaught errors, rejected promises,
| and every response >= 400 (fetch, XHR and the resource timing entries of
| images and scripts).
|
| CLAN_EDIT_SHOTS=<dir> additionally writes the English screenshots there.
|
*/

const CLAN_EDIT_COLLECTOR = <<<'JS'
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

/** Every resource and the document itself answered below 400. */
const CLAN_EDIT_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

const CLAN_EDIT_NO_OVERFLOW = '() => document.documentElement.scrollWidth <= document.documentElement.clientWidth';

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

    // Livewire uploads are multipart; the in-process server hands them over unparsed.
    app(HttpKernel::class)->prependMiddleware(ParseMultipartBody::class);
    // Under runningUnitTests() Livewire keeps temporary uploads on this disk.
    Storage::fake('tmp-for-tests');

    $this->logoRoot = public_path('__test-clan-logos-'.bin2hex(random_bytes(4)));
    config(['filesystems.disks.public.root' => $this->logoRoot, 'filesystems.disks.public.url' => '/'.basename($this->logoRoot)]);
    Storage::forgetDisk('public');
});

afterEach(function () {
    File::deleteDirectory($this->logoRoot);
});

function clanEditPage(User $user, string $to, int $width): Page
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(CLAN_EDIT_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

function clanEditShot(Page $page, string $name): void
{
    $dir = getenv('CLAN_EDIT_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * A logo that is recognisable in a screenshot: orange ground, dark square.
 */
function clanEditLogoFile(): string
{
    $image = imagecreatetruecolor(800, 600);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 247, 147, 26));
    imagefilledrectangle($image, 250, 150, 550, 450, (int) imagecolorallocate($image, 23, 18, 10));
    $path = storage_path('framework/testing/clan-edit-logo.png');
    File::ensureDirectoryExists(dirname($path));
    imagepng($image, $path);

    return $path;
}

test('the owner uploads a logo, sees the preview, signs, and the logo shows on the manage and the public page', function () {
    [$owner, $member] = User::factory()->count(2)->create();
    $signer = TestSigner::forBrowser($owner);
    $memberSigner = TestSigner::forBrowser($member);
    $service = app(ClanService::class);
    $draft = new ClanDraft('Laser Eyes', 'LSR', 'Rocket League clan of the Kempten meetup.');
    $clan = $service->create($owner, $draft, $signer->signTemplates($service->prepareCreate($owner, $draft)));
    $invite = $service->invite($owner, $clan, $member, $signer->signTemplates($service->prepareInvite($owner, $clan, $member)));
    $service->accept($invite, $member, $memberSigner->signTemplates($service->prepareAccept($invite, $member)));
    $seats = [$owner->id => LineupRole::Captain, $member->id => LineupRole::Player];
    $service->saveLineup($owner, $clan, 'rocket-league', '2v2', $seats, $signer->signTemplates($service->prepareLineup($owner, $clan->refresh(), 'rocket-league', '2v2', $seats)));
    $lineupEvent = $clan->lineups()->sole()->event_id;

    $manage = route('clans.manage', $clan, false);

    // Narrow first: the open card with a preview must fit 375 px.
    foreach ([375, 1440] as $width) {
        $page = clanEditPage($owner, $manage, $width);
        BrowserWait::until($page, '() => document.querySelector("[data-test=open-edit]") !== null', 10_000);
        $page->locator('[data-test=open-edit]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=logo-input]") !== null', 10_000);
        $page->locator('[data-test=logo-input]')->setInputFiles(clanEditLogoFile());
        BrowserWait::until($page, '() => document.querySelector("[data-test=logo-preview-upload]")?.naturalWidth > 0', 15_000);

        $card = $page->evaluate('() => { const r = document.querySelector("[data-test=edit-clan]").getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.width)]; }');
        fwrite(STDERR, "\n[clan-edit] {$width}px card left/right/width: ".json_encode($card).', scrollWidth/clientWidth: '.json_encode($page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]'))."\n");

        expect($page->evaluate(CLAN_EDIT_NO_OVERFLOW))->toBeTrue()
            ->and($card[1])->toBeLessThanOrEqual($width);
        clanEditShot($page, "clan-edit-preview-{$width}");
    }

    // Desktop page, preview showing: sign and save.
    $page->locator('[data-test=save-edit]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=logo-input]") === null && document.querySelector("#edit-clan") !== null', 15_000);

    $clan->refresh();
    $file = Storage::disk('public')->files(ClanLogos::DIRECTORY);

    expect($file)->toHaveCount(1)
        ->and($clan->picture)->toEndWith('/'.$file[0])
        ->and($clan->picture)->toStartWith('http')
        ->and(NostrEvent::query()->where('event_id', $clan->event_id)->sole()->payload()['tags'])->toContain(['picture', $clan->picture])
        ->and($clan->members()->count())->toBe(2)
        ->and($clan->lineups()->sole()->event_id)->toBe($lineupEvent);

    $logoSelector = 'img[src="'.$clan->picture.'"]';
    BrowserWait::until($page, '() => document.querySelector('.json_encode($logoSelector).')?.naturalWidth === 512', 10_000);
    clanEditShot($page, 'clan-edit-saved-1440');

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(CLAN_EDIT_BAD_RESPONSES))->toBe([]);

    // The public clan page, both widths.
    foreach ([375, 1440] as $width) {
        $public = clanEditPage($owner, route('clans.show', $clan, false), $width);
        BrowserWait::until($public, '() => document.querySelector('.json_encode($logoSelector).')?.naturalWidth === 512', 10_000);
        clanEditShot($public, "clan-public-{$width}");

        expect($public->evaluate('() => window.__errors'))->toBe([])
            ->and($public->evaluate(CLAN_EDIT_BAD_RESPONSES))->toBe([])
            ->and($public->evaluate(CLAN_EDIT_NO_OVERFLOW))->toBeTrue();
    }
});

test('the collectors see a broken image and a thrown error (positive control)', function () {
    $owner = User::factory()->create();
    $signer = TestSigner::forBrowser($owner);
    $service = app(ClanService::class);
    $draft = new ClanDraft('Laser Eyes', 'LSR', null, '/'.basename($this->logoRoot).'/clan-logos/missing.png');
    $clan = $service->create($owner, $draft, $signer->signTemplates($service->prepareCreate($owner, $draft)));

    $page = clanEditPage($owner, route('clans.manage', $clan, false), 1440);
    BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=open-edit]") !== null', 10_000);
    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);

    expect(implode("\n", $page->evaluate(CLAN_EDIT_BAD_RESPONSES)))->toMatch('#^404 http://\S+/clan-logos/missing\.png$#m');
});
