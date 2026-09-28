<?php

use App\Enums\ReportStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\SeriesReport;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Cards\PageCard;
use App\Support\Cards\SharePosts;
use App\Support\Cards\ShareRefused;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestSigner;

/**
 * The player's own posts (P46, NIP "Share posts" rev. 9.7): a won game or
 * series with the page card, the opponents mentioned (NIP-27 `nostr:npub` and
 * `p`) and the league's record quoted (NIP-18 `q`); a tournament win quoting
 * the tournament's 31923; "I'm in" with the invite card, the tournament's page
 * and its 31923. Never a hashtag; only real results of the player.
 */
beforeEach(function () {
    Storage::fake('local');
    config(['esports.relays' => ['wss://league.example'], 'app.url' => 'https://esports.example']);
});

function ownSign(TestSigner $signer, array $template): array
{
    return $signer->sign($template['kind'], $template['tags'], $template['content'], max($template['created_at'], now()->getTimestamp()));
}

/** @return list<string> */
function ownTagNames(array $template): array
{
    return array_column($template['tags'], 0);
}

test('a won rated game: the game card, the opponent mentioned, the league record quoted, no hashtag', function () {
    [$anna, $annaKey] = keyedPlayer();
    $bert = User::factory()->create(['name' => 'bert']);
    $league = new TestSigner;
    $record = NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(64, [['alt', 'Chess game']], "1. f3 e5 2. g4 Qh4# 0-1\n")));
    $game = ChessGame::factory()->rated()->finished('0-1')->create(['white_id' => $bert->id, 'black_id' => $anna->id, 'ply' => 4, 'record_event_id' => $record->id]);

    $posts = app(SharePosts::class);
    $template = $posts->prepare($anna, 'game', (string) $game->id);
    $card = PageCard::game($game->refresh())->url();
    $nevent = NostrKeys::nevent($record->event_id, $league->pubkey, 64);

    expect($template['kind'])->toBe(1)
        ->and($template['content'])->toBe("Won a blitz game against bert on TWENTY ONE Esports.\nGG nostr:{$bert->npub}\n\n{$card}\nhttps://esports.example/games/{$game->id}\n\nnostr:{$nevent}")
        ->and($template['tags'])->toBe([
            ['imeta', 'url '.$card, 'm image/png', 'dim 1200x630', 'alt Won a blitz game against bert on TWENTY ONE Esports.'],
            ['r', 'https://esports.example/games/'.$game->id],
            ['p', $bert->pubkey],
            ['q', $record->event_id, 'wss://league.example', $league->pubkey],
            ['alt', 'Share post: game in TWENTY ONE Esports'],
        ])
        ->and($card)->toStartWith('https://esports.example/cards/en/page/game/'.$game->id.'.png?v=');

    $stored = $posts->submit($anna, 'game', (string) $game->id, ownSign($annaKey, $template));

    expect($stored->pubkey)->toBe($anna->pubkey)
        ->and($stored->queued_at)->not->toBeNull()
        ->and(collect($stored->payload()['tags'])->where(0, 't')->all())->toBe([])
        ->and($stored->payload()['content'])->not->toContain('#');
});

test('a casual win quotes nothing; the loser, a draw, an aborted and a running game have nothing to post', function () {
    [$anna] = keyedPlayer();
    $bert = User::factory()->create(['name' => 'bert']);
    $posts = app(SharePosts::class);
    $casual = ChessGame::factory()->daily()->finished('1-0')->create(['white_id' => $anna->id, 'black_id' => $bert->id, 'ply' => 9]);
    $template = $posts->prepare($anna, 'game', (string) $casual->id);

    expect($template['content'])->toStartWith("Won a daily chess game against bert on TWENTY ONE Esports.\nGG nostr:{$bert->npub}\n\n")
        ->and($template['content'])->not->toContain('nostr:nevent')
        ->and(ownTagNames($template))->toBe(['imeta', 'r', 'p', 'alt']);

    $draw = ChessGame::factory()->finished('1/2-1/2')->create(['white_id' => $anna->id, 'black_id' => $bert->id, 'ply' => 9]);
    $running = ChessGame::factory()->create(['white_id' => $anna->id, 'black_id' => $bert->id, 'ply' => 9]);
    $noMoves = ChessGame::factory()->finished('1-0')->create(['white_id' => $anna->id, 'black_id' => $bert->id, 'ply' => 0]);

    expect(fn () => $posts->prepare($bert, 'game', (string) $casual->id))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare($anna, 'game', (string) $draw->id))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare($anna, 'game', (string) $running->id))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare($anna, 'game', (string) $noMoves->id))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare(User::factory()->create(), 'game', (string) $casual->id))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare($anna, 'game', 'x1'))->toThrow(ShareRefused::class);
});

test('a won series mentions the players of the other side, at most five, and quotes the result report when rated', function () {
    [$anna, $annaKey] = keyedPlayer();
    $mates = User::factory()->count(2)->create();
    $rivals = User::factory()->count(6)->create();
    $reporter = new TestSigner;
    $match = SeriesMatch::factory()->create([
        'status' => SeriesStatus::Confirmed, 'rated' => true, 'winner' => 'challenger', 'finished_at' => now(),
        'result_games' => [['winner' => 'challenger', 'challenger' => 3, 'challenged' => 1], ['winner' => 'challenged', 'challenger' => 0, 'challenged' => 2], ['winner' => 'challenger', 'challenger' => 4, 'challenged' => 2]],
        'rosters' => ['challenger' => [$anna->id, ...$mates->modelKeys()], 'challenged' => $rivals->modelKeys()],
    ]);
    $report = NostrEvent::fromSigned(SignedEvent::fromInput($reporter->sign(2152, [['alt', 'Result report']])));
    SeriesReport::query()->create(['series_match_id' => $match->id, 'side' => 'challenger', 'games' => $match->result_games, 'roster' => [], 'status' => ReportStatus::Confirmed, 'event_id' => $report->id]);

    $posts = app(SharePosts::class);
    $template = $posts->prepare($anna, 'series', (string) $match->number);
    $mentioned = $rivals->sortBy('id')->take(5);

    expect($template['content'])->toStartWith('Won 2–1 against '.$match->challenged_name.' in Rocket League 3v3 on TWENTY ONE Esports.'."\nGG ".$mentioned->map(fn (User $user) => 'nostr:'.$user->npub)->implode(' ')."\n\n")
        ->and($template['content'])->toEndWith("\nhttps://esports.example/matches/{$match->number}\n\nnostr:".NostrKeys::nevent($report->event_id, $reporter->pubkey, 2152))
        ->and(collect($template['tags'])->where(0, 'p')->pluck(1)->all())->toBe($mentioned->pluck('pubkey')->values()->all())
        ->and(collect($template['tags'])->where(0, 'q')->values()->all())->toBe([['q', $report->event_id, 'wss://league.example', $reporter->pubkey]])
        ->and($template['tags'][0][1])->toStartWith('url https://esports.example/cards/en/page/series/'.$match->number.'.png?v=');

    expect($posts->submit($anna, 'series', (string) $match->number, ownSign($annaKey, $template))->kind)->toBe(1);

    // The losing side, a reported but unconfirmed series and a stranger have nothing to post.
    $open = SeriesMatch::factory()->create(['status' => SeriesStatus::Reported, 'winner' => 'challenger', 'rosters' => ['challenger' => [$anna->id], 'challenged' => [$rivals[0]->id]]]);

    expect(fn () => $posts->prepare($rivals[0], 'series', (string) $match->number))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare($anna, 'series', (string) $open->number))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare(User::factory()->create(), 'series', (string) $match->number))->toThrow(ShareRefused::class);
});

test('a tournament win quotes the tournament\'s calendar event by its address', function () {
    [$anna, $annaKey] = keyedPlayer();
    $tournament = shareTournament($anna, User::factory()->create());
    $league = new TestSigner;
    $calendar = NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(31923, [['d', $tournament->slug], ['alt', 'Tournament']])));
    $tournament->forceFill(['event_id' => $calendar->id])->save();

    $template = app(SharePosts::class)->prepare($anna, 'tournament', (string) $tournament->id);
    $address = '31923:'.$league->pubkey.':'.$tournament->slug;

    expect($template['content'])->toEndWith("\n\nnostr:".NostrKeys::naddr(31923, $league->pubkey, $tournament->slug, 'wss://league.example'))
        ->and(collect($template['tags'])->where(0, 'q')->values()->all())->toBe([['q', $address, 'wss://league.example']])
        ->and(collect($template['tags'])->where(0, 'p')->all())->toBe([]);

    expect(app(SharePosts::class)->submit($anna, 'tournament', (string) $tournament->id, ownSign($annaKey, $template))->kind)->toBe(1);
});

test('"I\'m in": the invite card, the tournament page as the invite, the calendar event quoted; only for an entry', function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $tournament = openTournament(['name' => 'Testnet Cup']);
    [$anna, $annaKey] = keyedPlayer();
    soloSignup($tournament, $anna, $annaKey);
    $tournament->refresh();

    $posts = app(SharePosts::class);
    $template = $posts->prepare($anna, 'signup', (string) $tournament->id);
    $page = 'https://esports.example/tournaments/'.$tournament->id;
    $naddr = NostrKeys::naddr(31923, $tournament->event->pubkey, $tournament->slug, 'wss://league.example');

    expect($template['content'])->toStartWith("I’m in Testnet Cup on TWENTY ONE Esports (Chess blitz). Join me:\n{$page}\n\nhttps://esports.example/cards/en/tournament-invite/{$tournament->id}-wide.png?v=")
        ->and($template['content'])->toEndWith("\n\nnostr:{$naddr}")
        ->and($template['tags'])->toContain(['r', $page])
        ->and($template['tags'])->toContain(['q', $tournament->address(), 'wss://league.example'])
        ->and(ownTagNames($template))->toBe(['imeta', 'r', 'q', 'alt']);

    expect($posts->submit($anna, 'signup', (string) $tournament->id, ownSign($annaKey, $template))->kind)->toBe(1);

    // Not entered, or the tournament is under way: nothing to post.
    expect(fn () => $posts->prepare(User::factory()->create(), 'signup', (string) $tournament->id))->toThrow(ShareRefused::class);

    $tournament->forceFill(['status' => TournamentStatus::Running])->save();

    expect(fn () => $posts->prepare($anna, 'signup', (string) $tournament->id))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare($anna, 'signup', (string) Tournament::factory()->signup()->create()->id))->toThrow(ShareRefused::class);
});

test('rule 37 (rev. 9.7): a mention must be in the text, a quote must be linked, never a reply', function () {
    $signer = new TestSigner;
    $other = new TestSigner;
    $npub = NostrKeys::hexToNpub($other->pubkey);
    $card = 'https://esports.example/cards/en/page/game/1.png?v=abc';
    $imeta = ['imeta', 'url '.$card, 'm image/png'];
    $rules = app(EsportsEventRules::class);
    $check = fn (array $tags, string $content): ?string => $rules->check(SignedEvent::fromInput($signer->sign(1, [$imeta, ...$tags, ['alt', 'Share post']], $content)));
    $id = str_repeat('a', 64);

    expect($check([['p', $other->pubkey], ['q', $id, '', $other->pubkey]], "GG nostr:{$npub}\n{$card}\nnostr:".NostrKeys::nevent($id)))->toBeNull()
        ->and($check([['q', '31923:'.$other->pubkey.':cup', '']], "{$card}\nnostr:".NostrKeys::naddr(31923, $other->pubkey, 'cup')))->toBeNull()
        ->and($check([['p', $other->pubkey]], "GG\n{$card}"))->toBe('share_post_mention')
        ->and($check([['p', 'npub-not-hex']], "{$card}"))->toBe('share_post_mention')
        ->and($check(array_fill(0, 6, ['p', $other->pubkey]), "nostr:{$npub}\n{$card}"))->toBe('share_post_mention')
        ->and($check([['q', $id]], "{$card}"))->toBe('share_post_quote')
        ->and($check([['q', $id], ['q', $id]], "{$card}\nnostr:".NostrKeys::nevent($id)))->toBe('share_post_quote')
        ->and($check([['q', '31923:'.$other->pubkey.':cup']], "{$card}\nnostr:".NostrKeys::nevent($id)))->toBe('share_post_quote')
        ->and($check([['e', $id]], "{$card}"))->toBe('share_post_reference')
        ->and($check([['a', '31923:'.$other->pubkey.':cup']], "{$card}"))->toBe('share_post_reference');
});
