<?php

namespace App\Support\SeasonChain;

use App\Jobs\PublishNostrEvent;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEventGate;
use Illuminate\Support\Facades\DB;

/**
 * "Add as opponent" (NIP "Opponent list", P7e): the player's own NIP-51
 * follow set `30000` with `d` = `esports/<league key>`, one `p` per listed
 * player, signed by the player in the browser (two phases, like the clan
 * actions: prepare the template, check the signed event against it) and
 * submitted through the league: archived in nostr_events, where the trust
 * job and the gate read it ({@see OpponentLists}), and queued for the league
 * relays.
 *
 * The new version is the newest archived list with one `p` added or taken
 * out; entries keep their order. `content` stays empty (public only).
 *
 * Two players list each other when the newest list of each names the other,
 * the same test as the gate's condition 1 ({@see AnchoredTrustFacts}).
 */
final class Opponents
{
    public const TITLE = 'TWENTY ONE Esports: opponents';

    public const ALT = 'Follow set: opponents for rated games in TWENTY ONE Esports';

    public function __construct(private SignedEventGate $gate) {}

    /**
     * @return list<array{kind: int, tags: list<list<string>>, content: string, created_at: int}>
     *
     * @throws OpponentListRefused
     */
    public function prepareAdd(User $player, User $opponent): array
    {
        return [$this->addTemplate($player, $opponent)];
    }

    /**
     * @param  list<mixed>  $signed
     *
     * @throws OpponentListRefused|RejectedEvent
     */
    public function add(User $player, User $opponent, array $signed): NostrEvent
    {
        return $this->submit($player, $this->addTemplate($player, $opponent), $signed);
    }

    /**
     * @return list<array{kind: int, tags: list<list<string>>, content: string, created_at: int}>
     *
     * @throws OpponentListRefused
     */
    public function prepareRemove(User $player, string $pubkey): array
    {
        return [$this->removeTemplate($player, $pubkey)];
    }

    /**
     * Take a pubkey off the list; by pubkey, so an entry whose account is
     * gone can be removed too.
     *
     * @param  list<mixed>  $signed
     *
     * @throws OpponentListRefused|RejectedEvent
     */
    public function remove(User $player, string $pubkey, array $signed): NostrEvent
    {
        return $this->submit($player, $this->removeTemplate($player, $pubkey), $signed);
    }

    /**
     * The pubkeys on the player's newest list; empty without a league key.
     *
     * @return list<string>
     */
    public function entries(User $player): array
    {
        $newest = OpponentLists::forLeague()?->newest($player->pubkey);

        return $newest === null ? [] : OpponentLists::entries($newest);
    }

    /** Whether $player's newest list names $opponent. */
    public function lists(User $player, User $opponent): bool
    {
        return in_array($opponent->pubkey, $this->entries($player), true);
    }

    public function listEachOther(User $a, User $b): bool
    {
        return $a->id !== $b->id && $this->lists($a, $b) && $this->lists($b, $a);
    }

    /**
     * The entries of the player's list that list the player back.
     *
     * @return list<string> pubkeys
     */
    public function mutual(User $player): array
    {
        $lists = OpponentLists::forLeague();

        if ($lists === null) {
            return [];
        }

        return array_values(array_filter($this->entries($player), function (string $entry) use ($lists, $player): bool {
            $theirs = $lists->newest($entry);

            return $theirs !== null && in_array($player->pubkey, OpponentLists::entries($theirs), true);
        }));
    }

    /**
     * The pubkeys whose newest list names the player (only archived lists
     * that mention the pubkey at all are looked at).
     *
     * @return list<string>
     */
    public function listedBy(User $player): array
    {
        $lists = OpponentLists::forLeague();

        if ($lists === null) {
            return [];
        }

        $authors = NostrEvent::query()->where('kind', OpponentLists::KIND)->where('d', $lists->d())
            ->where('raw', 'like', '%'.$player->pubkey.'%')->where('pubkey', '!=', $player->pubkey)->distinct()->pluck('pubkey');

        return array_values(array_filter(array_map(strval(...), $authors->all()), function (string $author) use ($lists, $player): bool {
            $newest = $lists->newest($author);

            return $newest !== null && in_array($player->pubkey, OpponentLists::entries($newest), true);
        }));
    }

    /**
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     *
     * @throws OpponentListRefused
     */
    private function addTemplate(User $player, User $opponent): array
    {
        $entries = $this->editable($player, $opponent->pubkey);

        if (in_array($opponent->pubkey, $entries, true)) {
            throw new OpponentListRefused(__(':name is already on your opponent list.', ['name' => $opponent->displayName()]));
        }

        return $this->template($player, [...$entries, $opponent->pubkey]);
    }

    /**
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     *
     * @throws OpponentListRefused
     */
    private function removeTemplate(User $player, string $pubkey): array
    {
        $entries = $this->editable($player, $pubkey);

        if (! in_array($pubkey, $entries, true)) {
            throw new OpponentListRefused(__('That player is not on your opponent list.'));
        }

        return $this->template($player, array_values(array_diff($entries, [$pubkey])));
    }

    /**
     * @return list<string> the current entries
     *
     * @throws OpponentListRefused
     */
    private function editable(User $player, string $pubkey): array
    {
        if (OpponentLists::forLeague() === null) {
            throw new OpponentListRefused(__('Opponent lists open once the league is set up.'));
        }

        if ($player->pubkey === $pubkey) {
            throw new OpponentListRefused(__('You cannot add yourself as an opponent.'));
        }

        return $this->entries($player);
    }

    /**
     * An unsigned version; `created_at` keeps it newer than the stored one.
     *
     * @param  list<string>  $entries
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     */
    private function template(User $player, array $entries): array
    {
        $d = (string) OpponentLists::forLeague()?->d();
        $tags = [['d', $d], ['title', self::TITLE]];

        foreach ($entries as $entry) {
            $tags[] = ['p', $entry];
        }

        $tags[] = ['alt', self::ALT];
        $stored = NostrEvent::query()->where(['kind' => OpponentLists::KIND, 'pubkey' => $player->pubkey, 'd' => $d])->max('signed_at');

        return [
            'kind' => OpponentLists::KIND,
            'tags' => $tags,
            'content' => '',
            'created_at' => max(now()->getTimestamp(), $stored === null ? 0 : (int) $stored + 1),
        ];
    }

    /**
     * Check the signed version against the template, archive it and queue it
     * for the league relays (after the commit).
     *
     * @param  array{kind: int, tags: list<list<string>>, content: string, created_at: int}  $template
     * @param  list<mixed>  $signed
     *
     * @throws RejectedEvent
     */
    private function submit(User $player, array $template, array $signed): NostrEvent
    {
        if (count($signed) !== 1) {
            throw new RejectedEvent('event_count');
        }

        $event = $this->gate->check($signed[0], $template, $player);

        return DB::transaction(function () use ($event): NostrEvent {
            $stored = NostrEvent::fromSigned($event);
            PublishNostrEvent::dispatch($stored);

            return $stored;
        });
    }
}
