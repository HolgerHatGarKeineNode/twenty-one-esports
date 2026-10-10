<?php

use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\RankBadge;
use App\Models\RankBadgeVersion;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\StreamBot\StreamBotBuilders;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\StreamBot\StreamBotMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Support\BlockfillOn;
use Tests\Support\HyperOn;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\PongOn;

/*
 * The stream chat bot's builders (P22): each one offers messages only when
 * its facts are in the database, and every message keeps the copy rules
 * (no `#`, a direct link, 1-4 lines). A player named for an achievement is
 * tagged as the bot's notes tag them: `nostr:npub1…` and a `p` tag, the plain
 * name without a valid Nostr key.
 */

beforeEach(function () {
    config(['twentyone.profile.lud16' => null]);
    URL::forceRootUrl('https://esports.test');
    URL::forceScheme('https');
    // 2026-09-26 18:00 UTC is 20:00 in Berlin (CEST).
    $this->travelTo(Carbon::parse('2026-09-26 18:00:00', 'UTC'));
    $this->builders = app(StreamBotBuilders::class);
    $this->builders->pickVariantsWith(fn (int $variants): int => 0);
});

/**
 * @return list<StreamBotMessage>
 */
function botBuild(string $builder): array
{
    return test()->builders->build($builder, CarbonImmutable::now());
}

/** How a chat message names a player with a Nostr key: their `nostr:npub1…`. */
function botNpub(User $user): string
{
    return 'nostr:'.NostrKeys::hexToNpub($user->pubkey);
}

/** Data for every fact builder at once. */
function botFacts(): void
{
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    Tournament::factory()->signup()->create(['name' => 'Friday Blitz Cup #3', 'signup_closes_at' => now()->addDays(2), 'starts_at' => Carbon::parse('2026-09-28 18:00:00', 'UTC'),
        'pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 21_000, 'pot_balance_at' => now()]);
    Tournament::factory()->signup()->create(['name' => 'Midnight Arena', 'signup_closes_at' => now()->addMinutes(95), 'starts_at' => now()->addHours(2)]);
    Tournament::factory()->create(['name' => 'RL Sunday', 'status' => TournamentStatus::Running]);
    shareTournament($alice, $bob);
    ChessGame::factory()->create(['white_id' => $alice->id, 'black_id' => $bob->id]);
    ChessGame::factory()->create();
    SeriesMatch::factory()->create(['status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(20), 'challenger_name' => 'Laser Eyes', 'challenged_name' => 'HODL Rockets']);
    Clan::factory()->create(['name' => 'Stacking Sats', 'clantag' => 'SATS']);
    botRating($alice, 1612, 9);
    botRankUp($alice, 'gold-2', 'silver-1');
    planBlock0(now()->addDays(3)->addHours(4)->toIso8601String());
    config(['twentyone.profile.lud16' => 'bot@example.com']);
}

function botRating(User $user, int $rating, int $results): Rating
{
    return Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id,
        'user_id' => $user->id, 'rating' => $rating, 'results' => $results, 'wins' => $results, 'draws' => 0, 'losses' => 0]);
}

function botRankUp(User $user, string $tier, ?string $previous): RankBadgeVersion
{
    $badge = RankBadge::query()->create(['user_id' => $user->id, 'pubkey' => $user->pubkey, 'game' => 'chess', 'mode' => 'blitz',
        'd' => 'rank/chess-blitz/'.$user->pubkey, 'badge_pubkey' => str_repeat('b', 64), 'tier' => $tier, 'season' => '']);

    return RankBadgeVersion::query()->create(['rank_badge_id' => $badge->id, 'tier' => $tier, 'previous_tier' => $previous, 'season' => '', 'rating' => 1612, 'signed_at' => time()]);
}

test('every fact builder stays silent without its facts', function (string $builder) {
    expect(botBuild($builder))->toBe([]);
})->with([
    'tournament_signup', 'tournament_last_call', 'tournament_live', 'live_game', 'live_games', 'live_series',
    'tournament_winner', 'rank_up', 'new_clan', 'ladder_top', 'season', 'stats', 'tmnf_week', 'tmnf_top', 'tmnf_podium',
]);

test('the feature tips that depend on something stay silent without it', function () {
    config(['twentyone.profile.lud16' => '']);

    expect(botBuild('zap'))->toBe([])
        // No clan yet: the clan count line is left out, the tip stays.
        ->and(botBuild('clans')[0]->content)->not->toContain('clans are already in');
});

test('no builder ever writes a hashtag, and every message has a link and 1 to 4 lines', function (int $variant) {
    botFacts();
    $this->builders->pickVariantsWith(fn (int $variants): int => $variant % $variants);
    $messages = [];

    // TMNF's builders need a running and a finished week: TmnfStreamChatTest holds them to the same rules.
    foreach (array_diff(array_keys($this->builders->all()), ['tmnf_week', 'tmnf_top', 'tmnf_podium']) as $builder) {
        $built = botBuild($builder);
        expect($built)->not->toBe([], "builder {$builder} made no message with data");
        $messages = [...$messages, ...$built];
    }

    foreach ($messages as $message) {
        $lines = explode("\n", $message->content);

        expect($message->content)->not->toContain('#')
            ->and(StreamBotCopy::violations($message->content))->toBe([], $message->builder.': '.$message->content)
            ->and(count($lines))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(4)
            // The link ends its line: a client would take trailing characters into the URL.
            ->and($message->content)->toMatch('~https://esports\.test\S*(\n|$)~');
    }
})->with([0, 1]);

test('the copy guard refuses a hashtag, a t tag, a missing link and a fifth line', function () {
    expect(StreamBotCopy::violations("🏆 Join now #bitcoin\n👉 https://esports.test"))->toContain('contains # (hashtag)')
        ->and(StreamBotCopy::violations('👉 https://esports.test', [['t', 'bitcoin']]))->toContain('t tag')
        ->and(StreamBotCopy::violations('🏆 Join now'))->toContain('no link')
        ->and(StreamBotCopy::violations("a\nb\nc\nd\nhttps://esports.test"))->toContain('more than 4 lines')
        ->and(StreamBotCopy::violations("🏆 Join now\n👉 https://esports.test"))->toBe([]);
});

test('names from the database lose #, links and line breaks', function () {
    expect(StreamBotCopy::clean("Cup #3\nhttps://evil.example/x nostr:npub1abc www.spam.example"))->toBe('Cup 3')
        ->and(StreamBotCopy::clean(str_repeat('a', 50), 10))->toBe('aaaaaaaaa…')
        ->and(StreamBotCopy::clean('###'))->toBe('');
});

test('an open tournament: name without hashtag, Berlin start, spots, a real pot and the link', function () {
    botFacts();

    $messages = botBuild('tournament_signup');

    // Midnight Arena closes within three hours: its last call speaks for it.
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->factKey)->toStartWith('tournament-signup:')
        ->and($messages[0]->content)->toBe(implode("\n", [
            '🏆 Sign-up is open: Friday Blitz Cup 3',
            '🎮 Chess Blitz 5+3 · starts Mon 28 Sep, 20:00 CEST',
            '🪑 0 of 12 spots taken · 💰 21,000 sats in the pot',
            '👉 Grab your spot: https://esports.test/tournaments/'.Tournament::query()->where('name', 'Friday Blitz Cup #3')->value('id'),
        ]));
});

test('a stale or empty pot is not mentioned', function () {
    Tournament::factory()->signup()->create(['signup_closes_at' => now()->addDay(), 'pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 21_000, 'pot_balance_at' => now()->subDays(2)]);
    Tournament::factory()->signup()->create(['signup_closes_at' => now()->addDays(2), 'pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 0, 'pot_balance_at' => now()]);

    foreach (botBuild('tournament_signup') as $message) {
        expect($message->content)->not->toContain('sats');
    }
});

test('the last call names the time left and the free spots', function () {
    botFacts();

    $message = botBuild('tournament_last_call')[0];

    expect($message->content)->toContain('Last call for Midnight Arena')
        ->toContain('closes in 1 h 35 min, 12 spots still free')
        ->toEndWith('/signup');
});

test('happening now: a running tournament on the TV view, live boards and a series under way', function () {
    botFacts();

    expect(botBuild('tournament_live')[0]->content)->toContain('RL Sunday is running right now')->toEndWith('/tv')
        ->and(botBuild('live_game'))->toHaveCount(2)
        ->and(collect(botBuild('live_game'))->pluck('content')->implode("\n"))->toContain('Live on the board: '.botNpub(User::query()->where('name', 'Alice')->sole()).' vs '.botNpub(User::query()->where('name', 'Bob')->sole()))
        ->and(botBuild('live_games')[0]->content)->toStartWith('🔴 2 games are live right now')->toEndWith('https://esports.test/games')
        ->and(botBuild('live_series')[0]->content)->toContain('Laser Eyes vs HODL Rockets');
});

test('a daily game is not a live board, and one live game is not a list', function () {
    ChessGame::factory()->daily()->create();
    ChessGame::factory()->create();

    expect(botBuild('live_game'))->toHaveCount(1)
        ->and(botBuild('live_games'))->toBe([]);
});

test('pride: the latest winner, a rank up, a new clan and the top of the ladder, the player tagged', function () {
    botFacts();
    $alice = User::query()->where('name', 'Alice')->sole();

    expect(botBuild('tournament_winner')[0]->content)->toStartWith('🥇 '.botNpub($alice).' won Testnet Cup')
        ->and(botBuild('rank_up')[0]->content)->toStartWith('📈 Rank up! '.botNpub($alice).' reached Gold II in Chess Blitz 5+3')
        ->and(botBuild('new_clan')[0]->content)->toStartWith('🛡️ New clan: Stacking Sats [SATS]')
        ->and(botBuild('ladder_top')[0]->content)->toContain('🥇 '.botNpub($alice).' 1612');

    foreach (['tournament_winner', 'rank_up', 'ladder_top'] as $builder) {
        $message = botBuild($builder)[0];

        expect($message->tags)->toBe([['p', $alice->pubkey]], $builder)
            ->and($message->content)->not->toContain('Alice')
            ->and(StreamBotCopy::violations($message->content, $message->tags))->toBe([], $builder);
    }

    expect(botBuild('new_clan')[0]->tags)->toBe([]);
});

test('the ladder top tags each player with a Nostr key once and names one without a valid key plainly', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $cy = User::factory()->create(['name' => 'Cy']);
    botRating($alice, 1612, 9);
    botRating($bob, 1580, 7);
    botRating($cy, 1550, 5);
    // A stand-in for an account without a usable key: the pubkey column cannot be empty.
    DB::table('users')->where('id', $bob->id)->update(['pubkey' => 'not-a-nostr-key']);

    $message = botBuild('ladder_top')[0];

    expect($message->content)->toContain('🥇 '.botNpub($alice).' 1612 · 🥈 Bob 1580 · 🥉 '.botNpub($cy).' 1550')
        ->and($message->tags)->toBe([['p', $alice->pubkey], ['p', $cy->pubkey]])
        ->and(StreamBotCopy::violations($message->content, $message->tags))->toBe([]);
});

test('a tournament winner without an account (a team, a deleted player) keeps the plain name and no p tag', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $tournament = shareTournament($alice, User::factory()->create());
    TournamentParticipant::query()->where(['tournament_id' => $tournament->id, 'user_id' => $alice->id])->update(['user_id' => null]);

    $message = botBuild('tournament_winner')[0];

    expect($message->content)->toStartWith('🥇 Alice won Testnet Cup')
        ->and($message->content)->not->toContain('nostr:')
        ->and($message->tags)->toBe([]);
});

test('old news is no news: an old win, a rank down, an old clan', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $tournament = shareTournament($alice, User::factory()->create());
    $clan = Clan::factory()->create();
    DB::table('tournaments')->where('id', $tournament->id)->update(['updated_at' => now()->subDays(15)]);
    DB::table('clans')->where('id', $clan->id)->update(['created_at' => now()->subDays(8)]);
    botRankUp($alice, 'silver-1', 'gold-2');

    expect(botBuild('tournament_winner'))->toBe([])
        ->and(botBuild('new_clan'))->toBe([])
        ->and(botBuild('rank_up'))->toBe([]);
});

test('the season: the Block 0 countdown before launch, nothing without a date', function () {
    planBlock0(null);
    expect(botBuild('season'))->toBe([]);

    planBlock0(now()->addDays(3)->addHours(4)->toIso8601String());
    expect(botBuild('season')[0]->content)->toStartWith('⛏️ Block 0 in 3 days 4 h')->toEndWith('/mining');
});

test('the feature tips link the exact page', function (string $builder, string $path) {
    botFacts();

    expect(botBuild($builder)[0]->content)->toEndWith('https://esports.test'.$path)
        ->and(botBuild($builder)[0]->factKey)->toBe('feature:'.$builder);
})->with([
    ['play_blitz', '/chess'],
    ['daily_chess', '/chess/challenge'],
    ['clan_challenge', '/challenges/create'],
    ['invite_friend', '/chess'],
    ['clans', '/clans'],
    ['badges', '/ladder/chess/rapid'],
    ['all_games', '/play'],
    ['login', '/login'],
    ['zap', ''],
    ['aoe2', '/games/age-of-empires-2'],
    ['ea_fc', '/games/ea-sports-fc-27'],
]);

test('a switched-on game gets its own chat tip, and a switched-off game stays out of the rotation', function () {
    expect(array_keys($this->builders->all()))->toContain('aoe2', 'ea_fc')
        ->not->toContain('proof_of_pong', 'hyperbitcoinization', 'blockfill_play', 'board_morris');

    PongOn::play();
    HyperOn::play();
    BlockfillOn::play();
    NineMensMorrisOn::play();

    $builders = app(StreamBotBuilders::class);
    $builders->pickVariantsWith(fn (int $variants): int => 0);

    expect(array_keys($builders->all()))->toContain('proof_of_pong', 'hyperbitcoinization', 'blockfill_play', 'board_morris');

    $tips = [
        'proof_of_pong' => route('pong.index'),
        'hyperbitcoinization' => route('hyper.index'),
        'blockfill_play' => route('stacker.play'),
        'board_morris' => route('board.lobby', 'nine-mens-morris'),
    ];

    foreach ($tips as $builder => $url) {
        $message = $builders->build($builder, CarbonImmutable::now())[0];

        expect($message->content)->toEndWith($url)
            ->and($message->factKey)->toBe('feature:'.$builder)
            ->and(StreamBotCopy::violations($message->content, $message->tags))->toBe([], $message->content);
    }
});

test('a live board tags both players, and a player without a key keeps the plain name', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $guest = User::factory()->create(['name' => 'Guest', 'pubkey' => str_repeat('z', 64)]);
    ChessGame::factory()->create(['white_id' => $alice->id, 'black_id' => $guest->id]);

    $message = botBuild('live_game')[0];

    expect($message->content)->toContain('Live on the board: '.botNpub($alice).' vs Guest')
        ->and($message->tags)->toBe([['p', $alice->pubkey]]);
});

test('every wording leads with an emoji, keeps a link and stays inside four lines', function () {
    $values = [
        'name' => 'Cup', 'game' => 'Chess', 'starts' => 'Sat 4 Oct, 20:00 CEST', 'spots' => '3 of 8 spots taken',
        'pot' => '1,000', 'left' => '2 h', 'open' => '4 spots', 'url' => 'https://esports.test/play',
        'white' => 'nostr:npub1white', 'black' => 'nostr:npub1black', 'mode' => 'Blitz', 'count' => '3',
        'home' => 'Home', 'away' => 'Away', 'best_of' => '3', 'boards' => '4', 'winner' => 'nostr:npub1win',
        'player' => 'nostr:npub1player', 'tier' => 'Gold', 'tag' => 'SATS', 'ladder' => 'Rapid', 'podium' => '🥇 Ada 1600',
        'players' => '12', 'clans' => '3', 'games' => 'Chess and Blockfill', 'track' => 'A01', 'server' => 'tm.example',
        'ends' => 'Sun 5 Oct, 22:00 CEST', 'time' => '1:02.3', 'gap' => '', 'days' => '7', 'elo' => '+12',
        'loser' => 'nostr:npub1loser', 'places' => '8', 'free' => '2', 'sponsors' => 'the league', 'first' => '1st',
        'winners' => 'nostr:npub1win', 'tournament' => 'Cup', 'prize' => '1000', 'others' => 'nostr:npub1other',
        'blocks' => '40',
    ];

    foreach (StreamBotCopy::TEMPLATES as $name => $variants) {
        foreach (array_keys($variants) as $index) {
            $text = StreamBotCopy::render($name, $index, $values);

            expect(StreamBotCopy::violations($text))->toBe([], $name.'#'.$index.': '.$text);

            foreach (explode("\n", $text) as $line) {
                expect($line)->toMatch('/^\p{Extended_Pictographic}/u', $name.'#'.$index.': '.$line);
            }
        }
    }
});
