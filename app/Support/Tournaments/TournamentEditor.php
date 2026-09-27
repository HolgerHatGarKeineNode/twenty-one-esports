<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Lineup;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Series\Ladders;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Editing a tournament (the edit page): an admin on every tournament, an
 * organizer on their own (gate `manage-tournament`).
 *
 * Before the draw (draft or sign-up) everything the create page set can
 * change: name, start, sign-up close, capacity, results mode and directors,
 * game and mode, and the format with its options. More sign-ups than
 * expected may need another system, and a wrong game pick needs a fix:
 *
 * - capacity is checked against the entries: it never drops below the
 *   places they take, and no entry is ever dropped by it;
 * - a game or mode correction removes the clan lineups entered for the old
 *   game or mode only after an explicit confirmation (they are listed
 *   first, {@see incompatible()}); their players are notified and every
 *   removal is logged. Solo entries stay: every mode takes solo players;
 * - the format options are normalized against the new game's profile
 *   (FormatOptions::fromArray): a best-of the game does not offer falls back
 *   to its default;
 * - the frozen ladder of a published tournament belongs to its old game.
 *   A correction re-derives it from the first publish time: the new game
 *   and mode's ladder if a ladder was open then (the same season), else
 *   none, so the tournament is unrated for its whole run (fail closed, NIP
 *   "Tournaments", rev. 8.1).
 *
 * From the draw on (draw pending, running) only the name, the description
 * and the start change: the draw committed to a Bitcoin block with these entries and this
 * format, so the format, game and capacity are locked. There is no redo of
 * the draw: the league would pick among block hashes it has seen, which is
 * exactly what the commitment rules out (NIP "Tournament Draw"). A finished
 * or called-off tournament does not change.
 *
 * Every change to a published tournament republishes its `31923` and the
 * league calendar (TournamentPublisher::republish); the address never
 * changes. Every change is logged in the moderation log.
 */
final class TournamentEditor
{
    /** Fields that may still change after the draw. */
    public const AFTER_DRAW = ['name', 'description', 'starts_at'];

    /** Why a lineup is removed by a game correction (English key, translated for each player). */
    public const GAME_CORRECTED = 'The game or mode of the tournament was corrected.';

    /** What an entrant agreed to with their consent: a change asks every entry to confirm again. */
    public const CONSENT_FIELDS = ['results_mode' => 'Results', 'directors' => 'Tournament directors', 'game' => 'Game', 'mode' => 'Mode', 'format' => 'Format'];

    public function __construct(private TournamentPublisher $publisher, private TournamentModeration $moderation) {}

    /**
     * @param  array{name?: string, description?: string|null, starts_at?: CarbonImmutable, signup_closes_at?: CarbonImmutable, capacity?: int, results_mode?: TournamentResultsMode, director_ids?: list<int>, game?: string, mode?: string, format?: TournamentFormat, options?: array<string, mixed>, time_window?: int, on_site?: bool, stations?: int|null, times?: array<string, float>|null}  $changes
     * @return list<string> the fields that changed
     *
     * @throws TournamentRuleViolation
     */
    public function update(Tournament $tournament, User $actor, array $changes, bool $removeIncompatible = false): array
    {
        if (! Gate::forUser($actor)->allows('manage-tournament', $tournament)) {
            throw new TournamentRuleViolation('not_manager', __('Only the organizer of this tournament or an admin can do this.'));
        }

        [$changed, $removed, $reconfirm, $consentFields] = DB::transaction(function () use ($tournament, $actor, $changes, $removeIncompatible): array {
            $locked = Tournament::query()->with(['event', 'directors'])->lockForUpdate()->findOrFail($tournament->id);
            $diff = $this->diff($locked, $changes);

            if ($diff === []) {
                return [[], [], [], []];
            }

            $this->assertAllowed($locked, $diff);
            $this->validate($locked, $changes, $diff);

            $game = $changes['game'] ?? $locked->game;
            $mode = $changes['mode'] ?? $locked->mode;
            $gameChanged = $game !== $locked->game || $mode !== $locked->mode;
            $incompatible = $gameChanged ? $this->incompatible($locked, $game, $mode) : collect();

            if ($incompatible->isNotEmpty() && ! $removeIncompatible) {
                throw new TournamentRuleViolation('incompatible', __('These lineups were entered for the old game or mode: :entries. Confirm their removal to change the game.', [
                    'entries' => $incompatible->pluck('name')->implode(', '),
                ]));
            }

            $this->assertCapacity($locked, $game, $mode, (int) ($changes['capacity'] ?? $locked->capacity), $incompatible);

            $directorsChanged = isset($diff['director_ids']);
            unset($diff['director_ids']);
            $old = $locked->only(array_keys($diff));
            $oldLadder = $locked->ladder_address;

            $locked->forceFill(array_intersect_key($changes, $diff));

            // The options of the (new) game's profile: anything it does not offer falls back to its default.
            $locked->options = FormatOptions::fromArray($locked->options, $locked->profile())->toArray();

            if ($gameChanged && $locked->event_id !== null) {
                $locked->ladder_address = Ladders::address($game, $mode, $locked->published_at?->toImmutable());
            }

            $locked->save();

            $details = [];

            foreach (array_keys($diff) as $field) {
                $details[$field] = $this->logged($this->plain($old[$field] ?? null), $this->plain($locked->{$field}));
            }

            if ($directorsChanged) {
                $details['directors'] = $this->syncDirectors($locked, $actor, $changes['director_ids'] ?? []);
            }

            if ($gameChanged) {
                $details['ladder'] = [$oldLadder, $locked->ladder_address];
            }

            $this->moderation->log($locked, $actor, 'edited', details: $details);
            $removed = $this->moderation->removeLocked($locked, $actor, $incompatible, self::GAME_CORRECTED);
            [$reconfirm, $consentFields] = $this->askForReconfirm($locked, $actor, array_keys($details));

            if ($locked->status !== TournamentStatus::Draft) {
                $this->publisher->republish($locked);
            }

            return [array_keys($details), $removed, $reconfirm, $consentFields];
        });

        if ($removed !== []) {
            $this->moderation->notifyRemoved($tournament->refresh(), $removed, self::GAME_CORRECTED);
        }

        if ($reconfirm !== []) {
            $this->moderation->notifyReconfirm($tournament->refresh(), $reconfirm, $consentFields);
        }

        return $changed;
    }

    /**
     * A change to what an entrant agreed to (results mode, directors, game
     * or mode, format) while entries exist: their consents (22150) name the
     * superseded version. Every active entry waits for a new consent against
     * the current version (TournamentSignups::reconfirm); one still waiting
     * when sign-up closes is dropped (TournamentDraws::close). Returns who to
     * notify and the English labels of what changed.
     *
     * @param  list<string>  $changed
     * @return array{0: list<int>, 1: list<string>}
     */
    private function askForReconfirm(Tournament $locked, User $actor, array $changed): array
    {
        $fields = array_values(array_intersect_key(self::CONSENT_FIELDS, array_flip($changed)));

        if ($fields === [] || $locked->status !== TournamentStatus::Signup) {
            return [[], []];
        }

        $entries = TournamentSignup::query()->where('tournament_id', $locked->id)->active()->get();

        if ($entries->isEmpty()) {
            return [[], []];
        }

        TournamentSignup::query()->whereKey($entries->modelKeys())->update(['reconfirm_since' => now(), 'reconfirm_event_id' => null]);
        $this->moderation->log($locked, $actor, 'reconfirm', subject: trans_choice(':count entry|:count entries', $entries->count()), reason: implode(', ', $fields));

        return [array_values(array_unique(array_merge(...$entries->map(fn (TournamentSignup $signup): array => TournamentModeration::entrants($signup))->all()))), $fields];
    }

    /**
     * Active lineup entries that no longer fit the game and mode: a lineup
     * of another game or mode, or any lineup where players enter alone.
     *
     * @return Collection<int, TournamentSignup>
     */
    public function incompatible(Tournament $tournament, string $game, string $mode): Collection
    {
        $entersTeams = GameProfile::for($game, $mode)->entersTeams();

        return TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->whereNotNull('lineup_id')
            ->with('lineup')->orderBy('id')->get()
            ->filter(fn (TournamentSignup $signup): bool => ! $entersTeams || ! $signup->lineup instanceof Lineup
                || $signup->lineup->game !== $game || $signup->lineup->mode !== $mode)
            ->values();
    }

    /**
     * The fields whose value differs from the stored one.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, true>
     */
    private function diff(Tournament $tournament, array $changes): array
    {
        $diff = [];

        foreach ($changes as $field => $value) {
            $current = $field === 'director_ids'
                ? $tournament->directors->pluck('id')->sort()->values()->all()
                : $tournament->{$field};

            if ($field === 'director_ids') {
                $value = array_values(array_unique(array_filter(array_map(intval(...), (array) $value), fn (int $id): bool => $id !== $tournament->created_by_id)));
                sort($value);
            }

            [$current, $value] = [$this->plain($current), $this->plain($value)];

            // Stored JSON gives an option back as 1 where the chooser sends 1.0: keyed lists compare by value.
            if (is_array($current) && is_array($value) ? $current != $value : $current !== $value) {
                $diff[$field] = true;
            }
        }

        return $diff;
    }

    /**
     * @param  array<string, true>  $diff
     */
    private function assertAllowed(Tournament $tournament, array $diff): void
    {
        if (in_array($tournament->status, [TournamentStatus::Finished, TournamentStatus::Cancelled], true)) {
            throw new TournamentRuleViolation('ended', __('This tournament has ended; it can no longer be changed.'));
        }

        if (! $tournament->isBeforeDraw() && array_diff(array_keys($diff), self::AFTER_DRAW) !== []) {
            throw new TournamentRuleViolation('locked', __('The draw has run: format, game, capacity and sign-up are locked. Only the name, the description and the start time can change.'));
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, true>  $diff
     */
    private function validate(Tournament $tournament, array $changes, array $diff): void
    {
        if (isset($diff['name']) && (trim((string) $changes['name']) === '' || mb_strlen((string) $changes['name']) > 80)) {
            throw new TournamentRuleViolation('name', __('Give the tournament a name of up to 80 characters.'));
        }

        if (isset($diff['description']) && mb_strlen((string) $changes['description']) > 1000) {
            throw new TournamentRuleViolation('description', __('Keep the description to 1000 characters.'));
        }

        $startsAt = $changes['starts_at'] ?? $tournament->starts_at;

        if (isset($diff['starts_at']) && $tournament->status !== TournamentStatus::Running && $startsAt->isPast()) {
            throw new TournamentRuleViolation('start', __('Pick a start in the future.'));
        }

        if (isset($diff['signup_closes_at']) && $tournament->status !== TournamentStatus::Signup) {
            throw new TournamentRuleViolation('deadline', __('Sign-up closes are set when the tournament is published.'));
        }

        $closesAt = $changes['signup_closes_at'] ?? $tournament->signup_closes_at;

        if ($tournament->status === TournamentStatus::Signup && (isset($diff['signup_closes_at']) || isset($diff['starts_at']))
            && ($closesAt === null || ! $closesAt->isFuture() || $closesAt->greaterThan($startsAt))) {
            throw new TournamentRuleViolation('deadline', __('Sign-up has to close in the future, at the latest when the tournament starts.'));
        }

        if (isset($diff['capacity']) && ((int) $changes['capacity'] < 2 || (int) $changes['capacity'] > 64)) {
            throw new TournamentRuleViolation('capacity', __('Plan for 2 to 64 entries.'));
        }

        if ((isset($diff['game']) || isset($diff['mode'])) && TournamentGames::keyOf($changes['game'] ?? $tournament->game, $changes['mode'] ?? $tournament->mode) === null) {
            throw new TournamentRuleViolation('game', __('Pick a game and mode the league runs tournaments in.'));
        }
    }

    /**
     * The entries that stay after a game correction must fit: capacity never
     * drops below the places they take.
     *
     * @param  Collection<int, TournamentSignup>  $leaving
     */
    private function assertCapacity(Tournament $tournament, string $game, string $mode, int $capacity, Collection $leaving): void
    {
        $profile = GameProfile::for($game, $mode);
        $size = $profile->entersTeams() ? (int) app(GameRegistry::class)->mode($game, $mode)?->teamSize : 1;
        $staying = TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->whereNotIn('id', $leaving->pluck('id'))->get();
        $taken = $staying->whereNotNull('lineup_id')->count() * $size + $staying->whereNull('lineup_id')->count();

        if ($taken > $capacity * $size) {
            throw new TournamentRuleViolation('capacity_taken', __(':taken places are taken. Keep room for at least :min entries, or remove entries first.', [
                'taken' => $taken,
                'min' => (int) ceil($taken / max(1, $size)),
            ]));
        }
    }

    /**
     * Named directors as picked: a removed one is detached, a new one is
     * attached with the editor as appointer; an existing one keeps its
     * recorded appointer (the appointment chain decides who has an interest,
     * TournamentInterest).
     *
     * @param  list<int>  $ids
     * @return array{0: list<string>, 1: list<string>}
     */
    private function syncDirectors(Tournament $tournament, User $actor, array $ids): array
    {
        $before = $tournament->directors->pluck('name', 'id');
        $wanted = User::query()->whereKey($ids)->whereKeyNot((int) $tournament->created_by_id)->pluck('id')->all();

        $tournament->directors()->detach(array_values(array_diff($before->keys()->all(), $wanted)));
        $tournament->directors()->attach(array_values(array_diff($wanted, $before->keys()->all())), ['added_by_id' => $actor->id]);
        $tournament->unsetRelation('directors');

        $name = fn (User $user): string => $user->displayName();

        return [
            array_values(User::query()->whereKey($before->keys())->get()->map($name)->all()),
            array_values($tournament->directors()->get()->map($name)->all()),
        ];
    }

    /**
     * Old and new value for the log; of a keyed list (options, times) only
     * the entries that differ, as `key: value`.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function logged(mixed $old, mixed $new): array
    {
        if (! is_array($old) && ! is_array($new)) {
            return [$old, $new];
        }

        $old = is_array($old) ? $old : [];
        $new = is_array($new) ? $new : [];
        $line = fn (string $key, mixed $value): string => $key.': '.(is_scalar($value) ? var_export($value, true) : json_encode($value));
        $keys = array_values(array_filter(array_unique([...array_keys($old), ...array_keys($new)]), fn (int|string $key): bool => ($old[$key] ?? null) != ($new[$key] ?? null)));

        return [
            array_map(fn (int|string $key): string => $line((string) $key, $old[$key] ?? null), array_values(array_filter($keys, fn (int|string $key): bool => array_key_exists($key, $old)))),
            array_map(fn (int|string $key): string => $line((string) $key, $new[$key] ?? null), array_values(array_filter($keys, fn (int|string $key): bool => array_key_exists($key, $new)))),
        ];
    }

    /**
     * A value as the log and the comparison see it.
     */
    private function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof CarbonInterface => $value->copy()->utc()->format('Y-m-d H:i'),
            $value instanceof BackedEnum => $value->value,
            is_array($value) => $value,
            is_float($value) => $value,
            is_numeric($value) && ! is_string($value) => (int) $value,
            default => $value,
        };
    }
}
