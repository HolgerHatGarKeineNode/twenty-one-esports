<?php

use App\Support\Nostr\NostrKeys;
use App\Support\Pages\ProtocolPage;
use App\Support\Pages\RulesPage;
use Tests\Support\TestSigner;

/*
 * The rules (P28) and the open protocol (P29): real pages, every number
 * and kind read at render time, so they cannot drift from what the league
 * applies.
 */

test('the rules page has every section with an anchor, in English and German', function () {
    $html = $this->get(route('rules'))->assertOk()->assertDontSee('Coming soon')->getContent();

    foreach (['league', 'games', 'casual-1v1', 'chess', 'clan-series', 'tournaments', 'casual-cups', 'prizes', 'fair-play', 'chat'] as $id) {
        expect($html)->toContain('id="'.$id.'"')->toContain('href="#'.$id.'"');
    }

    $this->get(route('rules', ['lang' => 'de']))->assertOk()->assertSee('Casual und gewertet')->assertSee('Preistöpfe und Auszahlungen');
});

test('the rules state the numbers the league applies, read from the config at render time', function () {
    config([
        'esports.casual.ready_seconds' => 45,
        'esports.casual.lobby_minutes' => 7,
        'esports.casual.lock' => ['noshows' => 3, 'window_hours' => 12, 'minutes' => 45],
        'esports.casual_cups.sizes' => [4, 8, 16, 32],
        'esports.tournaments.drawn_replays' => 4,
        'esports.bitcoin.confirmations' => 3,
    ]);

    $this->get(route('rules'))->assertOk()
        ->assertSee('45 seconds')
        ->assertSee('7 minutes')
        ->assertSee('3 forfeited no-shows within 12 hours pause casual 1v1 for 45 minutes.')
        ->assertSee('4 → 8 → 16 → 32')
        ->assertSee('at most 4 times')
        ->assertSee('once it has 3 confirmations');
});

test('the games table comes from the game registry', function () {
    $table = collect(RulesPage::sections())->firstWhere('id', 'games')['table'];

    expect($table['rows'])->toContain(['Rocket League', '3v3', 'best of 3 / 5', 'clan lineups'])
        ->toContain(['EA Sports FC 27', '1v1', 'best of 1 / 3', 'players']);
});

test('durations read in the unit they are set in', function () {
    expect(RulesPage::minutes(5))->toBe('5 minutes')
        ->and(RulesPage::minutes(120))->toBe('2 hours')
        ->and(RulesPage::minutes(2880))->toBe('2 days')
        ->and(RulesPage::seconds(90))->toBe('90 seconds')
        ->and(RulesPage::seconds(60))->toBe('1 minute');
});

test('the protocol page lists the kinds of the NIP document itself', function () {
    $kinds = ProtocolPage::kinds();
    $nip = file_get_contents(base_path(ProtocolPage::NIP_PATH));

    expect(collect($kinds['own'])->pluck('kind')->all())->toContain('2150', '2152', '2154', '32152', '22150')
        ->and(collect($kinds['own'])->firstWhere('kind', '2154')['name'])->toBe('League Attestation')
        ->and(collect($kinds['reused'])->pluck('kind')->all())->toContain('31923', '30382')
        // Every kind row of the document's first table is on the page, none invented.
        ->and(count($kinds['own']))->toBe(substr_count(str($nip)->after("\n## Kinds\n")->before('The numbers form one family')->toString(), "\n| `"));

    $this->get(route('protocol'))->assertOk()
        ->assertSee('League Attestation')
        ->assertSee(ProtocolPage::nipUrl(), false)
        ->assertDontSee('Coming soon');
});

test('the protocol page shows the league key and commands with it, and says so when a key is not set up', function () {
    config(['esports.league.nsec' => null, 'esports.trust.nsec' => null, 'esports.relays' => []]);

    $this->get(route('protocol'))->assertOk()
        ->assertSee('Not set up on this server.')
        ->assertSee('No relay is set up on this server.')
        ->assertSee('nak req -k 2154 -a &lt;league-npub&gt; -l 5 wss://&lt;relay&gt;', false);

    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret, 'esports.relays' => ['wss://league.example']]);
    $npub = NostrKeys::hexToNpub($league->pubkey);

    $this->get(route('protocol'))->assertOk()
        ->assertSee($npub)
        ->assertSee('nak req -k 31923 -a '.$npub.' wss://league.example')
        ->assertSee('wss://league.example');
});
