<?php

namespace App\Support\SeasonChain;

use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Support\Board;
use App\Support\Membership;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\DB;

/**
 * The trust job (NIP "Trust", algorithm `anchored-trust-v1`): reads the
 * anchors and the players' opponent lists, computes every rank
 * ({@see AnchoredTrust}) and publishes, signed by the trust key:
 *
 * - its description (kind `0`, NIP-85 appendix 1), once;
 * - the anchor list (`30000`, `d` = `esports/<league key>/anchors`) whenever
 *   the anchor set changed, before the assertions that reference it;
 * - one assertion (`30382`) per player whose rank, anchor subtree or anchor
 *   list changed ("only if the contents of each event actually change");
 *   a player ranked above 0 once gets a rank-0 version when he drops to 0.
 *
 * Inputs: anchors = the paid members of the association for the current and
 * the previous year (`GET /api/members/{year}`) plus the league admins; the
 * newest opponent list of every pubkey and the league-labelled reports
 * (`1984`), read from the league relays and archived in nostr_events, so a
 * relay outage leaves the archived lists in place instead of emptying the
 * graph. Admin dismissals and exclusions have no storage yet: none apply.
 *
 * Fail closed: without the trust key, the league key or a readable member
 * list for both years the job refuses ({@see TrustJobRefused}); the ranks of
 * the last run stay, and no run for a new season opens rated play.
 */
final class TrustJob
{
    public const DESCRIPTION = 0;

    public const ANCHOR_LIST = 30000;

    public const ASSERTION = 30382;

    public const REPORT = 1984;

    public const LABEL_NAMESPACE = 'space.einundzwanzig.esports';

    public const LABELS = ['multi-account', 'result-fixing', 'cheating', 'abuse'];

    /** A report counts if its author had at least this rank in the previous run. */
    public const REPORTER_MINIMUM = 50;

    public const REPORT_MAX_AGE_DAYS = 365;

    public function __construct(private RelayReader $reader, private Membership $membership) {}

    /**
     * @throws TrustJobRefused
     */
    public function run(): TrustRun
    {
        $trust = LeagueKey::trust() ?? throw new TrustJobRefused('ESPORTS_TRUST_NSEC is not set or not a valid secret key.');
        $league = LeagueKey::fromConfig() ?? throw new TrustJobRefused('ESPORTS_LEAGUE_NSEC is not set or not a valid secret key.');

        if ($trust->pubkey() === $league->pubkey()) {
            throw new TrustJobRefused('The trust key must not be the league key (NIP-85: a key per algorithm).');
        }

        $anchors = $this->anchors() ?? throw new TrustJobRefused('The association\'s member list could not be read; the last ranks stay.');
        $lists = new OpponentLists($league->pubkey());

        // Network first, outside the transaction; the archive keeps every version read.
        $lists->archive($this->reader->fetch($lists->filter(), known: $lists->knownIds()));
        $knownReports = array_fill_keys(NostrEvent::query()->where('kind', self::REPORT)->pluck('event_id')->all(), true);
        $this->archiveReports($this->reader->fetch(['kinds' => [self::REPORT], '#L' => [self::LABEL_NAMESPACE]], known: $knownReports));

        return DB::transaction(fn (): TrustRun => $this->compute($trust, $league, $anchors, $lists));
    }

    /**
     * @param  list<string>  $anchors
     */
    private function compute(LeagueKey $trust, LeagueKey $league, array $anchors, OpponentLists $lists): TrustRun
    {
        $previous = TrustRank::query()->get()->keyBy('pubkey');
        $entries = $lists->newestEntries();
        $result = AnchoredTrust::compute($anchors, $entries, $this->countedReports($previous->map(fn (TrustRank $row): int => $row->rank)->all()));
        $now = now()->getTimestamp();

        $this->describe($trust, $now);
        $anchorList = $this->anchorList($trust, $league, $anchors, $now);
        $relay = (string) (config('esports.relays')[0] ?? '');

        $run = TrustRun::query()->create([
            'season_id' => Seasons::live()?->id,
            'trust_pubkey' => $trust->pubkey(),
            'anchor_list_nostr_event_id' => $anchorList->id,
            'anchors' => count($anchors),
            'lists' => count($entries),
            'ranked' => count(array_filter($result, fn (array $row): bool => $row['rank'] > 0)),
            'published' => 0,
            'computed_at' => now(),
        ]);

        $published = 0;

        foreach (array_unique([...array_keys($result), ...$previous->keys()->all()]) as $pubkey) {
            $pubkey = (string) $pubkey;
            $row = $result[$pubkey] ?? ['raw' => 0.0, 'rank' => 0, 'layer' => 0, 'anchor' => null];
            $current = $previous->get($pubkey);

            if ($current === null && $row['rank'] === 0) {
                continue; // never ranked above 0: no assertion (NIP "Trust rank")
            }

            $anchor = $row['rank'] > 0 ? $row['anchor'] : null;

            if ($current !== null && $current->rank === $row['rank'] && $current->anchor === ($anchor[0] ?? null)
                && $current->anchor_share === ($anchor[1] ?? null) && $current->anchor_list_event_id === $anchorList->event_id) {
                continue;
            }

            $tags = [['d', $pubkey], ['p', $pubkey], ['rank', (string) $row['rank']]];

            if ($anchor !== null) {
                $tags[] = ['anchor', $anchor[0], (string) $anchor[1]];
            }

            $tags[] = ['e', $anchorList->event_id, $relay];
            $tags[] = ['alt', 'Trusted assertion: TWENTY ONE Esports trust rank'];

            $event = $trust->publish(self::ASSERTION, $tags, '', $this->after($current === null ? null : NostrEvent::query()->find($current->nostr_event_id), $now));

            TrustRank::query()->updateOrCreate(['pubkey' => $pubkey], [
                'rank' => $row['rank'],
                'raw' => $row['raw'],
                'anchor' => $anchor[0] ?? null,
                'anchor_share' => $anchor[1] ?? null,
                'anchor_list_event_id' => $anchorList->event_id,
                'trust_run_id' => $run->id,
                'nostr_event_id' => $event->id,
                'event_id' => $event->event_id,
            ]);
            $published++;
        }

        $run->update(['published' => $published]);

        return $run;
    }

    /**
     * The paid members of this and the previous year, and the league admins;
     * null when a member list cannot be read.
     *
     * @return list<string>|null
     */
    private function anchors(): ?array
    {
        $year = now()->year;
        $anchors = [];

        foreach ([$year, $year - 1] as $paidIn) {
            $paid = $this->membership->paidPubkeys($paidIn);

            if ($paid === null) {
                return null;
            }

            $anchors += $paid;
        }

        foreach ([...Board::pubkeys(), ...Admin::query()->pluck('pubkey')->all()] as $admin) {
            if (NostrKeys::isHexPubkey($admin)) {
                $anchors[$admin] = true;
            }
        }

        $anchors = array_map(strval(...), array_keys($anchors));
        sort($anchors);

        return $anchors;
    }

    /** The trust key's kind 0 (NIP-85 appendix 1), published once and again only if the text changes. */
    private function describe(LeagueKey $trust, int $now): void
    {
        $content = (string) json_encode([
            'name' => (string) config('esports.trust.name'),
            'about' => 'Trust ranks of '.config('app.name').', algorithm anchored-trust-v1: anchors are the paid members of EINUNDZWANZIG and the league admins; '
                .'trust flows only through the players\' league opponent lists (kind 30000, d esports/<league key>), alpha 0.5, at most 3 hops, '
                .'rank = 100 + 25 * log2(8 * raw), clamped to 0..100; each counted report halves (at most two before an admin decides). A new algorithm version gets a new key.',
            'website' => rtrim((string) config('app.url'), '/').'/protocol',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $current = NostrEvent::query()->where('kind', self::DESCRIPTION)->where('pubkey', $trust->pubkey())->orderByDesc('signed_at')->first();

        if ($current === null || ($current->payload()['content'] ?? null) !== $content) {
            $trust->publish(self::DESCRIPTION, [], $content, $this->after($current, $now));
        }
    }

    /**
     * The anchor list this run references: the newest one if its `p` set is
     * the same, a new version otherwise.
     *
     * @param  list<string>  $anchors
     */
    private function anchorList(LeagueKey $trust, LeagueKey $league, array $anchors, int $now): NostrEvent
    {
        $d = 'esports/'.$league->pubkey().'/anchors';
        $current = NostrEvent::query()->where('kind', self::ANCHOR_LIST)->where('pubkey', $trust->pubkey())->where('d', $d)
            ->orderByDesc('signed_at')->orderBy('event_id')->first();

        if ($current !== null) {
            $listed = OpponentLists::entries($current);
            sort($listed);

            if ($listed === $anchors) {
                return $current;
            }
        }

        $tags = [
            ['d', $d],
            ['title', 'TWENTY ONE Esports: trust anchors'],
            ['description', 'Anchors of anchored-trust-v1: the paid members of EINUNDZWANZIG and the league admins.'],
        ];

        foreach ($anchors as $anchor) {
            $tags[] = ['p', $anchor];
        }

        $tags[] = ['alt', 'Follow set: trust anchors of TWENTY ONE Esports'];

        return $trust->publish(self::ANCHOR_LIST, $tags, '', $this->after($current, $now));
    }

    /**
     * Reports that count this run (NIP "Reports"): the league namespace with
     * one of its labels, an author with rank at least 50 in the previous run,
     * at most 365 days old, one per author and target. The algorithm caps
     * them at two.
     *
     * @param  array<string, int>  $previousRanks
     * @return array<string, int> target => counted reports
     */
    private function countedReports(array $previousRanks): array
    {
        $since = now()->subDays(self::REPORT_MAX_AGE_DAYS)->getTimestamp();
        $authors = [];

        $reports = NostrEvent::query()->where('kind', self::REPORT)->where('signed_at', '>=', $since)->cursor();

        foreach ($reports as $report) {
            if (($previousRanks[$report->pubkey] ?? 0) < self::REPORTER_MINIMUM) {
                continue;
            }

            $event = SignedEvent::fromInput($report->payload());
            $target = $event?->tag('p');

            if ($event === null || ! self::isLeagueReport($event) || ! NostrKeys::isHexPubkey($target) || $target === $event->pubkey) {
                continue;
            }

            $authors[$target][$event->pubkey] = true;
        }

        return array_map(count(...), $authors);
    }

    /**
     * @param  list<SignedEvent>  $events
     */
    private function archiveReports(array $events): void
    {
        foreach ($events as $event) {
            if ($event->kind === self::REPORT && self::isLeagueReport($event) && NostrEvent::query()->where('event_id', $event->id)->doesntExist()) {
                NostrEvent::fromSigned($event);
            }
        }
    }

    private static function isLeagueReport(SignedEvent $event): bool
    {
        // tagsNamed() drops the tag name: ["L", ns] -> [ns], ["l", label, ns] -> [label, ns].
        if (! in_array(self::LABEL_NAMESPACE, array_map(fn (array $tag): ?string => $tag[0] ?? null, $event->tagsNamed('L')), true)) {
            return false;
        }

        foreach ($event->tagsNamed('l') as $tag) {
            if (in_array($tag[0] ?? null, self::LABELS, true) && ($tag[1] ?? null) === self::LABEL_NAMESPACE) {
                return true;
            }
        }

        return false;
    }

    /** A replaceable event must be newer than the version it replaces (NIP-01). */
    private function after(?NostrEvent $previous, int $now): int
    {
        return $previous === null ? $now : max($now, $previous->signed_at + 1);
    }
}
