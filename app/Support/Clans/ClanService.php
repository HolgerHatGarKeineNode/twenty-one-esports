<?php

namespace App\Support\Clans;

use App\Enums\ClanRole;
use App\Enums\InviteStatus;
use App\Enums\LineupRole;
use App\Games\GameRegistry;
use App\Jobs\PublishNostrEvent;
use App\Models\Clan;
use App\Models\ClanDeparture;
use App\Models\ClanInvite;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use App\Support\Nostr\SignedEventGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Clan actions, each in two halves that share one plan:
 *
 *   prepareX(...)  -> the unsigned events the player has to sign
 *   x(..., $signed) -> the same plan rebuilt from the current state, every
 *                      signed event checked against it (SignedEventGate),
 *                      then the projection written and the events published
 *
 * Rebuilding instead of trusting a stored template means a stale browser
 * (someone else changed the clan in between) is refused, not applied.
 *
 * Protocol mapping (docs/nips/esports.md rev. 4):
 *  - clan 32150, signed by the owner, lists owner/captains/members and the
 *    players with a pending invite ("listing alone is an invitation");
 *  - lineup 32151, signed by the owner (see Lineup), published only once it
 *    lists as many captains + players as the mode needs (rule 8);
 *  - membership 12150, signed by the player: joining, switching and leaving.
 *    A 12150 names at most one clan, so a player is never in two clans; the
 *    database holds the same rule (`clan_members.user_id` unique).
 */
final class ClanService
{
    public function __construct(
        private SignedEventGate $gate,
        private GameRegistry $games,
    ) {}

    /* ---------- Create ---------------------------------------------------------------------------------------- */

    /**
     * @return list<array<string, mixed>>
     */
    public function prepareCreate(User $owner, ClanDraft $draft): array
    {
        return $this->createPlan($owner, $draft)['templates'];
    }

    /**
     * @param  list<mixed>  $signed
     *
     * @throws RejectedEvent|ClanRuleViolation
     */
    public function create(User $owner, ClanDraft $draft, array $signed): Clan
    {
        $plan = $this->createPlan($owner, $draft);
        $events = $this->verify($signed, $plan['templates'], $owner);

        return $this->persist($events, function () use ($owner, $draft, $plan, $events): Clan {
            $this->leaveCurrentClan($owner, 'switched');

            $clan = Clan::query()->create([
                'slug' => $plan['slug'],
                'owner_id' => $owner->id,
                'owner_pubkey' => $owner->pubkey,
                'name' => $draft->name,
                'clantag' => $draft->clantag,
                'description' => $draft->description,
                'picture' => $draft->picture,
                'meetup_name' => $draft->meetupName,
                'meetup_city' => $draft->meetupCity,
                'meetup_url' => $draft->meetupUrl,
                'meetup_latitude' => $draft->meetupLatitude,
                'meetup_longitude' => $draft->meetupLongitude,
                'event_id' => $events[0]->id,
            ]);

            ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $owner->id, 'role' => ClanRole::Captain, 'joined_at' => now()]);

            return $clan;
        });
    }

    /**
     * @return array{slug: string, templates: list<array<string, mixed>>}
     */
    private function createPlan(User $owner, ClanDraft $draft): array
    {
        if (Clan::query()->where('clantag', $draft->clantag)->exists()) {
            throw new ClanRuleViolation(__('The tag :tag is taken.', ['tag' => $draft->clantag]));
        }

        $this->assertCanLeave($owner);

        $slug = $this->uniqueSlug($draft->name);
        $clanAddress = Clan::KIND.':'.$owner->pubkey.':'.$slug;

        $clanTags = [['d', $slug], ['name', $draft->name], ['clantag', $draft->clantag]];

        if ($draft->picture !== null) {
            $clanTags[] = ['picture', $draft->picture];
        }

        if ($draft->meetupUrl !== null) {
            $clanTags[] = ['r', $draft->meetupUrl];
        }

        $clanTags[] = ['p', $owner->pubkey, '', ClanRole::Captain->value];
        $clanTags[] = ['alt', "Esports clan: {$draft->name} [{$draft->clantag}]"];

        return ['slug' => $slug, 'templates' => [
            $this->template(Clan::KIND, $clanTags, $draft->description ?? '', $owner, $slug),
            $this->membershipTemplate($owner, $clanAddress, []),
        ]];
    }

    /* ---------- Invite ---------------------------------------------------------------------------------------- */

    /**
     * @return list<array<string, mixed>>
     */
    public function prepareInvite(User $owner, Clan $clan, string $game, string $mode, User $invitee, LineupRole $role): array
    {
        return $this->invitePlan($owner, $clan, $game, $mode, $invitee, $role)['templates'];
    }

    /**
     * Invite a player into a lineup. The owner can also seat themselves; then
     * their own membership is part of the plan and no invite is created.
     *
     * @param  list<mixed>  $signed
     *
     * @throws RejectedEvent|ClanRuleViolation
     */
    public function invite(User $owner, Clan $clan, string $game, string $mode, User $invitee, LineupRole $role, array $signed): ?ClanInvite
    {
        $plan = $this->invitePlan($owner, $clan, $game, $mode, $invitee, $role);
        $events = $this->verify($signed, $plan['templates'], $owner);
        $byKind = $this->byKind($events);

        return $this->persist($events, function () use ($owner, $clan, $game, $mode, $invitee, $role, $byKind): ?ClanInvite {
            $lineup = Lineup::query()->firstOrCreate(['clan_id' => $clan->id, 'game' => $game, 'mode' => $mode]);
            $self = $invitee->is($owner);

            LineupSeat::query()->create([
                'lineup_id' => $lineup->id,
                'user_id' => $invitee->id,
                'role' => $role,
                'accepted_at' => $self ? now() : null,
            ]);

            if (isset($byKind[Clan::KIND])) {
                $clan->update(['event_id' => $byKind[Clan::KIND]->id]);
            }

            if (isset($byKind[Lineup::KIND])) {
                $lineup->update(['event_id' => $byKind[Lineup::KIND]->id]);
            }

            if ($self) {
                return null;
            }

            return ClanInvite::query()->create([
                'clan_id' => $clan->id,
                'lineup_id' => $lineup->id,
                'inviter_id' => $owner->id,
                'invitee_id' => $invitee->id,
                'role' => $role,
                'status' => InviteStatus::Pending,
            ]);
        });
    }

    /**
     * @return array{templates: list<array<string, mixed>>}
     */
    private function invitePlan(User $owner, Clan $clan, string $game, string $mode, User $invitee, LineupRole $role): array
    {
        $this->assertOwner($owner, $clan);

        $gameMode = $this->games->mode($game, $mode)
            ?? throw new ClanRuleViolation(__('This game mode does not exist.'));

        $lineup = Lineup::query()->where(['clan_id' => $clan->id, 'game' => $game, 'mode' => $mode])->with('seats.user')->first();
        $seats = $lineup === null ? collect() : $lineup->seats->sortBy('id')->values();
        $self = $invitee->is($owner);

        if ($seats->contains('user_id', $invitee->id)) {
            throw new ClanRuleViolation(__(':name already has a seat in this lineup.', ['name' => $invitee->displayName()]));
        }

        if (! $self && $role === LineupRole::Captain) {
            throw new ClanRuleViolation(__('Only you can take the captain seat of a lineup.'));
        }

        $templates = [];
        $listing = $this->clanListing($clan);

        if (! isset($listing[$invitee->pubkey])) {
            $listing[$invitee->pubkey] = ClanRole::Member;
            $templates[] = $this->clanTemplate($clan, $listing);
        }

        $lineupListing = array_values($seats->map(fn (LineupSeat $seat) => [$seat->user->pubkey, $seat->role])->all());
        $lineupListing[] = [$invitee->pubkey, $role];

        if ($this->countsTowardsMinimum($lineupListing) >= $gameMode->lineupMinimum()) {
            $templates[] = $this->lineupTemplate($clan, $game, $mode, $lineupListing);
        }

        if ($self) {
            $templates[] = $this->membershipTemplate($owner, $clan->address(), [...$this->acceptedLineupAddresses($owner, $clan), $this->lineupAddress($clan, $game, $mode)]);
        }

        return ['templates' => $templates];
    }

    /* ---------- Accept / decline ------------------------------------------------------------------------------ */

    /**
     * @return list<array<string, mixed>>
     */
    public function prepareAccept(ClanInvite $invite, User $player): array
    {
        return $this->acceptPlan($invite, $player);
    }

    /**
     * Join only with a valid signed Clan Membership that names the clan and
     * the lineup of the invite. Joining another clan leaves the current one.
     *
     * @param  list<mixed>  $signed
     *
     * @throws RejectedEvent|ClanRuleViolation
     */
    public function accept(ClanInvite $invite, User $player, array $signed): void
    {
        $templates = $this->acceptPlan($invite, $player);
        $events = $this->verify($signed, $templates, $player);

        $this->persist($events, function () use ($invite, $player): void {
            if ($player->clanMember()->first()?->clan_id !== $invite->clan_id) {
                $this->leaveCurrentClan($player, 'switched');

                ClanMember::query()->create(['clan_id' => $invite->clan_id, 'user_id' => $player->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
            }

            LineupSeat::query()->where(['lineup_id' => $invite->lineup_id, 'user_id' => $player->id])->update(['accepted_at' => now()]);

            $invite->update(['status' => InviteStatus::Accepted, 'responded_at' => now()]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function acceptPlan(ClanInvite $invite, User $player): array
    {
        $invite->refresh();

        if (! $invite->isPending() || $invite->invitee_id !== $player->id) {
            throw new ClanRuleViolation(__('This invite is no longer open.'));
        }

        $clan = $invite->clan;
        $lineup = $invite->lineup;
        $sameClan = $player->clanMember()->first()?->clan_id === $clan->id;

        if (! $sameClan) {
            $this->assertCanLeave($player);
        }

        $lineups = $sameClan ? $this->acceptedLineupAddresses($player, $clan) : [];
        $lineups[] = $lineup->address();

        return [$this->membershipTemplate($player, $clan->address(), array_values(array_unique($lineups)))];
    }

    public function decline(ClanInvite $invite, User $player): void
    {
        $invite->refresh();

        if (! $invite->isPending() || $invite->invitee_id !== $player->id) {
            throw new ClanRuleViolation(__('This invite is no longer open.'));
        }

        DB::transaction(function () use ($invite, $player): void {
            LineupSeat::query()->where(['lineup_id' => $invite->lineup_id, 'user_id' => $player->id])->whereNull('accepted_at')->delete();
            $invite->update(['status' => InviteStatus::Declined, 'responded_at' => now()]);
        });
    }

    /* ---------- Leave ----------------------------------------------------------------------------------------- */

    /**
     * @return list<array<string, mixed>>
     */
    public function prepareLeave(User $player): array
    {
        return $this->leavePlan($player);
    }

    /**
     * @param  list<mixed>  $signed
     *
     * @throws RejectedEvent|ClanRuleViolation
     */
    public function leave(User $player, array $signed): void
    {
        $events = $this->verify($signed, $this->leavePlan($player), $player);

        $this->persist($events, fn () => $this->leaveCurrentClan($player, 'left'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leavePlan(User $player): array
    {
        if ($player->clanMember()->first() === null) {
            throw new ClanRuleViolation(__('You are not in a clan.'));
        }

        $this->assertCanLeave($player);

        return [$this->membershipTemplate($player, null, [])];
    }

    /* ---------- Captains and removal (owner only) ------------------------------------------------------------- */

    /**
     * @return list<array<string, mixed>>
     */
    public function prepareMakeCaptain(User $owner, Clan $clan, User $member): array
    {
        return $this->makeCaptainPlan($owner, $clan, $member);
    }

    /**
     * @param  list<mixed>  $signed
     *
     * @throws RejectedEvent|ClanRuleViolation
     */
    public function makeCaptain(User $owner, Clan $clan, User $member, array $signed): void
    {
        $events = $this->verify($signed, $this->makeCaptainPlan($owner, $clan, $member), $owner);

        $this->persist($events, function () use ($clan, $member, $events): void {
            ClanMember::query()->where(['clan_id' => $clan->id, 'user_id' => $member->id])->update(['role' => ClanRole::Captain]);
            $clan->update(['event_id' => $events[0]->id]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function makeCaptainPlan(User $owner, Clan $clan, User $member): array
    {
        $this->assertOwner($owner, $clan);
        $membership = ClanMember::query()->where(['clan_id' => $clan->id, 'user_id' => $member->id])->first();

        if ($membership === null || $membership->role === ClanRole::Captain) {
            throw new ClanRuleViolation(__(':name cannot be made captain.', ['name' => $member->displayName()]));
        }

        $listing = $this->clanListing($clan);
        $listing[$member->pubkey] = ClanRole::Captain;

        return [$this->clanTemplate($clan, $listing)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function prepareRemove(User $owner, Clan $clan, User $member): array
    {
        return $this->removePlan($owner, $clan, $member)['templates'];
    }

    /**
     * Remove a player: a new clan event without them, and every lineup they sat
     * in signed again without them. A lineup that falls below the mode's size
     * cannot be signed (rule 8); it loses its event and stays incomplete until
     * seats are filled.
     *
     * @param  list<mixed>  $signed
     *
     * @throws RejectedEvent|ClanRuleViolation
     */
    public function remove(User $owner, Clan $clan, User $member, array $signed): void
    {
        $plan = $this->removePlan($owner, $clan, $member);
        $events = $this->verify($signed, $plan['templates'], $owner);

        $this->persist($events, function () use ($clan, $member, $plan, $events): void {
            $clan->update(['event_id' => $events[0]->id]);

            $lineupEvents = [];

            foreach ($events as $event) {
                if ($event->kind === Lineup::KIND) {
                    $lineupEvents[(string) $event->tag('d')] = $event->id;
                }
            }

            foreach ($plan['lineups'] as $lineup) {
                $lineup->update(['event_id' => $lineupEvents[$lineup->d()] ?? null]);
            }

            ClanInvite::query()->where(['clan_id' => $clan->id, 'invitee_id' => $member->id, 'status' => InviteStatus::Pending])
                ->update(['status' => InviteStatus::Withdrawn, 'responded_at' => now()]);

            $this->leaveCurrentClan($member, 'removed');
        });
    }

    /**
     * @return array{templates: list<array<string, mixed>>, lineups: list<Lineup>}
     */
    private function removePlan(User $owner, Clan $clan, User $member): array
    {
        $this->assertOwner($owner, $clan);

        if ($member->is($owner)) {
            throw new ClanRuleViolation(__('Leave the clan instead of removing yourself.'));
        }

        $listing = $this->clanListing($clan);

        if (! isset($listing[$member->pubkey])) {
            throw new ClanRuleViolation(__(':name is not in this clan.', ['name' => $member->displayName()]));
        }

        unset($listing[$member->pubkey]);
        $templates = [$this->clanTemplate($clan, $listing)];
        $lineups = [];

        $seated = Lineup::query()->where('clan_id', $clan->id)
            ->whereHas('seats', fn ($query) => $query->where('user_id', $member->id))
            ->with('seats.user')->orderBy('id')->get();

        foreach ($seated as $lineup) {
            $lineups[] = $lineup;
            $rest = array_values($lineup->seats->sortBy('id')->reject(fn (LineupSeat $seat) => $seat->user_id === $member->id)
                ->map(fn (LineupSeat $seat) => [$seat->user->pubkey, $seat->role])->all());

            if ($this->countsTowardsMinimum($rest) >= $lineup->gameMode()->lineupMinimum()) {
                $templates[] = $this->lineupTemplate($clan, $lineup->game, $lineup->mode, $rest);
            }
        }

        return ['templates' => $templates, 'lineups' => $lineups];
    }

    /* ---------- Rules --------------------------------------------------------------------------------------------- */

    /**
     * Only the owner signs clan and lineup events in this league.
     */
    private function assertOwner(User $user, Clan $clan): void
    {
        $member = ClanMember::query()->where(['clan_id' => $clan->id, 'user_id' => $user->id])->first();

        if ($clan->owner_id !== $user->id || $member === null) {
            throw new ClanRuleViolation(__('Only the founder of :clan can change its players.', ['clan' => $clan->name]));
        }
    }

    /**
     * The owner may leave only when another captain keeps the clan running,
     * or when nobody else is left (the clan then ends).
     */
    public function assertCanLeave(User $player): void
    {
        $membership = $player->clanMember()->with('clan.members')->first();

        if ($membership === null || $membership->clan->owner_id !== $player->id) {
            return;
        }

        $others = $membership->clan->members->reject(fn (ClanMember $member) => $member->user_id === $player->id);

        if ($others->isNotEmpty() && ! $others->contains(fn (ClanMember $member) => $member->role === ClanRole::Captain)) {
            throw new ClanRuleViolation(__('Make another player captain of :clan before you leave.', ['clan' => $membership->clan->name]));
        }
    }

    /**
     * Drop the player's membership and seats; a clan without members ends.
     */
    private function leaveCurrentClan(User $player, string $reason): void
    {
        $membership = $player->clanMember()->first();

        if ($membership === null) {
            return;
        }

        $clanId = $membership->clan_id;
        $membership->delete();

        LineupSeat::query()->where('user_id', $player->id)
            ->whereIn('lineup_id', Lineup::query()->where('clan_id', $clanId)->select('id'))
            ->delete();

        if (! ClanMember::query()->where('clan_id', $clanId)->exists()) {
            Clan::query()->whereKey($clanId)->delete();

            return;
        }

        ClanDeparture::query()->create(['clan_id' => $clanId, 'user_id' => $player->id, 'reason' => $reason, 'left_at' => now()]);
    }

    /* ---------- Templates ----------------------------------------------------------------------------------------- */

    /**
     * Who the clan event lists: the owner, the members (join order) and the
     * players with a pending invite, as pubkey => role.
     *
     * @return array<string, ClanRole>
     */
    private function clanListing(Clan $clan): array
    {
        $listing = [$clan->owner_pubkey => ClanRole::Captain];

        $members = ClanMember::query()->where('clan_id', $clan->id)->with('user')->orderBy('joined_at')->orderBy('id')->get();

        foreach ($members as $member) {
            $listing[$member->user->pubkey] ??= $member->role;
        }

        $invited = ClanInvite::query()->where(['clan_id' => $clan->id, 'status' => InviteStatus::Pending])->with('invitee')->orderBy('id')->get();

        foreach ($invited as $invite) {
            $listing[$invite->invitee->pubkey] ??= ClanRole::Member;
        }

        return $listing;
    }

    /**
     * @param  array<string, ClanRole>  $listing
     * @return array<string, mixed>
     */
    private function clanTemplate(Clan $clan, array $listing): array
    {
        $tags = [['d', $clan->slug], ['name', $clan->name], ['clantag', $clan->clantag]];

        if ($clan->picture !== null) {
            $tags[] = ['picture', $clan->picture];
        }

        if ($clan->meetup_url !== null) {
            $tags[] = ['r', $clan->meetup_url];
        }

        foreach ($listing as $pubkey => $role) {
            $tags[] = ['p', (string) $pubkey, '', $role->value];
        }

        $tags[] = ['alt', "Esports clan: {$clan->name} [{$clan->clantag}]"];

        $owner = User::query()->where('pubkey', $clan->owner_pubkey)->firstOrFail();

        return $this->template(Clan::KIND, $tags, $clan->description ?? '', $owner, $clan->slug);
    }

    /**
     * @param  list<array{0: string, 1: LineupRole}>  $listing
     * @return array<string, mixed>
     */
    private function lineupTemplate(Clan $clan, string $game, string $mode, array $listing): array
    {
        $d = "{$clan->slug}/{$game}/{$mode}";
        $tags = [['d', $d], ['a', $clan->address(), ''], ['game', $game], ['mode', $mode]];

        foreach ($listing as [$pubkey, $role]) {
            $tags[] = ['p', $pubkey, '', $role->value];
        }

        $tags[] = ['alt', "Esports lineup: {$clan->name}, {$game} {$mode}"];

        $owner = User::query()->where('pubkey', $clan->owner_pubkey)->firstOrFail();

        return $this->template(Lineup::KIND, $tags, '', $owner, $d);
    }

    /**
     * @param  list<string>  $lineupAddresses
     * @return array<string, mixed>
     */
    private function membershipTemplate(User $player, ?string $clanAddress, array $lineupAddresses): array
    {
        $tags = [];

        if ($clanAddress !== null) {
            $tags[] = ['a', $clanAddress, ''];
        }

        foreach ($lineupAddresses as $address) {
            $tags[] = ['a', $address, ''];
        }

        $tags[] = ['alt', $clanAddress === null ? 'Esports clan membership: no clan' : 'Esports clan membership and accepted lineups'];

        return $this->template(EsportsEventRules::MEMBERSHIP_KIND, $tags, '', $player, null);
    }

    /**
     * An unsigned event. `created_at` is a suggestion that keeps replaceable
     * versions strictly newer than the stored one, even within one second.
     *
     * @param  list<list<string>>  $tags
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     */
    private function template(int $kind, array $tags, string $content, User $author, ?string $d): array
    {
        $stored = NostrEvent::query()->where(['kind' => $kind, 'pubkey' => $author->pubkey, 'd' => $d])->max('signed_at');

        return [
            'kind' => $kind,
            'tags' => $tags,
            'content' => $content,
            'created_at' => max(now()->getTimestamp(), $stored === null ? 0 : (int) $stored + 1),
        ];
    }

    /**
     * @return list<string>
     */
    private function acceptedLineupAddresses(User $player, Clan $clan): array
    {
        return array_values(LineupSeat::query()->where('user_id', $player->id)->whereNotNull('accepted_at')
            ->whereIn('lineup_id', Lineup::query()->where('clan_id', $clan->id)->select('id'))
            ->with('lineup.clan')->orderBy('lineup_id')->get()
            ->map(fn (LineupSeat $seat) => $seat->lineup->address())->all());
    }

    private function lineupAddress(Clan $clan, string $game, string $mode): string
    {
        return Lineup::KIND.':'.$clan->owner_pubkey.":{$clan->slug}/{$game}/{$mode}";
    }

    /**
     * @param  list<array{0: string, 1: LineupRole}>  $listing
     */
    private function countsTowardsMinimum(array $listing): int
    {
        return count(array_filter($listing, fn (array $seat) => $seat[1]->countsTowardsMinimum()));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(trim(Str::slug($name), '-'), 40, '');
        $base = preg_match(Clan::SLUG_PATTERN, $base) === 1 ? $base : 'clan-'.Str::lower(Str::random(6));
        $slug = $base;

        for ($n = 2; Clan::query()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /* ---------- Verify and persist -------------------------------------------------------------------------------- */

    /**
     * @param  list<mixed>  $signed
     * @param  list<array<string, mixed>>  $templates
     * @return list<SignedEvent>
     *
     * @throws RejectedEvent
     */
    private function verify(array $signed, array $templates, User $author): array
    {
        if (count($signed) !== count($templates)) {
            throw new RejectedEvent('event_count');
        }

        $events = [];

        foreach ($templates as $index => $template) {
            /** @var array{kind: int, tags: list<list<string>>, content: string} $template */
            $events[] = $this->gate->check($signed[$index] ?? null, $template, $author);
        }

        return $events;
    }

    /**
     * @param  list<SignedEvent>  $events
     * @return array<int, SignedEvent>
     */
    private function byKind(array $events): array
    {
        $byKind = [];

        foreach ($events as $event) {
            $byKind[$event->kind] = $event;
        }

        return $byKind;
    }

    /**
     * Write the projection and the events in one transaction, then publish.
     *
     * @template T
     *
     * @param  list<SignedEvent>  $events
     * @param  callable(): T  $apply
     * @return T
     */
    private function persist(array $events, callable $apply): mixed
    {
        return DB::transaction(function () use ($events, $apply) {
            $result = $apply();

            foreach ($events as $event) {
                PublishNostrEvent::dispatch(NostrEvent::fromSigned($event));
            }

            return $result;
        });
    }
}
