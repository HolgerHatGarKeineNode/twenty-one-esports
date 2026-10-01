<?php

use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\LeagueTime;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The tournament edit page in the browser
|--------------------------------------------------------------------------
|
| /admin/tournaments/{id}/edit of a Rocket League 3v3 tournament with a clan
| lineup and two solo players, at 375 and 1440 px: the chooser and the
| moderation list render without horizontal overflow and with a clean
| console and network (BrowserConsole). At 1440 a game correction shows the
| lineups it would remove (the summary island, which Livewire tests skip),
| and removing a solo entry takes it off the list and into the log. A
| drawn tournament shows the format locked. A thrown error and a broken
| image at the end prove the collector sees what it has to.
|
| EDIT_SHOTS=<dir> writes the English screenshots there.
|
*/

const EDIT_STATE = <<<'JS'
    () => ({
        rows: document.querySelectorAll('[data-test=signup-row]').length,
        chooser: document.querySelector('[data-test=tournament-chooser]') !== null,
        incompatible: document.querySelector('[data-test=incompatible]')?.textContent.replace(/\s+/g, ' ').trim() ?? null,
        log: document.querySelector('[data-test=moderation-log]')?.textContent.replace(/\s+/g, ' ').trim() ?? '',
        locked: document.querySelector('[data-test=format-locked]') !== null,
        lang: document.documentElement.lang,
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        errors: window.__errors,
    })
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();

    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

function editShot(Page $page, string $name): void
{
    $dir = getenv('EDIT_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    // Pest clears tests/Browser/Screenshots on every run: move the file out at once.
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * @return array<string, mixed>
 */
function editState(Page $page): array
{
    return [...$page->evaluate(EDIT_STATE), 'bad' => $page->evaluate(BrowserConsole::BAD_RESPONSES)];
}

test('the edit page and its moderation list stay clean at 375 and 1440 px, and a removal lands in the log', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $tournament = openTournament(['name' => 'Rocket Sunday Munich'], rocketLeague: true);
    [$lineup, $captain, $signer] = keyedLineup(1);
    lineupSignup($tournament, $lineup, $captain, $signer);

    foreach (['Satoshi Solo', 'Hal Finney Fan'] as $name) {
        [$player, $key] = keyedPlayer();
        $player->forceFill(['name' => $name])->save();
        soloSignup($tournament, $player, $key);
    }

    $url = route('admin.tournaments.edit', $tournament);
    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $measured = [];

    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from($url));
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=signup-row]").length === 3', 10_000);
        editShot($page, "tournament-edit-{$width}");
        $state = editState($page);
        $measured[$width] = ['overflow' => $state['overflow'], 'rows' => $state['rows']];

        expect($state['chooser'])->toBeTrue()
            ->and($state['lang'])->toBe('en')
            ->and($state['overflow'])->toBeLessThanOrEqual(0)
            ->and($state['errors'])->toBe([])
            ->and($state['bad'])->toBe([]);
    }

    // A game correction to 2v2: the summary island lists the 3v3 lineup it would remove.
    $page->locator('[data-test=game-rocket-league] button:has-text("2v2")')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=incompatible]") !== null', 10_000);
    $corrected = editState($page);
    editShot($page, 'tournament-edit-game-correction-1440');

    expect($corrected['incompatible'])->toContain($lineup->clan->name)
        ->and($corrected['errors'])->toBe([]);

    // Remove a solo entry with a reason: it leaves the list and the log names it.
    $solo = TournamentSignup::query()->where('name', 'Satoshi Solo')->sole();
    $page->locator("[data-test=remove-{$solo->id}]")->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=remove-form]") !== null', 10_000);
    $page->locator('[data-test=remove-reason]')->fill('Signed up twice');
    $page->locator('[data-test=remove-block]')->check();
    editShot($page, 'tournament-remove-form-1440');
    $page->locator('[data-test=remove-confirm]')->click();
    BrowserWait::until($page, '() => document.querySelectorAll("[data-test=signup-row]").length === 2', 10_000);
    $removed = editState($page);
    $page->setViewportSize(375, 812);
    $narrow = editState($page);
    editShot($page, 'tournament-moderation-after-removal-375');
    $page->setViewportSize(1440, 900);

    expect($removed['log'])->toContain('removed an entry: Satoshi Solo — Signed up twice')
        ->and($removed['log'])->toContain('blocked a player: Satoshi Solo')
        ->and($solo->refresh()->removed_at)->not->toBeNull()
        ->and($removed['errors'])->toBe([])
        ->and($removed['bad'])->toBe([])
        ->and($narrow['overflow'])->toBeLessThanOrEqual(0);

    // After the draw the format is locked, with its reason, at both widths.
    $tournament->forceFill(['status' => TournamentStatus::Drawing])->save();

    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from($url));
        BrowserWait::until($page, '() => document.querySelector("[data-test=format-locked]") !== null', 10_000);
        editShot($page, "tournament-edit-locked-{$width}");
        $locked = editState($page);
        $measured["locked {$width}"] = $locked['overflow'];

        expect($locked['chooser'])->toBeFalse()
            ->and($locked['overflow'])->toBeLessThanOrEqual(0)
            ->and($locked['errors'])->toBe([])
            ->and($locked['bad'])->toBe([]);
    }

    // Positive control: a thrown error and a broken image on this very page are caught.
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); const img = new Image(); img.src = "/__missing-positive-control.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 10_000);
    $control = editState($page);

    expect(implode("\n", $control['errors']))->toContain('positive control')
        ->and(implode("\n", $control['errors']))->toContain('__missing-positive-control.png');

    fwrite(STDERR, "\n[tournament-edit] ".json_encode($measured)."\n");
});

test('a running tournament takes a new deadline on the edit page, with a clean console at 375 and 1440 px', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $tournament = openTournament(['name' => 'Rocket Sunday Munich'], rocketLeague: true);
    $tournament->forceFill(['status' => TournamentStatus::Running])->save();

    $url = route('admin.tournaments.edit', $tournament);
    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $measured = [];

    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from($url));
        BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-deadlines]") !== null', 10_000);
        editShot($page, "tournament-deadlines-{$width}");
        $state = editState($page);
        $measured[$width] = [$state['overflow'], $page->evaluate('() => { const r = document.querySelector("[data-test=tournament-deadlines]").getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; }')];

        expect($state['overflow'])->toBeLessThanOrEqual(0)
            ->and($state['errors'])->toBe([])
            ->and($state['bad'])->toBe([]);
    }

    $page->locator('[data-test=deadline-response_minutes]')->fill('45');
    $page->locator('[data-test=edit-save]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=edit-notice]") !== null', 10_000);
    $saved = editState($page);

    expect($tournament->refresh()->response_minutes)->toBe(45)
        ->and($page->evaluate('() => document.querySelector("[data-test=deadline-response_minutes]").value'))->toBe('45')
        ->and($saved['log'])->toContain('Series: answer within (minutes): — → 45')
        ->and($saved['log'])->toContain('running matches keep theirs')
        ->and($saved['errors'])->toBe([])
        ->and($saved['bad'])->toBe([]);

    // Positive control: a thrown error and a broken image on this very page are caught.
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); const img = new Image(); img.src = "/__missing-positive-control.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 10_000);

    expect(implode("\n", editState($page)['errors']))->toContain('positive control');

    fwrite(STDERR, "\n[tournament-deadlines] ".json_encode($measured)."\n");
});

/*
 * Publishing a draft (user, 2026-10-01: "du hast die publish Funktion total
 * versteckt"): the draft's row on /admin/tournaments leads with an orange
 * "Publish tournament", and the draft's page opens with a banner holding the
 * publish form, both inside the first screen at 375 and 1440.
 */
const PUBLISH_STATE = <<<'JS'
    () => {
        const rect = (selector) => { const el = document.querySelector(selector); if (!el) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), width: Math.round(r.width) }; };
        const draftRow = [...document.querySelectorAll('[data-test=tournament-row]')].find((row) => row.querySelector('[data-test=manage-publish]'));
        return {
            path: location.pathname,
            hash: location.hash,
            viewport: window.innerHeight,
            scrollY: Math.round(window.scrollY),
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            lang: document.documentElement.lang,
            publishButtons: document.querySelectorAll('[data-test=manage-publish]').length,
            firstAction: draftRow?.querySelector('[data-test=manage-actions] > *')?.dataset.test ?? null,
            listButton: rect('[data-test=manage-publish]'),
            listButtonColor: (() => { const b = document.querySelector('[data-test=manage-publish]'); return b ? getComputedStyle(b).backgroundColor : null; })(),
            banner: rect('[data-test=draft-banner]'),
            bannerText: document.querySelector('[data-test=draft-banner]')?.textContent.replace(/\s+/g, ' ').trim() ?? null,
            publishButton: rect('[data-test=publish]'),
            notice: document.querySelector('[data-test=draft-notice]')?.textContent.trim() ?? null,
            cta: document.querySelector('[data-test=signup-cta]')?.dataset.state ?? null,
            rows: [...document.querySelectorAll('[data-test=tournament-row]')].map((row) => row.textContent.replace(/\s+/g, ' ').trim()),
            errors: window.__errors,
        };
    }
    JS;

test('an admin publishes a draft from its orange button on the tournaments list, through the banner at the top of its page, at 375 and 1440 px', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $creator = organizer();
    $creator->forceFill(['name' => 'markusturm'])->save();
    $draft = Tournament::factory()->create(['created_by_id' => $creator->id, 'name' => 'Fifa27 Bitcoin Metropole Kempten']);
    $second = Tournament::factory()->create(['created_by_id' => $creator->id, 'name' => 'Blitz Night Kempten', 'starts_at' => now()->addDays(9)->setTime(19, 0)]);
    openTournament(['name' => 'Rocket Sunday Munich'], rocketLeague: true);

    $list = route('admin.tournaments');
    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $state = fn (): array => [...$page->evaluate(PUBLISH_STATE), 'bad' => $page->evaluate(BrowserConsole::BAD_RESPONSES)];
    $measured = [];

    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);

        // The list: one orange Publish per draft, the first action of its row.
        $page->goto(ComputeUrl::from($list));
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=tournament-row]").length === 3', 10_000);
        editShot($page, "publish-admin-list-{$width}");
        $listed = $state();

        expect($listed['publishButtons'])->toBe(2)
            ->and($listed['firstAction'])->toBe('manage-publish')
            ->and($listed['listButtonColor'])->toBe('rgb(247, 147, 26)')
            ->and($listed['lang'])->toBe('en')
            ->and($listed['overflow'])->toBeLessThanOrEqual(0)
            ->and($listed['errors'])->toBe([])
            ->and($listed['bad'])->toBe([]);

        // The draft's page: the banner and its Publish button in the first screen, no sideways scroll.
        $page->goto(ComputeUrl::from(route('tournaments.show', $second)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=draft-banner]") !== null', 10_000);
        editShot($page, "publish-draft-page-{$width}");
        $shown = $state();
        $measured[$width] = ['list button' => $listed['listButton'], 'banner' => $shown['banner'], 'publish' => $shown['publishButton'], 'viewport' => $shown['viewport'], 'overflow' => [$listed['overflow'], $shown['overflow']]];

        expect($shown['banner']['top'])->toBeGreaterThanOrEqual(0)->toBeLessThan($shown['viewport'])
            ->and($shown['publishButton']['bottom'])->toBeLessThanOrEqual($shown['viewport'])
            ->and($shown['bannerText'])->toContain('Players cannot see this tournament yet')->toContain('Publish tournament')
            ->and($shown['publishButtons'])->toBe(0)
            ->and($shown['overflow'])->toBeLessThanOrEqual(0)
            ->and($shown['errors'])->toBe([])
            ->and($shown['bad'])->toBe([]);
    }

    // The click path at 375: Publish on the list lands on the form, the close time is set, and sign-up opens.
    $page->setViewportSize(375, 812);
    $page->goto(ComputeUrl::from($list));
    BrowserWait::until($page, '() => document.querySelectorAll("[data-test=manage-publish]").length === 2', 10_000);
    $page->locator('[data-test=tournament-row]:has-text("Fifa27 Bitcoin Metropole Kempten") [data-test=manage-publish]')->click();
    BrowserWait::until($page, '() => location.hash === "#publish" && document.querySelector("[data-test=draft-banner]") !== null', 10_000);
    $landed = $state();

    expect($landed['path'])->toBe(parse_url(route('tournaments.show', $draft), PHP_URL_PATH))
        ->and($landed['banner']['top'])->toBeGreaterThanOrEqual(0)->toBeLessThan($landed['viewport'])
        ->and($landed['publishButton']['bottom'])->toBeLessThanOrEqual($landed['viewport']);

    $closes = LeagueTime::input($draft->starts_at->copy()->subHours(2));
    $page->locator('[data-test=closes-at] input')->fill($closes);
    $page->locator('[data-test=publish]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=draft-banner]") === null && document.querySelector("[data-test=signup-cta]") !== null', 10_000);
    $published = $state();
    $draft->refresh();

    expect($draft->status)->toBe(TournamentStatus::Signup)
        ->and(LeagueTime::input($draft->signup_closes_at))->toBe($closes)
        ->and($published['cta'])->toBe('open')
        ->and($published['overflow'])->toBeLessThanOrEqual(0)
        ->and($published['errors'])->toBe([])
        ->and($published['bad'])->toBe([]);

    // Back on the list its row says Sign-up open and carries no Publish; the other draft still does.
    $page->goto(ComputeUrl::from($list));
    BrowserWait::until($page, '() => document.querySelectorAll("[data-test=tournament-row]").length === 3', 10_000);
    $after = $state();
    $row = collect($after['rows'])->first(fn (string $row): bool => str_contains($row, 'Fifa27 Bitcoin Metropole Kempten'));

    expect($row)->toStartWith('Sign-up open')
        ->and($after['publishButtons'])->toBe(1)
        ->and($after['errors'])->toBe([])
        ->and($after['bad'])->toBe([]);

    // The edit page's Save and publish keeps a typed name: it saves, then lands on the banner.
    $page->goto(ComputeUrl::from(route('admin.tournaments.edit', $second)));
    BrowserWait::until($page, '() => document.querySelector("[data-test=edit-publish-top]") !== null', 10_000);
    $page->locator('[data-test=edit-name]')->fill('Blitz Night Kempten II');
    $page->locator('[data-test=edit-publish-top]')->click();
    BrowserWait::until($page, '() => location.hash === "#publish" && document.querySelector("[data-test=draft-banner]") !== null', 10_000);
    $saved = $state();

    expect($second->refresh()->name)->toBe('Blitz Night Kempten II')
        ->and($saved['path'])->toBe(parse_url(route('tournaments.show', $second), PHP_URL_PATH))
        ->and($saved['banner']['top'])->toBeLessThan($saved['viewport'])
        ->and($saved['errors'])->toBe([])
        ->and($saved['bad'])->toBe([]);

    // German: the banner of the other draft.
    $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from(route('tournaments.show', $second)));
    BrowserWait::until($page, '() => document.querySelector("[data-test=draft-banner]") !== null', 10_000);
    editShot($page, 'publish-draft-page-de-1440');
    $german = $state();

    expect($german['lang'])->toBe('de')
        ->and($german['bannerText'])->toContain('Spieler sehen dieses Turnier noch nicht')->toContain('Turnier veröffentlichen')
        ->and($german['banner']['top'])->toBeLessThan($german['viewport'])
        ->and($german['overflow'])->toBeLessThanOrEqual(0)
        ->and($german['errors'])->toBe([])
        ->and($german['bad'])->toBe([]);

    // Positive control: a thrown error and a broken image on this very page are caught.
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); const img = new Image(); img.src = "/__missing-positive-control.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 10_000);

    expect(implode("\n", $state()['errors']))->toContain('positive control')->toContain('__missing-positive-control.png');

    fwrite(STDERR, "\n[publish-cta] ".json_encode($measured)."\n");
});
