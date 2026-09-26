<?php

namespace App\Support\Tournaments;

use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Nostr\NostrLogin;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use App\Support\Nostr\SignedEventGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sign-up for a published tournament (TournamentSignup.dc.html): a captain
 * enters a lineup with the players it fields, a player enters solo (in a
 * team mode into the solo pool that the draw turns into mix teams), and
 * either can pull out until sign-up closes.
 *
 * Every step is signed by the person who takes it, in two halves like every
 * signed action here (prepareX() → the browser signs → x() rebuilds the plan
 * and checks the signed event against it, SignedEventGate). The event is a
 * NIP-98-style consent (27235) that is stored and never published: the NIP
 * keeps registration off the relays.
 *
 * Rules (open question 10, CEO defaults 2026-09-26):
 * - one entry per person: nobody is in two active entries, and a member of
 *   a clan whose lineup is entered cannot also enter solo (nor can a lineup
 *   be entered while one of its clan's members is in the solo pool);
 * - a lineup fields the mode's team size plus at most two substitutes;
 * - capacity counts player places: `capacity` teams (or players) of the
 *   mode's size; a lineup takes a team's places, a solo player one place.
 *   Solo players left over after the draw wait as substitutes.
 */
final class TournamentSignups
{
    public function __construct(private SignedEventGate $gate) {}

    /* ---------- Solo ------------------------------------------------------------------------------------------ */

    /**
     * @return list<array<string, mixed>>
     *
     * @throws TournamentRuleViolation
     */
    public function prepareSolo(Tournament $tournament, User $user): array
    {
        $this->soloPlan($tournament, $user);

        return [$this->consent($tournament, 'signup', [$user->pubkey], null)];
    }

    /**
     * @param  list<mixed>  $signed
     *
     * @throws TournamentRuleViolation|RejectedEvent
     */
    public function enterSolo(Tournament $tournament, User $user, array $signed): TournamentSignup
    {
        return $this->store($tournament, $user, $signed, function (Tournament $locked) use ($user): array {
            $this->soloPlan($locked, $user);

            return [null, $user->displayName(), [$user->id], $this->consent($locked, 'signup', [$user->pubkey], null)];
        });
    }

    private function soloPlan(Tournament $tournament, User $user): void
    {
        $this->assertOpen($tournament);
        $this->assertNotEntered($tournament, [$user->id]);

        if ($tournament->profile()->entersTeams()) {
            $clanId = ClanMember::query()->where('user_id', $user->id)->value('clan_id');
            $clanEntered = $clanId !== null && $this->active($tournament)->whereNotNull('lineup_id')
                ->whereHas('lineup', fn ($query) => $query->where('clan_id', $clanId))->exists();

            if ($clanEntered) {
                throw new TournamentRuleViolation('clan_entered', __('Your clan has entered a lineup already. One entry per person: you play with your clan or not at all.'));
            }
        }

        $this->assertCapacity($tournament, 1);
    }

    /* ---------- Lineup ---------------------------------------------------------------------------------------- */

    /**
     * @param  list<int>  $memberIds
     * @return list<array<string, mixed>>
     *
     * @throws TournamentRuleViolation
     */
    public function prepareLineup(Tournament $tournament, User $captain, int $lineupId, array $memberIds): array
    {
        [$lineup, $members] = $this->lineupPlan($tournament, $captain, $lineupId, $memberIds);

        return [$this->consent($tournament, 'signup', array_values($members->map(fn (LineupSeat $seat): string => $seat->user->pubkey)->all()), $lineup)];
    }

    /**
     * @param  list<int>  $memberIds
     * @param  list<mixed>  $signed
     *
     * @throws TournamentRuleViolation|RejectedEvent
     */
    public function enterLineup(Tournament $tournament, User $captain, int $lineupId, array $memberIds, array $signed): TournamentSignup
    {
        return $this->store($tournament, $captain, $signed, function (Tournament $locked) use ($captain, $lineupId, $memberIds): array {
            [$lineup, $members] = $this->lineupPlan($locked, $captain, $lineupId, $memberIds);
            $pubkeys = array_values($members->map(fn (LineupSeat $seat): string => $seat->user->pubkey)->all());

            return [$lineup->id, $lineup->clan->name, array_values($members->map(fn (LineupSeat $seat): int => $seat->user_id)->all()), $this->consent($locked, 'signup', $pubkeys, $lineup)];
        });
    }

    /**
     * @param  list<int>  $memberIds
     * @return array{0: Lineup, 1: Collection<int, LineupSeat>}
     */
    private function lineupPlan(Tournament $tournament, User $captain, int $lineupId, array $memberIds): array
    {
        $this->assertOpen($tournament);

        if (! $tournament->profile()->entersTeams()) {
            throw new TournamentRuleViolation('solo_only', __('Players enter this tournament on their own.'));
        }

        $lineup = Lineup::query()->with(['clan', 'seats.user.clanMember'])->find($lineupId);

        if ($lineup === null || $lineup->game !== $tournament->game || $lineup->mode !== $tournament->mode) {
            throw new TournamentRuleViolation('lineup_mode', __('Pick a lineup of this game and mode.'));
        }

        if (! $lineup->isActingCaptain($captain)) {
            throw new TournamentRuleViolation('not_captain', __('Only a captain of the lineup can enter it.'));
        }

        $ids = array_values(array_unique(array_map(intval(...), $memberIds)));
        $members = collect($lineup->activeSeats())->filter(fn (LineupSeat $seat): bool => in_array($seat->user_id, $ids, true))->values();

        if ($members->count() !== count($ids)) {
            throw new TournamentRuleViolation('member_foreign', __('Only active players of this lineup can be entered.'));
        }

        if ($members->count() < $tournament->teamSize() || $members->count() > $tournament->maxLineupSize()) {
            throw new TournamentRuleViolation('lineup_size', __('Enter :min to :max players: the team and up to :subs substitutes.', [
                'min' => $tournament->teamSize(), 'max' => $tournament->maxLineupSize(), 'subs' => Tournament::SUBSTITUTES,
            ]));
        }

        if ($this->active($tournament)->whereHas('lineup', fn ($query) => $query->where('clan_id', $lineup->clan_id))->exists()) {
            throw new TournamentRuleViolation('clan_entered', __('This clan has entered a lineup already.'));
        }

        $clanMembers = ClanMember::query()->where('clan_id', $lineup->clan_id)->pluck('user_id')->all();
        $soloClanMember = $this->active($tournament)->whereNull('lineup_id')->get()
            ->first(fn (TournamentSignup $signup): bool => array_intersect($signup->members, $clanMembers) !== []);

        if ($soloClanMember !== null) {
            throw new TournamentRuleViolation('member_solo', __(':name of your clan is signed up solo. One entry per person: they have to pull out first.', ['name' => $soloClanMember->name]));
        }

        $this->assertNotEntered($tournament, $ids);
        $this->assertCapacity($tournament, $tournament->teamSize());

        return [$lineup, $members];
    }

    /* ---------- Withdraw -------------------------------------------------------------------------------------- */

    /**
     * @return list<array<string, mixed>>
     *
     * @throws TournamentRuleViolation
     */
    public function prepareWithdraw(Tournament $tournament, User $user): array
    {
        $signup = $this->withdrawPlan($tournament, $user);

        return [$this->consent($tournament, 'withdraw', $this->pubkeys($signup), $signup->lineup)];
    }

    /**
     * @param  list<mixed>  $signed
     *
     * @throws TournamentRuleViolation|RejectedEvent
     */
    public function withdraw(Tournament $tournament, User $user, array $signed): void
    {
        $signup = $this->withdrawPlan($tournament, $user);
        $event = $this->verify($signed, $this->consent($tournament, 'withdraw', $this->pubkeys($signup), $signup->lineup), $user);

        DB::transaction(function () use ($signup, $event): void {
            $stored = NostrEvent::fromSigned($event);
            $updated = TournamentSignup::query()->whereKey($signup->id)->whereNull('withdrawn_at')
                ->update(['withdrawn_at' => now(), 'withdraw_event_id' => $stored->id]);

            if ($updated !== 1) {
                throw new TournamentRuleViolation('not_entered', __('You are not signed up.'));
            }
        });
    }

    /**
     * The entry this user can pull out: their own solo entry, or the lineup
     * entry of a lineup they captain.
     */
    private function withdrawPlan(Tournament $tournament, User $user): TournamentSignup
    {
        $this->assertOpen($tournament);

        $signup = $this->entryOf($tournament, $user);

        if ($signup === null) {
            throw new TournamentRuleViolation('not_entered', __('You are not signed up.'));
        }

        if (! $signup->isSolo() && ! ($signup->lineup?->isActingCaptain($user) ?? false)) {
            throw new TournamentRuleViolation('not_captain', __('Only a captain of the lineup can pull it out.'));
        }

        return $signup;
    }

    /* ---------- Reading --------------------------------------------------------------------------------------- */

    /**
     * The active entry this user is part of (solo or as a lineup member), or,
     * for a captain, the entry of their clan's lineup.
     */
    public function entryOf(Tournament $tournament, User $user): ?TournamentSignup
    {
        $signups = $this->active($tournament)->with(['lineup.clan', 'lineup.seats.user.clanMember'])->get();

        return $signups->first(fn (TournamentSignup $signup): bool => in_array($user->id, $signup->members, true))
            ?? $signups->first(fn (TournamentSignup $signup): bool => $signup->lineup?->isActingCaptain($user) ?? false);
    }

    /**
     * Player places taken and available (capacity × team size).
     *
     * @return array{taken: int, places: int, lineups: int, solos: int}
     */
    public function places(Tournament $tournament): array
    {
        $size = $tournament->teamSize();
        $lineups = $this->active($tournament)->whereNotNull('lineup_id')->count();
        $solos = $this->active($tournament)->whereNull('lineup_id')->count();

        return ['taken' => $lineups * $size + $solos, 'places' => $tournament->capacity * $size, 'lineups' => $lineups, 'solos' => $solos];
    }

    /* ---------- Helpers --------------------------------------------------------------------------------------- */

    /**
     * @return Builder<TournamentSignup>
     */
    private function active(Tournament $tournament): Builder
    {
        return TournamentSignup::query()->where('tournament_id', $tournament->id)->active();
    }

    private function assertOpen(Tournament $tournament): void
    {
        if (! $tournament->isSignupOpen()) {
            throw new TournamentRuleViolation('signup_closed', __('Sign-up for this tournament is closed.'));
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    private function assertNotEntered(Tournament $tournament, array $userIds): void
    {
        foreach ($this->active($tournament)->get() as $signup) {
            if (array_intersect($signup->members, $userIds) !== []) {
                throw new TournamentRuleViolation('already_entered', __('One entry per person: a player here is signed up already (:entry).', ['entry' => $signup->name]));
            }
        }
    }

    private function assertCapacity(Tournament $tournament, int $needed): void
    {
        $places = $this->places($tournament);

        if ($places['places'] < $places['taken'] + $needed) {
            throw new TournamentRuleViolation('full', __('This tournament is full.'));
        }
    }

    /**
     * @return list<string>
     */
    private function pubkeys(TournamentSignup $signup): array
    {
        $pubkeys = User::query()->whereIn('id', $signup->members)->pluck('pubkey', 'id');

        return array_values(array_filter(array_map(fn (int $id): ?string => $pubkeys[$id] ?? null, $signup->members)));
    }

    /**
     * The consent to sign: a NIP-98-style event for the tournament page,
     * naming the tournament, the action, the lineup and the players entered.
     *
     * @param  list<string>  $pubkeys
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     */
    private function consent(Tournament $tournament, string $action, array $pubkeys, ?Lineup $lineup): array
    {
        $tags = [
            ['u', route('tournaments.show', $tournament)],
            ['method', 'POST'],
            ['a', (string) $tournament->address(), ''],
            ['action', $action],
        ];

        if ($lineup !== null) {
            $tags[] = ['lineup', $lineup->address()];
        }

        foreach ($pubkeys as $pubkey) {
            $tags[] = ['p', $pubkey];
        }

        $what = $action === 'signup' ? 'Sign-up' : 'Withdrawal';
        $tags[] = ['alt', "Tournament {$what}: {$tournament->name}"];

        return [
            'kind' => NostrLogin::KIND,
            'tags' => $tags,
            'content' => $action === 'signup'
                ? "I enter {$tournament->name} and accept its rules."
                : "I pull out of {$tournament->name}.",
            'created_at' => now()->getTimestamp(),
        ];
    }

    /**
     * Check the signed consent against the rebuilt plan and store the entry,
     * under a lock on the tournament so capacity and one-entry-per-person hold
     * against a concurrent sign-up.
     *
     * @param  list<mixed>  $signed
     * @param  callable(Tournament): array{0: int|null, 1: string, 2: list<int>, 3: array{kind: int, tags: list<list<string>>, content: string, created_at: int}}  $plan
     */
    private function store(Tournament $tournament, User $user, array $signed, callable $plan): TournamentSignup
    {
        return DB::transaction(function () use ($tournament, $user, $signed, $plan): TournamentSignup {
            $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($tournament->id);
            [$lineupId, $name, $members, $template] = $plan($locked);
            $event = $this->verify($signed, $template, $user);

            return TournamentSignup::query()->create([
                'tournament_id' => $locked->id,
                'user_id' => $user->id,
                'lineup_id' => $lineupId,
                'name' => mb_substr($name, 0, 80),
                'members' => $members,
                'event_id' => NostrEvent::fromSigned($event)->id,
            ]);
        });
    }

    /**
     * @param  list<mixed>  $signed
     * @param  array{kind: int, tags: list<list<string>>, content: string, created_at: int}  $template
     *
     * @throws RejectedEvent
     */
    private function verify(array $signed, array $template, User $author): SignedEvent
    {
        if (count($signed) !== 1) {
            throw new RejectedEvent('event_count');
        }

        return $this->gate->check($signed[0], $template, $author);
    }
}
