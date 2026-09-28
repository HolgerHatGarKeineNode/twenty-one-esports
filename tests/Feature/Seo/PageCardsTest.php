<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\InviteLinkType;
use App\Enums\PayoutStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Cards\Canvas;
use App\Support\Cards\PageCard;
use App\Support\Cards\PageCardFacts;
use App\Support\GameNames;
use App\Support\Invites\InviteLinks;
use App\Support\PageMeta;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Link previews of every public page (P54)
|--------------------------------------------------------------------------
|
| Each public page names its own 1200 × 630 PNG (App\Support\Cards\PageCard)
| in og:image, with alt text and the large Twitter card; the picture follows
| the page's state (a game that ends, a pot that changes) through the `v` of
| its URL, is drawn once per state, never shows private data, and never
| answers with a 500.
|
*/

beforeEach(function () {
    Storage::fake('local');
    // Publishing a tournament signs with the league key.
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/**
 * Every public view with a fixture: name => fn () => its URL.
 *
 * @return array<string, Closure(): string>
 */
function cardPages(): array
{
    $pages = [
        'home' => fn () => route('home'),
        'play' => fn () => route('play'),
        'clans' => fn () => route('clans.index'),
        'clan' => fn () => route('clans.show', Clan::factory()->create(['name' => 'Hodl Squad'])),
        'matches' => fn () => route('matches.index'),
        'series' => fn () => route('matches.show', SeriesMatch::factory()->accepted()->create()->number),
        'chess lobby' => fn () => route('chess.lobby'),
        'live games' => fn () => route('games.index'),
        'finished chess game' => fn () => route('games.show', ChessGame::factory()->finished('0-1', ChessEndReason::Checkmate)->create(['number' => 24])),
        'running chess game' => fn () => route('games.show', ChessGame::factory()->create(['number' => 25])),
        'live stream' => fn () => route('live'),
        'season' => fn () => route('mining'),
        'chess ladder' => fn () => route('ladder.show', ['chess', 'blitz']),
        'rocket league ladder' => fn () => route('ladder.show', ['rocket-league', '2v2']),
        'strongest' => fn () => route('ladder.strongest'),
        'player' => fn () => route('players.show', User::factory()->create(['name' => 'satoshi'])->npub),
        'tournaments' => fn () => route('tournaments.index'),
        'tournament' => fn () => route('tournaments.show', openTournament(['name' => 'Halving Cup'])),
        'finished tournament' => fn () => route('tournaments.show', finishedCup()),
        'tournament draw' => fn () => route('tournaments.draw', openTournament()),
        'tournament tv' => fn () => route('tournaments.tv', openTournament()),
        'rules' => fn () => route('rules'),
        'open protocol' => fn () => route('protocol'),
        'login' => fn () => route('login'),
        'invite link' => fn () => app(InviteLinks::class)->create(User::factory()->create(), InviteLinkType::Blitz)->url(),
    ];

    // The series games' hubs; the dataset is read before the app boots, so the registry's slugs are listed here
    // and the test below checks that every registered series game has its own card.
    foreach (['rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26'] as $game) {
        $pages['hub '.$game] = fn () => GameNames::page($game);
    }

    return $pages;
}

/** A published single-elimination chess cup, played to the end. */
function finishedCup(): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $tournament->forceFill(['published_at' => now()])->save();
    playOutAsDirector($tournament);

    return $tournament->refresh();
}

/**
 * The og:image of a page and the tags around it.
 *
 * @return array{url: string, path: string, alt: string, width: string, height: string, twitter: string}
 */
function previewOf(string $html): array
{
    $tag = fn (string $pattern): string => preg_match($pattern, $html, $match) === 1 ? html_entity_decode($match[1]) : '';
    $url = $tag('#<meta property="og:image" content="([^"]+)">#');
    $parts = parse_url($url);

    return [
        'url' => $url,
        'path' => ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : ''),
        'alt' => $tag('#<meta property="og:image:alt" content="([^"]*)">#'),
        'width' => $tag('#<meta property="og:image:width" content="([^"]*)">#'),
        'height' => $tag('#<meta property="og:image:height" content="([^"]*)">#'),
        'twitter' => $tag('#<meta name="twitter:card" content="([^"]*)">#'),
    ];
}

test('every public page names a 1200 × 630 PNG with alt text in its preview, in English and German', function (Closure $page) {
    $url = $page();

    foreach (['en', 'de'] as $locale) {
        $html = $this->get($url.(str_contains($url, '?') ? '&' : '?').'lang='.$locale)->assertOk()->getContent();
        $preview = previewOf($html);

        expect($preview['url'])->not->toBe('')
            ->and($preview['width'].'x'.$preview['height'])->toBe('1200x630')
            ->and($preview['twitter'])->toBe('summary_large_image')
            ->and(mb_strlen($preview['alt']))->toBeGreaterThan(5)
            ->and($html)->toContain('<meta property="og:title" content="')
            ->toContain('<meta property="og:description" content="')
            ->toContain('<meta name="twitter:image:alt" content="');

        // The static brand card is a file under public/, the others are drawn by the app.
        $png = str_starts_with($preview['path'], '/images/og/')
            ? (string) file_get_contents(public_path(parse_url($preview['url'], PHP_URL_PATH)))
            : $this->get($preview['path'])->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
        $size = getimagesizefromstring($png);

        expect([$size[0] ?? null, $size[1] ?? null, $size['mime'] ?? null])->toBe([1200, 630, 'image/png']);
    }
})->with(cardPages());

test('every registered series game has a hub card', function () {
    foreach (array_keys(app(GameRegistry::class)->series()) as $game) {
        expect(previewOf($this->get(GameNames::page($game))->getContent())['path'])->toStartWith('/cards/en/page/page/hub.'.$game.'.png?v=');
    }
});

test('every public page but the invite link and login has a card of its own, not the brand card', function () {
    foreach (cardPages() as $name => $page) {
        $path = previewOf($this->get($page())->getContent())['path'];

        if (in_array($name, ['invite link', 'login'], true)) {
            continue;
        }

        expect($path)->toStartWith('/cards/en/page/', $name);
    }
});

test('a chess game\'s preview changes when it ends: live board first, the final position and result after', function () {
    $game = ChessGame::factory()->create(['number' => 24, 'fen' => 'rnbqkbnr/pppp1ppp/8/4p3/6P1/5P2/PPPPP2P/RNBQKBNR b KQkq - 0 2', 'ply' => 3]);
    $running = previewOf($this->get(route('games.show', $game))->getContent());
    $runningPng = $this->get($running['path'])->assertOk()->getContent();

    expect(PageCard::game($game)->facts)->toMatchArray(['status' => 'active', 'result' => null]);

    $game->forceFill(['status' => ChessGameStatus::Finished, 'result' => '0-1', 'end_reason' => ChessEndReason::Checkmate,
        'fen' => 'rnb1kbnr/pppp1ppp/8/4p3/6Pq/5P2/PPPPP2P/RNBQKBNR w KQkq - 1 3', 'ply' => 4, 'ended_at' => now()])->save();
    $finished = previewOf($this->get(route('games.show', $game))->getContent());
    $finishedPng = $this->get($finished['path'])->assertOk()->getContent();

    expect($finished['url'])->not->toBe($running['url'])
        ->and($finished['alt'])->toContain('won')
        ->and(md5($finishedPng))->not->toBe(md5($runningPng))
        // One file per card and language: the running state is gone.
        ->and(Storage::disk('local')->files('page-cards/game/'.$game->id))->toHaveCount(1);
});

test('a tournament\'s preview changes with its pot and a payout, and shows the pot as set, never the wallet balance', function () {
    $tournament = openTournament(['name' => 'Halving Cup']);
    $tournament->forceFill(['pool_opened_at' => now(), 'pot_source' => Tournament::POT_WALLET, 'prize_target_sats' => 21_000,
        'pot_balance_sats' => 987_654, 'pot_balance_at' => now()])->save();
    $before = PageCard::tournament($tournament->refresh());

    expect($before->facts)->toMatchArray(['pot' => 21_000, 'left' => 21_000])
        ->and(json_encode($before->facts))->not->toContain('987654');

    $tournament->forceFill(['prize_target_sats' => 50_000])->save();
    $raised = PageCard::tournament($tournament->refresh());
    TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'user_id' => null, 'participant_id' => null, 'pubkey' => str_repeat('a', 64),
        'name' => 'satsjaeger', 'place' => 1, 'amount_sats' => 8_000, 'idempotency_key' => 'p54-test', 'status' => PayoutStatus::Paid]);
    $paid = PageCard::tournament($tournament->refresh());

    expect($raised->url())->not->toBe($before->url())
        ->and($paid->facts)->toMatchArray(['pot' => 50_000, 'left' => 42_000])
        ->and($paid->url())->not->toBe($raised->url());
});

test('a finished tournament shows its podium with the prizes the league paid', function () {
    $tournament = finishedCup();
    $winner = $tournament->participants()->where('name', 'Player 1')->firstOrFail();
    TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'user_id' => $winner->user_id, 'participant_id' => $winner->id, 'pubkey' => $winner->user->pubkey,
        'name' => $winner->name, 'place' => 1, 'amount_sats' => 21_000, 'idempotency_key' => 'p54-podium', 'status' => PayoutStatus::Paid]);

    $podium = PageCard::tournament($tournament->refresh())->facts['podium'];

    expect($podium)->toHaveCount(3)
        ->and($podium[0])->toMatchArray(['place' => 1, 'name' => 'Player 1', 'paid' => 21_000])
        ->and($podium[1]['paid'])->toBeNull();
});

test('a player card carries the best rank and the latest win', function () {
    $player = User::factory()->create(['name' => 'Mx12art']);
    $opponent = User::factory()->create(['name' => 'Richi']);
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$player->id, 'user_id' => $player->id,
        'rating' => 1532, 'results' => 12, 'wins' => 8, 'draws' => 0, 'losses' => 4]);
    ChessGame::factory()->finished('0-1', ChessEndReason::Checkmate)->create(['white_id' => $opponent->id, 'black_id' => $player->id]);

    $facts = PageCard::player($player)->facts;

    expect($facts['best'])->toMatchArray(['rating' => 1532, 'game' => 'chess', 'mode' => 'blitz', 'place' => 1])
        ->and($facts['record'])->toBe(['wins' => 8, 'draws' => 0, 'losses' => 4])
        ->and($facts['latest_win'])->toMatchArray(['opponent' => 'Richi']);
});

test('a card is drawn once per state: the second request is the cached file', function () {
    $game = ChessGame::factory()->finished()->create();
    $card = PageCard::game($game);
    $path = parse_url($card->url(), PHP_URL_PATH).'?'.parse_url($card->url(), PHP_URL_QUERY);

    $started = microtime(true);
    $this->get($path)->assertOk();
    $miss = (microtime(true) - $started) * 1000;

    // Swap the cached file for a marker: a hit serves the file, a redraw would not.
    [$file] = Storage::disk('local')->files('page-cards/game/'.$game->id);
    Storage::disk('local')->put($file, 'cached-marker');

    $started = microtime(true);
    $hit = $this->get($path)->assertOk()->getContent();
    $hitMs = (microtime(true) - $started) * 1000;

    // A changed `v` is only a cache buster on the way: it draws nothing new.
    expect($hit)->toBe('cached-marker')
        ->and($this->get(parse_url($card->url(), PHP_URL_PATH).'?v=other')->getContent())->toBe('cached-marker')
        ->and($miss)->toBeLessThan(PAGE_CARD_MISS_MS)
        ->and($hitMs)->toBeLessThan(PAGE_CARD_HIT_MS);
});

/** Budgets with headroom: measured 2026-09-28 a draw took 170–370 ms, a cached answer a few ms plus the facts' queries. */
const PAGE_CARD_MISS_MS = 2000;

const PAGE_CARD_HIT_MS = 400;

test('the cards read no private data: neither in their source nor in what they draw', function () {
    $player = User::factory()->create(['name' => 'satoshi', 'lud16' => 'secret-p54@getalby.com', 'gamer_tags' => ['epic' => 'SecretTagP54'], 'looking_to_play' => true]);
    $game = ChessGame::factory()->finished()->create(['white_id' => $player->id]);
    $clan = Clan::factory()->create(['owner_id' => $player->id, 'owner_pubkey' => $player->pubkey]);

    foreach ([PageCard::player($player), PageCard::game($game), PageCard::clan($clan)] as $card) {
        expect(json_encode($card->facts))->not->toContain('secret-p54')->not->toContain('SecretTagP54')->not->toContain('looking');
    }

    foreach (['app/Support/Cards/PageCard.php', 'app/Support/Cards/PageCardFacts.php'] as $file) {
        $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path($file)));

        foreach (['lud16', 'gamer_tags', 'looking_to_play', 'pot_nwc_uri', 'pot_balance', 'potSats', 'lobby_name', 'lobby_password', 'message', 'chat'] as $private) {
            expect(stripos($code, $private))->toBeFalse("{$file} reads {$private}");
        }
    }
});

test('the card route is rate-limited like the share cards, and unknown or private pages are a 404', function () {
    expect(Route::getRoutes()->getByName('cards.page')->gatherMiddleware())->toContain('throttle:cards');

    $draft = Tournament::factory()->create();

    foreach ([['game', '999999'], ['tournament', (string) $draft->id], ['player', 'npub1nobody'], ['clan', 'no-such-clan'], ['series', '424242'], ['ladder', 'chess.nope'], ['page', 'admin']] as [$type, $key]) {
        $this->get(route('cards.page', ['locale' => 'en', 'type' => $type, 'key' => $key], false))->assertNotFound();
    }

    $this->get('/cards/en/page/game/..%2F..%2Fsecret.png')->assertNotFound();
    $this->get('/cards/en/page/wallet/1.png')->assertNotFound();
});

test('a card that cannot be read is the brand card with a 200, and a page whose card fails keeps the brand card', function () {
    $game = ChessGame::factory()->finished()->create();
    Schema::drop('chess_moves');

    $png = $this->get(route('cards.page', ['locale' => 'en', 'type' => 'game', 'key' => $game->id], false))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
    $size = getimagesizefromstring($png);

    expect([$size[0] ?? null, $size[1] ?? null])->toBe([1200, 630]);

    $meta = (new PageMeta)->card(fn () => throw new RuntimeException('facts failed'));

    expect($meta->images)->toBe([PageMeta::brandImage()]);
});

test('the counts of a fixed page are cached for a minute; entity cards read fresh', function () {
    expect(PageCardFacts::COUNTS_TTL)->toBe(60);

    $before = PageCard::page('clans')->facts['figures'][0][1];
    Clan::factory()->create();

    expect(PageCard::page('clans')->facts['figures'][0][1])->toBe($before);
});

test('no text on any card is cut or runs off the edge, in English or German, with long but realistic names and amounts', function () {
    $season = openSeason();
    [$satoshi, $hodler, $hal] = [
        User::factory()->create(['name' => 'Satoshi Nakamoto']),
        User::factory()->create(['name' => 'HalvingHodler21']),
        User::factory()->create(['name' => 'Lightning Larry']),
    ];

    foreach ([[$satoshi, 2412], [$hodler, 2388], [$hal, 2350]] as [$user, $rating]) {
        foreach ([Rating::RATED => $season->slug, Rating::CASUAL => ''] as $pool => $slug) {
            Rating::query()->create(['pool' => $pool, 'season' => $slug, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id, 'user_id' => $user->id,
                'rating' => $rating, 'results' => 188, 'wins' => 120, 'draws' => 18, 'losses' => 50]);
        }
    }

    // Games: every way to end (the longest reasons among them), in a tournament, a daily one running, a rating change.
    $cup = finishedCup();
    $cup->forceFill(['name' => 'Genesis Blitz Cup Autumn'])->save();
    $games = [];

    foreach (ChessEndReason::cases() as $index => $reason) {
        $games[] = ChessGame::factory()->rated()->finished(['1-0', '0-1', '1/2-1/2'][$index % 3], $reason)->create(['white_id' => $satoshi->id, 'black_id' => $hodler->id, 'ply' => 131,
            'tournament_match_id' => $cup->matches()->value('id')]);
    }
    $games[] = ChessGame::factory()->daily()->create(['white_id' => $hodler->id, 'black_id' => $satoshi->id, 'ply' => 97, 'fen' => 'r1bqkbnr/pppp1ppp/2n5/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R b KQkq - 2 49']);
    $newcomer = User::factory()->create(['name' => 'Satoshi Nakamoto']);
    $changed = ChessGame::factory()->finished('0-1', ChessEndReason::Checkmate)->create(['white_id' => $hodler->id, 'black_id' => $newcomer->id]);
    RatingChange::query()->create(['rating_id' => Rating::query()->where(['user_id' => $hodler->id, 'pool' => Rating::CASUAL])->value('id'), 'source' => RatingChange::CHESS, 'source_id' => $changed->id,
        'score' => 0, 'before' => 2388, 'after' => 2371, 'delta' => -17, 'results_before' => 187, 'revision' => 1]);
    $games[] = $changed;

    // Tournaments: a big pot with a first payout, a finished cup paying a million per place, a casual cup.
    $open = openTournament(['name' => 'Genesis Blitz Cup Autumn', 'capacity' => 64]);
    $open->forceFill(['pool_opened_at' => now(), 'pot_source' => Tournament::POT_WALLET, 'prize_target_sats' => 1_000_000])->save();
    TournamentPayout::query()->create(['tournament_id' => $open->id, 'user_id' => null, 'participant_id' => null, 'pubkey' => str_repeat('b', 64),
        'name' => 'x', 'place' => 1, 'amount_sats' => 1_000, 'idempotency_key' => 'p54-long-open', 'status' => PayoutStatus::Paid]);

    foreach ($cup->participants()->orderBy('id')->get() as $index => $entry) {
        $entry->forceFill(['name' => ['Satoshi Nakamoto', 'HalvingHodler21', 'Lightning Larry', 'Hal Finney'][$index] ?? $entry->name])->save();
        TournamentPayout::query()->create(['tournament_id' => $cup->id, 'user_id' => $entry->user_id, 'participant_id' => $entry->id, 'pubkey' => $entry->user?->pubkey ?? str_repeat('c', 64),
            'name' => $entry->name, 'place' => $index + 1, 'amount_sats' => 1_000_000, 'idempotency_key' => 'p54-long-'.$index, 'status' => PayoutStatus::Paid]);
    }
    $casual = openTournament(['name' => 'Casual Chess Cup US', 'cup_series' => 'chess-us']);

    // A clan and a decided series with long names.
    $lions = Clan::factory()->create(['name' => 'Lightning Network Lions', 'clantag' => 'WWWW', 'owner_id' => $satoshi->id, 'owner_pubkey' => $satoshi->pubkey]);
    $series = SeriesMatch::factory()->accepted()->create(['challenger_name' => 'Lightning Network Lions', 'challenged_name' => 'Orange Pill Academy']);
    $series->forceFill(['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'result_games' => [['winner' => 'challenger'], ['winner' => 'challenger']]])->save();

    $cards = [
        ...array_map(fn (ChessGame $game): Closure => fn () => PageCard::game($game->refresh()), $games),
        fn () => PageCard::tournament($open->refresh()),
        fn () => PageCard::tournament($cup->refresh()),
        fn () => PageCard::tournament($casual->refresh()),
        fn () => PageCard::player($satoshi->refresh()),
        fn () => PageCard::player($newcomer->refresh()),
        fn () => PageCard::clan($lions->refresh()),
        fn () => PageCard::series($series->refresh()),
        fn () => PageCard::ladder('chess', 'blitz'),
        fn () => PageCard::ladder('rocket-league', '2v2'),
        ...array_map(fn (string $page): Closure => fn () => PageCard::page($page), [...PageCard::PAGES, 'hub.rocket-league', 'hub.ea-sports-fc-27', 'hub.ea-sports-fc-26']),
    ];
    $cuts = [];

    foreach (['en', 'de'] as $locale) {
        app()->setLocale($locale);

        foreach ($cards as $card) {
            Canvas::$cuts = [];
            $built = $card();
            $built->render();

            foreach (Canvas::$cuts as $cut) {
                $cuts[] = "{$locale} {$built->type} {$built->key}: {$cut}";
            }
        }
    }

    Canvas::$cuts = null;

    expect($cuts)->toBe([]);
});
