<?php

use App\Enums\OverlayVariant;
use App\Enums\TournamentFormat;
use App\Models\ChessGame;
use App\Models\OverlayPreset;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\TournamentRunner;

/*
|--------------------------------------------------------------------------
| What the league live and tournament overlays read (plan "OBS-Broadcast-Overlays", P3, P4)
|--------------------------------------------------------------------------
|
| The snapshot of a league live preset carries every game of the league (the game spots) and the latest wins over
| every game; its crawl names them, the open tournaments with their start, and the invitation, and leaves out what
| a module switches off. The snapshot of a tournament preset carries the board (the round on now), every match with
| public names, seeds and results (the overlay diffs them for its moments), a status line that never states a count
| the banner could hold past its truth, its QR code with the `qr` module and its pot only above zero.
|
*/

/** A published, running single-elimination chess tournament of `$n` entries named after the league's regulars. */
function overlayTournament(int $n = 8, TournamentFormat $format = TournamentFormat::SingleElimination): Tournament
{
    $tournament = runningChess($format, $n);
    $tournament->forceFill(['name' => 'Blitz Open', 'published_at' => now()->subHour()])->save();

    foreach ($tournament->participants()->orderBy('seed')->get() as $index => $participant) {
        $participant->forceFill(['name' => 'seed'.($index + 1)])->save();
    }

    return $tournament->refresh();
}

/** Enter the open match `$key`, the side in slot `$winner` winning. */
function overlayResult(Tournament $tournament, string $key, int $winner): void
{
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', $key)->firstOrFail();
    app(TournamentRunner::class)->enterResult($match, $tournament->creator, ['result' => $winner === 0 ? '1-0' : '0-1']);
}

test('a league live snapshot carries every game and the latest wins, and its crawl names them, the open tournaments and the invitation', function () {
    $winner = User::factory()->create(['name' => 'hodlqueen']);
    $loser = User::factory()->create(['name' => 'markusturm']);
    ChessGame::factory()->finished('0-1')->create(['white_id' => $loser->id, 'black_id' => $winner->id]);
    Tournament::factory()->signup()->create(['published_at' => now(), 'signup_closes_at' => now()->addDay(), 'starts_at' => now()->addDays(2), 'name' => 'Autumn Cup']);
    OverlayPreset::factory()->withToken($token = str_repeat('f', 48))->create(['locale' => 'en']);
    OverlayPreset::factory()->withToken($quiet = str_repeat('g', 48))->create(['locale' => 'en', 'modules' => ['ads' => false] + OverlayPreset::MODULES]);

    $data = $this->getJson('/broadcast/'.$token.'/snapshot.json')->assertOk()->json();
    $texts = collect($data['ticker'])->pluck('text');

    expect(collect($data['games'])->firstWhere('slug', 'chess'))->toBe(['slug' => 'chess', 'name' => 'Chess', 'emblem' => 'emblem-chess'])
        ->and($data['recent'][0])->toMatchArray(['game' => 'chess', 'winner' => 'hodlqueen', 'loser' => 'markusturm', 'emblem' => 'emblem-chess'])
        // A one-game score written from White's side ("0–1") would read against the winner: left out.
        ->and($data['recent'][0]['score'])->toBeNull()
        ->and($texts)->toContain('hodlqueen beats markusturm')
        ->and($texts->first(fn (string $text): bool => str_starts_with($text, 'Autumn Cup, starts ')))->not->toBeNull()
        ->and($data['upcoming'][0]['starts'])->not->toBe('')
        ->and(collect($data['ticker'])->where('head', 'In the league')->pluck('text')->all())->toBe(collect($data['games'])->pluck('name')->all())
        ->and(collect($data['ticker'])->pluck('head'))->toContain('Join in');

    $off = $this->getJson('/broadcast/'.$quiet.'/snapshot.json')->json();
    expect(collect($off['ticker'])->pluck('head'))->not->toContain('Join in')->not->toContain('In the league')
        ->and(collect($off['ticker'])->pluck('text'))->toContain('hodlqueen beats markusturm');
});

test('a tournament snapshot carries its board, every match with seeds and results, and a status line without a count', function () {
    $tournament = overlayTournament();
    // Round 1 pairs 1-8, 4-5, 2-7, 3-6: the 7th seed beats the 2nd.
    overlayResult($tournament, 'm1-3', 1);
    OverlayPreset::factory()->withToken($token = str_repeat('h', 48))->create(['locale' => 'en', 'variant' => OverlayVariant::Tournament, 'tournament_id' => $tournament->id]);
    OverlayPreset::factory()->withToken($noQr = str_repeat('i', 48))->create(['locale' => 'en', 'variant' => OverlayVariant::Tournament, 'tournament_id' => $tournament->id, 'modules' => ['qr' => false] + OverlayPreset::MODULES]);

    $t = $this->getJson('/broadcast/'.$token.'/snapshot.json')->assertOk()->json('tournament');
    $boxes = collect($t['boxes']);
    $upset = $boxes->firstWhere('key', 'm1-3');

    expect($t['board'])->toMatchArray(['kind' => 'bracket', 'round' => 'Round 1', 'title' => null])
        ->and($t['board']['matches'])->toHaveCount(4)
        ->and($t['statusLine'])->toBe('Round 1, Single Elimination')
        ->and($t['statusLine'])->not->toMatch('/\d+ of \d+/')
        ->and($boxes->pluck('key')->all())->toBe(['m1-1', 'm1-2', 'm1-3', 'm1-4', 'm2-1', 'm2-2', 'm3-1'])
        ->and($boxes->where('final', true)->pluck('key')->all())->toBe(['m3-1'])
        ->and($boxes->firstWhere('key', 'm2-1')['round'])->toBe('Semifinal')
        ->and($upset['status'])->toBe('done')
        ->and($upset['sides'])->toBe([
            ['name' => 'seed2', 'known' => true, 'seed' => 2, 'score' => '0', 'won' => false],
            ['name' => 'seed7', 'known' => true, 'seed' => 7, 'score' => '1', 'won' => true],
        ])
        ->and($boxes->firstWhere('key', 'm1-1')['live'])->toBeTrue()
        ->and($t['qr'])->toContain('<svg')
        ->and($t['pot'])->toBeNull();

    $ticker = collect($this->getJson('/broadcast/'.$token.'/snapshot.json')->json('ticker'));
    expect($ticker->where('head', 'Up now')->pluck('text'))->toContain('seed1 against seed8')
        ->and($ticker->where('head', 'Result')->pluck('text')->all())->toBe(['seed7 beats seed2'])
        ->and($ticker->pluck('head'))->not->toContain('Prize pot')->not->toContain('Today')
        ->and($this->getJson('/broadcast/'.$noQr.'/snapshot.json')->json('tournament.qr'))->toBeNull();

    cache()->flush();
    $tournament->forceFill(['pot_source' => Tournament::POT_LEAGUE, 'prize_target_sats' => 21_000])->save();
    $pot = $this->getJson('/broadcast/'.$token.'/snapshot.json')->json();
    expect($pot['tournament']['pot'])->toBe(21_000)
        ->and(collect($pot['ticker'])->firstWhere('head', 'Prize pot')['text'] ?? null)->toContain('Blitz Open');
});

test('an open tournament shows its sign-ups and start, a round robin its table, a finished one its champion', function () {
    $open = Tournament::factory()->signup()->create(['published_at' => now(), 'signup_closes_at' => now()->addHours(2), 'starts_at' => now()->addHours(3), 'name' => 'Evening Cup']);
    $player = User::factory()->create();
    TournamentSignup::query()->create(['tournament_id' => $open->id, 'user_id' => $player->id, 'name' => 'early bird', 'members' => [$player->id]]);
    $table = overlayTournament(4, TournamentFormat::RoundRobin);
    $done = overlayTournament(4);
    playOutAsDirector($done);
    foreach (['j' => $open, 'k' => $table, 'l' => $done->refresh()] as $letter => $tournament) {
        OverlayPreset::factory()->withToken(str_repeat($letter, 48))->create(['locale' => 'en', 'variant' => OverlayVariant::Tournament, 'tournament_id' => $tournament->id]);
    }

    $signup = $this->getJson('/broadcast/'.str_repeat('j', 48).'/snapshot.json')->json('tournament');
    expect($signup)->toMatchArray(['status' => 'signup', 'entries' => 1, 'board' => null, 'boxes' => []])
        ->and($signup['statusLine'])->toBe('Sign-up open, starts '.$signup['starts']);

    $robin = $this->getJson('/broadcast/'.str_repeat('k', 48).'/snapshot.json')->json('tournament.board');
    expect($robin['kind'])->toBe('table')
        ->and($robin['round'])->toBe('Round 1')
        ->and(collect($robin['rows'])->pluck('name')->sort()->values()->all())->toBe(['seed1', 'seed2', 'seed3', 'seed4'])
        ->and($robin['matches'])->toHaveCount(2);

    $finished = $this->getJson('/broadcast/'.str_repeat('l', 48).'/snapshot.json')->json('tournament');
    expect($finished['champion'])->not->toBeNull()
        ->and($finished['statusLine'])->toBe('Finished, won by '.$finished['champion'])
        ->and($finished['board']['round'])->toBe('Final');
});
