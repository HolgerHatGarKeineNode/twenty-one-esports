<?php

/*
 * Admin trust page (P7d, NIP "Reports"): admins see the league reports and
 * dismiss them or exclude keys, each with a reason; the next trust run
 * applies it (tests/Feature/SeasonChain/TrustJobTest.php).
 */

use App\Enums\ClanRole;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\ClanDeparture;
use App\Models\ClanMember;
use App\Models\NostrEvent;
use App\Models\TrustCountedReport;
use App\Models\TrustDecision;
use App\Models\TrustExclusion;
use App\Models\TrustReportDismissal;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\TrustAdmin;
use App\Support\SeasonChain\TrustAdminRefused;
use App\Support\SeasonChain\TrustJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    $this->adminSigner = new TestSigner;
    $this->admin = User::factory()->withPubkey($this->adminSigner->pubkey)->create();
    Admin::query()->create(['pubkey' => $this->admin->pubkey]);

    $this->reporter = new TestSigner;
    User::factory()->withPubkey($this->reporter->pubkey)->create(['name' => 'reporterina']);
    $this->target = User::factory()->create(['name' => 'suspectus']);
    $this->report = NostrEvent::fromSigned(SignedEvent::fromInput($this->reporter->sign(TrustJob::REPORT, [
        ['p', $this->target->pubkey, 'other'], ['L', TrustJob::LABEL_NAMESPACE], ['l', 'multi-account', TrustJob::LABEL_NAMESPACE],
    ], 'Two accounts, one person.', now()->getTimestamp())));
});

test('the trust page is for admins only and lists the league reports', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.trust'))->assertForbidden();

    $this->actingAs($this->admin)->get(route('admin.trust'))
        ->assertOk()
        ->assertSee('reporterina → suspectus')
        ->assertSee('multi-account')
        ->assertSee('Two accounts, one person.');
});

test('an admin dismisses a report and excludes its author, each only with a reason, and can undo both', function () {
    config(['esports.board' => [NostrKeys::hexToNpub($this->admin->pubkey)]]);
    $page = Livewire::actingAs($this->admin)->test('pages::admin.trust')
        ->call('dismiss', $this->report->event_id)
        ->assertHasErrors('reason');

    expect(TrustReportDismissal::query()->count())->toBe(0);

    $page->set('reason', 'Same household, not the same person.')->call('dismiss', $this->report->event_id)->assertHasNoErrors()
        ->assertSee('Dismissed: Same household, not the same person.');
    $page->set('reason', 'Mass reporting.')->call('excludeAuthor', $this->report->event_id)->assertHasNoErrors();

    expect(TrustReportDismissal::query()->sole()->event_id)->toBe($this->report->event_id)
        ->and(TrustExclusion::query()->sole()->pubkey)->toBe($this->reporter->pubkey);

    $page->set('reason', 'Checked again.')->call('restore', $this->report->event_id)
        ->set('reason', 'Checked again.')->call('lift', $this->reporter->pubkey)->call('$refresh')->assertOk();

    expect(TrustReportDismissal::query()->count())->toBe(0)
        ->and(TrustExclusion::query()->count())->toBe(0);
});

test('round 3: every decision and every undo goes into an append-only log, shown on the page', function () {
    config(['esports.board' => [NostrKeys::hexToNpub($this->admin->pubkey)]]);
    $trust = app(TrustAdmin::class);

    $trust->dismiss($this->admin, $this->report->event_id, 'Same household.');
    $trust->restore($this->admin, $this->report->event_id, 'The household story was wrong.');
    $trust->exclude($this->admin, $this->reporter->pubkey, 'Mass reporting.');
    $trust->lift($this->admin, $this->reporter->pubkey, 'Appeal granted.');

    expect(TrustDecision::query()->orderBy('id')->get()->map(fn (TrustDecision $d) => [$d->actor_pubkey, $d->action, $d->target, $d->reason])->all())->toBe([
        [$this->admin->pubkey, 'dismiss', $this->report->event_id, 'Same household.'],
        [$this->admin->pubkey, 'restore', $this->report->event_id, 'The household story was wrong.'],
        [$this->admin->pubkey, 'exclude', $this->reporter->pubkey, 'Mass reporting.'],
        [$this->admin->pubkey, 'lift', $this->reporter->pubkey, 'Appeal granted.'],
    ])
        ->and(fn () => TrustDecision::query()->first()->update(['reason' => 'rewritten']))->toThrow(LogicException::class)
        ->and(fn () => TrustDecision::query()->first()->delete())->toThrow(LogicException::class);

    $this->actingAs($this->admin)->get(route('admin.trust'))->assertOk()
        ->assertSeeInOrder(['Appeal granted.', 'Mass reporting.', 'The household story was wrong.', 'Same household.']);
});

test('round 3: an admin cannot decide on a report by or about himself or his clan', function () {
    $clan = Clan::factory()->create(['owner_id' => $this->admin->id]);
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $this->target->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
    $trust = app(TrustAdmin::class);

    // The target is in the admin's clan.
    expect(fn () => $trust->dismiss($this->admin, $this->report->event_id, 'Not him.'))->toThrow(TrustAdminRefused::class, 'your own clan');

    // A report by the admin himself.
    $own = NostrEvent::fromSigned(SignedEvent::fromInput($this->adminSigner->sign(TrustJob::REPORT, [
        ['p', User::factory()->create()->pubkey, 'other'], ['L', TrustJob::LABEL_NAMESPACE], ['l', 'abuse', TrustJob::LABEL_NAMESPACE],
    ], 'x', now()->getTimestamp())));

    expect(fn () => $trust->dismiss($this->admin, $own->event_id, 'Mine.'))->toThrow(TrustAdminRefused::class, 'your own clan')
        ->and(TrustReportDismissal::query()->count())->toBe(0);
});

test('round 3: excluding a key is for the board; dismissing a single report stays with every admin', function () {
    $trust = app(TrustAdmin::class);

    expect(fn () => $trust->exclude($this->admin, $this->reporter->pubkey, 'Mass reporting.'))->toThrow(TrustAdminRefused::class, 'board')
        ->and(TrustExclusion::query()->count())->toBe(0);

    $trust->dismiss($this->admin, $this->report->event_id, 'Same household.');

    $this->actingAs($this->admin)->get(route('admin.trust'))->assertOk()->assertDontSee('data-test="trust-exclude-author"', false);

    config(['esports.board' => [NostrKeys::hexToNpub($this->admin->pubkey)]]);
    $trust->exclude($this->admin, $this->reporter->pubkey, 'Mass reporting.');

    expect(TrustExclusion::query()->count())->toBe(1);
});

test('regression (security re-check round 4): leaving the clan does not lift the own-clan guard for the rest of the season', function () {
    openSeason(); // Block 0 an hour ago
    $trust = app(TrustAdmin::class);
    $join = fn (Clan $clan, User $user) => ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $user->id, 'role' => ClanRole::Member, 'joined_at' => now()->subDays(3)]);
    $leave = function (Clan $clan, User $user, $at): void {
        ClanMember::query()->where('clan_id', $clan->id)->where('user_id', $user->id)->delete();
        ClanDeparture::query()->create(['clan_id' => $clan->id, 'user_id' => $user->id, 'reason' => 'left', 'left_at' => $at]);
    };

    // The admin and the target share a clan; the admin leaves it during the season.
    $clan = Clan::factory()->create();
    $join($clan, $this->admin);
    $join($clan, $this->target);
    $leave($clan, $this->admin, now()->subMinutes(10));

    expect(fn () => $trust->dismiss($this->admin, $this->report->event_id, 'Not him.'))->toThrow(TrustAdminRefused::class, 'your own clan');

    // The target left the admin's clan during the season: refused as well.
    $mine = Clan::factory()->create();
    $join($mine, $this->admin);
    $other = User::factory()->create();
    $join($mine, $other);
    $leave($mine, $other, now()->subMinutes(5));
    $aboutOther = NostrEvent::fromSigned(SignedEvent::fromInput($this->reporter->sign(TrustJob::REPORT, [
        ['p', $other->pubkey, 'other'], ['L', TrustJob::LABEL_NAMESPACE], ['l', 'abuse', TrustJob::LABEL_NAMESPACE],
    ], 'x', now()->getTimestamp())));

    expect(fn () => $trust->dismiss($this->admin, $aboutOther->event_id, 'Not her.'))->toThrow(TrustAdminRefused::class, 'your own clan')
        ->and(TrustReportDismissal::query()->count())->toBe(0);

    // Left before the season began: that clan is not his any more.
    ClanDeparture::query()->where('clan_id', $clan->id)->update(['left_at' => now()->subHours(2)]);
    $trust->dismiss($this->admin, $this->report->event_id, 'Not him.');

    expect(TrustReportDismissal::query()->count())->toBe(1);
});

test('round 4: the page shows which reports count now, and a dismissal takes the mark away', function () {
    $season = openSeason();
    $other = NostrEvent::fromSigned(SignedEvent::fromInput($this->reporter->sign(TrustJob::REPORT, [
        ['p', User::factory()->create()->pubkey, 'other'], ['L', TrustJob::LABEL_NAMESPACE], ['l', 'cheating', TrustJob::LABEL_NAMESPACE],
    ], 'Second report.', now()->getTimestamp())));
    TrustCountedReport::query()->create(['season_id' => $season->id, 'event_id' => $this->report->event_id, 'author' => $this->reporter->pubkey, 'target' => $this->target->pubkey, 'subtree' => $this->reporter->pubkey]);

    Livewire::actingAs($this->admin)->test('pages::admin.trust')
        ->assertSeeHtml('data-test="trust-counts"')
        ->assertSeeInOrder(['does not count', 'Second report.', 'suspectus', 'counts', 'Two accounts, one person.'])
        ->set('reason', 'Same household.')
        ->call('dismiss', $this->report->event_id)
        ->assertDontSeeHtml('data-test="trust-counts"');
});

test('round 4: rolling back the counted reports refuses while the live season has any, so the backdating attack stays closed', function () {
    $migration = require database_path('migrations/2026_09_26_060028_create_trust_counted_reports_table.php');
    $season = openSeason();
    TrustCountedReport::query()->create(['season_id' => $season->id, 'event_id' => $this->report->event_id, 'author' => $this->reporter->pubkey, 'target' => $this->target->pubkey, 'subtree' => $this->reporter->pubkey]);

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, '1 counted report(s)')
        ->and(TrustCountedReport::query()->count())->toBe(1);

    // Once the season has ended its counted reports no longer hold anything back.
    $season->update(['ends_at' => now()->subMinute()]);
    $migration->down();
    expect(Schema::hasTable('trust_counted_reports'))->toBeFalse();
    $migration->up();
});

test('round 4: rolling back the decision log refuses while it holds decisions, so a later refused rollback cannot have dropped it', function () {
    $migration = require database_path('migrations/2026_09_26_053644_create_trust_decisions_table.php');
    app(TrustAdmin::class)->dismiss($this->admin, $this->report->event_id, 'Same household.');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, '1 trust decision(s)')
        ->and(Schema::hasTable('trust_decisions'))->toBeTrue()
        ->and(TrustDecision::query()->count())->toBe(1);

    DB::table('trust_decisions')->delete();
    $migration->down();
    expect(Schema::hasTable('trust_decisions'))->toBeFalse();
    $migration->up();
});
