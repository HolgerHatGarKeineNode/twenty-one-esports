<?php

use App\Enums\InviteStatus;
use App\Enums\TournamentFormat;
use App\Models\ChessGame;
use App\Models\ClanInvite;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Navigation\Navigate;
use Tests\Support\ScoreDemoOn;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| wire:navigate only between the shell's navigable pages (performance plan P6b)
|--------------------------------------------------------------------------
|
| A wire:navigate swap keeps the window: whatever a page's components hang on
| window, document, the websocket or a timer and do not take off again in
| destroy() stays behind and doubles with every click. The spike measured it
| (docs/plans/…-performance/p6-spike.md): +10 listeners and one Echo callback
| per click on home ↔ /play, and chess.js's presence on `game.<id>.players`
| would never be left. So only the pages in Navigate::PAGES swap, and these
| tests hold the line:
|
| - no view writes wire:navigate by hand; links go through @navigate($href),
|   which asks App\Support\Navigation\Navigate;
| - every navigable page starts only Alpine components that are known to tear
|   down completely (NAVIGATE_SAFE_COMPONENTS, each checked in its source and
|   counted by tests/Browser/NavigateSpikeTest.php); a new component on such a
|   page fails here until someone checks it and adds it;
| - the layout marks exactly the navigable pages (`data-navigate-page`), which
|   resources/js/navigateGuard.js reads to load every other page in full;
| - links carry wire:navigate only on a navigable page, only towards one, and
|   only with the switch on.
|
*/

/**
 * Alpine components that remove everything they add in destroy() (or add nothing outside their element), so a
 * wire:navigate swap leaves nothing of them behind. Module-level code (echo.js, playerEvents.js, alerts.js) runs
 * once per window and is not listed.
 *
 * @var list<string>
 */
const NAVIGATE_SAFE_COMPONENTS = [
    // The shell, on every page: listeners on window/document behind an AbortController, intervals and the
    // player-events subscription cleared, the dock's watch channels stopListening (P6b).
    'shellHeader', 'shellSheet', 'firstSteps', 'profileCardHost', 'toastStack', 'matchDock', 'cupMatch',
    'notificationBell', 'casualWatch',
    // @persist keeps it across a swap; it removes its own livewire:navigated listener (livePlayer.js).
    'livePlayer',
    // Intervals cleared in destroy(), or nothing outside the element.
    'upcomingEvents', 'startsIn', 'countUp', 'cupStart', 'autoDecision', 'casualPlay',
    // Presence subscription and observer released in destroy().
    'followsHere',
    // Work on a click only, nothing started in init().
    'nostrBar', 'profileBadge', 'nostrAction', 'pushToggle',
];

/** Inline x-data objects with one of these start something outside their element that nobody takes off again. */
const NAVIGATE_INLINE_LEAKS = ['setInterval(', 'addEventListener(', 'Echo.', '.listen('];

/**
 * The world of the hot-route budget (PageQueryBudgetTest): a player in a ready lineup with a chess game, a series,
 * a tournament signup and a clan invite, a running tournament, a running score board and a live season.
 */
function navigateWorld(): User
{
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    openSeason();
    ScoreDemoOn::play();

    $lineup = Lineup::factory()->mode('3v3')->ready()->create();
    $rival = Lineup::factory()->mode('3v3')->ready()->create();
    $player = $lineup->seats()->where('user_id', '!=', $lineup->clan->owner_id)->firstOrFail()->user;

    ChessGame::factory()->create(['white_id' => $player->id]);
    ChessGame::factory()->daily()->create(['white_id' => $player->id]);
    SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => $lineup->id, 'challenged_lineup_id' => $rival->id]);
    TournamentSignup::query()->create([
        'tournament_id' => openTournament(['name' => 'Halving Cup', 'starts_at' => now()->addDays(3)], rocketLeague: true)->id,
        'user_id' => $player->id, 'name' => $player->displayName(), 'members' => [$player->id],
    ]);
    ClanInvite::query()->create(['clan_id' => $rival->clan_id, 'inviter_id' => $rival->clan->owner_id, 'invitee_id' => $player->id, 'status' => InviteStatus::Pending]);
    runningChess(TournamentFormat::SingleElimination, 4);
    runningScoreBoard(3);

    return $player;
}

/**
 * One URL per navigable page (ladder.show for chess blitz).
 *
 * @return array<string, string>
 */
function navigableUrls(): array
{
    return collect(Navigate::PAGES)->mapWithKeys(fn (string $name): array => [
        $name => route($name, $name === 'ladder.show' ? ['chess', 'blitz'] : [], absolute: false),
    ])->all();
}

/**
 * The Alpine components a page starts (x-data="name(…)" or x-data="name"), and its inline x-data objects that
 * start something outside their element.
 *
 * @return array{components: list<string>, inline: list<string>}
 */
function alpineOf(string $html): array
{
    preg_match_all('/\sx-data="([^"]*)"/', $html, $matches);
    $components = [];
    $inline = [];

    foreach ($matches[1] as $expression) {
        $expression = html_entity_decode($expression, ENT_QUOTES);

        if (preg_match('/^\s*([A-Za-z_$][\w$]*)\s*(\(|$)/', $expression, $name) === 1) {
            $components[] = $name[1];
        } elseif (array_filter(NAVIGATE_INLINE_LEAKS, fn (string $needle): bool => str_contains($expression, $needle)) !== []) {
            $inline[] = mb_substr(trim($expression), 0, 120);
        }
    }

    return ['components' => array_values(array_unique($components)), 'inline' => $inline];
}

test('no view writes wire:navigate by hand: links go through @navigate', function () {
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)) as $file) {
        // Blade comments may talk about wire:navigate; only markup and code count.
        $source = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', fn (array $comment): string => str_repeat("\n", substr_count($comment[0], "\n")), (string) file_get_contents($file->getPathname()));
        foreach (explode("\n", $source) as $number => $line) {
            if (preg_match('/\swire:navigate\b|navigate:\s*true/', $line) === 1) {
                $found[] = str_replace(resource_path('views').'/', '', $file->getPathname()).':'.($number + 1);
            }
        }
    }

    expect($found)->toBe([]);
});

test('every navigable page starts only components that tear down completely, as a guest and as a player', function () {
    $this->travelTo(now()->setTime(12, 0));
    config(['esports.navigate' => true]);
    $player = navigateWorld();
    $unsafe = [];

    foreach ([null, $player] as $viewer) {
        $viewer === null ? auth()->logout() : $this->actingAs($viewer);

        foreach (navigableUrls() as $name => $url) {
            $response = $this->get($url);

            if ($response->isRedirect()) {
                continue;
            }
            $response->assertOk();
            $alpine = alpineOf((string) $response->getContent());
            $who = $viewer === null ? 'guest' : 'player';

            foreach (array_diff($alpine['components'], NAVIGATE_SAFE_COMPONENTS) as $component) {
                $unsafe[] = "{$who} {$name}: {$component}";
            }
            foreach ($alpine['inline'] as $expression) {
                $unsafe[] = "{$who} {$name}: inline {$expression}";
            }
        }
    }

    expect($unsafe)->toBe([]);
});

test('the layout marks exactly the navigable pages', function () {
    $player = User::factory()->member()->create();
    $game = ChessGame::factory()->create(['white_id' => $player->id]);
    $marked = fn (string $url): bool => str_contains((string) $this->get($url)->assertOk()->getContent(), ' data-navigate-page');

    expect($marked(route('home', absolute: false)))->toBeTrue()
        ->and($marked(route('rules', absolute: false)))->toBeTrue()
        ->and($marked(route('games.show', $game, absolute: false)))->toBeFalse();

    $this->actingAs($player);
    expect($marked(route('settings.notifications', absolute: false)))->toBeTrue()
        ->and($marked(route('chess.lobby', absolute: false)))->toBeFalse()
        ->and($marked(route('games.show', $game, absolute: false)))->toBeFalse();
});

test('links carry wire:navigate only on a navigable page, only towards one, and only with the switch on', function () {
    $player = User::factory()->member()->create();
    $game = ChessGame::factory()->create(['white_id' => $player->id]);
    $this->actingAs($player);
    $navigating = function (string $url): array {
        preg_match_all('/<a href="([^"]+)"[^>]*\swire:navigate[\s>]/', (string) $this->get($url)->assertOk()->getContent(), $links);

        return array_values(array_unique(array_map(fn (string $href): string => ((string) parse_url(html_entity_decode($href), PHP_URL_PATH)) ?: '/', $links[1])));
    };

    config(['esports.navigate' => true]);
    $fromHome = $navigating(route('home', absolute: false));
    expect($fromHome)->toContain('/', '/play', '/matches', '/tournaments', '/settings/notifications')
        ->not->toContain('/chess', '/live')
        ->and(array_filter($fromHome, fn (string $path): bool => ! Navigate::isNavigable($path)))->toBe([]);
    // The settings tabs, the defect case of 2026-10-05: notifications runs push.js.
    expect($navigating(route('settings.account', absolute: false)))->toContain('/settings/notifications', '/settings/chess');
    // A full-load page links in full, so leaving it never fetches the next page twice.
    expect($navigating(route('chess.lobby', absolute: false)))->toBe([])
        ->and($navigating(route('games.show', $game, absolute: false)))->toBe([]);

    config(['esports.navigate' => false]);
    expect($navigating(route('home', absolute: false)))->toBe([])
        ->and($navigating(route('settings.account', absolute: false)))->toBe([]);
});

test('a link to another host, a fragment or a missing page is never navigable', function () {
    expect(Navigate::isNavigable('https://example.org/'))->toBeFalse()
        ->and(Navigate::isNavigable('/no-such-page'))->toBeFalse()
        ->and(Navigate::isNavigable(route('rules', absolute: false).'#chess'))->toBeTrue()
        ->and(Navigate::isNavigable(route('chess.lobby')))->toBeFalse()
        ->and(Navigate::isNavigable(route('home')))->toBeTrue();
});
