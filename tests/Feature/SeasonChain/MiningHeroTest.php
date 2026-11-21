<?php

/*
 * The live hero of /mining (plan "mining-page-clarity" P2, canvas SeasonLive): one primary action per viewer (a guest
 * logs in, a player plays rated chess), and the player's own standing for rated play as chips from real data: the
 * trust rank against the minimum, the players they list each other with, and their chess blocks today against the
 * daily limit. A chip that blocks rated play links to its fix. The stat tile shows the cheapest to the dearest win.
 */

use App\Models\NostrEvent;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\AnchoredTrustFacts;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

require_once __DIR__.'/../../Support/key_eras.php';

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    config(['esports.chess.rated_queue' => true]);
    app()->bind(TrustFacts::class, AnchoredTrustFacts::class);
});

/** @return array{0: User, 1: TestSigner} */
function heroPlayer(string $name): array
{
    $signer = new TestSigner;

    return [User::factory()->withPubkey($signer->pubkey)->create(['name' => $name]), $signer];
}

/** Both players add each other, signed as the browser would. */
function heroListEachOther(array $a, array $b): void
{
    $opponents = app(Opponents::class);

    foreach ([[$a, $b], [$b, $a]] as [[$player, $signer], [$opponent]]) {
        $opponents->add($player, $opponent, $signer->signTemplates($opponents->prepareAdd($player, $opponent)));
        test()->travel(1)->seconds();
    }
}

/** @param array<string, int> $ranks pubkey => rank */
function heroTrustRun(array $ranks): void
{
    $trust = new TestSigner;
    $anchors = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30000, [['d', 'esports/x/anchors'], ['alt', 'anchors']])));
    $run = TrustRun::query()->create(['season_id' => Seasons::live()?->id, 'trust_pubkey' => $trust->pubkey, 'anchor_list_nostr_event_id' => $anchors->id,
        'anchors' => 0, 'lists' => 0, 'ranked' => count($ranks), 'published' => count($ranks), 'computed_at' => now()]);

    foreach ($ranks as $pubkey => $rank) {
        $assertion = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30382, [['d', $pubkey], ['p', $pubkey], ['rank', (string) $rank], ['alt', 'rank']])));
        TrustRank::query()->create(['pubkey' => $pubkey, 'rank' => $rank, 'raw' => 1.0, 'anchor_list_event_id' => $anchors->event_id,
            'trust_run_id' => $run->id, 'nostr_event_id' => $assertion->id, 'event_id' => $assertion->event_id]);
    }
}

/** The hero's primary action and the standing chips (state, text, link) of /mining for this viewer. */
function heroOf(?User $viewer): array
{
    $html = html_entity_decode((string) ($viewer ? test()->actingAs($viewer) : test())->get(route('mining'))->assertOk()->getContent());
    preg_match('/data-test="mining-primary" data-variant="([a-z]+)">([^<]+)</', $html, $primary);
    preg_match_all('/data-test="standing-([a-z]+)" data-state="([a-z]+)">(.*?)<\/li>/s', $html, $chips, PREG_SET_ORDER);
    $standing = [];

    foreach ($chips as [$all, $key, $state, $inner]) {
        preg_match('/<a href="([^"]+)"/', $inner, $link);
        $standing[$key] = [$state, trim((string) preg_replace('/\s+/', ' ', strip_tags($inner))), $link[1] ?? null];
    }

    return ['primary' => [$primary[1] ?? null, trim($primary[2] ?? '')], 'standing' => $standing, 'html' => $html];
}

test('a guest gets the log-in action and a line on what rated play needs, never a standing', function () {
    openSeason();

    $hero = heroOf(null);

    expect($hero['primary'])->toBe(['login', 'Log in to mine'])
        ->and($hero['html'])->toContain('href="'.route('login').'" class="rv-b h-[52px] px-6" data-test="mining-primary"')
        ->toContain('Rated play needs trust rank 50 and an opponent who adds you back.')
        ->not->toContain('data-test="mining-standing"')
        // The live state names the state, not the season ("Pre-Season" read as not started yet).
        ->and($hero['html'])->toMatch('/data-test="season-state">Live since /');
});

test('an eligible player gets rated chess and three green chips: trust rank, mutual opponents, chess blocks today', function () {
    openSeason();
    $anna = heroPlayer('anna');
    $bert = heroPlayer('bert');
    heroTrustRun([$anna[0]->pubkey => 80, $bert[0]->pubkey => 90]);
    heroListEachOther($anna, $bert);

    $hero = heroOf($anna[0]);

    expect($hero['primary'])->toBe(['rated', 'Play rated chess'])
        ->and($hero['standing'])->toBe([
            'trust' => ['ok', 'Trust rank 80, needs 50', null],
            'opponents' => ['ok', '1 mutual opponent', null],
            'today' => ['ok', 'Chess today 0 of 5 blocks', null],
        ])
        // Another player's figures never reach this viewer.
        ->and($hero['html'])->not->toContain('Trust rank 90');
});

test('a player below the trust minimum sees a red trust chip that links to the opponents page', function () {
    openSeason();
    $anna = heroPlayer('anna');
    $bert = heroPlayer('bert');
    heroTrustRun([$anna[0]->pubkey => 12, $bert[0]->pubkey => 90]);
    heroListEachOther($anna, $bert);

    $hero = heroOf($anna[0]);

    expect($hero['primary'])->toBe(['rated', 'Play rated chess'])
        ->and($hero['standing']['trust'])->toBe(['bad', 'Trust rank 12, needs 50 How it rises', route('settings.opponents')])
        ->and($hero['standing']['opponents'][0])->toBe('ok');
});

test('a player without a mutual opponent sees a red opponents chip that links to the opponents page', function () {
    openSeason();
    [$anna] = heroPlayer('anna');
    heroTrustRun([$anna->pubkey => 80]);

    $hero = heroOf($anna);

    expect($hero['standing']['trust'][0])->toBe('ok')
        ->and($hero['standing']['opponents'])->toBe(['bad', 'No mutual opponent Add one', route('settings.opponents')]);
});

test('before trust ranks are computed the trust chip waits instead of naming a rank', function () {
    openSeason();
    [$anna] = heroPlayer('anna');

    expect(heroOf($anna)['standing']['trust'])->toBe(['wait', 'Trust ranks not computed yet', null]);
});

test('the chess blocks of today count the viewer\'s own blocks against the daily limit', function () {
    // Midday UTC: the season opens an hour before now, so its wins fall on the same UTC day whenever the suite runs.
    $this->travelTo(now()->utc()->setTime(12, 0));
    $season = keySeason();
    $this->travelTo($season->genesis_at->copy()->addHour());
    keyWins($season, ['chess/blitz', 'chess/blitz']);
    $winner = User::query()->where('pubkey', $season->attestations()->whereNotNull('height')->orderBy('height')->first()->winners()[0])->firstOrFail();

    expect(heroOf($winner)['standing']['today'])->toBe(['ok', 'Chess today 1 of 5 blocks', null]);
});

test('the stat tile shows the cheapest to the dearest win now, from the same chain rows the stream slides read', function () {
    $season = keySeason();
    $this->travelTo($season->genesis_at->copy()->addHour());
    $keys = app(ChainOverview::class)->live($season)['keys'];
    $pays = collect($keys)->flatMap(fn (array $key): array => array_column(array_filter($key['modes'], fn (array $mode, string $modeKey): bool => ChainOverview::mines($modeKey) && $mode['pays'] > 0, ARRAY_FILTER_USE_BOTH), 'pays'));

    $html = heroOf(null)['html'];

    expect([$pays->min(), $pays->max()])->toBe([2_100, 3_150])
        ->and(trim((string) preg_replace('/\s+/', ' ', strip_tags(str($html)->after('data-test="stat-win-range"')->before('</div>')->toString()))))
        ->toBe('> A win pays 2,100 to 3,150 sats per winner now, by mode');
});

test('before Block 0 and between seasons the page keeps its rest hero and shows no live hero', function () {
    $html = (string) $this->get(route('mining'))->assertOk()->getContent();

    expect($html)->toContain('data-state="pre-launch"')->toContain('data-test="mining-rest"')->not->toContain('data-test="mining-hero"');
});
