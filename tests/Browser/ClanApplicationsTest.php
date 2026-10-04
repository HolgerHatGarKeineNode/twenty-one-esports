<?php

use App\Enums\ClanRole;
use App\Enums\JoinRequestOrigin;
use App\Enums\JoinRequestStatus;
use App\Models\Clan;
use App\Models\ClanJoinRequest;
use App\Models\ClanMember;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use swentel\nostr\Encryption\Nip44;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;
use WebSocket\Client;
use WebSocket\Message\Text;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Clan applications (plan "Clan-Bewerbungen", P3/P4)
|--------------------------------------------------------------------------
|
| Against a local `nak serve` relay (never a public one), with a throwaway
| key behind the stubbed window.nostr:
|
| - the form on the clan page at 375 and 1440 px in English and at 375 px in
|   German: 44 px targets, no horizontal overflow, the time zone prefilled
|   from the browser;
| - applying: stored with the form, a NIP-17 gift wrap (kind 1059) from the
|   applicant to the captain's DM inbox (10050) that the captain opens with
|   their key; nothing as NIP-04 to the owner (no 10050) until the yes;
| - the manage page lists it with the form data; the captain approves.
|
| Every page: the console clean and no response of 400 or more, with a
| positive control. CA_SHOTS=<dir> writes screenshots there.
|
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    $this->port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $this->relay = Process::start(['nak', 'serve', '--hostname', '127.0.0.1', '--port', (string) $this->port]);
    WaitForPort::open('127.0.0.1', $this->port);
    $this->relayUrl = 'ws://127.0.0.1:'.$this->port;
    config(['esports.profile_relays' => [$this->relayUrl], 'esports.relays' => [$this->relayUrl], 'esports.chat.relays' => []]);
});

afterEach(function () {
    $this->relay->stop(1);
});

function caPage(User $user, string $to, int $width, string $locale = 'en'): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from('/locale/'.$locale));
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && window.Livewire !== undefined', 15_000);

    return $page;
}

function caShot(Page $page, string $name): void
{
    $dir = getenv('CA_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

function caSend(string $url, array $event): void
{
    $client = new Client($url);
    $client->setTimeout(5);
    $client->text(json_encode(['EVENT', $event]));
    $answer = $client->receive();
    $client->close();

    expect($answer instanceof Text ? json_decode($answer->getContent(), true) : null)->toMatchArray([0 => 'OK', 1 => $event['id'], 2 => true]);
}

/**
 * @return list<array<string, mixed>>
 */
function caQuery(string $url, string $args): array
{
    $out = Process::run('nak req '.$args.' '.escapeshellarg($url).' </dev/null')->output();

    return array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), explode("\n", trim($out)))));
}

/** A section's visible targets below 44 px, and the page's overflow. */
function caMeasure(Page $page, string $selector): array
{
    return $page->evaluate(str_replace('__SEL__', $selector, <<<'JS'
        () => {
            const section = document.querySelector('__SEL__');
            const targets = [...section.querySelectorAll('a, button, label, select, textarea')].filter((el) => el.checkVisibility())
                .map((el) => { const r = el.getBoundingClientRect(); return [el.dataset.test ?? el.textContent.trim().slice(0, 24), Math.round(r.width), Math.round(r.height), Math.round(r.right)]; });
            return {
                visible: section.checkVisibility(),
                overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
                small: targets.filter(([, w, h]) => w < 44 || h < 44),
                outside: targets.filter(([, , , right]) => right > innerWidth),
                count: targets.length,
            };
        }
        JS));
}

function caClean(Page $page, string $label): void
{
    expect([$label, $page->evaluate('() => window.__errors')])->toBe([$label, []])
        ->and([$label, $page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([$label, []]);
}

/**
 * Laser Eyes: the owner without a DM inbox, a captain with one on the relay.
 *
 * @return array{clan: Clan, owner: User, captain: User, captainSigner: TestSigner}
 */
function caClan(string $relay): array
{
    $ownerSigner = new TestSigner;
    $owner = User::factory()->withPubkey($ownerSigner->pubkey)->create(['name' => 'Founder Fritz']);
    $service = app(ClanService::class);
    $draft = new ClanDraft('Laser Eyes', 'LSR');
    $clan = $service->create($owner, $draft, $ownerSigner->signTemplates($service->prepareCreate($owner, $draft)));

    $captainSigner = new TestSigner;
    $captain = User::factory()->withPubkey($captainSigner->pubkey)->create(['name' => 'Captain Clara']);
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $captain->id, 'role' => ClanRole::Captain, 'joined_at' => now()]);

    $at = now()->getTimestamp() - 600;
    caSend($relay, $captainSigner->sign(10050, [['relay', $relay]], '', $at));
    caSend($relay, $ownerSigner->sign(10002, [['r', $relay]], '', $at));

    return ['clan' => $clan->refresh(), 'owner' => $owner, 'captain' => $captain, 'captainSigner' => $captainSigner];
}

test('the application form on the clan page: 44 px, no overflow, a clean console, en 375 and 1440, de 375', function () {
    ['clan' => $clan] = caClan($this->relayUrl);
    $player = User::factory()->create(['name' => 'satsjaeger']);
    TestSigner::forBrowser($player);
    $player->refresh();

    foreach ([['en', 375], ['en', 1440], ['de', 375]] as [$locale, $width]) {
        $label = "{$locale} {$width}";
        $page = caPage($player, route('clans.show', $clan, absolute: false), $width, $locale);
        $page->locator('[data-test=clan-apply-open]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=clan-apply-timezone]")?.value !== "" && document.querySelector("[data-test=clan-apply-timezone]")?.value !== undefined', 10_000);

        $zone = $page->evaluate('() => [document.querySelector("[data-test=clan-apply-timezone]").value, Intl.DateTimeFormat().resolvedOptions().timeZone]');
        $section = caMeasure($page, '[data-test=clan-apply]');
        fwrite(STDERR, "\n[ca] form {$label} ".json_encode($section).' zone '.json_encode($zone));

        expect([$label, $zone[0]])->toBe([$label, $zone[1]])
            ->and([$label, $section['visible'], $section['overflow'], $section['small'], $section['outside']])->toBe([$label, true, false, [], []]);
        caShot($page, "ca-form-{$locale}-{$width}");
        caClean($page, $label);
    }

    // Positive control: the collectors see a thrown error and a 404 on this page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("control-throw"); }); fetch("/control-missing-page"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("control-throw")) && window.__errors.some((e) => e.startsWith("404"))', 5_000);
});

test('apply from the clan page: a NIP-17 gift wrap to the captain\'s DM inbox, nothing as NIP-04 before the yes; the manage list shows it and the captain approves', function () {
    ['clan' => $clan, 'owner' => $owner, 'captain' => $captain, 'captainSigner' => $captainSigner] = caClan($this->relayUrl);
    $player = User::factory()->create(['name' => 'satsjaeger']);
    TestSigner::forBrowser($player);
    $player->refresh();

    // From the clan list: "Apply" opens the form on the clan page.
    $page = caPage($player, route('clans.index', absolute: false), 1440);
    $page->locator('[data-test=clan-card-apply]')->first()->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=clan-apply-form]") !== null && document.querySelector("[data-test=clan-apply-timezone]").value !== ""', 15_000);

    $page->locator('[data-test=clan-apply-game]:has-text("Rocket League")')->click();
    $page->locator('[data-test=clan-apply-platform]:has-text("PC")')->click();
    $page->locator('[data-test=clan-apply-message]')->fill('Diamond in 2v2, evenings.');
    expect(caQuery($this->relayUrl, '-k 1059 -k 4'))->toBe([]);

    $page->locator('[data-test=clan-apply-send]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=clan-apply]")?.dataset.status === "pending" && ! document.querySelector("[data-test=clan-apply-dm-busy]").checkVisibility() && document.querySelector("[data-test=clan-apply-dm-note]").checkVisibility()', 30_000);
    $note = $page->evaluate('() => document.querySelector("[data-test=clan-apply-dm-note]").textContent.trim()');
    fwrite(STDERR, "\n[ca] note ".$note);
    caShot($page, 'ca-applied-1440');

    $request = ClanJoinRequest::query()->sole();
    expect($request->origin)->toBe(JoinRequestOrigin::Application)
        ->and($request->games)->toBe(['rocket-league'])
        ->and($request->platforms)->toBe(['pc'])
        ->and($request->message)->toBe('Diamond in 2v2, evenings.')
        ->and($note)->toBe('Your application reached 1 of 2 captains as an encrypted Nostr message.');

    // The captain: one gift wrap on the relay, sealed by the applicant, with the application.
    $wraps = caQuery($this->relayUrl, '-k 1059 -t p='.$captain->pubkey);
    expect($wraps)->toHaveCount(1);
    $seal = json_decode(Nip44::decrypt($wraps[0]['content'], Nip44::getConversationKey($captainSigner->secret, $wraps[0]['pubkey'])), true);
    $rumor = json_decode(Nip44::decrypt($seal['content'], Nip44::getConversationKey($captainSigner->secret, $seal['pubkey'])), true);
    expect($seal['pubkey'])->toBe($player->pubkey)
        ->and($rumor['kind'])->toBe(14)
        ->and($rumor['content'])->toContain('Application to Laser Eyes [LSR] from satsjaeger')->toContain('Diamond in 2v2, evenings.')->toContain(route('clans.manage', $clan).'#join-requests');

    // The owner has no DM inbox: nothing as NIP-04 until the applicant says yes.
    expect(caQuery($this->relayUrl, '-k 4'))->toBe([])
        ->and($page->evaluate('() => document.querySelector("[data-test=clan-apply-dm-confirm]").checkVisibility()'))->toBeTrue();
    caClean($page, 'apply');

    // The manage page: the application with its form data; the captain approves.
    $manage = caPage($captain, route('clans.manage', $clan, absolute: false), 1440);
    BrowserWait::until($manage, '() => document.querySelector("[data-test=application]") !== null', 10_000);
    $row = $manage->evaluate('() => document.querySelector("[data-test=application]").innerText');
    expect($row)->toContain('satsjaeger')->toContain('Rocket League')->toContain('PC')->toContain('Diamond in 2v2, evenings.');
    caShot($manage, 'ca-manage-1440');

    $manage->locator('[data-test=application] [data-test=approve-request]')->click();
    BrowserWait::until($manage, '() => document.querySelector("[data-test=join-request-approved]") !== null', 10_000);

    expect($request->refresh()->status)->toBe(JoinRequestStatus::Approved)
        ->and($request->decided_by_id)->toBe($captain->id);
    caClean($manage, 'manage');
});
