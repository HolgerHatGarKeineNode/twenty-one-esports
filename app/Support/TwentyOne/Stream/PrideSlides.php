<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\TournamentChampion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * The data of the pride and prize slides (e1-e4): single players named for
 * what they did, and the sats a tournament pays. Everything is read as the
 * site shows it, nothing is estimated; a slide without data says so.
 *
 * - `win`: the latest decided game of the last week (else the latest ever),
 *   chess or a board game that is switched on (plan "Mühle und Dame", P7):
 *   winner, loser, blitz or daily chess or the board game, the winner's
 *   casual Elo change and the game's page (`url`); a board game that won
 *   its winner a finished tournament names it (`tournament`, `final` when a
 *   knockout's final decided it) and links it.
 * - `climbers`: the three biggest casual chess Elo gains of the last seven
 *   days (sum of the live rating changes, gains only).
 * - `signups`: the six newest sign-ups of tournaments open for sign-up
 *   (withdrawn and removed ones left out), with the tournament and its pot.
 * - `prizes`: the open tournament with the biggest pot, and what each place
 *   wins (PrizePool::projection(), after the fee reserve) and its sponsors.
 *
 * Cached like StreamStats (plain arrays with picture refs); frame() turns the
 * refs into data URIs from the daemon's memory.
 */
class PrideSlides
{
    public const CACHE_KEY = 'twentyone.stream.pride';

    /** Days a win or a climb counts as recent. */
    public const DAYS = 7;

    public function __construct(private StreamImages $images, private GameRegistry $games, private PrizePool $pools) {}

    /**
     * @return array{win: array<string, mixed>|null, climbers: list<array<string, mixed>>, signups: list<array<string, mixed>>, prizes: array<string, mixed>|null}
     */
    public function all(): array
    {
        $seconds = max(1, (int) config('twentyone.stream.stats.cache_seconds', 15));

        try {
            $data = Cache::remember(self::CACHE_KEY, $seconds, fn (): array => $this->read());
        } catch (Throwable $e) {
            report($e);

            $data = $this->read();
        }

        return $this->frame($data);
    }

    /**
     * @return array{win: array<string, mixed>|null, climbers: list<array<string, mixed>>, signups: list<array<string, mixed>>, prizes: array<string, mixed>|null}
     */
    public function read(): array
    {
        return ['win' => $this->win(), 'climbers' => $this->climbers(), 'signups' => $this->signups(), 'prizes' => $this->prizes()];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function win(): ?array
    {
        $decided = fn () => ChessGame::query()->where('status', ChessGameStatus::Finished)->whereIn('result', ['1-0', '0-1'])
            ->whereNotNull('white_id')->whereNotNull('black_id')->with(['white', 'black'])->latest('ended_at')->latest('id');
        $game = $decided()->where('ended_at', '>=', now()->subDays(self::DAYS))->first() ?? $decided()->first();
        // A failing board game read (its tables, its tournament) costs the board win only, never the pride slides.
        try {
            $board = $this->boardWin($game);
        } catch (Throwable $e) {
            report($e);
            $board = null;
        }

        if ($board !== null) {
            return $board;
        }

        if ($game === null) {
            return null;
        }

        [$winner, $loser] = $game->result === '1-0' ? [$game->white, $game->black] : [$game->black, $game->white];
        $delta = RatingChange::query()->where(['source' => 'chess', 'source_id' => $game->id])
            ->whereHas('rating', fn ($rating) => $rating->where(['user_id' => $winner->id, 'pool' => Rating::CASUAL]))
            ->value('delta');

        return [
            'gameId' => $game->id,
            'winner' => PublicName::clean($winner->displayName()),
            'winnerRef' => StreamImages::avatarRef($winner),
            'loser' => PublicName::clean($loser->displayName()),
            'loserRef' => StreamImages::avatarRef($loser),
            'mode' => $game->isCorrespondence() ? 'Daily chess' : 'Blitz chess',
            'delta' => is_numeric($delta) ? (int) $delta : null,
            'ago' => $game->ended_at?->diffForHumans(),
            'url' => route('games.show', $game),
        ];
    }

    /**
     * The latest decided board game when it wins over the chess game `win()`
     * found: a recent one (DAYS) beats an older chess win, and between two
     * recent (or two older) games the later end wins. Null while no board
     * game is switched on, its page is not routed, or chess is later.
     *
     * @return array<string, mixed>|null
     */
    private function boardWin(?ChessGame $chess): ?array
    {
        $slugs = array_keys($this->games->boards());

        if ($slugs === [] || ! Route::has('board.show')) {
            return null;
        }

        $decided = fn () => BoardGame::query()->where('status', BoardGameStatus::Finished)->whereIn('result', ['1-0', '0-1'])->whereIn('game', $slugs)
            ->whereNotNull('white_id')->whereNotNull('black_id')->whereNotNull('ended_at')->with(['white', 'black'])->latest('ended_at')->latest('id');
        $since = now()->subDays(self::DAYS);
        $game = $decided()->where('ended_at', '>=', $since)->first() ?? $decided()->first();

        if ($game === null || $game->ended_at === null) {
            return null;
        }

        if ($chess !== null && $chess->ended_at !== null) {
            $boardRecent = $game->ended_at->gte($since);
            $chessRecent = $chess->ended_at->gte($since);

            if ($chessRecent && ! $boardRecent || $chessRecent === $boardRecent && $chess->ended_at->gte($game->ended_at)) {
                return null;
            }
        }

        [$winner, $loser] = $game->result === '1-0' ? [$game->white, $game->black] : [$game->black, $game->white];
        $delta = RatingChange::query()->where(['source' => RatingChange::BOARD, 'source_id' => $game->id])
            ->whereHas('rating', fn ($rating) => $rating->where(['user_id' => $winner->id, 'pool' => Rating::CASUAL]))
            ->value('delta');
        $tournament = $this->wonTournament($game, $winner);

        return [
            'gameId' => $game->id,
            'winner' => PublicName::clean($winner->displayName()),
            'winnerRef' => StreamImages::avatarRef($winner),
            'loser' => PublicName::clean($loser->displayName()),
            'loserRef' => StreamImages::avatarRef($loser),
            // "Checkers blitz 5+3", as "Blitz chess" for chess: the pride note writes it in lower case.
            'mode' => $this->games->name($game->game).' '.mb_strtolower($this->games->mode($game->game, $game->mode)->name ?? $game->mode),
            'delta' => is_numeric($delta) ? (int) $delta : null,
            'ago' => $game->ended_at->diffForHumans(),
            'tournament' => $tournament === null ? null : PublicName::clean($tournament->name),
            // Won in a final (a knockout): this game decided it. A table (Swiss, round robin) was only its last game.
            'final' => $tournament !== null && $tournament->format->hasFinal(),
            'url' => $tournament === null ? route('board.show', $game) : route('tournaments.show', $tournament),
        ];
    }

    /**
     * The tournament this board game won its winner: a finished tournament
     * whose champion (TournamentChampion) the winner is, and this game their
     * last in it. Null for a casual game and every other tournament game.
     */
    private function wonTournament(BoardGame $game, User $winner): ?Tournament
    {
        $tournament = $game->tournamentMatch?->tournament;

        if ($tournament === null || $tournament->status !== TournamentStatus::Finished) {
            return null;
        }

        $last = BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->playedBy($winner)
            ->whereNotNull('ended_at')->latest('ended_at')->latest('id')->value('id');
        $champion = app(TournamentChampion::class)->of($tournament);

        return $last === $game->id && $champion !== null && in_array($winner->id, $champion->memberIds(), true) ? $tournament : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function climbers(): array
    {
        $rows = RatingChange::query()->where('rating_changes.source', 'chess')->where('rating_changes.created_at', '>=', now()->subDays(self::DAYS))
            ->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
            ->where('ratings.pool', Rating::CASUAL)->whereNotNull('ratings.user_id')
            ->groupBy('ratings.user_id')
            ->selectRaw('ratings.user_id as user_id, sum(rating_changes.delta) as gain, count(*) as games')
            ->havingRaw('sum(rating_changes.delta) > 0')
            ->orderByDesc('gain')->orderByDesc('games')->orderBy('ratings.user_id')
            ->limit(3)->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');
        $climbers = [];

        foreach ($rows as $row) {
            $user = $users->get((int) $row->getAttribute('user_id'));

            if ($user !== null) {
                $climbers[] = ['name' => PublicName::clean($user->displayName()), 'ref' => StreamImages::avatarRef($user), 'gain' => (int) $row->getAttribute('gain'), 'games' => (int) $row->getAttribute('games')];
            }
        }

        return $climbers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function signups(): array
    {
        $signups = TournamentSignup::query()->whereNull('withdrawn_at')->whereNull('removed_at')->whereNotNull('user_id')
            ->whereHas('tournament', fn ($tournament) => $tournament->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now()))
            ->with(['user', 'tournament'])->latest('created_at')->latest('id')->limit(6)->get();
        $rows = [];

        foreach ($signups as $signup) {
            if ($signup->user === null) {
                continue;
            }

            $rows[] = [
                'name' => PublicName::clean($signup->user->displayName()),
                'ref' => StreamImages::avatarRef($signup->user),
                'tournament' => PublicName::clean($signup->tournament->name),
                'pot' => $this->pools->shownPotSats($signup->tournament),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function prizes(): ?array
    {
        $best = null;

        foreach (Tournament::query()->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())->get() as $tournament) {
            $pot = $this->pools->shownPotSats($tournament);

            if ($pot !== null && ($best === null || $pot > $best[1])) {
                $best = [$tournament, $pot];
            }
        }

        if ($best === null) {
            return null;
        }

        [$tournament, $pot] = $best;
        $timezone = (string) config('twentyone.stream.stats.timezone', 'Europe/Berlin');

        return [
            'name' => PublicName::clean($tournament->name),
            'game' => $this->games->name($tournament->game),
            'mode' => $this->games->mode($tournament->game, $tournament->mode)->name ?? $tournament->mode,
            'pot' => $pot,
            'places' => array_map(fn (array $place): array => ['place' => $place['place'], 'sats' => $place['sats']], $this->pools->projection($tournament)),
            'sponsors' => $tournament->sponsors()->orderBy('id')->limit(3)->pluck('name')->map(fn ($name): string => PublicName::clean((string) $name))->all(),
            'startsAt' => $tournament->starts_at->copy()->timezone($timezone)->format('D j M, H:i T'),
            // A full https link: the pride note needs one (StreamBotCopy::violations), the slide drops the scheme.
            'url' => route('tournaments.show', $tournament),
        ];
    }

    /**
     * read() data (fresh or cached) with its picture refs turned into data URIs, as the views take it.
     *
     * @param  array<string, mixed>  $data
     * @return array{win: array<string, mixed>|null, climbers: list<array<string, mixed>>, signups: list<array<string, mixed>>, prizes: array<string, mixed>|null}
     */
    public function framed(array $data): array
    {
        return $this->frame($data);
    }

    /**
     * The cached data with its picture refs turned into data URIs.
     *
     * @param  array<string, mixed>  $data
     * @return array{win: array<string, mixed>|null, climbers: list<array<string, mixed>>, signups: list<array<string, mixed>>, prizes: array<string, mixed>|null}
     */
    private function frame(array $data): array
    {
        $win = is_array($data['win'] ?? null) ? $data['win'] : null;

        if ($win !== null) {
            $win['winnerAvatar'] = $this->images->avatar($win['winnerRef'] ?? null);
            $win['loserAvatar'] = $this->images->avatar($win['loserRef'] ?? null);
            unset($win['winnerRef'], $win['loserRef']);
        }

        $pictured = function (array $rows): array {
            $out = [];

            foreach ($rows as $row) {
                $row['avatar'] = $this->images->avatar($row['ref'] ?? null);
                unset($row['ref']);
                $out[] = $row;
            }

            return $out;
        };

        return [
            'win' => $win,
            'climbers' => $pictured(is_array($data['climbers'] ?? null) ? $data['climbers'] : []),
            'signups' => $pictured(is_array($data['signups'] ?? null) ? $data['signups'] : []),
            'prizes' => is_array($data['prizes'] ?? null) ? $data['prizes'] : null,
        ];
    }
}
