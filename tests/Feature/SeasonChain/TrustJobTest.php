<?php

/*
 * The trust job `anchored-trust-v1` end to end (P7d; NIP "Trust"): anchors
 * from the association's member list (faked HTTP) and the admins, opponent
 * lists and reports read over a real websocket from the in-memory test relay
 * (tests/Support/MiniRelay, never a public relay), ranks published as
 * `30382` by the trust key, and the gate reading them through
 * AnchoredTrustFacts. The graph is the test bed of NIP "Trust gate and
 * league-wide season 3".
 */

use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\SeasonChain\AnchoredTrustFacts;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\TrustAdmin;
use App\Support\SeasonChain\TrustFacts;
use App\Support\SeasonChain\TrustJob;
use App\Support\SeasonChain\TrustJobRefused;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tests\Support\TestSigner;

/** @var array<string, TestSigner> */
function trustPeople(): array
{
    static $people = null;

    return $people ??= array_map(fn () => new TestSigner, array_flip(['alice', 'bob', 'carol', 'dave', 'erin', 'frank', 'sybil1', 'sybil2', 'sybil3']));
}

function pk(string $name): string
{
    return trustPeople()[$name]->pubkey;
}

/**
 * A signed opponent list of this league.
 *
 * @param  list<string>  $names
 * @return array<string, mixed>
 */
function opponentList(string $author, array $names, int $createdAt): array
{
    $league = LeagueKey::fromConfig()->pubkey();
    $tags = [['d', 'esports/'.$league], ['title', 'TWENTY ONE Esports: opponents']];

    foreach ($names as $name) {
        $tags[] = ['p', pk($name)];
    }

    $tags[] = ['alt', 'Follow set: opponents for rated games in TWENTY ONE Esports'];

    return trustPeople()[$author]->sign(30000, $tags, '', $createdAt);
}

/** @return list<array<string, mixed>> the 11:05 lists of the season-3 test bed */
function seasonThreeEvents(int $at): array
{
    return [
        opponentList('alice', ['bob', 'erin', 'carol'], $at),
        opponentList('bob', ['alice', 'dave', 'frank'], $at),
        opponentList('carol', ['alice', 'erin', 'dave'], $at),
        opponentList('dave', ['bob', 'carol'], $at),
        opponentList('erin', ['alice', 'carol'], $at),
        opponentList('frank', ['alice', 'bob'], $at),
        opponentList('sybil1', ['sybil2', 'sybil3', 'alice'], $at),
        opponentList('sybil2', ['sybil1', 'sybil3'], $at),
        opponentList('sybil3', ['sybil1', 'sybil2'], $at),
    ];
}

/**
 * Run $body against a mini relay seeded with $events; returns $body's result.
 *
 * @param  list<array<string, mixed>>  $events
 */
function withRelay(array $events, Closure $body, array $limits = []): mixed
{
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $seed = tempnam(sys_get_temp_dir(), 'trust-relay');
    file_put_contents($seed, json_encode($events));
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port, $seed, (string) json_encode($limits)]);

    try {
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }

        config(['esports.relays' => ['ws://127.0.0.1:'.$port]]);

        return $body();
    } finally {
        $relay->stop();
        @unlink($seed);
    }
}

/**
 * Two runs: the first reads the anchors' lists and ranks their entries, the
 * second reads the lists of the players ranked by then (a newly vouched
 * player's list is read one run later), which reaches layer 2 (frank).
 */
function runTwice(): TrustRun
{
    app(TrustJob::class)->run();

    return app(TrustJob::class)->run();
}

/** @param list<string> $names */
function payMembers(array $names): void
{
    $pubkeys = array_map(fn (string $name): array => ['pubkey' => pk($name)], $names);
    config(['test.members_down' => false]);

    // The member API is faked; NIP-11 documents come from the local test relay itself.
    Http::preventStrayRequests();
    Http::allowStrayRequests(['http://127.0.0.1:*']);
    Http::fake(function (Request $request) use ($pubkeys) {
        if (str_starts_with($request->url(), 'http://127.0.0.1:')) {
            return null;
        }

        return ! config('test.members_down') && str_starts_with($request->url(), rtrim((string) config('esports.membership.api_url'), '/').'/')
            ? Http::response(str_ends_with($request->url(), '/'.now()->year) ? $pubkeys : [])
            : Http::response('down', 503);
    });
}

function rankOf(string $name): ?int
{
    return TrustRank::query()->where('pubkey', pk($name))->value('rank');
}

/** @return list<list<string>> */
function assertionTags(string $name): array
{
    return NostrEvent::query()->findOrFail(TrustRank::query()->where('pubkey', pk($name))->value('nostr_event_id'))->payload()['tags'];
}

beforeEach(function () {
    $this->season = openSeason();
    $this->trustKey = new TestSigner;
    config(['esports.trust.nsec' => $this->trustKey->secret, 'esports.board' => []]);
    app()->bind(TrustFacts::class, AnchoredTrustFacts::class);

    // Every player of the test bed has an account here; the job reads only league users' lists.
    foreach (trustPeople() as $signer) {
        User::factory()->withPubkey($signer->pubkey)->create();
    }
});

test('regression (security gate F1): a flood of newer lists from throwaway keys neither hides the players\' real lists nor gets stored', function () {
    payMembers(['alice', 'carol']);
    $at = now()->subMinutes(5)->getTimestamp();
    $league = LeagueKey::fromConfig()->pubkey();
    $flood = [];

    // Newer than the real lists, so a relay sends them first; each names alice to look relevant.
    foreach (range(1, 30) as $i) {
        $flood[] = (new TestSigner)->sign(30000, [['d', 'esports/'.$league], ['p', pk('alice')]], '', $at + 60 + $i);
    }

    config(['esports.relay_timeout_seconds' => 1]);
    $run = withRelay([...seasonThreeEvents($at), ...$flood], runTwice(...));

    expect(array_map(rankOf(...), ['bob', 'dave', 'erin', 'frank']))->toBe([100, 100, 100, 46])
        // Lists of the closed author set only: anchors, then the players ranked in the first run.
        ->and($run->lists)->toBe(5)
        ->and(NostrEvent::query()->where('kind', 30000)->where('d', 'esports/'.$league)->count())->toBe(5);
});

test('trust run 1 of the season-3 test bed: anchors and their entries 100, frank 46, the sybils get no assertion; the gate opens and pins them', function () {
    payMembers(['alice', 'carol']);
    $gate = app(RatedTrustGate::class);

    expect($gate->refusal([pk('alice'), pk('bob')], [pk('alice'), pk('bob')]))->toBe(RatedTrustGate::NOT_COMPUTED);

    $run = withRelay(seasonThreeEvents(now()->subMinute()->getTimestamp()), runTwice(...));

    expect(array_map(rankOf(...), ['alice', 'carol', 'bob', 'dave', 'erin', 'frank']))->toBe([100, 100, 100, 100, 100, 46])
        ->and(array_map(rankOf(...), ['sybil1', 'sybil2', 'sybil3']))->toBe([null, null, null])
        ->and($run->only(['season_id', 'anchors', 'lists', 'ranked', 'published']))->toBe(['season_id' => $this->season->id, 'anchors' => 2, 'lists' => 5, 'ranked' => 6, 'published' => 1])
        ->and($run->trust_pubkey)->toBe($this->trustKey->pubkey);

    // The assertion as NIP-85 and the NIP print it: d = p = the player, rank, anchor, e to the anchor list.
    $anchorList = $run->anchorList;
    $tags = assertionTags('erin');

    expect(NostrEvent::query()->findOrFail(TrustRank::query()->where('pubkey', pk('erin'))->value('nostr_event_id'))->pubkey)->toBe($this->trustKey->pubkey)
        ->and($tags)->toContain(['d', pk('erin')], ['p', pk('erin')], ['rank', '100'], ['e', $anchorList->event_id, (string) config('esports.relays.0')])
        // Before alice adds frank both anchors pass erin the same share: the lower pubkey wins.
        ->and(collect($tags)->firstWhere(0, 'anchor'))->toBe(['anchor', min(pk('alice'), pk('carol')), '50'])
        ->and($anchorList->pubkey)->toBe($this->trustKey->pubkey)
        ->and($anchorList->d)->toBe('esports/'.LeagueKey::fromConfig()->pubkey().'/anchors')
        ->and(collect($anchorList->payload()['tags'])->where(0, 'p')->pluck(1)->sort()->values()->all())->toBe(collect([pk('alice'), pk('carol')])->sort()->values()->all())
        ->and(NostrEvent::query()->where('kind', 0)->where('pubkey', $this->trustKey->pubkey)->count())->toBe(1)
        // Every opponent list version read is archived and served by id.
        // Read are only the lists that can change a rank: the anchors' and the ranked players'.
        ->and(NostrEvent::query()->where('kind', 30000)->where('d', 'esports/'.LeagueKey::fromConfig()->pubkey())->count())->toBe(5);

    // The gate pins ranks, assertion ids and the lists that name each other.
    $pin = $gate->pin([pk('alice'), pk('bob')], [pk('alice'), pk('bob')]);
    $aliceList = NostrEvent::query()->where('kind', 30000)->where('pubkey', pk('alice'))->value('event_id');

    expect($gate->refusal([pk('alice'), pk('bob')], [pk('alice'), pk('bob')]))->toBeNull()
        ->and($pin->connected)->toBeTrue()
        ->and($pin->trustKey)->toBe($this->trustKey->pubkey)
        ->and($pin->players[pk('alice')])->toMatchArray(['rank' => 100, 'assertion' => TrustRank::query()->where('pubkey', pk('alice'))->value('event_id'), 'list' => $aliceList])
        // alice does not list dave: not connected. frank is below the minimum.
        ->and($gate->refusal([pk('alice'), pk('dave')], [pk('alice'), pk('dave')]))->toBe(RatedTrustGate::NOT_CONNECTED)
        // One-sided: frank lists alice, alice does not list frank yet.
        ->and($gate->pin([pk('frank'), pk('alice')], [pk('frank'), pk('alice')])->connected)->toBeFalse()
        ->and($gate->refusal([pk('bob'), pk('frank')], [pk('bob'), pk('frank')]))->toBe(RatedTrustGate::NOT_TRUSTED);
});

test('trust run 2: after alice adds frank only the changed assertions are republished; an unchanged run and a relay outage publish nothing', function () {
    payMembers(['alice', 'carol']);
    $before = now()->subMinutes(2)->getTimestamp();
    $events = seasonThreeEvents($before);

    withRelay($events, runTwice(...));
    $unchanged = withRelay($events, fn () => app(TrustJob::class)->run());

    $bob = TrustRank::query()->where('pubkey', pk('bob'))->value('event_id');
    $second = withRelay([...$events, opponentList('alice', ['bob', 'erin', 'carol', 'frank'], $before + 60)], fn () => app(TrustJob::class)->run());

    // frank's rank changed; erin's subtree changed with it (57 % carol), which is new content too.
    expect($unchanged->published)->toBe(0)
        ->and($second->published)->toBe(2)
        ->and(rankOf('frank'))->toBe(100)
        ->and(TrustRank::query()->where('pubkey', pk('bob'))->value('event_id'))->toBe($bob)
        // Trust run 4 of the NIP: erin in carol's subtree with 57.
        ->and(collect(assertionTags('erin'))->firstWhere(0, 'anchor'))->toBe(['anchor', pk('carol'), '57']);

    // Relay down: the archived lists stay, nobody drops to 0, nothing is republished.
    config(['esports.relays' => ['ws://127.0.0.1:9']]);
    $outage = app(TrustJob::class)->run();

    expect($outage->published)->toBe(0)
        ->and(array_map(rankOf(...), ['alice', 'bob', 'frank']))->toBe([100, 100, 100]);
});

test('a counted report halves the target; a report by an unranked author does not count', function () {
    payMembers(['alice', 'carol']);
    $at = now()->subMinutes(2)->getTimestamp();
    $report = fn (string $author, string $target) => trustPeople()[$author]->sign(1984, [
        ['p', pk($target), 'other'], ['L', TrustJob::LABEL_NAMESPACE], ['l', 'multi-account', TrustJob::LABEL_NAMESPACE], ['alt', 'Report'],
    ], 'reason', $at);

    withRelay(seasonThreeEvents($at), fn () => app(TrustJob::class)->run());
    // carol (rank 100 in the previous run) reports bob; sybil1 (no rank) reports dave.
    withRelay([...seasonThreeEvents($at), $report('carol', 'bob'), $report('sybil1', 'dave')], fn () => app(TrustJob::class)->run());

    // bob: raw 1/6 halved to 1/12 -> round(100 + 25 * log2(2/3)) = 85.
    expect(rankOf('bob'))->toBe(85)
        ->and(rankOf('dave'))->toBe(100);
});

test('regression (security re-check round 3, Q5): throwaway accounts republishing lists cannot starve a ranked player\'s edit', function () {
    payMembers(['alice', 'carol']);
    $at = now()->subMinutes(20)->getTimestamp();
    $league = LeagueKey::fromConfig()->pubkey();
    $throwaways = array_map(function (): TestSigner {
        $signer = new TestSigner;
        User::factory()->withPubkey($signer->pubkey)->create();

        return $signer;
    }, range(1, 10));
    $listBy = fn (TestSigner $signer, int $createdAt) => $signer->sign(30000, [['d', 'esports/'.$league], ['p', pk('alice')]], '', $createdAt);

    withRelay([...seasonThreeEvents($at), ...array_map(fn (TestSigner $s) => $listBy($s, $at), $throwaways)], runTwice(...));

    // bob drops frank; ten sign-ups republish newer lists than bob's edit.
    config(['esports.trust.max_events' => 5]);
    $second = [
        ...array_filter(seasonThreeEvents($at), fn (array $event) => $event['pubkey'] !== pk('bob')),
        opponentList('bob', ['alice', 'dave'], $at + 120),
        ...array_map(fn (TestSigner $s) => $listBy($s, $at + 200 + random_int(1, 50)), $throwaways),
    ];
    withRelay(array_values($second), fn () => app(TrustJob::class)->run());

    expect(rankOf('frank'))->toBe(0)
        ->and(NostrEvent::query()->where('kind', 30000)->whereIn('pubkey', array_map(fn (TestSigner $s) => $s->pubkey, $throwaways))->count())->toBe(0);
});

test('round 3: a relay that allows 3 filters per REQ (NIP-11) gets REQs of 3, and every reporter is read', function () {
    payMembers(['alice', 'carol']);
    $at = now()->subMinutes(10)->getTimestamp();
    withRelay(seasonThreeEvents($at), runTwice(...));

    // Six ranked reporters, six filters: more than the relay takes in one REQ.
    withRelay([...seasonThreeEvents($at), leagueReport('carol', pk('bob'), $at + 30)], fn () => app(TrustJob::class)->run(), ['max_filters' => 3]);

    expect(rankOf('bob'))->toBe(85);
});

test('round 3: a relay that refuses with CLOSED is a failed read: logged with its reason, the last ranks stay', function () {
    payMembers(['alice', 'carol']);
    $at = now()->subMinutes(10)->getTimestamp();
    withRelay(seasonThreeEvents($at), runTwice(...));
    Log::spy();

    // It says 10 in NIP-11 but refuses more than 3: the REQs of 6 reporter filters are CLOSED.
    withRelay([...seasonThreeEvents($at), leagueReport('carol', pk('bob'), $at + 30)], fn () => app(TrustJob::class)->run(), ['max_filters' => 3, 'advertised_max_filters' => 10]);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Relay refused a read' && str_contains($context['reason'], 'too many filters'));

    expect(rankOf('bob'))->toBe(100)
        ->and(rankOf('frank'))->toBe(46);
});

test('round 3: never more than 10 filters per REQ, even when a relay advertises more', function () {
    payMembers([]);
    Log::spy();
    $filters = array_map(fn () => ['kinds' => [TrustJob::REPORT], 'authors' => [(new TestSigner)->pubkey], 'limit' => 1], range(1, 12));

    // rnostr-like: 10 filters at most, while its NIP-11 document claims 50.
    withRelay([], fn () => app(RelayReader::class)->fetch($filters), ['max_filters' => 10, 'advertised_max_filters' => 50]);

    Log::shouldNotHaveReceived('warning');
});

test('round 3: a relay that ignores `limit` still gets at most the per-author cap of one author\'s reports', function () {
    payMembers(['alice', 'carol']);
    $at = now()->subMinutes(10)->getTimestamp();
    withRelay(seasonThreeEvents($at), runTwice(...));

    $burst = array_map(fn (int $i) => leagueReport('alice', (new TestSigner)->pubkey, $at + 60 + $i), range(1, 8));
    config(['esports.trust.max_events' => 6, 'esports.trust.reports_limit_per_author' => 3]);
    withRelay([...seasonThreeEvents($at), leagueReport('carol', pk('bob'), $at + 30), ...$burst], fn () => app(TrustJob::class)->run(), ['ignore_limits' => true]);

    expect(NostrEvent::query()->where('kind', 1984)->where('pubkey', pk('alice'))->count())->toBe(3)
        ->and(rankOf('bob'))->toBe(85);
});

/** A signed league report by a test-bed player against any pubkey. */
function leagueReport(string $author, string $target, int $at): array
{
    return trustPeople()[$author]->sign(1984, [
        ['p', $target, 'other'], ['L', TrustJob::LABEL_NAMESPACE], ['l', 'multi-account', TrustJob::LABEL_NAMESPACE], ['alt', 'Report'],
    ], 'reason', $at);
}

test('regression (security re-check, F1 reports): a report burst by one trusted account buries only its own reports', function () {
    payMembers(['alice', 'carol']);
    $at = now()->subMinutes(10)->getTimestamp();
    withRelay(seasonThreeEvents($at), fn () => app(TrustJob::class)->run());

    // carol's real report against bob, then 8 newer reports by alice against throwaway keys.
    $burst = array_map(fn (int $i) => leagueReport('alice', (new TestSigner)->pubkey, $at + 60 + $i), range(1, 8));
    config(['esports.trust.max_events' => 6, 'esports.trust.reports_limit_per_author' => 3]);
    withRelay([...seasonThreeEvents($at), leagueReport('carol', pk('bob'), $at + 30), ...$burst], fn () => app(TrustJob::class)->run());

    expect(rankOf('bob'))->toBe(85)
        ->and(NostrEvent::query()->where('kind', 1984)->where('pubkey', pk('alice'))->count())->toBeLessThanOrEqual(3);
});

test('mass reports (N1): at most reports_per_author reports of one author count per season, the earliest first', function () {
    payMembers(['alice', 'carol']);
    $at = now()->subMinutes(10)->getTimestamp();
    withRelay(seasonThreeEvents($at), fn () => app(TrustJob::class)->run());

    $reports = [];
    foreach (['bob', 'dave', 'erin', 'frank'] as $i => $target) {
        $reports[] = leagueReport('alice', pk($target), $at + 60 + $i);
    }
    config(['esports.trust.reports_per_author' => 3]);
    withRelay([...seasonThreeEvents($at), ...$reports], fn () => app(TrustJob::class)->run());

    // bob and dave (raw 1/6) drop to 85; frank follows bob to 21 (raw 1/72), but the fourth report, against frank, does not count (else 0).
    expect(array_map(rankOf(...), ['bob', 'dave', 'erin', 'frank']))->toBe([85, 85, 100, 21]);
});

test('an admin dismissal stops a report counting, and an exclusion takes a pubkey out of the graph', function () {
    payMembers(['alice', 'carol']);
    $at = now()->subMinutes(10)->getTimestamp();
    $report = leagueReport('carol', pk('bob'), $at + 30);
    withRelay(seasonThreeEvents($at), fn () => app(TrustJob::class)->run());
    withRelay([...seasonThreeEvents($at), $report], fn () => app(TrustJob::class)->run());

    expect(rankOf('bob'))->toBe(85);

    // A board member: excluding a key is the board's decision.
    $admin = User::factory()->create();
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);
    app(TrustAdmin::class)->dismiss($admin, $report['id'], 'Carol mixed up two accounts.');
    withRelay(seasonThreeEvents($at), fn () => app(TrustJob::class)->run());

    expect(rankOf('bob'))->toBe(100);

    // Excluding carol: raw 0, her own list vouches for nobody; dave keeps only bob's entry (layer 2).
    app(TrustAdmin::class)->exclude($admin, pk('carol'), 'Sold her account.');
    withRelay(seasonThreeEvents($at), fn () => app(TrustJob::class)->run());

    expect(rankOf('carol'))->toBe(0)
        ->and(rankOf('dave'))->toBe(46);

    app(TrustAdmin::class)->lift($admin, pk('carol'), 'She got her account back.');
    withRelay(seasonThreeEvents($at), fn () => app(TrustJob::class)->run());

    expect(rankOf('carol'))->toBe(100);
});

test('the job refuses without the trust key, with the league key as trust key, or without the member list, and keeps the last ranks', function () {
    payMembers(['alice', 'carol']);
    withRelay(seasonThreeEvents(now()->subMinute()->getTimestamp()), runTwice(...));

    config(['test.members_down' => true]);
    Cache::flush(); // the member lists are cached for an hour (Membership)
    expect(fn () => app(TrustJob::class)->run())->toThrow(TrustJobRefused::class, 'member list');

    config(['esports.trust.nsec' => config('esports.league.nsec')]);
    expect(fn () => app(TrustJob::class)->run())->toThrow(TrustJobRefused::class, 'must not be the league key');

    config(['esports.trust.nsec' => null]);
    expect(fn () => app(TrustJob::class)->run())->toThrow(TrustJobRefused::class, 'ESPORTS_TRUST_NSEC')
        ->and(TrustRun::query()->count())->toBe(2)
        ->and(rankOf('frank'))->toBe(46);

    // A run before Block 0 (no live season then) does not open rated play in the season released later.
    TrustRun::query()->update(['season_id' => null]);

    expect(app(AnchoredTrustFacts::class)->available())->toBeFalse();
});

test('the league admins are anchors too', function () {
    payMembers(['alice']);
    Admin::query()->create(['pubkey' => pk('carol')]);
    $run = withRelay(seasonThreeEvents(now()->subMinute()->getTimestamp()), runTwice(...));

    expect($run->anchors)->toBe(2)
        ->and(array_map(rankOf(...), ['carol', 'dave', 'frank']))->toBe([100, 100, 46]);
});
