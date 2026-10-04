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
 * round, all matches played, out, the cup won, the tournament over) and how many matches the
 * others are still playing, and "Back to the tournament". Nobody is moved
 * off the page on their own (user, 2026-10-03: "Da ist ein Auto-Redirect
 * irgendwie drin oder? Das bitte ausmachen"): the player stays on the
 * finished game to look at it.
 *
 * Read-only: it reads the stored bracket after TournamentRunner synced it.
 */
final class TournamentGameEnd
{
    /**
     * The panel for one finished tournament game, series or aborted game;
     * null when it is no tournament game.
     *
     * @return array{tournament: string, round: string, url: string, state: string, headline: string, result: string|null, step: string|null, score: string|null, line: string|null, others: string|null, player: bool}|null
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
        ];
    }

    /**
     * The banner at the very top of a tournament game's page, live and after
     * its end (user, 2026-10-03: "groß fett rein, oben, dass es sich um ein
     * Turnierspiel handelt"): the tournament, its round, the game of a duel
     * ("Game 2 of 3") and what is at stake. Null when it is no tournament game.
     *
     * @return array{tournament: string, round: string, step: string|null, stake: string, url: string, cup: bool}|null
     */
    public static function banner(ChessGame|BoardGame|SeriesMatch $played): ?array
    {
        $match = self::matchOf($played);

        if ($match === null) {
            return null;
        }

        $tournament = $match->tournament;
        $pair = self::pairMatches($match);
        $index = $pair->search(fn (TournamentMatch $other): bool => $other->id === $match->id);
        $cup = $tournament->isCasualCup();

        return [
            'tournament' => $tournament->title(),
            'round' => (string) __('Round :number', ['number' => $match->round->number]),
            'step' => $pair->count() > 1 && $index !== false ? (string) __('Game :n of :total', ['n' => $index + 1, 'total' => $pair->count()]) : null,
            'stake' => $cup ? (string) __('Cup game — counts for the tournament') : (string) __('Tournament game — counts for the tournament'),
            'url' => route('tournaments.show', $tournament),
            'cup' => $cup,
            // The tournament desk's button (TournamentDesk::for(), per viewer, in the banner component).
            'tournamentId' => $tournament->id,
        ];
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
     * The viewer's state once this match is decided; the tournament page's "What to do now" hero (TournamentNow) reads it too.
     *
     * @return array{0: string, 1: string, 2: string|null}
     */
    public static function after(Tournament $tournament, TournamentMatch $match, ?int $slot): array
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

        if ($participantId !== null && self::playedOut($tournament, $match)) {
            return ['done', __('Your matches are done — waiting for the others'), __('The final standings come when the last games end.')];
        }

        return ['waiting', __('Your match is done: wait for the next round'), __('The next round starts once the open matches are decided.')];
    }

    /**
     * Whether an entry with no match ahead has played its last match: a round
     * robin stores every round up front, so nothing ahead means all games are
     * played; Swiss pairs one round at a time, so only its planned count
     * tells the last round. A group stage is never the end: its knockout
     * stage follows. The tournament page's hero (TournamentNow) reads it too.
     */
    public static function playedOut(Tournament $tournament, TournamentMatch $last): bool
    {
        $stage = $last->round->stage;

        if ($stage->number < (int) TournamentStage::query()->where('tournament_id', $tournament->id)->max('number')) {
            return false;
        }

        return match ($stage->format) {
            TournamentFormat::RoundRobin => true,
            TournamentFormat::Swiss => $last->round->number >= app(TournamentRunner::class)->swissRounds($tournament),
            default => false,
        };
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
    public static function others(Tournament $tournament, Collection $pair): ?string
    {
        $count = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
            ->where('status', 'ready')->whereNull('result')->whereNotIn('id', $pair->pluck('id'))->count();

        return $count === 0 ? null : trans_choice(':count other match is still being played.|:count other matches are still being played.', $count);
    }
}
