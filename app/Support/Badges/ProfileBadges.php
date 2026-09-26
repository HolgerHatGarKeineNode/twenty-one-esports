<?php

namespace App\Support\Badges;

use App\Jobs\PublishNostrEvent;
use App\Models\NostrEvent;
use App\Models\RankBadge;
use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use App\Support\Nostr\SignedEventGate;
use Illuminate\Support\Facades\DB;

/**
 * "Show on my Nostr profile" (NIP "Rank badges", Profile): the player adds
 * the pair (`a` definition, `e` award) of their rank badge to their NIP-58
 * profile badges, the replaceable kind `10008`, signed in the browser.
 *
 * Never from a stale copy. The browser reads the player's newest `10008`
 * (and a deprecated `30008` `profile_badges`) from their NIP-65 write relays
 * and the league relays and hands the SIGNED events in; the league checks
 * author, kind and signature, archives them, and builds the new version from
 * the newest list it knows: every entry and its order kept, the content kept
 * verbatim (it may hold private NIP-51 items), the pairs of a `30008` merged
 * in (NIP-58: "migrate"), the new pair appended at the end. When the browser
 * reached no relay at all and the league has no archived list either, the
 * league refuses: writing then could drop every badge the player has.
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

    /** Most events the browser may hand in for one change. */
    public const MAX_FOUND = 12;

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
    public function prepare(User $user, RankBadge $badge, array $found, bool $reached): array
    {
        $this->archive($user, $found);
        $template = $this->template($user, $badge, $reached);

        return ['template' => $template, 'kept' => count($this->pairs($template['tags'])) - 1];
    }

    /**
     * @param  list<mixed>  $found
     *
     * @throws ProfileBadgesRefused|RejectedEvent
     */
    public function submit(User $user, RankBadge $badge, array $found, bool $reached, mixed $signed): NostrEvent
    {
        $this->archive($user, $found);
        $template = $this->template($user, $badge, $reached);
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
    private function template(User $user, RankBadge $badge, bool $reached): array
    {
        if ($badge->pubkey !== $user->pubkey || $badge->awardEvent === null) {
            throw new ProfileBadgesRefused(__('This badge is not yours to show.'));
        }

        $newest = $this->newest($user->pubkey, self::KIND, null);
        $legacy = $this->newest($user->pubkey, self::LEGACY_KIND, self::LEGACY_D);

        if (! $reached && $newest === null && $legacy === null) {
            throw new ProfileBadgesRefused(__('Your current badges could not be read from any relay, so nothing was changed. Try again in a moment.'));
        }

        $payload = $newest?->payload() ?? [];
        /** @var list<list<string>> $tags */
        $tags = array_values(array_filter((array) ($payload['tags'] ?? []), is_array(...)));
        $listed = array_column($this->pairs($tags), 0);

        // NIP-58: a deprecated 30008 `profile_badges` is migrated into the 10008.
        foreach ($legacy === null ? [] : $this->pairs((array) ($legacy->payload()['tags'] ?? [])) as [$a, $e]) {
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
     * Archive the player's own lists the browser read: a `10008` or a
     * `30008` `profile_badges`, signed by the player; anything else is
     * ignored. Cheap checks first, Schnorr last.
     *
     * @param  list<mixed>  $found
     */
    private function archive(User $user, array $found): void
    {
        foreach (array_slice($found, 0, self::MAX_FOUND) as $input) {
            $event = SignedEvent::fromInput($input);

            if ($event === null || $event->pubkey !== $user->pubkey
                || ! ($event->kind === self::KIND || ($event->kind === self::LEGACY_KIND && $event->tag('d') === self::LEGACY_D))
                || $event->createdAt > now()->getTimestamp() + SignedEventGate::MAX_AHEAD_SECONDS
                || NostrEvent::query()->where('event_id', $event->id)->exists()
                || ! $event->hasValidSignature()) {
                continue;
            }

            NostrEvent::fromSigned($event);
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
