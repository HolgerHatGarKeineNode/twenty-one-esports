<?php

namespace App\Support\SeasonChain;

use App\Models\NostrEvent;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * The players' opponent lists (NIP "Opponent list", NIP-51 follow set
 * `30000` with `d` = `esports/<league key>`), archived in nostr_events:
 * every version the league ever read stays there and is served by id,
 * because relays keep only the newest one and a `gate` row may name an
 * older one (NIP "Checking a gate").
 *
 * The newest version of a player is the highest `created_at`, ties broken by
 * the lowest id (NIP-01). Only public `p` entries count; `content` (private
 * items) is ignored.
 */
final class OpponentLists
{
    public const KIND = 30000;

    public function __construct(private readonly string $leaguePubkey) {}

    /** The lists of the configured league, or null without a league key. */
    public static function forLeague(): ?self
    {
        $league = LeagueKey::fromConfig();

        return $league === null ? null : new self($league->pubkey());
    }

    public function d(): string
    {
        return 'esports/'.$this->leaguePubkey;
    }

    /** Authors per filter, so one REQ stays within what relays accept. */
    public const AUTHORS_PER_FILTER = 250;

    /**
     * The relay filters for the lists of these authors only (league players
     * and anchors: a list by anyone else feeds no rank, and reading it would
     * let a flood of throwaway keys crowd out the real ones). Authors whose
     * list is archived already ask only for versions since $since.
     *
     * @param  list<string>  $authors
     * @return list<array<string, mixed>>
     */
    public function filters(array $authors, ?int $since): array
    {
        $archived = array_fill_keys($this->query()->distinct()->pluck('pubkey')->all(), true);
        $filters = [];

        foreach ([true, false] as $known) {
            $group = array_values(array_filter($authors, fn (string $author): bool => isset($archived[$author]) === $known));

            foreach (array_chunk($group, self::AUTHORS_PER_FILTER) as $chunk) {
                $filter = ['kinds' => [self::KIND], 'authors' => $chunk, '#d' => [$this->d()], 'limit' => count($chunk)];

                if ($known && $since !== null) {
                    $filter['since'] = $since;
                }

                $filters[] = $filter;
            }
        }

        return $filters;
    }

    /**
     * Store the versions newer than the author's newest archived one; older
     * ones and events of another kind or league are skipped, so a run adds at
     * most one version per author. Signatures were checked by the reader.
     *
     * @param  list<SignedEvent>  $events
     * @return int versions newly archived
     */
    public function archive(array $events): int
    {
        $stored = 0;
        usort($events, fn (SignedEvent $a, SignedEvent $b): int => [$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]);

        foreach ($events as $event) {
            if ($event->kind !== self::KIND || $event->tag('d') !== $this->d()) {
                continue;
            }

            $newest = $this->newest($event->pubkey);

            if ($newest === null || $event->createdAt > $newest->signed_at) {
                NostrEvent::fromSigned($event);
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * Every archived version's id, so a run reads only new versions.
     *
     * @return array<string, true>
     */
    public function knownIds(): array
    {
        return array_fill_keys($this->query()->pluck('event_id')->all(), true);
    }

    /** The newest archived list of one player, or null. */
    public function newest(string $pubkey): ?NostrEvent
    {
        return $this->query()->where('pubkey', $pubkey)->orderByDesc('signed_at')->orderBy('event_id')->first();
    }

    /**
     * The newest list of every player: pubkey => listed pubkeys.
     *
     * @return array<string, list<string>>
     */
    public function newestEntries(): array
    {
        $newest = [];

        foreach ($this->query()->orderByDesc('signed_at')->orderBy('event_id')->cursor() as $event) {
            $newest[$event->pubkey] ??= self::entries($event);
        }

        return $newest;
    }

    /**
     * The distinct valid pubkeys of the list's public `p` tags, the author
     * left out.
     *
     * @return list<string>
     */
    public static function entries(NostrEvent $event): array
    {
        $entries = [];

        foreach ((array) ($event->payload()['tags'] ?? []) as $tag) {
            $pubkey = is_array($tag) && ($tag[0] ?? null) === 'p' ? ($tag[1] ?? null) : null;

            if (NostrKeys::isHexPubkey($pubkey) && $pubkey !== $event->pubkey) {
                $entries[$pubkey] = true;
            }
        }

        return array_keys($entries);
    }

    /**
     * @return Builder<NostrEvent>
     */
    private function query(): Builder
    {
        return NostrEvent::query()->where('kind', self::KIND)->where('d', $this->d());
    }
}
