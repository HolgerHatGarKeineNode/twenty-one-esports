<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Clan;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchSlot;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What the tournament TV shows (P19, pages::tournaments.tv), read from the
 * same stored bracket as the tournament page (TournamentView), plus what a
 * big screen needs on top: who stands behind each side (avatars, clan logos),
 * which box feeds which (the winner lines), the matches worth a spotlight,
 * the latest results for the ticker, who is still in the running for the
 * pot, and the champion.
 *
 * Only the league's own numbers: the pot comes from TournamentPrizePool and
 * its scene is left out while that returns nothing.
 */
final class TournamentTv
{
    /** @var Collection<int, TournamentParticipant>|null */
    private ?Collection $participants = null;

    /** @var array<int, User>|null */
    private ?array $users = null;

    /** @var Collection<string, TournamentMatch>|null */
    private ?Collection $matches = null;

    public function __construct(private Tournament $tournament) {}

    /**
     * Changes whenever anything the TV shows changes: the TV asks for it on
     * every poll and re-renders only when it moved.
     */
    public function version(): string
    {
        $tournament = $this->tournament;
        // Timestamps have second precision; a result entered in the second the match was paired would not move them.
        $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->orderBy('id')->get(['id', 'status', 'result'])
            ->map(fn (TournamentMatch $match): string => $match->id.':'.$match->status.':'.md5((string) json_encode($match->result)))->implode(',');
        $slots = TournamentMatchSlot::query()->whereIn('tournament_match_id', TournamentMatch::query()->select('id')->where('tournament_id', $tournament->id))
            ->orderBy('id')->pluck('tournament_participant_id', 'id')->map(fn ($id, $slot): string => $slot.':'.$id)->implode(',');

        return md5(implode('|', [
            $tournament->status->value,
            (string) $tournament->updated_at?->getTimestampMs(),
            $matches,
            $slots,
            (string) $tournament->participants()->count(),
            (string) $tournament->participants()->max('updated_at'),
            (string) $tournament->signups()->count(),
            (string) $tournament->signups()->whereNull('withdrawn_at')->count(),
            (string) $tournament->signups()->max('updated_at'),
        ]));
    }

    /**
     * The stages as TournamentView draws them, every box with the ids behind
     * its sides (`ids`), the boxes that feed it with their winner (`from`)
     * and a signature of its state (`sig`) the TV compares to spot a new
     * result.
     *
     * @return list<array<string, mixed>>
     */
    public function stages(): array
    {
        if (! in_array($this->tournament->status, [TournamentStatus::Running, TournamentStatus::Finished], true)) {
            return [];
        }

        $stages = (new TournamentView($this->tournament))->stages();

        foreach ($stages as &$stage) {
            foreach ($stage['parts'] as &$part) {
                if ($part['kind'] === 'bracket') {
                    foreach ($part['sections'] as &$section) {
                        foreach ($section['columns'] as &$column) {
                            $column['matches'] = array_map(fn (array $box): array => $this->enrich($box, $column['label']), $column['matches']);
                        }
                    }
                } elseif ($part['kind'] === 'table') {
                    foreach ($part['rounds'] as $number => &$round) {
                        $round = array_map(fn (array $box): array => $this->enrich($box, __('Round :round', ['round' => $number])), $round);
                    }

                    $part['rows'] = array_map(fn (array $row): array => $row + ['participant' => $this->byName($row['name'])], $part['rows']);
                } else {
                    // A lobby tournament's heats are its lobbies (P10): "Lobby 2", from the match key `h1-2`.
                    $lobbies = Lobbies::isLobby($this->tournament);
                    $part['heats'] = array_map(fn (array $box): array => $this->enrich($box, $lobbies
                        ? __('Lobby :number', ['number' => (int) substr((string) strrchr((string) $box['key'], '-'), 1)])
                        : __('Heat')), $part['heats']);
                }
            }
        }

        return $stages;
    }

    /**
     * The stage the bracket scene shows: the first one with a match still to
     * play, else the last one.
     *
     * @param  list<array<string, mixed>>  $stages
     * @return array<string, mixed>|null
     */
    public static function currentStage(array $stages): ?array
    {
        foreach ($stages as $stage) {
            foreach (self::boxesOf($stage) as $box) {
                if (in_array($box['status'], ['waiting', 'ready'], true) && $box['bracket'] !== 'bye') {
                    return $stage;
                }
            }
        }

        return $stages === [] ? null : $stages[count($stages) - 1];
    }

    /**
     * Every box of a stage, in drawing order.
     *
     * @param  array<string, mixed>  $stage
     * @return list<array<string, mixed>>
     */
    public static function boxesOf(array $stage): array
    {
        $boxes = [];

        foreach ($stage['parts'] as $part) {
            if ($part['kind'] === 'bracket') {
                foreach ($part['sections'] as $section) {
                    foreach ($section['columns'] as $column) {
                        array_push($boxes, ...$column['matches']);
                    }
                }
            } elseif ($part['kind'] === 'table') {
                foreach ($part['rounds'] as $round) {
                    array_push($boxes, ...$round);
                }
            } else {
                array_push($boxes, ...$part['heats']);
            }
        }

        return $boxes;
    }

    /**
     * The table round a Swiss or round-robin part shows on the bracket scene:
     * the first round with a match to play, else the last.
     *
     * @param  array<string, mixed>  $part
     * @return array{number: int, boxes: list<array<string, mixed>>}|null
     */
    public static function tableRound(array $part): ?array
    {
        $rounds = $part['rounds'];

        foreach ($rounds as $number => $round) {
            foreach ($round as $box) {
                if (in_array($box['status'], ['waiting', 'ready'], true) && $box['bracket'] !== 'bye') {
                    return ['number' => (int) $number, 'boxes' => $round];
                }
            }
        }

        if ($rounds === []) {
            return null;
        }

        $last = array_key_last($rounds);

        return ['number' => (int) $last, 'boxes' => $rounds[$last]];
    }

    /**
     * Matches worth the spotlight: up now (both sides known, waiting for the
     * result), the deciding ones first (a final, then a semifinal, then the
     * latest round).
     *
     * @param  list<array<string, mixed>>  $stages
     * @return list<array<string, mixed>>
     */
    public static function spotlight(array $stages, int $limit = 2): array
    {
        $live = [];

        foreach ($stages as $stage) {
            foreach (self::boxesOf($stage) as $index => $box) {
                if ($box['live']) {
                    $weight = match (true) {
                        in_array($box['bracket'], ['grand-final', 'reset'], true) || $box['round'] === __('Final') => 3,
                        $box['round'] === __('Semifinal') => 2,
                        default => 1,
                    };
                    $live[] = [$weight, $stage['number'], $index, $box];
                }
            }
        }

        usort($live, fn (array $a, array $b): int => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);

        return array_map(fn (array $entry): array => $entry[3], array_slice($live, 0, $limit));
    }

    /**
     * The latest results, newest first: who beat whom and the score.
     *
     * @return list<array{key: string, winner: string, loser: string|null, label: string|null, draw: bool}>
     */
    public function ticker(int $limit = 8): array
    {
        $done = $this->matches()->filter(fn (TournamentMatch $match): bool => $match->status === 'done' && $match->bracket !== 'bye' && $match->result !== null)
            ->sortByDesc(fn (TournamentMatch $match): string => ($match->updated_at?->format('Y-m-d H:i:s.u') ?? '').sprintf('%010d', $match->id))
            ->take($limit);
        $items = [];

        foreach ($done as $match) {
            $ids = $match->slots->map(fn ($slot): ?int => $slot->tournament_participant_id)->all();
            $winner = $match->result['winner'] ?? null;
            $names = array_map(fn (?int $id): string => $this->participants()->get($id ?? 0)->name ?? '?', $ids);

            if (count($names) < 2) {
                continue;
            }

            $draw = $winner === null;
            $first = $draw ? 0 : (int) $winner;
            $items[] = [
                'key' => $match->key,
                'winner' => $names[$first],
                'loser' => count($names) === 2 ? $names[1 - $first] : null,
                'label' => $match->result['label'] ?? null,
                'draw' => $draw,
            ];
        }

        return $items;
    }

    /**
     * Played and total matches, byes and matches that turned out not to be
     * needed left out.
     *
     * @return array{played: int, total: int}
     */
    public function progress(): array
    {
        $real = $this->matches()->filter(fn (TournamentMatch $match): bool => $match->bracket !== 'bye' && $match->status !== 'skipped');

        return ['played' => $real->where('status', 'done')->count(), 'total' => $real->count()];
    }

    /**
     * Who can still win something: every entry that still has a match to
     * play (in the bracket, or a table stage still running). An entry that
     * is out keeps what it has won and is not listed.
     *
     * @return list<array<string, mixed>>
     */
    public function contenders(): array
    {
        if ($this->tournament->status !== TournamentStatus::Running) {
            return [];
        }

        $alive = [];

        foreach ($this->matches() as $match) {
            if (in_array($match->status, ['waiting', 'ready'], true)) {
                foreach ($match->slots as $slot) {
                    if ($slot->tournament_participant_id !== null) {
                        $alive[(int) $slot->tournament_participant_id] = true;
                    }
                }
            }
        }

        $ids = $alive === []
            ? $this->participants()->whereNotNull('seed')->keys()->all()
            : array_keys($alive);

        $rows = array_values(array_filter(array_map(fn (int $id): ?array => $this->entry($id), $ids)));
        usort($rows, fn (array $a, array $b): int => ($a['seed'] ?? PHP_INT_MAX) <=> ($b['seed'] ?? PHP_INT_MAX));

        return $rows;
    }

    /**
     * The winner of a finished tournament, as the champion scene shows them.
     *
     * @return array<string, mixed>|null
     */
    public function champion(): ?array
    {
        $winner = app(TournamentChampion::class)->of($this->tournament);

        return $winner === null ? null : $this->entry($winner->id);
    }

    /**
     * One entry with its faces: the player, or the lineup's clan and players.
     *
     * @return array{id: int, name: string, seed: int|null, user: User|null, users: list<User>, clan: Clan|null, tag: string|null, mix: bool}|null
     */
    public function entry(?int $id): ?array
    {
        $participant = $id === null ? null : $this->participants()->get($id);

        if ($participant === null) {
            return null;
        }

        $users = array_values(array_filter(array_map(fn (int $member): ?User => $this->users()[$member] ?? null, $participant->memberIds())));

        return [
            'id' => $participant->id,
            'name' => $participant->name,
            'seed' => $participant->seed,
            'user' => $participant->lineup_id === null && ! $participant->isMixTeam() ? ($users[0] ?? null) : null,
            'users' => $users,
            'clan' => $participant->lineup?->clan,
            'tag' => $participant->lineup?->clan->clantag,
            'mix' => $participant->isMixTeam(),
        ];
    }

    /**
     * Whether the format has tables worth a standings scene.
     *
     * @param  list<array<string, mixed>>  $stages
     * @return list<array{title: string, rows: list<array<string, mixed>>, advance: int|null, swiss: bool}>
     */
    public function tables(array $stages): array
    {
        $tables = [];
        $twoStage = $this->tournament->format === TournamentFormat::TwoStage;

        foreach ($stages as $stage) {
            foreach ($stage['parts'] as $part) {
                if ($part['kind'] === 'table') {
                    $tables[] = [
                        'title' => $part['title'] ?? $stage['title'],
                        'rows' => $part['rows'],
                        'advance' => $twoStage && $stage['number'] === 1 ? $this->tournament->formatOptions()->advance : null,
                        'swiss' => $stage['format'] === TournamentFormat::Swiss,
                    ];
                }
            }
        }

        return $tables;
    }

    /**
     * @param  array<string, mixed>  $box
     * @return array<string, mixed>
     */
    private function enrich(array $box, string $round): array
    {
        $match = $this->matches()->get($box['key']);
        $ids = $match === null ? [] : $match->slots->map(fn ($slot): ?int => $slot->tournament_participant_id === null ? null : (int) $slot->tournament_participant_id)->all();
        $from = $match === null ? [] : $match->slots->map(fn ($slot): ?string => ($slot->source['take'] ?? null) === 'winner' ? (string) ($slot->source['match'] ?? '') : null)->all();

        foreach ($box['sides'] as $index => &$side) {
            $side['entry'] = $this->entry($ids[$index] ?? null);
        }

        $sides = is_array($box['sides']) ? $box['sides'] : [];
        $known = $sides !== [] && array_filter($sides, fn (array $side): bool => ! $side['known']) === [];

        return $box + [
            'ids' => $ids,
            'from' => $from,
            'round' => $round,
            'live' => $box['status'] === 'ready' && $box['bracket'] !== 'bye' && $known,
            'sig' => implode('|', [$box['status'], (string) $box['label'], implode(',', array_map(fn (?int $id): string => (string) $id, $ids))]),
        ];
    }

    /**
     * A table row's participant by name, when the name is unique in the
     * tournament (the table carries names only).
     *
     * @return array<string, mixed>|null
     */
    private function byName(string $name): ?array
    {
        $matches = $this->participants()->where('name', $name);

        return $matches->count() === 1 ? $this->entry($matches->first()->id) : null;
    }

    /**
     * @return Collection<int, TournamentParticipant>
     */
    private function participants(): Collection
    {
        return $this->participants ??= $this->tournament->participants()->with('lineup.clan')->get()->keyBy('id');
    }

    /**
     * @return array<int, User>
     */
    private function users(): array
    {
        if ($this->users === null) {
            $ids = $this->participants()->flatMap(fn (TournamentParticipant $participant): array => $participant->memberIds())->unique()->values()->all();
            $this->users = User::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
        }

        return $this->users;
    }

    /**
     * @return Collection<string, TournamentMatch>
     */
    private function matches(): Collection
    {
        return $this->matches ??= TournamentMatch::query()->where('tournament_id', $this->tournament->id)->with('slots')->orderBy('id')->get()->keyBy('key');
    }
}
