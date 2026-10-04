<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\TournamentFormat;
use App\Models\Tournament;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatCopy;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\TournamentDeadlines;
use Carbon\CarbonInterface;
use Throwable;

/**
 * "How it runs" for the stream's tournament slides (t?3, and the short form
 * on the next-tournament slide t?7): the format in one line (FormatCopy), its
 * stages as a numbered sequence, how long a match is, what showing up means,
 * and when it starts and ends. Every line is read from the tournament's own
 * settings (format options, deadlines, start, the open-end estimate); nothing
 * is promised that the tournament page does not say. Stream copy is English.
 *
 * `field` is how many entries the stages are described for: the drawn
 * participants once there are some, else the places.
 */
final class TournamentPlaybook
{
    /**
     * @return array{short: string, steps: list<array{title: string, line: string}>, matches: string, showUp: string|null, starts: string|null, ends: string|null}
     */
    public static function of(Tournament $tournament, int $field, string $timezone): array
    {
        $options = $tournament->formatOptions();
        $profile = $tournament->profile();
        $n = max(2, $field);
        $at = fn (?CarbonInterface $moment, string $format): ?string => $moment?->copy()->timezone($timezone)->format($format);
        $end = null;

        try {
            $expected = $tournament->expectedEnd();
            $end = $expected === null ? null : 'Around '.$at($expected['typical'], 'H:i T');
        } catch (Throwable) {
            // An estimate that cannot be made leaves the line out; it is never guessed.
        }

        // A lobby tournament (P10): one diplomacy match per lobby, to its time limit.
        if (Lobbies::isLobby($tournament)) {
            $hours = Lobbies::timeLimit($tournament->game);

            return [
                'short' => FormatCopy::for($tournament->format)['short'],
                'steps' => self::lobbySteps($tournament, $n),
                'matches' => 'One lobby match, '.($hours % 60 === 0 ? intdiv($hours, 60).' h' : $hours.' min').' time limit',
                'showUp' => 'Be in the lobby at the start',
                'starts' => $at($tournament->starts_at, 'D j M, H:i T'),
                'ends' => $end,
            ];
        }

        // A cup of two plays one match (CasualCups::formatFor()), not the round robin or knockout it is stored as.
        $duel = CasualCups::isDuel($tournament);

        return [
            'short' => $duel ? 'One match, two players' : FormatCopy::for($tournament->format)['short'],
            'steps' => $duel ? self::duelSteps($tournament) : self::steps($tournament, $n),
            'matches' => self::matches($profile->isSeries(), $options->bestOf, $tournament->format->hasFinal() ? $options->finalBestOf : $options->bestOf, $profile->isDaily()),
            'showUp' => self::showUp($tournament, $profile->isSeries(), $n),
            'starts' => $at($tournament->starts_at, 'D j M, H:i T'),
            'ends' => $end,
        ];
    }

    /**
     * The stages in play order, one line each.
     *
     * @return list<array{title: string, line: string}>
     */
    private static function steps(Tournament $tournament, int $n): array
    {
        $options = $tournament->formatOptions();

        return match ($tournament->format) {
            TournamentFormat::TwoStage => self::twoStage($n, $options->groupSize, $options->advance, $options->groupStage, $options->finalStage),
            TournamentFormat::SingleElimination => [
                ['title' => 'Knockout', 'line' => $n.' '.self::entrants($tournament).'. Lose once and you are out.'],
                ['title' => 'Final', 'line' => $options->thirdPlace ? 'The last two play for the title, the semifinal losers for third.' : 'The last two play for the title.'],
            ],
            TournamentFormat::DoubleElimination => [
                ['title' => 'Upper bracket', 'line' => $n.' '.self::entrants($tournament).'. Win and you stay up.'],
                ['title' => 'Lower bracket', 'line' => 'Lose once and you drop here. Lose twice and you are out.'],
                ['title' => 'Grand final', 'line' => $options->grandFinal === 'reset' ? 'Upper winner against lower winner. The lower side must win twice.' : 'Upper winner against lower winner, one match for the title.'],
            ],
            TournamentFormat::Swiss => [
                ['title' => ($rounds = $options->swissRounds ?? Estimator::swissDefault($n)).' '.($rounds === 1 ? 'round' : 'rounds'), 'line' => 'Everyone plays every round, against someone on the same score.'],
                ['title' => 'The table', 'line' => 'Most points wins. Nobody is knocked out.'],
            ],
            TournamentFormat::RoundRobin => [
                ['title' => ($rounds = $options->iterations * Estimator::roundRobinRounds($n)).' '.($rounds === 1 ? 'round' : 'rounds'), 'line' => 'Everyone plays everyone'.($options->iterations > 1 ? ', '.$options->iterations.' times over.' : '.')],
                ['title' => 'The table', 'line' => 'Most points wins.'],
            ],
            // Not enabled for any live game (Estimator::disabledReason); described plainly, never drawn.
            default => [['title' => $tournament->format->label(), 'line' => FormatCopy::for($tournament->format)['how']]],
        };
    }

    /**
     * A duel's one match: chess as a round robin of two over `duel_games`
     * games, the series games as one best of `duel_best_of`.
     *
     * @return list<array{title: string, line: string}>
     */
    private static function duelSteps(Tournament $tournament): array
    {
        $options = $tournament->formatOptions();

        return [['title' => 'Duel', 'line' => $tournament->profile()->isSeries()
            ? 'Best of '.$options->bestOf.'.'
            : $options->iterations.' games, colours alternate. Most points wins.']];
    }

    /**
     * A lobby tournament's run: the lobbies the draw makes of the field, the
     * one diplomacy match, the shared place 1.
     *
     * @return list<array{title: string, line: string}>
     */
    private static function lobbySteps(Tournament $tournament, int $n): array
    {
        $sizes = Lobbies::split($tournament->game, $n);
        $count = count($sizes);

        return [
            ['title' => $count === 1 ? '1 lobby' : $count.' lobbies', 'line' => $n.' players, '.($count === 1 ? 'all in one lobby' : implode(' + ', $sizes)).'. Nobody moves on.'],
            ['title' => 'Diplomacy', 'line' => 'Everyone starts alone. Ally, betray, survive.'],
            ['title' => 'Shared wins', 'line' => 'The allies left standing share place 1.'],
        ];
    }

    /**
     * @return list<array{title: string, line: string}>
     */
    private static function twoStage(int $n, int $groupSize, int $advance, string $groupStage, string $finalStage): array
    {
        $groups = Estimator::groups($n, max(2, $groupSize));
        $through = min($advance, min($groups));
        $count = count($groups);
        $sizes = min($groups) === max($groups) ? (string) $groups[0] : min($groups).' to '.max($groups);
        $inGroup = match ($groupStage) {
            'single-elimination' => 'a knockout in each group',
            'double-elimination' => 'a double knockout in each group',
            default => 'everyone plays everyone',
        };

        return [
            ['title' => 'Group stage', 'line' => $count.' '.($count === 1 ? 'group' : 'groups').' of '.$sizes.', '.$inGroup.'. The top '.$through.' go through.'],
            ['title' => 'Final stage', 'line' => ($count * $through).' left, '.($finalStage === 'double-elimination' ? 'double elimination' : 'single elimination').' to the final.'],
        ];
    }

    /** "players" or "teams", as the tournament enters them. */
    private static function entrants(Tournament $tournament): string
    {
        return $tournament->profile()->entersTeams() ? 'teams' : 'players';
    }

    /** "One game a match", "Best of 3, the final best of 5", "One game a match, the final best of 3", "One move a day". */
    public static function matches(bool $series, int $bestOf, int $finalBestOf, bool $daily): string
    {
        if ($daily) {
            return 'One game a match, a move a day';
        }

        if (! $series) {
            return 'One game a match';
        }

        // A best of 1 (Age of Empires II's cups, EA FC's rounds) is one game, said so.
        return ($bestOf === 1 ? 'One game a match' : 'Best of '.$bestOf).($finalBestOf !== $bestOf ? ', the final best of '.$finalBestOf : '');
    }

    /**
     * What showing up means: the first-move window of a game, a series'
     * no-show wait, or a casual cup's round window.
     */
    private static function showUp(Tournament $tournament, bool $series, int $n): ?string
    {
        if ($tournament->isCasualCup()) {
            $hours = CasualCups::windowHours($n);

            return $hours.' '.($hours === 1 ? 'hour' : 'hours').' to play each round';
        }

        if ($tournament->profile()->isDaily()) {
            return null;
        }

        if ($series) {
            $minutes = $tournament->noshow_minutes ?? TournamentDeadlines::defaults($tournament->mode)['noshow_minutes'];

            // "At the start", not "at kickoff": the series games are not all football (Age of Empires II).
            return 'Be there at the start, '.$minutes.' min grace';
        }

        $minutes = max(1, intdiv(TournamentDeadlines::checkinSeconds($tournament), 60));

        return 'First move within '.$minutes.' min, or it is a forfeit';
    }
}
