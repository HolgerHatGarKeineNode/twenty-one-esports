<?php

/*
 * Admin trust page (P7d, NIP "Reports"): admins see the league reports and
 * dismiss them or exclude keys, each with a reason; the next trust run
 * applies it (tests/Feature/SeasonChain/TrustJobTest.php).
 */

use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\TrustExclusion;
use App\Models\TrustReportDismissal;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\TrustJob;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    $this->admin = User::factory()->create();
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
    $page = Livewire::actingAs($this->admin)->test('pages::admin.trust')
        ->call('dismiss', $this->report->event_id)
        ->assertHasErrors('reason');

    expect(TrustReportDismissal::query()->count())->toBe(0);

    $page->set('reason', 'Same household, not the same person.')->call('dismiss', $this->report->event_id)->assertHasNoErrors()
        ->assertSee('Dismissed: Same household, not the same person.');
    $page->set('reason', 'Mass reporting.')->call('excludeAuthor', $this->report->event_id)->assertHasNoErrors();

    expect(TrustReportDismissal::query()->sole()->event_id)->toBe($this->report->event_id)
        ->and(TrustExclusion::query()->sole()->pubkey)->toBe($this->reporter->pubkey);

    $page->call('restore', $this->report->event_id)->call('lift', $this->reporter->pubkey)->call('$refresh')->assertOk();

    expect(TrustReportDismissal::query()->count())->toBe(0)
        ->and(TrustExclusion::query()->count())->toBe(0);
});
