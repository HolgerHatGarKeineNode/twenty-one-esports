<?php

namespace App\Support\Tournaments;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentStage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What a player sees when a tournament game ends (user, 2026-10-03: "einfache
 * Hinweise für den Spieler, wie es weitergeht"): the chess game page, the
 * board game page and the series room show this panel instead of the casual
 * follow-ups (rematch, next opponent, new game).
 *
 * It says the result of this game, where the match stands (a two-player
 * duel plays its games as matches between the same pair: "Game 2 of 3"),
 * what comes next for the viewer (the match goes on, wait for the next
 * round, out, the cup won, the tournament over) and how many matches the
 * others are still playing. A player of a game that just ended is taken to
 * the tournament page after a countdown, or straight to the next game of
 * the same pairing once it exists ({@see redirect()}).
 *
 * Read-only: it reads the stored bracket after TournamentRunner synced it.
 */
final class TournamentGameEnd
{
    /** A game that ended longer ago than this no longer counts down: a player opening an old game stays on it. */
    public const RECENT_MINUTES = 15;

    /** Seconds before a player of a game that just ended is taken on; `esports.tournaments.end_redirect_seconds` overrides it (the browser test pins it shorter). */
    public const REDIRECT_SECONDS = 10;

    /**
     * The panel for one finished tournament game, series or aborted game;
     * null when it is no tournament game.
     *
     * @return array{tournament: string, round: string, url: string, state: string, headline: string, result: string|null, step: string|null, score: string|null, line: string|null, others: string|null, player: bool, countdown: bool, seconds: int, next: bool}|null
     */
    public static function of(ChessGame|BoardGame|SeriesMatch $played, ?User $viewer): ?array
    {
        $match = self::matchOf($played);

        if ($match === null) {
            return null;
        }

        $tournament = $match->tournament;
        $slot = self::slotOf($match, $viewer);
        $pair = self::pairMatches($match);
        $index = $pair->search(fn (TournamentMatch $other): bool => $other->id === $match->id);
        $step = $pair->count() > 1 && $index !== false ? (string) __('Game :n of :total', ['n' => $index + 1, 'total' => $pair->count()]) : null;
        $nextInPair = $index === false ? null : $pair->slice($index + 1)->first(fn (TournamentMatch $other): bool => $other->result === null && in_array($other->status, ['waiting', 'ready'], true));
        $cup = $tournament->isCasualCup();

        [$state, $headline, $line] = match (true) {
            $match->result === null && $tournament->isDirectorMode() => ['director', (string) __('Your game is over'), (string) __('The tournament directors enter the result.')],
            $match->result === null && $match->status === 'ready' && $tournament->status === TournamentStatus::Running => ['replay', (string) __('Your match goes on'), (string) __('The match is not decided yet: the next game follows.')],
            $nextInPair !== null => ['continues', (string) __('Your match goes on'), (string) __('Game :n follows.', ['n' => (int) $pair->search(fn (TournamentMatch $other): bool => $other->id === $nextInPair->id) + 1])],
            default => self::after($tournament, $match, $slot),
        };

        if ($slot === null) {
            // A spectator: what happened to the match, without the "you".
            [$headline, $line] = match ($state) {
                'replay', 'continues' => [(string) __('The match goes on'), $line],
                'won', 'over' => [$cup ? (string) __('The cup is over') : (string) __('The tournament is over'), null],
                default => [(string) __('The match is over'), null],
            };
        }

        $ended = $played instanceof SeriesMatch ? $played->finished_at : $played->ended_at;
        $countdown = $slot !== null && $ended instanceof CarbonInterface && $ended->gte(now()->subMinutes(self::RECENT_MINUTES));

        return [
            'tournament' => $tournament->title(),
            // The round, so a cup game reads as one (user, 2026-10-03: "Warum dann diese verwirrende Meldungen?").
            'round' => (string) __('Round :number', ['number' => $match->round->number]),
            'url' => route('tournaments.show', $tournament),
            'state' => $state,
            'headline' => $headline,
            'result' => self::result($played, $match, $slot, $viewer),
            'step' => $step,
            'score' => $pair->count() > 1 && $slot !== null ? self::score($pair, $slot) : null,
            'line' => $line,
            'others' => $tournament->status === TournamentStatus::Running ? self::others($tournament, $pair) : null,
            'player' => $slot !== null,
            'countdown' => $countdown,
            'seconds' => max(1, (int) config('esports.tournaments.end_redirect_seconds', self::REDIRECT_SECONDS)),
            'next' => $countdown && self::nextGameUrl($played, $pair, $viewer) !== null,
        ];
    }

    /**
     * Where the countdown takes a player: the next game of the same pairing
     * once it exists, else the tournament page. Null when it is no
     * tournament game.
     */
    public static function redirect(ChessGame|BoardGame|SeriesMatch $played, ?User $viewer): ?string
    {
        $match = self::matchOf($played);

        if ($match === null) {
            return null;
        }

        return self::nextGameUrl($played, self::pairMatches($match), $viewer) ?? route('tournaments.show', $match->tournament);
    }

    private static function matchOf(ChessGame|BoardGame|SeriesMatch $played): ?TournamentMatch
    {
        if ($played->tournament_match_id === null) {
            return null;
        }

        return TournamentMatch::query()->with(['tournament', 'round.stage', 'slots.participant'])->find($played->tournament_match_id);
    }

    /** The viewer's slot in the match (0 or 1), or null for a spectator. */
    private static function slotOf(TournamentMatch $match, ?User $viewer): ?int
    {
        if ($viewer === null) {
            return null;
        }

        foreach ($match->slots as $slot) {
            if (in_array($viewer->id, $slot->participant?->memberIds() ?? [], true)) {
                return $slot->slot;
            }
        }

        return null;
    }

    /**
     * Every match of the tournament between the same two entries, in play
     * order: one for a knockout, `duel_games` for a two-player chess duel.
     *
     * @return Collection<int, TournamentMatch>
     */
    private static function pairMatches(TournamentMatch $match): Collection
    {
        $entries = $match->slots->pluck('tournament_participant_id')->filter()->values();

        if ($entries->count() !== 2) {
            return collect([$match]);
        }

        return TournamentMatch::query()->where('tournament_id', $match->tournament_id)->where('bracket', '!=', 'bye')->where('status', '!=', 'skipped')
            ->whereHas('slots', fn ($query) => $query->where('tournament_participant_id', $entries[0]))
            ->whereHas('slots', fn ($query) => $query->where('tournament_participant_id', $entries[1]))
            ->with(['round.stage', 'slots'])->get()
            ->sortBy(fn (TournamentMatch $other): array => [$other->round->stage->number, $other->round->number, $other->id])->values();
    }

    /**
     * The viewer's state once this match is decided.
     *
     * @return array{0: string, 1: string, 2: string|null}
     */
    private static function after(Tournament $tournament, TournamentMatch $match, ?int $slot): array
    {
        $cup = $tournament->isCasualCup();

        if ($tournament->status === TournamentStatus::Finished) {
            $participant = $slot === null ? null : $match->slots->firstWhere('slot', $slot)?->participant;
            $champion = $participant === null ? null : app(TournamentChampion::class)->of($tournament);

            return $champion !== null && $champion->id === $participant->id
                ? ['won', $cup ? __('You won the cup') : __('You won the tournament'), __('Congratulations! The final standings are on the tournament page.')]
                : ['over', $cup ? __('The cup is over') : __('The tournament is over'), __('The final standings are on the tournament page.')];
        }

        $participantId = $slot === null ? null : $match->slots->firstWhere('slot', $slot)?->tournament_participant_id;
        $next = $participantId === null ? null : TournamentMatch::query()->where('tournament_id', $tournament->id)->where('id', '!=', $match->id)
            ->whereNull('result')->whereIn('status', ['waiting', 'ready'])
            ->whereHas('slots', fn ($query) => $query->where('tournament_participant_id', $participantId))
            ->orderBy('id')->first();

        if ($next !== null) {
            return ['waiting', __('Your match is done: wait for the next round'), $next->status === 'ready'
                ? __('Your next match is ready. The tournament page shows it.')
                : __('Your next match starts as soon as your opponent is known.')];
        }

        if ($participantId !== null && self::isLastKnockout($match)) {
            return ['out', __('You are out'), $cup ? __('Thanks for playing! Follow the rest of the cup on the tournament page.') : __('Thanks for playing! Follow the rest of the tournament on the tournament page.')];
        }

        return ['waiting', __('Your match is done: wait for the next round'), __('The next round starts once the open matches are decided.')];
    }

    /** Whether the match is a knockout of the last stage: an entry without a further match there is out. */
    private static function isLastKnockout(TournamentMatch $match): bool
    {
        $stage = $match->round->stage;

        return in_array($stage->format, [TournamentFormat::SingleElimination, TournamentFormat::DoubleElimination], true)
            && $stage->number === (int) TournamentStage::query()->where('tournament_id', $match->tournament_id)->max('number');
    }

    /** The result of this game, from the viewer's side when they played it. */
    private static function result(ChessGame|BoardGame|SeriesMatch $played, TournamentMatch $match, ?int $slot, ?User $viewer): ?string
    {
        if ($played instanceof SeriesMatch) {
            $winner = $match->result['winner'] ?? null;
            $label = (string) ($match->result['label'] ?? '');

            if ($winner === null) {
                return $label === '' ? null : (string) __('Series :score', ['score' => $label]);
            }

            return (string) match (true) {
                $slot === null => __(':name wins the series :score', ['name' => $match->slots->firstWhere('slot', (int) $winner)->participant->name ?? '', 'score' => $label]),
                (int) $winner === $slot => __('You won the series :score', ['score' => $label]),
                default => __('You lost the series :score', ['score' => $label]),
            };
        }

        if ($played->status === ChessGameStatus::Aborted || $played->status === BoardGameStatus::Aborted) {
            return (string) __('The game was aborted: no first move.');
        }

        $winner = match ($played->result) {
            '1-0' => 'w',
            '0-1' => 'b',
            default => null,
        };
        $color = $played->colorOf($viewer);

        return (string) match (true) {
            $winner === null => __('This game was a draw'),
            $color === null => __(':name won this game', ['name' => ($winner === 'w' ? $played->white : $played->black)?->displayName() ?? '']),
            $color === $winner => __('You won this game'),
            default => __('You lost this game'),
        };
    }

    /**
     * The duel's score from the viewer's side, over its decided games.
     *
     * @param  Collection<int, TournamentMatch>  $pair
     */
    private static function score(Collection $pair, int $slot): string
    {
        $mine = 0.0;
        $theirs = 0.0;

        foreach ($pair as $match) {
            $won = (array) ($match->result['games_won'] ?? []);
            $mine += (float) ($won[$slot] ?? 0);
            $theirs += (float) ($won[1 - $slot] ?? 0);
        }

        $format = fn (float $points): string => str_replace('.5', '½', rtrim(rtrim(number_format($points, 1, '.', ''), '0'), '.'));

        return __('Score :mine : :theirs', ['mine' => $format($mine), 'theirs' => $format($theirs)]);
    }

    /**
     * How many other matches of the tournament are still being played.
     *
     * @param  Collection<int, TournamentMatch>  $pair
     */
    private static function others(Tournament $tournament, Collection $pair): ?string
    {
        $count = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
            ->where('status', 'ready')->whereNull('result')->whereNotIn('id', $pair->pluck('id'))->count();

        return $count === 0 ? null : trans_choice(':count other match is still being played.|:count other matches are still being played.', $count);
    }

    /**
     * The viewer's next game of the same pairing once it exists: a replay
     * of the same match or the next game of a duel.
     *
     * @param  Collection<int, TournamentMatch>  $pair
     */
    private static function nextGameUrl(ChessGame|BoardGame|SeriesMatch $played, Collection $pair, ?User $viewer): ?string
    {
        if ($viewer === null) {
            return null;
        }

        $ids = $pair->pluck('id');

        if ($played instanceof ChessGame) {
            $game = ChessGame::query()->whereIn('tournament_match_id', $ids)->whereKeyNot($played->id)->where('status', ChessGameStatus::Active)
                ->where(fn ($query) => $query->where('white_id', $viewer->id)->orWhere('black_id', $viewer->id))->latest('id')->first();

            return $game === null ? null : route('games.show', $game);
        }

        if ($played instanceof BoardGame) {
            $game = BoardGame::query()->whereIn('tournament_match_id', $ids)->whereKeyNot($played->id)->where('status', BoardGameStatus::Active)
                ->where(fn ($query) => $query->where('white_id', $viewer->id)->orWhere('black_id', $viewer->id))->latest('id')->first();

            return $game === null ? null : route('board.show', $game);
        }

        $series = SeriesMatch::query()->whereIn('tournament_match_id', $ids)->whereKeyNot($played->id)->latest('id')->get()
            ->first(fn (SeriesMatch $series): bool => $series->status->isRunning());

        return $series === null ? null : route('matches.room', $series);
    }
}
