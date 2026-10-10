<?php

use App\Enums\OverlayVariant;
use App\Enums\TournamentFormat;
use App\Models\Admin;
use App\Models\OverlayPreset;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The break scene and the 3D bracket (plan "OBS-Broadcast-Overlays", P5, P6)
|--------------------------------------------------------------------------
|
| A break preset's snapshot says which state the scene is in (the admin's pin, else the preset's tournament, else the
| clock), what its countdown runs to, the next matches, the top of the ladders and the pot only above zero. Both
| full-screen variants render on the engine with their words. The tournament page offers the 3D view beside the drawn
| bracket without loading three.js, and its data endpoint serves public data only and only for what the page shows.
|
*/

function breakTournament(int $n = 8): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, $n);
    $tournament->forceFill(['name' => 'Blitz Open', 'published_at' => now()->subHour()])->save();

    foreach ($tournament->participants()->orderBy('seed')->get() as $index => $participant) {
        $participant->forceFill(['name' => 'seed'.($index + 1)])->save();
    }

    return $tournament->refresh();
}

function breakSnapshot(array $attributes): array
{
    $token = str_pad((string) random_int(1, 999_999), 48, 'b');
    OverlayPreset::factory()->withToken($token)->create(['variant' => OverlayVariant::Break, 'locale' => 'en'] + $attributes);

    return test()->getJson('/broadcast/'.$token.'/snapshot.json')->assertOk()->json();
}

test('a break scene follows its tournament: starting soon before the start, a break with the next matches while it runs, thanks with the champion at the end', function () {
    $soon = Tournament::factory()->signup()->create(['published_at' => now(), 'signup_closes_at' => now()->addMinutes(20), 'starts_at' => now()->addMinutes(45), 'name' => 'Evening Cup', 'pot_source' => Tournament::POT_LEAGUE, 'prize_target_sats' => 21_000]);
    $running = breakTournament();
    $finished = breakTournament();
    playOutAsDirector($finished);

    $a = breakSnapshot(['tournament_id' => $soon->id])['break'];
    $b = breakSnapshot(['tournament_id' => $running->id])['break'];
    $c = breakSnapshot(['tournament_id' => $finished->id])['break'];

    expect($a['state'])->toBe('soon')
        ->and($a['target'])->toMatchArray(['name' => 'Evening Cup', 'status' => 'signup'])
        ->and($a['pot'])->toBe(21_000)
        ->and($a['matches'])->toBe([])
        ->and($b['state'])->toBe('break')
        // Round 1 of eight: four matches up now, seeds and public names only.
        ->and($b['matches'])->toHaveCount(4)
        ->and($b['matches'][0])->toMatchArray(['live' => true])
        ->and($b['matches'][0]['sides'][0])->toMatchArray(['name' => 'seed1', 'seed' => 1, 'known' => true])
        // No pot set up: none shown.
        ->and($b['pot'])->toBeNull()
        ->and($c['state'])->toBe('end')
        ->and($c['champion'])->toBe('seed1');
});

test('a pinned scene wins; without a tournament the scene counts down to the next cup and says soon only within two hours', function () {
    $running = breakTournament();
    Tournament::factory()->signup()->create(['published_at' => now(), 'signup_closes_at' => now()->addDays(2), 'starts_at' => now()->addDays(3), 'name' => 'Far Cup']);

    expect(breakSnapshot(['tournament_id' => $running->id, 'scene' => 'end'])['break']['state'])->toBe('end');

    $far = breakSnapshot([])['break'];
    expect($far['state'])->toBe('break')->and($far['target']['name'])->toBe('Far Cup');

    Tournament::factory()->signup()->create(['published_at' => now(), 'signup_closes_at' => now()->addMinutes(30), 'starts_at' => now()->addMinutes(90), 'name' => 'Near Cup']);
    $near = breakSnapshot([])['break'];
    expect($near['state'])->toBe('soon')->and($near['target']['name'])->toBe('Near Cup');
});

test('the break scene lists the top three of every ladder with results, by public name, and nothing private', function () {
    foreach ([['hodlqueen', 1900], ['markusturm', 1850], ['satsjaeger', 1800], ['zapzap', 1700]] as [$name, $elo]) {
        $user = User::factory()->create(['name' => $name]);
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => $elo, 'results' => 10]);
    }

    $data = breakSnapshot([]);
    $chess = collect($data['break']['leaders'])->firstWhere('game', 'chess');

    expect($chess['emblem'])->toBe('emblem-chess')
        ->and($chess['rows'])->toBe([
            ['place' => 1, 'name' => 'hodlqueen', 'rating' => '1900'],
            ['place' => 2, 'name' => 'markusturm', 'rating' => '1850'],
            ['place' => 3, 'name' => 'satsjaeger', 'rating' => '1800'],
        ])
        ->and(json_encode($data))->not->toContain('npub')->not->toContain('"pubkey"');
});

test('the break and bracket variants render full screen on the engine with their words', function () {
    $tournament = breakTournament();
    OverlayPreset::factory()->withToken($break = str_repeat('k', 48))->create(['variant' => OverlayVariant::Break, 'locale' => 'de']);
    OverlayPreset::factory()->withToken($bracket = str_repeat('l', 48))->create(['variant' => OverlayVariant::Bracket, 'locale' => 'de', 'tournament_id' => $tournament->id]);

    $configOf = fn (string $html): array => json_decode((string) str($html)->betweenFirst('id="broadcast-config">', '</script>'), true);
    $breakConfig = $configOf($this->get('/broadcast/'.$break)->assertOk()->assertSee('data-variant="break"', false)->getContent());
    $config = $configOf($this->get('/broadcast/'.$bracket)->assertOk()->assertSee('data-variant="bracket"', false)->getContent());

    expect($breakConfig['texts'])->toMatchArray(['stateSoon' => 'Gleich geht’s los', 'stateBreak' => 'Kurze Pause', 'stateEnd' => 'Danke fürs Zuschauen'])
        ->and($breakConfig['snapshot']['preset']['modules']['music'])->toBeTrue()
        ->and($config['texts']['scanFollow'])->toBe('Live verfolgen')
        ->and($config['tournamentId'])->toBe($tournament->id)
        // The bracket reads the stages with what feeds each match (the light paths) and the seeds.
        ->and($config['snapshot']['tournament']['stages'][0]['parts'][0]['sections'][0]['columns'][1]['matches'][0]['from'])->toBe(['m1-1', 'm1-2']);
});

test('an admin can give a break preset a tournament and pin its scene; the music module is there', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $tournament = breakTournament();

    Livewire::actingAs($admin)->test('pages::admin.overlays')
        ->set('name', 'Pause')->set('variant', 'break')
        ->assertSee(__('The next cup or tournament, automatically'))
        ->set('tournament', $tournament->id)->set('scene', 'soon')->set('modules.music', false)
        ->call('save')->assertHasNoErrors();

    $preset = OverlayPreset::query()->sole();
    expect($preset->tournament_id)->toBe($tournament->id)
        ->and($preset->scene)->toBe('soon')
        ->and($preset->hasModule('music'))->toBeFalse();

    // A league live preset keeps neither.
    Livewire::actingAs($admin)->test('pages::admin.overlays')->set('name', 'Live')->set('variant', 'league-live')->set('scene', 'end')->call('save');
    expect(OverlayPreset::query()->where('name', 'Live')->sole())->tournament_id->toBeNull()->scene->toBeNull();
});

test('the tournament page offers 3D beside the drawn bracket without loading three.js; the data serves what the page shows and nothing private', function () {
    $tournament = breakTournament();
    app(TournamentRunner::class)->enterResult(TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 'm1-1')->firstOrFail(), $tournament->creator, ['result' => '1-0']);

    $html = $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('data-test="bracket-view-toggle"', false)
        ->assertSee('data-test="bracket-3d"', false)
        ->assertSee('data-test="bracket-2d"', false)
        ->getContent();
    expect($html)->not->toContain('three.min.js"></script>');

    $data = $this->getJson(route('tournaments.bracket-data', $tournament))->assertOk()->json();
    $first = $data['stages'][0]['parts'][0]['sections'][0]['columns'][0]['matches'][0];
    expect($data['name'])->toBe('Blitz Open')
        ->and($first['sides'][0])->toMatchArray(['name' => 'seed1', 'won' => true])
        ->and($first['sides'][0]['entry'])->toMatchArray(['seed' => 1])
        ->and($first)->not->toHaveKey('ids')
        ->and(json_encode($data))->not->toContain('pubkey')->not->toContain('npub');

    // Before the draw there is no bracket to show: no switch, no data.
    $signup = Tournament::factory()->signup()->create(['published_at' => now(), 'signup_closes_at' => now()->addDay()]);
    $this->get(route('tournaments.show', $signup))->assertOk()->assertDontSee('data-test="bracket-view-toggle"', false);
    $this->getJson(route('tournaments.bracket-data', $signup))->assertNotFound();
    $tournament->forceFill(['published_at' => null])->save();
    $this->getJson(route('tournaments.bracket-data', $tournament))->assertNotFound();
});

test('a remembered 3D that cannot run (no WebGL, three.js not loading) is stored back as 2D, so the next visit does not retry it', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/bracketView.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 3')->toContain('ℹ fail 0');
});
