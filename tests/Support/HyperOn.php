<?php

namespace Tests\Support;

use App\Enums\ClanRole;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\HyperMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Hyper\HyperCups;
use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperMap;
use App\Support\Hyper\HyperMatches;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Route;

/**
 * Hyperbitcoinization switched on for one test (plan "Hyperbitcoinization", P2): the switch on, the registry
 * rebuilt from the config as at boot, and routes/hyper.php routed as routes/web.php does when the switch is
 * on at boot (the test app boots with it off). Plus the matches the tests and the browser tests start from.
 */
final class HyperOn
{
    public static function play(): void
    {
        config(['esports.hyper.enabled' => true]);
        app()->forgetInstance(GameRegistry::class);

        if (! Route::has('hyper.match')) {
            Route::middleware('web')->group(base_path('routes/hyper.php'));
        }

        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    /**
     * A live match of `$a` (seat 0, bitcoiner) against `$b` (seat 1, fed) and `$bots` bots after them.
     */
    public static function versus(User $a, User $b, int $bots = 0, ?int $seed = 7): HyperMatch
    {
        return app(HyperMatches::class)->create([
            ['user' => $a, 'faction' => 'bitcoiner'],
            ['user' => $b, 'faction' => 'fed'],
            ...array_fill(0, $bots, ['bot' => true]),
        ], seed: $seed, creator: $a);
    }

    /**
     * A match one action from its end: `$a` (seat 0) holds every territory but Mexico, which `$b` (seat 1)
     * holds with one pleb; `$a` is in the attack phase with 6 units in New York next to it and a
     * 51%-Attacke in hand (`play_card attack51 mexiko` wins without dice; an attack from `ny` almost
     * surely). The state is written over a fresh match's, so this match's action log does not replay it.
     */
    public static function endgame(User $a, User $b): HyperMatch
    {
        $match = self::versus($a, $b);
        $state = HyperGame::fromArray($match->state)->toArray();

        foreach (HyperMap::IDS as $id) {
            $state['territories'][$id] = ['owner' => 0, 'pleb' => 2, 'maxi' => 0, 'asic' => 0, 'shield' => null];
        }

        $state['territories']['mexiko'] = ['owner' => 1, 'pleb' => 1, 'maxi' => 0, 'asic' => 0, 'shield' => null];
        $state['territories']['ny']['pleb'] = 6;
        $state['seat'] = 0;
        $state['phase'] = 'attack';
        $state['placed'] = [];
        $state['seats'][0] = [...$state['seats'][0], 'hand' => ['attack51'], 'free_plebs' => 0];
        $state['seats'][1] = [...$state['seats'][1], 'hand' => ['pizza']];
        $match->forceFill(['state' => HyperGame::fromArray($state)->toArray(), 'current_seat' => 0])->save();

        return $match->refresh();
    }

    /**
     * Any match one card from its end (P5): seat `$winner` (a player) holds every territory but Mexico, which seat
     * `$runnerUp` holds with one pleb, in its attack phase with a 51%-Attacke in hand (`play_card attack51 mexiko`
     * ends it, see finish()); every other seat is out already, with the place `$out` gives it (seat => place), as
     * the knock-outs before would have left them.
     *
     * @param  array<int, int>  $out
     */
    public static function ending(HyperMatch $match, int $winner, int $runnerUp, array $out = []): HyperMatch
    {
        $state = HyperGame::fromArray($match->state)->toArray();

        foreach (HyperMap::IDS as $id) {
            $state['territories'][$id] = ['owner' => $winner, 'pleb' => 2, 'maxi' => 0, 'asic' => 0, 'shield' => null];
        }

        $state['territories']['mexiko'] = ['owner' => $runnerUp, 'pleb' => 1, 'maxi' => 0, 'asic' => 0, 'shield' => null];
        $state['territories']['ny']['pleb'] = 6;
        $state['seat'] = $winner;
        $state['phase'] = 'attack';
        $state['placed'] = [];
        $state['pending_move'] = null;
        $state['seats'][$winner] = [...$state['seats'][$winner], 'hand' => ['attack51'], 'free_plebs' => 0];

        foreach ($out as $seat => $place) {
            $state['seats'][$seat] = [...$state['seats'][$seat], 'out' => true];
            $match->seats->firstWhere('seat', $seat)?->forceFill(['place' => $place])->save();
        }

        $match->forceFill(['state' => HyperGame::fromArray($state)->toArray(), 'current_seat' => $winner])->save();

        return $match->refresh();
    }

    /** Ends a match prepared by ending(): its winner plays the 51%-Attacke on Mexico. */
    public static function finish(HyperMatch $match, User $winner): HyperMatch
    {
        app(HyperMatches::class)->act($match, $winner, ['type' => 'play_card', 'card' => 'attack51', 'target' => 'mexiko']);

        return $match->refresh()->load('seats');
    }

    /**
     * A running players-mode Hyperbitcoinization tournament of `$n` players (P5), bracket stored and synced, so its
     * first tables are started. `hyper_cup` in `$options` makes it a weekend cup (HyperCups).
     *
     * @param  array<string, mixed>  $options
     */
    public static function tournament(int $n, TournamentFormat $format, array $options = [], string $mode = 'live'): Tournament
    {
        $profile = GameProfile::for(Hyperbitcoinization::SLUG, $mode);
        $tournament = Tournament::factory()->create([
            'game' => Hyperbitcoinization::SLUG, 'mode' => $mode, 'format' => $format,
            'options' => [...FormatOptions::fromArray($options, $profile)->toArray(), ...array_intersect_key($options, [HyperCups::OPTION => true])],
            'capacity' => $n, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
            'slug' => 'hyper-cup-'.fake()->unique()->numberBetween(1, 1_000_000), 'ladder_address' => null,
        ]);

        foreach (range(1, $n) as $index) {
            $user = User::factory()->create();
            TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
        }

        app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
        app(TournamentRunner::class)->sync($tournament);

        return $tournament->refresh();
    }

    /**
     * Ends a match: the seats in `$order` (seat indexes, best first) take places 1, 2, …; the first is a player.
     *
     * @param  list<int>  $order
     */
    public static function finishTable(HyperMatch $match, array $order): HyperMatch
    {
        $winner = $match->load('seats.user')->seats->firstWhere('seat', $order[0])?->user ?? throw new \LogicException('The winner is a player.');
        $out = [];

        foreach (array_slice($order, 2) as $index => $seat) {
            $out[$seat] = $index + 3;
        }

        return self::finish(self::ending($match, winner: $order[0], runnerUp: $order[1], out: $out), $winner);
    }

    /**
     * A player in a clan: the clan's owner when it has none yet, else a member.
     */
    public static function inClan(User $user, Clan $clan): User
    {
        ClanMember::query()->updateOrCreate(['user_id' => $user->id], ['clan_id' => $clan->id, 'role' => ClanRole::Member, 'joined_at' => now()]);

        return $user;
    }

    /**
     * A live 2v2 team match (P4): `$a` and `$c` for clan `$red` (team 0, seats 0 and 2), `$b` and `$d` for
     * clan `$blue` (team 1, seats 1 and 3); a null player is a bot of that team.
     */
    public static function teams(Clan $red, Clan $blue, ?User $a, ?User $b, ?User $c = null, ?User $d = null, ?int $seed = 7): HyperMatch
    {
        $seat = fn (?User $user, string $faction, int $team): array => $user === null ? ['bot' => true, 'faction' => $faction, 'team' => $team] : ['user' => $user, 'faction' => $faction, 'team' => $team];

        return app(HyperMatches::class)->create([
            $seat($a, 'bitcoiner', 0), $seat($b, 'fed', 1), $seat($c, 'ezb', 0), $seat($d, 'goldbug', 1),
        ], seed: $seed, creator: $a, teamClans: [$red->id, $blue->id]);
    }

    /**
     * A team match one card from its end: team 0 (seats 0, 2) holds every territory but Mexico, which seat 1
     * holds with one pleb (seat 3 is out); seat 0 has a 51%-Attacke in hand in its attack phase
     * (`play_card attack51 mexiko` wins it for team 0, seat 2 still in).
     */
    public static function teamEndgame(HyperMatch $match): HyperMatch
    {
        $state = HyperGame::fromArray($match->state)->toArray();

        foreach (HyperMap::IDS as $index => $id) {
            $state['territories'][$id] = ['owner' => $index % 3 === 0 ? 2 : 0, 'pleb' => 2, 'maxi' => 0, 'asic' => 0, 'shield' => null];
        }

        $state['territories']['mexiko'] = ['owner' => 1, 'pleb' => 1, 'maxi' => 0, 'asic' => 0, 'shield' => null];
        $state['territories']['ny'] = ['owner' => 0, 'pleb' => 6, 'maxi' => 0, 'asic' => 0, 'shield' => null];
        $state['seat'] = 0;
        $state['phase'] = 'attack';
        $state['placed'] = [];
        $state['seats'][0] = [...$state['seats'][0], 'hand' => ['attack51'], 'free_plebs' => 0];
        $state['seats'][3] = [...$state['seats'][3], 'out' => true];
        $match->forceFill(['state' => HyperGame::fromArray($state)->toArray(), 'current_seat' => 0])->save();

        return $match->refresh();
    }
}
