<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Series\Ladders;
use App\Support\StreamBot\PrideNotes;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\LobbyResults;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use App\Support\TwentyOne\Stream\GameSpotlight;
use App\Support\TwentyOne\Stream\PrideSlides;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;
use App\Support\TwentyOne\Stream\TournamentSlides;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestSigner;

/*
 * Age of Empires II on the stream (plan "AoE2 und Trackmania", P8, stage 1),
 * from the league's own data, never a game account: the live lobby slides
 * tell each lobby's countdown and report state and the time limit, and show
 * every lobby of 40 players; the AoE2 spotlight slide (d6) tells the lobby
 * match running, finished with its shared 1st place, or none; a lobby
 * tournament's place 1 is a pride note that passes the stream bot's rules.
 */

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $this->freezeSecond();
});

/**
 * A running AoE2 lobby tournament of `$n` players, drawn now; player `i` is
 * named by `$nameOf(i)` ("Player i" by default).
 */
function aoeLobby(int $n, string $name = 'Lobby Night', ?Closure $nameOf = null): Tournament
{
    $nameOf ??= fn (int $index): string => "Player {$index}";
    $tournament = Tournament::factory()->create([
        'name' => $name, 'game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll,
        'options' => FormatOptions::fromArray([], GameProfile::for('age-of-empires-2', '1v1'))->toArray(),
        'capacity' => $n, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'starts_at' => now(),
        'slug' => 'aoe-stream-'.fake()->unique()->numberBetween(1, 1_000_000), 'created_by_id' => organizer()->id,
        'ladder_address' => Ladders::address('age-of-empires-2', '1v1'),
    ]);

    foreach (range(1, $n) as $index) {
        $user = User::factory()->create(['name' => $nameOf($index)]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $nameOf($index), 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The lobbies of a lobby tournament, in order. @return list<TournamentMatch> */
function aoeLobbies(Tournament $tournament): array
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots.participant.user')->orderBy('position')->get()->all();
}

/** The places `$places` (in slot order) as LobbyResults takes them. @return array<int, int> */
function aoePlaces(TournamentMatch $match, array $places): array
{
    return $match->slots->mapWithKeys(fn ($slot, int $i): array => [$slot->tournament_participant_id => $places[$i]])->all();
}

/** The words a slide shows: its text elements, lower case, one per line. */
function aoeWords(string $svg): string
{
    preg_match_all('/<text[^>]*>(.*?)<\/text>/s', $svg, $texts);

    return mb_strtolower(html_entity_decode(implode("\n", array_map(strip_tags(...), $texts[1])), ENT_QUOTES | ENT_HTML5));
}

/** A rotation scene rendered as the stream shows it after a poll: this poll's upcoming tournaments, the live ones read into the cache. */
function aoeScene(string $scene, ?array $tournament = null): string
{
    $now = (int) now()->getTimestampMs();
    $upcoming = app(TournamentSlides::class)->all($now);
    app(TournamentLiveSlides::class)->snapshots();

    return SceneRenderer::fromConfig()->svg([...app(SceneSource::class)->rotation($scene, null, [], 0, $now, app(StreamStats::class)->all(), $tournament, $upcoming), 'viewers' => 12],
        RotationPlanner::VIEWS[$scene]);
}

test('a running AoE2 lobby tournament shows every lobby with its countdown or report state, and the time limit', function () {
    $tournament = aoeLobby(9);
    [$a, $b] = aoeLobbies($tournament);
    $slides = app(TournamentLiveSlides::class);
    $status = fn (int $nowMs): array => array_column($slides->data($tournament->refresh(), $nowMs)['board']['groups'], 'status', 'title');
    $now = (int) now()->getTimestampMs();

    // The time limit (2 h) after the lobby's set-up (15 min), then the report window (60 min), as Lobbies::reportBy() plans them.
    expect($status($now))->toBe(['Lobby 1' => '2:15 left', 'Lobby 2' => '2:15 left'])
        ->and($status($now + 140 * 60_000))->toBe(['Lobby 1' => 'report due 55:00', 'Lobby 2' => 'report due 55:00'])
        ->and($status($now + 196 * 60_000))->toBe(['Lobby 1' => 'overdue', 'Lobby 2' => 'overdue']);

    // A player's report waits for the directors: "in review", and its places stay off the stream.
    $b->forceFill(['lobby_report' => ['places' => aoePlaces($b, [2, 1, 3, 4]), 'user_id' => null, 'name' => 'Player 2', 'at' => now()->toIso8601String()]])->save();
    $frame = $slides->data($tournament->refresh(), $now);

    expect(array_column($frame['board']['groups'], 'status', 'title'))->toBe(['Lobby 1' => '2:15 left', 'Lobby 2' => 'in review'])
        ->and(array_column($frame['board']['groups'][1]['rows'], 'rank'))->toBe([null, null, null, null]);

    foreach (['ta4', 'tb4', 'tc4'] as $scene) {
        $words = aoeWords(aoeScene($scene, $frame));

        expect($words)->toContain('2 h time limit', 'lobby 1', '2:15 left', 'lobby 2', 'in review', 'player 9');
    }

    // A decided lobby says so.
    app(LobbyResults::class)->enter($a, $tournament->creator, aoePlaces($a, [1, 1, 3, 4, 5]));
    expect($status($now))->toBe(['Lobby 1' => 'decided', 'Lobby 2' => 'in review']);
});

test('the live lobby slides show all five lobbies of 40 players and a single lobby of 4, long names included, nothing hidden', function () {
    $long = aoeLobby(40, 'AoE2 Casual Cup EU, the long lobby night of the weekend', fn (int $index): string => $index % 7 === 0 ? "Satoshi Nakamoto the Very Long Named Player {$index}" : "Player {$index}");
    $small = aoeLobby(4, 'Four');
    $now = (int) now()->getTimestampMs();

    foreach (['ta4', 'tb4', 'tc4'] as $scene) {
        $svg = aoeScene($scene, app(TournamentLiveSlides::class)->data($long, $now));

        expect(preg_match_all('/data-unit="g-\d+-title"/', $svg))->toBe(5, $scene)
            ->and(preg_match_all('/data-unit="g-\d+-name-\d+"/', $svg))->toBe(40, $scene)
            ->and(aoeWords($svg))->not->toContain('more lobby')->toContain('lobby 5', '2:15 left');

        $svg = aoeScene($scene, app(TournamentLiveSlides::class)->data($small, $now));

        expect(preg_match_all('/data-unit="g-\d+-name-\d+"/', $svg))->toBe(4, $scene)
            ->and(aoeWords($svg))->toContain('lobby 1', '2:15 left');
    }

    // Finished, two allies share place 1 in every lobby: ten names, the line holds the whole names it can and counts the rest,
    // above tb4's orange ticker (y 664) and never cut inside a name.
    foreach (aoeLobbies($long) as $lobby) {
        app(LobbyResults::class)->enter($lobby->refresh(), $long->creator, aoePlaces($lobby, [1, 1, 3, 4, 5, 6, 7, 8]));
    }
    $finished = app(TournamentLiveSlides::class)->data($long->refresh(), $now);
    $tb4 = aoeScene('tb4', $finished);
    preg_match('/data-unit="first-line" data-box="[\d.]+ [\d.]+ [\d.]+ ([\d.]+)"[^>]*>([^<]*)</', $tb4, $line);
    preg_match('/data-unit="line"[^>]*>([^<]*)</', aoeScene('tc4', $finished), $tc4);

    expect($long->status)->toBe(TournamentStatus::Finished)
        ->and((float) $line[1])->toBeLessThanOrEqual(664)
        ->and(html_entity_decode($line[2]))->toStartWith('Shared 1st place: ')->toMatch('/ \+\d+ more$/')->not->toContain('…')
        ->and(html_entity_decode($tc4[1]))->toStartWith('Finished: shared 1st place: ')->toMatch('/ \+\d+ more$/')->not->toContain('…');
});

test('the AoE2 spotlight slide says when no lobby match is on, and when the next one starts', function () {
    $svg = aoeScene('d6');

    expect(aoeWords($svg))->toContain('no lobby match on right now.')
        // The ladder's top stays: here the open spot.
        ->toContain('the top spot is open.')
        ->and($svg)->not->toContain('data-unit="now-label"');

    $open = openTournament(['name' => 'Lobby Next', 'game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll,
        'options' => FormatOptions::defaults(GameProfile::for('age-of-empires-2', '1v1'))->toArray(), 'capacity' => 16]);
    Cache::forget(TournamentSlides::CACHE_KEY);
    $frame = collect(app(TournamentSlides::class)->all((int) now()->getTimestampMs()))->firstWhere('id', $open->id);

    expect(aoeWords(aoeScene('d6')))->toContain('no lobby match on right now. next: '.mb_strtolower($frame['startsAt']));

    // Before the stream's poll has read the live tournaments, the slide claims nothing about the match (and reads nothing).
    Cache::forget(TournamentLiveSlides::CACHE_KEY);
    DB::enableQueryLog();
    $now = app(GameSpotlight::class)->now('age-of-empires-2', (int) now()->getTimestampMs());

    expect($now)->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});

test('the AoE2 spotlight slide shows a running lobby match with its lobbies decided and a countdown', function () {
    aoeLobby(9, 'Lobby Night');

    $svg = aoeScene('d6');

    expect(aoeWords($svg))->toContain('lobby night', '0 of 2 lobbies decided', 'lobby 1: 2:15 left')
        ->not->toContain('no lobby match')
        ->not->toContain('the top spot is open.')
        ->and($svg)->toMatch('/data-unit="now-label"[^>]*>Live now</');
});

test('the AoE2 spotlight slide shows a finished lobby match with its shared 1st place', function () {
    $tournament = aoeLobby(9, 'Lobby Done');
    [$a, $b] = aoeLobbies($tournament);
    app(LobbyResults::class)->enter($a, $tournament->creator, aoePlaces($a, [1, 1, 3, 4, 5]));
    app(LobbyResults::class)->enter($b, $tournament->creator, aoePlaces($b, [1, 2, 3, 4]));
    Cache::forget(TournamentLiveSlides::CACHE_KEY);

    $svg = aoeScene('d6');
    $words = aoeWords($svg);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and($words)->toContain('lobby done')
        ->not->toContain('no lobby match')
        ->and($svg)->toMatch('/data-unit="now-label"[^>]*>Shared 1st place</');

    foreach ([$a->slots[0], $a->slots[1], $b->slots[0]] as $slot) {
        expect($words)->toContain(mb_strtolower((string) $slot->participant->name));
    }
});

test('an AoE2 lobby tournament win is a pride note that tags every player on the shared 1st place and passes the stream bot rules', function () {
    $tournament = aoeLobby(9, 'Lobby #Done');
    [$a, $b] = aoeLobbies($tournament);
    app(LobbyResults::class)->enter($a, $tournament->creator, aoePlaces($a, [1, 1, 3, 4, 5]));
    app(LobbyResults::class)->enter($b, $tournament->creator, aoePlaces($b, [1, 2, 3, 4]));
    $winners = collect([$a->slots[0], $a->slots[1], $b->slots[0]])->map(fn ($slot): User => $slot->participant->user);

    $pride = app(PrideSlides::class);
    $win = $pride->read()['win'];
    $svg = SceneRenderer::fromConfig()->svg(['pride' => $pride->framed($pride->read()), 'stats' => [], 'backdrop' => null, 'viewers' => null], RotationPlanner::VIEWS['e1']);

    expect($win)->toMatchArray(['kind' => 'lobby', 'gameId' => $tournament->id, 'tournament' => 'Lobby #Done', 'url' => route('tournaments.show', $tournament)])
        ->and(collect($win['winners'])->pluck('name')->sort()->values()->all())->toBe($winners->map->displayName()->sort()->values()->all())
        ->and(aoeWords($svg))->toContain('tournament win: lobby #done', 'shared 1st place with 2 others', 'age of empires ii, one lobby match');

    foreach ([0, 1] as $variant) {
        $note = app(PrideNotes::class)->compose(1, $variant);
        $tagged = collect($note['tags'])->where(0, 'p')->pluck(1)->sort()->values()->all();

        expect(StreamBotCopy::violations($note['body'], $note['tags']))->toBe([])
            ->and($note['body'])->toContain('Shared 1st place', 'Lobby Done', route('tournaments.show', $tournament))
            ->and(substr_count($note['body'], 'nostr:npub1'))->toBe(3)
            ->and($tagged)->toBe($winners->pluck('pubkey')->sort()->values()->all())
            ->and(collect($note['tags'])->where(0, 't')->all())->toBe([]);
    }
});
