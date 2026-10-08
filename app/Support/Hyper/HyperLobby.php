<?php

namespace App\Support\Hyper;

use App\Enums\HyperMatchStatus;
use App\Events\HyperLobbyUpdated;
use App\Events\HyperRematchUpdated;
use App\Events\HyperTableStarted;
use App\Jobs\FillHyperTable;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\HyperTable;
use App\Models\HyperTableSeat;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Hyperbitcoinization lobby (plan "Hyperbitcoinization", P3): tables of 2 to 6 seats that wait for
 * players, then start one match through HyperMatches::create(). Every change runs in a transaction on the
 * table row locked for update (the board lobby's pattern), and the lobby hears about it after commit on the
 * public `hyper.lobby` channel (HyperLobbyUpdated, no data: the page renders again).
 *
 * - open(): a player sets a table up (seats, live or correspondence, round limit) and takes its first seat.
 * - join() / pick() / leave(): a player takes the first free seat, chooses a faction nobody at the table has
 *   (or none: drawn at the start), or gets up again. The creator getting up closes the table.
 * - fillBots(): the creator gives every free seat to a bot. A live table does so by itself
 *   `esports.hyper.lobby_fill_seconds` after it opened (FillHyperTable, and the `hyper:check-clocks` sweep).
 * - A full table starts at once, seats in their order; its players hear it on their own channel
 *   (HyperTableStarted), and the lobby opens the match in a new tab.
 * - rematch(): after a match, the same lineup at a new table with a new seed; bots and seats whose player
 *   left are bots, every other player says yes first (`ready`). The table's players hear each yes on the
 *   old match's channels (HyperRematchUpdated), and the url once it starts.
 *
 * - A clan table (P4, `clans`): 4 or 6 seats in two sides seated alternately (seats 0, 2, 4 and 1, 3, 5),
 *   each side one clan (HyperTeams: a clan linked to a meetup plays as that meetup). The creator's clan
 *   takes side 0; the first player of another clan sets side 1. Only members of a side's clan sit there;
 *   bots fill free seats of either side and play for it. When the last player of side 1 gets up, the side
 *   is open for any other clan again.
 *
 * A player waits at one lobby table at a time. Refusals are HyperRuleViolations: `table_closed`,
 * `table_full`, `faction_taken`, `already_seated`, `seated_elsewhere`, `not_seated`, `not_creator`,
 * `no_rematch` (the match is not over, or the user did not play it), and at a clan table `no_clan` (the
 * player is in no clan), `not_your_clan` (both sides belong to other clans), `side_full`.
 */
final class HyperLobby
{
    public function __construct(private HyperMatches $matches) {}

    /**
     * @throws HyperRuleViolation `seated_elsewhere`, `bad_table`, `faction_taken`
     */
    public function open(User $creator, int $seats, string $mode, int $limit, ?string $faction = null, bool $clans = false): HyperTable
    {
        if ($seats < 2 || $seats > 6 || ! in_array($mode, [HyperMatch::LIVE, HyperMatch::CORRESPONDENCE], true) || ! in_array($limit, HyperGame::LIMITS, true)) {
            throw new HyperRuleViolation('bad_table', 'A table has 2 to 6 seats, a mode and a known round limit.');
        }

        if ($clans && ! in_array($seats, HyperTeams::SEATS, true)) {
            throw new HyperRuleViolation('bad_table', 'A clan table has 4 or 6 seats.');
        }

        $this->checkFaction($faction);
        $clan = $clans ? (HyperTeams::clanOf($creator) ?? throw new HyperRuleViolation('no_clan', 'A clan table needs a clan.')) : null;

        $table = DB::transaction(function () use ($creator, $seats, $mode, $limit, $faction, $clan): HyperTable {
            $this->refuseSeatedElsewhere($creator);
            $live = $mode === HyperMatch::LIVE;
            $table = HyperTable::query()->create([
                'mode' => $mode,
                'seats' => $seats,
                'round_limit' => $limit,
                'team_clans' => $clan === null ? null : [$clan->id, null],
                'status' => HyperTable::OPEN,
                'created_by' => $creator->id,
                'fill_at' => $live ? now()->addSeconds($this->fillSeconds()) : null,
            ]);
            HyperTableSeat::query()->create(['hyper_table_id' => $table->id, 'seat' => 0, 'user_id' => $creator->id, 'faction' => $faction, 'ready' => true]);

            if ($live) {
                FillHyperTable::dispatch($table->id)->delay(now()->addSeconds($this->fillSeconds() + 1));
            }

            return $table;
        });

        $this->announce();

        return $table->load('takenSeats');
    }

    /**
     * The user takes the first free seat, with a faction nobody at the table has (or none yet). A table
     * full after it starts.
     *
     * @throws HyperRuleViolation `table_closed`, `already_seated`, `seated_elsewhere`, `table_full`, `faction_taken`
     */
    public function join(HyperTable $table, User $user, ?string $faction = null): HyperTable
    {
        $this->checkFaction($faction);

        return $this->change($table, function (HyperTable $table) use ($user, $faction): void {
            if ($table->rematch_of !== null) {
                throw new HyperRuleViolation('table_closed', 'A rematch takes the old lineup only.');
            }

            if ($table->seatOf($user) !== null) {
                throw new HyperRuleViolation('already_seated', 'You sit at this table already.');
            }

            $this->refuseSeatedElsewhere($user);

            $free = array_values(array_diff(range(0, $table->seats - 1), $table->takenSeats->pluck('seat')->all()));

            if ($free === []) {
                throw new HyperRuleViolation('table_full', 'Every seat is taken.');
            }

            if ($table->isTeamTable()) {
                $free = $this->freeOnSide($table, $user, $free);
            }

            $this->refuseTakenFaction($table, $faction, null);
            HyperTableSeat::query()->create(['hyper_table_id' => $table->id, 'seat' => min($free), 'user_id' => $user->id, 'faction' => $faction, 'ready' => true]);
        });
    }

    /**
     * The user's faction at the table: one nobody else there has, or null (drawn at the start).
     *
     * @throws HyperRuleViolation `table_closed`, `not_seated`, `faction_taken`
     */
    public function pick(HyperTable $table, User $user, ?string $faction): HyperTable
    {
        $this->checkFaction($faction);

        return $this->change($table, function (HyperTable $table) use ($user, $faction): void {
            $seat = $table->seatOf($user) ?? throw new HyperRuleViolation('not_seated', 'You do not sit at this table.');
            $this->refuseTakenFaction($table, $faction, $seat);
            $seat->faction = $faction;
            $seat->save();
        });
    }

    /**
     * The user gets up. The creator getting up closes the table.
     *
     * @throws HyperRuleViolation `table_closed`, `not_seated`
     */
    public function leave(HyperTable $table, User $user): HyperTable
    {
        return $this->change($table, function (HyperTable $table) use ($user): void {
            $seat = $table->seatOf($user) ?? throw new HyperRuleViolation('not_seated', 'You do not sit at this table.');

            if ((int) $table->created_by === (int) $user->id || $table->rematch_of !== null) {
                $table->status = HyperTable::CANCELLED;
                $table->save();

                return;
            }

            $seat->delete();

            // Side 1 without a player is open for any other clan again.
            if ($table->isTeamTable() && ! $table->takenSeats->contains(fn (HyperTableSeat $other): bool => $other->id !== $seat->id && $other->user_id !== null && HyperTable::sideOf($other->seat) === 1)) {
                $table->team_clans = [$table->team_clans[0] ?? null, null];
                $table->save();
            }
        });
    }

    /**
     * The creator gives every free seat to a bot, and the table starts.
     *
     * @throws HyperRuleViolation `table_closed`, `not_creator`
     */
    public function fillBots(HyperTable $table, User $user): HyperTable
    {
        return $this->change($table, function (HyperTable $table) use ($user): void {
            if ((int) $table->created_by !== (int) $user->id) {
                throw new HyperRuleViolation('not_creator', 'Only who set the table up fills it with bots.');
            }

            $this->seatBots($table);
        });
    }

    /**
     * Every open live table whose wait is over gets bots for its free seats and starts (the sweep and
     * FillHyperTable; `only` narrows it to one table). Each table on its own: one that fails is reported and
     * the others still start. Returns how many started.
     */
    public function fillDue(?int $only = null): int
    {
        $due = HyperTable::query()->where('status', HyperTable::OPEN)->where('mode', HyperMatch::LIVE)
            ->whereNull('rematch_of')->whereNotNull('fill_at')->where('fill_at', '<=', now())
            ->when($only !== null, fn ($query) => $query->whereKey($only))
            ->get();
        $started = 0;

        foreach ($due as $table) {
            try {
                $after = $this->change($table, function (HyperTable $table): void {
                    if ($table->fill_at !== null && $table->fill_at->lte(now())) {
                        $this->seatBots($table);
                    }
                });
                $started += $after->status === HyperTable::STARTED ? 1 : 0;
            } catch (HyperRuleViolation) {
                // Closed or started meanwhile.
            }
        }

        return $started;
    }

    /**
     * A rematch of a finished match: the same lineup (seat order and factions) at a new table, with a new
     * seed. The user says yes (the first yes sets the table up); bots and seats whose player left are
     * bots; once every player said yes the match starts. Returns the table and, once started, its match.
     *
     * @return array{table: HyperTable, match: HyperMatch|null}
     *
     * @throws HyperRuleViolation `no_rematch`
     */
    public function rematch(HyperMatch $old, User $user): array
    {
        $table = DB::transaction(function () use ($old, $user): HyperTable {
            $locked = HyperMatch::query()->lockForUpdate()->findOrFail($old->id);
            $locked->load('seats');
            $mine = $locked->seatOf($user);

            if ($locked->status !== HyperMatchStatus::Finished || $mine === null) {
                throw new HyperRuleViolation('no_rematch', 'Only a player of a finished match asks for a rematch.');
            }

            $table = HyperTable::query()->where('rematch_of', $locked->id)->lockForUpdate()->first();

            if ($table === null) {
                $table = HyperTable::query()->create([
                    'mode' => $locked->mode,
                    'seats' => $locked->seats->count(),
                    'round_limit' => $locked->round_limit,
                    'team_clans' => $locked->team_clans,
                    'status' => HyperTable::OPEN,
                    'created_by' => $user->id,
                    'rematch_of' => $locked->id,
                ]);

                foreach ($locked->seats as $seat) {
                    $player = $seat->user_id !== null && ($seat->seat === $mine->seat || ! in_array($seat->takeover, [HyperSeat::TAKEOVER_LEFT, HyperSeat::TAKEOVER_FORFEIT], true));
                    HyperTableSeat::query()->create([
                        'hyper_table_id' => $table->id,
                        'seat' => $seat->seat,
                        'user_id' => $player ? $seat->user_id : null,
                        'bot' => ! $player,
                        'faction' => $seat->faction,
                        'ready' => ! $player,
                    ]);
                }
            }

            $table->load('takenSeats');

            if ($table->isOpen()) {
                $seat = $table->seatOf($user);

                if ($seat !== null && ! $seat->ready) {
                    $seat->ready = true;
                    $seat->save();
                }

                if ($table->takenSeats->every(fn (HyperTableSeat $seat): bool => $seat->ready)) {
                    $this->start($table);
                }
            }

            Broadcasts::send(new HyperRematchUpdated($locked->ulid, $this->rematchPayload($table)));

            return $table;
        });

        $table->refresh()->load('takenSeats', 'match');

        return ['table' => $table, 'match' => $table->status === HyperTable::STARTED ? $table->match : null];
    }

    /**
     * What `hyper.rematch` carries and the rematch endpoint answers: who said yes (seat indexes), who is
     * still asked, and the new match's url once it started.
     *
     * @return array{table: string, ready: list<int>, waiting: list<int>, url: string|null}
     */
    public function rematchPayload(HyperTable $table): array
    {
        $table->loadMissing('takenSeats');
        $humans = $table->takenSeats->filter(fn (HyperTableSeat $seat): bool => ! $seat->bot);

        return [
            'table' => $table->ulid,
            'ready' => array_values($humans->filter(fn (HyperTableSeat $seat): bool => $seat->ready)->pluck('seat')->all()),
            'waiting' => array_values($humans->reject(fn (HyperTableSeat $seat): bool => $seat->ready)->pluck('seat')->all()),
            'url' => $table->status === HyperTable::STARTED && $table->hyper_match_id !== null
                ? route('hyper.match', HyperMatch::query()->findOrFail($table->hyper_match_id))
                : null,
        ];
    }

    /**
     * The open lobby tables, newest first (rematches are not in the lobby).
     *
     * @return Collection<int, HyperTable>
     */
    public function openTables(int $limit = 30): Collection
    {
        return HyperTable::query()->where('status', HyperTable::OPEN)->whereNull('rematch_of')
            ->with('takenSeats.user', 'creator')->latest('id')->limit($limit)->get();
    }

    /**
     * The open lobby table the user waits at, if any.
     */
    public function tableOf(User $user): ?HyperTable
    {
        return HyperTable::query()->where('status', HyperTable::OPEN)->whereNull('rematch_of')
            ->whereHas('takenSeats', fn ($seats) => $seats->where('user_id', $user->id))->first();
    }

    /* ---------- Internals ----------------------------------------------------------------------------------- */

    /**
     * Lock the table, apply one change to an open table, start it when it is full, announce after commit.
     *
     * @param  Closure(HyperTable): void  $change
     *
     * @throws HyperRuleViolation
     */
    private function change(HyperTable $table, Closure $change): HyperTable
    {
        $locked = DB::transaction(function () use ($table, $change): HyperTable {
            $locked = HyperTable::query()->lockForUpdate()->findOrFail($table->id);
            $locked->load('takenSeats');

            if (! $locked->isOpen()) {
                throw new HyperRuleViolation('table_closed', 'This table is not open any more.');
            }

            $change($locked);
            $locked->load('takenSeats');

            if ($locked->isOpen() && $locked->freeSeats() === 0) {
                $this->start($locked);
            }

            return $locked;
        });

        $this->announce();

        return $locked->refresh()->load('takenSeats');
    }

    /**
     * Bots on every free seat (their factions drawn at the start).
     */
    private function seatBots(HyperTable $table): void
    {
        $taken = $table->takenSeats->pluck('seat')->all();

        foreach (array_diff(range(0, $table->seats - 1), $taken) as $seat) {
            HyperTableSeat::query()->create(['hyper_table_id' => $table->id, 'seat' => $seat, 'bot' => true, 'ready' => true]);
        }

        $table->load('takenSeats');
    }

    /**
     * The full table becomes a match, seats in their order; its players hear it on their own channel.
     */
    private function start(HyperTable $table): void
    {
        $table->load('takenSeats.user');
        $team = fn (HyperTableSeat $seat): array => $table->isTeamTable() ? ['team' => HyperTable::sideOf($seat->seat)] : [];
        $seats = array_values($table->takenSeats->map(fn (HyperTableSeat $seat): array => $seat->bot || $seat->user === null
            ? ['bot' => true, 'faction' => $seat->faction, ...$team($seat)]
            : ['user' => $seat->user, 'faction' => $seat->faction, ...$team($seat)])->all());
        $match = $this->matches->create($seats, $table->round_limit, creator: $table->creator()->first(), mode: $table->mode, teamClans: $table->team_clans);

        $table->forceFill(['status' => HyperTable::STARTED, 'hyper_match_id' => $match->id, 'started_at' => now(), 'fill_at' => null])->save();
        $players = array_values(array_filter($table->takenSeats->map(fn (HyperTableSeat $seat): ?int => $seat->bot ? null : $seat->user_id)->all()));

        if ($players !== []) {
            Broadcasts::send(new HyperTableStarted($table->ulid, route('hyper.match', $match), $players));
        }
    }

    private function announce(): void
    {
        Broadcasts::send(new HyperLobbyUpdated);
    }

    /**
     * The free seats of the side the user's clan plays at this clan table: side 0 for the creator's clan;
     * side 1 for the clan already there, or for any other clan while side 1 has none (which sets it).
     *
     * @param  list<int>  $free
     * @return non-empty-list<int>
     *
     * @throws HyperRuleViolation `no_clan`, `not_your_clan`, `side_full`
     */
    private function freeOnSide(HyperTable $table, User $user, array $free): array
    {
        $clan = HyperTeams::clanOf($user) ?? throw new HyperRuleViolation('no_clan', 'A clan table takes clan members only.');
        [$first, $second] = [$table->team_clans[0] ?? null, $table->team_clans[1] ?? null];

        $side = match (true) {
            $clan->id === $first => 0,
            $second === null || $clan->id === $second => 1,
            default => throw new HyperRuleViolation('not_your_clan', 'Both sides of this table belong to other clans.'),
        };
        $mine = array_values(array_filter($free, fn (int $seat): bool => HyperTable::sideOf($seat) === $side));

        if ($mine === []) {
            throw new HyperRuleViolation('side_full', 'Your clan\'s side is full.');
        }

        if ($side === 1 && $second === null) {
            $table->team_clans = [$first, $clan->id];
            $table->save();
        }

        return $mine;
    }

    private function refuseSeatedElsewhere(User $user): void
    {
        if ($this->tableOf($user) !== null) {
            throw new HyperRuleViolation('seated_elsewhere', 'You wait at another table already.');
        }
    }

    private function refuseTakenFaction(HyperTable $table, ?string $faction, ?HyperTableSeat $own): void
    {
        if ($faction !== null && $table->takenSeats->contains(fn (HyperTableSeat $seat): bool => $seat->faction === $faction && $seat->id !== $own?->id)) {
            throw new HyperRuleViolation('faction_taken', 'Somebody at this table plays this faction.');
        }
    }

    private function checkFaction(?string $faction): void
    {
        if ($faction !== null && ! array_key_exists($faction, HyperGame::FACTIONS)) {
            throw new HyperRuleViolation('faction_taken', 'Unknown faction.');
        }
    }

    private function fillSeconds(): int
    {
        return max(1, (int) config('esports.hyper.lobby_fill_seconds', 120));
    }
}
