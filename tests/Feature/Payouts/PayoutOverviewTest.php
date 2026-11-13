<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Navigation\AdminNavigation;
use App\Support\Payouts\PayoutOverview;
use App\Support\Prizes\PrizeChips;
use App\Support\Prizes\PrizePool;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| The overview of the admin payouts page (user, 2026-11-13: "eine weltbeste Filterfunktion und eine Übersicht der
| Turniere. Prio A sind Turniere, die auf Auszahlung warten"): every tournament with a pot and one payout state read
| from its data, what waits first, filters by state, game and name, and a page that never opens a tournament by itself.
*/

/** The ids of the tournaments the page lists, in order. */
function payoutOverviewIds(Testable $page): array
{
    preg_match_all('/data-test="payouts-row" data-tournament="(\d+)"/', $page->html(), $rows);

    return array_map(intval(...), $rows[1]);
}

test('each payout state is read from the tournament and its payouts', function (string $state) {
    $tournament = potTournamentIn($state);

    expect(PayoutOverview::stateOf($tournament->id))->toBe($state)
        ->and((new PayoutOverview)->counts()[$state])->toBe(1)
        ->and((new PayoutOverview)->counts()[PayoutOverview::FILTER_WAITING])->toBe(PayoutOverview::waits($state) ? 1 : 0);
})->with(PayoutOverview::STATES);

test('an unchecked tournament only waits once it has finished, whatever its status before', function (TournamentStatus $status) {
    $tournament = potTournamentIn(PayoutOverview::NOT_DUE, ['status' => $status]);

    expect(PayoutOverview::stateOf($tournament->id))->toBe(PayoutOverview::NOT_DUE)
        ->and(PayoutOverview::waitingTotal())->toBe(0);
})->with([TournamentStatus::Draft, TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running, TournamentStatus::Cancelled]);

test('the most urgent payout decides the state, and a checked tournament without payouts is paid', function () {
    $mixed = potTournamentIn(PayoutOverview::PAID, payouts: [[PayoutStatus::Paid, 10], [PayoutStatus::Open, 10, 'no_lud16'], [PayoutStatus::Pending, 10], [PayoutStatus::Failed, 10, 'wallet_error'], [PayoutStatus::Paying, 10, 'needs_check']]);
    $inFlight = potTournamentIn(PayoutOverview::PAID, payouts: [[PayoutStatus::Paying, 10, 'unconfirmed'], [PayoutStatus::Paid, 10]]);
    $none = potTournamentIn(PayoutOverview::PAID, payouts: []);

    expect(PayoutOverview::stateOf($mixed->id))->toBe(PayoutOverview::NEEDS_CHECK)
        ->and(PayoutOverview::stateOf($inFlight->id))->toBe(PayoutOverview::PAYING)
        ->and(PayoutOverview::stateOf($none->id))->toBe(PayoutOverview::PAID);
});

test('a tournament without a pot is not part of the overview', function () {
    $without = Tournament::factory()->create(['status' => TournamentStatus::Finished]);

    expect(PayoutOverview::stateOf($without->id))->toBeNull()
        ->and((new PayoutOverview)->counts()[PayoutOverview::FILTER_ALL])->toBe(0);
});

test('a row carries its pot, what still waits, what is paid and when it finished, in a fixed number of queries', function () {
    $tournament = potTournamentIn(PayoutOverview::FAILED, ['prize_target_sats' => 123_456_789]);
    $stage = $tournament->stages()->create(['number' => 1, 'format' => $tournament->format, 'status' => 'finished']);
    $roundId = DB::table('tournament_rounds')->insertGetId(['tournament_stage_id' => $stage->id, 'number' => 1]);
    DB::table('tournament_matches')->insert(['tournament_id' => $tournament->id, 'tournament_round_id' => $roundId, 'key' => 'final', 'bracket' => 'main', 'position' => 1, 'updated_at' => '2026-10-12 20:15:00']);
    foreach (range(1, 5) as $ignored) {
        potTournamentIn(PayoutOverview::PAID);
    }

    DB::enableQueryLog();
    $rows = (new PayoutOverview)->page(PayoutOverview::FILTER_ALL, 1);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $row = $rows->getCollection()->firstWhere('id', $tournament->id);
    $pot = PrizeChips::of($row)['sats'];

    expect($row->getAttribute('payout_state'))->toBe(PayoutOverview::FAILED)
        ->and($pot)->toBe(123_456_789)->toBe(app(PrizePool::class)->potSats($tournament))
        ->and((int) $row->getAttribute('payouts_count'))->toBe(3)
        ->and((int) $row->getAttribute('unpaid_count'))->toBe(3)
        ->and((int) $row->getAttribute('unpaid_sats'))->toBe(100_000)
        ->and((int) $row->getAttribute('paid_count'))->toBe(0)
        ->and(PayoutOverview::finishedAt($row)?->toDateTimeString())->toBe('2026-10-12 20:15:00')
        // The count, the page and the four grouped reads of the pots: the same for six rows as for one.
        ->and($queries)->toBe(6);
});

test('the page lands on what waits, most urgent first, and opens no tournament', function () {
    $paid = potTournamentIn(PayoutOverview::PAID, ['name' => 'Paid Cup']);
    $unchecked = potTournamentIn(PayoutOverview::UNCHECKED, ['name' => 'Unchecked Cup']);
    $signup = potTournamentIn(PayoutOverview::NOT_DUE, ['name' => 'Signup Cup']);
    $ready = potTournamentIn(PayoutOverview::READY, ['name' => 'Ready Cup']);
    $failed = potTournamentIn(PayoutOverview::FAILED, ['name' => 'Failed Cup']);

    $page = Livewire::actingAs(anAdmin())->test('pages::admin.payouts')
        ->assertSet('tournamentId', null)
        ->assertSeeHtml('data-test="payouts-overview" data-filter="waiting"')
        ->assertDontSeeHtml('data-test="payouts-tournament-panel"')
        ->assertDontSee('Signup Cup')->assertDontSee('Paid Cup');

    expect(payoutOverviewIds($page))->toBe([$failed->id, $ready->id, $unchecked->id]);

    // Everything: what waits stays on top, then the rest.
    $page->call('showState', PayoutOverview::FILTER_ALL)->assertSet('state', 'all');
    expect(payoutOverviewIds($page))->toBe([$failed->id, $ready->id, $unchecked->id, $signup->id, $paid->id]);
});

test('with nothing waiting the page lands on all tournaments, and the waiting filter says everything is paid', function () {
    $paid = potTournamentIn(PayoutOverview::PAID);

    $page = Livewire::actingAs(anAdmin())->test('pages::admin.payouts')
        ->assertSeeHtml('data-test="payouts-overview" data-filter="all"')
        ->assertSeeHtml('data-test="readout-waiting" data-count="0"')
        ->assertDontSeeHtml('data-test="payouts-all-paid"');
    expect(payoutOverviewIds($page))->toBe([$paid->id]);

    $page->call('showState', PayoutOverview::FILTER_WAITING)
        ->assertSeeHtml('data-test="payouts-all-paid"')
        ->assertDontSeeHtml('data-test="payouts-no-hits"');
});

test('the state chips, the search and the game filter narrow the list', function () {
    $chessReady = potTournamentIn(PayoutOverview::READY, ['name' => 'Blitz Night Köln']);
    $rocketReady = potTournamentIn(PayoutOverview::READY, ['name' => 'Rocket 100%_Cup', 'game' => 'rocket-league', 'mode' => '3v3']);
    $chessPaid = potTournamentIn(PayoutOverview::PAID, ['name' => 'Blitz Night Wien']);

    $page = Livewire::actingAs(anAdmin())->test('pages::admin.payouts')->call('showState', PayoutOverview::PAID);
    expect(payoutOverviewIds($page))->toBe([$chessPaid->id]);

    // The search ignores case and reads % and _ as text.
    $page->call('showState', PayoutOverview::FILTER_ALL)->set('q', 'blitz night');
    expect(payoutOverviewIds($page))->toBe([$chessReady->id, $chessPaid->id]);
    $page->set('q', '100%_c');
    expect(payoutOverviewIds($page))->toBe([$rocketReady->id]);
    $page->set('q', '%');
    expect(payoutOverviewIds($page))->toBe([$rocketReady->id]);

    $page->set('q', '')->call('showGame', 'chess');
    expect(payoutOverviewIds($page))->toBe([$chessReady->id, $chessPaid->id]);

    // No hits: an empty state with the way out, not an empty table.
    $page->set('q', 'no such tournament')->assertSeeHtml('data-test="payouts-no-hits"')->assertDontSeeHtml('data-test="payouts-all-paid"');
});

test('the filters live in the URL and an unknown state falls back to the default', function () {
    $ready = potTournamentIn(PayoutOverview::READY, ['name' => 'Ready Cup']);
    $paid = potTournamentIn(PayoutOverview::PAID, ['name' => 'Paid Cup']);
    $admin = anAdmin();

    $this->actingAs($admin)->get(route('admin.payouts', ['state' => 'paid']))->assertOk()->assertSee('Paid Cup')->assertDontSee('Ready Cup');
    $this->actingAs($admin)->get(route('admin.payouts', ['state' => 'all', 'q' => 'ready']))->assertOk()->assertSee('Ready Cup')->assertDontSee('Paid Cup');
    $this->actingAs($admin)->get(route('admin.payouts', ['state' => 'nonsense']))->assertOk()->assertSeeHtml('data-filter="waiting"');
});

test('the list is paginated, twelve tournaments a page', function () {
    foreach (range(1, PayoutOverview::PER_PAGE + 2) as $ignored) {
        potTournamentIn(PayoutOverview::PAID);
    }

    $page = Livewire::actingAs(anAdmin())->test('pages::admin.payouts');
    expect(payoutOverviewIds($page))->toHaveCount(PayoutOverview::PER_PAGE);

    $page->call('showPage', 2);
    expect(payoutOverviewIds($page))->toHaveCount(2);

    // A filter starts at the first page again.
    $page->call('showState', PayoutOverview::PAID)->assertSet('page', 1);
});

test('a link to a tournament opens its panel with the way back, and back marks it in the overview', function () {
    $ready = potTournamentIn(PayoutOverview::READY, ['name' => 'Ready Cup']);
    $failed = potTournamentIn(PayoutOverview::FAILED, ['name' => 'Failed Cup']);
    $admin = anAdmin();

    $this->actingAs($admin)->get(route('admin.payouts', ['tournament' => $ready->id]))->assertOk()
        ->assertSeeHtml('data-test="payouts-tournament-panel"')
        ->assertSeeHtml('data-test="payouts-back"')
        ->assertSeeHtml('data-test="payouts-detail-state" data-state="ready"')
        ->assertDontSeeHtml('data-test="payouts-overview"');

    $page = Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $ready->id])
        // The next one that waits is one click away.
        ->assertSeeHtml('data-test="payouts-next"')->assertSee('Failed Cup')
        ->call('close')
        ->assertSet('tournamentId', null)->assertSet('seen', $ready->id)
        ->assertSeeHtml('data-test="payouts-overview"')->assertSee('opened last');

    $page->call('open', $failed->id)->assertSet('tournamentId', $failed->id)->assertSeeHtml('data-test="payouts-detail-state" data-state="failed"');
});

test('a link to a tournament without a pot lands on the overview', function () {
    potTournamentIn(PayoutOverview::READY);
    $without = Tournament::factory()->create();

    Livewire::actingAs(anAdmin())->test('pages::admin.payouts', ['tournamentId' => $without->id])
        ->assertSet('tournamentId', null)->assertSeeHtml('data-test="payouts-overview"');
});

test('the urgency order binds one rank per state', function () {
    // PayoutOverview::ordered() writes eight `when ?` ranks by hand; a ninth state needs a ninth rank.
    expect(PayoutOverview::STATES)->toHaveCount(8);
});

test('the sidebar badge counts the waiting tournaments, like the readout', function () {
    potTournamentIn(PayoutOverview::UNCHECKED);
    potTournamentIn(PayoutOverview::READY);
    potTournamentIn(PayoutOverview::PAID);

    $this->actingAs(anAdmin());
    $badge = collect(AdminNavigation::forCurrentUser()->groups())->flatMap(fn (array $group) => $group['items'])->firstWhere('key', 'payouts')['count'];

    expect($badge)->toBe(2);
});

test('the readout counts the tournaments the waiting filter lists, unchecked ones included', function () {
    potTournamentIn(PayoutOverview::UNCHECKED);
    potTournamentIn(PayoutOverview::OPEN);
    potTournamentIn(PayoutOverview::PAYING);
    potTournamentIn(PayoutOverview::PAID);

    $page = Livewire::actingAs(anAdmin())->test('pages::admin.payouts')
        ->assertSeeHtml('data-test="readout-waiting" data-count="2"')
        ->assertSeeHtml('data-test="payouts-states-waiting"');

    expect(payoutOverviewIds($page))->toHaveCount(2)
        ->and((new PayoutOverview)->counts()[PayoutOverview::FILTER_WAITING])->toBe(PayoutOverview::waitingTotal())->toBe(2);
});

test('the overview and the panel survive a Livewire round trip', function () {
    $ready = potTournamentIn(PayoutOverview::READY);
    $admin = anAdmin();

    Livewire::actingAs($admin)->test('pages::admin.payouts')->call('$refresh')->assertOk()->set('q', 'x')->assertOk();
    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $ready->id])->call('$refresh')->assertOk();
});

test('only admins see the overview', function () {
    potTournamentIn(PayoutOverview::READY);

    Livewire::actingAs(User::factory()->create())->test('pages::admin.payouts')->assertForbidden();
});
