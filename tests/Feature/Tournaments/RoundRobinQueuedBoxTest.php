<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentTv;
use App\Support\Tournaments\TournamentView;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| A live round robin: only the matches being played are "Up now"
|--------------------------------------------------------------------------
|
| DerCaddy, 2026-11-04, on a blitz Blockli round robin of three players: rounds 1, 3 and 6 all said "Jetzt dran",
| as if the six games ran at once. The league starts them one after another per player
| (TournamentMatchMaker::waitsForEarlierRound()); the bracket now says so for every match held back. Daily play
| starts every game at once and keeps its "Up now".
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
});

function queuedRoundRobin(string $mode, TournamentResultsMode $results = TournamentResultsMode::Players): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => $mode, 'format' => TournamentFormat::RoundRobin,
        'options' => FormatOptions::fromArray(['legs' => 1], GameProfile::for('blockli', $mode))->toArray(),
        'capacity' => 3, 'results_mode' => $results, 'status' => TournamentStatus::Running,
        'starts_at' => now()->subMinute(), 'slug' => 'queued-'.fake()->unique()->numberBetween(1, 1_000_000),
    ]);

    foreach (range(1, 3) as $index) {
        $player = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** @return list<array<string, mixed>> every match box of the round-robin part, round by round */
function queuedBoxes(Tournament $tournament): array
{
    $part = (new TournamentView($tournament->refresh()))->stages()[0]['parts'][0];

    return array_values(array_filter(array_merge(...array_values($part['rounds'])), fn (array $box): bool => $box['bracket'] !== 'bye'));
}

test('blitz: the match being played is up now, the later rounds of its players wait for it', function () {
    $tournament = queuedRoundRobin('blitz');
    $boxes = collect(queuedBoxes($tournament));
    $started = $boxes->filter(fn (array $box): bool => $box['href'] !== null);

    expect($boxes->count())->toBe(3)
        ->and($started)->toHaveCount(1)
        ->and($started->first()['queued'])->toBeFalse()
        ->and($boxes->reject(fn (array $box): bool => $box['href'] !== null)->every(fn (array $box): bool => $box['queued']))->toBeTrue();

    $html = $this->get(route('tournaments.show', $tournament))->assertOk()->getContent();

    expect(substr_count($html, 'data-test="queued"'))->toBe(2)
        ->and(preg_match_all('/data-test="match-box"[^>]*\bdata-live\b/', $html))->toBe(1);

    $tv = collect((new TournamentTv($tournament))->stages()[0]['parts'][0]['rounds'] ?? [])->flatten(1)->filter(fn ($box): bool => is_array($box) && ($box['bracket'] ?? null) !== 'bye');

    expect($tv->where('live', true))->toHaveCount(1);
});

test('daily play starts every game at once: nothing waits, every match is up now', function () {
    $tournament = queuedRoundRobin('correspondence');
    $boxes = collect(queuedBoxes($tournament));

    expect($boxes->count())->toBe(3)
        ->and($boxes->every(fn (array $box): bool => $box['href'] !== null && ! $box['queued']))->toBeTrue();
});

test('a director opens the rounds himself: no match of a director tournament is held back', function () {
    $tournament = queuedRoundRobin('blitz', TournamentResultsMode::Director);
    $boxes = collect(queuedBoxes($tournament));

    expect($boxes->count())->toBe(3)
        ->and($boxes->contains(fn (array $box): bool => $box['queued']))->toBeFalse();
});
