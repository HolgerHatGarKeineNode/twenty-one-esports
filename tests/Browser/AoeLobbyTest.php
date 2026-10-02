<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentOrganizer;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\LobbyWords;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\LobbyResults;
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

/** 64 player names (a tournament's most), real campaign heroes, short and long. */
const AOE_LOBBY_NAMES = ['Saladin', 'Joan of Arc', 'Genghis Khan', 'Barbarossa', 'Tamerlane', 'El Cid', 'Attila', 'Bari', 'Gajah Mada',
    'Richard the Lionheart', 'Frederick', 'Yodit', 'Kotyan Khan', 'Tariq ibn Ziyad', 'Dagnajan', 'Prithviraj', 'Sundjata', 'Francesco Sforza',
    'Babur', 'Edward Longshanks', 'Alaric', 'Bayinnaung', 'Cuauhtemoc', 'Pachacuti', 'Vlad Dracula', 'Lac Long Quan', 'Suryavarman',
    'Le Loi', 'Ivaylo', 'Thoros', 'Constantine', 'Jadwiga', 'Algirdas', 'Gedimino', 'Dmitry Donskoy', 'Ivan the Terrible', 'Hautevilles',
    'Tamar', 'Ismail', 'Rajendra', 'Devapala', 'Shivaji', 'Kushluk', 'Sargis', 'Hannibal', 'Scipio', 'Xerxes', 'Cyrus the Great',
    'Darius', 'Ashoka', 'Ragnar', 'Erik the Red', 'Harald Hardrada', 'Sigurd', 'Ingrid', 'Grimhild', 'Theodoric', 'Odoacer',
    'Clovis', 'Charlemagne', 'Alfred the Great', 'Godfrey', 'Baldwin', 'Bohemond of Taranto'];

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
            // An ellipsised name: how many of its characters still show (its width over the width of one character).
            fewestShown: Math.min(99, ...[...box.querySelectorAll('.tv-room-name')].filter((name) => name.scrollWidth > name.clientWidth + 1)
                .map((name) => Math.floor(name.clientWidth / (name.scrollWidth / name.textContent.length)))),
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

    foreach (array_slice(AOE_LOBBY_NAMES, 0, $players) as $index => $name) {
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
        ->and($page->evaluate('() => document.querySelector("[data-test=lobby-name]").innerText.trim()'))->toBe(LobbyWords::lobbyName($tournament->id, 1))
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

    expect($shot)->toBe([200, 'image/webp'])
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

test('the TV keeps every name readable with many lobbies: 24, 32 and 40 players (a full cup) and 64, at 1920×1080 and 1280×720', function (int $players, array $lobbies) {
    [$tournament] = aoeLobbyTournament($players);
    $problems = [];
    $measured = [];

    foreach ([[1920, 1080], [1280, 720]] as [$width, $height]) {
        $page = visit('/robots.txt')->page();
        $page->context()->addInitScript(BrowserConsole::COLLECTOR);
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.tv', $tournament)));
        BrowserWait::until($page, '() => window.Alpine !== undefined && document.querySelector(".tv-stage") !== null && document.fonts.status === "loaded"', 10_000);

        foreach (['spotlight', 'bracket'] as $scene) {
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
        expect($m['panels'])->toBe($lobbies, $key)
            ->and($m['players'])->toBe($players, $key)
            ->and($m['bottom'])->toBeLessThanOrEqual($m['stageBottom'], $key)
            ->and($m['stageBottom'])->toBeLessThanOrEqual($m['viewport'][1], $key)
            ->and($m['right'])->toBeLessThanOrEqual($m['stageRight'], $key)
            ->and($m['overflowing'])->toBe(0, $key)
            // A long name may end in "…", but never before its eighth character.
            ->and($m['fewestShown'])->toBeGreaterThanOrEqual(8, $key)
            ->and($m['duels'])->toBe(0, $key);
    }

    expect($problems)->toBe([]);
})->with([
    '24 players, 3 lobbies' => [24, [8, 8, 8]],
    '32 players, 4 lobbies' => [32, [8, 8, 8, 8]],
    '40 players, 5 lobbies' => [40, [8, 8, 8, 8, 8]],
    '64 players, 8 lobbies' => [64, [8, 8, 8, 8, 8, 8, 8, 8]],
]);

test('the create page swaps the deadlines with the game: Age of Empires II shows the lobby line and no series fields, chess brings its deadlines back, in English and German at 390 and 1440', function (string $locale) {
    $organizer = User::factory()->create(['name' => 'Lobby Director']);
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);
    $problems = [];
    $measured = [];
    $page = shellPage($organizer, 1440, 900);
    // The language switch answers with a redirect; the pages below are opened (and checked) after it.
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        shellOpen($page, route('admin.tournaments.create', [], false), $problems);
        BrowserWait::until($page, '() => document.querySelector("[data-test=deadline-noshow_minutes]") !== null', 8_000);

        $page->locator('[data-test=game-age-of-empires-2] button')->first()->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-deadlines-lobby]") !== null', 8_000);
        $page->evaluate('() => document.querySelector("[data-test=tournament-deadlines-lobby]").scrollIntoView({ block: "center" })');
        $lobby = $page->evaluate('() => { const box = document.querySelector("[data-test=tournament-deadlines-lobby]"); const r = box.getBoundingClientRect();
            return { lang: document.documentElement.lang, text: box.innerText, fields: document.querySelectorAll("[data-test^=deadline-]").length,
                visible: box.checkVisibility(), left: r.left, right: r.right, vw: window.innerWidth, scroll: document.documentElement.scrollWidth - document.documentElement.clientWidth }; }');
        shellShot($page, "aoe-lobby-create-deadlines-{$locale}-{$width}");

        $page->locator('[data-test=game-chess] button')->first()->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=deadline-checkin_minutes]") !== null', 8_000);
        $chess = $page->evaluate('() => ({ lobby: document.querySelector("[data-test=tournament-deadlines-lobby]") !== null, checkin: document.querySelector("[data-test=deadline-checkin_minutes]")?.checkVisibility() ?? false })');

        foreach ([...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)] as $problem) {
            $problems[] = "create {$width}: {$problem}";
        }

        $measured[$width] = compact('lobby', 'chess');
    }

    fwrite(STDERR, "\n[aoe create deadlines {$locale}] ".json_encode($measured));

    foreach ($measured as $width => $m) {
        expect($m['lobby']['lang'])->toBe($locale, (string) $width)
            ->and($m['lobby']['visible'])->toBeTrue()
            ->and($m['lobby']['fields'])->toBe(0)
            ->and($m['lobby']['text'])->toContain($locale === 'de' ? 'Eine Lobby hat keine Serien-Fristen' : 'A lobby has no series deadlines')
            ->and($m['lobby']['left'])->toBeGreaterThanOrEqual(0)
            ->and($m['lobby']['right'])->toBeLessThanOrEqual($m['lobby']['vw'])
            ->and($m['lobby']['scroll'])->toBeLessThanOrEqual(0)
            ->and($m['chess'])->toBe(['lobby' => false, 'checkin' => true]);
    }

    expect($problems)->toBe([]);
})->with(['en', 'de']);

test('a director\'s reject reason of one long unbroken word wraps inside the lobby card, at 375 in English and German', function (string $locale) {
    [$tournament, $director] = aoeLobbyTournament();
    $lobby = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('position', 1)->with('slots.participant')->sole();
    $reporter = User::query()->find($lobby->slots[0]->participant->user_id);
    $lobby->forceFill(['lobby_report' => ['places' => $lobby->slots->mapWithKeys(fn ($slot, int $index): array => [$slot->tournament_participant_id => $index + 1])->all(),
        'user_id' => $reporter->id, 'name' => $reporter->name, 'at' => now()->toIso8601String(), 'screenshot' => null]])->save();
    $lobby->refresh();
    app(LobbyResults::class)->reject($lobby, $director, 'Wrong end screen. '.str_repeat('x', 70).' Please report again.', LobbyResults::reportIdentity(LobbyResults::currentReport($lobby->lobby_report)));

    $problems = [];
    $page = shellPage($reporter, 375, 812);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    shellOpen($page, route('tournaments.show', $tournament, false), $problems);
    $measured = $page->evaluate('() => { const el = document.querySelector("[data-lobby=\\"1\\"] [data-test=lobby-rejected]");
        return { lang: document.documentElement.lang, text: el.innerText, scroll: el.scrollWidth, client: el.clientWidth,
            page: document.documentElement.scrollWidth, viewport: document.documentElement.clientWidth }; }');

    expect($measured['lang'])->toBe($locale)
        ->and($measured['text'])->toContain(str_repeat('x', 70))
        ->and($measured['scroll'])->toBeLessThanOrEqual($measured['client'])
        ->and($measured['page'])->toBeLessThanOrEqual($measured['viewport'])
        ->and($problems)->toBe([]);
})->with(['en', 'de']);

/** A box's words, and how many of its visible parts stick out of the viewport. */
const AOE_LOBBY_CHIPS = <<<'JS'
    (selector) => {
        const box = document.querySelector(selector);
        const rects = [...box.querySelectorAll('span, p, a, b')].filter((el) => el.checkVisibility()).map((el) => el.getBoundingClientRect());
        return {
            lang: document.documentElement.lang,
            text: box.innerText.replace(/\s+/g, ' ').trim(),
            outside: rects.filter((r) => r.left < -0.5 || r.right > window.innerWidth + 0.5).length,
            scroll: document.documentElement.scrollWidth,
            client: document.documentElement.clientWidth,
        };
    }
    JS;

test('a live lobby tournament says "One lobby match" without a 1v1 on its page and on its /tournaments card, at 375 and 1440 in English and 1440 in German', function (int $width, int $height, string $locale) {
    [$tournament] = aoeLobbyTournament();
    $page = shellPage(null, $width, $height);
    $problems = [];
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));

    shellOpen($page, route('tournaments.show', $tournament, false), $problems);
    shellShot($page, "aoe-lobby-chips-{$locale}-{$width}");
    $hero = $page->evaluate(AOE_LOBBY_CHIPS, '[data-test=tournament-hero]');

    $card = "[data-test=organizer-card][data-tournament=\"{$tournament->id}\"]";
    shellOpen($page, route('tournaments.index', [], false), $problems);
    $page->evaluate('(selector) => { const card = document.querySelector(selector); window.scrollTo(0, card.getBoundingClientRect().top + window.scrollY - 80); }', $card);
    shellShot($page, "aoe-lobby-index-{$locale}-{$width}");
    $listed = $page->evaluate(AOE_LOBBY_CHIPS, $card);
    fwrite(STDERR, "\n[aoe chips] {$locale} {$width}: ".json_encode(['hero' => $hero, 'card' => $listed]));

    foreach ([$hero, $listed] as $measured) {
        expect($measured['lang'])->toBe($locale)
            ->and($measured['text'])->toContain('Age of Empires II', $locale === 'de' ? 'Ein Lobby-Match' : 'One lobby match')
            ->and($measured['text'])->not->toContain('1v1')
            ->and($measured['text'])->not->toContain('Free for All')
            ->and($measured['outside'])->toBe(0)
            ->and($measured['scroll'])->toBeLessThanOrEqual($measured['client']);
    }
    expect($problems)->toBe([]);

    // Positive control: the collector sees a throw and a failed fetch on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("aoe chips positive control"); }); fetch("/tournaments/0"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("aoe chips positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 375, en' => [375, 812, 'en'],
    'desktop 1440, en' => [1440, 900, 'en'],
    'desktop 1440, de' => [1440, 900, 'de'],
]);
