<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;
use App\Models\Clan;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentStage;
use App\Support\Tournaments\Engine\Standings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * The public view of a running or finished tournament, built from the stored
 * bracket and results (TournamentShow.dc.html, TournamentFormats.dc.html):
 * per stage either a bracket (knockout parts in columns by round), or a
 * table with its pairings (Swiss, round robin, round-robin groups), or heats.
 * Director results carry their marker ("Entered by the tournament director").
 */
final class TournamentView
{
    /** @var Collection<int, TournamentParticipant> */
    private Collection $participants;

    public function __construct(private Tournament $tournament)
    {
        $this->participants = $tournament->participants()->with('lineup.clan')->get()->keyBy('id');
    }

    /**
     * @return list<array{number: int, format: TournamentFormat, title: string, parts: list<array<string, mixed>>}>
     */
    public function stages(): array
    {
        $matches = TournamentMatch::query()->where('tournament_id', $this->tournament->id)
            ->with(['round', 'slots', 'seriesMatch', 'chessGame', 'boardGame'])->orderBy('id')->get();
        $stages = [];

        foreach (TournamentStage::query()->where('tournament_id', $this->tournament->id)->orderBy('number')->get() as $stage) {
            $own = $matches->filter(fn (TournamentMatch $match): bool => $match->round->tournament_stage_id === $stage->id);
            $parts = [];

            foreach ($own->groupBy(fn (TournamentMatch $match): string => (string) ($match->group ?? 0)) as $group => $groupMatches) {
                $parts[] = $this->part($stage, (int) $group, $groupMatches);
            }

            $stages[] = [
                'number' => $stage->number,
                'format' => $stage->format,
                'title' => $this->tournament->format === TournamentFormat::TwoStage ? ($stage->number === 1 ? __('Group stage') : __('Final stage')) : $stage->format->label(),
                'parts' => $parts,
            ];
        }

        return $stages;
    }

    /**
     * @param  Collection<int, TournamentMatch>  $matches
     * @return array<string, mixed>
     */
    private function part(TournamentStage $stage, int $group, Collection $matches): array
    {
        $title = $group > 0 ? __('Group :group', ['group' => self::letter($group)]) : null;

        if ($stage->format === TournamentFormat::Swiss || $stage->format === TournamentFormat::RoundRobin) {
            return [
                'kind' => 'table',
                'title' => $title,
                'rows' => $this->table($stage, $group, $matches),
                'rounds' => $matches->groupBy(fn (TournamentMatch $match): int => $match->round->number)
                    ->map(fn (Collection $round): array => array_values($round->map(fn (TournamentMatch $match): array => $this->box($match))->all()))
                    ->all(),
            ];
        }

        if ($matches->contains(fn (TournamentMatch $match): bool => in_array($match->bracket, ['heat', 'board'], true))) {
            return ['kind' => 'heats', 'title' => $title, 'heats' => array_values($matches->map(fn (TournamentMatch $match): array => $this->box($match))->all())];
        }

        $sections = [];

        foreach ($matches->groupBy(fn (TournamentMatch $match): string => $this->section($match->bracket)) as $section => $sectionMatches) {
            $sections[] = [
                'title' => match ($section) {
                    'upper' => __('Upper bracket'),
                    'lower' => __('Lower bracket'),
                    'grand-final' => __('Grand final'),
                    default => null,
                },
                'columns' => array_values($sectionMatches->groupBy(fn (TournamentMatch $match): int => $match->round->number)
                    ->sortKeys()
                    ->map(fn (Collection $round, int $number): array => [
                        'label' => $this->roundLabel($number, $sectionMatches),
                        'matches' => array_values($round->sortBy('position')->map(fn (TournamentMatch $match): array => $this->box($match))->all()),
                    ])->all()),
            ];
        }

        return ['kind' => 'bracket', 'title' => $title, 'sections' => $sections];
    }

    private function section(string $bracket): string
    {
        return match ($bracket) {
            'upper' => 'upper',
            'lower' => 'lower',
            'grand-final', 'reset' => 'grand-final',
            default => 'main',
        };
    }

    /**
     * @param  Collection<int, TournamentMatch>  $matches
     */
    private function roundLabel(int $number, Collection $matches): string
    {
        $last = (int) $matches->max(fn (TournamentMatch $match): int => $match->round->number);

        return match (true) {
            $number === $last && $matches->contains(fn (TournamentMatch $match): bool => in_array($match->bracket, ['main', 'grand-final', 'reset'], true)) => __('Final'),
            $number === $last - 1 && $matches->every(fn (TournamentMatch $match): bool => $match->bracket === 'main' || $match->bracket === 'third-place') => __('Semifinal'),
            default => __('Round :round', ['round' => $number]),
        };
    }

    /**
     * The table of a Swiss stage, a round robin or one group.
     *
     * @param  Collection<int, TournamentMatch>  $matches
     * @return list<array{rank: int, name: string, tag: string|null, clan: Clan|null, mix: bool, points: string, wins: int, ties: int, losses: int, games: string, buchholz: string}>
     */
    private function table(TournamentStage $stage, int $group, Collection $matches): array
    {
        $options = $this->tournament->formatOptions();
        $members = $group > 0
            ? $this->participants->where('group', $group)->sortBy('seed')->keys()->all()
            : $this->participants->whereNotNull('seed')->sortBy('seed')->keys()->all();
        $games = [];

        foreach ($matches->where('status', 'done') as $match) {
            $a = $match->slots[0]->tournament_participant_id ?? null;
            $b = $match->slots[1]->tournament_participant_id ?? null;
            $result = $match->matchResult();

            if ($a !== null && $match->bracket === 'bye') {
                $games[] = [(int) $a, null, Engine\MatchResult::win(0)];
            } elseif ($a !== null && $b !== null && $result !== null) {
                $games[] = [(int) $a, (int) $b, $result];
            }
        }

        $swiss = $stage->format === TournamentFormat::Swiss;
        $custom = $options->rankBy === 'custom';
        $rows = Standings::table(
            array_values(array_map(intval(...), $members)),
            $games,
            $swiss || $custom ? $options->pointsWin : 1.0,
            $swiss || $custom ? $options->pointsTie : 0.5,
            $swiss ? $options->pointsBye : 0.0,
            $swiss ? 'points' : $options->rankBy,
            $swiss ? $options->swissTieBreaks : $options->roundRobinTieBreaks,
        );

        return array_map(function ($row): array {
            $participant = $this->participants->get($row->entrant);

            return [
                'rank' => $row->rank,
                'name' => $participant->name ?? '?',
                'tag' => $participant?->lineup?->clan->clantag,
                'clan' => $participant?->lineup?->clan,
                'mix' => $participant?->isMixTeam() ?? false,
                'points' => self::number($row->points),
                'wins' => $row->wins,
                'ties' => $row->ties,
                'losses' => $row->losses,
                'games' => self::number($row->gameWins).':'.self::number($row->gameLosses),
                'buchholz' => self::number($row->medianBuchholz),
            ];
        }, $rows);
    }

    /**
     * One match as the views draw it.
     *
     * @return array<string, mixed>
     */
    public function box(TournamentMatch $match): array
    {
        $result = $match->result;
        $winner = $result['winner'] ?? null;
        // One game per match (chess, and the board games of plan "Mühle und Dame", P5): 1, ½, 0.
        $chess = $this->tournament->profile()->isChess() || $this->tournament->profile()->isBoard();
        $sides = [];

        foreach ($match->slots as $slot) {
            $participant = $slot->tournament_participant_id === null ? null : $this->participants->get($slot->tournament_participant_id);
            $index = $slot->slot;
            $score = null;

            $rank = isset($result['ranks'][$index]) && ! in_array($slot->tournament_participant_id, (array) ($result['unplaced'] ?? []), true) ? (int) $result['ranks'][$index] : null;

            if ($result !== null && $match->bracket !== 'bye') {
                $won = $result['games_won'][$index] ?? null;
                $score = $chess ? (($result['double_loss'] ?? false) ? '0' : ($winner === null ? '½' : ($winner === $index ? '1' : '0'))) : ($won === null ? null : self::number((float) $won));
            }

            // A heat or a lobby (P10) shows each side's place; a shared place 1 is won by all who hold it.
            if ($result !== null && isset($result['ranks']) && $match->bracket === 'heat') {
                $score = $rank === null ? '–' : '#'.$rank;
            }

            $sides[] = [
                'name' => $participant->name ?? self::source($slot->source),
                'known' => $participant !== null,
                'tag' => $participant?->lineup?->clan->clantag,
                'clan' => $participant?->lineup?->clan,
                'mix' => $participant?->isMixTeam() ?? false,
                'score' => $score,
                'won' => $result !== null && (isset($result['ranks']) && $match->bracket === 'heat' ? $rank === 1 : $winner === $index),
            ];
        }

        return [
            'key' => $match->key,
            'bracket' => $match->bracket,
            'status' => $match->status,
            'sides' => $sides,
            // A lobby's result (P10) is worded in the viewer's language, not the one it was entered in.
            'label' => $match->lobby !== null && is_array($result) ? LobbyResults::describe($result) : ($result['label'] ?? null),
            'number' => $match->seriesMatch !== null ? $match->seriesMatch->number : $match->chessGame?->number,
            'href' => $match->seriesMatch !== null
                ? route('matches.show', $match->seriesMatch)
                : ($match->chessGame !== null ? route('games.show', $match->chessGame)
                    : ($match->boardGame !== null && Route::has('board.show') ? route('board.show', $match->boardGame) : null)),
            // A result set on the tournament control (P18) is marked like a director's.
            'director' => $match->isDirectorResult() || ($result['by'] ?? null) === 'control' ? self::marker((array) $result) : null,
            'held' => $match->held !== null,
            'if_needed' => $match->if_needed,
        ];
    }

    /**
     * The public marker of a director result (TOURNAMENT-FORMATS.md, section 7).
     *
     * @param  array<string, mixed>  $result
     * @return array{corrected: bool, control: bool, lines: list<string>}
     */
    public static function marker(array $result): array
    {
        $at = fn (?string $time): string => $time === null ? '' : Carbon::parse($time)->timezone((string) config('esports.preseason.display_timezone'))->format('H:i');
        // Set or corrected on the tournament control (P18): by the organizer or an admin, unrated.
        if (($result['by'] ?? null) === 'control') {
            $who = $result['corrected'] ?? $result;
            $line = isset($result['corrected'])
                ? __('Corrected by :name at :time, was :old.', ['name' => (string) ($who['name'] ?? '?'), 'time' => $at($who['at'] ?? null), 'old' => (string) ($result['was'] ?? '')])
                : __('Set by :name at :time.', ['name' => (string) ($who['name'] ?? '?'), 'time' => $at($who['at'] ?? null)]);

            $effect = TournamentControl::storedElo($result['elo'] ?? null);
            $elo = $effect === null ? null : TournamentControl::describeElo($effect);

            return ['corrected' => isset($result['corrected']), 'control' => true, 'lines' => [$line, $elo === null
                ? __('Decided by the organizer or an admin; unrated.')
                : __('Decided by the organizer or an admin; the Elo of the played result was corrected: :elo.', ['elo' => $elo])]];
        }

        $lines = [__('Entered by :name at :time.', ['name' => (string) ($result['name'] ?? '?'), 'time' => $at($result['at'] ?? null)])];

        if (isset($result['corrected'])) {
            $lines[] = __('Corrected by :name at :time, was :old.', ['name' => (string) ($result['corrected']['name'] ?? '?'), 'time' => $at($result['corrected']['at'] ?? null), 'old' => (string) ($result['was'] ?? '')]);
        }

        $lines[] = __('Not confirmed by the players.');

        return ['corrected' => isset($result['corrected']), 'control' => false, 'lines' => $lines];
    }

    /**
     * Where an unknown side comes from, in words.
     *
     * @param  array<string, mixed>  $source
     */
    private static function source(array $source): string
    {
        return match ($source['take'] ?? null) {
            'winner' => __('Winner of :match', ['match' => self::matchName((string) ($source['match'] ?? ''))]),
            'loser' => __('Loser of :match', ['match' => self::matchName((string) ($source['match'] ?? ''))]),
            'group-rank' => __(':place. of group :group', ['place' => (int) ($source['rank'] ?? 1), 'group' => self::letter((int) ($source['group'] ?? 1))]),
            'rank' => __(':place. of :match', ['place' => (int) ($source['rank'] ?? 1), 'match' => self::matchName((string) ($source['match'] ?? ''))]),
            default => __('open'),
        };
    }

    /** Group 1 is A, group 2 B (26 groups at most). */
    private static function letter(int $group): string
    {
        return chr(64 + max(1, min(26, $group)));
    }

    private static function matchName(string $key): string
    {
        return strtoupper($key);
    }

    private static function number(float $value): string
    {
        if (abs($value - round($value)) < 0.001) {
            return (string) (int) round($value);
        }

        return abs($value - floor($value) - 0.5) < 0.001 ? (floor($value) > 0 ? (int) floor($value).'½' : '½') : number_format($value, 1);
    }
}
