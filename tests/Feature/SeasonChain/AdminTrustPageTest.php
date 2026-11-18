<?php

/*
 * Admin trust page (P7d, NIP "Reports"): admins see the league reports,
 * which wait until an admin confirms or dismisses them, and exclude keys,
 * each with a reason; the next trust run applies it
 * (tests/Feature/SeasonChain/TrustJobTest.php).
 */

use App\Enums\ClanRole;
use App\Livewire\Actions\DeleteAccount;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\ClanDeparture;
use App\Models\ClanMember;
use App\Models\NostrEvent;
use App\Models\TrustCountedReport;
use App\Models\TrustDecision;
use App\Models\TrustExclusion;
use App\Models\TrustReportConfirmation;
use App\Models\TrustReportDismissal;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanService;
use App\Support\Navigation\AdminNavigation;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\TrustAdmin;
use App\Support\SeasonChain\TrustAdminRefused;
use App\Support\SeasonChain\TrustJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

test('an admin dismisses a report and excludes its author, each only with a reason, and can confirm the report after all and lift the exclusion', function () {
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

    $page->set('reason', 'Checked again.')->call('confirm', $this->report->event_id)
        ->set('reason', 'Checked again.')->call('lift', $this->reporter->pubkey)->call('$refresh')->assertOk();

    expect(TrustReportDismissal::query()->count())->toBe(0)
        ->and(TrustReportConfirmation::query()->sole()->event_id)->toBe($this->report->event_id)
        ->and(TrustExclusion::query()->count())->toBe(0);
});

test('round 3: every decision and every undo goes into an append-only log, shown on the page', function () {
    config(['esports.board' => [NostrKeys::hexToNpub($this->admin->pubkey)]]);
    $trust = app(TrustAdmin::class);

    $trust->dismiss($this->admin, $this->report->event_id, 'Same household.');
    $trust->confirm($this->admin, $this->report->event_id, 'The household story was wrong.');
    $trust->dismiss($this->admin, $this->report->event_id, 'Two people after all.');
    $trust->exclude($this->admin, $this->reporter->pubkey, 'Mass reporting.');
    $trust->lift($this->admin, $this->reporter->pubkey, 'Appeal granted.');

    expect(TrustDecision::query()->orderBy('id')->get()->map(fn (TrustDecision $d) => [$d->actor_pubkey, $d->action, $d->target, $d->reason])->all())->toBe([
        [$this->admin->pubkey, 'dismiss', $this->report->event_id, 'Same household.'],
        [$this->admin->pubkey, 'confirm', $this->report->event_id, 'The household story was wrong.'],
        [$this->admin->pubkey, 'withdraw', $this->report->event_id, 'Two people after all.'],
        [$this->admin->pubkey, 'exclude', $this->reporter->pubkey, 'Mass reporting.'],
        [$this->admin->pubkey, 'lift', $this->reporter->pubkey, 'Appeal granted.'],
    ])
        ->and(fn () => TrustDecision::query()->first()->update(['reason' => 'rewritten']))->toThrow(LogicException::class)
        ->and(fn () => TrustDecision::query()->first()->delete())->toThrow(LogicException::class);

    $this->actingAs($this->admin)->get(route('admin.trust'))->assertOk()
        ->assertSeeInOrder(['Appeal granted.', 'Mass reporting.', 'withdrew confirmation of report', 'Two people after all.', 'confirmed report', 'The household story was wrong.', 'Same household.']);
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
        ->and(fn () => $trust->confirm($this->admin, $own->event_id, 'Mine.'))->toThrow(TrustAdminRefused::class, 'your own clan')
        ->and(fn () => $trust->confirm($this->admin, $this->report->event_id, 'Him.'))->toThrow(TrustAdminRefused::class, 'your own clan')
        ->and(TrustReportDismissal::query()->count())->toBe(0)
        ->and(TrustReportConfirmation::query()->count())->toBe(0);
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

test('regression (P7d gate, Low A): a clan that ends keeps its departures, so the last member cannot shed the own-clan guard', function () {
    Queue::fake();
    openSeason(); // Block 0 an hour ago
    config(['esports.board' => [NostrKeys::hexToNpub($this->admin->pubkey)]]);
    $clans = app(ClanService::class);
    $trust = app(TrustAdmin::class);

    // The board member founds a clan, a mate joins.
    $draft = new ClanDraft('Laser Eyes', 'LSR', 'Rocket League clan of the Kempten meetup.');
    $clan = $clans->create($this->admin, $draft, $this->adminSigner->signTemplates($clans->prepareCreate($this->admin, $draft)));
    $mateSigner = new TestSigner;
    $mate = User::factory()->withPubkey($mateSigner->pubkey)->create();
    $invite = $clans->invite($this->admin, $clan, $mate, $this->adminSigner->signTemplates($clans->prepareInvite($this->admin, $clan, $mate)));
    $clans->accept($invite, $mate, $mateSigner->signTemplates($clans->prepareAccept($invite, $mate)));

    // The mate leaves, then the owner leaves last: the clan ends.
    $clans->leave($mate, $mateSigner->signTemplates($clans->prepareLeave($mate)));
    $clans->leave($this->admin, $this->adminSigner->signTemplates($clans->prepareLeave($this->admin)));

    expect(Clan::query()->whereKey($clan->id)->exists())->toBeFalse()
        ->and(ClanDeparture::query()->where('clan_id', $clan->id)->orderBy('id')->get()->map(fn (ClanDeparture $row) => [$row->user_id, $row->reason, $row->clan_address, $row->clan_name])->all())
        ->toBe([
            [$mate->id, 'left', $clan->address(), 'Laser Eyes'],
            [$this->admin->id, 'left', $clan->address(), 'Laser Eyes'],
        ]);

    expect(fn () => $trust->exclude($this->admin, $mate->pubkey, 'Mass reporting.'))->toThrow(TrustAdminRefused::class, 'your own clan')
        ->and(TrustExclusion::query()->count())->toBe(0);
});

test('regression (P7e): a former mate who deletes their account stays under the own-clan guard', function () {
    Queue::fake();
    openSeason(); // Block 0 an hour ago
    config(['esports.board' => [NostrKeys::hexToNpub($this->admin->pubkey)]]);
    $clans = app(ClanService::class);
    $trust = app(TrustAdmin::class);

    $draft = new ClanDraft('Laser Eyes', 'LSR', 'Rocket League clan of the Kempten meetup.');
    $clan = $clans->create($this->admin, $draft, $this->adminSigner->signTemplates($clans->prepareCreate($this->admin, $draft)));
    $mateSigner = new TestSigner;
    $mate = User::factory()->withPubkey($mateSigner->pubkey)->create();
    $invite = $clans->invite($this->admin, $clan, $mate, $this->adminSigner->signTemplates($clans->prepareInvite($this->admin, $clan, $mate)));
    $clans->accept($invite, $mate, $mateSigner->signTemplates($clans->prepareAccept($invite, $mate)));

    // The mate leaves, then deletes the account: the departure stays, with the pubkey.
    $clans->leave($mate, $mateSigner->signTemplates($clans->prepareLeave($mate)));
    app(DeleteAccount::class)($mate);

    expect(User::query()->whereKey($mate->id)->exists())->toBeFalse()
        ->and(ClanDeparture::query()->where('clan_id', $clan->id)->get()->map(fn (ClanDeparture $row) => [$row->user_id, $row->pubkey])->all())
        ->toBe([[null, $mateSigner->pubkey]]);

    expect(fn () => $trust->exclude($this->admin, $mateSigner->pubkey, 'Mass reporting.'))->toThrow(TrustAdminRefused::class, 'your own clan')
        ->and(TrustExclusion::query()->count())->toBe(0);

    // The clan's "Former" tab still renders the row, as a deleted player.
    Livewire::actingAs($this->admin)->test('pages::clans.manage', ['clan' => $clan])->set('tab', 'former')->assertOk()->assertSee('Deleted player');
});

test('regression (P7e): a mate who deletes their account while still in the clan stays under the own-clan guard', function () {
    Queue::fake();
    openSeason();
    config(['esports.board' => [NostrKeys::hexToNpub($this->admin->pubkey)]]);
    $clans = app(ClanService::class);

    $draft = new ClanDraft('Laser Eyes', 'LSR', 'Rocket League clan of the Kempten meetup.');
    $clan = $clans->create($this->admin, $draft, $this->adminSigner->signTemplates($clans->prepareCreate($this->admin, $draft)));
    $mateSigner = new TestSigner;
    $mate = User::factory()->withPubkey($mateSigner->pubkey)->create();
    $invite = $clans->invite($this->admin, $clan, $mate, $this->adminSigner->signTemplates($clans->prepareInvite($this->admin, $clan, $mate)));
    $clans->accept($invite, $mate, $mateSigner->signTemplates($clans->prepareAccept($invite, $mate)));

    app(DeleteAccount::class)($mate);

    expect(fn () => app(TrustAdmin::class)->exclude($this->admin, $mateSigner->pubkey, 'Mass reporting.'))->toThrow(TrustAdminRefused::class, 'your own clan')
        ->and(ClanDeparture::query()->where('clan_id', $clan->id)->sole()->only(['user_id', 'pubkey', 'reason']))->toBe(['user_id' => null, 'pubkey' => $mateSigner->pubkey, 'reason' => 'deleted']);
});

test('the page marks each report as waiting, confirmed or counting, and a withdrawn confirmation takes the counting mark away', function () {
    $season = openSeason();
    $other = NostrEvent::fromSigned(SignedEvent::fromInput($this->reporter->sign(TrustJob::REPORT, [
        ['p', User::factory()->create()->pubkey, 'other'], ['L', TrustJob::LABEL_NAMESPACE], ['l', 'cheating', TrustJob::LABEL_NAMESPACE],
    ], 'Second report.', now()->getTimestamp())));
    // The first report is confirmed and counted in the last trust run; the second waits.
    TrustReportConfirmation::query()->create(['event_id' => $this->report->event_id, 'confirmed_by_id' => $this->admin->id, 'reason' => 'Checked the games.']);
    TrustCountedReport::query()->create(['season_id' => $season->id, 'event_id' => $this->report->event_id, 'author' => $this->reporter->pubkey, 'target' => $this->target->pubkey, 'subtree' => $this->reporter->pubkey]);

    Livewire::actingAs($this->admin)->test('pages::admin.trust')
        ->assertSeeHtml('data-test="trust-counts"')
        ->assertSeeInOrder(['waiting', 'Second report.', 'suspectus', 'confirmed, counts', 'Two accounts, one person.', 'Confirmed: Checked the games.'])
        // Confirmed now, applied by the next run: not counted yet.
        ->set('reason', 'Checked the replay.')
        ->call('confirm', $other->event_id)
        ->assertSeeHtml('data-test="trust-confirmed"')
        ->assertDontSeeHtml('data-test="trust-waiting"')
        ->set('reason', 'Same household.')
        ->call('dismiss', $this->report->event_id)
        ->assertDontSeeHtml('data-test="trust-counts"')
        ->assertSee('Dismissed: Same household.');
});

test('a report that counted before reports needed a confirmation shows as waiting and does not carry the counting mark', function () {
    $season = openSeason();
    $this->actingAs($this->admin);
    TrustCountedReport::query()->create(['season_id' => $season->id, 'event_id' => $this->report->event_id, 'author' => $this->reporter->pubkey, 'target' => $this->target->pubkey, 'subtree' => $this->reporter->pubkey]);

    Livewire::actingAs($this->admin)->test('pages::admin.trust')
        ->assertSeeHtml('data-test="trust-waiting"')
        ->assertDontSeeHtml('data-test="trust-counts"');

    expect(trustNavCount())->toBe(1);
});

test('confirming a report takes the explicit button and a reason, the button asks first, and the confirmation can be withdrawn', function () {
    openSeason();
    $page = Livewire::actingAs($this->admin)->test('pages::admin.trust')
        ->assertSeeHtml('data-test="trust-confirm"')
        // R18: it lowers a player's rank, so the button asks before the request is sent.
        ->assertSeeHtml('wire:confirm="Confirm this report? At the next trust run it halves the trust of suspectus;')
        ->assertDontSeeHtml('data-test="trust-withdraw"')
        // Typing the reason saves nothing on its own.
        ->set('reason', 'Checked the games.');

    expect(TrustReportConfirmation::query()->count())->toBe(0)
        ->and(TrustDecision::query()->count())->toBe(0);

    $page->set('reason', '')->call('confirm', $this->report->event_id)->assertHasErrors('reason');

    expect(TrustReportConfirmation::query()->count())->toBe(0);

    $page->set('reason', 'Checked the games.')->call('confirm', $this->report->event_id)->assertHasNoErrors()
        ->assertSet('reason', '')
        ->assertSee('Confirmed: Checked the games.')
        ->assertSeeHtml('data-test="trust-withdraw"')
        ->assertDontSeeHtml('data-test="trust-confirm"');

    expect(TrustReportConfirmation::query()->sole()->only(['event_id', 'confirmed_by_id', 'reason']))
        ->toBe(['event_id' => $this->report->event_id, 'confirmed_by_id' => $this->admin->id, 'reason' => 'Checked the games.'])
        ->and(TrustReportConfirmation::query()->sole()->created_at)->not->toBeNull();

    $page->set('reason', 'Wrong player.')->call('dismiss', $this->report->event_id)->assertHasNoErrors()
        ->assertSee('Dismissed: Wrong player.')
        ->assertSeeHtml('data-test="trust-confirm"');

    expect(TrustReportConfirmation::query()->count())->toBe(0)
        ->and(TrustReportDismissal::query()->sole()->only(['event_id', 'dismissed_by_id', 'reason']))
        ->toBe(['event_id' => $this->report->event_id, 'dismissed_by_id' => $this->admin->id, 'reason' => 'Wrong player.']);
});

test('only admins confirm, withdraw and dismiss a report', function () {
    $player = User::factory()->create();
    $trust = app(TrustAdmin::class);

    expect(fn () => $trust->confirm($player, $this->report->event_id, 'I say so.'))->toThrow(TrustAdminRefused::class, 'Only admins')
        ->and(fn () => $trust->dismiss($player, $this->report->event_id, 'I say so.'))->toThrow(TrustAdminRefused::class, 'Only admins');

    // Withdrawing is a dismissal of a confirmed report: the same gate.
    $trust->confirm($this->admin, $this->report->event_id, 'Checked the games.');

    expect(fn () => $trust->dismiss($player, $this->report->event_id, 'I say so.'))->toThrow(TrustAdminRefused::class, 'Only admins')
        ->and(TrustReportConfirmation::query()->count())->toBe(1)
        ->and(TrustReportDismissal::query()->count())->toBe(0)
        ->and(TrustDecision::query()->pluck('action')->all())->toBe(['confirm']);

    Livewire::actingAs($player)->test('pages::admin.trust')->assertForbidden();
});

test('an admin who lost the role while the page was open cannot confirm or dismiss from it', function (string $action) {
    $page = Livewire::actingAs($this->admin)->test('pages::admin.trust')->set('reason', 'Checked the games.');
    Admin::query()->where('pubkey', $this->admin->pubkey)->delete();

    $page->call($action, $this->report->event_id)->assertForbidden();

    expect(TrustReportConfirmation::query()->count())->toBe(0)
        ->and(TrustReportDismissal::query()->count())->toBe(0);
})->with(['confirm', 'dismiss']);

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

test('the exclusion field is the player picker: a picked key is excluded, nothing picked asks for a pick (P16)', function () {
    config(['esports.board' => [NostrKeys::hexToNpub($this->admin->pubkey)]]);
    $this->actingAs($this->admin)->get(route('admin.trust'))->assertOk()
        ->assertSee('data-picker-id="trust-key"', false)
        ->assertDontSee('data-test="trust-key"', false);

    Livewire::actingAs($this->admin)->test('pages::admin.trust')
        ->set('reason', 'Mass reporting.')->call('exclude')
        ->assertHasErrors(['key' => 'required'])
        ->set('key', $this->reporter->pubkey)->call('exclude')->assertHasNoErrors()
        ->assertSet('key', null);

    expect(TrustExclusion::query()->sole()->pubkey)->toBe($this->reporter->pubkey);
});

/** The count the admin navigation shows beside "Trust", for the acting admin. */
function trustNavCount(): int
{
    return collect(AdminNavigation::forCurrentUser()->groups())->flatMap(fn (array $group) => $group['items'])->firstWhere('key', 'trust')['count'];
}

/** A league report by a new reporter that no admin decided on, signed at $at (now). */
function undecidedReport(string $content, ?int $at = null): NostrEvent
{
    return NostrEvent::fromSigned(SignedEvent::fromInput((new TestSigner)->sign(TrustJob::REPORT, [
        ['p', User::factory()->create()->pubkey, 'other'], ['L', TrustJob::LABEL_NAMESPACE], ['l', 'cheating', TrustJob::LABEL_NAMESPACE],
    ], $content, $at ?? now()->getTimestamp())));
}

test('the admin navigation shows beside Trust how many reports of the live season wait for an admin, as the trust page marks them', function () {
    $this->actingAs($this->admin);

    // Before Block 0 nothing is rated and nothing waits.
    expect(trustNavCount())->toBe(0);

    $season = openSeason(); // Block 0 an hour ago
    Seasons::forget();

    // The report of every test here was read and nobody decided.
    expect(trustNavCount())->toBe(1);

    undecidedReport('Waits too.');
    undecidedReport('Signed in the second of Block 0.', $season->genesis_at->getTimestamp());
    undecidedReport('Signed a second before Block 0: the trust job does not read it.', $season->genesis_at->getTimestamp() - 1);
    TrustReportConfirmation::query()->create(['event_id' => undecidedReport('Confirmed.')->event_id, 'confirmed_by_id' => $this->admin->id, 'reason' => 'Checked the games.']);
    TrustReportDismissal::query()->create(['event_id' => undecidedReport('Dismissed.')->event_id, 'dismissed_by_id' => $this->admin->id, 'reason' => 'Same household.']);
    TrustExclusion::query()->create(['pubkey' => undecidedReport('Its author was excluded.')->pubkey, 'excluded_by_id' => $this->admin->id, 'reason' => 'Mass reporting.']);

    $marked = fn (): int => substr_count(Livewire::actingAs($this->admin)->test('pages::admin.trust')->html(), 'data-test="trust-waiting"');

    expect($marked())->toBe(3)
        ->and(trustNavCount())->toBe(3);

    // The season ended: nothing waits between seasons.
    $season->update(['ends_at' => now()->subMinute()]);
    Seasons::forget();

    expect($marked())->toBe(0)
        ->and(trustNavCount())->toBe(0);
});

test('the count beside Trust costs the admin navigation one query, however many reports wait', function () {
    openSeason();
    $this->actingAs($this->admin);

    $queries = function (): array {
        Seasons::forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        AdminNavigation::forCurrentUser()->groups();
        DB::disableQueryLog();

        return array_column(DB::getQueryLog(), 'query');
    };

    $none = $queries();
    array_map(fn (int $i): NostrEvent => undecidedReport('Report '.$i), range(1, 5));
    $five = $queries();

    expect(count($five))->toBe(count($none))
        ->and(trustNavCount())->toBe(6)
        ->and(array_filter($five, fn (string $sql): bool => str_contains($sql, 'trust_report_confirmations')))->toHaveCount(1)
        // The live season is part of that one query, not a second one.
        ->and(array_filter($five, fn (string $sql): bool => str_starts_with($sql, 'select * from "seasons"')))->toBe([]);
});
