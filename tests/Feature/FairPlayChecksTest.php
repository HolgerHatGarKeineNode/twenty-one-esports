<?php

use App\Enums\ChessEndReason;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\FairPlayBar;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\TrustExclusion;
use App\Models\TrustRank;
use App\Models\TrustReportConfirmation;
use App\Models\TrustReportDismissal;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\FairPlay\Checks;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Fair play: the admin checking view (/admin/checks)
|--------------------------------------------------------------------------
|
| Admins see which players of the live season look conspicuous, as chips
| with the number that triggered each, and go on to the bar form. The page
| only reads: nothing is barred by it, and only the live season counts.
|
*/

beforeEach(function () {
    Queue::fake();
    $this->withoutVite();
    $this->freezeSecond();
});

function ckAdmin(): User
{
    $admin = User::factory()->create(['name' => 'Referee', 'created_at' => now()->subYear()]);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** A season live for 30 days: pair limit 3 per season, 20 moves at least, trust minimum 50. */
function ckSeason(array $attributes = []): Season
{
    $season = openSeason(['slug' => 'season-1', 'genesis_at' => now()->subDays(30), 'ends_at' => now()->addDays(60), 'minimum_trust' => 50, ...$attributes], ladders: false);
    $season->forceFill(['parameters' => [...$season->parameters, 'pairlimit' => [1, 3], 'moves' => 20]])->save();

    return $season;
}

/** An account a year old, so it is never new. */
function ckPlayer(string $name): User
{
    return User::factory()->create(['name' => $name, 'created_at' => now()->subYear()]);
}

/** The player's published trust: Trusted with a spread source unless the arguments say otherwise. */
function ckTrust(User $player, int $rank = 100, ?string $anchor = null, int $share = 40): void
{
    $trust = new TestSigner;
    $anchors = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30000, [['d', 'esports/x/anchors'], ['alt', 'anchors']])));
    $run = TrustRun::query()->create(['trust_pubkey' => $trust->pubkey, 'anchor_list_nostr_event_id' => $anchors->id,
        'anchors' => 0, 'lists' => 0, 'ranked' => 1, 'published' => 1, 'computed_at' => now()]);
    $assertion = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30382, [['d', $player->pubkey], ['p', $player->pubkey], ['rank', (string) $rank], ['alt', 'rank']])));

    TrustRank::query()->create(['pubkey' => $player->pubkey, 'rank' => $rank, 'raw' => 1.0, 'anchor' => $anchor ?? str_repeat('a', 64), 'anchor_share' => $share,
        'anchor_list_event_id' => $anchors->event_id, 'trust_run_id' => $run->id, 'nostr_event_id' => $assertion->id, 'event_id' => $assertion->event_id]);
}

/** A Trusted player with an old account: nothing about him is conspicuous yet. */
function ckClean(string $name): User
{
    $player = ckPlayer($name);
    ckTrust($player);

    return $player;
}

/**
 * A win of `$winner` over `$loser` as the league attests it: a mined block,
 * or with `reason` a candidate that was refused.
 *
 * @param  array{reason?: string, subject?: string, source?: string, source_id?: int, days_ago?: float, reward?: int}  $with
 */
function ckWin(Season $season, User $winner, User $loser, array $with = []): SeasonAttestation
{
    static $number = 0;
    $number++;
    $refused = isset($with['reason']);

    return SeasonAttestation::query()->create([
        'season_id' => $season->id, 'source' => $with['source'] ?? 'chess', 'source_id' => $with['source_id'] ?? 900_000 + $number, 'label' => '#'.$number,
        'game' => 'chess', 'mode' => 'blitz', 'ladder_address' => 'chess/blitz', 'attested_at' => now()->subSeconds((int) round(($with['days_ago'] ?? 1) * 86400)),
        'candidate' => ['game' => 'chess', 'winners' => [$winner->pubkey], 'losers' => [$loser->pubkey], 'pairing' => ['user:'.$winner->id, 'user:'.$loser->id]],
        'height' => $refused ? null : (int) SeasonAttestation::query()->where('season_id', $season->id)->max('height') + 1,
        'reason' => $with['reason'] ?? null, 'subject' => $with['subject'] ?? null,
        'era' => 1, 'reward_per_player' => $refused ? 0 : ($with['reward'] ?? 5_000), 'reward' => $refused ? 0 : ($with['reward'] ?? 5_000),
        'event_id' => hash('sha256', 'check-'.$number),
    ]);
}

/** `$count` blocks against `$count` different opponents. */
function ckVaried(Season $season, User $player, int $count): void
{
    foreach (range(1, $count) as $index) {
        ckWin($season, $player, ckPlayer('Opponent '.$index.' of '.$player->name));
    }
}

/** A block from a finished chess game of `$ply` plies that ended this way. */
function ckChessWin(Season $season, User $winner, int $ply, ChessEndReason $end): void
{
    $loser = ckPlayer('Loser at ply '.$ply);
    $game = ChessGame::factory()->finished('1-0', $end)->create(['white_id' => $winner->id, 'black_id' => $loser->id, 'ply' => $ply, 'rated' => true]);

    ckWin($season, $winner, $loser, ['source_id' => $game->id]);
}

/** A seat of a Hyperbitcoinization match the player gave up. */
function ckForfeit(User $player, float $daysAgo, bool $rated = true): void
{
    HyperSeat::factory()->create(['hyper_match_id' => HyperMatch::factory()->create(['rated' => $rated])->id, 'seat' => 5, 'user_id' => $player->id,
        'bot' => true, 'takeover' => HyperSeat::TAKEOVER_FORFEIT, 'left_at' => now()->subSeconds((int) round($daysAgo * 86400))]);
}

/** A league report against the player, signed `$daysAgo` days ago; nobody decided about it yet. */
function ckReport(User $target, float $daysAgo = 1): NostrEvent
{
    return NostrEvent::fromSigned(SignedEvent::fromInput((new TestSigner)->sign(1984, [['p', $target->pubkey, 'other'], ['L', 'space.einundzwanzig.esports'], ['l', 'multi-account', 'space.einundzwanzig.esports']],
        'Two accounts, one person.', now()->subSeconds((int) round($daysAgo * 86400))->getTimestamp())));
}

/**
 * The row of one player as the page lists it with "all players" on.
 *
 * @return array{signals: array<string, string>, states: array<string, string>, blocks: int, sats: int, opponents: list<array{name: string, won: int, lost: int}>}|null
 */
function ckRow(Season $season, User $player): ?array
{
    $row = collect(app(Checks::class)->read($season, all: true)['rows'])->firstWhere('pubkey', $player->pubkey);

    return $row === null ? null : [
        'signals' => array_column($row['signals'], 'label', 'key'),
        'states' => array_column($row['states'], 'label', 'key'),
        'blocks' => $row['blocks'],
        'sats' => $row['sats'],
        'opponents' => $row['opponents'],
    ];
}

/** Arranges one case of the signal dataset around the player "Ada". */
function ckArrange(string $case, Season $season, User $ada): void
{
    $xaver = ckPlayer('Xaver');

    match ($case) {
        'three of five blocks from one opponent' => [ckWin($season, $ada, $xaver), ckWin($season, $ada, $xaver), ckWin($season, $ada, $xaver), ckVaried($season, $ada, 2)],
        'three of six blocks from one opponent' => [ckWin($season, $ada, $xaver), ckWin($season, $ada, $xaver), ckWin($season, $ada, $xaver), ckVaried($season, $ada, 3)],
        'two blocks, both from one opponent' => [ckWin($season, $ada, $xaver), ckWin($season, $ada, $xaver)],
        'a pairing with three blocks' => [ckWin($season, $ada, $xaver), ckWin($season, $xaver, $ada), ckWin($season, $ada, $xaver), ckVaried($season, $ada, 4)],
        'a pairing with two blocks and a refused third' => [ckWin($season, $ada, $xaver), ckWin($season, $ada, $xaver), ckWin($season, $ada, $xaver, ['reason' => 'too-few-moves']), ckVaried($season, $ada, 4)],
        'three wins refused at a limit' => [ckVaried($season, $ada, 4), ckWin($season, $ada, $xaver, ['reason' => 'pairing-season-limit']),
            ckWin($season, $ada, $xaver, ['reason' => 'pairing-daily-limit']), ckWin($season, $ada, $xaver, ['reason' => 'player-daily-limit', 'subject' => $ada->pubkey])],
        'two wins refused at a limit, one for another reason' => [ckVaried($season, $ada, 4), ckWin($season, $ada, $xaver, ['reason' => 'pairing-season-limit']),
            ckWin($season, $ada, $xaver, ['reason' => 'player-daily-limit', 'subject' => $ada->pubkey]), ckWin($season, $ada, $xaver, ['reason' => 'too-few-moves'])],
        'two of four chess wins by resignation or timeout at ply 46' => [ckChessWin($season, $ada, 46, ChessEndReason::Resignation), ckChessWin($season, $ada, 41, ChessEndReason::Timeout),
            ckChessWin($season, $ada, 90, ChessEndReason::Resignation), ckChessWin($season, $ada, 90, ChessEndReason::Checkmate)],
        'one short win, one at ply 47, one short checkmate' => [ckChessWin($season, $ada, 46, ChessEndReason::Resignation), ckChessWin($season, $ada, 47, ChessEndReason::Resignation),
            ckChessWin($season, $ada, 41, ChessEndReason::Checkmate), ckChessWin($season, $ada, 90, ChessEndReason::Checkmate)],
        'two blocks each way with one opponent' => [ckTrust($xaver), ckWin($season, $ada, $xaver), ckWin($season, $ada, $xaver), ckWin($season, $xaver, $ada), ckWin($season, $xaver, $ada), ckVaried($season, $ada, 4), ckVaried($season, $xaver, 4)],
        'two blocks one way, one back' => [ckTrust($xaver), ckWin($season, $ada, $xaver), ckWin($season, $ada, $xaver), ckWin($season, $xaver, $ada), ckVaried($season, $ada, 4), ckVaried($season, $xaver, 4)],
        'one rated match left in the season' => [ckVaried($season, $ada, 4), ckForfeit($ada, 2)],
        'a match left before Block 0 and a casual one left' => [ckVaried($season, $ada, 4), ckForfeit($ada, 31), ckForfeit($ada, 2, rated: false)],
        'the whole trust from one member' => [ckVaried($season, $ada, 4), TrustRank::query()->where('pubkey', $ada->pubkey)->update(['anchor' => $xaver->pubkey, 'anchor_share' => 100])],
        '99 percent of the trust from one member' => [ckVaried($season, $ada, 4), TrustRank::query()->where('pubkey', $ada->pubkey)->update(['anchor' => $xaver->pubkey, 'anchor_share' => 99])],
        'a member who is his own source' => [ckVaried($season, $ada, 4), TrustRank::query()->where('pubkey', $ada->pubkey)->update(['anchor' => $ada->pubkey, 'anchor_share' => 100])],
        'rank 49 after mining' => [ckVaried($season, $ada, 4), TrustRank::query()->where('pubkey', $ada->pubkey)->update(['rank' => 49])],
        'no trust rank after mining' => [ckVaried($season, $ada, 4), TrustRank::query()->where('pubkey', $ada->pubkey)->delete()],
        'rank 50 after mining' => [ckVaried($season, $ada, 4), TrustRank::query()->where('pubkey', $ada->pubkey)->update(['rank' => 50])],
        'no trust rank, only a refused win' => [ckWin($season, $ada, $xaver, ['reason' => 'not-trusted']), TrustRank::query()->where('pubkey', $ada->pubkey)->delete()],
        'an account seven days older than its first block' => [$ada->forceFill(['created_at' => now()->subDays(9)])->save(), ckWin($season, $ada, $xaver, ['days_ago' => 2]), ckVaried($season, $ada, 3)],
        'an account seven days and an hour older than its first block' => [$ada->forceFill(['created_at' => now()->subDays(9)->subHour()])->save(), ckWin($season, $ada, $xaver, ['days_ago' => 2]), ckVaried($season, $ada, 3)],
        'one waiting report' => [ckVaried($season, $ada, 4), ckReport($ada), ckReport($xaver)],
        'a dismissed report, a confirmed one, one of an excluded author, one from before Block 0' => [
            ckVaried($season, $ada, 4),
            TrustReportDismissal::query()->create(['event_id' => ckReport($ada)->event_id, 'dismissed_by_id' => $xaver->id, 'reason' => 'Same household.']),
            TrustReportConfirmation::query()->create(['event_id' => ckReport($ada)->event_id, 'confirmed_by_id' => $xaver->id, 'reason' => 'Checked.']),
            TrustExclusion::query()->create(['pubkey' => ckReport($ada)->pubkey, 'excluded_by_id' => $xaver->id, 'reason' => 'Mass reporting.']),
            ckReport($ada, 31),
        ],
    };
}

test('a guest and a player are refused, an admin opens the page and finds it in the admin nav', function () {
    $player = ckPlayer('Ada');
    $admin = ckAdmin();

    $this->get(route('admin.checks'))->assertRedirect(route('login'));
    $this->actingAs($player)->get(route('admin.checks'))->assertForbidden();
    Livewire::actingAs($player)->test('pages::admin.checks')->assertForbidden();

    $this->actingAs($admin)->get(route('admin.checks'))->assertOk()
        ->assertSee('data-test="admin-checks"', false)
        ->assertSee('data-test="admin-nav-checks"', false)
        ->assertSee('href="'.route('admin.checks').'"', false);
});

test('a signal shows its chip with the number that triggered it', function (string $case, string $signal, string $label) {
    $season = ckSeason();
    $ada = ckClean('Ada');
    ckArrange($case, $season, $ada);

    expect(ckRow($season, $ada)['signals'])->toHaveKey($signal, $label);

    // On the default list, the chip as the admin reads it.
    $this->actingAs(ckAdmin())->get(route('admin.checks'))->assertOk()
        ->assertSee('data-signal="'.$signal.'"', false)
        ->assertSee($label);
})->with([
    ['three of five blocks from one opponent', 'dominant', '60 % of the blocks against Xaver (3 of 5)'],
    ['a pairing with three blocks', 'pair-limit', 'Pairings at the season limit: 1'],
    ['three wins refused at a limit', 'limits', 'Wins refused at a limit: 3'],
    ['two of four chess wins by resignation or timeout at ply 46', 'short-wins', 'Short wins: 2 of 4'],
    ['two blocks each way with one opponent', 'mutual', 'Mutual wins with Xaver: 2 to 2'],
    ['one rated match left in the season', 'forfeits', 'Rated matches left: 1'],
    ['the whole trust from one member', 'thin-trust', 'Trust through Xaver alone: 100 %'],
    ['rank 49 after mining', 'no-trust', 'Trust below the minimum now: 49'],
    ['no trust rank after mining', 'no-trust', 'Trust below the minimum now: 0'],
    ['an account seven days older than its first block', 'new-account', 'New account: 7 days before its first block'],
    ['one waiting report', 'reports', 'Open reports: 1'],
]);

test('just under its threshold a signal shows no chip', function (string $case, string $signal) {
    $season = ckSeason();
    $ada = ckClean('Ada');
    ckArrange($case, $season, $ada);

    expect(ckRow($season, $ada))->not->toBeNull()
        ->and(ckRow($season, $ada)['signals'])->not->toHaveKey($signal);
})->with([
    ['three of six blocks from one opponent', 'dominant'],
    ['two blocks, both from one opponent', 'dominant'],
    ['a pairing with two blocks and a refused third', 'pair-limit'],
    ['two wins refused at a limit, one for another reason', 'limits'],
    ['one short win, one at ply 47, one short checkmate', 'short-wins'],
    ['two blocks one way, one back', 'mutual'],
    ['a match left before Block 0 and a casual one left', 'forfeits'],
    ['99 percent of the trust from one member', 'thin-trust'],
    ['a member who is his own source', 'thin-trust'],
    ['rank 50 after mining', 'no-trust'],
    ['no trust rank, only a refused win', 'no-trust'],
    ['an account seven days and an hour older than its first block', 'new-account'],
    ['a dismissed report, a confirmed one, one of an excluded author, one from before Block 0', 'reports'],
]);

test('mutual wins show on both rows with the partner, and the opponents list counts the blocks each way', function () {
    $season = ckSeason();
    $ada = ckClean('Ada');
    ckArrange('two blocks each way with one opponent', $season, $ada);
    $xaver = User::query()->where('name', 'Xaver')->sole();

    expect(ckRow($season, $xaver)['signals'])->toHaveKey('mutual', 'Mutual wins with Ada: 2 to 2')
        ->and(ckRow($season, $ada)['opponents'][0])->toBe(['name' => 'Xaver', 'won' => 2, 'lost' => 2])
        ->and(ckRow($season, $ada)['opponents'])->toHaveCount(5);

    $this->actingAs(ckAdmin())->get(route('admin.checks'))->assertOk()
        ->assertSee('data-test="checks-opponents"', false)
        ->assertSee('2 won · 2 lost');
});

test('a row counts the blocks and sats the player mined, never a refused win', function () {
    $season = ckSeason();
    $ada = ckClean('Ada');
    $xaver = ckPlayer('Xaver');
    ckWin($season, $ada, $xaver, ['reward' => 5_000]);
    ckWin($season, $ada, ckPlayer('Yann'), ['reward' => 2_500]);
    ckWin($season, $ada, $xaver, ['reason' => 'pairing-daily-limit']);

    expect(ckRow($season, $ada))->toMatchArray(['blocks' => 2, 'sats' => 7_500]);

    $this->actingAs(ckAdmin())->get(route('admin.checks', ['all' => 1]))->assertOk()
        ->assertSee('2 blocks')
        ->assertSee('7,500');
});

test('barred is a state on the row and never puts a player on the list', function () {
    $season = ckSeason();
    $admin = ckAdmin();
    $ada = ckClean('Ada');
    ckVaried($season, $ada, 4);
    FairPlayBar::query()->create(['user_id' => $ada->id, 'pubkey' => $ada->pubkey, 'reason' => 'Ticket 4711', 'until' => now()->addDays(3), 'barred_by_id' => $admin->id, 'barred_by_pubkey' => $admin->pubkey]);

    expect(ckRow($season, $ada)['states'])->toHaveKey('barred')
        ->and(ckRow($season, $ada)['signals'])->toBe([]);

    $this->actingAs($admin)->get(route('admin.checks'))->assertOk()->assertDontSee('data-test="checks-row"', false);
    $this->actingAs($admin)->get(route('admin.checks', ['all' => 1]))->assertOk()
        ->assertSee('data-state="barred"', false)
        // The internal reason stays on the fair-play page.
        ->assertDontSee('Ticket 4711');
});

test('only the live season counts: an ended season and a season before its Block 0 show nobody', function () {
    $admin = ckAdmin();
    $old = ckClean('Oldtimer');
    $ended = Season::factory()->ended()->create(['slug' => 'pre-season']);
    ckWin($ended, $old, ckPlayer('Xaver'));
    ckWin($ended, $old, ckPlayer('Xaver'));
    ckWin($ended, $old, ckPlayer('Xaver'));

    // Between seasons: the ended season's results are not read.
    $this->actingAs($admin)->get(route('admin.checks', ['all' => 1]))->assertOk()
        ->assertSee('data-empty="no-season"', false)
        ->assertDontSee('Oldtimer');

    // A released season whose Block 0 lies ahead is not live either.
    $planned = ckSeason(['genesis_at' => now()->addDay(), 'ends_at' => now()->addDays(60)]);
    ckWin($planned, $old, ckPlayer('Yann'));
    $this->actingAs($admin)->get(route('admin.checks', ['all' => 1]))->assertOk()
        ->assertSee('data-empty="no-season"', false)
        ->assertDontSee('Oldtimer');

    // Live now: its own results show, the ended season's still do not.
    $this->travel(2)->days();
    $ada = ckClean('Ada');
    ckWin($planned->refresh(), $ada, ckPlayer('Zoe'));

    $this->actingAs($admin)->get(route('admin.checks', ['all' => 1]))->assertOk()
        ->assertSee('Ada')
        ->assertSee('Oldtimer');
    expect(ckRow($planned, $old))->toMatchArray(['blocks' => 1])
        ->and(ckRow($planned, $old)['signals'])->not->toHaveKey('pair-limit')
        ->and(ckRow($planned, $old)['signals'])->not->toHaveKey('dominant');
});

test('an honest player has no row until all players are switched on', function () {
    $season = ckSeason();
    $admin = ckAdmin();
    $ada = ckClean('Ada');
    ckVaried($season, $ada, 3);
    ckChessWin($season, $ada, 92, ChessEndReason::Resignation);
    ckChessWin($season, $ada, 64, ChessEndReason::Checkmate);

    expect(ckRow($season, $ada)['signals'])->toBe([]);

    $this->actingAs($admin)->get(route('admin.checks'))->assertOk()
        ->assertSee('data-empty="no-signals"', false)
        ->assertDontSee('data-test="checks-row"', false);

    Livewire::actingAs($admin)->test('pages::admin.checks')
        ->assertDontSeeHtml('data-test="checks-row"')
        ->call('toggleAll')
        ->assertSet('all', true)
        ->assertSeeHtml('data-test="checks-row"')
        ->assertSee('Ada')
        ->assertDontSeeHtml('data-test="checks-chip"');
});

test('before anybody mined the page says so', function () {
    ckSeason();

    $this->actingAs(ckAdmin())->get(route('admin.checks'))->assertOk()->assertSee('data-empty="no-players"', false);
});

test('the page costs the same number of queries for 5 and for 50 players', function () {
    $season = ckSeason();
    $admin = ckAdmin();
    $arrange = function (int $from, int $to) use ($season): void {
        foreach (range($from, $to) as $index) {
            $player = ckClean('Player '.$index);
            $rival = ckClean('Rival '.$index);
            ckChessWin($season, $player, 44, ChessEndReason::Resignation);
            ckWin($season, $player, $rival);
            ckWin($season, $rival, $player);
            ckForfeit($player, 1);
            ckReport($player);
        }
    };
    $queries = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.checks', ['all' => 1]))->assertOk()->assertSee('data-test="checks-row"', false);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $arrange(1, 3);
    // The first request of a session stamps the membership check; that is the login's, not the page's.
    $queries();
    $few = $queries();
    expect(app(Checks::class)->read($season, all: true)['players'])->toBe(6);

    $arrange(4, 25);
    expect(app(Checks::class)->read($season, all: true)['players'])->toBe(50)
        ->and($queries())->toBe($few);
});

test('the list is capped and says how many it shows', function () {
    config(['esports.fair_play.checks.rows' => 2]);
    $season = ckSeason();
    foreach (['Ada', 'Bea', 'Cem'] as $name) {
        ckVaried($season, ckClean($name), 1);
    }

    $this->actingAs(ckAdmin())->get(route('admin.checks', ['all' => 1]))->assertOk()
        ->assertSee('data-test="checks-more"', false)
        ->assertSee('Showing 2 of 3.');
});

test('"Bar…" opens the fair-play page with the player picked in the bar form and writes nothing', function () {
    $season = ckSeason();
    $admin = ckAdmin();
    $ada = ckClean('Ada');
    ckArrange('one rated match left in the season', $season, $ada);
    $href = route('admin.fair-play', ['bar' => $ada->npub]).'#fair-play-bar';

    $this->actingAs($admin)->get(route('admin.checks'))->assertOk()
        ->assertSee('href="'.e($href).'"', false)
        ->assertSee('href="'.route('players.show', $ada->npub).'"', false);

    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    // The first request of a session stamps the membership check; that is the login's, not the page's.
    $this->actingAs($admin)->get(route('admin.fair-play'))->assertOk();
    $writes = [];

    $this->actingAs($admin)->get($href)->assertOk()
        ->assertSee('id="fair-play-bar"', false)
        // The picker opens with the player's chip.
        ->assertSee('\u0022name\u0022:\u0022Ada\u0022', false);
    $page = Livewire::withQueryParams(['bar' => $ada->npub])->actingAs($admin)->test('pages::admin.fair-play')
        ->assertSet('barPlayer', $ada->pubkey)
        ->assertSet('barReason', '');

    expect($writes)->toBe([])
        ->and(FairPlayBar::query()->count())->toBe(0);

    // The pick alone bars nobody: the button still asks for the reason.
    $page->call('bar')->assertHasErrors('barReason');
    expect(FairPlayBar::query()->count())->toBe(0);

    $page->set('barReason', 'Left a rated match, ticket 4712')->call('bar')->assertHasNoErrors();
    expect(FairPlayBar::query()->where('pubkey', $ada->pubkey)->count())->toBe(1);

    // A key without an account here, or no key at all, picks nobody.
    Livewire::withQueryParams(['bar' => (new TestSigner)->pubkey])->actingAs($admin)->test('pages::admin.fair-play')->assertSet('barPlayer', null);
    Livewire::withQueryParams(['bar' => 'Ada'])->actingAs($admin)->test('pages::admin.fair-play')->assertSet('barPlayer', null);
    Livewire::actingAs($admin)->test('pages::admin.fair-play')->assertSet('barPlayer', null);
});

test('the page reads in German', function () {
    $season = ckSeason();
    $ada = ckClean('Ada');
    ckArrange('two blocks each way with one opponent', $season, $ada);
    ckForfeit($ada, 2);

    $this->actingAs(ckAdmin())->get(route('admin.checks', ['lang' => 'de']))->assertOk()
        ->assertSee('Auffälligkeiten')
        ->assertSee('Auffällige Spieler')
        ->assertSee('Gegenseitige Siege mit Xaver: 2 zu 2')
        ->assertSee('Gewertete Matches verlassen: 1')
        ->assertSee('Sperren…')
        ->assertSee('Spielerseite')
        ->assertSee('Alle Spieler')
        ->assertDontSee('Conspicuous players')
        ->assertDontSee('Mutual wins')
        ->assertDontSee('Player page');
});
