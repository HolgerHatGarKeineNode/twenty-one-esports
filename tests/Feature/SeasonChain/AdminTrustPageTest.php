<?php

/*
 * Admin trust page (P7d, NIP "Reports"): admins see the league reports and
 * dismiss them or exclude keys, each with a reason; the next trust run
 * applies it (tests/Feature/SeasonChain/TrustJobTest.php).
 */

use App\Enums\ClanRole;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\NostrEvent;
use App\Models\TrustDecision;
use App\Models\TrustExclusion;
use App\Models\TrustReportDismissal;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\TrustAdmin;
use App\Support\SeasonChain\TrustAdminRefused;
use App\Support\SeasonChain\TrustJob;
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
