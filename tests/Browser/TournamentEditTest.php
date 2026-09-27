<?php

use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\TournamentSignup;
use App\Models\User;
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
