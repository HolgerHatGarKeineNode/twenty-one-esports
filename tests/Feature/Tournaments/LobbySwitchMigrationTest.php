<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\BotPost;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\SeasonChain\LeagueKey;
use App\Support\StreamBot\TournamentNotes;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The live data switch to lobbies (plan "AoE2 und Trackmania", P10)
|--------------------------------------------------------------------------
|
| The data migration that runs on deploy: every Age of Empires II
| tournament not drawn yet, the open casual cups included, becomes one
| lobby match with its calendar event republished and its stream-bot note
| due for a correction; drawn ones, other games and team modes keep their
| format. Running it twice changes nothing.
|
*/

beforeEach(function () {
    Queue::fake();
    config([
        'esports.league.nsec' => (new TestSigner)->secret,
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => (new TestSigner)->secret,
    ]);
});

function runLobbySwitchMigration(): void
{
    (require database_path('migrations/2026_10_01_160100_switch_undrawn_aoe2_tournaments_to_lobbies.php'))->up();
}

/** What the switch may touch, per tournament: a run that changes nothing leaves this as it was. */
function lobbySwitchSnapshot(): array
{
    return Tournament::query()->orderBy('id')->get()->mapWithKeys(fn (Tournament $tournament): array => [$tournament->id => [
        $tournament->format->value, $tournament->options, $tournament->capacity, $tournament->status->value, $tournament->event_id, $tournament->starts_at->getTimestamp(),
    ]])->all();
}

test('the migration switches only the undrawn Age of Empires II tournaments, republishes their calendar events, and is idempotent', function () {
    $aoe = GameProfile::for('age-of-empires-2', '1v1');
    $closesAt = CarbonImmutable::now()->addDays(3);

    // An AoE2 cup as the cups opened before P10: double elimination, 4 places grown to 8, published, three signed up.
    $cup = Tournament::factory()->create([
        'name' => 'AoE2 Casual Cup EU #1', 'game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::DoubleElimination,
        'options' => ['bestOf' => 1, 'finalBestOf' => 3, 'grandFinal' => 'single'], 'capacity' => 8, 'starts_at' => $closesAt, 'created_by_id' => null,
        'cup_series' => 'age-of-empires-2-eu', 'cup_number' => 1, 'cup_open_series' => 'age-of-empires-2-eu',
    ]);
    $cup = app(TournamentPublisher::class)->openSignup($cup, $closesAt);
    foreach (range(1, 3) as $ignored) {
        [$player, $signer] = keyedPlayer();
        soloSignup($cup->refresh(), $player, $signer);
    }
    $oldEvent = $cup->refresh()->event_id;
    // The stream bot announced it already, with the old "1v1" game line.
    $note = app(TournamentNotes::class)->event(LeagueKey::streamBot(), $cup, now()->getTimestamp());
    BotPost::query()->create(['subject_type' => BotPost::SUBJECT_TOURNAMENT, 'subject_id' => $cup->id, 'kind' => 1, 'event_id' => $note->id, 'event' => json_encode($note->toArray()), 'published_at' => now()]);

    // An organizer's draft in single elimination, an AoE2 2v2 tournament in sign-up, an AoE2 tournament already drawn, a chess cup.
    $draft = Tournament::factory()->create(['game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'options' => FormatOptions::defaults($aoe)->toArray(), 'capacity' => 16]);
    $teams = openTournament(['game' => 'age-of-empires-2', 'mode' => '2v2', 'format' => TournamentFormat::SingleElimination, 'options' => FormatOptions::defaults(GameProfile::for('age-of-empires-2', '2v2'))->toArray(), 'capacity' => 8]);
    $drawn = Tournament::factory()->create(['game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'options' => FormatOptions::defaults($aoe)->toArray(),
        'capacity' => 4, 'status' => TournamentStatus::Running, 'slug' => 'aoe-drawn-'.fake()->unique()->numberBetween(1, 1_000_000)]);
    foreach (range(1, 4) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $drawn->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($drawn, str_repeat('ab', 32));
    config(['esports.casual_cups.enabled' => ['chess']]);
    $chess = app(CasualCups::class)->ensure('chess', 'eu');

    expect(app(TournamentNotes::class)->stale())->toBe([]);
    $untouched = array_intersect_key(lobbySwitchSnapshot(), array_flip([$teams->id, $drawn->id, $chess->id]));

    runLobbySwitchMigration();

    $cup->refresh();
    $calendar = $cup->event()->firstOrFail()->payload();

    expect($cup->format)->toBe(TournamentFormat::FreeForAll)
        ->and($cup->capacity)->toBe(40)
        ->and($cup->formatOptions()->lobbyMinutes)->toBe(135)
        ->and($cup->formatOptions()->heatSize)->toBe(8)
        ->and($cup->status)->toBe(TournamentStatus::Signup)
        ->and($cup->signups()->active()->count())->toBe(3)
        // A new version of its 31923 says the new format; the stream bot's note is due for its correction.
        ->and($cup->event_id)->not->toBe($oldEvent)
        ->and(collect($calendar['tags'])->firstWhere(0, 'summary')[1])->toBe('Age of Empires II: Definitive Edition, one lobby match, free for all.')
        ->and($calendar['content'])->toContain('A casual cup the league opens on its own, played as one lobby match')->not->toContain('double elimination')
        ->and(array_map(fn (Tournament $tournament): int => $tournament->id, app(TournamentNotes::class)->stale()))->toBe([$cup->id])
        ->and($draft->refresh()->format)->toBe(TournamentFormat::FreeForAll)
        ->and($draft->capacity)->toBe(16)
        ->and($draft->event_id)->toBeNull()
        ->and(array_intersect_key(lobbySwitchSnapshot(), $untouched))->toBe($untouched);

    // Twice is once: nothing changes, nothing is published again.
    $after = lobbySwitchSnapshot();
    $events = NostrEvent::query()->count();

    runLobbySwitchMigration();

    expect(lobbySwitchSnapshot())->toBe($after)
        ->and(NostrEvent::query()->count())->toBe($events);
});
