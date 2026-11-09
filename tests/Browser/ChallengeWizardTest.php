<?php

use App\Models\Admin;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Support\ComputeUrl;
use Pest\Browser\Support\Screenshot;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The challenge wizard in the browser (plan "Canvas and live alignment", P4)
|--------------------------------------------------------------------------
|
| ChallengeCreate.dc.html: five steps, one on screen, "Next" in the step's own
| card. As an admin who captains a ready RL 1v1 lineup, in German, with the
| longest clan names, at 390, 768 and 1440: per step the step's card and
| title, its Next/Send button takes a click, nothing squeezed or overflowing,
| no browser-native select, checkbox, radio, date or time field on screen
| (R11, R2), the header marks Spiele; a bad date on step 4 keeps the step and
| says why; console and responses quiet on every step and after $refresh.
| WIZARD_SHOTS=<dir> saves a screenshot per step and width.
|
*/

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

/** An admin captaining a ready RL 1v1 lineup of a long-named clan, and two ready 1v1 lineups of other clans. */
function wizardLeague(): User
{
    $admin = User::factory()->create(['name' => 'Ada Admin Who Watches Every Replay Here']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $clan = Clan::factory()->create(['owner_id' => $admin->id, 'name' => 'Bitcoin Metropole Kempten Allgäu Nord', 'clantag' => 'BMK']);
    Lineup::factory()->mode('1v1')->ready()->create(['clan_id' => $clan->id]);
    foreach (['(A)ll (C)ats (A)re (B)eautiful', 'Einundzwanzig Mannheim Rhein-Neckar'] as $name) {
        $owner = User::factory()->create(['name' => 'Captain '.substr(md5($name), 0, 6)]);
        Lineup::factory()->mode('1v1')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $owner->id, 'name' => $name])->id]);
    }

    return $admin->refresh();
}

function wizardOpen(User $user, int $width): mixed
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript('performance.setResourceTimingBufferSize(5000)');
    $page->setViewportSize($width, $width < 640 ? 844 : ($width < 1024 ? 1024 : 900));
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from(route('challenges.create', absolute: false)));
    $page->waitForLoadState('networkidle');
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && document.fonts.status === "loaded"', 10_000);

    return $page;
}

function wizardShot(mixed $page, string $name): void
{
    $dir = getenv('WIZARD_SHOTS');
    if (! is_string($dir) || $dir === '') {
        return;
    }
    File::ensureDirectoryExists($dir);
    $page->screenshot(true, 'wizard-'.$name);
    File::move(Screenshot::path('wizard-'.$name), $dir.'/'.$name.'.png');
}

function wizardLoud(mixed $page): array
{
    return array_merge(
        $page->evaluate('() => window.__errors ?? ["collector missing"]'),
        array_map(fn ($e): string => 'runner '.(is_array($e) ? ($e['message'] ?? json_encode($e)) : (string) $e), $page->javaScriptErrors()),
        $page->evaluate(BrowserConsole::BAD_RESPONSES),
    );
}

/** The step on screen: its number, title, the card, the action button (scrolled to) and what is wrong with the page. */
const WIZARD_PROBE = <<<'JS'
async () => {
    const root = document.querySelector('[data-test=challenge-create]');
    const card = document.querySelector('[data-test^=wizard-step-]:not([data-test=wizard-step-count]):not([data-test=wizard-step-title])');
    const action = document.querySelector('[data-test=next-step], [data-test=send-challenge]');
    action?.scrollIntoView({ block: 'center' });
    await new Promise((r) => setTimeout(r, 150));
    const r = action ? action.getBoundingClientRect() : null;
    const hit = r ? document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2) : null;
    const native = [...document.querySelectorAll('main select, main input[type=date], main input[type=time], main input[type=datetime-local], main input[type=checkbox], main input[type=radio]')]
        .filter((el) => el.checkVisibility()).map((el) => el.outerHTML.slice(0, 60));
    const cardBox = card?.getBoundingClientRect();
    const steps = card ? card.querySelectorAll('[data-test=wizard-stepper] > li').length : 0;
    return {
        step: Number(root?.dataset.step ?? 0), card: card?.dataset.test ?? null, steps,
        title: document.querySelector('[data-test=wizard-step-title]')?.textContent.trim(),
        action: action ? { text: action.textContent.trim().replace(/\s+/g, ' '), h: Math.round(r.height), hit: !!hit && action.contains(hit), over: hit?.closest('[data-test]')?.dataset.test ?? hit?.tagName } : null,
        cardWidth: cardBox ? Math.round(cardBox.width) : 0, cardLeft: cardBox ? Math.round(cardBox.left) : 0,
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        native, spiele: document.querySelector('[data-test=games-menu]')?.getAttribute('aria-current') ?? null,
    };
}
JS;

test('P4 the challenge is a wizard of five steps, each in its own card with its action, at 390, 768 and 1440', function () {
    $admin = wizardLeague();
    $titles = [1 => 'Lineup', 2 => 'Gegner', 3 => 'Gewertet oder casual', 4 => 'Termine', 5 => 'Senden'];
    $actions = [1 => 'Weiter: Gegner', 2 => 'Weiter: Gewertet oder casual', 3 => 'Weiter: Termine', 4 => 'Weiter: Senden', 5 => 'Herausforderung senden'];
    $problems = [];

    foreach ([390, 768, 1440] as $width) {
        $page = wizardOpen($admin, $width);
        foreach ([1, 2, 3, 4, 5] as $step) {
            if ($step === 2) {
                $page->evaluate('() => document.querySelector("[data-test=pick-opponent]:not([disabled])").click()');
                BrowserWait::until($page, '() => document.querySelector("[data-test=pick-opponent][aria-checked=true]") !== null', 5_000);
            }
            $m = $page->evaluate(WIZARD_PROBE);
            $squeezed = $page->evaluate(BrowserConsole::SQUEEZED, ['main']);
            // BrowserConsole::SPILLED, without the screen-reader-only texts (the stepper's step names, the search label):
            // they are clipped to 1 px on purpose.
            $spilled = $page->evaluate(str_replace('.filter((el) => el.checkVisibility())', '.filter((el) => el.checkVisibility() && ! el.closest(".sr-only"))', BrowserConsole::SPILLED), ['[data-test=challenge-create]']);
            wizardShot($page, "step{$step}-{$width}");
            $issues = [];
            if ($m['step'] !== $step || $m['card'] !== "wizard-step-{$step}" || $m['steps'] !== 5 || $m['title'] !== $titles[$step]) {
                $issues[] = 'step '.json_encode([$m['step'], $m['card'], $m['steps'], $m['title']]);
            }
            if ($m['action'] === null || $m['action']['text'] !== $actions[$step] || ! $m['action']['hit'] || $m['action']['h'] < 44) {
                $issues[] = 'action '.json_encode($m['action']);
            }
            if ($m['overflow'] > 0 || $squeezed !== [] || $spilled !== []) {
                $issues[] = 'layout '.json_encode(['overflow' => $m['overflow'], 'squeezed' => $squeezed, 'spilled' => $spilled]);
            }
            if ($m['native'] !== []) {
                $issues[] = 'native controls '.json_encode($m['native']);
            }
            if ($m['spiele'] === null) {
                $issues[] = 'header does not mark Spiele';
            }
            if ($step === 4) {
                // German dates in the German page: "10.11.2026", not the browser's "mm/dd/yyyy".
                $dates = $page->evaluate('() => [...document.querySelectorAll("[data-test=time-date], [data-test=reply-date]")].map((i) => i.value)');
                if ($dates === [] || collect($dates)->contains(fn (string $d): bool => preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $d) !== 1)) {
                    $issues[] = 'dates '.json_encode($dates);
                }
            }
            $loud = wizardLoud($page);
            if ($loud !== []) {
                $issues[] = 'loud '.json_encode($loud);
            }
            if ($issues !== []) {
                $problems["{$width} step {$step}"] = $issues;
            }
            if ($step < 5) {
                $page->evaluate('() => document.querySelector("[data-test=next-step]").click()');
                BrowserWait::until($page, '() => document.querySelector("[data-test=challenge-create]")?.dataset.step === "'.($step + 1).'"', 5_000);
            }
        }
        // A roundtrip of the page on the last step keeps it, quietly.
        $page->evaluate('async () => { for (const c of window.Livewire.all()) { await c.$wire.$refresh(); } await new Promise((r) => setTimeout(r, 500)); }');
        $after = $page->evaluate('() => document.querySelector("[data-test=challenge-create]").dataset.step');
        $loud = wizardLoud($page);
        if ($after !== '5' || $loud !== []) {
            $problems["{$width} refresh"] = ['step '.$after, ...$loud];
        }
    }

    expect($problems)->toBe([]);
});

test('P4 the wizard: a bad date on step 4 keeps the step and names the field; back keeps the picked opponent', function () {
    $admin = wizardLeague();
    $page = wizardOpen($admin, 390);
    $page->evaluate('() => document.querySelector("[data-test=next-step]").click()');
    BrowserWait::until($page, '() => document.querySelector("[data-test=challenge-create]")?.dataset.step === "2"', 5_000);
    // Next without an opponent: the step stays and says what is missing.
    $page->evaluate('() => document.querySelector("[data-test=next-step]").click()');
    BrowserWait::until($page, '() => document.querySelector("[data-test=step-error]") !== null', 5_000);
    expect($page->evaluate('() => [document.querySelector("[data-test=challenge-create]").dataset.step, document.querySelector("[data-test=step-error]").textContent.trim()]'))
        ->toBe(['2', 'Wähle einen Gegner oder lade einen per Link ein.']);

    $page->evaluate('() => document.querySelector("[data-test=pick-opponent]").click()');
    BrowserWait::until($page, '() => document.querySelector("[data-test=pick-opponent][aria-checked=true]") !== null', 5_000);
    $picked = $page->evaluate('() => document.querySelector("[data-test=pick-opponent][aria-checked=true]").textContent.replace(/\s+/g, " ").trim()');
    foreach ([3, 4] as $next) {
        $page->evaluate('() => document.querySelector("[data-test=next-step]").click()');
        BrowserWait::until($page, '() => document.querySelector("[data-test=challenge-create]")?.dataset.step === "'.$next.'"', 5_000);
    }

    $page->locator('[data-test=time-date]')->fill('31.02.2026');
    // A real click: the field loses focus first and its value goes with the click, as for a player.
    $page->locator('[data-test=next-step]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=step-error]") !== null', 5_000);
    expect($page->evaluate('() => document.querySelector("[data-test=challenge-create]").dataset.step'))->toBe('4')
        ->and($page->evaluate('() => document.querySelector("[data-test=step-error]").textContent.trim()'))->toStartWith('Gib ein Datum wie');

    // A German date with a weekday in front is understood; the weekday hint names it.
    $page->locator('[data-test=time-date]')->fill('Sa. 14.11.2026');
    $page->locator('[data-test=time-date]')->press('Tab');
    BrowserWait::until($page, '() => document.querySelector("[data-test=time-weekday]")?.textContent.trim() === "Samstag"', 5_000);

    // Back twice: the opponent picked on step 2 is still picked.
    foreach ([3, 2] as $back) {
        $page->evaluate('() => document.querySelector("[data-test=wizard-back]").click()');
        BrowserWait::until($page, '() => document.querySelector("[data-test=challenge-create]")?.dataset.step === "'.$back.'"', 5_000);
    }
    expect($page->evaluate('() => document.querySelector("[data-test=pick-opponent][aria-checked=true]")?.textContent.replace(/\s+/g, " ").trim()'))->toBe($picked)
        ->and(wizardLoud($page))->toBe([]);
});

test('P4 positive control: the probe sees a native field and the collector a thrown error', function () {
    $admin = wizardLeague();
    $page = wizardOpen($admin, 390);
    $page->evaluate('() => { const i = document.createElement("input"); i.type = "date"; document.querySelector("[data-test=wizard-step-1]").append(i); setTimeout(() => { throw new Error("wizard positive control"); }); }');
    $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
    expect($page->evaluate(WIZARD_PROBE)['native'])->not->toBe([])
        ->and(implode(' | ', wizardLoud($page)))->toContain('wizard positive control');
});
