<?php

/*
|--------------------------------------------------------------------------
| Hyperbitcoinization's sound as configuration (plan "Hyperbitcoinization", P6)
|--------------------------------------------------------------------------
|
| The page's sound map and the viewer's switches under Node (tests/js/hyperSounds.test.mjs), and the
| league-wide switch of soundboard emotes (HyperEmotes::CLIPS_SETTING on /admin/settings): off, no table
| offers a clip and the server sends none; stickers stay.
|
*/

use App\Events\HyperEmoteSent;
use App\Models\Admin;
use App\Models\User;
use App\Support\Hyper\HyperEmotes;
use App\Support\Nostr\NostrKeys;
use App\Support\Settings\LeagueSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Tests\Support\HyperOn;

beforeEach(function () {
    $this->withoutVite();
    HyperOn::play();
});

test('every sound mapping, the bot-turn rule, the switches and the stored settings hold (Node)', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/hyperSounds.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 7')->toContain('ℹ fail 0')->toContain('ℹ skipped 0');
});

test('who a moment waits for: a multiplayer table never waits for a click, a battle without a human runs without animation (Node)', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/hyperPace.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 4')->toContain('ℹ fail 0');
});

test('soundboard emotes muted league-wide: no table offers a clip, the server sends none, stickers stay', function () {
    Event::fake([HyperEmoteSent::class]);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::versus($anna, $bert);
    $config = function () use ($anna, $match): array {
        $html = (string) $this->actingAs($anna)->get(route('hyper.match', $match))->assertOk()->getContent();
        preg_match('#<script type="application/json" id="hyper-config">(.*?)</script>#s', $html, $found);

        return [json_decode($found[1], true, flags: JSON_THROW_ON_ERROR), $html];
    };

    // On by default, and listed on the settings page while the game is on.
    [$on, $onHtml] = $config();
    expect(HyperEmotes::clipsOn())->toBeTrue()
        ->and($on['clips'])->toContain('cerca-wette')
        ->and($onHtml)->toContain('id="emote-filter"')
        ->and(LeagueSettings::definitions())->toHaveKey(HyperEmotes::CLIPS_SETTING);

    LeagueSettings::save($admin, [HyperEmotes::CLIPS_SETTING => 'off']);
    LeagueSettings::forget();

    [$off, $offHtml] = $config();
    expect(HyperEmotes::clipsOn())->toBeFalse()
        ->and($off['clips'])->toBe([])
        ->and($offHtml)->not->toContain('id="emote-filter"');

    $this->actingAs($anna)->postJson(route('hyper.emote', $match), ['emote' => 'cerca-wette'])
        ->assertUnprocessable()->assertJsonPath('reason', 'clips_muted')->assertJsonPath('message', 'Soundboard emotes are switched off.');
    $this->actingAs($anna)->postJson(route('hyper.emote', $match), ['emote' => 'gg'])->assertOk()->assertJsonPath('kind', 'sticker');
    Event::assertDispatchedTimes(HyperEmoteSent::class, 1);

    // Back on: the clip goes out again.
    LeagueSettings::save($admin, [HyperEmotes::CLIPS_SETTING => 'on']);
    LeagueSettings::forget();
    $this->actingAs($bert)->postJson(route('hyper.emote', $match), ['emote' => 'cerca-wette'])->assertOk()->assertJsonPath('kind', 'clip');
});

test('the switch is listed only while Hyperbitcoinization is on', function () {
    config(['esports.hyper.enabled' => false]);

    expect(LeagueSettings::definitions())->not->toHaveKey(HyperEmotes::CLIPS_SETTING)
        // Not listed means not set: the emotes (of a game that is off anyway) count as on.
        ->and(HyperEmotes::clipsOn())->toBeTrue();
});
