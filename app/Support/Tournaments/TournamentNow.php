<?php

namespace App\Support\Tournaments;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\HyperMatchStatus;
use App\Enums\PongMatchStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\Hyperbitcoinization;
use App\Games\ProofOfPong;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\HyperMatch;
use App\Models\Lineup;
use App\Models\PongMatch;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentRound;
use App\Models\User;
use App\Support\Hyper\HyperTournamentTeams;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Series\CasualMatches;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;

/**
 * "What to do now" at the very top of a running tournament page (user,
 * 2026-10-03: "Oben müssen sie sehen, was sie machen müssen. Warten,
 * Spielen, in Lobby gehen."). Exactly one state per viewer, with one
 * primary action:
 *
 * - play: the viewer's game is live, "Go to your game";
 * - busy: the opponent (or the viewer) is in another live game, so the
 *   game waits; it says so and switches by itself once the game exists;
 * - ready / start / invited: a casual cup game still to be started
 *   (the opponent's invite to accept, the invite to send, the invite sent);
 * - schedule: a cup series without an agreed time (the times form);
 * - room: a series the league started, "Open your match room" with the
 *   lobby name and password once a player set them;
 * - lobby: a lobby tournament's own lobby (name and password);
 * - lineup: a Hyperbitcoinization clan bracket's match (P5b) whose players the viewer, a captain, names now;
 * - starting / match: a match without a game here yet;
 * - wait / done / bye: the viewer's match is decided or has no game this
 *   round, with the round's live count and, in a cup, its deadline;
 * - out / won / over: the viewer's tournament has ended;
 * - watch: anybody who does not play, with the live boards and the TV view.
 *
 * The decided states read TournamentGameEnd::after(), the panel a player
 * sees when a tournament game ends, so the game page and this hero say the
 * same. Read-only.
 */
final class TournamentNow
{
    /** The states with something to do right now: the hero wears the orange ring. */
    public const ACTIVE = ['play', 'ready', 'start', 'schedule', 'room', 'lobby', 'match', 'lineup'];

    /** The states that wait on somebody else: the hero says why and switches by itself. */
    public const WAITING = ['busy', 'invited', 'starting', 'wait', 'bye', 'done'];

    /** How many live boards a spectator gets as links. */
    public const BOARDS = 4;

    /** Every key of the hero, so each state names only what it says. */
    private const DEFAULTS = ['context' => null, 'line' => null, 'action' => null, 'until' => null, 'round' => null, 'others' => null,
        'paused' => false, 'boards' => [], 'lobby' => null, 'live' => 0, 'me' => null, 'opponent' => null, 'lineup' => null];

    /**
     * @param  array<string, mixed>|null  $cup  the show page's cupMatch()
     * @return array{state: string, title: string, context: string|null, line: string|null, action: array{label: string, href?: string, wire?: string, icon: string, target?: string}|null, until: CarbonInterface|null, round: array{number: int, total: int, playing: int}|null, others: string|null, paused: bool, boards: list<array{label: string, url: string, target?: string}>, lobby: array{name: string|null, password: string|null, chat: bool}|null, live: int, me: User|null, opponent: User|null, lineup: array{match: int, size: int, picked: list<int>, players: list<User>, until: CarbonInterface|null}|null, game: string}|null
     */
    public static function of(Tournament $tournament, ?User $viewer, ?array $cup = null, ?TournamentMatch $lobby = null): ?array
    {
        $now = self::resolve($tournament, $viewer, $cup, $lobby);

        /** @var array{state: string, title: string, context: string|null, line: string|null, action: array{label: string, href?: string, wire?: string, icon: string, target?: string}|null, until: CarbonInterface|null, round: array{number: int, total: int, playing: int}|null, others: string|null, paused: bool, boards: list<array{label: string, url: string, target?: string}>, lobby: array{name: string|null, password: string|null, chat: bool}|null, live: int, me: User|null, opponent: User|null, lineup: array{match: int, size: int, picked: list<int>, players: list<User>, until: CarbonInterface|null}|null, game: string}|null */
        return $now === null ? null : [...self::DEFAULTS, 'paused' => $tournament->status === TournamentStatus::Running && $tournament->isPaused(), 'game' => $tournament->game, ...$now];
    }

    /**
     * @param  array<string, mixed>|null  $cup
     * @return array<string, mixed>|null
     */
    private static function resolve(Tournament $tournament, ?User $viewer, ?array $cup, ?TournamentMatch $lobby): ?array
    {
        if (! in_array($tournament->status, [TournamentStatus::Running, TournamentStatus::Finished], true) || $tournament->profile()->isScore()) {
            return null;
        }

        $participant = $viewer === null ? null : TournamentParticipant::query()->where('tournament_id', $tournament->id)->get()
            ->first(fn (TournamentParticipant $entry): bool => in_array($viewer->id, $entry->memberIds(), true));

        if ($tournament->status === TournamentStatus::Finished) {
            return $participant === null ? null : [...self::finished($tournament, $participant), 'me' => $viewer];
        }

        if ($participant === null) {
            return self::watch($tournament);
        }

        if ($lobby !== null) {
            return ['state' => 'lobby', 'title' => (string) __('Join your lobby'), 'context' => (string) __('Lobby :number', ['number' => $lobby->position]), 'me' => $viewer];
        }

        // The match with a live game first (a round robin keeps every round open at once), else the earliest round.
        $match = $cup['match'] ?? TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'ready')->whereNull('result')->whereNull('lobby')
            ->where('bracket', '!=', 'bye')->whereHas('slots', fn ($query) => $query->where('tournament_participant_id', $participant->id))
            ->with(['round', 'slots.participant', 'chessGame', 'boardGame', 'seriesMatch', 'hyperMatch', 'pongMatch'])->get()
            ->sortBy(fn (TournamentMatch $open): array => [self::liveGame($tournament, $open) === null ? 1 : 0, $open->round->number, $open->id])->first();

        if ($match instanceof TournamentMatch) {
            return [...self::open($tournament, $match, $participant, $cup, $viewer), 'me' => $viewer];
        }

        return [...self::decided($tournament, $participant, $viewer), 'me' => $viewer];
    }

    /** The match's live game here, if any. */
    private static function liveGame(Tournament $tournament, TournamentMatch $match): ChessGame|BoardGame|HyperMatch|PongMatch|null
    {
        $profile = $tournament->profile();

        return match (true) {
            // Hyperbitcoinization (P5): the table the league started for the match.
            $profile->isHyper() => $match->hyperMatch?->status === HyperMatchStatus::Active ? $match->hyperMatch : null,
            // Proof of Pong (plan "Proof of Pong", P4): the live match, waiting for its players or in play.
            $profile->isPong() => $match->pongMatch !== null && ! $match->pongMatch->isOver() ? $match->pongMatch : null,
            $profile->isBoard() => $match->boardGame?->status === BoardGameStatus::Active ? $match->boardGame : null,
            $profile->isChess() => $match->chessGame?->status === ChessGameStatus::Active ? $match->chessGame : null,
            default => null,
        };
    }

    /**
     * Whether one of `$userIds` is in a live game outside `$match`: a chess or board game, or a casual series.
     *
     * @param  list<int>  $userIds
     */
    private static function busy(array $userIds, TournamentMatch $match): bool
    {
        if ($userIds === []) {
            return false;
        }

        $elsewhere = fn ($query) => $query->where(fn ($inner) => $inner->whereIn('white_id', $userIds)->orWhereIn('black_id', $userIds))
            ->where(fn ($inner) => $inner->whereNull('tournament_match_id')->orWhere('tournament_match_id', '!=', $match->id));

        return ChessGame::query()->where('status', ChessGameStatus::Active)->where($elsewhere)->exists()
            || BoardGame::query()->where('status', BoardGameStatus::Active)->where($elsewhere)->exists()
            || User::query()->whereKey($userIds)->get()->contains(fn (User $user): bool => CasualMatches::runningMatchOf($user) !== null);
    }

    /**
     * The viewer's open match: play it, start it, or join its room.
     *
     * @param  array<string, mixed>|null  $cup
     * @return array<string, mixed>
     */
    private static function open(Tournament $tournament, TournamentMatch $match, TournamentParticipant $participant, ?array $cup, ?User $viewer): array
    {
        $opponent = $match->slots->first(fn ($slot): bool => $slot->participant !== null && $slot->tournament_participant_id !== $participant->id)?->participant;
        $name = (string) ($opponent->name ?? __('Your opponent'));
        $context = (string) __('Round :number against :name', ['number' => $match->round->number, 'name' => $opponent->name ?? __('your opponent')]);
        $profile = $tournament->profile();
        $face = ['opponent' => $opponent?->user_id === null ? null : User::query()->find($opponent->user_id)];
        $game = self::liveGame($tournament, $match);

        // A Proof of Pong match (plan "Proof of Pong", P4) opens in a tab of its own, full-screen.
        if ($game instanceof PongMatch) {
            return [...$face, 'state' => 'play', 'title' => (string) __('Play now'), 'context' => $context,
                'line' => (string) ($game->status === PongMatchStatus::Waiting ? __('Your match is ready. Open it before the check-in ends, or it counts as a loss.') : __('Your match is live.')),
                'action' => ['label' => (string) __('Go to your match'), 'href' => route('pong.match', $game), 'icon' => 'play', 'target' => '_blank']];
        }

        // A Hyperbitcoinization table (P5) opens in a new tab, as every match of it does (user, 2026-10-08).
        if ($game instanceof HyperMatch) {
            // A clan bracket's substitute (P5b) sits at no seat: the table is theirs to watch.
            $seated = $viewer !== null && $game->seats()->where('user_id', $viewer->id)->exists();

            return [...$face, 'state' => 'play', 'title' => (string) ($seated ? __('Play now') : __('Your clan plays now')),
                'context' => $game->isTeamMatch() ? $context : (string) __('Round :number · table of :count', ['number' => $match->round->number, 'count' => $match->slots->count()]),
                'line' => (string) ($seated ? __('Your table is live and your turn time runs.') : __('You are not in this match. Watch your clan play.')),
                'action' => ['label' => (string) ($seated ? __('Go to your table') : __('Watch your clan')), 'href' => route('hyper.match', $game), 'icon' => 'pawn', 'target' => '_blank']];
        }

        // A clan bracket (P5b): no table before both clans named their players.
        if (HyperTournamentTeams::isClanBracket($tournament)) {
            return [...$face, ...self::lineup($tournament, $match, $participant, $opponent, $context, $viewer)];
        }

        if ($game !== null) {
            $url = $game instanceof BoardGame ? route('board.show', $game) : route('games.show', ['game' => $game]);

            return [...$face, 'state' => 'play', 'title' => (string) __('Play now'), 'context' => $context, 'line' => (string) __('Your game is live and your clock runs.'),
                'action' => ['label' => (string) __('Go to your game'), 'href' => $url, 'icon' => 'pawn']];
        }

        $series = $match->seriesMatch;

        if ($series instanceof SeriesMatch && $series->status->isRunning()) {
            return [...$face, 'state' => 'room', 'title' => (string) __('Join your lobby'), 'context' => $context,
                'line' => $series->start_at === null ? null : (string) __('Your match starts :time. Check in in the match room before.', ['time' => $series->start_at->copy()->setTimezone(self::zone($viewer))->translatedFormat('D j M, H:i')]),
                'action' => ['label' => (string) __('Open your match room'), 'href' => route('matches.room', $series), 'icon' => 'next'],
                'lobby' => ['name' => $series->lobby_name, 'password' => $series->lobby_password, 'chat' => $series->isCasualPairing()]];
        }

        if ($cup !== null && ($cup['series'] ?? false)) {
            return [...$face, 'state' => 'schedule', 'title' => (string) __('Agree on a time'), 'context' => $context];
        }

        $playsHere = $profile->isChess() || $profile->isBoard();
        // Why nothing starts yet (user, 2026-10-03: a cup game waited while the opponent played a casual game, and the page did not say so).
        $opponentBusy = $playsHere && ($cup === null || $cup['incoming'] === null) && self::busy($opponent?->memberIds() ?? [], $match);
        $viewerBusy = $playsHere && ! $opponentBusy && self::busy($participant->memberIds(), $match);

        if ($opponentBusy) {
            $line = match (true) {
                $cup !== null && $cup['outgoing'] !== null => (string) __(':name is in another game right now. Your invite waits; this page tells you once they accept.', ['name' => $name]),
                $cup !== null && ! ($cup['evening'] ?? false) => (string) __(':name is in another game right now. Invite them when it ends, or the league starts your game at :slot.', ['name' => $name, 'slot' => $cup['slot']->copy()->setTimezone(self::zone($viewer))->translatedFormat('D j M, H:i')]),
                default => (string) __(':name is in another game right now. Yours starts within a minute after it ends.', ['name' => $name]),
            };

            return [...$face, 'state' => 'busy', 'title' => (string) __('Your opponent is still playing'), 'context' => $context, 'line' => $line.' '.__('This page tells you as soon as your game starts.')];
        }

        if ($viewerBusy) {
            return [...$face, 'state' => 'busy', 'title' => (string) __('Finish your other game first'), 'context' => $context,
                'line' => (string) __('You are in another game right now. This one starts once it is over.')];
        }

        if ($cup !== null) {
            return [...$face, ...match (true) {
                $cup['incoming'] !== null => ['state' => 'ready', 'title' => (string) __('Your opponent is ready'), 'context' => $context, 'line' => (string) __(':name invited you to your cup game.', ['name' => $name]),
                    'action' => ['label' => (string) __('Start your game'), 'wire' => 'acceptCupInvite('.(int) $cup['incoming']->id.')', 'icon' => 'pawn']],
                $cup['outgoing'] !== null => ['state' => 'invited', 'title' => (string) __('Invite sent'), 'context' => $context, 'line' => (string) __('Waiting for :name to accept. Stay here: this page tells you when your game starts.', ['name' => $name])],
                default => ['state' => 'start', 'title' => (string) __('Start your game'), 'context' => $context,
                    'line' => ($cup['evening'] ?? false) ? (string) __('Both online? Invite your opponent now. Otherwise the league starts your game as soon as you are both free.') : (string) __('Both online? Invite your opponent now. Otherwise the league starts your game at :slot.', ['slot' => $cup['slot']->copy()->setTimezone(self::zone($viewer))->translatedFormat('D j M, H:i')]),
                    'action' => ['label' => (string) __('Invite your opponent'), 'wire' => 'playCupMatch', 'icon' => 'pawn']],
            }];
        }

        if ($playsHere) {
            return [...$face, 'state' => 'starting', 'title' => (string) __('Your game starts soon'), 'context' => $context, 'line' => (string) __('Stay here: this page tells you as soon as your game is ready.')];
        }

        $wait = $viewer === null ? null : collect(TournamentWaits::ofPlayer($tournament, $viewer))->first(fn (MatchWait $wait): bool => $wait->matchId === $match->id);

        return [...$face, 'state' => 'match', 'title' => (string) __('Play your match'), 'context' => $context,
            'line' => $tournament->isDirectorMode() ? (string) __('The tournament directors enter the result.') : null,
            'action' => $wait === null || $wait->url === route('tournaments.show', $tournament) ? null : ['label' => (string) __('Open your match'), 'href' => $wait->url, 'icon' => 'next']];
    }

    /**
     * A clan bracket's match before its table (P5b): the viewer's captain names the players (the viewer, when a
     * captain), or the other clan does; the league seats the players entered first at the deadline.
     *
     * @return array<string, mixed>
     */
    private static function lineup(Tournament $tournament, TournamentMatch $match, TournamentParticipant $participant, ?TournamentParticipant $opponent, string $context, ?User $viewer): array
    {
        $slot = (int) $match->slots->firstWhere('tournament_participant_id', $participant->id)?->slot;
        $other = (int) $match->slots->first(fn ($each): bool => $each->tournament_participant_id !== $participant->id)?->slot;
        $until = HyperTournamentTeams::deadline($tournament, $match);
        $time = $until === null ? null : $until->copy()->setTimezone(self::zone($viewer))->translatedFormat('H:i');
        $size = $tournament->teamSize();
        $lineup = $participant->lineup_id === null ? null : Lineup::query()->with(['clan', 'seats'])->find($participant->lineup_id);

        if (HyperTournamentTeams::mustName($tournament, $match, $participant, $slot)) {
            if ($lineup?->isActingCaptain($viewer) ?? false) {
                $eligible = HyperTournamentTeams::eligible($participant);
                $players = User::query()->whereKey($eligible)->get()->sortBy(fn (User $user): int => (int) array_search($user->id, $eligible, true))->values()->all();

                return ['state' => 'lineup', 'title' => (string) __('Name your team'), 'context' => $context,
                    'line' => (string) ($time === null
                        ? __('Pick the :count players who play this match.', ['count' => $size])
                        : __('Pick the :count players who play this match. Without a pick, the league seats the players your clan entered first at :time.', ['count' => $size, 'time' => $time])),
                    'lineup' => ['match' => $match->id, 'size' => $size, 'picked' => array_slice($eligible, 0, $size), 'players' => $players, 'until' => $until]];
            }

            return ['state' => 'starting', 'title' => (string) __('Your captain names the team'), 'context' => $context,
                'line' => (string) __(':count of your clan play this match. This page tells you as soon as the table starts.', ['count' => $size])];
        }

        $waiting = $opponent !== null && HyperTournamentTeams::mustName($tournament, $match, $opponent, $other);

        return ['state' => 'starting', 'title' => (string) __('Your table starts soon'), 'context' => $context,
            'line' => (string) ($waiting
                ? ($time === null
                    ? __(':name still names their team.', ['name' => $opponent->name])
                    : __(':name still names their team. The table starts at :time at the latest.', ['name' => $opponent->name, 'time' => $time]))
                : __('Stay here: this page tells you as soon as your table starts.'))];
    }

    /**
     * No open match: a bye, waiting for the next round, all games played, or out.
     *
     * @return array<string, mixed>
     */
    private static function decided(Tournament $tournament, TournamentParticipant $participant, ?User $viewer): array
    {
        $current = TournamentRunner::currentRound($tournament);
        $live = self::round($tournament, $current);
        $deadline = $current?->window_ends_at instanceof CarbonInterface && $current->window_ends_at->isFuture() ? $current->window_ends_at : null;
        $waitLine = $deadline !== null
            ? (string) __('The next round starts when they finish, at the latest at :time.', ['time' => $deadline->copy()->setTimezone(self::zone($viewer))->translatedFormat('H:i')])
            : (string) __('The next round starts when they finish.');
        $flip = (string) __('This page tells you as soon as your game starts.');

        $last = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNotNull('result')->where('bracket', '!=', 'bye')
            ->whereHas('slots', fn ($query) => $query->where('tournament_participant_id', $participant->id))
            ->with(['tournament', 'round.stage', 'slots.participant'])->orderByDesc('id')->first();
        $ahead = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNull('result')->where('status', 'waiting')
            ->whereHas('slots', fn ($query) => $query->where('tournament_participant_id', $participant->id))->exists();

        // A bye: a knockout or Swiss round gives the entry a one-sided match, a round robin of an odd field none at all.
        $inRound = $current === null ? collect() : TournamentMatch::query()->where('tournament_round_id', $current->id)
            ->whereHas('slots', fn ($query) => $query->where('tournament_participant_id', $participant->id))->pluck('bracket');
        $bye = $current !== null && ($inRound->contains('bye') || ($inRound->isEmpty() && $ahead));

        if ($bye) {
            return ['state' => 'bye', 'title' => (string) __('Bye this round'), 'context' => $live === null ? null : self::roundLine($live),
                'line' => (string) __('No game for you this round: you move on.').' '.$waitLine, 'until' => $deadline, 'round' => $live];
        }

        if ($last === null) {
            return ['state' => 'wait', 'title' => (string) __('Wait for your first match'), 'context' => $live === null ? null : self::roundLine($live),
                'line' => (string) __('Your match starts when its round opens.').' '.$flip, 'until' => $deadline, 'round' => $live];
        }

        $slot = $last->slots->firstWhere('tournament_participant_id', $participant->id)?->slot;
        [$state, , $line] = TournamentGameEnd::after($tournament, $last, $slot);

        if ($state === 'out') {
            return ['state' => 'out', 'title' => (string) __('You are out'), 'context' => $live === null ? null : self::roundLine($live),
                'line' => (string) __('Thanks for playing!'), 'action' => ['label' => (string) __('Watch the rest'), 'href' => self::watchUrl($tournament), 'icon' => 'eye'], 'round' => $live];
        }

        if (! $ahead && TournamentGameEnd::playedOut($tournament, $last)) {
            $title = $last->round->stage->format === TournamentFormat::RoundRobin ? __('Your games are done') : __('Your matches are done — waiting for the others');

            return ['state' => 'done', 'title' => (string) $title, 'context' => $live === null ? null : self::roundLine($live),
                'line' => (string) __('The final standings come when the last games end.'), 'action' => ['label' => (string) __('Watch the rest'), 'href' => self::watchUrl($tournament), 'icon' => 'eye'], 'round' => $live];
        }

        return ['state' => 'wait', 'title' => (string) __('Wait for the next round'), 'context' => $live === null ? null : self::roundLine($live),
            'line' => ($live !== null && $live['playing'] > 0 ? $waitLine : (string) $line).' '.$flip, 'until' => $deadline, 'round' => $live,
            'others' => $live === null ? TournamentGameEnd::others($tournament, collect([$last])) : null];
    }

    /**
     * A finished tournament: the champion, or the viewer's final place.
     *
     * @return array<string, mixed>
     */
    private static function finished(Tournament $tournament, TournamentParticipant $participant): array
    {
        $cup = $tournament->isCasualCup();
        $base = ['action' => ['label' => (string) __('See the final standings'), 'href' => route('tournaments.show', $tournament).'#bracket', 'icon' => 'tournaments']];

        if (app(TournamentChampion::class)->of($tournament)?->id === $participant->id) {
            return [...$base, 'state' => 'won', 'title' => $cup ? (string) __('You won the cup') : (string) __('You won the tournament'), 'line' => (string) __('Congratulations, champion!')];
        }

        $place = collect(app(TournamentPlacements::class)->of($tournament) ?? [])->first(fn (array $row): bool => in_array($participant->id, $row['participants'], true))['place'] ?? null;

        return [...$base, 'state' => 'over', 'title' => $place === null ? ($cup ? (string) __('The cup is over') : (string) __('The tournament is over')) : (string) __('You finished in place :place', ['place' => $place]),
            'line' => (string) __('Thanks for playing!')];
    }

    /**
     * Anybody who does not play: the live boards and the TV view.
     *
     * @return array<string, mixed>
     */
    private static function watch(Tournament $tournament): array
    {
        $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'ready')->whereNull('result')->where('bracket', '!=', 'bye')->select('id');
        $boards = [];
        $live = 0;

        foreach ([ChessGame::class => ['games.show', 'game', ChessGameStatus::Active], BoardGame::class => ['board.show', 'boardGame', BoardGameStatus::Active]] as $model => [$route, $param, $active]) {
            $games = $model::query()->whereIn('tournament_match_id', $matches)->where('status', $active)->with(['white', 'black'])->orderBy('id')->get();
            $live += $games->count();

            foreach ($games->take(self::BOARDS - count($boards)) as $game) {
                $boards[] = ['label' => (string) __(':white against :black', ['white' => $game->white->displayName(), 'black' => $game->black->displayName()]), 'url' => route($route, [$param => $game])];
            }
        }

        // Hyperbitcoinization tables (P5): each opens in a new tab. Asked only of its own tournaments while routed (P6): every
        // other tournament page stays within its query budget, with the game off too.
        $tables = $tournament->game !== Hyperbitcoinization::SLUG || ! Route::has('hyper.match') ? collect()
            : HyperMatch::query()->whereIn('tournament_match_id', $matches)->where('status', HyperMatchStatus::Active)->withCount('seats')->orderBy('id')->get();
        $live += $tables->count();

        foreach ($tables->take(self::BOARDS - count($boards)) as $table) {
            $boards[] = ['label' => (string) __('Table of :count', ['count' => $table->seats_count]), 'url' => route('hyper.match', $table), 'target' => '_blank'];
        }

        // Proof of Pong matches (plan "Proof of Pong", P4), each in a tab of its own; asked only of its own tournaments.
        $pong = $tournament->game !== ProofOfPong::SLUG || ! Route::has('pong.match') ? collect()
            : PongMatch::query()->whereIn('tournament_match_id', $matches)->where('status', PongMatchStatus::Active)->with(['left', 'right'])->orderBy('id')->get();
        $live += $pong->count();

        foreach ($pong->take(self::BOARDS - count($boards)) as $played) {
            $boards[] = ['label' => (string) __(':white against :black', ['white' => $played->left?->displayName() ?? '?', 'black' => $played->right?->displayName() ?? '?']), 'url' => route('pong.match', $played), 'target' => '_blank'];
        }

        $live += SeriesMatch::query()->whereIn('tournament_match_id', $matches)->get()->filter(fn (SeriesMatch $series): bool => $series->status->isRunning())->count();
        $current = TournamentRunner::currentRound($tournament);

        return ['state' => 'watch', 'title' => (string) __('Watch live'), 'context' => trans_choice(':count game running|:count games running', $live),
            'line' => null, 'action' => $tournament->isLeagueWeek() ? null : ['label' => (string) __('Open the TV view'), 'href' => route('tournaments.tv', $tournament), 'icon' => 'eye'],
            'round' => self::round($tournament, $current), 'boards' => $boards, 'live' => $live];
    }

    /**
     * The open round's matches: how many there are and how many still play.
     *
     * @return array{number: int, total: int, playing: int}|null
     */
    private static function round(Tournament $tournament, ?TournamentRound $round): ?array
    {
        if ($round === null) {
            return null;
        }

        $matches = TournamentMatch::query()->where('tournament_round_id', $round->id)->where('bracket', '!=', 'bye')->where('status', '!=', 'skipped')->get(['id', 'status', 'result']);

        return ['number' => $round->number, 'total' => $matches->count(), 'playing' => $matches->filter(fn (TournamentMatch $match): bool => $match->result === null && $match->status === 'ready')->count()];
    }

    /** @param  array{number: int, total: int, playing: int}  $round */
    private static function roundLine(array $round): string
    {
        return $round['playing'] === 0
            ? (string) __('Round :number: all matches are done', ['number' => $round['number']])
            : trans_choice('Round :number: :count match still playing|Round :number: :count matches still playing', $round['playing'], ['number' => $round['number']]);
    }

    private static function watchUrl(Tournament $tournament): string
    {
        return route('tournaments.show', $tournament).'#bracket';
    }

    private static function zone(?User $viewer): string
    {
        return (string) ($viewer->timezone ?? config('esports.preseason.display_timezone'));
    }
}
