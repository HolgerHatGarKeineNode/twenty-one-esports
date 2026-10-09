<?php

use App\Events\HyperLobbyUpdated;
use App\Events\HyperMatchUpdated;
use App\Events\HyperTableStarted;
use App\Models\Clan;
use App\Models\HyperMatch;
use App\Models\HyperTable;
use App\Models\User;
use App\Support\Clans\ClanPride;
use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperLobby;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperRuleViolation;
use App\Support\Hyper\HyperStats;
use App\Support\Hyper\HyperTeamChat;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\HyperOn;

/*
| Team matches of Hyperbitcoinization (plan "Hyperbitcoinization", P4): clan tables in the lobby (two sides
| seated alternately, a clan per side, a clan linked to a meetup plays as that meetup), the team match they
| start, the team's win, and the team chat's members, which only a player of the team gets.
*/

beforeEach(function () {
    $this->withoutVite();
    HyperOn::play();
    Event::fake([HyperLobbyUpdated::class, HyperTableStarted::class, HyperMatchUpdated::class]);
});

/**
 * Two clans with two players each, plus a third clan's player and a player without clan.
 *
 * @return array{red: Clan, blue: Clan, anna: User, carl: User, bert: User, dora: User, olga: User, nobody: User}
 */
function hyperClans(): array
{
    [$anna, $carl, $bert, $dora, $olga, $nobody] = User::factory()->count(6)->create();
    $red = Clan::factory()->create(['owner_id' => $anna->id, 'name' => 'Red Pill', 'clantag' => 'RED']);
    $blue = Clan::factory()->create(['owner_id' => $bert->id, 'name' => 'Blue Clan', 'clantag' => 'BLU', 'meetup_name' => 'Einundzwanzig Kassel', 'meetup_city' => 'Kassel']);
    $green = Clan::factory()->create(['owner_id' => $olga->id]);
    HyperOn::inClan($carl, $red);
    HyperOn::inClan($dora, $blue);

    return compact('red', 'blue', 'anna', 'carl', 'bert', 'dora', 'olga', 'nobody') + ['green' => $green];
}

test('a clan table seats each clan on its own side, alternately, and refuses everybody else', function () {
    ['red' => $red, 'blue' => $blue, 'anna' => $anna, 'carl' => $carl, 'bert' => $bert, 'dora' => $dora, 'olga' => $olga, 'nobody' => $nobody] = hyperClans();
    $lobby = app(HyperLobby::class);

    expect(fn () => $lobby->open($nobody, 4, HyperMatch::LIVE, 0, clans: true))->toThrow(HyperRuleViolation::class, 'A clan table needs a clan.')
        ->and(fn () => $lobby->open($anna, 5, HyperMatch::LIVE, 0, clans: true))->toThrow(HyperRuleViolation::class, 'A clan table has 4 or 6 seats.');

    $table = $lobby->open($anna, 6, HyperMatch::LIVE, 0, clans: true);
    expect($table->team_clans)->toBe([$red->id, null]);

    $table = $lobby->join($table, $carl);
    $table = $lobby->join($table, $bert);
    expect($table->team_clans)->toBe([$red->id, $blue->id])
        ->and($table->takenSeats->pluck('user_id', 'seat')->all())->toBe([0 => $anna->id, 1 => $bert->id, 2 => $carl->id])
        ->and(hyperRefusal(fn () => $lobby->join($table, $olga)))->toBe('not_your_clan')
        ->and(hyperRefusal(fn () => $lobby->join($table, $nobody)))->toBe('no_clan');

    // The last player of side 1 gets up: any other clan may take it now.
    $table = $lobby->leave($table, $bert);
    expect($table->team_clans)->toBe([$red->id, null]);
    $table = $lobby->join($table, $olga);
    expect($table->takenSeats->firstWhere('user_id', $olga->id)->seat)->toBe(1)
        ->and(hyperRefusal(fn () => $lobby->join($table, $dora)))->toBe('not_your_clan');
});

test('a full side refuses its clan\'s next player', function () {
    ['red' => $red, 'anna' => $anna, 'carl' => $carl] = hyperClans();
    $third = HyperOn::inClan(User::factory()->create(), $red);
    $lobby = app(HyperLobby::class);

    $table = $lobby->join($lobby->open($anna, 4, HyperMatch::LIVE, 0, clans: true), $carl);

    expect(hyperRefusal(fn () => $lobby->join($table, $third)))->toBe('side_full');
});

test('bots fill both sides, and the team match plays two teams with the clans of the table', function () {
    ['red' => $red, 'blue' => $blue, 'anna' => $anna, 'bert' => $bert] = hyperClans();
    $lobby = app(HyperLobby::class);

    $table = $lobby->join($lobby->open($anna, 4, HyperMatch::LIVE, 20, clans: true), $bert);
    $table = $lobby->fillBots($table, $anna);
    $match = $table->match()->with('seats')->firstOrFail();
    $game = HyperGame::fromArray($match->state);

    expect($match->team_clans)->toBe([$red->id, $blue->id])
        ->and($match->seats->pluck('team')->all())->toBe([0, 1, 0, 1])
        ->and($match->seats->pluck('bot')->all())->toBe([false, false, true, true])
        ->and($game->isTeamGame())->toBeTrue()
        ->and($game->allied(0, 2))->toBeTrue()
        ->and($game->allied(1, 2))->toBeFalse();

    $snapshot = app(HyperMatches::class)->snapshot($match, $anna);

    // The blue clan is linked to a meetup: it plays as the meetup, with the clan's tag.
    expect($snapshot['teams'])->toHaveCount(2)
        ->and($snapshot['teams'][0])->toMatchArray(['side' => 0, 'clan_id' => $red->id, 'name' => 'Red Pill', 'meetup' => false, 'tag' => 'RED'])
        ->and($snapshot['teams'][1])->toMatchArray(['side' => 1, 'clan_id' => $blue->id, 'name' => 'Einundzwanzig Kassel', 'meetup' => true, 'city' => 'Kassel', 'tag' => 'BLU']);
});

test('the lobby page opens a clan table and shows both sides with their clan and meetup', function () {
    ['anna' => $anna, 'bert' => $bert] = hyperClans();

    $this->actingAs($anna);
    $component = Livewire::test('hyper-lobby')
        ->call('$set', 'clans', true)
        ->assertSet('seats', 4)
        ->call('openTable')
        ->assertSeeHtml('data-test="hyper-lobby-sides"')
        ->assertSee('Red Pill')
        ->assertSee('Open for a clan');

    $this->actingAs($bert);
    $table = HyperTable::query()->firstOrFail();
    Livewire::test('hyper-lobby')->call('join', $table->ulid)
        ->assertSee('Einundzwanzig Kassel')
        ->assertSee('Kassel')
        ->assertDontSee('Open for a clan');

    expect($component)->not->toBeNull();
});

test('a team wins together: every seat of the winning team takes place 1, and the end names the team', function () {
    ['red' => $red, 'blue' => $blue, 'anna' => $anna, 'carl' => $carl, 'bert' => $bert] = hyperClans();
    $match = HyperOn::teamEndgame(HyperOn::teams($red, $blue, $anna, $bert, $carl));

    $after = app(HyperMatches::class)->act($match, $anna, ['type' => 'play_card', 'card' => 'attack51', 'target' => 'mexiko'])['match'];
    $after->load('seats');
    $snapshot = app(HyperMatches::class)->snapshot($after, $bert);

    expect($after->winner_seat)->toBe(0)
        ->and($after->seats->firstWhere('seat', 0)->place)->toBe(1)
        ->and($after->seats->firstWhere('seat', 2)->place)->toBe(1)
        ->and($after->seats->firstWhere('seat', 1)->place)->toBeGreaterThan(1)
        ->and($snapshot['winner_team'])->toBe(0);
});

test('the team chat names its members only to a player of that team', function () {
    ['red' => $red, 'blue' => $blue, 'anna' => $anna, 'carl' => $carl, 'bert' => $bert, 'dora' => $dora, 'nobody' => $spectator] = hyperClans();
    $match = HyperOn::teams($red, $blue, $anna, $bert, $carl, $dora);
    $url = route('hyper.team', $match);

    $answer = $this->actingAs($anna)->getJson($url)->assertOk()->json();

    expect(array_column($answer['members'], 'pubkey'))->toBe([$anna->pubkey, $carl->pubkey])
        ->and(array_column($answer['members'], 'name'))->toBe([$anna->displayName(), $carl->displayName()])
        ->and($answer['match'])->toBe(HyperTeamChat::tag($match));

    $this->actingAs($bert)->getJson($url)->assertOk()->assertJsonPath('members.0.pubkey', $bert->pubkey)->assertJsonPath('members.1.pubkey', $dora->pubkey)->assertJsonCount(2, 'members');

    // An opponent's answer is about their own team; a spectator gets nothing.
    $this->actingAs($spectator)->getJson($url)->assertForbidden()->assertJsonPath('reason', 'not_teammate')->assertJsonMissingPath('members');
    auth()->logout();
    $this->getJson($url)->assertUnauthorized();

    // A player who left the match reads no more of it; a match without teams has no team chat.
    app(HyperMatches::class)->leave($match, $carl);
    $this->actingAs($carl)->getJson($url)->assertForbidden();
    $this->actingAs($anna)->getJson($url)->assertOk()->assertJsonCount(1, 'members');
    $this->actingAs($anna)->getJson(route('hyper.team', HyperOn::versus($anna, $bert)))->assertNotFound();
});

test('the match page and its snapshot never show a team\'s Nostr keys to the other team or a spectator', function () {
    ['red' => $red, 'blue' => $blue, 'anna' => $anna, 'carl' => $carl, 'bert' => $bert, 'dora' => $dora, 'nobody' => $spectator] = hyperClans();
    $match = HyperOn::teams($red, $blue, $anna, $bert, $carl, $dora);
    $keys = fn (?User $viewer): array => array_column(app(HyperMatches::class)->snapshot($match->fresh(), $viewer)['seats'], 'pubkey');

    expect($keys($anna))->toBe([$anna->pubkey, null, $carl->pubkey, null])
        ->and($keys($bert))->toBe([null, $bert->pubkey, null, $dora->pubkey])
        ->and($keys($spectator))->toBe([null, null, null, null])
        ->and($keys(null))->toBe([null, null, null, null]);
    // A hidden key still leaves a face: the placeholder avatar, not nothing (which the afterplay drew as a bot).
    $avatars = array_column(app(HyperMatches::class)->snapshot($match->fresh(), $anna)['seats'], 'avatar');
    expect($avatars[1])->not->toBeNull()->not->toContain($bert->pubkey)
        ->and($avatars[3])->not->toBeNull()->not->toContain($dora->pubkey);

    $page = $this->actingAs($bert)->get(route('hyper.match', $match))->assertOk()->getContent();

    expect($page)->not->toContain($anna->pubkey)->not->toContain($carl->pubkey)
        ->and($page)->toContain(str_replace('/', '\\/', route('hyper.team', $match, false)));

    $guest = $this->actingAs($spectator)->get(route('hyper.match', $match))->getContent();
    expect($guest)->not->toContain($anna->pubkey)->not->toContain($bert->pubkey)->not->toContain('"teamChat":{');
});

test('a finished team match: team statistics with MVPs and clan moments, and the winning clan\'s pride', function () {
    ['red' => $red, 'blue' => $blue, 'anna' => $anna] = hyperClans();
    config(['esports.hyper.bot_round_cap' => 6]);
    $matches = app(HyperMatches::class);
    $match = HyperOn::teams($red, $blue, $anna, null, seed: 11);
    $matches->act($match, $anna, ['type' => 'end_turn']);
    $matches->leave($match->refresh(), $anna);
    $match->refresh()->load('seats');

    expect($match->status->value)->toBe('finished');

    $stats = HyperStats::compute($match);
    $winnerTeam = $match->seats->firstWhere('seat', $match->winner_seat)->team;
    // Conquests counted independently from the stored events, per team (a lone-unit 51% attack conquers without an event).
    $bySeat = [0, 0, 0, 0];
    foreach ($match->actions as $action) {
        foreach ($action->events as $event) {
            if ($event['type'] === 'territory_conquered') {
                $bySeat[$event['seat']]++;
            }
        }
    }
    $conquests = [0 => $bySeat[0] + $bySeat[2], 1 => $bySeat[1] + $bySeat[3]];

    expect($stats['teams'])->toHaveCount(2)
        ->and(array_column($stats['teams'], 'seats'))->toBe([[0, 2], [1, 3]])
        ->and(array_column($stats['teams'], 'won'))->toBe([$winnerTeam === 0, $winnerTeam === 1])
        ->and($stats['teams'][0]['conquests'])->toBeGreaterThanOrEqual($conquests[0])
        ->and($stats['teams'][1]['conquests'])->toBeGreaterThanOrEqual($conquests[1])
        ->and($stats['moments'][0]['key'])->toBe('team_win')
        ->and($stats['moments'][0]['team'])->toBe($winnerTeam)
        ->and($stats['moments'][0]['mvp']['seat'])->toBeIn($stats['teams'][$winnerTeam]['seats'])
        ->and(array_column($stats['moments'], 'key'))->not->toContain('first_bank', 'zone', 'knockout');

    // The MVP has the most conquests of the winning team, counted from the events.
    $mvp = $stats['teams'][$winnerTeam]['mvp'];
    $team = $stats['teams'][$winnerTeam]['seats'];
    expect($bySeat[$mvp['seat']])->toBe(max(array_map(fn (int $seat): int => $bySeat[$seat], $team)));

    $pride = app(ClanPride::class)->read();
    $winnerClan = $winnerTeam === 0 ? $red : $blue;
    $loserClan = $winnerTeam === 0 ? $blue : $red;
    $moment = collect($pride[$winnerClan->id] ?? [])->firstWhere('type', 'hyper');

    expect($moment)->toMatchArray(['opponent_tag' => $loserClan->clantag, 'size' => '2v2'])
        ->and(collect($pride[$loserClan->id] ?? [])->firstWhere('type', 'hyper'))->toBeNull();

    // Switched off, the game adds no pride.
    config(['esports.hyper.enabled' => false]);
    expect(collect(app(ClanPride::class)->read()[$winnerClan->id] ?? [])->firstWhere('type', 'hyper'))->toBeNull();
});
