<?php

use App\Enums\ClanRole;
use App\Enums\PayoutStatus;
use App\Enums\SeriesStatus;
use App\Enums\StackerRunStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\ScoreMetric;
use App\Jobs\VerifyStackerRun;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanDeparture;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\ScoreRun;
use App\Models\Season;
use App\Models\SeriesMatch;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\GameNames;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Players\PlayerStats;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BlockfillOn;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The public record on the player page (P31)
|--------------------------------------------------------------------------
|
| Ladders (rating, rank, form, share of wins, peak), the latest results,
| tournaments with place and paid prize, the season record and the clans,
| each with its own empty line; the same for a guest and a signed-in
| visitor, and nothing a guest must not see.
|
*/

/**
 * Finished chess games of `$player` against fresh opponents, oldest first,
 * each with the rating change it wrote in the player's `$pool` ladder.
 *
 * @param  list<array{0: string, 1: int}>  $games  [result from the player's side (win|loss|draw), delta]
 */
beforeEach(function () {
    // The admins approved the weeks around now (the approval itself: LeagueWeekApprovalTest).
    leagueWeeksApproved(Blockfill::SLUG);
});

function playerStatsChess(User $player, array $games, string $pool = Rating::CASUAL, string $season = ''): Rating
{
    $rating = Rating::query()->create(['pool' => $pool, 'season' => $season, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$player->id, 'user_id' => $player->id,
        'rating' => 1000, 'results' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0]);
    $value = 1000;

    foreach ($games as $index => [$outcome, $delta]) {
        $game = ChessGame::factory()->finished(match ($outcome) {
            'win' => '1-0', 'loss' => '0-1', default => '1/2-1/2'
        })
            ->create(['white_id' => $player->id, 'rated' => $pool === Rating::RATED, 'updated_at' => now()->subHours(count($games) - $index)]);
        RatingChange::query()->create(['rating_id' => $rating->id, 'source' => RatingChange::CHESS, 'source_id' => $game->id, 'score' => match ($outcome) {
            'win' => 1.0, 'loss' => 0.0, default => 0.5
        },
            'before' => $value, 'after' => $value + $delta, 'delta' => $delta, 'results_before' => $index]);
        $value += $delta;
    }

    $rating->forceFill([
        'rating' => $value, 'results' => count($games),
        'wins' => count(array_filter($games, fn (array $game): bool => $game[0] === 'win')),
        'draws' => count(array_filter($games, fn (array $game): bool => $game[0] === 'draw')),
        'losses' => count(array_filter($games, fn (array $game): bool => $game[0] === 'loss')),
    ])->save();

    return $rating;
}

test('a player without games gets one line per part and no coming-soon text', function () {
    $player = User::factory()->create();

    $page = $this->get(route('players.show', $player->npub))->assertOk();

    $page->assertSee('data-test="player-ladders-empty"', false)
        ->assertSee('data-test="player-results-empty"', false)
        ->assertSee('data-test="player-tournaments-empty"', false)
        ->assertSee('data-test="player-seasons-empty"', false)
        ->assertSee('data-test="player-clans-empty"', false)
        ->assertSee(__('No results yet. The first finished game puts :name on a ladder.', ['name' => $player->displayName()]))
        ->assertDontSee('Coming soon')
        ->assertDontSee('still being built')
        // Another player's page offers no action of the player's own.
        ->assertDontSee(__('Find a game'))
        ->assertDontSee(__('Find a clan'));

    $this->actingAs($player)->get(route('players.show', $player->npub))->assertOk()
        ->assertSee(__('No results yet. Your first finished game puts you on a ladder.'))
        ->assertSee(route('play'), false)
        ->assertSee(__('Find a clan'))
        ->assertSee(__('See tournaments'));
});

test('a casual ladder shows rating, record, share of wins, the last five oldest first and the peak', function () {
    $player = User::factory()->create();
    // 1000 → 1016 → 1030 (peak) → 1014 → 1014 → 1026 → 1010
    playerStatsChess($player, [['win', 16], ['win', 14], ['loss', -16], ['draw', 0], ['win', 12], ['loss', -16]]);

    $html = $this->get(route('players.show', $player->npub))->assertOk()->getContent();
    $card = str($html)->after('data-test="player-ladder"')->before('</li>');

    expect((string) $card)->toContain('data-pool="casual"')
        ->toContain('>1010<')
        ->toContain(__('Casual'))
        ->toContain(__(':w W, :d D, :l L', ['w' => 3, 'd' => 1, 'l' => 2]))
        ->toContain(__(':n% won', ['n' => 50]))
        ->and(str($card)->after('data-test="player-ladder-peak"')->before('</span>')->toString())->toContain('1030')
        // The last five, oldest first: win, loss, draw, win, loss (the first win dropped out).
        ->and(preg_match_all('/data-outcome="(\w+)"/', (string) str($card)->after('player-ladder-form')->before('player-ladder-record'), $form))->toBe(5)
        ->and($form[1])->toBe(['win', 'loss', 'draw', 'win', 'loss'])
        // A casual rating counts for no rank.
        ->and((string) $card)->not->toContain(__('Provisional'));
});

test('a new ladder fills its form from the right with empty slots', function () {
    $player = User::factory()->create();
    playerStatsChess($player, [['win', 20], ['win', 18]]);

    $card = str($this->get(route('players.show', $player->npub))->getContent())->after('player-ladder-form')->before('player-ladder-record');
    preg_match_all('/data-outcome="(\w+)"/', (string) $card, $form);

    expect($form[1])->toBe(['none', 'none', 'none', 'win', 'win']);
});

test('once a season is live the ladder is the rated one, with its rank badge', function () {
    Season::factory()->create();
    $player = User::factory()->create();
    playerStatsChess($player, [['win', 16], ['loss', -10]]);
    playerStatsChess($player, [['win', 40], ['win', 30], ['win', 25], ['win', 20], ['win', 15], ['win', 10]], Rating::RATED, 'pre-season');

    $html = $this->get(route('players.show', $player->npub))->assertOk()->getContent();

    // 1140: platinum-2 with six rated results.
    expect(substr_count($html, 'data-test="player-ladder"'))->toBe(1)
        ->and($html)->toContain('data-pool="rated"')
        ->and((string) str($html)->after('data-test="player-ladder"')->before('</li>'))->toContain('>1140<')->toContain(__('Platinum').' II')
        // The season record lists the Pre-Season and Season 0 apart.
        ->and((string) str($html)->after('data-season="rated-pre-season"')->before('</li>'))->toContain(__(':w W, :d D, :l L', ['w' => 6, 'd' => 0, 'l' => 0]))
        ->and((string) str($html)->after('data-season="casual"')->before('</li>'))->toContain(__(':w W, :d D, :l L', ['w' => 1, 'd' => 0, 'l' => 1]));
});

test('recent results show the opponent, the score and what the result did to the rating, newest first', function () {
    $player = User::factory()->create();
    playerStatsChess($player, [['win', 16], ['loss', -12]]);

    $html = $this->get(route('players.show', $player->npub))->getContent();
    preg_match_all('/data-test="player-result" data-outcome="(\w+)"/', $html, $outcomes);
    preg_match_all('/data-test="player-result-score">([^<]+)</', $html, $scores);

    expect($outcomes[1])->toBe(['loss', 'win'])
        ->and($scores[1])->toBe(['0–1', '1–0'])
        ->and($html)->toContain('−12')->toContain('+16');
});

test('recent results stop at ten', function () {
    $player = User::factory()->create();
    playerStatsChess($player, array_fill(0, 12, ['win', 5]));

    expect(substr_count($this->get(route('players.show', $player->npub))->getContent(), 'data-test="player-result"'))->toBe(10);
});

test('a lineup series counts for a player only from the day their seat was accepted', function () {
    $lineup = Lineup::factory()->mode('2v2')->ready()->create();
    $other = Lineup::factory()->mode('2v2')->ready()->create();
    // The captain has sat in the lineup for a week; the newcomer since yesterday.
    LineupSeat::query()->where('lineup_id', $lineup->id)->update(['accepted_at' => now()->subWeek()]);
    $newcomer = User::factory()->create();
    ClanMember::query()->create(['clan_id' => $lineup->clan_id, 'user_id' => $newcomer->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
    LineupSeat::query()->create(['lineup_id' => $lineup->id, 'user_id' => $newcomer->id, 'role' => 'substitute', 'accepted_at' => now()->subDay()]);
    $game = ['challenger' => 3, 'challenged' => 1, 'winner' => 'challenger'];
    $series = fn ($finished) => SeriesMatch::factory()->create(['game' => 'rocket-league', 'mode' => '2v2', 'challenger_lineup_id' => $lineup->id, 'challenged_lineup_id' => $other->id,
        'status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'finished_at' => $finished, 'result_games' => [$game, $game]]);
    $series(now()->subDays(5));
    $series(now()->subHour());

    $owner = $this->get(route('players.show', $lineup->clan->owner->npub))->getContent();
    $late = $this->get(route('players.show', $newcomer->npub))->getContent();

    expect(substr_count($owner, 'data-test="player-result"'))->toBe(2)
        ->and(substr_count($late, 'data-test="player-result"'))->toBe(1)
        ->and($late)->toContain('>2 : 0<')->toContain(e($other->clan->name));
});

test('tournaments show the place and only the prize the league paid, never a pot', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $tournament->forceFill(['published_at' => now()->subDay(), 'name' => 'Halving Cup'])->save();
    playOutAsDirector($tournament);
    $places = app(TournamentPlacements::class)->of($tournament->refresh());
    $champion = TournamentParticipant::query()->findOrFail($places[0]['participants'][0])->user;
    $runnerUp = TournamentParticipant::query()->findOrFail($places[1]['participants'][0])->user;
    $payout = fn (User $user, int $place, int $sats, PayoutStatus $status) => TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'pubkey' => $user->pubkey, 'name' => 'x',
        'place' => $place, 'amount_sats' => $sats, 'idempotency_key' => TournamentPayout::keyFor($tournament->id, $user->pubkey, $place), 'status' => $status]);
    $payout($champion, 1, 21_000, PayoutStatus::Paid);
    $payout($runnerUp, 2, 12_600, PayoutStatus::Failed);

    $first = $this->get(route('players.show', $champion->npub))->getContent();
    $second = $this->get(route('players.show', $runnerUp->npub))->getContent();

    expect((string) str($first)->after('data-test="player-tournament"')->before('</a>'))->toContain('Halving Cup')->toContain('>1.<')->toContain("21\u{00A0}000 sats")
        ->and($first)->toContain('data-test="player-prizes"')
        ->and((string) str($second)->after('data-test="player-tournament"')->before('</a>'))->toContain('>2.<')->not->toContain('sats')
        // An unpaid payout is no prize won.
        ->and($second)->not->toContain('data-test="player-prizes"')->not->toContain('12 600')->not->toContain("12\u{00A0}600");
});

test('an unpublished tournament is not listed', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2);
    $player = $tournament->participants()->first()->user;

    $this->get(route('players.show', $player->npub))->assertSee('data-test="player-tournaments-empty"', false);
});

test('the clans: the one now, then the ones left, without why', function () {
    $player = User::factory()->create();
    $now = Clan::factory()->create(['name' => 'Laser Eyes']);
    ClanMember::query()->create(['clan_id' => $now->id, 'user_id' => $player->id, 'role' => ClanRole::Member, 'joined_at' => now()->subMonth()]);
    $left = Clan::factory()->create(['name' => 'Stack Sats']);
    ClanDeparture::query()->create(['clan_id' => $left->id, 'clan_name' => 'Stack Sats', 'user_id' => $player->id, 'reason' => 'removed', 'left_at' => now()->subMonths(2)]);
    ClanDeparture::query()->create(['clan_id' => 999_999, 'clan_name' => 'Gone Clan', 'user_id' => $player->id, 'reason' => 'left', 'left_at' => now()->subMonths(4)]);

    $html = $this->get(route('players.show', $player->npub))->getContent();
    preg_match_all('/data-test="player-clan" data-current="(\w+)"/', $html, $rows);

    expect($rows[1])->toBe(['true', 'false', 'false'])
        ->and((string) str($html)->after('data-test="player-clans"')->before('</section>'))
        ->toContain('Laser Eyes')->toContain('Stack Sats')->toContain('Gone Clan')->toContain(route('clans.show', $left))
        ->not->toContain('removed');
});

test('a guest and a signed-in visitor see the same record, and a guest never the game they look for', function () {
    $player = User::factory()->create(['looking_to_play' => 'rocket-league/1v1']);
    playerStatsChess($player, [['win', 16]]);
    $stats = fn (string $html): string => (string) str($html)->after('data-test="player-stats"')->before('</main>');

    $guest = $this->get(route('players.show', $player->npub))->assertOk()->getContent();
    $visitor = $this->actingAs(User::factory()->create())->get(route('players.show', $player->npub))->assertOk()->getContent();

    expect($stats($guest))->toBe($stats($visitor))
        ->and(preg_match('/href="([^"]+)" data-test="schedule-1v1"/', $guest, $link))->toBe(1)
        ->and(html_entity_decode($link[1]))->not->toContain('game=')
        ->and($stats($guest))->not->toContain('looking');
});

test('the record costs the same queries for two results as for twelve', function () {
    $few = User::factory()->create();
    playerStatsChess($few, [['win', 5], ['loss', -5]]);
    $many = User::factory()->create();
    playerStatsChess($many, array_fill(0, 12, ['win', 5]));
    $count = function (User $user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('players.show', $user->npub))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $count($few);

    expect($count($many))->toBe($count($few));
});

test('a player with more tournaments than listed: six rows read, the count and the prize total over all of them', function () {
    $player = User::factory()->create();
    $extra = 3;
    $tournaments = Tournament::factory()->count(PlayerStats::TOURNAMENTS + $extra)
        ->sequence(fn ($sequence) => ['name' => 'Cup '.$sequence->index, 'starts_at' => now()->subDays(20 - $sequence->index)])
        ->create(['status' => TournamentStatus::Finished, 'published_at' => now()->subMonth()]);

    foreach ($tournaments as $tournament) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => 'x', 'rating' => 1000, 'members' => [$player->id]]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => User::factory()->create()->id, 'name' => 'y', 'rating' => 1000]);
        TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'pubkey' => $player->pubkey, 'name' => 'x', 'place' => 1, 'amount_sats' => 1_000,
            'idempotency_key' => TournamentPayout::keyFor($tournament->id, $player->pubkey, 1), 'status' => PayoutStatus::Paid]);
    }

    $hydrated = ['participants' => 0, 'payouts' => 0, 'tournaments' => 0];
    TournamentParticipant::retrieved(function () use (&$hydrated): void {
        $hydrated['participants']++;
    });
    TournamentPayout::retrieved(function () use (&$hydrated): void {
        $hydrated['payouts']++;
    });
    Tournament::retrieved(function () use (&$hydrated): void {
        $hydrated['tournaments']++;
    });

    $stats = (new PlayerStats($player))->tournaments();

    expect($stats['count'])->toBe(PlayerStats::TOURNAMENTS + $extra)
        ->and($stats['prizes'])->toBe((PlayerStats::TOURNAMENTS + $extra) * 1_000)
        // Newest start first.
        ->and(array_map(fn (array $row): string => $row['tournament']->name, $stats['rows']))->toBe(['Cup 8', 'Cup 7', 'Cup 6', 'Cup 5', 'Cup 4', 'Cup 3'])
        ->and(array_column($stats['rows'], 'prize'))->toBe(array_fill(0, PlayerStats::TOURNAMENTS, 1_000))
        ->and(array_column($stats['rows'], 'of'))->toBe(array_fill(0, PlayerStats::TOURNAMENTS, 2))
        // Only the listed tournaments and the player's entries in them are loaded; payouts only as sums.
        ->and($hydrated)->toBe(['participants' => PlayerStats::TOURNAMENTS, 'payouts' => 0, 'tournaments' => PlayerStats::TOURNAMENTS]);

    $this->get(route('players.show', $player->npub))->assertOk()
        ->assertSee(trans_choice(':count played|:count played', PlayerStats::TOURNAMENTS + $extra))
        ->assertSee("9\u{00A0}000 sats", false);
});

/** A ranked Blockfill run of `$user` with `$ticks`, decided by the verifier job as verified (it joins its week). */
function playerStatsBlockfillRun(User $user, int $ticks): StackerRun
{
    $run = StackerRun::factory()->for($user)->create([
        'status' => StackerRunStatus::Verifying, 'issued_at' => now(), 'started_at' => now(), 'submitted_at' => now(),
        'ticks' => $ticks, 'state_hash' => '00000000', 'replay' => 'AAAA',
    ]);

    VerifyStackerRun::dispatchSync($run->id);

    return $run->refresh();
}

test('a player with chess and Blockfill sees the chess ladder with its place and Blockfill with best, week place, points place and attempts', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    app()->instance(Verifier::class, new FakeStackerVerifier);
    BlockfillOn::play();
    $time = fn (int $ticks): string => ScoreMetric::time()->format(Blockfill::milliseconds($ticks));
    $player = User::factory()->create(['name' => 'Stack Pleb']);
    $rival = User::factory()->create(['name' => 'Fast Rival']);
    // Chess: two players on the casual blitz ladder, the player second.
    playerStatsChess($player, [['win', 16], ['loss', -10]]);
    playerStatsChess($rival, [['win', 30]]);

    // Last week (Monday 2026-09-28 Berlin): the player wins it, and its end scores the points ladder.
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00'));
    app(BlockfillWeeks::class)->open();
    playerStatsBlockfillRun($player, 900);
    playerStatsBlockfillRun($rival, 1300);

    // This week: the rival is faster, the player second with their better of two runs.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    app(ScoreLeaderboards::class)->tick();
    app(BlockfillWeeks::class)->open();
    playerStatsBlockfillRun($player, 1500);
    playerStatsBlockfillRun($rival, 958);
    $this->travel(5)->minutes();
    playerStatsBlockfillRun($player, 1200);

    $html = $this->get(route('players.show', $player->npub))->assertOk()->getContent();
    $chess = (string) str($html)->after('data-test="player-ladder" data-game="chess"')->before('</li>');
    $blockfill = (string) str($html)->after('data-test="player-score" data-game="blockfill"')->before('</li>');

    expect($chess)->toContain('>1006<')
        ->and((string) str($chess)->after('data-test="player-ladder-place"')->before('</span>'))->toContain(__('#:place of :count', ['place' => 2, 'count' => 2]))
        ->and($blockfill)->toContain(e(GameNames::mode(Blockfill::SLUG, Blockfill::MODE)))
        ->and($blockfill)->toContain(trans_choice(':count verified attempt|:count verified attempts', 3))
        // The best of all three runs is last week's.
        ->and((string) str($blockfill)->after('data-test="player-score-best"')->before('</b>'))->toContain($time(900))
        // This week: their better run, second of two.
        ->and((string) str($blockfill)->after('data-test="player-score-board"')->before('</a>'))->toContain(e(__('Blockfill Week :week, :year', ['week' => 41, 'year' => 2026])))
        ->and((string) str($blockfill)->after('data-test="player-score-board-place"')->before('</span>'))->toContain($time(1200))->toContain(__('#:place of :count', ['place' => 2, 'count' => 2]))
        // Last week's win leads the points ladder.
        ->and((string) str($blockfill)->after('data-test="player-score-points"')->before('</span>'))->toContain(__('#:place of :count', ['place' => 1, 'count' => 2]))
        // The latest attempts, newest first.
        ->and(preg_match_all('#data-test="player-score-attempt">\s*<b>([^<]+)</b>#', $blockfill, $attempts))->toBe(3)
        ->and($attempts[1])->toBe([$time(1200), $time(1500), $time(900)])
        // Chess stands before Blockfill, as in the registry.
        ->and(strpos($html, 'data-game="chess"'))->toBeLessThan(strpos($html, 'data-game="blockfill"'));
});

test('a score value nobody verified yet gives no card, and its proof link never reaches the page', function () {
    BlockfillOn::play();
    $player = User::factory()->create();
    ScoreRun::query()->create(['user_id' => $player->id, 'game' => Blockfill::SLUG, 'mode' => Blockfill::MODE, 'course' => Blockfill::MODE, 'value' => 15_000,
        'unit' => 'ms', 'source' => ScoreRun::MANUAL, 'achieved_at' => now(), 'proof_url' => 'https://example.test/proof/my-account-id']);

    $this->get(route('players.show', $player->npub))->assertOk()
        ->assertDontSee('data-test="player-score"', false)
        ->assertSee('data-test="player-ladders-empty"', false)
        ->assertDontSee('my-account-id');
});
