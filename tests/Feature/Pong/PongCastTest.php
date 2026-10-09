<?php

/*
|--------------------------------------------------------------------------
| Proof of Pong's cast (plan "Proof of Pong", P3)
|--------------------------------------------------------------------------
|
| One source for both sides (resources/js/pong/cast.json): 25 figures to pick and five bots, each with its portrait,
| victory pose and paddle skin, every clip a file of the soundboard. The game against a bot takes a bot and a figure
| by id; a live match takes a player's pick only from the cast and only before the first point, shows both picks to
| both pages and keeps them (sides swapped) into the rematch.
|
*/

use App\Enums\PongMatchStatus;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\Pong\PongCast;
use App\Support\Pong\PongMatches;
use Tests\Support\PongOn;

beforeEach(function () {
    $this->withoutVite();
    PongOn::play();
});

test('the cast has 25 figures and five bots, each with portrait, pose, skin and clips that exist', function () {
    $data = PongCast::data();
    $all = [...$data['players'], ...$data['bots']];
    $clips = collect($all)->flatMap(fn (array $f): array => [...$f['goal'], ...$f['win']])
        ->merge(collect($data['commentator'])->flatten())->unique();

    expect($data['players'])->toHaveCount(25)
        ->and(array_column($data['bots'], 'level'))->toBe([1, 2, 3, 4, 4])
        ->and(array_column($data['bots'], 'id'))->toBe(['nocoiner', 'shitcoiner', 'schiff', 'schnabel', 'lagarde'])
        ->and(collect($all)->pluck('id')->duplicates()->all())->toBe([])
        ->and(collect($all)->reject(fn (array $f): bool => is_file(public_path("pong/art/por-{$f['id']}.webp")) && is_file(public_path("pong/art/win-{$f['id']}.webp")))->pluck('id')->all())->toBe([])
        ->and(collect($all)->reject(fn (array $f): bool => count($f['skin']) === 3 && preg_match('/^#[0-9a-f]{6}$/', $f['skin'][0]) === 1 && preg_match('/^#[0-9a-f]{6}$/', $f['skin'][1]) === 1)->pluck('id')->all())->toBe([])
        ->and($clips->reject(fn (string $clip): bool => is_file(public_path("hyper/s/{$clip}.mp3")))->values()->all())->toBe([])
        ->and(collect(['halving', 'brrr', 'pizza', 'difficulty'])->reject(fn (string $e): bool => is_file(public_path("pong/art/ev-{$e}.webp")))->all())->toBe([])
        ->and(collect($data['arenas'])->reject(fn (string $a): bool => is_file(public_path("pong/art/arena-{$a}.webp")))->all())->toBe([]);
});

test('the bot game takes its bot and the player\'s figure by id, and refuses anything else', function () {
    $user = User::factory()->create();

    $html = (string) $this->actingAs($user)->get(route('pong.bot', ['bot' => 'schnabel', 'figure' => 'oma', 'seed' => 1]))->assertOk()->getContent();
    preg_match('#<script type="application/json" id="pong-config">(.*?)</script>#s', $html, $found);
    $config = json_decode($found[1], true, flags: JSON_THROW_ON_ERROR);

    expect($config)->toMatchArray(['level' => 4, 'bot' => 'Madame CBDC Schnabel', 'botFigure' => 'schnabel', 'figure' => 'oma', 'figurePicked' => 'oma', 'arena' => 'ezb'])
        ->and($config['castTexts'])->toHaveKey('Hodl Grandma')
        ->and($config['urls']['again'])->toBe(route('pong.bot', ['bot' => 'schnabel', 'figure' => 'oma'], false));

    // A level still names its own bot; level 4 is the end boss.
    $this->get(route('pong.bot', ['level' => 4]))->assertOk()->assertSee('data-test="pong-face-opponent" src="/pong/art/por-lagarde.webp"', false);
    $this->get(route('pong.bot', ['bot' => 'holger']))->assertRedirect();
    $this->get(route('pong.bot', ['figure' => 'lagarde']))->assertRedirect();
});

test('a live match takes each player\'s pick from the cast before the first point and keeps both into the rematch', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $matches = app(PongMatches::class);
    $match = $matches->create($anna, $bert);

    $this->actingAs($anna)->postJson(route('pong.figure', $match), ['figure' => 'saylor'])->assertOk()->assertJsonPath('figures', ['saylor', null]);
    $this->actingAs($bert)->postJson(route('pong.figure', $match), ['figure' => 'hosp'])->assertOk()->assertJsonPath('figures', ['saylor', 'hosp']);
    // Not one of the players' cast (a bot), nobody's id, or not a player of the match: refused.
    $this->actingAs($anna)->postJson(route('pong.figure', $match), ['figure' => 'lagarde'])->assertUnprocessable();
    $this->actingAs(User::factory()->create())->postJson(route('pong.figure', $match), ['figure' => 'oma'])->assertForbidden();
    expect($matches->snapshot($match->fresh())['figures'])->toBe(['saylor', 'hosp']);

    // After the first point the picks stand.
    $match->refresh()->forceFill(['status' => PongMatchStatus::Active, 'score_left' => 1])->save();
    $this->actingAs($anna)->postJson(route('pong.figure', $match), ['figure' => 'oma'])->assertOk()->assertJsonPath('figures', ['saylor', 'hosp']);

    // The rematch swaps the sides and each player keeps their figure.
    $match->refresh()->forceFill(['status' => PongMatchStatus::Finished, 'winner_id' => $anna->id])->save();
    $matches->rematch($match, $anna);
    $matches->rematch($match->fresh(), $bert);
    $next = PongMatch::query()->where('rematch_of_id', $match->id)->sole();

    expect($next->left_id)->toBe($bert->id)
        ->and(PongMatches::figuresOf($next))->toBe(['hosp', 'saylor']);
});

test('the live page carries the cast\'s texts, the arena and the figure endpoint', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = app(PongMatches::class)->create($anna, $bert);

    $html = (string) $this->actingAs($anna)->get(route('pong.match', $match))->assertOk()
        ->assertSee('data-test="pong-picker"', false)->getContent();
    preg_match('#<script type="application/json" id="pong-config">(.*?)</script>#s', $html, $found);
    $config = json_decode($found[1], true, flags: JSON_THROW_ON_ERROR);

    expect($config['urls']['figure'])->toBe(route('pong.figure', $match, false))
        ->and($config['arena'])->toBeIn(PongCast::data()['arenas'])
        ->and($config['snapshot']['figures'])->toBe([null, null])
        ->and($config['castTexts'])->toHaveKey('Madame Brrr Lagarde');
});
