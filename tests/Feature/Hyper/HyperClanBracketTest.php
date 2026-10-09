<?php

use App\Enums\HyperMatchStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Hyperbitcoinization;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\Lineup;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Clans\ClanPride;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperTournamentTeams;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentEditor;
use App\Support\Tournaments\TournamentMatchMaker;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use Livewire\Livewire;
use Tests\Support\HyperOn;

/*
| Hyperbitcoinization clan brackets (plan "Hyperbitcoinization", P5b): clans sign up as teams of 2 or 3 (plus up to
| two substitutes), a captain names who plays before each match, the league starts one rated team table seated
| A B A B with both clans in `team_clans`, and the team win moves the bracket. A team whose every player forfeited
| loses; a single forfeiting member is played on by a bot.
*/

beforeEach(function () {
    $this->withoutVite();
    HyperOn::play();
});

/**
 * A clan of `$count` players; the first is its owner.
 *
 * @return array{0: Clan, 1: list<User>}
 */
function hyperClan(int $count): array
{
    $players = User::factory()->count($count)->create()->values()->all();
    $clan = Clan::factory()->create(['owner_id' => $players[0]->id]);

    foreach ($players as $player) {
        HyperOn::inClan($player, $clan);
    }

    return [$clan, $players];
}

/**
 * A running clan bracket (knockout) of the given clans, each entered with all its players in order, bracket stored
 * and synced.
 *
 * @param  list<array{0: Clan, 1: list<User>}>  $clans
 */
function hyperClanBracket(array $clans, int $teamSize = 2, string $mode = 'live'): Tournament
{
    $profile = GameProfile::for(Hyperbitcoinization::SLUG, $mode);
    $tournament = Tournament::factory()->create([
        'game' => Hyperbitcoinization::SLUG, 'mode' => $mode, 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::fromArray(['teamSize' => $teamSize], $profile)->toArray(),
        'capacity' => count($clans), 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'hyper-clans-'.fake()->unique()->numberBetween(1, 1_000_000), 'ladder_address' => null,
    ]);

    foreach ($clans as $index => [$clan, $players]) {
        TournamentParticipant::query()->create([
            'tournament_id' => $tournament->id, 'lineup_id' => HyperTournamentTeams::lineup($clan, $mode)->id, 'name' => $clan->name,
            'rating' => 1500 - 10 * $index, 'members' => array_map(fn (User $user): int => $user->id, $players),
        ]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

test('a clan bracket is offered as a 2v2 or 3v3 knockout only, and its clans enter as teams', function () {
    $live = GameProfile::for(Hyperbitcoinization::SLUG, 'live');
    $estimator = new Estimator;

    expect(FormatOptions::fromArray(['teamSize' => 3], $live)->teamSize)->toBe(3)
        ->and(FormatOptions::fromArray(['teamSize' => 4], $live)->teamSize)->toBe(1)
        // Only Hyperbitcoinization reads it: every other game keeps 1.
        ->and(FormatOptions::fromArray(['teamSize' => 2], GameProfile::for('chess', 'blitz'))->teamSize)->toBe(1)
        ->and($estimator->disabledReason(TournamentFormat::SingleElimination, $live->withTeamSize(2), 8))->toBeNull()
        ->and($estimator->disabledReason(TournamentFormat::FreeForAll, $live->withTeamSize(2), 8))->toBe('Clan tournaments of Hyperbitcoinization are played as a knockout: two clans per match.')
        ->and($estimator->disabledReason(TournamentFormat::FreeForAll, $live, 8))->toBeNull();

    $tournament = Tournament::factory()->create(['game' => Hyperbitcoinization::SLUG, 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination,
        'options' => ['teamSize' => 3]]);

    expect($tournament->teamSize())->toBe(3)
        ->and($tournament->profile()->entersTeams())->toBeTrue()
        ->and($tournament->maxLineupSize())->toBe(5)
        ->and(HyperTournamentTeams::isClanBracket($tournament))->toBeTrue();
});

test('the chooser offers players, clan 2v2 and clan 3v3, and picking a clan size picks the knockout', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->set('name', 'Clan Night')
        ->call('pickGame', 'hyperbitcoinization/live')
        ->set('players', '8')
        ->call('select', 'free-for-all')
        ->call('option', 'teamSize', 3)
        ->assertSet('options.teamSize', 3)
        ->assertSet('selected', 'single-elimination')
        ->call('create')
        ->assertHasNoErrors();

    $tournament = Tournament::query()->sole();

    expect([$tournament->format, $tournament->teamSize()])->toBe([TournamentFormat::SingleElimination, 3]);

    // The edit page draws the chooser with the stored team size as a radio group.
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSee('data-test="hyper-teamSize"', false)
        ->assertSeeHtml('aria-checked="true" wire:click="option(\'teamSize\', 3)"');
});

test('a clan captain signs the clan up with a substitute; nobody enters solo, and the team size stays once anyone entered', function () {
    openSeason(ladders: false);
    [$captain, $signer] = keyedPlayer();
    $clan = Clan::factory()->create(['owner_id' => $captain->id]);
    HyperOn::inClan($captain, $clan);
    $mates = User::factory()->count(3)->create()->each(fn (User $user) => HyperOn::inClan($user, $clan));
    $tournament = openTournament(['game' => Hyperbitcoinization::SLUG, 'mode' => 'live', 'format' => TournamentFormat::SingleElimination,
        'options' => ['teamSize' => 2], 'capacity' => 8]);

    // The sign-up page sets up the clan's entry (a mirror of the clan, never published) for its captain.
    $this->actingAs($captain)->get(route('tournaments.signup', $tournament))->assertOk()->assertSee(__('Bring your clan'));
    $lineup = Lineup::query()->where(['clan_id' => $clan->id, 'game' => Hyperbitcoinization::SLUG, 'mode' => 'live'])->sole();

    expect($lineup->event_id)->toBeNull()
        ->and($lineup->seats()->count())->toBe(4)
        ->and($lineup->isActingCaptain($captain))->toBeTrue()
        ->and($lineup->isActingCaptain($mates[0]))->toBeFalse();

    $signup = lineupSignup($tournament, $lineup->load('clan', 'seats.user'), $captain, $signer, [$captain->id, $mates[0]->id, $mates[1]->id]);

    expect($signup->members)->toBe([$captain->id, $mates[0]->id, $mates[1]->id])
        ->and($signup->name)->toBe($clan->name);

    [$loner, $lonerSigner] = keyedPlayer();
    expect(fn () => soloSignup($tournament, $loner, $lonerSigner))->toThrow(TournamentRuleViolation::class, __('Clans enter this tournament as teams. Ask your clan\'s captain to sign the clan up.'));

    $this->actingAs($loner)->get(route('tournaments.signup', $tournament))->assertOk()
        ->assertSee('data-test="clans-only"', false)->assertDontSee('data-test="enter-solo"', false);

    expect(fn () => app(TournamentEditor::class)->update($tournament, $tournament->creator, ['options' => [...$tournament->options, 'teamSize' => 3]]))
        ->toThrow(TournamentRuleViolation::class, __('Players or clans have signed up already. The team size stays as it is.'));
});

test('the table waits for the captain to name the team, then seats both clans A B A B in a rated team match', function () {
    $season = openSeason(ladders: false);
    [$red, $reds] = hyperClan(3);
    [$blue, $blues] = hyperClan(2);
    $tournament = hyperClanBracket([[$red, $reds], [$blue, $blues]]);
    $final = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->sole();
    $redSlot = (int) $final->slots()->whereHas('participant', fn ($query) => $query->where('name', $red->name))->value('slot');

    // Red entered three players for a 2v2: no table until its captain names two. Blue entered exactly two.
    expect(HyperMatch::query()->count())->toBe(0)
        ->and($final->refresh()->lineups['since'] ?? null)->not->toBeNull();

    // Only an acting captain names, exactly two, and only entered players.
    $teams = app(HyperTournamentTeams::class);
    expect(fn () => $teams->name($tournament, $final, $reds[1], [$reds[1]->id, $reds[2]->id]))->toThrow(TournamentRuleViolation::class, __('Only a captain of the clan can name its players.'))
        ->and(fn () => $teams->name($tournament, $final, $reds[0], [$reds[0]->id]))->toThrow(TournamentRuleViolation::class, __('Pick exactly :count players.', ['count' => 2]))
        ->and(fn () => $teams->name($tournament, $final, $reds[0], [$reds[0]->id, $blues[0]->id]))->toThrow(TournamentRuleViolation::class);

    $teams->name($tournament, $final, $reds[0], [$reds[2]->id, $reds[0]->id]);
    $table = HyperMatch::query()->with('seats')->sole();
    $red0 = $redSlot === 0;
    $teamOf = fn (int $seat): int => $seat % 2;
    $expected = $red0 ? [$reds[2]->id, $blues[0]->id, $reds[0]->id, $blues[1]->id] : [$blues[0]->id, $reds[2]->id, $blues[1]->id, $reds[0]->id];

    expect($table->tournament_match_id)->toBe($final->id)
        ->and($table->team_clans)->toBe($red0 ? [$red->id, $blue->id] : [$blue->id, $red->id])
        ->and($table->seats->sortBy('seat')->pluck('user_id')->all())->toBe($expected)
        ->and($table->seats->every(fn (HyperSeat $seat): bool => $seat->team === $teamOf($seat->seat) && ! $seat->bot))->toBeTrue()
        ->and($table->mode)->toBe('live')
        ->and($table->rated)->toBeTrue()
        ->and($table->season)->toBe($season->slug)
        ->and($final->refresh()->lineups['sides'][$redSlot]['by'])->toBe($reds[0]->id);

    // Named once, the match takes no other lineup.
    expect(fn () => $teams->name($tournament, $final, $reds[0], [$reds[0]->id, $reds[1]->id]))->toThrow(TournamentRuleViolation::class, __('This match takes no lineup any more.'));
});

test('a clan that does not name its team in time plays with the players it entered first', function () {
    [$red, $reds] = hyperClan(4);
    [$blue, $blues] = hyperClan(3);
    $tournament = hyperClanBracket([[$red, $reds], [$blue, $blues]], mode: 'correspondence');

    expect(HyperMatch::query()->count())->toBe(0);

    $this->travel((int) config('esports.hyper.tournament_lineup_minutes.correspondence') - 1)->minutes();
    app(TournamentMatchMaker::class)->startReady($tournament);
    expect(HyperMatch::query()->count())->toBe(0);

    $this->travel(2)->minutes();
    app(TournamentMatchMaker::class)->startReady($tournament->refresh());
    $table = HyperMatch::query()->with('seats')->sole();

    expect($table->mode)->toBe('correspondence')
        ->and($table->seats->pluck('user_id')->sort()->values()->all())->toBe(collect([$reds[0]->id, $reds[1]->id, $blues[0]->id, $blues[1]->id])->sort()->values()->all());
});

test('the team win is the bracket winner and the champion, and a clan that won the tournament is proud of it', function () {
    [$red, $reds] = hyperClan(2);
    [$blue, $blues] = hyperClan(2);
    $tournament = hyperClanBracket([[$red, $reds], [$blue, $blues]]);
    $table = HyperMatch::query()->with('seats.user')->sole();
    $final = TournamentMatch::query()->whereKey($table->tournament_match_id)->sole();
    $winnerClan = $table->team_clans[0];

    // Team 0 (seats 0, 2) wins: seat 0 plays the last card.
    $match = HyperOn::teamEndgame($table);
    $match->seats->firstWhere('seat', 3)->forceFill(['place' => 4])->save();
    HyperOn::finish($match, $match->load('seats.user')->seats->firstWhere('seat', 0)->user);
    $final->refresh();

    expect($final->result)->toMatchArray(['winner' => 0, 'games_won' => [1.0, 0.0], 'forfeit' => false, 'by' => 'players'])
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(app(TournamentChampion::class)->of($tournament)?->lineup?->clan_id)->toBe($winnerClan);

    $moments = collect(app(ClanPride::class)->all()[$winnerClan] ?? []);

    expect($moments->firstWhere('type', 'tournament'))->toMatchArray(['place' => 1, 'tournament' => $tournament->name])
        ->and($moments->firstWhere('type', 'hyper'))->not->toBeNull();
});

test('one forfeiting member is played on by a bot and the team can still win; a team whose every player forfeited loses', function () {
    openSeason(ladders: false);
    [$red, $reds] = hyperClan(2);
    [$blue, $blues] = hyperClan(2);
    $tournament = hyperClanBracket([[$red, $reds], [$blue, $blues]]);
    $table = HyperMatch::query()->with('seats.user')->sole();
    $final = TournamentMatch::query()->whereKey($table->tournament_match_id)->sole();
    $seat2 = $table->seats->firstWhere('seat', 2)->user;
    $seat0 = $table->seats->firstWhere('seat', 0)->user;

    // Seat 2 leaves: a forfeit, a bot plays it on, and its team (0) wins anyway.
    app(HyperMatches::class)->leave($table, $seat2);
    $match = HyperOn::teamEndgame($table->refresh());
    $match->seats->firstWhere('seat', 3)->forceFill(['place' => 4])->save();
    $match = HyperOn::finish($match, $seat0);

    expect($match->seats->firstWhere('seat', 2)->takeover)->toBe(HyperSeat::TAKEOVER_FORFEIT)
        ->and($final->refresh()->result)->toMatchArray(['winner' => 0, 'forfeit' => false]);
});

test('a team whose every player forfeited loses the bracket match, even when its bots win the table', function () {
    openSeason(ladders: false);
    [$red, $reds] = hyperClan(2);
    [$blue, $blues] = hyperClan(2);
    $tournament = hyperClanBracket([[$red, $reds], [$blue, $blues]]);
    $table = HyperMatch::query()->with('seats.user')->sole();
    $final = TournamentMatch::query()->whereKey($table->tournament_match_id)->sole();
    $seat0 = $table->seats->firstWhere('seat', 0)->user;

    // Team 0 forfeits as a whole: seat 2 leaves, and seat 0 is marked as forfeited too while it still plays the winning
    // card, standing in for the bot that would play the seat on (a bot's winning turn is not scriptable here).
    app(HyperMatches::class)->leave($table, $table->seats->firstWhere('seat', 2)->user);
    $match = HyperOn::teamEndgame($table->refresh());
    $match->seats->firstWhere('seat', 0)->forceFill(['takeover' => HyperSeat::TAKEOVER_FORFEIT, 'left_at' => now()])->save();
    $match->seats->firstWhere('seat', 3)->forceFill(['place' => 4])->save();
    $match = HyperOn::finish($match->refresh(), $seat0);

    expect($match->status)->toBe(HyperMatchStatus::Finished)
        ->and($final->refresh()->result)->toMatchArray(['winner' => 1, 'games_won' => [0.0, 1.0], 'forfeit' => true]);

    // The clan pride credits the team that won by place, never the forfeited one.
    $moments = app(ClanPride::class)->all();
    expect(collect($moments[$match->team_clans[0]] ?? [])->firstWhere('type', 'hyper'))->toBeNull()
        ->and(collect($moments[$match->team_clans[1]] ?? [])->firstWhere('type', 'hyper'))->not->toBeNull();
});

test('the tournament page asks the captain for the team, opens the table in a new tab, and sends a substitute to watch', function () {
    [$red, $reds] = hyperClan(3);
    [$blue, $blues] = hyperClan(2);
    $tournament = hyperClanBracket([[$red, $reds], [$blue, $blues]]);
    $final = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->sole();

    $this->actingAs($reds[0])->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee(__('Name your team'))->assertSee('data-test="now-lineup"', false)->assertSee('data-test="lineup-confirm"', false);
    $this->actingAs($reds[1])->get(route('tournaments.show', $tournament))->assertOk()->assertSee(__('Your captain names the team'));
    $this->actingAs($blues[0])->get(route('tournaments.show', $tournament))->assertOk()->assertSee(__('Your table starts soon'));

    Livewire::actingAs($reds[0])->test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('nameHyperLineup', $final->id, [$reds[0]->id, $reds[1]->id])
        ->assertSet('cupError', '');

    $table = HyperMatch::query()->sole();
    $link = '/href="'.preg_quote(route('hyper.match', $table), '/').'"\s+target="_blank"/';
    $player = $this->actingAs($reds[1])->get(route('tournaments.show', $tournament))->assertOk()->assertSee(__('Go to your table'));
    $sub = $this->actingAs($reds[2])->get(route('tournaments.show', $tournament))->assertOk()->assertSee(__('Watch your clan'));

    expect((string) $player->getContent())->toMatch($link)
        ->and((string) $sub->getContent())->toMatch($link);
});
