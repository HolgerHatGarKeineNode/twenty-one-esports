<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Series\Ladders;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\LobbyResults;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;
use App\Support\TwentyOne\Stream\TournamentSlides;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestSigner;

/*
 * The stream's tournament slides of an Age of Empires II lobby tournament
 * (P10): upcoming, running and finished, every scene tells lobbies; none
 * names a 1v1, a bracket or a pairing ("vs"). Finished, the slides name the
 * shared place 1 across all lobbies.
 */

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/** A running AoE2 lobby tournament of `$n` players named "Player N". */
function slideLobby(int $n): Tournament
{
    $tournament = Tournament::factory()->create([
        'name' => 'Lobby Night', 'game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll,
        'options' => FormatOptions::fromArray([], GameProfile::for('age-of-empires-2', '1v1'))->toArray(),
        'capacity' => $n, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'starts_at' => now(),
        'slug' => 'slide-lobby-'.fake()->unique()->numberBetween(1, 1_000_000), 'created_by_id' => organizer()->id,
        'ladder_address' => Ladders::address('age-of-empires-2', '1v1'),
    ]);

    foreach (range(1, $n) as $index) {
        $user = User::factory()->create(['name' => "Player {$index}"]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The words a slide shows: the contents of its text elements, lower case, one line each. */
function slideWords(string $svg): string
{
    preg_match_all('/<text[^>]*>(.*?)<\/text>/s', $svg, $texts);

    return mb_strtolower(html_entity_decode(implode("\n", array_map(strip_tags(...), $texts[1])), ENT_QUOTES | ENT_HTML5));
}

/** @return list<string> what of `1v1`, `bracket` or `vs` the words hold */
function pairingWords(string $words): array
{
    return array_values(array_filter(['1v1', 'bracket', 'vs'], fn (string $word): bool => preg_match('/\b'.preg_quote($word, '/').'\b/u', $words) === 1));
}

test('every tournament slide of an AoE2 lobby tournament, upcoming, running and finished, tells lobbies and never a 1v1, a bracket or a pairing', function () {
    $open = openTournament(['name' => 'Lobby Next', 'game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll,
        'options' => FormatOptions::defaults(GameProfile::for('age-of-empires-2', '1v1'))->toArray(), 'capacity' => 16]);
    foreach (range(1, 5) as $index) {
        [$player, $signer] = keyedPlayer();
        $player->forceFill(['name' => "Signed {$index}"])->save();
        soloSignup($open->refresh(), $player, $signer);
    }
    $running = slideLobby(9);
    $finished = slideLobby(9);
    $finished->forceFill(['name' => 'Lobby Done'])->save();
    [$a, $b] = TournamentMatch::query()->where('tournament_id', $finished->id)->with('slots')->orderBy('position')->get()->all();
    $places = fn (TournamentMatch $match, array $places): array => $match->slots->mapWithKeys(fn ($slot, int $i): array => [$slot->tournament_participant_id => $places[$i]])->all();
    app(LobbyResults::class)->enter($a, $finished->creator, $places($a, [1, 1, 3, 4, 5]));
    app(LobbyResults::class)->enter($b, $finished->creator, $places($b, [1, 2, 3, 4]));
    $winners = collect([$a->slots[0], $a->slots[1], $b->slots[0]])->sortBy('tournament_participant_id')->values()->map(fn ($slot): string => (string) $slot->participant->name)->all();

    $now = (int) now()->getTimestampMs();
    $upcoming = app(TournamentSlides::class)->all($now);
    $source = app(SceneSource::class);
    $stats = app(StreamStats::class)->all();
    $renderer = SceneRenderer::fromConfig();
    $frame = collect($upcoming)->firstWhere('id', $open->id);

    expect($frame['preview']['kind'])->toBe('lobbies')
        ->and($frame['mode'])->toBe('');

    $svgs = [];
    foreach (array_diff(RotationPlanner::TOURNAMENT_SCENES, RotationPlanner::LIVE_TOURNAMENT_SCENES) as $scene) {
        $svgs['upcoming-'.$scene] = $renderer->svg([...$source->rotation($scene, null, [], 0, $now, $stats, $frame, $upcoming), 'viewers' => 12], RotationPlanner::VIEWS[$scene]);
    }
    foreach (['ta3', 'tb3', 'tc3'] as $scene) {
        $svgs['upcoming-'.$scene] = $renderer->svg([...$source->rotation($scene, null, [], 0, $now, $stats, $frame, $upcoming), 'viewers' => 12], RotationPlanner::VIEWS[$scene]);
    }
    foreach (['running' => $running, 'finished' => $finished] as $key => $tournament) {
        $live = app(TournamentLiveSlides::class)->data($tournament->refresh(), $now);
        foreach (RotationPlanner::LIVE_TOURNAMENT_SCENES as $scene) {
            $svgs[$key.'-'.$scene] = $renderer->svg([...$source->rotation($scene, null, [], 0, $now, $stats, $live, $upcoming), 'viewers' => 12], RotationPlanner::VIEWS[$scene]);
        }
    }

    $offenders = array_filter(array_map(fn (string $svg): array => pairingWords(slideWords($svg)), $svgs));

    expect($svgs)->toHaveCount(39)
        ->and($offenders)->toBe([])
        // The preview: the lobbies the draw would make now, with their players.
        ->and(slideWords($svgs['upcoming-ta2']))->toContain('lobbies if sign-up closed now', 'lobby 1', 'signed 1')
        ->and(slideWords($svgs['upcoming-tc2']))->toContain('lobby 1')
        // The hero line names the lobby match.
        ->and(slideWords($svgs['upcoming-ta1'].$svgs['upcoming-tb1'].$svgs['upcoming-tc1']))->toContain('one lobby match')
        // Running: every lobby with its players, how many are decided.
        ->and(slideWords($svgs['running-ta4']))->toContain('lobby 1, live', 'lobby 2, live', 'player 9', 'one lobby match, 0 of 2 lobbies decided')
        ->and(slideWords($svgs['running-tb4'].$svgs['running-tc4']))->toContain('lobby 2, live')
        // Finished: the lobbies by place, and place 1 shared across them.
        ->and(slideWords($svgs['finished-ta4']))->toContain('final places', 'shared 1st place', ...array_map(mb_strtolower(...), $winners))
        ->and(slideWords($svgs['finished-tb4']))->toContain('shared 1st place: '.mb_strtolower(implode(', ', $winners)))
        ->and(slideWords($svgs['finished-tc4']))->toContain('shared 1st place: '.mb_strtolower(implode(', ', $winners)));

    // The check sees what it looks for: a bracket tournament's slides do name them.
    expect(pairingWords("final bracket\nhal vs adam\nage of empires ii, 1v1"))->toBe(['1v1', 'bracket', 'vs'])
        // Three on place 1 fit the three podium cards: nothing hidden.
        ->and(slideWords($svgs['finished-ta6'].$svgs['finished-tb6'].$svgs['finished-tc6']))->not->toContain('more');
});

test('the join steps keep Age of Empires II among the casual 1v1 games: its queue is still there', function () {
    $now = (int) now()->getTimestampMs();
    $svg = SceneRenderer::fromConfig()->svg([...app(SceneSource::class)->rotation('a4', null, [], 0, $now, app(StreamStats::class)->all()), 'viewers' => null], RotationPlanner::VIEWS['a4']);

    expect(slideWords($svg))->toContain('rocket league, ea fc, aoe2')->toContain('as a casual 1v1.')
        ->not->toContain('lobbies of up to 8');
});

test('the champion slides show two podium cards and "+N more" when more share place 1 than three cards hold', function () {
    $tournament = slideLobby(9);
    [$a, $b] = TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots')->orderBy('position')->get()->all();
    $places = fn (TournamentMatch $match, array $places): array => $match->slots->mapWithKeys(fn ($slot, int $i): array => [$slot->tournament_participant_id => $places[$i]])->all();
    // Five allies on place 1 across the two lobbies.
    app(LobbyResults::class)->enter($a, $tournament->creator, $places($a, [1, 1, 1, 4, 5]));
    app(LobbyResults::class)->enter($b, $tournament->creator, $places($b, [1, 1, 3, 4]));

    $now = (int) now()->getTimestampMs();
    $frame = app(TournamentLiveSlides::class)->data($tournament->refresh(), $now);
    $source = app(SceneSource::class);
    $renderer = SceneRenderer::fromConfig();

    expect($frame['champion'])->toBeNull()
        ->and($frame['podium'])->toHaveCount(4)
        ->and($frame['podiumMore'])->toBe(1);

    foreach (['ta6', 'tb6', 'tc6'] as $scene) {
        $svg = $renderer->svg([...$source->rotation($scene, null, [], 0, $now, [], $frame, []), 'viewers' => null], RotationPlanner::VIEWS[$scene]);

        expect(slideWords($svg))->toContain('+3 more')
            ->and(substr_count($svg, 'data-unit="podium-face-'))->toBe(2, $scene);
    }
});
