<?php

use App\Enums\HyperMatchStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\Hyperbitcoinization;
use App\Models\Admin;
use App\Models\HyperMatch;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\SeasonChain\LadderEvents;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentEditor;
use App\Support\Tournaments\TournamentGames;
use App\Support\Tournaments\TournamentRuleViolation;
use Livewire\Livewire;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;

/*
| Hyperbitcoinization tournaments (plan "Hyperbitcoinization", P5): free-for-all tables of 3 to 6 whose best move on,
| or a 1v1 knockout; every table is one match the league starts on its server, rated on the season's terms, and its
| places move the bracket. A clan bracket (2v2, 3v3) is not offered yet. No season ladder event is signed for the game.
*/

beforeEach(function () {
    $this->withoutVite();
    HyperOn::play();
});

test('a Hyperbitcoinization tournament is offered as free-for-all tables of 3 to 6 or a 1v1 knockout, nothing else', function () {
    $profile = GameProfile::for(Hyperbitcoinization::SLUG, 'live');
    $estimator = new Estimator;

    expect(TournamentGames::keyOf(Hyperbitcoinization::SLUG, 'live'))->not->toBeNull()
        ->and(TournamentGames::keyOf(Hyperbitcoinization::SLUG, 'correspondence'))->not->toBeNull()
        ->and($profile->isHyper())->toBeTrue()
        ->and($estimator->disabledReason(TournamentFormat::FreeForAll, $profile, 8))->toBeNull()
        ->and($estimator->disabledReason(TournamentFormat::FreeForAll, $profile, 2))->toBe('Needs at least 3 players.')
        ->and($estimator->disabledReason(TournamentFormat::SingleElimination, $profile, 8))->toBeNull();

    foreach ([TournamentFormat::Swiss, TournamentFormat::RoundRobin, TournamentFormat::DoubleElimination, TournamentFormat::TwoStage, TournamentFormat::Leaderboard] as $format) {
        expect($estimator->disabledReason($format, $profile, 8))->toBe('Hyperbitcoinization tournaments are played as free-for-all tables or as a 1v1 knockout.');
    }

    // Tables of 3 to 6, and at least one of a table drops out.
    expect(FormatOptions::fromArray(['heatSize' => 8, 'heatAdvance' => 2], $profile)->heatSize)->toBe(4)
        ->and(FormatOptions::fromArray(['heatSize' => 6, 'heatAdvance' => 6], $profile)->heatAdvance)->toBe(5)
        ->and(FormatOptions::fromArray(['heatSize' => 5, 'heatAdvance' => 2], $profile)->heatSize)->toBe(5);
});

test('free-for-all tables: each table is one rated match of its entries, its places move the best two on, and the final crowns the winner', function () {
    $season = openSeason(ladders: false);
    $tournament = HyperOn::tournament(8, TournamentFormat::FreeForAll, ['heatSize' => 4, 'heatAdvance' => 2]);
    $heats = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 'like', 'h1-%')->orderBy('key')->get();
    $tables = HyperMatch::query()->with('seats')->orderBy('id')->get();

    expect($heats)->toHaveCount(2)
        ->and($tables)->toHaveCount(2)
        ->and($tables->pluck('tournament_match_id')->all())->toBe($heats->pluck('id')->all())
        ->and($tables->every(fn (HyperMatch $table): bool => $table->rated && $table->season === $season->slug && $table->seats->count() === 4 && $table->seats->every(fn ($seat) => ! $seat->bot)))->toBeTrue()
        ->and($tables[0]->seats->pluck('user_id')->all())->toBe($heats[0]->slots()->with('participant')->orderBy('slot')->get()->map(fn ($slot) => $slot->participant->user_id)->all());

    // Table 1: seat 2 wins, seat 0 second; table 2: seat 3 wins, seat 1 second.
    HyperOn::finishTable($tables[0], [2, 0, 1, 3]);
    $heats[0]->refresh();

    expect($heats[0]->result)->toMatchArray(['winner' => 2, 'ranks' => [2, 3, 1, 4], 'by' => 'players', 'hyper' => $tables[0]->ulid])
        ->and($heats[0]->status)->toBe('done')
        ->and(HyperMatch::query()->count())->toBe(2);

    HyperOn::finishTable($tables[1], [3, 1, 0, 2]);
    $final = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 'final')->sole();
    $finalTable = HyperMatch::query()->where('tournament_match_id', $final->id)->with('seats')->sole();
    $advanced = [
        $heats[0]->slots()->where('slot', 2)->value('tournament_participant_id'), $heats[0]->slots()->where('slot', 0)->value('tournament_participant_id'),
        $heats[1]->slots()->where('slot', 3)->value('tournament_participant_id'), $heats[1]->slots()->where('slot', 1)->value('tournament_participant_id'),
    ];

    expect($final->slots()->pluck('tournament_participant_id')->sort()->values()->all())->toBe(collect($advanced)->sort()->values()->all())
        ->and($finalTable->seats)->toHaveCount(4);

    HyperOn::finishTable($finalTable, [1, 0, 2, 3]);
    $champion = $final->refresh()->slots()->where('slot', 1)->value('tournament_participant_id');

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(app(TournamentChampion::class)->of($tournament)?->id)->toBe($champion);
});

test('a 1v1 knockout plays one two-seat match per pairing, and its winner advances', function () {
    $tournament = HyperOn::tournament(4, TournamentFormat::SingleElimination);
    $tables = HyperMatch::query()->with('seats.user')->orderBy('id')->get();

    expect($tables)->toHaveCount(2)
        ->and($tables->every(fn (HyperMatch $table): bool => $table->seats->count() === 2 && ! $table->rated))->toBeTrue();

    $first = TournamentMatch::query()->whereKey($tables[0]->tournament_match_id)->sole();
    HyperOn::finishTable($tables[0], [1, 0]);

    expect($first->refresh()->result)->toMatchArray(['winner' => 1, 'games_won' => [0.0, 1.0], 'by' => 'players'])
        ->and($first->result)->not->toHaveKey('ranks');

    HyperOn::finishTable($tables[1], [0, 1]);
    $final = HyperMatch::query()->where('id', '>', $tables[1]->id)->with('seats')->sole();

    expect($final->seats->pluck('user_id')->sort()->values()->all())->toBe(collect([$tables[0]->seats[1]->user_id, $tables[1]->seats[0]->user_id])->sort()->values()->all())
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running);
});

test('the tournament page sends a player to the live table in a new tab and shows a spectator the live tables', function () {
    $tournament = HyperOn::tournament(4, TournamentFormat::FreeForAll, ['heatSize' => 4, 'heatAdvance' => 1]);
    $table = HyperMatch::query()->with('seats.user')->sole();
    $player = $table->seats[0]->user;

    $link = '/href="'.preg_quote(route('hyper.match', $table), '/').'"\s+target="_blank"/';
    $mine = $this->actingAs($player)->get(route('tournaments.show', $tournament))->assertOk()->assertSee(__('Go to your table'));
    $watch = $this->actingAs(User::factory()->create())->get(route('tournaments.show', $tournament))->assertOk()->assertSee('data-test="now-boards"', false);

    expect((string) $mine->getContent())->toMatch($link)
        ->and((string) $watch->getContent())->toMatch($link);
});

test('a finished table that the league voided since moves nothing', function () {
    $tournament = HyperOn::tournament(4, TournamentFormat::FreeForAll, ['heatSize' => 4, 'heatAdvance' => 1]);
    $table = HyperMatch::query()->sole();
    $match = TournamentMatch::query()->whereKey($table->tournament_match_id)->sole();
    $match->forceFill(['replaced_through' => $table->id])->save();

    HyperOn::finishTable($table, [0, 1, 2, 3]);

    expect($match->refresh()->result)->toBeNull()
        ->and($table->refresh()->status)->toBe(HyperMatchStatus::Finished)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running);
});

test('Block 0 signs no season ladder for Hyperbitcoinization, while it does for chess', function () {
    $season = openSeason(ladders: false);
    $trust = new TestSigner;
    config(['esports.trust.nsec' => $trust->secret]);

    app(LadderEvents::class)->publish($season, LeagueKey::required(), $trust->pubkey);

    expect(NostrEvent::query()->where('kind', 32152)->where('d', 'like', 'hyperbitcoinization/%')->count())->toBe(0)
        ->and(NostrEvent::query()->where(['kind' => 32152, 'd' => 'chess/blitz/'.$season->slug])->count())->toBe(1);
});

test('the tournament chooser offers Hyperbitcoinization with its table size and how many move on', function () {
    config(['esports.hyper.tournaments' => true]);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->assertSee('data-test="game-row-hyperbitcoinization"', false)
        ->set('name', 'Hyper Night')
        ->call('pickGame', 'hyperbitcoinization/live')
        ->set('players', '12')
        ->call('select', 'free-for-all')
        ->call('option', 'heatSize', 6)
        ->call('option', 'heatAdvance', 3)
        ->assertSet('options.heatSize', 6)
        ->assertSet('options.heatAdvance', 3)
        // A table of 9 is none: the default stays.
        ->call('option', 'heatSize', 9)
        ->assertSet('options.heatSize', 4)
        ->call('option', 'heatSize', 5)
        ->call('create')
        ->assertHasNoErrors();

    $tournament = Tournament::query()->sole();

    expect([$tournament->game, $tournament->mode, $tournament->format])->toBe([Hyperbitcoinization::SLUG, 'live', TournamentFormat::FreeForAll])
        ->and($tournament->formatOptions()->heatSize)->toBe(5)
        ->and($tournament->formatOptions()->heatAdvance)->toBe(3);

    // The edit page draws the chooser with the stored game: both settings as radio groups.
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSee('data-test="hyper-heatSize"', false)
        ->assertSee('data-test="hyper-heatAdvance"', false);
});

test('Hyperbitcoinization tournaments are offered only while ESPORTS_HYPER_TOURNAMENTS is on (off by default); one that exists keeps its game', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $key = TournamentGames::keyOf(Hyperbitcoinization::SLUG, 'live');

    expect(config('esports.hyper.tournaments'))->toBeFalse()
        ->and(array_column(TournamentGames::grouped(), 'slug'))->not->toContain(Hyperbitcoinization::SLUG)
        ->and(TournamentGames::offers($key))->toBeFalse()
        // The game it has now stays: an existing tournament's chooser still shows it.
        ->and(array_column(TournamentGames::grouped($key), 'slug'))->toContain(Hyperbitcoinization::SLUG);

    Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->assertDontSee('data-test="game-row-hyperbitcoinization"', false)
        ->call('pickGame', $key)
        ->assertSet('game', 'blitz')
        ->set('game', $key)
        ->set('name', 'Sneaky Hyper')
        ->call('create')
        ->assertHasErrors('game');

    expect(Tournament::query()->where('game', Hyperbitcoinization::SLUG)->exists())->toBeFalse();

    // An existing tournament is never turned into one either.
    $chess = Tournament::factory()->create(['status' => TournamentStatus::Draft]);
    expect(fn () => app(TournamentEditor::class)->update($chess, $chess->creator, ['game' => Hyperbitcoinization::SLUG, 'mode' => 'live']))
        ->toThrow(TournamentRuleViolation::class);

    config(['esports.hyper.tournaments' => true]);

    expect(array_column(TournamentGames::grouped(), 'slug'))->toContain(Hyperbitcoinization::SLUG)
        ->and(TournamentGames::offers($key))->toBeTrue();
    Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->assertSee('data-test="game-row-hyperbitcoinization"', false)
        ->call('pickGame', $key)
        ->assertSet('game', $key);
});
