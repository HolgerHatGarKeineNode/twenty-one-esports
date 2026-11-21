<?php

use App\Models\Clan;
use App\Models\ImpersonationHold;
use App\Models\Lineup;
use App\Models\User;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The warning when a member accepts (plan "Profile standard and
| impersonation", P4)
|--------------------------------------------------------------------------
|
| /settings/opponents with three requests: one below the profile standard,
| one under an impersonation hold, one that meets everything. The first two
| carry a warning; their Accept only opens the confirm (focus on Cancel,
| nothing signed), Cancel closes it, "Accept anyway" signs with the stubbed
| window.nostr and the lists are mutual. The third accepts in one click as
| before. At 390 and 1440 px, German and English: no horizontal overflow,
| the block inside the viewport, every button and link at least 44 x 44 px,
| no button text on two lines, nothing squeezed, the console and every
| response clean (a thrown error as positive control at the end).
|
| D17: the same warning and confirm on the opponent button, on a player
| page ("Add as opponent", the player does not list you) and in the match
| room (accepting the challenger's request inside the "do not list each
| other" notice), each at 390 and 1440 in German and English.
|
| AW_SHOTS=<dir> additionally writes the screenshots there.
|
*/

const AW_COLLECTOR = <<<'JS'
    window.__errors = JSON.parse(sessionStorage.getItem('__errors') ?? '[]');
    const push = (entry) => { window.__errors.push(entry); sessionStorage.setItem('__errors', JSON.stringify(window.__errors)); };
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

const AW_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

/** Interactive elements under the selector that are visible and smaller than 44 x 44 px. */
const AW_SMALL_TARGETS = <<<'JS'
    (selector) => [...document.querySelectorAll(selector)].flatMap((root) => [...root.querySelectorAll('a, button, summary')])
        .filter((e) => e.checkVisibility())
        .map((e) => { const r = e.getBoundingClientRect(); return [e.dataset.test || e.textContent.trim().slice(0, 24), Math.round(r.width), Math.round(r.height)]; })
        .filter(([, w, h]) => w < 44 || h < 44)
    JS;

/** Visible buttons under the selector whose label runs over more than one line (R11), with the number of lines. */
const AW_WRAPPED_BUTTONS = <<<'JS'
    (selector) => [...document.querySelectorAll(selector)].flatMap((root) => [...root.querySelectorAll('button')])
        .filter((e) => e.checkVisibility() && e.textContent.trim() !== '')
        .map((e) => { const walker = document.createTreeWalker(e, NodeFilter.SHOW_TEXT), tops = new Set();
            for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                if (node.textContent.trim() === '') continue;
                const range = document.createRange(); range.selectNodeContents(node);
                [...range.getClientRects()].filter((r) => r.width > 0).forEach((r) => tops.add(Math.round(r.top)));
            }
            return [e.dataset.test || e.textContent.trim().slice(0, 24), tops.size]; })
        .filter(([, lines]) => lines > 1)
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    // The requesters' pictures (needed for the profile mark) go through an "image proxy" that is the league's own
    // icon on this server: the avatars load for real, and the error collector stays strict about everything else.
    config(['session.driver' => 'database', 'esports.image_proxy_url' => '/icon-192.png?proxy=']);
    config(['esports.fair_play.profile_standard.enabled' => true, 'esports.fair_play.profile_standard.since' => null, 'esports.fair_play.impersonation.enabled' => true]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);
});

function awPage(User $user, string $to, int $width, string $locale): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(AW_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.documentElement.lang === '.json_encode($locale).' && window.Alpine !== undefined', 10_000);

    return $page;
}

function awShot(Page $page, string $name): void
{
    $dir = getenv('AW_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** At this width: no overflow, the block inside the viewport, targets of 44 px, no wrapped button, nothing squeezed, no error, no bad response. */
function awMeasure(Page $page, string $selector, int $width, string $label): void
{
    $page->setViewportSize($width, 900);
    BrowserWait::until($page, '() => document.documentElement.clientWidth === '.$width, 5_000);
    $page->evaluate('() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
    $box = $page->evaluate('() => { const r = document.querySelector('.json_encode($selector).').getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.width), Math.round(r.height)]; }');
    $scroll = $page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]');
    $small = $page->evaluate(AW_SMALL_TARGETS, $selector);
    $wrapped = $page->evaluate(AW_WRAPPED_BUTTONS, $selector);
    $squeezed = $page->evaluate(BrowserConsole::SQUEEZED, [$selector]);
    fwrite(STDERR, "\n[aw] {$label} {$width}px {$selector} left/right/width/height: ".json_encode($box).', scrollWidth/clientWidth: '.json_encode($scroll)
        .', small targets: '.json_encode($small).', wrapped buttons: '.json_encode($wrapped).', squeezed: '.json_encode($squeezed)."\n");

    expect($box[2])->toBeGreaterThan(0)
        ->and($scroll[0])->toBeLessThanOrEqual($scroll[1])
        ->and($box[0])->toBeGreaterThanOrEqual(0)
        ->and($box[1])->toBeLessThanOrEqual($width)
        ->and($small)->toBe([])
        ->and($wrapped)->toBe([])
        ->and($squeezed)->toBe([])
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(AW_BAD_RESPONSES))->toBe([]);
}

/** A requester who has added `$member` to their list (signed like the browser does). */
function awRequest(User $member, array $attributes): User
{
    $player = User::factory()->create($attributes);
    $signer = TestSigner::forBrowser($player);
    $opponents = app(Opponents::class);
    $opponents->add($player, $member, $signer->signTemplates($opponents->prepareAdd($player, $member)));

    return $player;
}

/** One server roundtrip of the Livewire component around the element (a $refresh), awaited. */
function awRefresh(Page $page, string $selector = '[data-test=opponent-settings]'): void
{
    $page->evaluate('() => Livewire.find(document.querySelector('.json_encode($selector).').closest("[wire\\\\:id]").getAttribute("wire:id")).$refresh()');
    $page->evaluate('() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
}

function awCardSelector(User $player, string $inner = ''): string
{
    return '[data-test=opponent-request][data-pubkey="'.$player->pubkey.'"]'.($inner === '' ? '' : ' '.$inner);
}

test('a request below the standard or under a hold warns, takes a confirm before anything is signed, and is accepted after it; a clean one accepts in one click, at 390 and 1440 in German and English', function () {
    $met = ['picture' => 'https://example.com/p.png', 'nip05' => 'x@example.com', 'nip05_verified_at' => now()->subDays(20), 'created_at' => now()->subMonths(2)];
    $anna = User::factory()->create(['name' => 'anna', ...$met]);
    TestSigner::forBrowser($anna);
    $fresh = awRequest($anna, ['name' => 'Neuling Längenfeld', 'picture' => null, 'nip05' => null, 'nip05_verified_at' => null, 'created_at' => now()->subDays(2)]);
    $original = User::factory()->create(['name' => 'Odell', 'created_at' => now()->subYear()]);
    $held = awRequest($anna, ['name' => 'Odeli', ...$met]);
    ImpersonationHold::factory()->create(['user_id' => $held->id, 'pubkey' => $held->pubkey, 'matched_user_id' => $original->id, 'matched_pubkey' => $original->pubkey]);
    $clean = awRequest($anna, ['name' => 'bert', ...$met]);
    $late = awRequest($anna, ['name' => 'Spätzünder', ...$met]);
    $turn = awRequest($anna, ['name' => 'Wendehals', ...$met]);
    $lateHold = ImpersonationHold::factory()->create(['user_id' => $late->id, 'pubkey' => $late->pubkey, 'matched_user_id' => $original->id, 'matched_pubkey' => $original->pubkey]);
    $opponents = app(Opponents::class);

    foreach (['de' => $fresh, 'en' => $held] as $locale => $requester) {
        $page = awPage($anna, route('settings.opponents', [], false), 390, $locale);
        BrowserWait::until($page, '() => document.querySelector('.json_encode(awCardSelector($requester)).') !== null', 10_000);

        $lines = $page->evaluate('() => Object.fromEntries([...document.querySelectorAll("[data-test=opponent-request]")].map((c) => [c.dataset.pubkey, [...c.querySelectorAll("[data-test^=opponent-request-warning-]")].map((l) => l.dataset.test.replace("opponent-request-warning-", ""))]))');
        $texts = $page->evaluate('() => [...document.querySelectorAll("[data-test=opponent-request-warning]")].map((w) => w.innerText.replace(/\s+/g, " "))');
        fwrite(STDERR, "\n[aw] {$locale} warning lines: ".json_encode($lines).', texts: '.json_encode($texts, JSON_UNESCAPED_UNICODE)."\n");

        expect($lines[$held->pubkey] ?? null)->toBe(['held'])
            ->and($lines[$clean->pubkey] ?? null)->toBe([])
            ->and(mb_strtolower(implode(' ', $texts)))->not->toContain('odell')->not->toContain('imperson')->not->toContain('gesicht')->not->toContain('face')
            ->and(implode(' ', $texts))->toContain($locale === 'de' ? 'Dieses Konto wird gerade geprüft.' : 'This account is being checked.');
        if ($locale === 'de') {
            expect($lines[$fresh->pubkey])->toBe(['known', 'nip05', 'profile'])
                ->and(implode(' ', $texts))->toContain('Keine NIP-05-Adresse festgelegt.')->toContain('Profilbild');
        }

        foreach ([390, 1440] as $width) {
            awMeasure($page, '[data-test=opponent-requests]', $width, "{$locale} warnings");
            awShot($page, "opponents-accept-warning-{$width}x900-{$locale}");
        }

        // Accept opens the confirm only: focus on Cancel, the other actions out of the way, nothing signed.
        $page->setViewportSize(390, 900);
        $page->locator(awCardSelector($requester, '[data-test=opponent-request-accept]'))->click();
        BrowserWait::until($page, '() => document.querySelector('.json_encode(awCardSelector($requester, '[data-test=opponent-request-confirm]')).')?.checkVisibility() === true', 5_000);
        BrowserWait::until($page, '() => document.activeElement?.dataset.test === "opponent-request-confirm-cancel"', 5_000);
        expect($page->evaluate('() => document.querySelector('.json_encode(awCardSelector($requester, '[data-test=opponent-request-decline]')).').checkVisibility()'))->toBeFalse()
            ->and($opponents->entries($anna))->not->toContain($requester->pubkey);

        foreach ([390, 1440] as $width) {
            awMeasure($page, awCardSelector($requester), $width, "{$locale} confirm open");
            awShot($page, "opponents-accept-confirm-{$width}x900-{$locale}");
        }

        // Cancel closes it again, focus back on Accept, still nothing signed.
        $page->locator(awCardSelector($requester, '[data-test=opponent-request-confirm-cancel]'))->click();
        BrowserWait::until($page, '() => document.querySelector('.json_encode(awCardSelector($requester, '[data-test=opponent-request-confirm]')).').checkVisibility() === false && document.activeElement?.dataset.test === "opponent-request-accept"', 5_000);
        expect($opponents->entries($anna))->not->toContain($requester->pubkey);

        // Accept, then "Accept anyway": signed, the request goes, the lists are mutual.
        $this->travel(2)->seconds();
        $page->locator(awCardSelector($requester, '[data-test=opponent-request-accept]'))->click();
        BrowserWait::until($page, '() => document.activeElement?.dataset.test === "opponent-request-confirm-cancel"', 5_000);
        // A server roundtrip while the confirm is open keeps it open (the card is keyed, its x-data stable).
        awRefresh($page);
        expect($page->evaluate('() => document.querySelector('.json_encode(awCardSelector($requester, '[data-test=opponent-request-confirm]')).')?.checkVisibility()'))->toBeTrue();
        $page->locator(awCardSelector($requester, '[data-test=opponent-request-confirm-accept]'))->click();
        BrowserWait::until($page, '() => document.querySelector('.json_encode(awCardSelector($requester)).') === null', 15_000);

        expect($opponents->listEachOther($anna->refresh(), $requester))->toBeTrue();
        awMeasure($page, '[data-test=opponent-settings]', 390, "{$locale} accepted");
    }

    // The clean request: no warning, one click signs as before. (A new list version needs a later second than the
    // last one, see Opponents; the clock moves on instead of the test sleeping.)
    $this->travel(2)->seconds();
    $page->locator(awCardSelector($clean, '[data-test=opponent-request-accept]'))->click();
    BrowserWait::until($page, '() => document.querySelector('.json_encode(awCardSelector($clean)).') === null', 15_000);
    expect($opponents->listEachOther($anna->refresh(), $clean))->toBeTrue();
    awMeasure($page, '[data-test=opponent-settings]', 1440, 'en clean accepted');

    // A warning that goes away between renders (the hold ends while the confirm is open): the card falls back to
    // the plain one-click accept, no confirm left over.
    $page->locator(awCardSelector($late, '[data-test=opponent-request-accept]'))->click();
    BrowserWait::until($page, '() => document.querySelector('.json_encode(awCardSelector($late, '[data-test=opponent-request-confirm]')).')?.checkVisibility() === true', 5_000);
    $lateHold->forceFill(['decided_at' => now(), 'decision' => ImpersonationHold::RELEASED])->save();
    $this->travel(2)->seconds();
    awRefresh($page);
    BrowserWait::until($page, '() => document.querySelector('.json_encode(awCardSelector($late, '[data-test=opponent-request-warning]')).') === null', 5_000);
    expect($page->evaluate('() => [document.querySelector('.json_encode(awCardSelector($late, '[data-test=opponent-request-confirm]')).'), document.querySelector('.json_encode(awCardSelector($late, '[data-test=opponent-request-accept]')).')?.checkVisibility()]'))->toBe([null, true]);
    $page->locator(awCardSelector($late, '[data-test=opponent-request-accept]'))->click();
    BrowserWait::until($page, '() => document.querySelector('.json_encode(awCardSelector($late)).') === null', 15_000);
    expect($opponents->listEachOther($anna->refresh(), $late))->toBeTrue();
    awMeasure($page, '[data-test=opponent-settings]', 1440, 'en late accepted');

    // And the other way: a card that gains a warning between renders (a hold raised now) takes the confirm.
    ImpersonationHold::factory()->create(['user_id' => $turn->id, 'pubkey' => $turn->pubkey, 'matched_user_id' => $original->id, 'matched_pubkey' => $original->pubkey]);
    awRefresh($page);
    BrowserWait::until($page, '() => document.querySelector('.json_encode(awCardSelector($turn, '[data-test=opponent-request-warning-held]')).') !== null', 5_000);
    $page->locator(awCardSelector($turn, '[data-test=opponent-request-accept]'))->click();
    BrowserWait::until($page, '() => document.querySelector('.json_encode(awCardSelector($turn, '[data-test=opponent-request-confirm]')).')?.checkVisibility() === true && document.activeElement?.dataset.test === "opponent-request-confirm-cancel"', 5_000);
    expect($opponents->entries($anna->refresh()))->not->toContain($turn->pubkey);
    awMeasure($page, awCardSelector($turn), 1440, 'en turned warned confirm open');

    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
});

/** The opponent button in its warned state: open the confirm (focus on Cancel, nothing signed), cancel, open it again. */
function awButtonConfirm(Page $page, string $scope, User $member, User $player, string $label): void
{
    $page->setViewportSize(390, 900);
    $page->locator($scope.' [data-test=opponent-add]')->click();
    BrowserWait::until($page, '() => document.querySelector('.json_encode($scope.' [data-test=opponent-confirm]').')?.checkVisibility() === true && document.activeElement?.dataset.test === "opponent-confirm-cancel"', 5_000);
    expect(app(Opponents::class)->lists($member, $player))->toBeFalse();

    foreach ([390, 1440] as $width) {
        awMeasure($page, $scope, $width, "{$label} confirm open");
        awShot($page, str_replace(' ', '-', $label)."-confirm-{$width}x900");
    }

    $page->locator($scope.' [data-test=opponent-confirm-cancel]')->click();
    BrowserWait::until($page, '() => document.querySelector('.json_encode($scope.' [data-test=opponent-confirm]').').checkVisibility() === false && document.activeElement?.dataset.test === "opponent-add"', 5_000);
    expect(app(Opponents::class)->lists($member, $player))->toBeFalse();
}

test('player page: "Add as opponent" for an account below the standard warns, opens a confirm before anything is signed, and adds after it, at 390 and 1440 in German and English', function () {
    $anna = User::factory()->create(['name' => 'anna', 'picture' => 'https://example.com/p.png', 'nip05' => 'x@example.com', 'nip05_verified_at' => now()->subDays(20), 'created_at' => now()->subMonths(2)]);
    TestSigner::forBrowser($anna);
    $carol = User::factory()->create(['name' => 'Carola von Neuland', 'picture' => null, 'nip05' => null, 'nip05_verified_at' => null, 'created_at' => now()->subDays(3)]);
    $scope = '[data-test=opponent]';

    foreach (['de', 'en'] as $locale) {
        $page = awPage($anna, route('players.show', $carol->npub, false), 390, $locale);
        BrowserWait::until($page, '() => document.querySelector("[data-test=opponent] [data-test=opponent-request-warning]") !== null', 10_000);

        $lines = $page->evaluate('() => [...document.querySelectorAll("[data-test=opponent] [data-test^=opponent-request-warning-]")].map((l) => l.dataset.test.replace("opponent-request-warning-", ""))');
        $text = $page->evaluate('() => document.querySelector("[data-test=opponent]").innerText.replace(/\\s+/g, " ")');
        fwrite(STDERR, "\n[aw] {$locale} player page: ".json_encode($lines).' '.json_encode($text, JSON_UNESCAPED_UNICODE)."\n");
        expect($lines)->toBe(['known', 'nip05', 'profile'])
            ->and($text)->toContain($locale === 'de' ? 'Du kannst die Person trotzdem hinzufügen.' : 'You can still add them.')
            ->and($text)->toContain($locale === 'de' ? 'Kein Profilbild' : 'No profile picture')
            ->and(mb_strtolower($text))->not->toContain('gesicht')->not->toContain('face');

        foreach ([390, 1440] as $width) {
            awMeasure($page, $scope, $width, "{$locale} player page");
            awShot($page, "player-add-warning-{$width}x900-{$locale}");
        }

        awButtonConfirm($page, $scope, $anna, $carol, "player-add {$locale}");
    }

    // "Add anyway": signed, the button now says the player is on the list.
    $page->locator('[data-test=opponent-add]')->click();
    BrowserWait::until($page, '() => document.activeElement?.dataset.test === "opponent-confirm-cancel"', 5_000);
    $page->locator('[data-test=opponent-confirm-add]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=opponent]")?.dataset.state === "listed"', 15_000);
    expect(app(Opponents::class)->lists($anna->refresh(), $carol))->toBeTrue();
    awMeasure($page, $scope, 390, 'en player page added');

    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
});

test('match room: the challenged captain whose challenger is held reads only "being checked" in the notice, and the accept there needs the confirm, at 390 and 1440 in German and English', function () {
    openSeason();
    app()->instance(TrustFacts::class, new class implements TrustFacts
    {
        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            return ['trust' => array_fill_keys($players, 100), 'anchors' => [], 'connected' => true];
        }
    });

    $sides = [];
    foreach (['anna', 'bert'] as $name) {
        $captain = User::factory()->create(['name' => $name, 'picture' => 'https://example.com/'.$name.'.png', 'nip05' => $name.'@example.com', 'nip05_verified_at' => now()->subDays(20), 'created_at' => now()->subMonths(2)]);
        $signer = TestSigner::forBrowser($captain);
        $sides[$name] = [Lineup::factory()->mode('1v1')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id, 'name' => $name.' clan'])->id]), $captain, $signer];
    }
    [$a, $b] = [$sides['anna'], $sides['bert']];
    $service = app(SeriesService::class);
    $start = now()->addHour()->startOfMinute()->getTimestamp();
    $draft = new ChallengeDraft($a[0]->id, $b[0]->id, 3, true, [$start], $start - 600, '');
    $match = $service->challenge($a[1], $draft, $a[2]->signTemplates($service->prepareChallenge($a[1], $draft)['templates']));
    $opponents = app(Opponents::class);
    $opponents->add($a[1], $b[1], $a[2]->signTemplates($opponents->prepareAdd($a[1], $b[1])));
    // The hold is raised after the challenge went out (a profile change, P2).
    $original = User::factory()->create(['name' => 'Anna Original', 'created_at' => now()->subYears(2)]);
    ImpersonationHold::factory()->create(['user_id' => $a[1]->id, 'pubkey' => $a[1]->pubkey, 'matched_user_id' => $original->id, 'matched_pubkey' => $original->pubkey]);
    $scope = '[data-test=needs-mutual]';

    foreach (['de', 'en'] as $locale) {
        $page = awPage($b[1], route('matches.room', $match, false), 390, $locale);
        BrowserWait::until($page, '() => document.querySelector("[data-test=needs-mutual] [data-test=opponent]")?.dataset.state === "lists-you"', 10_000);

        $lines = $page->evaluate('() => [...document.querySelectorAll("[data-test=needs-mutual] [data-test^=opponent-request-warning-]")].map((l) => l.dataset.test.replace("opponent-request-warning-", ""))');
        $text = $page->evaluate('() => document.querySelector("[data-test=needs-mutual]").innerText.replace(/\\s+/g, " ")');
        fwrite(STDERR, "\n[aw] {$locale} room notice: ".json_encode($lines).' '.json_encode($text, JSON_UNESCAPED_UNICODE)."\n");
        expect($lines)->toBe(['held'])
            ->and($text)->toContain($locale === 'de' ? 'Dieses Konto wird gerade geprüft.' : 'This account is being checked.')
            ->and(mb_strtolower($text))->not->toContain('original')->not->toContain('imperson')->not->toContain('gesicht')->not->toContain('face');

        foreach ([390, 1440] as $width) {
            awMeasure($page, $scope, $width, "{$locale} room notice");
            awShot($page, "room-accept-warning-{$width}x900-{$locale}");
        }

        awButtonConfirm($page, $scope, $b[1], $a[1], "room-accept {$locale}");
    }

    // "Accept anyway": signed, the notice goes, the lists are mutual; anna stays out of rated play while held.
    $page->locator($scope.' [data-test=opponent-add]')->click();
    BrowserWait::until($page, '() => document.activeElement?.dataset.test === "opponent-confirm-cancel"', 5_000);
    $page->locator($scope.' [data-test=opponent-confirm-add]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=needs-mutual]") === null', 15_000);
    expect($opponents->listEachOther($a[1], $b[1]->refresh()))->toBeTrue();
    awMeasure($page, '[data-test=answer-card]', 390, 'en room accepted');

    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
});
