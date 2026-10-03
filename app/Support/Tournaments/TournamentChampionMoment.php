<?php

namespace App\Support\Tournaments;

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Models\Clan;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Payouts\TournamentPlacements;

/**
 * The champion moment at the top of a finished tournament page (user,
 * 2026-10-03: "Das mini Ding als Stolz-Moment für den Sieger??? NEINN!!!!!
 * Das muss krasser werden!!!!"): who won, the podium, the champion's record
 * and path, the prize paid, and what the viewer did in it.
 *
 * - `champions`: the single champion (TournamentChampion), else everybody on
 *   a shared place 1 (a lobby tournament's allies, TournamentPlacements);
 * - `podium`: places 1 to 3 as TournamentPlacements has them, ties kept;
 * - `record` and `path`: the single champion's decided matches (wins, draws,
 *   losses, the table's points when the last stage is a table) and the
 *   played ones in order (round, opponent, result, score); none for a
 *   shared place 1 or a leaderboard;
 * - `prize`: the sats the league paid to place 1, null while nothing is paid;
 * - `viewer`: `won` for a champion, `placed` with the place for anybody else
 *   who played and has one, `played` without a place, null for a spectator.
 *
 * Null while the tournament is not finished, for a league week (its own
 * board has its podium) and when no place 1 can be read. Read-only.
 */
final class TournamentChampionMoment
{
    /** Podium places shown. */
    public const PODIUM = 3;

    /** Steps of the path shown at most, the last ones. */
    public const PATH = 6;

    /**
     * @return array{champions: list<array{id: int, name: string, users: list<User>, clan: Clan|null, href: string|null}>, shared: bool, podium: list<array{place: int, entries: list<array{id: int, name: string, users: list<User>, clan: Clan|null, href: string|null}>}>, record: array{wins: int, draws: int, losses: int, points: string|null}|null, path: list<array{round: string, opponent: string, result: string, score: string|null}>, prize: int|null, viewer: array{state: string, place: int|null}|null}|null
     */
    public static function of(Tournament $tournament, ?User $viewer): ?array
    {
        if ($tournament->status !== TournamentStatus::Finished || $tournament->isLeagueWeek()) {
            return null;
        }

        $placements = app(TournamentPlacements::class)->of($tournament) ?? [];
        $champion = app(TournamentChampion::class)->of($tournament);
        $first = $placements[0] ?? null;
        $championIds = $champion !== null ? [$champion->id] : (($first['place'] ?? null) === 1 ? $first['participants'] : []);

        if ($championIds === []) {
            return null;
        }

        $tv = new TournamentTv($tournament);
        $entry = fn (int $id): ?array => self::face($tv->entry($id));
        $champions = array_values(array_filter(array_map($entry, $championIds)));

        if ($champions === []) {
            return null;
        }

        $podium = [];

        foreach ($placements as $row) {
            if ($row['place'] > self::PODIUM) {
                break;
            }

            $podium[] = ['place' => $row['place'], 'entries' => array_values(array_filter(array_map($entry, $row['participants'])))];
        }

        // A tournament whose placements cannot be read still has its champion on top.
        if ($podium === []) {
            $podium[] = ['place' => 1, 'entries' => $champions];
        }

        [$record, $path] = count($champions) === 1 ? self::record($tv, $champions[0]['id']) : [null, []];
        $prize = (int) TournamentPayout::query()->where('tournament_id', $tournament->id)->where('place', 1)->where('status', PayoutStatus::Paid)->sum('amount_sats');

        return [
            'champions' => $champions,
            'shared' => count($champions) > 1,
            'podium' => $podium,
            'record' => $record,
            'path' => $path,
            'prize' => $prize > 0 ? $prize : null,
            'viewer' => self::viewer($tournament, $viewer, $championIds, $placements),
        ];
    }

    /**
     * @param  array{id: int, name: string, user: User|null, users: list<User>, clan: Clan|null}|null  $entry
     * @return array{id: int, name: string, users: list<User>, clan: Clan|null, href: string|null}|null
     */
    private static function face(?array $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [
            'id' => $entry['id'],
            'name' => $entry['name'],
            'users' => $entry['users'],
            'clan' => $entry['clan'],
            'href' => $entry['user'] instanceof User ? route('players.show', $entry['user']->npub) : null,
        ];
    }

    /**
     * The champion's record over every decided match of two sides, and the
     * played ones as the path (the last PATH, in bracket order).
     *
     * @return array{0: array{wins: int, draws: int, losses: int, points: string|null}|null, 1: list<array{round: string, opponent: string, result: string, score: string|null}>}
     */
    private static function record(TournamentTv $tv, int $champion): array
    {
        $stages = $tv->stages();
        $record = ['wins' => 0, 'draws' => 0, 'losses' => 0, 'points' => null];
        $path = [];

        foreach ($stages as $stage) {
            foreach (TournamentTv::boxesOf($stage) as $box) {
                $index = array_search($champion, $box['ids'] ?? [], true);

                if (! is_int($index) || $box['bracket'] === 'bye' || $box['status'] !== 'done' || count($box['sides']) !== 2) {
                    continue;
                }

                $own = $box['sides'][$index];
                $other = $box['sides'][1 - $index];
                $result = match (true) {
                    (bool) $own['won'] => 'won',
                    ! $other['won'] => 'drew',
                    default => 'lost',
                };
                $record[['won' => 'wins', 'drew' => 'draws', 'lost' => 'losses'][$result]]++;

                if (($other['entry']['name'] ?? null) !== null) {
                    $path[] = [
                        'round' => (string) ($box['round'] ?? ''),
                        'opponent' => (string) $other['entry']['name'],
                        'result' => $result,
                        'score' => is_string($own['score'] ?? null) && is_string($other['score'] ?? null) ? $own['score'].'–'.$other['score'] : (is_string($box['label'] ?? null) ? $box['label'] : null),
                    ];
                }
            }
        }

        // The points of a table, when the last stage is one (round robin, Swiss; a two-stage final table).
        $last = $stages === [] ? null : $stages[count($stages) - 1];

        foreach ($last['parts'] ?? [] as $part) {
            foreach ($part['kind'] === 'table' ? $part['rows'] : [] as $row) {
                if (($row['participant']['id'] ?? null) === $champion) {
                    $record['points'] = (string) $row['points'];
                }
            }
        }

        $decided = $record['wins'] + $record['draws'] + $record['losses'];

        return [$decided === 0 && $record['points'] === null ? null : $record, array_slice($path, -self::PATH)];
    }

    /**
     * @param  list<int>  $championIds
     * @param  list<array{place: int, participants: list<int>}>  $placements
     * @return array{state: string, place: int|null}|null
     */
    private static function viewer(Tournament $tournament, ?User $viewer, array $championIds, array $placements): ?array
    {
        if ($viewer === null) {
            return null;
        }

        $mine = TournamentParticipant::query()->where('tournament_id', $tournament->id)->get()
            ->first(fn (TournamentParticipant $entry): bool => in_array($viewer->id, $entry->memberIds(), true));

        if ($mine === null) {
            return null;
        }

        if (in_array($mine->id, $championIds, true)) {
            return ['state' => 'won', 'place' => 1];
        }

        $place = collect($placements)->first(fn (array $row): bool => in_array($mine->id, $row['participants'], true))['place'] ?? null;

        return ['state' => $place === null ? 'played' : 'placed', 'place' => $place];
    }
}
