<?php

/*
| Score games next to chess, the series and the board games (plan "AoE2 und
| Trackmania", P4): a score game comes in only through `esports.score_games`
| (the demo off by default), and every switch between the kinds leaves it
| out of what it has none of: no Elo ladder, no ladder event, no casual
| queue or lobby, no cup, no lineup, no match filter, no mining. ScoreDemoOn
| switches the demo on.
*/

use App\Enums\TournamentStatus;
use App\Games\Chess;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Games\ScoreDemo;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Clans\ClanService;
use App\Support\Engagement\HomeHub;
use App\Support\GameNames;
use App\Support\Navigation\ShellNavigation;
use App\Support\Scores\ScoreRuns;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\ChainOverview;
use App\Support\Series\CasualLobby;
use App\Support\Series\CasualMatches;
use App\Support\Series\Ladders;
use App\Support\Series\SeriesRuleViolation;
use App\Support\StreamBot\StreamBotBuilders;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentGames;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\Support\ScoreDemoOn;

test('no score game is registered and no score route exists while the switch is off', function () {
    $registry = app(GameRegistry::class);

    expect(config('esports.score_games.demo'))->toBeFalse()
        ->and($registry->scores())->toBe([])
        ->and($registry->versus())->toBe($registry->all())
        ->and(Route::has('scores.show'))->toBeFalse()
        ->and(Route::has('tournaments.scores'))->toBeFalse()
        ->and(Route::has('admin.scores'))->toBeFalse()
        ->and(Route::has('scores.ingest'))->toBeFalse()
        ->and(collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains((string) $event->command, 'scores:tick')))->toBeFalse();
});

test('the demo comes in with its switch, names its kind and is neither chess, a series nor a board game', function () {
    ScoreDemoOn::play();
    $registry = app(GameRegistry::class);

    expect(array_keys($registry->scores()))->toBe([ScoreDemo::SLUG])
        ->and($registry->get(ScoreDemo::SLUG)->kind())->toBe(GameKind::Score)
        ->and($registry->isScore(ScoreDemo::SLUG))->toBeTrue()
        ->and($registry->isSeries(ScoreDemo::SLUG))->toBeFalse()
        ->and($registry->isBoard(ScoreDemo::SLUG))->toBeFalse()
        ->and($registry->versus())->not->toHaveKey(ScoreDemo::SLUG)
        ->and(GameProfile::for(ScoreDemo::SLUG, 'time-trial')->isScore())->toBeTrue()
        ->and(TournamentGames::find('score-demo/highscore'))->toBe([ScoreDemo::SLUG, 'highscore']);
});

test('a wrong score game entry stays off and leaves the other games registered', function () {
    config(['esports.score_games.games' => [Chess::class, 'No\\Such\\Game']]);
    app()->forgetInstance(GameRegistry::class);

    expect(app(GameRegistry::class)->scores())->toBe([])
        ->and(array_keys(app(GameRegistry::class)->all()))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2']);
});

test('its page is its leaderboards, in the navigation and the sitemap, never the chess lobby or an Elo ladder', function () {
    ScoreDemoOn::play();

    $nav = collect(ShellNavigation::current()->games())->firstWhere('slug', ScoreDemo::SLUG);

    expect(GameNames::page(ScoreDemo::SLUG))->toBe(route('scores.show', ScoreDemo::SLUG))
        ->and(array_column($nav['actions'], 'href'))->toBe([route('scores.show', ScoreDemo::SLUG)]);

    $sitemap = (string) $this->get(route('sitemap.section', ['section' => 'pages', 'file' => 1]))->assertOk()->getContent();

    expect($sitemap)->toContain(route('scores.show', ScoreDemo::SLUG))
        ->not->toContain('ladder/'.ScoreDemo::SLUG);

    $this->get(route('ladder.show', [ScoreDemo::SLUG, 'time-trial']))->assertNotFound();
    $this->get(route('scores.show', 'chess'))->assertNotFound();
});

test('it has no Elo ladder, no ladder event, no mining share by default and no stream bot announcement', function () {
    ScoreDemoOn::play();
    $season = openSeason();

    expect(Ladders::address(ScoreDemo::SLUG, 'time-trial'))->toBeNull()
        ->and(Ladders::address('chess', 'blitz'))->not->toBeNull();

    publishLadders($season);

    expect(NostrEvent::query()->where('kind', Ladders::KIND)->pluck('d')->filter(fn ($d) => str_starts_with((string) $d, ScoreDemo::SLUG.'/'))->all())->toBe([])
        ->and(NostrEvent::query()->where('kind', Ladders::KIND)->count())->toBeGreaterThan(0)
        // P7 of the plan: a score game is a row of the chain draft without a weight, share or daily limit, and mines
        // (one solo block per window the league opens) only once the board saves its proposal.
        ->and(ChainDraft::table(ChainDraft::defaults())[ScoreDemo::SLUG] ?? null)->toBe([ScoreDemo::SLUG.'/time-trial', ScoreDemo::SLUG.'/highscore'])
        ->and(collect(ChainDraft::defaults()['weights'])->keys()->filter(fn ($key) => str_starts_with((string) $key, ScoreDemo::SLUG))->all())->toBe([])
        ->and(ChainDraft::defaults()['shares'])->not->toHaveKey(ScoreDemo::SLUG)
        ->and(ChainDraft::defaults()['daily'])->not->toHaveKey(ScoreDemo::SLUG)
        ->and(ChainOverview::mines(ScoreDemo::SLUG.'/time-trial'))->toBeTrue();

    $names = json_encode(app(StreamBotBuilders::class)->build('all_games', now()->toImmutable()));

    expect($names)->toContain('Rocket League')->not->toContain('Score Demo');
});

test('it has no casual queue, no lobby, no cup and no lineup, even listed by mistake', function () {
    ScoreDemoOn::play();
    config([
        'esports.casual.games' => [...config('esports.casual.games'), ScoreDemo::SLUG],
        'esports.casual_cups.enabled' => [...config('esports.casual_cups.enabled', []), ScoreDemo::SLUG],
        'esports.casual_cups.games.'.ScoreDemo::SLUG => ['name' => 'Score', 'mode' => 'time-trial', 'best_of' => 1, 'final_best_of' => 1],
    ]);

    expect(CasualLobby::games())->not->toContain(ScoreDemo::SLUG)
        ->and(CasualCups::enabledGames())->not->toContain(ScoreDemo::SLUG)
        ->and(fn () => app(CasualMatches::class)->assertGame(ScoreDemo::SLUG))->toThrow(SeriesRuleViolation::class);

    $owner = User::factory()->create();
    $clan = Clan::factory()->create(['owner_id' => $owner->id]);

    expect(fn () => app(ClanService::class)->prepareLineup($owner, $clan, ScoreDemo::SLUG, 'time-trial', []))
        ->toThrow(ClanRuleViolation::class);
});

test('hubs, rules and weekly events leave it out; /matches lists its highscore attempts', function () {
    ScoreDemoOn::play();

    // Its attempts are listed with the matches (tests/Feature/Matches/ScoreAttemptsTest.php), so it has a filter there.
    Livewire\Livewire::withQueryParams(['game' => ScoreDemo::SLUG])->test('pages::matches.index')
        ->assertSet('game', ScoreDemo::SLUG)
        ->assertSeeHtml('data-test="game-'.ScoreDemo::SLUG.'"');

    expect(collect(app(HomeHub::class)->ladders())->pluck('game')->all())->not->toContain(ScoreDemo::SLUG);

    // The rules' table of games and modes (each with its ladder and series); the navigation lists the game as every other.
    $rules = (string) $this->get(route('rules'))->assertOk()->getContent();
    preg_match('#id="games".*?</section>#s', $rules, $table);

    expect($table[0] ?? '')->toContain('Rocket League')->not->toContain('Score Demo');
    $this->get(route('ladder.strongest'))->assertOk()->assertDontSee(route('ladder.show', [ScoreDemo::SLUG, 'time-trial']));
    $this->get(route('play'))->assertOk()->assertSee('data-test="play-game-'.ScoreDemo::SLUG.'"', false)->assertSee(__('Time attack, the fastest time wins'));
});

test('the invite link module offers "beat my time" for it, never a match or a chess link', function () {
    ScoreDemoOn::play();

    Livewire\Livewire::actingAs(User::factory()->create())->test('invite-link', ['game' => ScoreDemo::SLUG])
        ->assertSet('state', 'score')
        ->assertDontSeeHtml('data-state="daily"');
});

test('its leaderboard starts no series and no chess game when the bracket moves', function () {
    ScoreDemoOn::play();
    $tournament = Tournament::factory()->scoreDemo()->create(['status' => TournamentStatus::Running, 'starts_at' => now()->subHour()]);

    foreach (range(1, 2) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "P{$index}", 'rating' => 1500, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);
    Artisan::call('tournaments:advance');

    expect(SeriesMatch::query()->count())->toBe(0)
        ->and(ChessGame::query()->count())->toBe(0)
        ->and(BoardGame::query()->count())->toBe(0)
        ->and($tournament->matches()->sole()->status)->toBe('ready')
        ->and(app(ScoreRuns::class)->standings($tournament))->toHaveCount(2);
});
