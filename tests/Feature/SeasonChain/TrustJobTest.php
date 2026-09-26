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
use App\Models\TrustCountedReport;
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

test('round 3: a relay that allows 5 filters per REQ (NIP-11) gets REQs of 5, and every reporter is read', function () {
    payMembers(['alice', 'carol']);
    $at = now()->subMinutes(10)->getTimestamp();
    withRelay(seasonThreeEvents($at), runTwice(...));

    // Six ranked reporters, six filters: more than the relay takes in one REQ.
    withRelay([...seasonThreeEvents($at), leagueReport('carol', pk('bob'), $at + 30)], fn () => app(TrustJob::class)->run(), ['max_filters' => 5]);

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

/**
 * Twelve report filters, one per fresh author; the first author has one report on the relay.
 *
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} filters, events
 */
function twelveReportFilters(): array
{
    $signers = array_map(fn () => new TestSigner, range(1, 12));
    $filters = array_map(fn (TestSigner $s) => ['kinds' => [TrustJob::REPORT], 'authors' => [$s->pubkey], 'limit' => 1], $signers);

    return [$filters, [$signers[0]->sign(TrustJob::REPORT, [['p', (new TestSigner)->pubkey, 'other']], '', now()->subMinute()->getTimestamp())]];
}

/** @return array{connect: int, req: int} */
function relayLog(string $path): array
{
    $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];

    return ['connect' => count(array_keys($lines, 'connect', true)), 'req' => count(array_keys($lines, 'req', true))];
}

test('round 4: one connection per relay and fetch, with a subscription per batch of filters', function () {
    payMembers([]);
    [$filters, $events] = twelveReportFilters();
    $log = tempnam(sys_get_temp_dir(), 'relay-log');

    $read = withRelay($events, fn () => app(RelayReader::class)->fetch($filters), ['max_filters' => 5, 'log' => $log]);

    expect($read)->toHaveCount(1)
        ->and(relayLog($log))->toBe(['connect' => 1, 'req' => 3]);
    @unlink($log);
});

test('regression (security re-check round 4): a relay answering just inside the deadline fails the fetch once its total budget is used', function () {
    payMembers([]);
    Log::spy();
    [$filters, $events] = twelveReportFilters();
    config(['esports.relay_timeout_seconds' => 5, 'esports.relay_fetch_budget_seconds' => 2]);

    // Three REQs of 1.2 s each: every one inside the 5 s deadline, together over the 2 s budget.
    [$read, $seconds] = withRelay($events, function () use ($filters) {
        $start = microtime(true);

        return [app(RelayReader::class)->fetch($filters), microtime(true) - $start];
    }, ['max_filters' => 5, 'eose_delay_ms' => 1200]);

    expect($read)->toBe([])
        ->and($seconds)->toBeLessThan(2.6);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'Relay read over its time budget');
});

test('round 4: a relay whose NIP-11 max_filters is below 5 is not read at all, with a logged reason', function () {
    payMembers([]);
    Log::spy();
    [$filters, $events] = twelveReportFilters();
    $log = tempnam(sys_get_temp_dir(), 'relay-log');

    $read = withRelay($events, fn () => app(RelayReader::class)->fetch($filters), ['max_filters' => 3, 'log' => $log]);

    expect($read)->toBe([])
        ->and(relayLog($log)['connect'])->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Relay unsupported' && str_contains($context['reason'], 'max_filters 3'));
    @unlink($log);
});

test('round 4: the newest version wins whatever the relay order (v1 on relay A, v2 on relay B)', function () {
    payMembers([]);
    $author = new TestSigner;
    $v1 = $author->sign(30000, [['d', 'esports/x'], ['p', pk('bob')]], '', now()->subHour()->getTimestamp());
    $v2 = $author->sign(30000, [['d', 'esports/x'], ['p', pk('carol')]], '', now()->subMinute()->getTimestamp());
    $filter = [['kinds' => [30000], 'authors' => [$author->pubkey], 'limit' => 1]];

    $read = withRelay([$v1], function () use ($v2, $filter) {
        $a = config('esports.relays')[0];

        return withRelay([$v2], function () use ($a, $filter) {
            $b = config('esports.relays')[0];

            return [app(RelayReader::class)->fetch($filter, [$a, $b])[0]->id ?? null, app(RelayReader::class)->fetch($filter, [$b, $a])[0]->id ?? null];
        });
    });

    expect($read)->toBe([$v2['id'], $v2['id']]);
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

/**
 * An event as the archive holds it (the job never re-checks archived events),
 * for graphs too large to sign in a test.
 *
 * @param  list<list<string>>  $tags
 */
function archivedEvent(string $pubkey, int $kind, array $tags, int $at): void
{
    $id = bin2hex(random_bytes(32));
    $d = collect($tags)->firstWhere(0, 'd')[1] ?? null;

    NostrEvent::query()->create(['event_id' => $id, 'pubkey' => $pubkey, 'kind' => $kind, 'd' => $d, 'signed_at' => $at,
        'raw' => json_encode(['id' => $id, 'pubkey' => $pubkey, 'created_at' => $at, 'kind' => $kind, 'tags' => $tags, 'content' => '', 'sig' => str_repeat('a', 128)])]);
}

test('round 3, N1 residual: the accounts one anchor vouches for count at most reports_per_anchor reports together', function () {
    payMembers(['alice', 'carol']);
    config(['esports.relays' => [], 'esports.trust.reports_per_anchor' => 3]);
    $at = now()->subMinutes(30)->getTimestamp(); // inside the season (Block 0 an hour ago)
    $d = 'esports/'.LeagueKey::fromConfig()->pubkey();
    $socks = array_map(fn () => (new TestSigner)->pubkey, range(1, 14));
    $players = array_map(fn () => (new TestSigner)->pubkey, range(1, 8));

    // alice vouches for 14 accounts (rank 55 each: allowed to report); carol for 8 players (rank 75).
    archivedEvent(pk('alice'), 30000, [['d', $d], ...array_map(fn (string $p) => ['p', $p], $socks)], $at);
    archivedEvent(pk('carol'), 30000, [['d', $d], ...array_map(fn (string $p) => ['p', $p], $players)], $at);
    app(TrustJob::class)->run();

    // Each of the 14 reports three players: 42 reports, every player hit five times or more.
    foreach ($socks as $i => $sock) {
        foreach (range(0, 2) as $j) {
            archivedEvent($sock, TrustJob::REPORT, [['p', $players[($i * 3 + $j) % 8], 'other'], ['L', TrustJob::LABEL_NAMESPACE], ['l', 'cheating', TrustJob::LABEL_NAMESPACE]], $at + 60 + $i * 3 + $j);
        }
    }
    app(TrustJob::class)->run();

    $eligible = TrustRank::query()->whereIn('pubkey', $players)->where('rank', '>=', 50)->count();

    // Without the subtree cap all eight drop to 25; with it three reports count, each player stays at 50 or more.
    expect(TrustRank::query()->whereIn('pubkey', $socks)->min('rank'))->toBe(55)
        ->and($eligible)->toBe(8);
});

/**
 * Round 4 test bed: alice vouches for $reporters (one subtree, rank 55 or
 * more each: allowed to report), carol for eight players (rank 75 each).
 *
 * @param  list<string>  $reporters
 * @return list<string> the players
 */
function reportSubtree(array $reporters, int $at): array
{
    $d = 'esports/'.LeagueKey::fromConfig()->pubkey();
    $players = array_map(fn () => (new TestSigner)->pubkey, range(1, 8));

    archivedEvent(pk('alice'), 30000, [['d', $d], ...array_map(fn (string $p) => ['p', $p], $reporters)], $at);
    archivedEvent(pk('carol'), 30000, [['d', $d], ...array_map(fn (string $p) => ['p', $p], $players)], $at);
    app(TrustJob::class)->run();

    return $players;
}

function archivedReport(string $author, string $target, int $at): void
{
    archivedEvent($author, TrustJob::REPORT, [['p', $target, 'other'], ['L', TrustJob::LABEL_NAMESPACE], ['l', 'cheating', TrustJob::LABEL_NAMESPACE]], $at);
}

test('regression (security re-check round 4): backdated reports from the same subtree cannot push out a report that already counts', function () {
    payMembers(['alice', 'carol']);
    config(['esports.relays' => [], 'esports.trust.reports_per_anchor' => 3]);
    $at = now()->subMinutes(30)->getTimestamp();
    $reporters = array_map(fn () => (new TestSigner)->pubkey, range(1, 4));
    $players = reportSubtree($reporters, $at);

    archivedReport($reporters[0], $players[0], $at + 100);
    app(TrustJob::class)->run();
    expect(TrustRank::query()->where('pubkey', $players[0])->value('rank'))->toBe(50);

    // Three burners of the same subtree sign reports dated before the genuine one.
    foreach ([1, 2, 3] as $i) {
        archivedReport($reporters[$i], $players[$i], $at + 10);
    }
    app(TrustJob::class)->run();

    // The genuine report keeps its slot; of the burners only the first two seen fit the cap of 3.
    expect(array_map(fn (string $p) => TrustRank::query()->where('pubkey', $p)->value('rank'), array_slice($players, 0, 4)))->toBe([50, 50, 50, 75])
        ->and(TrustCountedReport::query()->count())->toBe(3);
});

test('round 4: a report that counted once stays counted for the season, even when an older report becomes eligible later', function () {
    payMembers(['alice', 'carol']);
    config(['esports.relays' => [], 'esports.trust.reports_per_anchor' => 1]);
    $at = now()->subMinutes(30)->getTimestamp();
    $genuine = (new TestSigner)->pubkey;
    $late = (new TestSigner)->pubkey;
    $players = reportSubtree([$genuine], $at);

    // $late reports first, while nobody vouches for it yet: it does not count.
    archivedReport($late, $players[1], $at + 10);
    archivedReport($genuine, $players[0], $at + 100);
    app(TrustJob::class)->run();

    // alice adds $late: it is ranked one run later, and its older report becomes eligible.
    archivedEvent(pk('alice'), 30000, [['d', 'esports/'.LeagueKey::fromConfig()->pubkey()], ['p', $genuine], ['p', $late]], $at + 200);
    app(TrustJob::class)->run();
    app(TrustJob::class)->run();

    expect(TrustRank::query()->where('pubkey', $late)->value('rank'))->toBeGreaterThanOrEqual(50)
        ->and(TrustRank::query()->where('pubkey', $players[0])->value('rank'))->toBe(50)
        ->and(TrustRank::query()->where('pubkey', $players[1])->value('rank'))->toBe(75);
});

test('round 4: within one run the caps fill in the order the reports were first seen, not by their created_at', function () {
    payMembers(['alice', 'carol']);
    config(['esports.relays' => [], 'esports.trust.reports_per_anchor' => 1]);
    $at = now()->subMinutes(30)->getTimestamp();
    $reporters = array_map(fn () => (new TestSigner)->pubkey, range(1, 2));
    $players = reportSubtree($reporters, $at);

    // Both arrive before the next run; the second claims to be older.
    archivedReport($reporters[0], $players[0], $at + 100);
    archivedReport($reporters[1], $players[1], $at + 10);
    app(TrustJob::class)->run();

    expect(TrustRank::query()->where('pubkey', $players[0])->value('rank'))->toBe(50)
        ->and(TrustRank::query()->where('pubkey', $players[1])->value('rank'))->toBe(75);
});

test('round 4: a report against a key that was never ranked uses no budget', function () {
    payMembers(['alice', 'carol']);
    config(['esports.relays' => [], 'esports.trust.reports_per_anchor' => 1]);
    $at = now()->subMinutes(30)->getTimestamp();
    $reporters = array_map(fn () => (new TestSigner)->pubkey, range(1, 2));
    $players = reportSubtree($reporters, $at);

    archivedReport($reporters[1], (new TestSigner)->pubkey, $at + 10);
    archivedReport($reporters[0], $players[0], $at + 100);
    app(TrustJob::class)->run();

    expect(TrustRank::query()->where('pubkey', $players[0])->value('rank'))->toBe(50)
        ->and(TrustCountedReport::query()->count())->toBe(1);
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
