<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentOrganizer;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;
use Tests\Support\ParseMultipartBody;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| An Age of Empires II lobby tournament (plan "AoE2 und Trackmania", P10)
|--------------------------------------------------------------------------
|
| Nine players drawn into lobbies of 5 and 4. The lobby cards show each
| lobby's settings (the map size follows its players), and the name and
| password only to its own players; measured at 375 and 1440 px in English
| and German, no sideways scroll, nothing cut. A player reports a shared
| place 1 with the end screen, a director confirms it. Console and answers
| stay clean, with a positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    // Livewire uploads are multipart; the in-process server hands them over unparsed (tests/Browser/ClanEditTest.php).
    app(HttpKernel::class)->prependMiddleware(ParseMultipartBody::class);
    // Under runningUnitTests() Livewire keeps temporary uploads on this disk; the end screens land on `local`.
    Storage::fake('tmp-for-tests');
    Storage::fake('local');
});

/** The cards: count, players, the map size row, who sees the access block, and anything cut or sticking out. */
const AOE_LOBBY_MEASURE = <<<'JS'
    () => {
        const cards = [...document.querySelectorAll('[data-test=lobby-card]')];
        const cut = [];
        cards.forEach((card, i) => card.querySelectorAll('dt, dd, h3, p, label, select, button, a').forEach((el) => {
            if (el.checkVisibility() && el.scrollWidth > el.clientWidth + 1) cut.push(`${i}:${el.tagName}:${el.textContent.trim().slice(0, 30)} ${el.scrollWidth}>${el.clientWidth}`);
        }));
        const settings = (card) => Object.fromEntries([...card.querySelectorAll('[data-test=lobby-settings] dt')].map((dt) => [dt.innerText.trim(), dt.nextElementSibling.innerText.trim()]));
        return {
            lang: document.documentElement.lang,
            players: cards.map((card) => Number(card.dataset.players)),
            settings: cards.map(settings),
            access: cards.map((card) => card.querySelector('[data-test=lobby-access]') !== null),
            outside: cards.map((card) => { const r = card.getBoundingClientRect(); return r.left < 0 || r.right > window.innerWidth + 0.5; }),
            cut,
            scroll: document.documentElement.scrollWidth,
            client: document.documentElement.clientWidth,
        };
    }
    JS;

/** A TV scene's lobbies against the stage: every panel and player inside it, nothing cut, no duel. */
const AOE_LOBBY_TV = <<<'JS'
    (scene) => {
        const stage = document.querySelector('.tv-stage').getBoundingClientRect();
        const box = document.querySelector(`[data-scene-id=${scene}]`);
        const panels = [...box.querySelectorAll('[data-test=tv-lobby]')];
        const players = [...box.querySelectorAll('[data-test=tv-lobby-player]')];
        const rects = [...panels, ...players].map((el) => el.getBoundingClientRect());
        return {
            scene: document.querySelector('.tv-stage').dataset.scene,
            panels: panels.map((panel) => Number(panel.dataset.players)),
            players: players.length,
            bottom: Math.round(Math.max(...rects.map((r) => r.bottom))), stageBottom: Math.round(stage.bottom),
            right: Math.round(Math.max(...rects.map((r) => r.right))), stageRight: Math.round(stage.right),
            viewport: [window.innerWidth, window.innerHeight],
            overflowing: panels.filter((panel) => panel.scrollHeight > panel.clientHeight + 1).length,
            cut: [...box.querySelectorAll('.tv-room-name')].filter((name) => name.scrollWidth > name.clientWidth + 1).map((name) => name.textContent),
            nameSize: Math.round(Math.min(...[...box.querySelectorAll('.tv-room-name')].map((name) => parseFloat(getComputedStyle(name).fontSize)))),
            duels: box.querySelectorAll('.tv-duel').length,
            text: box.innerText,
            game: document.querySelector('[data-test=tv]').innerText.includes('1v1'),
        };
    }
    JS;

/**
 * A running Age of Empires II lobby tournament of `$players` (up to nine) named players and its organizer.
 *
 * @return array{0: Tournament, 1: User}
 */
function aoeLobbyTournament(int $players = 9): array
{
    $organizer = User::factory()->create(['name' => 'Lobby Director']);
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);
    $tournament = Tournament::factory()->create([
        'name' => 'Diplomacy Night', 'game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll,
        'options' => FormatOptions::fromArray([], GameProfile::for('age-of-empires-2', '1v1'))->toArray(),
        'capacity' => $players, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'starts_at' => now(),
        'published_at' => now(), 'slug' => 'diplomacy-night', 'created_by_id' => $organizer->id,
    ]);

    foreach (array_slice(['Saladin', 'Joan of Arc', 'Genghis Khan', 'Barbarossa', 'Tamerlane', 'El Cid', 'Attila', 'Bari', 'Gajah Mada'], 0, $players) as $index => $name) {
        $user = User::factory()->create(['name' => $name]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $name, 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return [$tournament->refresh(), $organizer];
}

function aoeLobbyEndScreen(): string
{
    $image = imagecreatetruecolor(640, 360);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 60, 30));
    $path = storage_path('framework/testing/aoe-end-screen.png');
    File::ensureDirectoryExists(dirname($path));
    imagepng($image, $path);

    return $path;
}

/**
 * A TV screenshot to SHELL_SHOTS: no settle (the scene's dwell bar animates the whole time it shows).
 */
function aoeLobbyTvShot(Page $page, string $name): void
{
    $dir = getenv('SHELL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** Scroll the first lobby card to the top of the viewport. */
function aoeLobbyScrollToCards(Page $page): void
{
    $page->evaluate('() => { const card = document.querySelector("[data-test=lobby-card]"); window.scrollTo(0, card.getBoundingClientRect().top + window.scrollY - 80); }');
}

test('the lobby cards of nine players show 5 and 4 with their own settings, the password only to the lobby\'s players, at 375 and 1440 in English and German', function (int $width, int $height, string $locale) {
    [$tournament] = aoeLobbyTournament();
    $lobbies = TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots.participant')->orderBy('position')->get();
    $player = User::query()->find($lobbies[0]->slots[0]->participant->user_id);
    $page = shellPage($player, $width, $height);
    $problems = [];
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    shellOpen($page, route('tournaments.show', $tournament, false), $problems);

    shellShot($page, "aoe-lobby-tournament-{$locale}-{$width}");
    aoeLobbyScrollToCards($page);
    shellShot($page, "aoe-lobby-card-{$locale}-{$width}");
    $measured = $page->evaluate(AOE_LOBBY_MEASURE);
    fwrite(STDERR, "\n[aoe lobby] {$locale} {$width}: ".json_encode($measured));

    $size = $locale === 'de' ? 'Kartengröße' : 'Map size';
    $allied = $locale === 'de' ? 'Bündnissieg' : 'Allied Victory';
    $victory = $locale === 'de' ? 'Sieg' : 'Victory';

    expect($measured['lang'])->toBe($locale)
        ->and($measured['players'])->toBe([5, 4])
        ->and(array_column($measured['settings'], $size))->toBe($locale === 'de' ? ['Normal', 'Mittel'] : ['Normal', 'Medium'])
        ->and(array_column($measured['settings'], $allied))->toBe($locale === 'de' ? ['An', 'An'] : ['On', 'On'])
        ->and(array_column($measured['settings'], $victory))->toBe($locale === 'de' ? ['Zeitlimit, 2 Stunden', 'Zeitlimit, 2 Stunden'] : ['Time Limit, 2 hours', 'Time Limit, 2 hours'])
        // The name and password: only on the viewer's own lobby.
        ->and($measured['access'])->toBe([true, false])
        ->and($measured['outside'])->toBe([false, false])
        ->and($measured['cut'])->toBe([])
        ->and($measured['scroll'])->toBeLessThanOrEqual($measured['client'])
        ->and($page->evaluate('() => document.querySelector("[data-test=lobby-name]").innerText.trim()'))->toBe('e21-t'.$tournament->id.'-l1')
        ->and($page->evaluate('() => document.querySelector("[data-test=lobby-rules]") !== null'))->toBeTrue();

    // The password shows on demand, and a Livewire roundtrip of the cards stays clean.
    $page->locator('[data-test=lobby-access] button[aria-pressed]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=lobby-password]")?.checkVisibility()', 5_000);
    expect($page->evaluate('() => document.querySelector("[data-test=lobby-password]").innerText.trim()'))->toBe($lobbies[0]->lobby_password);
    shellOpen($page, route('tournaments.show', $tournament, false), $problems);

    expect($problems)->toBe([]);

    // Positive control: the collector sees a throw, a failed fetch and a broken image on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("aoe lobby positive control"); }); fetch("/tournaments/0"); document.body.append(Object.assign(document.createElement("img"), { src: "/aoe-lobby-control.png" })); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("aoe lobby positive control")) && window.__errors.some((e) => e.startsWith("404 ")) && window.__errors.some((e) => e.includes("aoe-lobby-control.png"))', 5_000);
})->with([
    'phone 375, en' => [375, 812, 'en'],
    'desktop 1440, en' => [1440, 900, 'en'],
    'phone 375, de' => [375, 812, 'de'],
    'desktop 1440, de' => [1440, 900, 'de'],
]);

test('a player reports a shared place 1 with the end screen, and a director confirms it', function () {
    [$tournament, $director] = aoeLobbyTournament();
    $lobby = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('position', 2)->with('slots.participant')->sole();
    $reporter = User::query()->find($lobby->slots[0]->participant->user_id);
    $places = [1, 1, 3, 4];
    $problems = [];

    $page = shellPage($reporter, 1440, 900);
    shellOpen($page, route('tournaments.show', $tournament, false), $problems);
    aoeLobbyScrollToCards($page);

    foreach ($lobby->slots as $index => $slot) {
        $page->locator('[data-lobby="2"] select[wire\\:model="places.'.$lobby->id.'.'.$slot->tournament_participant_id.'"]')->selectOption((string) $places[$index]);
    }

    $page->locator('[data-lobby="2"] [data-test=lobby-shot]')->setInputFiles(aoeLobbyEndScreen());
    // The upload finishes before the report is sent (Livewire's temporary upload).
    BrowserWait::until($page, '() => !!Livewire.all().find((c) => c.el.closest("[data-test=lobbies]"))?.$wire.shot', 15_000);
    $page->locator('[data-lobby="2"] [data-test=lobby-submit]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-lobby=\'2\'] [data-test=lobby-reported]") !== null || document.querySelector("[data-lobby=\'2\'] [data-test=lobby-error]") !== null', 15_000);

    expect($page->evaluate('() => document.querySelector("[data-lobby=\'2\'] [data-test=lobby-error]")?.innerText ?? null'))->toBeNull()
        ->and($lobby->refresh()->lobby_report['places'])->toBe($lobby->slots->mapWithKeys(fn ($slot, int $index): array => [$slot->tournament_participant_id => $places[$index]])->all());
    shellOpen($page, route('tournaments.show', $tournament, false), $problems);

    // The director sees the report and the end screen, and confirms.
    $desk = shellPage($director, 1440, 900);
    shellOpen($desk, route('tournaments.show', $tournament, false), $problems);
    aoeLobbyScrollToCards($desk);
    BrowserWait::until($desk, '() => document.querySelector("[data-lobby=\'2\'] [data-test=lobby-confirm]") !== null', 10_000);
    $shot = $desk->evaluate('() => fetch(document.querySelector("[data-lobby=\'2\'] [data-test=lobby-screenshot]").href).then((r) => [r.status, r.headers.get("content-type")])');
    $desk->locator('[data-lobby="2"] [data-test=lobby-confirm]')->click();
    BrowserWait::until($desk, '() => document.querySelector("[data-lobby=\'2\'] [data-test=lobby-result]") !== null', 15_000);
    $desk->evaluate('() => { const card = document.querySelector("[data-lobby=\'2\']"); window.scrollTo(0, card.getBoundingClientRect().top + window.scrollY - 80); }');
    shellShot($desk, 'aoe-lobby-confirmed-en-1440');

    $result = $desk->evaluate('() => ({ label: document.querySelector("[data-lobby=\'2\'] [data-test=lobby-result]").innerText.trim(), places: [...document.querySelectorAll("[data-lobby=\'2\'] [data-test=lobby-player] span:first-child")].map((el) => el.innerText.trim()) })');
    shellOpen($desk, route('tournaments.show', $tournament, false), $problems);
    fwrite(STDERR, "\n[aoe lobby report] ".json_encode(compact('result', 'shot')));

    $winners = $lobby->slots->take(2)->map(fn ($slot): string => $slot->participant->name)->all();

    expect($shot)->toBe([200, 'image/png'])
        ->and($result['label'])->toBe('Shared place 1: '.implode(', ', $winners))
        ->and($result['places'])->toBe(['#1', '#1', '#3', '#4'])
        ->and($lobby->refresh()->result['ranks'])->toBe($places)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running)
        ->and($problems)->toBe([]);
});

test('the TV shows each lobby as a grid of its players inside the stage at 1920×1080 and 1280×720: one lobby of 8, and 5 + 4', function (int $players, array $lobbies) {
    [$tournament] = aoeLobbyTournament($players);
    $problems = [];
    $measured = [];

    foreach ([[1920, 1080], [1280, 720]] as [$width, $height]) {
        // The TV is a bare page without the app shell (tests/Browser/TournamentTvTest.php opens it the same way).
        $page = visit('/robots.txt')->page();
        $page->context()->addInitScript(BrowserConsole::COLLECTOR);
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.tv', $tournament)));
        BrowserWait::until($page, '() => window.Alpine !== undefined && document.querySelector(".tv-stage") !== null && document.fonts.status === "loaded"', 10_000);

        foreach (['spotlight', 'bracket'] as $scene) {
            // The rotation holds a scene for seconds; show the one measured, then let its transition end.
            $page->evaluate('() => { document.querySelector(".tv-stage").dataset.scene = "'.$scene.'"; return new Promise((resolve) => setTimeout(resolve, 700)); }');
            $measured["{$width}-{$scene}"] = $page->evaluate('() => ('.AOE_LOBBY_TV.')("'.$scene.'")');
            aoeLobbyTvShot($page, "aoe-lobby-tv-{$players}-{$scene}-{$width}");
        }

        foreach ([...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)] as $problem) {
            $problems[] = "tv {$width}: {$problem}";
        }
    }

    fwrite(STDERR, "\n[aoe lobby tv {$players}] ".json_encode(array_map(fn (array $m): array => array_diff_key($m, ['text' => true]), $measured)));

    foreach ($measured as $key => $m) {
        expect($m['scene'])->toBe(explode('-', $key)[1], $key)
            ->and($m['panels'])->toBe($lobbies, $key)
            ->and($m['players'])->toBe($players, $key)
            ->and($m['bottom'])->toBeLessThanOrEqual($m['stageBottom'], $key)
            ->and($m['stageBottom'])->toBeLessThanOrEqual($m['viewport'][1], $key)
            ->and($m['right'])->toBeLessThanOrEqual($m['stageRight'], $key)
            ->and($m['overflowing'])->toBe(0, $key)
            ->and($m['cut'])->toBe([], $key)
            ->and($m['duels'])->toBe(0, $key)
            ->and($m['game'])->toBeFalse($key)
            ->and(preg_match('/\bvs\b/i', $m['text']))->toBe(0, $key)
            // Readable from the sofa: never under 20 px at 1280 (1.25 stage units).
            ->and($m['nameSize'])->toBeGreaterThanOrEqual(20, $key);
    }

    expect($problems)->toBe([]);
})->with([
    'one lobby of 8' => [8, [8]],
    'lobbies of 5 and 4' => [9, [5, 4]],
]);
