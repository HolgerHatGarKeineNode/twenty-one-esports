<?php

namespace App\Support\SeasonChain;

use App\Models\NostrEvent;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
 *
 * The newest version per league and player is also kept in one row of
 * `opponent_lists_current` (P7e gate, Low), updated whenever a version is
 * archived ({@see track()}): reads of "the newest list" never scan the
 * versions, however many one account files.
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

    /**
     * One relay filter per author (their newest list, `limit` 1): only the
     * authors whose lists can change a rank (TrustJob: anchors and ranked
     * players), and nobody's list competes with anybody else's for a place in
     * the answer. Authors whose list is archived already ask only for versions
     * since $since.
     *
     * @param  list<string>  $authors
     * @return list<array<string, mixed>>
     */
    public function filters(array $authors, ?int $since): array
    {
        $archived = array_fill_keys($this->query()->distinct()->pluck('pubkey')->all(), true);
        $filters = [];

        foreach ($authors as $author) {
            $filter = ['kinds' => [self::KIND], 'authors' => [$author], '#d' => [$this->d()], 'limit' => 1];

            if (isset($archived[$author]) && $since !== null) {
                $filter['since'] = $since;
            }

            $filters[] = $filter;
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
        $id = DB::table('opponent_lists_current')->where('d', $this->d())->where('pubkey', $pubkey)->value('nostr_event_id');

        return $id === null ? null : NostrEvent::query()->whereKey($id)->first();
    }

    /**
     * The newest list of every player: pubkey => listed pubkeys.
     *
     * @return array<string, list<string>>
     */
    public function newestEntries(): array
    {
        $newest = [];

        foreach (DB::table('opponent_lists_current')->where('d', $this->d())->orderBy('pubkey')->get(['pubkey', 'entries']) as $row) {
            $newest[(string) $row->pubkey] = self::decode((string) $row->entries);
        }

        return $newest;
    }

    /**
     * The players whose newest list names $pubkey, from the current rows
     * only (one per player), never from the versions.
     *
     * @return list<string>
     */
    public function listing(string $pubkey): array
    {
        $rows = DB::table('opponent_lists_current')->where('d', $this->d())->where('pubkey', '!=', $pubkey)
            ->where('entries', 'like', '%'.$pubkey.'%')->orderBy('pubkey')->get(['pubkey', 'entries']);

        return array_values(array_map(fn (object $row): string => (string) $row->pubkey,
            array_filter($rows->all(), fn (object $row): bool => in_array($pubkey, self::decode((string) $row->entries), true))));
    }

    /**
     * Keep the current row of an archived opponent list version: taken when
     * it is the author's first version in that league, or newer (higher
     * `created_at`, ties to the lower id, NIP-01). Any other event is left
     * alone. Called for every created NostrEvent.
     */
    public static function track(NostrEvent $event): void
    {
        if ($event->kind !== self::KIND || preg_match('#^esports/[0-9a-f]{64}$#', (string) $event->d) !== 1) {
            return;
        }

        $current = DB::table('opponent_lists_current')->where('d', $event->d)->where('pubkey', $event->pubkey)->lockForUpdate()->first(['signed_at', 'event_id']);

        $newer = $current === null || $event->signed_at > (int) $current->signed_at
            || ($event->signed_at === (int) $current->signed_at && $event->event_id < (string) $current->event_id);

        if (! $newer) {
            return;
        }

        DB::table('opponent_lists_current')->updateOrInsert(['d' => $event->d, 'pubkey' => $event->pubkey], [
            'nostr_event_id' => $event->id,
            'event_id' => $event->event_id,
            'signed_at' => $event->signed_at,
            'entries' => (string) json_encode(self::entries($event)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private static function decode(string $entries): array
    {
        return array_values(array_filter((array) json_decode($entries, true), is_string(...)));
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
