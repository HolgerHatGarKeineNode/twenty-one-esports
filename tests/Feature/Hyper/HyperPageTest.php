<?php

use App\Models\HyperMatch;
use App\Models\User;
use App\Support\GameChat\GameChannels;
use App\Support\Hyper\HyperTexts;
use App\Support\Nostr\PlayerProfile;
use Illuminate\Support\Facades\Process;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;

/*
| The Hyperbitcoinization pages (plan "Hyperbitcoinization", P2b): the full-screen match page without the
| league's shell, read-only for spectators; the start page in the shell with the quick start into a new tab and
| the running matches; the table chat's names; every text of the game page in German too; and the page
| script's pure parts under Node (tests/js/hyperClient.test.mjs).
*/

beforeEach(function () {
    $this->withoutVite();
    HyperOn::play();
});

test('the match page is full-screen without the league shell, carries the snapshot, and is read-only for a spectator', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey]);
    $match = HyperOn::versus($anna, $bert, bots: 1);

    $page = $this->actingAs($anna)->get(route('hyper.match', $match))->assertOk();
    $page->assertSee('id="hyper-snapshot"', false)
        ->assertSee('data-test="hyper-emote-open"', false)
        ->assertSee('data-test="hyper-leave"', false)
        // No league header, navigation or footer: the page is its own document.
        ->assertDontSee('data-test="shell-match-dock"', false)
        ->assertDontSee('<footer', false);
    $html = (string) $page->getContent();
    preg_match('#<script type="application/json" id="hyper-snapshot">(.*?)</script>#s', $html, $snapshot);
    preg_match('#<script type="application/json" id="hyper-config">(.*?)</script>#s', $html, $config);
    $snapshot = json_decode($snapshot[1], true, flags: JSON_THROW_ON_ERROR);
    $config = json_decode($config[1], true, flags: JSON_THROW_ON_ERROR);

    expect($snapshot['me'])->toBe(0)
        ->and($snapshot['legal'])->toBeArray()
        ->and($config['urls']['act'])->toBe(route('hyper.act', $match, false))
        ->and($config['chat']['channel'])->toBe(GameChannels::matchChannelId($match->ulid))
        ->and($config['chat']['me'])->toBe($anna->pubkey)
        ->and($config['clips'])->toContain('maurice_all-time-high')
        ->and($config['texts'])->toHaveCount(count(HyperTexts::KEYS));

    // A logged-in spectator and a guest: no seat, no cards, no emotes, no leaving.
    foreach ([$carl, null] as $viewer) {
        $view = ($viewer ? $this->actingAs($viewer) : $this)->get(route('hyper.match', $match))->assertOk();
        $view->assertSee('class="spectator"', false)
            ->assertDontSee('data-test="hyper-emote-open"', false)
            ->assertDontSee('data-test="hyper-leave"', false);
        preg_match('#id="hyper-snapshot">(.*?)</script>#s', (string) $view->getContent(), $seen);
        $seen = json_decode($seen[1], true, flags: JSON_THROW_ON_ERROR);
        expect($seen['me'])->toBeNull()
            ->and($seen['legal'])->toBeNull()
            ->and(array_column($seen['state']['seats'], 'hand'))->each->toBeNull();
    }
});

test('the start page offers the bots game into a new tab and lists the running matches; a guest is asked to log in', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $running = HyperOn::versus($anna, $bert);
    $over = HyperOn::versus($bert, $anna);
    $over->forceFill(['status' => 'finished'])->save();

    $this->get(route('hyper.index'))->assertOk()
        ->assertSee('data-test="hyper-login"', false)
        ->assertDontSee('data-test="hyper-quick"', false);

    $page = $this->actingAs($anna)->get(route('hyper.index'))->assertOk();
    $page->assertSee('data-test="hyper-quick"', false)
        ->assertSee('action="'.route('hyper.quick').'" target="_blank"', false)
        // Defaults: 3 bots, no round limit.
        ->assertSee('name="bots" value="3" class="sr-only" checked', false)
        ->assertSee('name="limit" value="0" class="sr-only" checked', false)
        ->assertSee('href="'.route('hyper.match', $running).'" target="_blank"', false)
        ->assertDontSee(route('hyper.match', $over), false);
});

test('the start page form sends the player on to the new match; a JSON request still gets its id', function () {
    $anna = User::factory()->create();

    $response = $this->actingAs($anna)->post(route('hyper.quick'), ['bots' => 2, 'faction' => '', 'limit' => 12]);

    $created = HyperMatch::query()->latest('id')->firstOrFail();
    $response->assertRedirect(route('hyper.match', $created));
    expect($created->seats)->toHaveCount(3)
        ->and($created->round_limit)->toBe(12)
        ->and($created->seats->first()->user_id)->toBe($anna->id);

    $this->actingAs($anna)->postJson(route('hyper.quick'), ['bots' => 1])->assertCreated()->assertJsonStructure(['id', 'url']);
    $this->actingAs($anna)->post(route('hyper.quick'), ['bots' => 9])->assertSessionHasErrors('bots');
});

test('the table chat names league accounts by their league name and nobody else', function () {
    $anna = User::factory()->create();
    $stranger = str_repeat('e', 64);

    $this->getJson(route('hyper.people', ['keys' => $anna->pubkey.','.$stranger.',nonsense']))
        ->assertOk()
        ->assertExactJson([$anna->pubkey => ['name' => $anna->displayName(), 'avatar' => $anna->avatarUrl() ?? PlayerProfile::generatedAvatarUrl($anna->pubkey)]]);
});

test('every text of the game page is a key the scripts use and has a German translation', function () {
    $keys = [];
    foreach (glob(resource_path('js/hyper/*.js')) ?: [] as $file) {
        preg_match_all("/(?<![\\w.$])(?:t|T|tr)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $found);
        array_push($keys, ...array_map(fn (string $key): string => str_replace("\\'", "'", $key), $found[1]));
    }
    $blade = [];
    foreach ([resource_path('views/hyper/match.blade.php'), resource_path('views/pages/hyper/index.blade.php')] as $file) {
        preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $found);
        array_push($blade, ...$found[1]);
    }
    $german = json_decode((string) file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(array_values(array_unique($keys)))->toEqualCanonicalizing(HyperTexts::KEYS)
        ->and(array_values(array_diff([...HyperTexts::KEYS, ...$blade], array_keys($german))))->toBe([]);

    app()->setLocale('de');
    expect(HyperTexts::dictionary())->toMatchArray(['Money Printer' => 'Notenpresse', 'Your turn' => 'Du bist dran', ':name is on the move …' => ':name ist am Zug …']);
});

test('the page script keeps every ply once, mirrors the server’s events and counts reactions (Node)', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/hyperClient.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 5')->toContain('ℹ skipped 0');
});
