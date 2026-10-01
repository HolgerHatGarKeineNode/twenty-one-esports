<?php

use App\Enums\PayoutStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Games\Blockfill;
use App\Games\ScoreMetric;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanDeparture;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\MatchNumber;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\StackerRun;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The public record on the player page (P31)
|--------------------------------------------------------------------------
|
| A player with a chess ladder, a Rocket League 1v1 ladder and a 2v2 lineup
| ladder, results of all three, a won tournament with its paid prize, a
| clan now and one left; and a player with nothing, whose parts each say so
| in one line. Measured at 1440 and 375 (German at 375): nothing wider than
| the window, no target under 44 px, nothing cut, the side column level with
| the ladders, and the console and the answers clean, with a positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

/**
 * One rating with its changes, oldest first: [score, delta] per result.
 *
 * @param  list<array{0: float, 1: int}>  $changes
 * @param  list<int>  $sources
 */
function playerStatsRating(string $subject, string $game, string $mode, array $changes, string $source, array $sources): void
{
    [$kind, $id] = explode(':', $subject);
    $rating = Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => $game, 'mode' => $mode, 'subject' => $subject,
        $kind === 'user' ? 'user_id' : 'lineup_id' => (int) $id, 'rating' => 1000, 'results' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0]);
    $value = 1000;

    foreach ($changes as $index => [$score, $delta]) {
        RatingChange::query()->create(['rating_id' => $rating->id, 'source' => $source, 'source_id' => $sources[$index] ?? 0, 'score' => $score,
            'before' => $value, 'after' => $value + $delta, 'delta' => $delta, 'results_before' => $index]);
        $value += $delta;
    }

    $scores = array_column($changes, 0);
    $rating->forceFill(['rating' => $value, 'results' => count($changes), 'wins' => count(array_filter($scores, fn (float $s): bool => $s === 1.0)),
        'draws' => count(array_filter($scores, fn (float $s): bool => $s === 0.5)), 'losses' => count(array_filter($scores, fn (float $s): bool => $s === 0.0))])->save();
}

function playerStatsSeed(): User
{
    $player = User::factory()->create(['name' => 'Satoshi Sparring']);
    $names = ['Hal Finney', 'Nick Szabo', 'Adam Back', 'Wei Dai', 'Len Sassaman', 'Gavin A.', 'Ross U.', 'Mt. Gox', 'Lightning Larry', 'Pleb Paul'];
    $opponents = collect($names)->map(fn (string $name) => User::factory()->create(['name' => $name]));

    // Chess blitz: twelve games, the newest last.
    $chess = [];
    $outcomes = ['1-0', '1-0', '0-1', '1/2-1/2', '1-0', '1-0', '0-1', '1-0', '1-0', '1/2-1/2', '1-0', '0-1'];
    foreach ($outcomes as $index => $result) {
        $chess[] = ChessGame::factory()->finished($result)->create(['white_id' => $player->id, 'black_id' => $opponents[$index % 10]->id, 'updated_at' => now()->subHours(40 - $index * 3)])->id;
    }
    playerStatsRating('user:'.$player->id, 'chess', 'blitz', array_map(fn (string $r, int $i): array => [$r === '1-0' ? 1.0 : ($r === '0-1' ? 0.0 : 0.5), $r === '1-0' ? 14 + $i : ($r === '0-1' ? -15 : 1)], $outcomes, array_keys($outcomes)), RatingChange::CHESS, $chess);

    // Rocket League 1v1: two roster series.
    $game = fn (int $c, int $d) => ['challenger' => $c, 'challenged' => $d, 'winner' => $c > $d ? 'challenger' : 'challenged'];
    $rl = [];
    foreach ([[$opponents[0], 'challenger', [$game(3, 1), $game(2, 1)]], [$opponents[3], 'challenged', [$game(2, 4), $game(0, 1)]]] as $i => [$other, $winner, $games]) {
        $rl[] = SeriesMatch::factory()->create([
            'challenger_lineup_id' => null, 'challenged_lineup_id' => null,
            'number' => MatchNumber::query()->create(['user_id' => $player->id, 'used_at' => now()])->id,
            'created_by_id' => null, 'game' => 'rocket-league', 'mode' => '1v1', 'best_of' => 3,
            'challenger_name' => $player->displayName(), 'challenged_name' => $other->displayName(), 'challenger_tag' => 'SATS', 'challenged_tag' => 'OPP',
            'challenger_lineup_address' => '', 'challenged_lineup_address' => '',
            'sides' => ['challenger' => [$player->id], 'challenged' => [$other->id]],
            'status' => SeriesStatus::Confirmed, 'winner' => $winner, 'start_at' => now()->subHours(3 + $i), 'finished_at' => now()->subHours(2 + $i), 'result_games' => $games,
        ])->id;
    }
    playerStatsRating('user:'.$player->id, 'rocket-league', '1v1', [[0.0, -16], [1.0, 16]], RatingChange::SERIES, array_reverse($rl));

    // Clan now (with a 2v2 lineup) and one left.
    $clan = Clan::factory()->create(['name' => 'Laser Eyes', 'owner_id' => $player->id]);
    $lineup = Lineup::factory()->mode('2v2')->ready()->create(['clan_id' => $clan->id]);
    LineupSeat::query()->where('lineup_id', $lineup->id)->update(['accepted_at' => now()->subMonth()]);
    $rival = Lineup::factory()->mode('2v2')->ready()->create(['clan_id' => Clan::factory()->create(['name' => 'Proof Of Work'])->id]);
    $series = SeriesMatch::factory()->create(['game' => 'rocket-league', 'mode' => '2v2', 'challenger_lineup_id' => $lineup->id, 'challenged_lineup_id' => $rival->id,
        'status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'start_at' => now()->subMinutes(50), 'finished_at' => now()->subMinutes(30), 'result_games' => [$game(3, 2), $game(1, 0), $game(2, 4), $game(5, 1)]]);
    playerStatsRating('lineup:'.$lineup->id, 'rocket-league', '2v2', [[1.0, 20]], RatingChange::SERIES, [$series->id]);
    $former = Clan::factory()->create(['name' => 'Stack Sats']);
    ClanDeparture::query()->create(['clan_id' => $former->id, 'clan_name' => 'Stack Sats', 'user_id' => $player->id, 'reason' => 'left', 'left_at' => now()->subMonths(3)]);

    // A won tournament with its paid prize.
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    TournamentParticipant::query()->where('tournament_id', $tournament->id)->where('seed', 1)->update(['user_id' => $player->id, 'members' => [$player->id]]);
    playOutAsDirector($tournament);
    $tournament->forceFill(['published_at' => now()->subWeek(), 'name' => 'Halving Blitz Cup'])->save();
    expect(app(TournamentPlacements::class)->of($tournament->refresh())[0]['participants'])->toBe([TournamentParticipant::query()->where('tournament_id', $tournament->id)->where('user_id', $player->id)->value('id')]);
    TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'pubkey' => $player->pubkey, 'name' => 'x', 'place' => 1, 'amount_sats' => 21_000,
        'idempotency_key' => TournamentPayout::keyFor($tournament->id, $player->pubkey, 1), 'status' => PayoutStatus::Paid]);

    return $player;
}

function playerStatsPage(User $player, int $width, int $height, ?User $visitor = null): Page
{
    $page = visit($visitor ? BrowserLogin::url($visitor) : '/robots.txt')->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('players.show', $player->npub, false)));
    BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=player-stats]") !== null && document.fonts.status === "loaded"', 10_000);

    return $page;
}

function playerStatsShot(Page $page, string $name, ?string $element = null): void
{
    $dir = getenv('CASUAL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $element === null ? $page->screenshot(true, $name) : $page->screenshotElement($element, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * @return array<string, mixed>
 */
function playerStatsGeometry(Page $page): array
{
    return $page->evaluate('() => {
        const root = document.querySelector("[data-test=player-stats]");
        const parts = [...root.querySelectorAll("section")];
        const inside = parts.every((el) => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth + 0.5; });
        const targets = [...root.querySelectorAll("a[href], button")].filter((el) => el.checkVisibility() && getComputedStyle(el).display !== "inline");
        const small = targets.filter((el) => el.getBoundingClientRect().height < 44).map((el) => (el.dataset.test || el.innerText.trim().slice(0, 30)) + " " + Math.round(el.getBoundingClientRect().height));
        const clipped = [...root.querySelectorAll("*")].filter((el) => el.checkVisibility() && el.children.length === 0 && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).textOverflow !== "ellipsis" && getComputedStyle(el).overflowX === "visible").map((el) => el.dataset.test || el.tagName + ":" + el.innerText.slice(0, 20));
        const cards = [...root.querySelectorAll("[data-test=player-ladder]")].map((el) => { const r = el.getBoundingClientRect(); return [Math.round(r.top), Math.round(r.height)]; });
        const firstCard = root.querySelector("[data-test=player-ladder], [data-test=player-ladders-empty]").getBoundingClientRect().top;
        const side = root.querySelector("[data-test=player-tournaments]").getBoundingClientRect().top;
        // The time of a result stays whole next to a cut game name.
        const hiddenWhen = [...root.querySelectorAll("[data-test=player-result-when]")].filter((el) => { const r = el.getBoundingClientRect(), row = el.closest("a").querySelector("[data-test=player-result-score]").getBoundingClientRect(); return r.width < 8 || el.scrollWidth > el.clientWidth + 1 || r.right > row.left; }).length;
        return { overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, inside, small, clipped, cards, hiddenWhen, sideOffset: Math.round(side - firstCard) };
    }');
}

function playerStatsControl(Page $page): void
{
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
}

test('a player with games shows ladders, results, the won cup, seasons and clans, measured at 1440 and 375 and in German at 375', function () {
    $player = playerStatsSeed();

    $wide = playerStatsPage($player, 1440, 900);
    playerStatsControl($wide);
    $desk = playerStatsGeometry($wide);
    $state = $wide->evaluate('() => ({
        ladders: [...document.querySelectorAll("[data-test=player-ladder]")].map((el) => el.dataset.ladder + " " + el.querySelector("[data-test=player-ladder-rating]").innerText),
        form: [...document.querySelector("[data-test=player-ladder-form]").querySelectorAll("[data-outcome]")].map((el) => el.dataset.outcome),
        results: [...document.querySelectorAll("[data-test=player-result]")].map((el) => el.dataset.outcome + " " + el.querySelector("[data-test=player-result-score]").innerText),
        place: document.querySelector("[data-test=player-tournament-place]").innerText,
        prize: document.querySelector("[data-test=player-tournament-prize]").innerText,
        clans: [...document.querySelectorAll("[data-test=player-clan]")].map((el) => el.dataset.current),
        faces: [...document.querySelectorAll("[data-test=player-result]")].filter((el) => el.querySelector("[data-avatar]")).length,
    })');
    playerStatsShot($wide, 'player-stats-1440');
    playerStatsShot($wide, 'player-stats-part-1440', '[data-test=player-stats]');

    $narrow = playerStatsPage($player, 375, 812);
    $phone = playerStatsGeometry($narrow);
    playerStatsShot($narrow, 'player-stats-375');

    $de = playerStatsPage($player, 375, 812, User::factory()->create(['locale' => 'de']));
    $german = playerStatsGeometry($de);
    $words = $de->evaluate('() => [document.querySelector("#ps-ladders-h").innerText, document.querySelector("#ps-results-h").innerText, document.querySelector("[data-test=player-ladder-record]").innerText]');
    playerStatsShot($de, 'player-stats-de-375');

    // The won cup moved the chess rating too, so the chess value is read back.
    $chess = Rating::query()->where(['subject' => 'user:'.$player->id, 'game' => 'chess', 'pool' => Rating::CASUAL])->value('rating');

    expect($state['ladders'])->toBe(['chess/blitz '.$chess, 'rocket-league/1v1 1000', 'rocket-league/2v2 1020'])
        // The exact order of the form is tests/Feature/PlayerStatsTest.php's; here five played blocks.
        ->and($state['form'])->toHaveCount(5)->not->toContain('none')
        ->and($state['results'])->toHaveCount(10)
        // The cup's two games are the newest, then the series.
        ->and(array_slice($state['results'], 0, 5))->toBe(['win 1–0', 'win 1–0', 'win 3 : 1', 'win 2 : 0', 'loss 0 : 2'])
        // Every player row has the opponent's picture; the lineup series has the clan's mark.
        ->and($state['faces'])->toBe(9)
        ->and($state['place'])->toBe('1.')
        ->and($state['prize'])->toBe("21\u{00A0}000 sats")
        ->and($state['clans'])->toBe(['true', 'false'])
        ->and($desk)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => [], 'hiddenWhen' => 0])
        // The side column starts level with the first ladder card.
        ->and(abs($desk['sideOffset']))->toBeLessThanOrEqual(1)
        // Two cards in a row from sm, all of one height per row.
        ->and(count(array_unique(array_column(array_slice($desk['cards'], 0, 2), 0))))->toBe(1)
        ->and(count(array_unique(array_column(array_slice($desk['cards'], 0, 2), 1))))->toBe(1)
        ->and($phone)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => [], 'hiddenWhen' => 0])
        ->and($german)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => [], 'hiddenWhen' => 0])
        ->and($words)->toBe(['Ladders', 'Letzte Ergebnisse', '9 S, 2 U, 3 N']);

    foreach ([$wide, $narrow, $de] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

test('a player with nothing played gets one line per part, nothing cut, as a guest and on the own page', function () {
    $player = User::factory()->create(['name' => 'Fresh Pleb']);

    $guest = playerStatsPage($player, 1440, 900);
    playerStatsControl($guest);
    $desk = playerStatsGeometry($guest);
    $lines = $guest->evaluate('() => ["player-ladders-empty", "player-results-empty", "player-tournaments-empty", "player-seasons-empty", "player-clans-empty"].map((t) => { const el = document.querySelector("[data-test=" + t + "]"); return el ? Math.round(el.getBoundingClientRect().height) : null; })');
    $soon = $guest->evaluate('() => /coming soon|still being built/i.test(document.body.innerText)');
    playerStatsShot($guest, 'player-stats-empty-1440');

    $own = playerStatsPage($player, 375, 812, $player);
    $phone = playerStatsGeometry($own);
    $actions = $own->evaluate('() => [...document.querySelectorAll("[data-test=player-stats] a")].map((a) => new URL(a.href).pathname)');
    playerStatsShot($own, 'player-stats-empty-own-375');

    expect($lines)->each->toBeLessThanOrEqual(90)
        ->and($soon)->toBeFalse()
        ->and($desk)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => [], 'hiddenWhen' => 0])
        ->and($phone)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => [], 'hiddenWhen' => 0])
        ->and($actions)->toBe(['/play', '/tournaments', '/clans']);

    foreach ([$guest, $own] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

test('a Blockfill player gets a score card next to the ladders, measured at 1440 and 375 and in German at 375', function () {
    BlockfillOn::play();
    $this->freezeTime();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    $player = playerStatsSeed();

    // This week's board: a faster rival, then the player with two runs (the better one counts).
    foreach ([[User::factory()->create(['name' => 'Ada Blockspace']), 958], [$player, 1500], [$player, 1200]] as [$user, $ticks]) {
        $run = StackerRun::factory()->for($user)->verified($ticks)->create(['submitted_at' => now()->subMinutes($ticks / 10), 'week' => StackerRuns::weekOf(now())]);
        app(BlockfillWeeks::class)->record($run, now());
    }

    $wide = playerStatsPage($player, 1440, 900);
    playerStatsControl($wide);
    $desk = playerStatsGeometry($wide);
    $card = $wide->evaluate('() => { const el = document.querySelector("[data-test=player-score][data-game=blockfill]"); const r = el.getBoundingClientRect(); return {
        best: el.querySelector("[data-test=player-score-best]").innerText,
        board: el.querySelector("[data-test=player-score-board-place]").innerText,
        attempts: el.querySelectorAll("[data-test=player-score-attempt]").length,
        order: [...document.querySelectorAll("[data-test=player-ladder], [data-test=player-score]")].map((c) => c.dataset.game),
        width: Math.round(r.width), height: Math.round(r.height) }; }');
    playerStatsShot($wide, 'player-games-1440');

    $narrow = playerStatsPage($player, 375, 812);
    $phone = playerStatsGeometry($narrow);
    playerStatsShot($narrow, 'player-games-375');
    playerStatsShot($narrow, 'player-games-card-375', '[data-test=player-score]');

    $de = playerStatsPage($player, 375, 812, User::factory()->create(['locale' => 'de']));
    $german = playerStatsGeometry($de);
    $words = $de->evaluate('() => [...document.querySelector("[data-test=player-score]").querySelectorAll(".text-ink-3")].map((el) => el.innerText.trim()).filter((t) => t !== "")');
    playerStatsShot($de, 'player-games-de-375');

    expect($card['best'])->toBe(ScoreMetric::time()->format(Blockfill::milliseconds(1200)))
        ->and($card['board'])->toBe(ScoreMetric::time()->format(Blockfill::milliseconds(1200)).' · #2 of 2')
        ->and($card['attempts'])->toBe(2)
        ->and($card['order'])->toBe(['chess', 'rocket-league', 'rocket-league', 'blockfill'])
        ->and($desk)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => [], 'hiddenWhen' => 0])
        ->and(abs($desk['sideOffset']))->toBeLessThanOrEqual(1)
        ->and($phone)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => [], 'hiddenWhen' => 0])
        ->and($german)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => [], 'hiddenWhen' => 0])
        ->and($words)->toContain('Persönliche Bestmarke', 'Letzte bestätigte Versuche');

    foreach ([$wide, $narrow, $de] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});
