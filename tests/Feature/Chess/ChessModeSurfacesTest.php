<?php

/*
|--------------------------------------------------------------------------
| Every chess mode of the registry on every surface (plan "Schach Rapid und Clan", P3)
|--------------------------------------------------------------------------
|
| Until rapid 10+5 joined, every page wrote "blitz" for every chess game that
| was not daily (56 `isCorrespondence()` ternaries in 28 files). The pages
| now name a game's mode from the registry (App\Support\Chess\ChessModes).
| This guard reads the modes from the registry, puts a game, a rating or an
| invite of each mode where a surface shows one, and expects each mode by its
| own name on each surface, and never "Blitz" for a rapid game. A surface
| that hard-codes a mode fails here, naming the surface and the mode it lost.
|
*/

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\ChessInviteStatus;
use App\Enums\InviteLinkType;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Cards\PageCardFacts;
use App\Support\Cards\SharePosts;
use App\Support\Chess\ChessModes;
use App\Support\Chess\ChessPgn;
use App\Support\Dock\OpenMatches;
use App\Support\Invites\InviteLinks;
use App\Support\Notifications\ChessNotifications;
use App\Support\Pages\RulesPage;
use App\Support\Rating\Ratings;
use App\Support\SeasonChain\ChainOverview;
use App\Support\Seo\Sitemap;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\StreamTexts;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    $this->withoutVite();
});

/** A game of `$mode` on that mode's registry clock (daily: one day a move). */
function surfaceGame(string $mode, array $attributes = []): ChessGame
{
    if ($mode === ChessGame::CORRESPONDENCE) {
        return ChessGame::factory()->daily()->create($attributes);
    }

    [$base, $increment] = array_map(intval(...), explode('+', (string) app(GameRegistry::class)->mode('chess', $mode)?->timeControl));

    return ChessGame::factory()->create(['mode' => $mode, 'initial_ms' => $base * 1000, 'increment_ms' => $increment * 1000, 'white_ms' => $base * 1000, 'black_ms' => $base * 1000, ...$attributes]);
}

/** The values of `data-mode` (or `$attribute`) on the elements carrying `data-test="$test"`. */
function surfaceModes(string $html, string $test, string $attribute = 'data-mode'): array
{
    preg_match_all('#<[a-z]+[^>]*data-test="'.preg_quote($test).'"[^>]*>#', $html, $tags);

    return array_values(array_filter(array_map(fn (string $tag): ?string => preg_match('#\s'.preg_quote($attribute).'="([^"]+)"#', $tag, $m) === 1 ? $m[1] : null, $tags[0])));
}

test('the registry has the modes this guard walks: rapid first, daily last, every live mode on a clock', function () {
    expect(ChessModes::all())->toBe(['rapid', 'blitz', 'correspondence'])
        ->and(ChessModes::live())->toBe(['rapid', 'blitz'])
        ->and(array_map(ChessModes::clock(...), ChessModes::live()))->toBe(['10+5', '5+3'])
        ->and(array_map(ChessModes::label(...), ChessModes::all()))->toBe(['Rapid 10+5', 'Blitz 5+3', 'Daily']);
});

test('profile chips: one chip per chess mode the player has a result in, the default mode always', function () {
    $player = User::factory()->create();

    foreach (ChessModes::all() as $mode) {
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => $mode, 'subject' => 'user:'.$player->id, 'user_id' => $player->id,
            'rating' => 1016, 'results' => 1, 'wins' => 1, 'draws' => 0, 'losses' => 0]);
    }

    $html = $this->get(route('players.show', $player->npub))->assertOk()->getContent();

    expect(surfaceModes($html, 'header-rating', 'data-chess-mode'))->toBe(ChessModes::all())
        ->and(array_column(Ratings::chipsFor($player), 'label'))->toContain(...array_map(fn (string $mode): string => BadgeCopy::ladder('chess', $mode), ChessModes::all()))
        // Without results only the default mode's chip.
        ->and(array_column(Ratings::chipsFor(User::factory()->create()), 'mode'))->toBe([ChessModes::DEFAULT]);
});

test('home counts every live mode as live, and /games lists and filters every live mode by name', function () {
    foreach (ChessModes::live() as $mode) {
        surfaceGame($mode);
    }
    surfaceGame(ChessGame::CORRESPONDENCE);
    $live = count(ChessModes::live());

    $home = $this->get(route('home'))->assertOk()->getContent();
    expect($home)->toContain(trans_choice(':count live board|:count live boards', $live).', '.trans_choice(':count daily game|:count daily games', 1));

    $games = $this->get(route('games.index'))->assertOk()->getContent();
    expect(surfaceModes($games, 'live-game'))->toEqualCanonicalizing(ChessModes::live());

    foreach (ChessModes::live() as $mode) {
        expect($games)->toContain('data-test="live-mode-'.$mode.'"');
        $filtered = $this->get(route('games.index', ['mode' => $mode]))->assertOk()->getContent();
        expect(surfaceModes($filtered, 'live-game'))->toBe([$mode])
            ->and($filtered)->toContain('data-test="live-game-mode">'.ChessModes::label($mode).'<');
    }
});

test('/matches names each game by its own mode, never blitz for a rapid game', function () {
    foreach (ChessModes::all() as $mode) {
        surfaceGame($mode, ['status' => ChessGameStatus::Finished, 'result' => '1-0', 'end_reason' => ChessEndReason::Resignation, 'ended_at' => now()]);
    }

    $html = $this->get(route('matches.index'))->assertOk()->getContent();
    expect(surfaceModes($html, 'chess-row'))->toEqualCanonicalizing(ChessModes::all());

    preg_match('#data-test="chess-row" data-mode="rapid".*?</a>#s', $html, $rapid);
    expect($rapid[0] ?? '')->toContain(ChessModes::short('rapid').' 10+5')->not->toContain('Blitz');
});

test('the dock and the notifications name the game\'s and the invite\'s mode', function () {
    foreach (ChessModes::live() as $mode) {
        $game = surfaceGame($mode);
        $item = app(OpenMatches::class)->for($game->white)->firstWhere('key', 'game-'.$game->id);
        expect($item?->title)->toBe(ChessModes::short($mode))
            ->and($item?->sentence)->toStartWith(ChessModes::short($mode).' ');

        $invite = ChessInvite::query()->create(['inviter_id' => User::factory()->create()->id, 'invitee_id' => User::factory()->create()->id, 'mode' => $mode,
            'status' => ChessInviteStatus::Pending, 'expires_at' => now()->addMinutes(2)]);
        $dock = app(OpenMatches::class)->for($invite->invitee)->firstWhere('key', 'invite-'.$invite->id);
        expect($dock?->title)->toBe(__(':mode invite', ['mode' => ChessModes::short($mode)]));

        app(ChessNotifications::class)->inviteReceived($invite);
        expect($invite->invitee->notifications()->sole()->data['body'])->toContain(ChessModes::label($mode));

        $game->forceFill(['status' => ChessGameStatus::Finished, 'result' => null, 'end_reason' => ChessEndReason::Aborted, 'ended_at' => now()])->save();
        app(ChessNotifications::class)->gameOver($game);
        expect($game->white->notifications()->latest('created_at')->first()->data['title'])->toStartWith(ChessModes::short($mode).' ')
            ->when($mode !== 'blitz', fn ($title) => $title->not->toContain('Blitz'));
    }
});

test('navigation opens the default ladder; the ladder page, the rules and the sitemap list every mode', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('clans.index'))->assertOk()->getContent();
    expect($html)->toContain('href="'.route('ladder.show', ['chess', ChessModes::DEFAULT]).'"');

    $ladder = $this->get(route('ladder.show', ['chess', ChessModes::DEFAULT]))->assertOk()->getContent();
    foreach (ChessModes::all() as $mode) {
        expect($ladder)->toContain('href="'.route('ladder.show', ['chess', $mode]).'"');
    }

    $rules = collect(RulesPage::sections())->keyBy('id');
    $facts = array_column($rules['tournaments']['facts'], 0);
    foreach (ChessModes::live() as $mode) {
        expect($rules['chess']['lead'])->toContain(ChessModes::label($mode))
            ->and($facts)->toContain(__('Chess first move, :mode', ['mode' => ChessModes::label($mode)]));
    }

    $sitemap = array_column(app(Sitemap::class)->entries('pages', 1) ?? [], 'url');
    foreach (ChessModes::all() as $mode) {
        expect($sitemap)->toContain(route('ladder.show', ['chess', $mode]));
    }
});

test('OG card, share post, structured data, PGN and the record name the mode', function () {
    foreach (ChessModes::live() as $mode) {
        $game = surfaceGame($mode, ['status' => ChessGameStatus::Finished, 'result' => '1-0', 'end_reason' => ChessEndReason::Resignation, 'ended_at' => now(), 'ply' => 2]);

        expect(PageCardFacts::game($game)['mode'])->toBe($mode)
            ->and(ChessPgn::headersFor($game)['Event'])->toBe('TWENTY ONE esports, casual '.$mode)
            ->and(app(SharePosts::class)->post($game->white, 'game', (string) $game->id)->sentence)->toContain(ChessModes::label($mode));

        // The page's description and JSON-LD name the mode; a rapid game's page never says blitz.
        $html = $this->get(route('games.show', $game))->assertOk()->getContent();
        expect($html)->toContain(e(__('Casual · :mode', ['mode' => ChessModes::label($mode)])))
            ->toContain(' · '.ChessModes::label($mode).'","url"')
            // The menu lists every mode; the game's own lines (description, kind, time control) name only its own.
            ->when($mode !== 'blitz', fn ($page) => $page->not->toContain('Casual · Blitz 5+3')->not->toContain('Blitz 5+3 · 5 min'));
    }
});

test('mining labels and the stream know every mode', function () {
    // Each mode has its own label, none falls back to its key.
    foreach (ChessModes::all() as $mode) {
        expect(ChainOverview::keyLabel('chess/'.$mode))->not->toBe('chess/'.$mode)->toBe(BadgeCopy::ladder('chess', $mode));
    }
    expect(array_unique(array_map(fn (string $mode): string => ChainOverview::keyLabel('chess/'.$mode), ChessModes::all())))->toHaveCount(count(ChessModes::all()))
        ->and(array_keys(StreamStats::LADDERS))->toContain('rapid', 'blitz', 'daily');

    foreach (ChessModes::live() as $mode) {
        expect(StreamTexts::for(surfaceGame($mode))['title'])->toEndWith('Chess '.ucfirst($mode));
    }
});

test('the lobby has a tile per live mode, the default first, and its ladder card switches through every mode', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('chess.lobby'))->assertOk()->getContent();

    $positions = array_map(fn (string $mode): int|false => strpos($html, 'data-test="play-'.$mode.'"'), ChessModes::live());
    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(collect($positions)->sort()->values()->all())
        ->and(surfaceModes($html, 'play-'.ChessModes::DEFAULT))->toBe([ChessModes::DEFAULT]);

    foreach (ChessModes::all() as $mode) {
        expect($html)->toContain('data-test="lobby-ladder-mode-'.$mode.'"');
    }
});

/*
 * Every Livewire page this plan touched answers a roundtrip (a 500 on an XHR
 * shows in no console): `$refresh` once, with a rapid game in play.
 */
test('every touched Livewire page answers a roundtrip with a rapid game in play', function () {
    $player = User::factory()->create();
    $game = surfaceGame('rapid', ['white_id' => $player->id]);
    $tournament = Tournament::factory()->signup()->create(['mode' => 'rapid']);
    $rapidInvite = app(InviteLinks::class)->create(User::factory()->create(), InviteLinkType::Rapid);

    $pages = [
        ['pages::chess.lobby', []],
        ['pages::chess.challenge', []],
        ['pages::games.index', []],
        ['pages::games.index', ['mode' => 'rapid']],
        ['pages::games.show', ['game' => $game]],
        ['pages::ladder.show', ['game' => 'chess', 'mode' => 'rapid']],
        ['pages::invites.create', ['game' => 'chess']],
        ['pages::invites.link', ['link' => $rapidInvite]],
        ['pages::me.hub', []],
        ['pages::mining', []],
        ['pages::live', []],
        ['pages::tournaments.show', ['tournament' => $tournament]],
        ['follows-here', ['context' => 'chess']],
    ];

    foreach ($pages as [$name, $params]) {
        Livewire::actingAs($player)->test($name, $params)->assertOk()->call('$refresh')->assertOk();
    }

    Livewire::actingAs(organizer())->test('pages::admin.tournament-create')->set('game', 'rapid')->assertOk()->call('$refresh')->assertOk();
});
