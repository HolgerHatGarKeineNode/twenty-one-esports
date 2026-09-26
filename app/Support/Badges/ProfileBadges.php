<?php

namespace App\Support\Badges;

use App\Jobs\PublishNostrEvent;
use App\Models\NostrEvent;
use App\Models\RankBadge;
use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use App\Support\Nostr\SignedEventGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Show on my Nostr profile" (NIP "Rank badges", Profile): the player adds
 * the pair (`a` definition, `e` award) of their rank badge to their NIP-58
 * profile badges, the replaceable kind `10008`, signed in the browser.
 *
 * Never from a stale or partial read (P11 security gate F1). The browser
 * reads the player's newest valid `10008` (and a deprecated `30008`
 * `profile_badges`) with resources/js/relayRead.js: a relay counts as read
 * only after its EOSE, and at least one of the player's write relays must
 * answer (`$read`). It hands in the SIGNED events; the league checks author,
 * kind and signature again, archives them, and builds the new version from
 * the newest list it knows: every entry and its order kept, the content kept
 * verbatim (it may hold private NIP-51 items), the new pair appended. A
 * `30008` is merged in (NIP-58: "migrate") only when there is no `10008` or
 * the `30008` is newer, so a pair the player removed never comes back.
 *
 * The league refuses when no write relay was read, and when the read came
 * back empty although it knows a list: either could drop every badge the
 * player has.
 *
 * Cost (gate F2): at most two events per call (the newest `10008` and the
 * newest `30008`); cheap checks first; no Schnorr for an event that is not
 * newer than the archived newest of its kind; verdicts cached by the digest
 * of the whole event (never by id alone: a forged copy must not poison the
 * real one); `esports.badges.profile_calls_per_minute` calls per player.
 *
 * Two phases like every signed action: prepare() gives the template, submit()
 * rebuilds it from the same inputs and checks the signed event against it
 * ({@see SignedEventGate}), then archives it and queues it for the league
 * relays; the browser publishes it to the player's write relays.
 */
final class ProfileBadges
{
    public const KIND = 10008;

    public const LEGACY_KIND = 30008;

    public const LEGACY_D = 'profile_badges';

    public const ALT = 'Profile badges';

    /** Events looked at per call: the newest 10008 and the newest 30008. */
    public const MAX_FOUND = 2;

    public function __construct(private SignedEventGate $gate) {}

    /**
     * The relays the browser reads the player's lists from and falls back to
     * for publishing: the profile relays and the league's own relays.
     *
     * @return list<string>
     */
    public static function browserRelays(): array
    {
        return array_values(array_unique([...(array) config('esports.profile_relays', []), ...(array) config('esports.relays', [])]));
    }

    /**
     * @param  list<mixed>  $found  signed events the browser read from relays
     * @return array{template: array{kind: int, tags: list<list<string>>, content: string, created_at: int}, kept: int}
     *
     * @throws ProfileBadgesRefused
     */
    public function prepare(User $user, RankBadge $badge, array $found, bool $read): array
    {
        $this->throttle($user);
        $this->owned($user, $badge);
        $template = $this->template($user, $badge, $read, $this->archive($user, $found));

        return ['template' => $template, 'kept' => count($this->pairs($template['tags'])) - 1];
    }

    /**
     * @param  list<mixed>  $found
     *
     * @throws ProfileBadgesRefused|RejectedEvent
     */
    public function submit(User $user, RankBadge $badge, array $found, bool $read, mixed $signed): NostrEvent
    {
        $this->throttle($user);
        $this->owned($user, $badge);
        $template = $this->template($user, $badge, $read, $this->archive($user, $found));
        $event = $this->gate->check($signed, $template, $user);

        return DB::transaction(function () use ($event): NostrEvent {
            $stored = NostrEvent::fromSigned($event);
            PublishNostrEvent::dispatch($stored);

            return $stored;
        });
    }

    /** Whether the player's newest known list holds this badge. */
    public function isListed(User $user, RankBadge $badge): bool
    {
        $newest = $this->newest($user->pubkey, self::KIND, null);

        return $newest !== null && in_array($badge->address(), array_column($this->pairs((array) ($newest->payload()['tags'] ?? [])), 0), true);
    }

    /**
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     *
     * @throws ProfileBadgesRefused
     */
    private function template(User $user, RankBadge $badge, bool $read, int $found): array
    {
        // Never from the archive alone: without a write relay's EOSE the league does not know the newest list.
        if (! $read) {
            throw new ProfileBadgesRefused(__('Your badges could not be read from your relays, so nothing was changed. Try again in a moment.'));
        }

        $newest = $this->newest($user->pubkey, self::KIND, null);
        $legacy = $this->newest($user->pubkey, self::LEGACY_KIND, self::LEGACY_D);

        if ($found === 0 && ($newest !== null || $legacy !== null)) {
            throw new ProfileBadgesRefused(__('Your relays returned no badge list, but you have one. Nothing was changed. Try again in a moment.'));
        }

        $payload = $newest?->payload() ?? [];
        /** @var list<list<string>> $tags */
        $tags = array_values(array_filter((array) ($payload['tags'] ?? []), is_array(...)));
        $listed = array_column($this->pairs($tags), 0);

        // NIP-58: a deprecated 30008 `profile_badges` is migrated into the 10008, but only when it is the newer list.
        $migrate = $legacy !== null && ($newest === null || $legacy->signed_at > $newest->signed_at);

        foreach ($migrate ? $this->pairs((array) ($legacy->payload()['tags'] ?? [])) : [] as [$a, $e]) {
            if (! in_array($a, $listed, true)) {
                $tags[] = ['a', $a];
                $tags[] = ['e', $e];
                $listed[] = $a;
            }
        }

        if (in_array($badge->address(), $listed, true)) {
            throw new ProfileBadgesRefused(__('This badge is on your Nostr profile already.'));
        }

        $tags[] = ['a', $badge->address()];
        $tags[] = ['e', (string) $badge->awardEvent->event_id];

        if (! in_array('alt', array_column($tags, 0), true)) {
            $tags[] = ['alt', self::ALT];
        }

        $at = now()->getTimestamp();

        // Never dated ahead: a list from this second (or later) makes the change wait.
        if ($newest !== null && $newest->signed_at >= $at) {
            throw new ProfileBadgesRefused(__('Your badges changed a second ago. Try again in a moment.'));
        }

        return ['kind' => self::KIND, 'tags' => $tags, 'content' => (string) ($payload['content'] ?? ''), 'created_at' => $at];
    }

    /**
     * Archive the player's own lists the browser read: the first `10008` and
     * the first `30008` `profile_badges` of at most MAX_FOUND events, signed by
     * the player; anything else is ignored. Cheap checks first; Schnorr only
     * for an event newer than the archived newest of its kind, and its verdict
     * cached by the digest of the whole event.
     *
     * @param  list<mixed>  $found
     * @return int the lists of the player the relays returned (valid or older than the archive)
     */
    private function archive(User $user, array $found): int
    {
        $seen = [];
        $events = array_filter(array_map(SignedEvent::fromInput(...), array_slice($found, 0, self::MAX_FOUND)));
        // The newest per kind first (NIP-01: created_at, then the lower id); an invalid one lets the next of its kind in.
        usort($events, fn (SignedEvent $a, SignedEvent $b): int => [$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]);

        foreach ($events as $event) {
            $kind = $event->kind;

            if ($event->pubkey !== $user->pubkey || isset($seen[$kind])
                || ! ($kind === self::KIND || ($kind === self::LEGACY_KIND && $event->tag('d') === self::LEGACY_D))
                || $event->createdAt > now()->getTimestamp() + SignedEventGate::MAX_AHEAD_SECONDS) {
                continue;
            }

            $seen[$kind] = true;
            $archived = $this->newest($user->pubkey, $kind, $kind === self::KIND ? null : self::LEGACY_D);

            // Not newer than what the league holds: the archive already has the newer list, no Schnorr needed.
            if ($archived !== null && $event->createdAt <= $archived->signed_at) {
                continue;
            }

            if (! $this->verified($event)) {
                unset($seen[$kind]);

                continue;
            }

            NostrEvent::query()->where('event_id', $event->id)->exists() || NostrEvent::fromSigned($event);
        }

        return count($seen);
    }

    /** Schnorr, once per exact event: the key is the digest of the whole signed event, not its id. */
    private function verified(SignedEvent $event): bool
    {
        return (bool) Cache::remember('nostr-verified:'.hash('sha256', $event->toJson()), 86_400, fn (): bool => $event->hasValidSignature());
    }

    /**
     * @throws ProfileBadgesRefused
     */
    private function owned(User $user, RankBadge $badge): void
    {
        if ($badge->pubkey !== $user->pubkey || $badge->awardEvent === null) {
            throw new ProfileBadgesRefused(__('This badge is not yours to show.'));
        }
    }

    /**
     * Per player: counted before any work, so parallel calls cannot slip
     * past the check.
     *
     * @throws ProfileBadgesRefused
     */
    private function throttle(User $user): void
    {
        if (RateLimiter::hit('profile-badges:'.$user->id, 60) > max(1, (int) config('esports.badges.profile_calls_per_minute'))) {
            throw new ProfileBadgesRefused(__('You tried this often. Try again in a minute.'));
        }
    }

    private function newest(string $pubkey, int $kind, ?string $d): ?NostrEvent
    {
        // NIP-01: the highest created_at wins, a tie goes to the lowest id.
        return NostrEvent::query()->where(['kind' => $kind, 'pubkey' => $pubkey, 'd' => $d])
            ->orderByDesc('signed_at')->orderBy('event_id')->first();
    }

    /**
     * The (a, e) pairs of a badge list: every `a` with the `e` right after it.
     *
     * @param  array<mixed>  $tags
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(array $tags): array
    {
        $tags = array_values($tags);
        $pairs = [];

        foreach ($tags as $index => $tag) {
            $next = $tags[$index + 1] ?? null;

            if (is_array($tag) && ($tag[0] ?? null) === 'a' && is_array($next) && ($next[0] ?? null) === 'e') {
                $pairs[] = [(string) ($tag[1] ?? ''), (string) ($next[1] ?? '')];
            }
        }

        return $pairs;
    }
}
