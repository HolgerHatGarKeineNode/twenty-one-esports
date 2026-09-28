<?php

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Jobs\SyncRankBadges;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\PlacementReveal;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Engagement\ClanHashrate;
use App\Support\Rating\EloRating;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Elo of a corrected result (RatingService::correct(), P18 control)
|--------------------------------------------------------------------------
|
| An organizer or admin correcting a played result that moved rated Elo
| reverts that Elo exactly once and rates the corrected outcome, delta only
| (docs/nips/esports.md, "Elo of a corrected result"). A forfeit reverts
| and rates nothing. The old rows stay as the audit trail. Casual Elo and
| a result that moved nothing are left alone; rank badges, an unshown
| placement reveal and the clan hashrate follow the corrected outcome.
|
*/

beforeEach(function () {
    Queue::fake();
    app()->bind(TrustFacts::class, TrustedFacts::class);
});

function ecAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/**
 * A published, running players-mode RL 1v1 final between two keyed solo
 * players, rated when a season is open; its series paired.
 *
 * @return array{0: Tournament, 1: SeriesMatch, 2: array{0: User, 1: mixed}, 3: array{0: User, 1: mixed}}
 */
function ecDuel(): array
{
    $tournament = Tournament::factory()->rocketLeague()->create([
        'mode' => '1v1', 'options' => FormatOptions::defaults(GameProfile::for('rocket-league', '1v1'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'created_by_id' => organizer()->id,
    ]);
    $tournament = app(TournamentPublisher::class)->publish($tournament, $tournament->creator, CarbonImmutable::now()->addHour());
    $tournament->forceFill(['status' => TournamentStatus::Running])->save();
    $sides = [keyedPlayer(), keyedPlayer()];

    foreach ($sides as $index => [$player]) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $player->displayName(), 'rating' => 1100 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('9a', 32));
    app(TournamentRunner::class)->sync($tournament);
    $series = SeriesMatch::query()->where('tournament_match_id', $tournament->matches()->value('id'))->sole();
    // The challenger's side first.
    $challenger = $series->rosterSide('challenger')[0] === $sides[0][0]->id ? $sides[0] : $sides[1];
    $challenged = $challenger === $sides[0] ? $sides[1] : $sides[0];

    return [$tournament->refresh(), $series, $challenger, $challenged];
}

/** `$winner` reports a clean series win, signed, and `$other` confirms it, signed. */
function ecPlay(SeriesMatch $series, array $winner, array $other): SeriesMatch
{
    $service = app(SeriesService::class);
    $side = $series->refresh()->captainSideOf($winner[0]);

    foreach (range(0, intdiv($series->best_of, 2)) as $index) {
        $service->saveLiveGame($series, $winner[0], $index, $side === 'challenger' ? 3 : 1, $side === 'challenger' ? 1 : 3, null);
    }

    $service->report($series, $winner[0], $winner[1]->signTemplates($service->prepareReport($series, $winner[0])));
    $service->respond($series->refresh(), $other[0], 'confirmed', '', $other[1]->signTemplates($service->prepareResponse($series->refresh(), $other[0], 'confirmed', '')));

    return $series->refresh();
}

/** The rated rating row of a user in a game and mode. */
function ecRating(User $user, string $game, string $mode): Rating
{
    return Rating::query()->where(['pool' => Rating::RATED, 'game' => $game, 'mode' => $mode, 'subject' => 'user:'.$user->id])->sole();
}

/** The current game of a chess tournament match. */
function ecGame(TournamentMatch $match): ChessGame
{
    return ChessGame::query()->where('tournament_match_id', $match->id)->latest('id')->firstOrFail();
}

/** The loser resigns the match's current game. */
function ecResign(TournamentMatch $match, string $loserColor): ChessGame
{
    $game = ecGame($match);

    return app(ChessGameService::class)->resign($game, $loserColor === 'w' ? $game->white : $game->black)->refresh();
}

function ecMatches(Tournament $tournament): array
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->with(['slots.participant', 'round'])
        ->get()->sortBy(fn (TournamentMatch $match): array => [$match->round->number, $match->position])->values()->all();
}

/* ---------- Rocket League 1v1: a rated series corrected ------------------------------------------------------ */

test('a corrected winner of a rated series reverts the old Elo and gets the correct delta, exactly once', function () {
    openSeason(['slug' => 'season-1']);
    [$tournament, $series, $a, $b] = ecDuel();
    $series = ecPlay($series, $a, $b);
    $engine = EloRating::fromConfig('rating');
    $won = $engine->rate($engine->start, $engine->start, 1.0, 0, 0);
    $lost = $engine->rate($engine->start, $engine->start, 0.0, 0, 0);

    expect($series->status)->toBe(SeriesStatus::Confirmed)
        ->and(ecRating($a[0], 'rocket-league', '1v1')->rating)->toBe($won['challenger'])
        ->and($won['challenger_delta'])->toBeGreaterThan(0);

    $match = $tournament->matches()->firstOrFail();
    $attestations = SeasonAttestation::query()->count();
    $control = app(TournamentControl::class);
    $input = ['winners' => array_fill(0, intdiv($series->best_of, 2) + 1, 1)];
    $admin = ecAdmin();

    expect($control->eloPreview($tournament, $match->id, $input))->toBe(['reverted' => [$won['challenger_delta'], $won['challenged_delta']], 'applied' => [$lost['challenger_delta'], $lost['challenged_delta']]]);

    $changed = $control->setResult($tournament, $admin, $match->id, $input, 'The replay shows the other side won');
    // A double submit of the same correction changes nothing.
    $again = $control->setResult($tournament, $admin, $match->id, $input, 'The replay shows the other side won');
    $ca = ecRating($a[0], 'rocket-league', '1v1');
    $cb = ecRating($b[0], 'rocket-league', '1v1');
    $all = RatingChange::query()->withoutGlobalScope(RatingChange::LIVE)->where('source', RatingChange::SERIES)->where('source_id', $series->id)->orderBy('id')->get();
    $log = TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->sole();

    expect($changed)->toBeTrue()->and($again)->toBeFalse()
        // As if the corrected result had been rated in the first place: no later results here.
        ->and([$ca->rating, $cb->rating])->toBe([$lost['challenger'], $lost['challenged']])
        ->and([$ca->results, $ca->wins, $ca->losses, $cb->results, $cb->wins, $cb->losses])->toBe([1, 0, 1, 1, 1, 0])
        // Audit trail: the first two rows reverted, never deleted; the corrected two are revision 1.
        ->and($all)->toHaveCount(4)
        ->and($all->take(2)->every(fn (RatingChange $change): bool => $change->reverted_at !== null && $change->revision === 0))->toBeTrue()
        ->and($all->slice(2)->map(fn (RatingChange $change): array => [$change->revision, $change->reverted_at, $change->delta])->values()->all())
        ->toBe([[1, null, $lost['challenger_delta']], [1, null, $lost['challenged_delta']]])
        ->and($all[2]->created_at->equalTo($all[0]->created_at))->toBeTrue()
        ->and(RatingChange::query()->where('source_id', $series->id)->count())->toBe(2)
        // The log and the public marker state the Elo effect; the chain gets no new attestation.
        ->and($log->details['elo'])->toBe([sprintf('+%d/−%d', $won['challenger_delta'], abs($won['challenged_delta'])), sprintf('−%d/+%d', abs($lost['challenger_delta']), $lost['challenged_delta'])])
        ->and($match->refresh()->result)->toMatchArray(['unrated' => false, 'elo' => ['reverted' => [$won['challenger_delta'], $won['challenged_delta']], 'applied' => [$lost['challenger_delta'], $lost['challenged_delta']]]])
        ->and(TournamentView::marker($match->result)['lines'][1])->toContain('the Elo of the played result was corrected')
        ->and(SeasonAttestation::query()->count())->toBe($attestations);

    Queue::assertPushed(SyncRankBadges::class, fn (SyncRankBadges $job): bool => $job->game === 'rocket-league' && $job->mode === '1v1'
        && $job->subjects === ['user:'.$a[0]->id, 'user:'.$b[0]->id]);
});

test('a correction to a forfeit leaves both ratings as before the series; a second forfeit reverts nothing twice', function () {
    openSeason(['slug' => 'season-1']);
    [$tournament, $series, $a, $b] = ecDuel();
    $engine = EloRating::fromConfig('rating');
    ecPlay($series, $a, $b);
    $match = $tournament->matches()->firstOrFail();
    $control = app(TournamentControl::class);

    $control->setResult($tournament, ecAdmin(), $match->id, ['noshow' => 1], 'The winner was not the registered player');
    $control->setResult($tournament, ecAdmin(), $match->id, ['noshow' => 0], 'It was the other way round');

    foreach ([$a[0], $b[0]] as $player) {
        $rating = ecRating($player, 'rocket-league', '1v1');

        expect([$rating->rating, $rating->results, $rating->wins, $rating->draws, $rating->losses])->toBe([$engine->start, 0, 0, 0, 0]);
    }

    expect(RatingChange::query()->count())->toBe(0)
        ->and(RatingChange::query()->withoutGlobalScope(RatingChange::LIVE)->whereNotNull('reverted_at')->count())->toBe(2)
        ->and(TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->orderBy('id')->get()->pluck('details.elo')->all())
        ->toBe([[sprintf('+%d/−%d', 20, 20), null], null])
        ->and($match->refresh()->result['unrated'])->toBeTrue();
});

test('RatingService::revert is idempotent and a reverted result is never rated again by apply', function () {
    openSeason(['slug' => 'season-1']);
    [, $series, $a, $b] = ecDuel();
    $series = ecPlay($series, $a, $b);
    $ratings = app(RatingService::class);

    expect($ratings->revert($series))->not->toBeNull()
        ->and($ratings->revert($series))->toBeNull()
        ->and($ratings->correct($series, 1.0))->toBeNull()
        ->and($ratings->applySeries($series))->toBeFalse()
        ->and(RatingChange::query()->count())->toBe(0)
        ->and(ecRating($a[0], 'rocket-league', '1v1')->results)->toBe(0);
});

test('an admin decision never reaches a confirmed series: decide() refuses it, so its Elo is only corrected on the control', function () {
    openSeason(['slug' => 'season-1']);
    [, $series, $a, $b] = ecDuel();
    $series = ecPlay($series, $a, $b);

    expect(fn () => app(SeriesService::class)->decide($series, ecAdmin(), ['type' => 'void'], 'Changed my mind'))
        ->toThrow(SeriesRuleViolation::class, __('This match has nothing to decide.'));
    expect(RatingChange::query()->whereNotNull('reverted_at')->count())->toBe(0);
});

test('a correction of a casual (unrated) series leaves its casual Elo as it is', function () {
    // No season and no league key: casual, nothing published or signed.
    $tournament = Tournament::factory()->rocketLeague()->create([
        'mode' => '1v1', 'options' => FormatOptions::defaults(GameProfile::for('rocket-league', '1v1'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'ec-casual',
    ]);
    [$a, $b] = [[User::factory()->create()], [User::factory()->create()]];

    foreach ([$a[0], $b[0]] as $index => $player) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $player->displayName(), 'rating' => 1100 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('9a', 32));
    app(TournamentRunner::class)->sync($tournament);
    $series = SeriesMatch::query()->where('tournament_match_id', $tournament->matches()->value('id'))->sole();
    $service = app(SeriesService::class);
    $side = $series->captainSideOf($a[0]);

    foreach (range(0, intdiv($series->best_of, 2)) as $index) {
        $service->saveLiveGame($series, $a[0], $index, $side === 'challenger' ? 3 : 1, $side === 'challenger' ? 1 : 3, null);
    }

    $service->report($series, $a[0], []);
    $service->respond($series->refresh(), $b[0], 'confirmed', '', []);
    $before = RatingChange::query()->orderBy('id')->get(['id', 'before', 'after', 'delta'])->toArray();
    $flip = ['winners' => array_fill(0, intdiv($series->best_of, 2) + 1, $series->rosterSide('challenger')[0] === $a[0]->id ? 1 : 0)];
    $casual = Rating::query()->where('pool', Rating::CASUAL)->orderBy('id')->get(['rating', 'results'])->toArray();

    expect($before)->toHaveCount(2)
        ->and(app(TournamentControl::class)->eloPreview($tournament, $tournament->matches()->value('id'), $flip))->toBeNull();

    expect(app(TournamentControl::class)->setResult($tournament, ecAdmin(), $tournament->matches()->value('id'), $flip, 'The other side won'))->toBeTrue();

    expect(RatingChange::query()->orderBy('id')->get(['id', 'before', 'after', 'delta'])->toArray())->toBe($before)
        ->and(Rating::query()->where('pool', Rating::CASUAL)->orderBy('id')->get(['rating', 'results'])->toArray())->toBe($casual)
        ->and(RatingChange::query()->withoutGlobalScope(RatingChange::LIVE)->count())->toBe(2)
        ->and($tournament->matches()->firstOrFail()->result['unrated'])->toBeTrue();
});

test('a result of a season that has closed is not corrected', function () {
    $season = openSeason(['slug' => 'season-1']);
    [$tournament, $series, $a, $b] = ecDuel();
    ecPlay($series, $a, $b);
    $rating = ecRating($a[0], 'rocket-league', '1v1')->rating;
    $season->forceFill(['ends_at' => now()->subSecond()])->save();

    expect(app(TournamentControl::class)->setResult($tournament, ecAdmin(), $tournament->matches()->value('id'), ['winners' => array_fill(0, intdiv($series->best_of, 2) + 1, 1)], 'Too late for the ladder'))->toBeTrue();

    expect(ecRating($a[0], 'rocket-league', '1v1')->rating)->toBe($rating)
        ->and(RatingChange::query()->withoutGlobalScope(RatingChange::LIVE)->whereNotNull('reverted_at')->count())->toBe(0);
});

/* ---------- Chess: delta only, placement and hashrate -------------------------------------------------------- */

test('chess: a corrected first-round winner is re-rated delta only; the later final keeps its rating and is held', function () {
    openSeason();
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players, clans: true);
    [$first, $second] = ecMatches($tournament);
    $game = ecResign($first, 'b');
    ecResign($second, 'b');
    $final = ecMatches($tournament)[2];
    $finalGame = ecGame($final);
    $finalist = $game->white;
    $finalGame = app(ChessGameService::class)->resign($finalGame, $finalGame->white_id === $finalist->id ? $finalGame->black : $finalGame->white)->refresh();
    $finalChanges = RatingChange::query()->where('source', RatingChange::CHESS)->where('source_id', $finalGame->id)->orderBy('id')->get(['id', 'before', 'after', 'delta'])->toArray();
    $old = RatingChange::query()->where('source', RatingChange::CHESS)->where('source_id', $game->id)->orderBy('id')->get();
    $now = [ecRating($game->white, 'chess', 'blitz')->rating, ecRating($game->black, 'chess', 'blitz')->rating];
    $corrected = EloRating::fromConfig('rating')->rate($old[0]->before, $old[1]->before, 0.0, $old[0]->results_before, $old[1]->results_before);
    $whiteSlot = in_array($game->white_id, $first->slots[0]->participant->memberIds(), true) ? 0 : 1;

    expect($finalGame->status)->toBe(ChessGameStatus::Finished)->and($finalChanges)->toHaveCount(2);

    app(TournamentControl::class)->setResult($tournament, ecAdmin(), $first->id, ['result' => $whiteSlot === 0 ? '0-1' : '1-0'], 'Black won on time, the scoresheet was wrong');

    expect(ecRating($game->white, 'chess', 'blitz')->rating)->toBe($now[0] - $old[0]->delta + $corrected['challenger_delta'])
        ->and(ecRating($game->black, 'chess', 'blitz')->rating)->toBe($now[1] - $old[1]->delta + $corrected['challenged_delta'])
        // The later game is not replayed: its rows and their values stand as rated and attested.
        ->and(RatingChange::query()->where('source', RatingChange::CHESS)->where('source_id', $finalGame->id)->orderBy('id')->get(['id', 'before', 'after', 'delta'])->toArray())->toBe($finalChanges)
        ->and(ecMatches($tournament)[2]->held)->not->toBeNull();

    // The log lists the deltas in the order of the match's slots, whoever had White.
    $sign = fn (int $delta): string => ($delta >= 0 ? '+' : '−').abs($delta);
    $bySlot = $whiteSlot === 0 ? [$old[0]->delta, $old[1]->delta] : [$old[1]->delta, $old[0]->delta];

    expect(TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->latest('id')->first()->details['elo'][0])->toBe($sign($bySlot[0]).'/'.$sign($bySlot[1]));
});

test('chess: an unshown placement reveal follows the corrected rating, a shown one stays', function () {
    config(['season.rating.provisional' => 1]);
    openSeason();
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players, clans: true);
    [$match] = ecMatches($tournament);
    $game = ecResign($match, 'b');
    $white = PlacementReveal::query()->where('user_id', $game->white_id)->sole();
    $black = PlacementReveal::query()->where('user_id', $game->black_id)->sole();
    $black->forceFill(['shown_at' => now()])->save();
    $shown = $black->only(['rating', 'tier']);
    $whiteSlot = in_array($game->white_id, $match->slots[0]->participant->memberIds(), true) ? 0 : 1;

    app(TournamentControl::class)->setResult($tournament, ecAdmin(), $match->id, ['result' => $whiteSlot === 0 ? '0-1' : '1-0'], 'Black won');
    $rating = ecRating($game->white, 'chess', 'blitz');

    expect($white->refresh()->rating)->toBe($rating->rating)->toBeLessThan(1000)
        ->and($black->refresh()->only(['rating', 'tier']))->toBe($shown);

    // Forfeited instead: White is provisional again, the unshown reveal goes.
    app(TournamentControl::class)->setResult($tournament, ecAdmin(), $match->id, ['result' => 'noshow-'.$whiteSlot], 'White never showed up');

    expect(PlacementReveal::query()->whereKey($white->id)->exists())->toBeFalse()
        ->and(PlacementReveal::query()->whereKey($black->id)->exists())->toBeTrue();
});

test('chess: the clan hashrate counts the corrected winner, and a voided result not at all', function () {
    $season = openSeason();
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players, clans: true);
    [$match] = ecMatches($tournament);
    $game = ecResign($match, 'b');
    $clanOf = fn (User $user): string => Clan::query()->where('owner_id', $user->id)->sole()->address();
    $hashrate = function () use ($season): array {
        Cache::flush();

        return (new ClanHashrate)->forSeason($season->slug);
    };
    $whiteSlot = in_array($game->white_id, $match->slots[0]->participant->memberIds(), true) ? 0 : 1;
    $before = $hashrate();

    expect($before[$clanOf($game->white)] ?? 0)->toBeGreaterThan($before[$clanOf($game->black)] ?? 0);

    app(TournamentControl::class)->setResult($tournament, ecAdmin(), $match->id, ['result' => $whiteSlot === 0 ? '0-1' : '1-0'], 'Black won');
    $after = $hashrate();

    expect($after[$clanOf($game->black)] ?? 0)->toBe($before[$clanOf($game->white)])
        ->and($after[$clanOf($game->white)] ?? 0)->toBe($before[$clanOf($game->black)] ?? 0)
        ->and($game->refresh()->result)->toBe('1-0');

    app(TournamentControl::class)->setResult($tournament, ecAdmin(), $match->id, ['result' => 'noshow-'.$whiteSlot], 'White never showed up');

    expect($hashrate())->toBe([]);
});

/* ---------- The form states the effect before the save -------------------------------------------------------- */

test('the result form states the Elo effect before the save, and the log keeps it', function () {
    openSeason(['slug' => 'season-1']);
    [$tournament, $series, $a, $b] = ecDuel();
    ecPlay($series, $a, $b);
    $match = $tournament->matches()->firstOrFail();

    Livewire::actingAs(ecAdmin())->test('tournament-control', ['tournament' => $tournament])
        ->call('edit', $match->id)
        ->assertDontSee('data-test="control-elo-effect"', false)
        ->set('seriesWinner', '0')
        ->assertDontSee('data-test="control-elo-effect"', false)
        ->set('seriesWinner', '1')
        ->assertSee('Elo: reverts +20/−20, applies −20/+20')
        ->set('resultReason', 'The replay shows the other side won')
        ->call('setResult')
        ->assertSet('error', '')
        ->call('$refresh')->assertOk();

    expect(TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->sole()->details['elo'])->toBe(['+20/−20', '−20/+20']);
});

/* ---------- The migration's rollback -------------------------------------------------------------------------- */

/** The column lists of the unique indexes of rating_changes. */
function ecUniqueIndexes(): array
{
    return collect(Schema::getIndexes('rating_changes'))->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])
        ->map(fn (array $index): array => $index['columns'])->sortBy(fn (array $columns): int => count($columns))->values()->all();
}

test('rolling the migration back is refused once a correction exists, and the unique index stays', function () {
    openSeason(['slug' => 'season-1']);
    [, $series, $a, $b] = ecDuel();
    app(RatingService::class)->correct(ecPlay($series, $a, $b), 0.0);
    $migration = require database_path('migrations/2026_09_28_022912_add_reverts_to_rating_changes.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'corrected results')
        ->and(ecUniqueIndexes())->toBe([['rating_id', 'source', 'source_id', 'revision']])
        ->and(RatingChange::query()->withoutGlobalScope(RatingChange::LIVE)->count())->toBe(4);
});

test('without corrections the migration rolls back to the old unique index and forward again', function () {
    $migration = require database_path('migrations/2026_09_28_022912_add_reverts_to_rating_changes.php');

    $migration->down();

    expect(ecUniqueIndexes())->toBe([['rating_id', 'source', 'source_id']])
        ->and(Schema::hasColumns('rating_changes', ['revision']))->toBeFalse();

    $migration->up();

    expect(ecUniqueIndexes())->toBe([['rating_id', 'source', 'source_id', 'revision']])
        ->and(Schema::hasColumns('rating_changes', ['revision', 'reverted_at']))->toBeTrue();
});
