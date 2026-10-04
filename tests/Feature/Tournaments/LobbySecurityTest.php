<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Series\Ladders;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\LobbyResults;
use App\Support\Tournaments\LobbySwitch;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentDraws;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Lobby results under attack (security audit of P10)
|--------------------------------------------------------------------------
|
| F1: the lobbies of a tournament share one pot, so nobody with a stake in
| ANY entry decides any lobby. F2: a director confirms or rejects the report
| they saw, never one swapped since. F3: a lobby nobody decides is closed
| without a result a day after its deadline, so a cup series goes on. F4: a
| calendar event the switch could not republish is healed later. N1: end
| screens leave no orphans, keep no EXIF, and are deleted after 30 days.
|
*/

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/** A running Age of Empires II lobby tournament of `$n` players, drawn and synced. */
function securedLobby(int $n): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll,
        'options' => FormatOptions::fromArray([], GameProfile::for('age-of-empires-2', '1v1'))->toArray(),
        'capacity' => $n, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'starts_at' => now(),
        'slug' => 'secured-lobby-'.fake()->unique()->numberBetween(1, 1_000_000), 'created_by_id' => organizer()->id,
        'ladder_address' => Ladders::address('age-of-empires-2', '1v1'),
    ]);

    foreach (range(1, $n) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** @return list<TournamentMatch> */
function securedLobbies(Tournament $tournament): array
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots.participant')->orderBy('position')->get()->all();
}

/** @return array<int, int> participant id => place, in slot order */
function securedPlaces(TournamentMatch $match, array $places): array
{
    return $match->slots->mapWithKeys(fn ($slot, int $index): array => [$slot->tournament_participant_id => $places[$index]])->all();
}

function securedPlayer(TournamentMatch $match, int $slot): User
{
    return User::query()->findOrFail($match->loadMissing('slots.participant')->slots[$slot]->participant->user_id);
}

function securedDirector(Tournament $tournament, User $user, int $addedBy): void
{
    DB::table('tournament_directors')->insert(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'added_by_id' => $addedBy, 'created_at' => now(), 'updated_at' => now()]);
}

test('F1: nobody with a stake in any lobby decides another lobby of the same pot; a neutral admin does', function () {
    $tournament = securedLobby(9);
    [$first, $second] = securedLobbies($tournament);
    $results = app(LobbyResults::class);

    // A player of lobby 1 the creator named director.
    $playingDirector = securedPlayer($first, 0);
    securedDirector($tournament, $playingDirector, $tournament->created_by_id);
    // A player of lobby 1 who is an admin.
    $playingAdmin = securedPlayer($first, 1);
    $playingAdmin->forceFill(['pubkey' => str_repeat('a1', 32)])->save();
    Admin::query()->create(['pubkey' => $playingAdmin->pubkey]);
    // The organizer plays lobby 1 and named an alt as director.
    $organizer = securedPlayer($first, 2);
    $tournament->forceFill(['created_by_id' => $organizer->id])->save();
    $alt = User::factory()->create();
    securedDirector($tournament->refresh(), $alt, $organizer->id);
    // An admin who plays nowhere.
    [$neutral] = keyedPlayer();
    Admin::query()->create(['pubkey' => $neutral->pubkey]);

    $actors = ['playing director' => $playingDirector, 'playing admin' => $playingAdmin, 'organizer\'s alt' => $alt, 'organizer himself' => $organizer];
    $refused = [];

    foreach ($actors as $who => $actor) {
        expect($playingAdmin->isAdmin())->toBeTrue();

        try {
            $results->enter($second, $actor, securedPlaces($second, [1, 2, 3, 4]));
            $refused[$who] = false;
        } catch (TournamentRuleViolation $e) {
            $refused[$who] = $e->reason === 'interested';
        }

        expect(LobbyResults::mayDecide($tournament, $second, $actor))->toBeFalse();
    }

    expect($refused)->toBe(array_fill_keys(array_keys($actors), true))
        ->and($second->refresh()->result)->toBeNull()
        ->and(LobbyResults::mayDecide($tournament, $second, $neutral))->toBeTrue();

    $results->enter($second, $neutral, securedPlaces($second, [1, 1, 3, 4]));

    expect($second->refresh()->result['ranks'])->toBe([1, 1, 3, 4]);
});

test('F2: a report swapped between render and confirm is refused and nothing is stored; reject is held the same way', function () {
    $tournament = securedLobby(4);
    [$lobby] = securedLobbies($tournament);
    $honest = securedPlayer($lobby, 0);
    $cheat = securedPlayer($lobby, 3);
    $director = $tournament->creator;
    $results = app(LobbyResults::class);

    $results->report($lobby, $honest, securedPlaces($lobby, [1, 2, 3, 4]), UploadedFile::fake()->image('honest.png', 64, 64));
    $page = Livewire::actingAs($director)->test('tournament-lobbies', ['tournament' => $tournament]);
    $shown = LobbyResults::reportIdentity($lobby->refresh()->lobby_report);

    // The card carries the identity of the report it shows.
    expect($page->html())->toContain($shown);

    // Between render and click: another player replaces the report, himself alone on place 1.
    $this->travel(1)->seconds();
    $results->report($lobby, $cheat, securedPlaces($lobby, [4, 2, 3, 1]), UploadedFile::fake()->image('cheat.png', 64, 64));

    $page->call('confirm', $lobby->id, $shown)->assertSee('The report changed — look again.');
    expect($lobby->refresh()->result)->toBeNull()
        ->and($lobby->lobby_report['user_id'])->toBe($cheat->id);

    $page->set("reasons.{$lobby->id}", 'Wrong end screen.')->call('reject', $lobby->id, $shown)->assertSee('The report changed — look again.');
    expect($lobby->refresh()->lobby_report)->not->toBeNull();

    // Looked again: the shown report is the current one, the confirmation stores exactly it.
    $page->call('confirm', $lobby->id, LobbyResults::reportIdentity($lobby->lobby_report));
    expect($lobby->refresh()->result['ranks'])->toBe([4, 2, 3, 1]);
});

test('F2: a disqualified player no longer reports his lobby', function () {
    $tournament = securedLobby(5);
    [$lobby] = securedLobbies($tournament);
    $player = securedPlayer($lobby, 0);
    app(TournamentControl::class)->disqualify($tournament, $tournament->creator, $lobby->slots[0]->tournament_participant_id, 'Cheated.');

    expect(LobbyResults::plays($lobby->refresh()->load('slots.participant', 'tournament'), $player))->toBeFalse()
        ->and(fn () => app(LobbyResults::class)->report($lobby, $player, securedPlaces($lobby, [1, 2, 3, 4, 5]), UploadedFile::fake()->image('e.png', 64, 64)))
        ->toThrow(TournamentRuleViolation::class, 'Only a player of this lobby can report its places.');
});

test('a confirm and a reject by two directors never both apply', function () {
    $tournament = securedLobby(4);
    [$lobby] = securedLobbies($tournament);
    $second = User::factory()->create();
    securedDirector($tournament, $second, $tournament->created_by_id);
    $results = app(LobbyResults::class);
    $results->report($lobby, securedPlayer($lobby, 0), securedPlaces($lobby, [1, 2, 3, 4]), UploadedFile::fake()->image('e.png', 64, 64));
    $shown = LobbyResults::reportIdentity($lobby->refresh()->lobby_report);

    $results->confirm($lobby, $tournament->creator, $shown);

    expect(fn () => $results->reject($lobby, $second, 'Not the end screen.', $shown))->toThrow(TournamentRuleViolation::class)
        ->and($lobby->refresh()->result['ranks'])->toBe([1, 2, 3, 4])
        ->and($lobby->lobby['rejected'] ?? null)->toBeNull();
});

test('F3: an AoE2 cup nobody decides tells the admins, closes the lobby without a result a day later, finishes, and the next cup opens', function () {
    $hash = hash('sha256', 'block 900001');
    $tip = 900000;
    Http::fake(function ($request) use (&$tip, $hash) {
        return match (true) {
            str_ends_with($request->url(), '/blocks/tip/height') => Http::response((string) $tip),
            str_ends_with($request->url(), '/block-height/900001') => Http::response($hash),
            str_ends_with($request->url(), '/block/'.$hash) => Http::response(['timestamp' => now()->addMinutes(10)->getTimestamp()]),
            default => Http::response('', 404),
        };
    });
    config(['esports.casual_cups.enabled' => ['age-of-empires-2']]);
    [$admin] = keyedPlayer();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $cup = app(CasualCups::class)->ensure('age-of-empires-2', 'eu');
    $players = [];
    foreach (range(1, 3) as $ignored) {
        [$player, $signer] = keyedPlayer();
        soloSignup($cup->refresh(), $player, $signer);
        $players[] = $player;
    }
    $this->travelTo($cup->signup_closes_at->addMinute());
    cupTick();
    $tip = 900006;
    app(TournamentDraws::class)->resolve($cup->refresh());
    cupTick();
    $lobby = TournamentMatch::query()->where('tournament_id', $cup->id)->whereNotNull('lobby')->sole();
    // A player's report waits; the league never confirms it.
    app(LobbyResults::class)->report($lobby, $players[0], securedPlaces($lobby->load('slots'), [1, 2, 3]), UploadedFile::fake()->image('e.png', 64, 64));
    $reportBy = LobbyResults::reportBy($lobby);

    $this->travelTo($reportBy->addMinute());
    cupTick();
    cupTick();
    $told = DB::table('notifications')->where('notifiable_id', $admin->id)->pluck('data')
        ->filter(fn (string $data): bool => str_contains((string) (json_decode($data, true)['title'] ?? ''), 'waits for a decision'))->count();

    expect($told)->toBe(1)
        ->and($lobby->refresh()->result)->toBeNull()
        ->and($cup->refresh()->status)->toBe(TournamentStatus::Running);

    foreach (range(1, 30) as $ignored) {
        $this->travel(1)->days();
        cupTick();
    }

    $result = $lobby->refresh()->result;

    expect($result)->toMatchArray(['decided' => 'no_result', 'by' => 'league', 'lobby_label' => 'none'])
        ->and($result['unplaced'])->toHaveCount(3)
        ->and(LobbyResults::describe($result))->toBe('closed without a result')
        ->and($cup->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(app(TournamentPlacements::class)->of($cup))->toBe([])
        ->and(TournamentModerationEntry::query()->where('tournament_id', $cup->id)->where('action', 'lobby_no_result')->count())->toBe(1)
        ->and(Tournament::query()->where('game', 'age-of-empires-2')->whereKeyNot($cup->id)->where('status', TournamentStatus::Signup)->exists())->toBeTrue();
});

test('F4: a calendar event the switch could not republish is healed once the league key is back', function () {
    $aoe = GameProfile::for('age-of-empires-2', '1v1');
    $tournament = openTournament(['game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'options' => FormatOptions::defaults($aoe)->toArray(), 'capacity' => 16]);
    $summary = fn (): ?string => collect($tournament->refresh()->event()->firstOrFail()->payload()['tags'])->firstWhere(0, 'summary')[1] ?? null;

    config(['esports.league.nsec' => null]);
    (require database_path('migrations/2026_10_01_160100_switch_undrawn_aoe2_tournaments_to_lobbies.php'))->up();

    expect($tournament->refresh()->format)->toBe(TournamentFormat::FreeForAll)
        ->and($summary())->not->toContain('one lobby match')
        ->and(app(LobbySwitch::class)->healCalendar())->toBe([]);

    config(['esports.league.nsec' => (new TestSigner)->secret]);
    cupTick();

    expect($summary())->toContain('one lobby match')
        ->and(app(LobbySwitch::class)->healCalendar())->toBe([]);
});

test('N1: an end screen is stored re-encoded without EXIF, a refused report leaves no file, and a decided lobby\'s screens go after 30 days', function () {
    $tournament = securedLobby(4);
    [$lobby] = securedLobbies($tournament);
    $player = securedPlayer($lobby, 0);
    $results = app(LobbyResults::class);

    // A JPEG with an EXIF block (APP1, "Exif\0\0" and a GPS marker) after the SOI.
    $jpeg = UploadedFile::fake()->image('end.jpg', 64, 64);
    $bytes = (string) file_get_contents($jpeg->getRealPath());
    $exif = "Exif\0\0GPSLatitude 52.5200";
    file_put_contents($jpeg->getRealPath(), substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2));
    expect((string) file_get_contents($jpeg->getRealPath()))->toContain('GPSLatitude');

    $results->report($lobby, $player, securedPlaces($lobby, [1, 2, 3, 4]), $jpeg);
    $stored = (string) Storage::disk('local')->get($lobby->refresh()->lobby_report['screenshot']);

    expect($stored)->not->toContain('GPSLatitude')->not->toContain('Exif')
        // Re-encoded lossy as WebP (re-audit M2): RIFF, then WEBP.
        ->and(substr($stored, 0, 4))->toBe('RIFF')->and(substr($stored, 8, 4))->toBe('WEBP');

    // Refused inside the transaction that would accept it (here: its save fails under the lock): the new file goes with it.
    $before = Storage::disk('local')->allFiles("lobby-results/{$lobby->id}");
    $refuse = true;
    TournamentMatch::saving(function () use (&$refuse): void {
        if ($refuse) {
            throw new RuntimeException('Refused under the lock.');
        }
    });

    expect(fn () => $results->report($lobby, $player, securedPlaces($lobby, [1, 2, 3, 4]), UploadedFile::fake()->image('again.png', 64, 64)))->toThrow(RuntimeException::class)
        ->and($before)->toHaveCount(1)
        ->and(Storage::disk('local')->allFiles("lobby-results/{$lobby->id}"))->toBe($before);

    $refuse = false;
    $results->enter($lobby, $tournament->creator, securedPlaces($lobby, [1, 2, 3, 4]));

    // 29 days after the decision the screens stay, after 31 they are deleted.
    $this->travel(29)->days();
    expect(LobbyResults::pruneScreenshots())->toBe(0)
        ->and(Storage::disk('local')->allFiles("lobby-results/{$lobby->id}"))->not->toBe([]);
    $this->travel(2)->days();
    expect(LobbyResults::pruneScreenshots())->toBe(1)
        ->and(Storage::disk('local')->allFiles("lobby-results/{$lobby->id}"))->toBe([]);
});

test('the stored heat options of a drawn free-for-all win over the lobby game\'s current ones', function () {
    $tournament = securedLobby(9);
    $stored = [...$tournament->options, 'heatSize' => 5, 'heatAdvance' => 3, 'lobbyMinutes' => 0];
    DB::table('tournaments')->where('id', $tournament->id)->update(['options' => json_encode($stored)]);

    $drawn = Tournament::query()->findOrFail($tournament->id)->formatOptions();
    $undrawn = Tournament::factory()->create(['game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll, 'status' => TournamentStatus::Signup,
        'options' => $stored, 'created_by_id' => organizer()->id])->formatOptions();

    expect([$drawn->heatSize, $drawn->heatAdvance, $drawn->lobbyMinutes])->toBe([5, 3, 0])
        ->and([$undrawn->heatSize, $undrawn->heatAdvance, $undrawn->lobbyMinutes > 0])->toBe([8, 1, true]);
});

/** The overdue notices each user got, by user id. */
function securedNotices(): array
{
    return DB::table('notifications')->get(['notifiable_id', 'data'])
        ->filter(fn (object $row): bool => str_contains((string) (json_decode($row->data, true)['title'] ?? ''), 'waits for a decision'))
        ->countBy(fn (object $row): int => (int) $row->notifiable_id)->all();
}

test('M1 (R5): the overdue notice skips a playing organizer and his director and goes to an admin who may decide', function () {
    $tournament = securedLobby(9);
    [$first, $second] = securedLobbies($tournament);
    $organizer = securedPlayer($first, 0);
    $tournament->forceFill(['created_by_id' => $organizer->id])->save();
    $alt = User::factory()->create();
    securedDirector($tournament->refresh(), $alt, $organizer->id);
    [$admin] = keyedPlayer();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->travelTo(LobbyResults::reportBy($second)->addMinute());
    $done = app(LobbyResults::class)->tick();
    $notices = securedNotices();

    expect($done)->toMatchArray(['overdue' => 2, 'closed' => 0])
        ->and($notices[$organizer->id] ?? 0)->toBe(0)
        ->and($notices[$alt->id] ?? 0)->toBe(0)
        ->and($notices[$admin->id] ?? 0)->toBe(2)
        ->and(LobbyResults::mayDecide($tournament, $second, $admin))->toBeTrue();
});

test('M1 (R12): after a scheduler outage the first tick tells the deciders and closes nothing; the close comes a day after that notice', function () {
    $tournament = securedLobby(4);
    [$lobby] = securedLobbies($tournament);
    $results = app(LobbyResults::class);

    // Nothing ran until a day and an hour after the deadline.
    $this->travelTo(LobbyResults::reportBy($lobby)->addHours(25));
    expect($results->tick())->toMatchArray(['overdue' => 1, 'closed' => 0])
        ->and($lobby->refresh()->result)->toBeNull()
        ->and(securedNotices()[$tournament->created_by_id] ?? 0)->toBe(1);

    $this->travel(23)->hours();
    expect($results->tick())->toMatchArray(['overdue' => 0, 'closed' => 0]);

    $this->travel(2)->hours();
    expect($results->tick())->toMatchArray(['overdue' => 0, 'closed' => 1])
        ->and($lobby->refresh()->result['decided'])->toBe('no_result');
});

/** A PNG of only a header claiming `$width` × `$height` (no pixels to decode). */
function securedPngHeader(int $width, int $height): UploadedFile
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $path = (string) tempnam(sys_get_temp_dir(), 'png');
    file_put_contents($path, "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IDAT', '').$chunk('IEND', ''));

    return new UploadedFile($path, 'huge.png', 'image/png', null, true);
}

/** A busy 4K end screen: many coloured boxes, so it does not compress to nothing. */
function securedEndScreen4k(): UploadedFile
{
    $image = imagecreatetruecolor(3840, 2160);
    mt_srand(21);
    foreach (range(1, 4000) as $ignored) {
        $x = mt_rand(0, 3800);
        $y = mt_rand(0, 2120);
        imagefilledrectangle($image, $x, $y, $x + mt_rand(4, 120), $y + mt_rand(4, 60), (int) imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
    }
    $path = tempnam(sys_get_temp_dir(), 'shot').'.png';
    imagepng($image, $path);
    imagedestroy($image);

    return new UploadedFile($path, 'end-screen.png', 'image/png', null, true);
}

test('M2: a picture over 4096 px is refused from its header before decoding; a 4K end screen is stored at 1920 px and under 2 MB; a sixth report in ten minutes is refused', function () {
    $tournament = securedLobby(4);
    [$lobby] = securedLobbies($tournament);
    $player = securedPlayer($lobby, 0);
    $results = app(LobbyResults::class);

    $started = microtime(true);
    expect(fn () => $results->report($lobby, $player, securedPlaces($lobby, [1, 2, 3, 4]), securedPngHeader(6000, 6000)))
        ->toThrow(TournamentRuleViolation::class, 'The screenshot is too large: at most 4096 pixels on its longest side.');
    expect(microtime(true) - $started)->toBeLessThan(0.5)
        ->and(Storage::disk('local')->allFiles("lobby-results/{$lobby->id}"))->toBe([]);

    $shot = securedEndScreen4k();
    // The downscale is measured on a picture with content, not on one flat colour.
    expect(filesize((string) $shot->getRealPath()))->toBeGreaterThan(100_000);
    $results->report($lobby, $player, securedPlaces($lobby, [1, 2, 3, 4]), $shot);
    $stored = (string) Storage::disk('local')->get($lobby->refresh()->lobby_report['screenshot']);
    $size = getimagesizefromstring($stored);

    expect([$size[0], $size[1]])->toBe([1920, 1080])
        ->and(strlen($stored))->toBeLessThanOrEqual(2 * 1024 * 1024)
        ->and($size['mime'])->toBe('image/webp');

    // Two reports counted so far (the refused one too); three more pass, the sixth is refused.
    foreach (range(1, 3) as $ignored) {
        $results->report($lobby, $player, securedPlaces($lobby, [1, 2, 3, 4]), UploadedFile::fake()->image('e.png', 64, 64));
    }
    expect(fn () => $results->report($lobby, $player, securedPlaces($lobby, [1, 2, 3, 4]), UploadedFile::fake()->image('e.png', 64, 64)))
        ->toThrow(TournamentRuleViolation::class, 'Too many reports for this lobby.');

    // Another player of the lobby is not held by it, and eleven minutes later the first one reports again.
    $results->report($lobby, securedPlayer($lobby, 1), securedPlaces($lobby, [1, 2, 3, 4]), UploadedFile::fake()->image('e.png', 64, 64));
    $this->travel(11)->minutes();
    $results->report($lobby, $player, securedPlaces($lobby, [1, 2, 3, 4]), UploadedFile::fake()->image('e.png', 64, 64));

    expect($lobby->refresh()->lobby_report['user_id'])->toBe($player->id);
});

test('L1 (R6): a last-minute report keeps the honest end screen, the deciders see every report, and a reject reopens reporting for 30 minutes', function () {
    $tournament = securedLobby(4);
    [$lobby] = securedLobbies($tournament);
    $honest = securedPlayer($lobby, 0);
    $cheat = securedPlayer($lobby, 3);
    $director = $tournament->creator;
    $results = app(LobbyResults::class);
    $reportBy = LobbyResults::reportBy($lobby);

    $this->travelTo($reportBy->subMinutes(5));
    $results->report($lobby, $honest, securedPlaces($lobby, [1, 2, 3, 4]), UploadedFile::fake()->image('honest.png', 64, 64));
    $honestShot = $lobby->refresh()->lobby_report['screenshot'];
    $this->travelTo($reportBy->subMinute());
    $results->report($lobby, $cheat, securedPlaces($lobby, [4, 2, 3, 1]), UploadedFile::fake()->image('cheat.png', 64, 64));

    expect(Storage::disk('local')->exists($honestShot))->toBeTrue()
        ->and(LobbyResults::earlierReports($lobby->refresh()->lobby_report))->toHaveCount(1)
        ->and(LobbyResults::earlierReports($lobby->lobby_report)[0]['user_id'])->toBe($honest->id);

    // After the deadline: the honest player cannot report; the director rejects the last-minute report.
    $this->travelTo($reportBy->addMinutes(10));
    expect(fn () => $results->report($lobby, $honest, securedPlaces($lobby, [1, 2, 3, 4]), UploadedFile::fake()->image('again.png', 64, 64)))
        ->toThrow(TournamentRuleViolation::class, 'The time to report this lobby is over.');

    Livewire::actingAs($director)->test('tournament-lobbies', ['tournament' => $tournament])
        ->assertSee('data-test="lobby-earlier"', false)->assertSee('Earlier reports');
    $results->reject($lobby, $director, 'Wrong end screen.', LobbyResults::reportIdentity(LobbyResults::currentReport($lobby->refresh()->lobby_report)));

    expect(LobbyResults::reportOpen($lobby->refresh()))->toBeTrue()
        ->and(LobbyResults::currentReport($lobby->lobby_report))->toBeNull()
        ->and(LobbyResults::earlierReports($lobby->lobby_report))->toHaveCount(2);

    $results->report($lobby, $honest, securedPlaces($lobby, [1, 2, 3, 4]), UploadedFile::fake()->image('again.png', 64, 64));
    $earlier = LobbyResults::earlierReports($lobby->refresh()->lobby_report);

    expect(Storage::disk('local')->allFiles("lobby-results/{$lobby->id}"))->toHaveCount(3)
        ->and(array_column($earlier, 'user_id'))->toBe([$honest->id, $cheat->id])
        ->and($earlier[1]['rejected']['reason'])->toBe('Wrong end screen.');

    // The deciders open every earlier end screen; the list shows the latest first.
    $this->actingAs($director)->get(route('tournaments.lobby-screenshot', [$tournament, $lobby, 'report' => 0]))->assertOk();
    $this->actingAs($director)->get(route('tournaments.lobby-screenshot', [$tournament, $lobby, 'report' => 5]))->assertNotFound();
    Livewire::actingAs($director)->test('tournament-lobbies', ['tournament' => $tournament])
        ->assertSeeInOrder(['Rejected: Wrong end screen.', 'Reported by '.$honest->displayName()]);

    // The reopening ends after 30 minutes.
    $this->travel(31)->minutes();
    expect(LobbyResults::reportOpen($lobby->refresh()))->toBeFalse();
});

test('L2: only a logged-in player of a lobby in its report window gets an upload URL; old temporary uploads are deleted', function () {
    Storage::fake('tmp-for-tests');
    $tournament = securedLobby(4);
    [$lobby] = securedLobbies($tournament);
    $player = securedPlayer($lobby, 0);
    $file = [['name' => 'a.png', 'size' => 1024, 'type' => 'image/png']];

    Livewire::test('tournament-lobbies', ['tournament' => $tournament])->call('_startUpload', 'shot', $file, false)->assertForbidden();
    Livewire::actingAs(User::factory()->create())->test('tournament-lobbies', ['tournament' => $tournament])->call('_startUpload', 'shot', $file, false)->assertForbidden();
    Livewire::actingAs($player)->test('tournament-lobbies', ['tournament' => $tournament])->call('_startUpload', 'places', $file, false)->assertForbidden();
    Livewire::actingAs($player)->test('tournament-lobbies', ['tournament' => $tournament])->call('_startUpload', 'shot', $file, false)
        ->assertOk()->assertDispatched('upload:generatedSignedUrl');

    $this->travelTo(LobbyResults::reportBy($lobby)->addMinute());
    Livewire::actingAs($player)->test('tournament-lobbies', ['tournament' => $tournament])->call('_startUpload', 'shot', $file, false)->assertForbidden();

    // The hourly prune: a day-old temporary upload goes, a fresh one stays.
    $disk = Storage::disk('tmp-for-tests');
    $disk->put('livewire-tmp/old.png', 'old');
    $disk->put('livewire-tmp/new.png', 'new');
    touch($disk->path('livewire-tmp/old.png'), now()->subHours(25)->getTimestamp());
    touch($disk->path('livewire-tmp/new.png'), now()->subHours(2)->getTimestamp());
    $this->artisan('uploads:prune-tmp')->assertSuccessful();

    expect($disk->exists('livewire-tmp/old.png'))->toBeFalse()
        ->and($disk->exists('livewire-tmp/new.png'))->toBeTrue();
});

test('a game that plays no lobbies never reads lobby minutes from stored options, and the reports never leave the model', function () {
    expect(FormatOptions::fromArray(['lobbyMinutes' => 500], GameProfile::for('rocket-league', '3v3'))->lobbyMinutes)->toBe(0);

    $tournament = securedLobby(4);
    [$lobby] = securedLobbies($tournament);
    app(LobbyResults::class)->report($lobby, securedPlayer($lobby, 0), securedPlaces($lobby, [1, 2, 3, 4]), UploadedFile::fake()->image('e.png', 64, 64));

    expect($lobby->refresh()->toArray())->not->toHaveKey('lobby_report')->not->toHaveKey('lobby_password');
});
