<?php

namespace App\Support\Hyper;

use App\Enums\HyperEndReason;
use App\Enums\HyperMatchStatus;
use App\Events\HyperHandUpdated;
use App\Events\HyperMatchUpdated;
use App\Jobs\CheckHyperClock;
use App\Jobs\PlayHyperBots;
use App\Models\HyperAction;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\GameChat\GameChannels;
use App\Support\Nostr\PlayerProfile;
use App\Support\Notifications\HyperNotifications;
use App\Support\StreamChat\StreamChat;
use App\Support\Tournaments\TournamentRunner;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every change to a Hyperbitcoinization match goes through here (plan "Hyperbitcoinization", P2), and the
 * server is the only judge: the rules core (HyperGame) checks every action against the stored state, never
 * against what a browser believes.
 *
 * On the board games' proven pattern (App\Support\Board\BoardGameService): each change runs in a
 * transaction on the match row locked for update, stores every action the core took with the events it
 * caused (hyper_actions, the replay), and broadcasts after commit (HyperMatchUpdated to the table,
 * HyperHandUpdated to a seat whose cards changed). An action names the ply it expects to become; a client
 * that is behind is refused instead of guessed at, and the unique (match, ply) index refuses a second
 * action for the same ply.
 *
 * Bots play on the server (PlayHyperBots, queued after the change that hands them the turn), each bot turn
 * stored as the actions a player would have sent. A live turn has `esports.hyper.turn_seconds`; when it
 * runs out (CheckHyperClock, delayed to the deadline, and the `hyper:check-clocks` sweep) the server ends
 * it with `end_turn` (placed troops stay). After `esports.hyper.takeover_timeouts` timed-out turns in a
 * row a bot takes over a casual seat; a player who leaves is replaced by a bot as well (casual; in a rated
 * match it is marked as a forfeit). The end writes every seat's place and loot.
 *
 * A rated match (P5, HyperSeason) is one whose every seat is a player at the start, begun in a live chain
 * season; a tournament match is rated on the same terms, a cup match never. In it no bot plays for a player:
 * an overdue correspondence turn ends as a live one does, and `takeover_timeouts` timed-out turns in a row are a
 * forfeit like leaving (a bot plays the seat on for the others, the player takes the last place). The end
 * writes the season entry (HyperSeason::record()) in the same transaction, and after the commit reports a
 * tournament match's places (TournamentRunner::hyperMatchFinished()) and, behind `esports.hyper.publish`, has
 * the league sign the result (HyperPublisher).
 *
 * A correspondence match (P3) has `esports.hyper.correspondence_hours` per turn instead; a turn that runs
 * out there is played by a bot for the seat (the whole turn when the player had not begun it, else it ends
 * as live), and the seat whose turn starts is notified (HyperNotifications; never a bot seat, never a
 * spectator). The lobby is HyperLobby.
 *
 * A team match (P4) gives every seat a `team` (0 or 1, seated alternately by the lobby) and keeps each
 * team's clan (`team_clans`); the rules core plays the teams, and the end gives every seat of the winning
 * team place 1.
 */
final class HyperMatches
{
    /** The action fields the core reads; anything else a client sends is not stored. */
    private const ACTION_KEYS = ['type', 'territory', 'unit', 'qty', 'from', 'to', 'mode', 'count', 'card', 'target'];

    /** Reverb refuses a message above 10 kB; a broadcast above this sends no events (the page fetches them). */
    private const BROADCAST_BYTES = 9000;

    /* ---------- Start --------------------------------------------------------------------------------------- */

    /**
     * A match that starts at once, seats in the given order: a player (`user`) or a bot (`bot` true, no
     * user). A faction left out is drawn from the factions nobody chose; every faction once per match.
     * `mode`: HyperMatch::LIVE (a turn of `turn_seconds`) or ::CORRESPONDENCE (`correspondence_hours`).
     * `teamClans`: a team match, the clan of team 0 and team 1 (null for a side of bots); every seat then
     * names its `team`. `rated` (P5): null or true rates the match when every seat is a player and a chain
     * season is live (HyperSeason::seasonFor()), false never (a cup). `tournamentMatch`: the tournament match
     * it plays, whose places it reports at the end.
     *
     * @param  list<array{user?: User|null, bot?: bool, faction?: string|null, team?: int|null}>  $seats
     * @param  list<int|null>|null  $teamClans
     *
     * @throws InvalidArgumentException for 2 > seats > 6, a seat without player and bot, a player twice, an unknown or doubled faction, an unknown mode, teams on some seats only
     */
    public function create(array $seats, int $limit = 0, ?int $seed = null, ?User $creator = null, string $mode = HyperMatch::LIVE, ?array $teamClans = null, ?bool $rated = null, ?int $tournamentMatch = null): HyperMatch
    {
        if (($teamClans !== null) !== array_any($seats, fn (array $seat): bool => isset($seat['team']))) {
            throw new InvalidArgumentException('A team match names the teams\' clans and every seat\'s team.');
        }

        if (! in_array($mode, [HyperMatch::LIVE, HyperMatch::CORRESPONDENCE], true)) {
            throw new InvalidArgumentException('A match is live or correspondence.');
        }

        if (count($seats) < 2 || count($seats) > 6) {
            throw new InvalidArgumentException('A match has 2 to 6 seats.');
        }

        $users = array_values(array_filter(array_map(fn (array $seat): ?int => ($seat['user'] ?? null)?->id, $seats)));

        if (count($users) !== count(array_unique($users))) {
            throw new InvalidArgumentException('A player takes one seat.');
        }

        $chosen = array_values(array_filter(array_map(fn (array $seat): ?string => $seat['faction'] ?? null, $seats)));
        $free = array_values(array_diff(array_keys(HyperGame::FACTIONS), $chosen));
        shuffle($free);
        $specs = [];

        foreach ($seats as $seat) {
            $bot = (bool) ($seat['bot'] ?? false);

            if ($bot === (($seat['user'] ?? null) instanceof User)) {
                throw new InvalidArgumentException('A seat is a player or a bot.');
            }

            $specs[] = ['faction' => $seat['faction'] ?? array_shift($free), 'bot' => $bot, ...(isset($seat['team']) ? ['team' => $seat['team']] : [])];
        }

        $seed ??= random_int(0, 0xFFFFFFFF);
        $step = HyperGame::start($specs, $limit, $seed);
        $now = $this->nowMs();
        // Only a match without bots counts in the season (user, 2026-10-08), and only inside one.
        $season = $rated !== false && ! array_any($specs, fn (array $spec): bool => $spec['bot']) ? HyperSeason::seasonFor() : null;

        $match = DB::transaction(function () use ($seats, $specs, $step, $limit, $seed, $creator, $now, $mode, $teamClans, $season, $tournamentMatch): HyperMatch {
            $match = HyperMatch::query()->create([
                'mode' => $mode,
                'status' => HyperMatchStatus::Active,
                'seed' => $seed,
                'round_limit' => $limit,
                'team_clans' => $teamClans,
                'rated' => $season !== null,
                'season' => $season,
                'tournament_match_id' => $tournamentMatch,
                'state' => $step->game->toArray(),
                'ply' => 0,
                'current_seat' => $step->game->currentSeat(),
                'turn_started_ms' => $now,
                'deadline_ms' => $now + $this->turnMs($mode),
                'created_by' => $creator?->id,
            ]);

            foreach ($specs as $index => $spec) {
                HyperSeat::query()->create([
                    'hyper_match_id' => $match->id,
                    'seat' => $index,
                    'user_id' => ($seats[$index]['user'] ?? null)?->id,
                    'faction' => $spec['faction'],
                    'bot' => $spec['bot'],
                    'team' => $spec['team'] ?? null,
                ]);
            }

            // Ply 0: the setup's events (deal, first turn), so the log alone shows the whole match.
            HyperAction::query()->create([
                'hyper_match_id' => $match->id,
                'ply' => 0,
                'seat' => $step->game->currentSeat(),
                'source' => HyperAction::SERVER,
                'action' => ['type' => 'start', 'limit' => $limit],
                'events' => $step->events,
                'created_at' => now(),
            ]);

            $this->afterTurnChange($match);

            if ($specs[$step->game->currentSeat()]['bot']) {
                PlayHyperBots::dispatch($match->id, 0);
            }

            // The spectators' "Who wins?" of a rated or tournament match (P5), signed by the league after the commit.
            if ($match->rated || $match->tournament_match_id !== null) {
                $id = $match->id;
                DB::afterCommit(fn () => app(HyperPublisher::class)->poll($id));
            }

            return $match;
        });

        return $match->load('seats');
    }

    /* ---------- Actions ------------------------------------------------------------------------------------- */

    /**
     * One action of the user's seat (HyperGame's actions). `expectedPly` is the ply the client believes the
     * action becomes (the match's ply + 1); a mismatch is refused, so an action sent twice is taken once.
     * Returns the match after it and the action's events as the user's seat sees them.
     *
     * @param  array<string, mixed>  $action
     * @return array{match: HyperMatch, events: list<array<string, mixed>>}
     *
     * @throws HyperRuleViolation `not_seated`, `seat_taken_over`, `game_over`, `out_of_sync`, `turn_timed_out`, or the core's reason
     */
    public function act(HyperMatch $match, User $user, array $action, ?int $expectedPly = null): array
    {
        $action = array_intersect_key($action, array_flip(self::ACTION_KEYS));
        $events = [];
        $seatIndex = null;

        $match = $this->change($match, function (HyperMatch $match) use ($user, $action, $expectedPly, &$events, &$seatIndex): void {
            $seat = $match->seatOf($user) ?? throw new HyperRuleViolation('not_seated', 'Only a seated player acts.');
            $seatIndex = $seat->seat;

            if ($seat->bot) {
                throw new HyperRuleViolation('seat_taken_over', 'A bot plays this seat now.');
            }

            if ($expectedPly !== null && $expectedPly !== $match->ply + 1) {
                throw new HyperRuleViolation('out_of_sync', "Expected ply {$expectedPly}, the match is at ".($match->ply + 1).'.');
            }

            $step = $this->game($match)->apply($seat->seat, $action);
            $seat->timeouts = 0;
            $seat->save();
            $this->store($match, $seat->seat, HyperAction::PLAYER, [['action' => $action, 'events' => $step->events]], $step->game);
            $events = $step->events;
        });

        return ['match' => $match, 'events' => (new HyperView($seatIndex, ! $match->isActive()))->events($events)];
    }

    /**
     * Plays every bot turn in a row from `expectedPly` on, each in its own transaction and broadcast, until
     * a player is to move or the match is over. Does nothing when the match moved on since (another job,
     * a player) or the seat to move is no bot.
     */
    public function playBots(HyperMatch $match, int $expectedPly): void
    {
        $ply = $expectedPly;

        while ($ply !== null) {
            $played = null;

            try {
                $match = $this->change($match, function (HyperMatch $match) use ($ply, &$played): void {
                    if ($match->ply !== $ply || ! $this->seatToMove($match)->bot) {
                        return;
                    }

                    $this->capBotsOnly($match);
                    $step = HyperBot::playTurn($this->game($match));
                    $this->store($match, (int) $match->current_seat, HyperAction::BOT, $step->actions, $step->game);
                    $played = $match->ply;
                }, chainBots: false);
            } catch (HyperRuleViolation $violation) {
                if ($violation->reason === 'game_over') {
                    return;
                }

                if ($violation->reason !== 'turn_timed_out') {
                    throw $violation;
                }

                // A player's overdue turn was ended first: play on from there if a bot is next.
                $match = HyperMatch::query()->with('seats')->findOrFail($match->id);
                $played = $match->ply;
            }

            $ply = $played !== null && $match->isActive() && $this->seatToMove($match)->bot ? $played : null;
        }
    }

    /**
     * A match only bots still play (every player left or was taken over) ends at the end of round
     * `esports.hyper.bot_round_cap` by the limit's ranking, as the simulator cuts a game at round 200:
     * without a player nobody ends it, and a rare bot game never does by itself. Stored as a server action
     * `{"type":"round_limit","round":n}`, so a replay sets the same limit at the same ply.
     */
    private function capBotsOnly(HyperMatch $match): void
    {
        $game = $this->game($match);
        $cap = (int) config('esports.hyper.bot_round_cap', 200);

        if ($match->seats->contains(fn (HyperSeat $seat): bool => ! $seat->bot) || $game->round() < $cap || ($game->limit() > 0 && $game->limit() <= $game->round())) {
            return;
        }

        $match->ply++;
        HyperAction::query()->create([
            'hyper_match_id' => $match->id,
            'ply' => $match->ply,
            'seat' => (int) $match->current_seat,
            'source' => HyperAction::SERVER,
            'action' => ['type' => 'round_limit', 'round' => $game->round()],
            'events' => [],
            'created_at' => now(),
        ]);
        $match->state = [...$match->state, 'limit' => $game->round()];
        $match->save();
    }

    /**
     * Ends the turn of the seat to move once its deadline passed; a bot seat whose turn is overdue is
     * played instead. Safe to call any time, from anywhere: only the server's clock decides.
     */
    public function checkClock(HyperMatch $match): HyperMatch
    {
        try {
            return $this->change($match, fn (): null => null);
        } catch (HyperRuleViolation $violation) {
            // `turn_timed_out` is this very check ending the turn; `game_over` a match that ended meanwhile.
            if (! in_array($violation->reason, ['game_over', 'turn_timed_out'], true)) {
                throw $violation;
            }

            return $match->refresh();
        }
    }

    /**
     * The user leaves their seat: a bot plays it from now on (in a rated match it is marked as a forfeit).
     * In the user's own turn the turn ends here.
     *
     * @throws HyperRuleViolation `not_seated`, `already_left`, `game_over`
     */
    public function leave(HyperMatch $match, User $user): HyperMatch
    {
        return $this->change($match, function (HyperMatch $match) use ($user): void {
            $seat = $match->seatOf($user) ?? throw new HyperRuleViolation('not_seated', 'Only a seated player leaves.');

            if ($seat->left_at !== null) {
                throw new HyperRuleViolation('already_left', 'This seat was left already.');
            }

            $seat->forceFill(['bot' => true, 'takeover' => $match->rated ? HyperSeat::TAKEOVER_FORFEIT : HyperSeat::TAKEOVER_LEFT, 'left_at' => now()])->save();
            $game = $this->game($match)->withBot($seat->seat);

            if ($match->current_seat === $seat->seat) {
                $step = $game->apply($seat->seat, ['type' => 'end_turn']);
                $this->store($match, $seat->seat, HyperAction::LEAVE, [['action' => ['type' => 'end_turn'], 'events' => $step->events]], $step->game);

                return;
            }

            $match->state = $game->toArray();
            $match->save();
        });
    }

    /* ---------- Reading ------------------------------------------------------------------------------------- */

    /**
     * Everything the match page needs, as the viewer may see it: on load and after a reconnect.
     *
     * @return array<string, mixed>
     */
    public function snapshot(HyperMatch $match, ?User $viewer): array
    {
        $match->loadMissing('seats.user');
        $me = $match->seatOf($viewer);
        $over = ! $match->isActive();
        $game = $this->game($match);
        $myTurn = $me !== null && ! $over && ! $me->bot && $match->current_seat === $me->seat;

        return [
            'id' => $match->ulid,
            'url' => route('hyper.match', $match),
            'mode' => $match->mode,
            'status' => $match->status->value,
            'rated' => $match->rated,
            'limit' => $match->round_limit,
            'ply' => $match->ply,
            'round' => $game->round(),
            'seat' => $match->current_seat,
            'phase' => $game->phase(),
            'teams' => $match->isTeamMatch() ? HyperTeams::sides($match->team_clans) : null,
            'winner_team' => $match->isTeamMatch() && $match->winner_seat !== null ? $match->seats->firstWhere('seat', $match->winner_seat)?->team : null,
            'turn_seconds' => intdiv($this->turnMs($match->mode), 1000),
            'turn_started_ms' => $match->turn_started_ms,
            'deadline_ms' => $match->deadline_ms,
            'server_ms' => $this->nowMs(),
            'winner' => $match->winner_seat,
            'end_reason' => $match->end_reason?->value,
            'me' => $me?->seat,
            'map' => ['territories' => HyperMap::IDS, 'zones' => HyperMap::ZONE_KEYS],
            // A team match names a seat's Nostr key only to that seat's team: opponents and spectators never get a team's keys.
            'seats' => $match->seats->map(fn (HyperSeat $seat): array => $this->seatView($seat, $match->isTeamMatch() && ($me === null || $me->team !== $seat->team)))->all(),
            'state' => (new HyperView($match->handSeatOf($viewer), $over))->state($match->state),
            'legal' => $myTurn ? $game->legal() : null,
            'chat' => ['channel' => GameChannels::matchChannelId($match->ulid)],
        ];
    }

    /**
     * The events after `afterPly`, grouped by ply, as the viewer may see them: the page's catch-up after a
     * reconnect or a truncated broadcast. At most `limit` plies.
     *
     * @return array{ply: int, actions: list<array{ply: int, seat: int, source: string, events: list<array<string, mixed>>}>}
     */
    public function eventsSince(HyperMatch $match, ?User $viewer, int $afterPly, int $limit = 500): array
    {
        $view = new HyperView($match->handSeatOf($viewer), ! $match->isActive());
        $actions = $match->actions()->where('ply', '>', $afterPly)->limit($limit)->get();

        return [
            'ply' => $match->ply,
            'actions' => array_values($actions->map(fn (HyperAction $action): array => [
                'ply' => $action->ply,
                'seat' => $action->seat,
                'source' => $action->source,
                'events' => $view->events($action->events),
            ])->all()),
        ];
    }

    /**
     * The match's rules core at its stored state.
     */
    public function game(HyperMatch $match): HyperGame
    {
        return HyperGame::fromArray($match->state);
    }

    /* ---------- Internals ----------------------------------------------------------------------------------- */

    /**
     * Lock, run the clock, apply one change, and broadcast after commit. A turn that ran out before the
     * change is ended by the timer first, and the change is refused (`turn_timed_out`) once that is stored.
     *
     * @param  Closure(HyperMatch): void  $change
     *
     * @throws HyperRuleViolation
     */
    private function change(HyperMatch $match, Closure $change, bool $chainBots = true): HyperMatch
    {
        $refusal = null;
        $plyBefore = 0;
        $handsBefore = [];

        $locked = DB::transaction(function () use ($match, $change, &$refusal, &$plyBefore, &$handsBefore): HyperMatch {
            $locked = HyperMatch::query()->lockForUpdate()->findOrFail($match->id);
            $locked->load('seats');
            $plyBefore = $locked->ply;
            $handsBefore = $this->hands($this->game($locked));

            if ($this->expire($locked)) {
                $refusal = new HyperRuleViolation('turn_timed_out', 'The turn ran out before the action arrived.');

                return $locked;
            }

            if (! $locked->isActive()) {
                $refusal = new HyperRuleViolation('game_over', 'The match is over.');

                return $locked;
            }

            $change($locked);

            return $locked;
        });

        if ($locked->wasChanged()) {
            $this->announce($locked, $plyBefore, $handsBefore);
        }

        if ($chainBots && $locked->isActive() && $this->seatToMove($locked)->bot) {
            PlayHyperBots::dispatch($locked->id, $locked->ply);
        }

        if ($refusal !== null) {
            throw $refusal;
        }

        return $locked;
    }

    /**
     * Ends the turn of a seat whose deadline has passed; true if it did. A timed-out player's seat goes to
     * a bot after `takeover_timeouts` in a row (casual only). An overdue bot turn is left to PlayHyperBots,
     * which change() sends.
     *
     * In a correspondence match a bot plays the overdue turn for the player (source `timer`, the bot's
     * actions): the whole turn when the player had not begun it (still recruiting, nothing placed, no
     * conquest waiting), else the turn ends as live, because the bot only plays a turn from its start.
     */
    private function expire(HyperMatch $match): bool
    {
        if (! $match->isActive() || $match->deadline_ms === null || $this->nowMs() < $match->deadline_ms) {
            return false;
        }

        $seat = $this->seatToMove($match);

        if ($seat->bot) {
            return false;
        }

        $overdue = $this->overdueTurn($match);
        $after = $overdue->game;
        $seat->timeouts++;

        // A bot takes over a seat that let its turns run out too often in a row: in a casual match it plays for the
        // player, in a rated one the player has forfeited (P5) and the bot only plays on for the others.
        if ($seat->timeouts >= (int) config('esports.hyper.takeover_timeouts', 3)) {
            $seat->forceFill($match->rated
                ? ['bot' => true, 'takeover' => HyperSeat::TAKEOVER_FORFEIT, 'left_at' => now()]
                : ['bot' => true, 'takeover' => HyperSeat::TAKEOVER_TIMEOUTS]);
            $after = $after->withBot($seat->seat);
        }

        $seat->save();
        $this->store($match, $seat->seat, HyperAction::TIMER, $overdue->actions, $after);

        return true;
    }

    /**
     * What the server does for a seat whose turn ran out: `end_turn` (live; or a correspondence turn the
     * player had begun), or in correspondence a bot's whole turn for an untouched one.
     */
    private function overdueTurn(HyperMatch $match): HyperStep
    {
        $game = $this->game($match);
        $seat = (int) $match->current_seat;
        $state = $game->toArray();

        // Untouched: the seat has not acted this turn at all (a played card counts, though it places nothing).
        $last = $match->actions()->reorder('ply', 'desc')->first(['seat', 'source']);
        $touched = $last !== null && $last->source === HyperAction::PLAYER && (int) $last->seat === $seat;

        // Never in a rated match (P5): a bot's turn would count as the player's play.
        if ($match->isCorrespondence() && ! $match->rated && ! $touched && $game->phase() === 'buy' && $state['placed'] === [] && $state['pending_move'] === null) {
            try {
                $turn = HyperBot::playTurn($game);

                // The bot played this seat's turn, and nothing beyond it.
                if ($turn->actions !== [] && ($turn->game->currentSeat() !== $seat || $turn->game->isOver())) {
                    return $turn;
                }
            } catch (HyperRuleViolation $violation) {
                report($violation);
            }
        }

        $step = $game->apply($seat, ['type' => 'end_turn']);

        return new HyperStep($step->game, $step->events, [['action' => ['type' => 'end_turn'], 'events' => $step->events]]);
    }

    /**
     * Stores the actions one seat took (one for a player, a whole turn for a bot) with their events, and
     * the state after them; keeps the turn's clock, the places of seats knocked out, and the end.
     *
     * @param  list<array{action: array<string, mixed>, events: list<array<string, mixed>>}>  $actions
     */
    private function store(HyperMatch $match, int $seat, string $source, array $actions, HyperGame $after): void
    {
        $now = $this->nowMs();
        $events = [];

        foreach ($actions as $taken) {
            $match->ply++;
            HyperAction::query()->create([
                'hyper_match_id' => $match->id,
                'ply' => $match->ply,
                'seat' => $seat,
                'source' => $source,
                'action' => $taken['action'],
                'events' => $taken['events'],
                'created_at' => now(),
            ]);
            array_push($events, ...$taken['events']);
        }

        $match->state = $after->toArray();
        $match->current_seat = $after->currentSeat();
        $this->placeKnockedOut($match, $events);

        if ($after->isOver()) {
            $this->finish($match, $after);
        } elseif (in_array('turn_started', array_column($events, 'type'), true)) {
            $match->turn_started_ms = $now;
            $match->deadline_ms = $now + $this->turnMs($match->mode);
            $this->afterTurnChange($match);
        }

        $match->save();
    }

    /**
     * A seat knocked out takes the place behind every seat still in: 4 seats, the first out is 4th.
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function placeKnockedOut(HyperMatch $match, array $events): void
    {
        $alive = $match->seats->whereNull('place')->count();

        foreach ($events as $event) {
            if (($event['type'] ?? null) !== 'player_eliminated') {
                continue;
            }

            $seat = $match->seats->firstWhere('seat', $event['seat']);

            if ($seat instanceof HyperSeat && $seat->place === null) {
                $seat->place = $alive--;
                $seat->save();
            }
        }
    }

    /**
     * The end: the winner first, at the round limit the seats still in by the limit's ranking, and every
     * seat's loot.
     */
    private function finish(HyperMatch $match, HyperGame $game): void
    {
        $loot = $game->loot();

        // standings() lists the seats still in, best first: the winner alone after a conquest, all of them at the limit.
        $ranking = $game->standings();
        $offset = 0;

        // A team match: every seat of the winning team shares place 1 (out or not), the others follow.
        if ($game->winnerTeam() !== null) {
            $winners = $match->seats->filter(fn (HyperSeat $seat): bool => $seat->team === $game->winnerTeam());
            $winners->each(fn (HyperSeat $seat) => $seat->place = 1);
            $ranking = array_values(array_filter($ranking, fn (int $index): bool => ! $winners->contains('seat', $index)));
            $offset = $winners->count();
        }

        foreach ($ranking as $rank => $index) {
            $seat = $match->seats->firstWhere('seat', $index);

            if ($seat instanceof HyperSeat && $seat->place === null) {
                $seat->place = $offset + $rank + 1;
            }
        }

        // A rated match (P5): whoever forfeited takes the last place, whatever the bot did with the seat.
        if ($match->rated) {
            HyperSeason::placeForfeits($match);
        }

        foreach ($match->seats as $seat) {
            $seat->loot = $loot[$seat->seat] ?? 0.0;
            $seat->save();
        }

        $match->forceFill([
            'status' => HyperMatchStatus::Finished,
            'winner_seat' => $game->winner(),
            'end_reason' => $game->wonByLimit() ? HyperEndReason::Limit : HyperEndReason::Conquest,
            'current_seat' => null,
            'deadline_ms' => null,
            'ended_at' => now(),
        ]);

        app(HyperSeason::class)->record($match);
        $id = $match->id;

        // After the commit, never part of it: the bracket moves on, and the league signs the result.
        if ($match->tournament_match_id !== null) {
            DB::afterCommit(fn () => app(TournamentRunner::class)->hyperMatchFinished($id));
        }

        if ($match->rated || $match->tournament_match_id !== null) {
            DB::afterCommit(fn () => app(HyperPublisher::class)->result($id));
        }
    }

    /**
     * A new turn began: its clock check is due at its deadline, and in a correspondence match the player
     * whose turn it is hears about it (a bot seat or a seat its player left never does).
     */
    private function afterTurnChange(HyperMatch $match): void
    {
        if ($match->deadline_ms === null) {
            return;
        }

        // Whole seconds, rounded up plus one: a queue's delay is second-precise, and an early check does nothing.
        $seconds = (int) ceil(max(0, $match->deadline_ms - $this->nowMs()) / 1000) + 1;
        CheckHyperClock::dispatch($match->id)->delay(now()->addSeconds($seconds));

        if (! $match->isCorrespondence()) {
            return;
        }

        $seat = $match->seats->firstWhere('seat', $match->current_seat);

        if ($seat instanceof HyperSeat && ! $seat->bot && $seat->left_at === null && $seat->user_id !== null) {
            app(HyperNotifications::class)->yourMove($match, $seat);
        }
    }

    /**
     * The table's broadcast of the plies since `plyBefore`, and each seat's secrets when its cards changed.
     *
     * @param  list<list<string>>  $handsBefore
     */
    private function announce(HyperMatch $match, int $plyBefore, array $handsBefore): void
    {
        $over = ! $match->isActive();
        $hands = $this->hands($this->game($match));
        $events = [];

        foreach ($match->actions()->where('ply', '>', $plyBefore)->get() as $action) {
            array_push($events, ...$action->events);
        }

        Broadcasts::send(new HyperMatchUpdated($match->ulid, $this->broadcastPayload($match, $plyBefore, $events)));

        foreach ($match->seats as $seat) {
            $hand = $hands[$seat->seat] ?? [];
            $secrets = HyperView::secretsOf($seat->seat, $events, $over);

            if ($seat->user_id !== null && $seat->left_at === null && ! $seat->bot && ($secrets !== [] || $hand !== ($handsBefore[$seat->seat] ?? []))) {
                Broadcasts::send(new HyperHandUpdated($match->ulid, $seat->seat, $match->ply, $hand, $secrets));
            }
        }
    }

    /**
     * What `hyper.updated` carries: where the match stands and the table's view of the new events.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    public function broadcastPayload(HyperMatch $match, int $fromPly, array $events): array
    {
        $game = $this->game($match);
        $payload = [
            'match' => $match->ulid,
            'from_ply' => $fromPly,
            'ply' => $match->ply,
            'status' => $match->status->value,
            'round' => $game->round(),
            'seat' => $match->current_seat,
            'phase' => $game->phase(),
            'turn_started_ms' => $match->turn_started_ms,
            'deadline_ms' => $match->deadline_ms,
            'winner' => $match->winner_seat,
            'seats' => $match->seats->map(fn (HyperSeat $seat): array => ['seat' => $seat->seat, 'bot' => $seat->bot, 'takeover' => $seat->takeover, 'place' => $seat->place, 'loot' => $seat->loot])->all(),
            'events' => (new HyperView(null, ! $match->isActive()))->events($events),
            'truncated' => false,
        ];

        if (strlen((string) json_encode($payload)) > self::BROADCAST_BYTES) {
            $payload['events'] = null;
            $payload['truncated'] = true;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function seatView(HyperSeat $seat, bool $hideKey = false): array
    {
        $user = $seat->user;

        return [
            'seat' => $seat->seat,
            'faction' => $seat->faction,
            'team' => $seat->team,
            'bot' => $seat->bot,
            'takeover' => $seat->takeover,
            'left' => $seat->left_at !== null,
            'user_id' => $user?->id,
            'name' => $user?->displayName(),
            'pubkey' => $hideKey ? null : $user?->pubkey,
            'avatar' => $user === null ? null : ($user->avatarUrl() ?? ($hideKey ? route('avatars.generated', ['pubkey' => StreamChat::AVATAR_PLACEHOLDER, 'v' => 1], false) : PlayerProfile::generatedAvatarUrl($user->pubkey))),
            // Who is at the table right now comes from the presence channel `hyper.{ulid}.here`, not from here.
            'connected' => null,
            'place' => $seat->place,
            'loot' => $seat->loot,
        ];
    }

    /**
     * Every seat's cards, by seat index.
     *
     * @return list<list<string>>
     */
    private function hands(HyperGame $game): array
    {
        return array_map(fn (int $seat): array => $game->seatAt($seat)['hand'], range(0, $game->seatCount() - 1));
    }

    private function seatToMove(HyperMatch $match): HyperSeat
    {
        return $match->seats->firstWhere('seat', $match->current_seat) ?? throw new HyperRuleViolation('bad_seat', 'No seat to move.');
    }

    private function turnMs(string $mode): int
    {
        return $mode === HyperMatch::CORRESPONDENCE
            ? (int) config('esports.hyper.correspondence_hours', 24) * 3_600_000
            : (int) config('esports.hyper.turn_seconds', 90) * 1000;
    }

    private function nowMs(): int
    {
        return (int) now()->getTimestampMs();
    }
}
