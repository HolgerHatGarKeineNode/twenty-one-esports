<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;

/**
 * The layperson explanation of every format (TOURNAMENT-FORMATS.md, section
 * 7, "Final copy"): a short line, how it runs, who it is good for, upsides
 * and downsides. English copy, translated by lang/de.json.
 */
final class FormatCopy
{
    /**
     * @return array{short: string, how: string, good: string, pros: list<string>, cons: list<string>}
     */
    public static function for(TournamentFormat $format): array
    {
        $copy = match ($format) {
            TournamentFormat::Swiss => [
                'short' => 'Pairs by score, nobody is out',
                'how' => 'Everyone plays every round. After each round, players with the same score meet, and nobody meets the same opponent twice.',
                'good' => 'Many players, one evening, and everyone should play all night.',
                'pros' => ['Nobody is knocked out.', 'Needs far fewer rounds than everyone against everyone.', 'The strongest players meet in the last rounds.'],
                'cons' => ['No final match. The table decides.', 'Equal points at the top are split by tie-breaks.', 'Each pairing is only known once the round before is done.'],
            ],
            TournamentFormat::RoundRobin => [
                'short' => 'Everyone plays everyone',
                'how' => 'Everyone plays everyone. Most points wins.',
                'good' => 'Small groups up to about 8, or daily chess, where all games run at the same time.',
                'pros' => ['The fairest result: no lucky or unlucky draw.', 'Everyone plays the same number of games.'],
                'cons' => ['Gets long fast: 12 players need 11 rounds.', 'Late games can no longer change the top of the table.'],
            ],
            TournamentFormat::TwoStage => [
                'short' => 'Groups first, then a knockout final',
                'how' => 'First small groups where everyone plays everyone, then the best of each group play a knockout bracket to the final.',
                'good' => '8 or more players or teams who want both: several games each and a real final.',
                'pros' => ['Several guaranteed games in the group.', 'Ends with a final everyone can watch.'],
                'cons' => ['Takes longer than a single bracket.', 'The group draw matters: a strong group can knock out a strong player early.'],
            ],
            TournamentFormat::SingleElimination => [
                'short' => 'Lose once and you are out',
                'how' => 'Lose once and you are out. Winners move on until two are left for the final.',
                'good' => 'Little time, many players, or a show final on stream.',
                'pros' => ['The fastest format.', 'Every match matters.'],
                'cons' => ['Half the field plays only one match.', 'One bad game ends the day, even for a strong player.'],
            ],
            TournamentFormat::DoubleElimination => [
                'short' => 'Out after two losses',
                'how' => 'Lose once and you drop to the lower bracket. Lose twice and you are out. The winners of both brackets meet in the grand final.',
                'good' => 'Brackets where one bad game should not end the tournament.',
                'pros' => ['Everyone plays at least 2 matches.', 'A second chance feels fair.'],
                'cons' => ['Takes about twice as long as Single Elimination.', 'Harder to follow for people watching.'],
            ],
            TournamentFormat::FreeForAll => [
                'short' => 'Several players in one match',
                'how' => 'Several players compete in the same match. The best of each heat move on to the next round.',
                'good' => 'Games where 3 or more players compete at once, like racing or party games.',
                'pros' => [],
                'cons' => [],
            ],
            TournamentFormat::Leaderboard => [
                'short' => 'Best score or time wins',
                'how' => 'Everyone plays on their own for a score or a time. The best result ranks first.',
                'good' => 'Time trials and score attacks.',
                'pros' => [],
                'cons' => [],
            ],
        };

        return $copy;
    }
}
