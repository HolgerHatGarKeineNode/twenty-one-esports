<?php

use App\Enums\HyperMatchStatus;
use App\Enums\TournamentFormat;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use App\Jobs\PlayHyperBots;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\HyperAction;
use App\Models\HyperMatch;
use App\Models\HyperRating;
use App\Models\HyperRatingChange;
use App\Models\HyperTable;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Cards\SharePosts;
use App\Support\Cards\ShareRefused;
use App\Support\GameNames;
use App\Support\Hyper\HyperLobby;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperMoments;
use App\Support\Matches\MempoolStrip;
use App\Support\Tournaments\TournamentControl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\HyperOn;

/*
| Hyperbitcoinization on the league's surfaces (plan "Hyperbitcoinization", P6): with the switch on, the game has its
| place on home, /play, /matches (strip, list, filter; every match opens full-screen in a new tab), the sitemap, the
| rules, the link previews and the share posts; with it off, none of them knows it. And the gates' polish: rematch
| decline and expiry, the bot chain in runs, a corrected tournament result reverting its season Elo, the countdown.
| tests/Feature/GameSurfacesTest.php checks the registry-driven surfaces for every game, Hyperbitcoinization included.
*/

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
});

/** A finished 4-seat match of Anna (winner) and Bert with two bots, and a running one. */
function hyperSurfaceMatches(): array
{
    [$anna, $bert] = [User::factory()->create(['name' => 'Anna Surface']), User::factory()->create(['name' => 'Bert Surface'])];
    $done = HyperOn::finishTable(HyperOn::versus($anna, $bert, bots: 2), [0, 1, 2, 3]);
    $live = HyperOn::versus($anna, $bert, bots: 1);
    $daily = app(HyperMatches::class)->create([['user' => $anna, 'faction' => 'bitcoiner'], ['user' => $bert, 'faction' => 'fed']], seed: 9, creator: $anna, mode: HyperMatch::CORRESPONDENCE);

    return compact('anna', 'bert', 'done', 'live', 'daily');
}

test('switched on, home, /play and the navigation lead to the lobby and the season ladder, with the cover art', function () {
    HyperOn::play();

    expect(GameNames::page(Hyperbitcoinization::SLUG))->toBe(route('hyper.index'))
        ->and(app(GameRegistry::class)->cover(Hyperbitcoinization::SLUG)?->path(1280, 'webp'))->toBe('images/games/hyperbitcoinization-1280.webp')
        ->and(public_path('images/games/hyperbitcoinization-480.jpg'))->toBeFile();

    $home = $this->get(route('home'))->assertOk()->getContent();
    $tile = (string) str($home)->after('data-test="game-tile" data-game="hyperbitcoinization"')->before('</a>');

    expect($home)->toMatch('#href="'.preg_quote(route('hyper.index'), '#').'"(?:\s+wire:navigate)?\s+class="rv-gt" data-test="game-tile" data-game="hyperbitcoinization"#')
        ->and($tile)->toContain('data-game-cover="hyperbitcoinization"')
        ->and($home)->not->toContain(route('chess.lobby').'"  class="hub-tile-main" data-test="games-menu-hyperbitcoinization"');

    $play = (string) str($this->get(route('play'))->assertOk()->getContent())->after('data-test="play-game-hyperbitcoinization"')->before('</li>');
    expect($play)->toContain(route('hyper.index'), route('hyper.ladder'), route('rules').'#hyperbitcoinization', '90 s per turn', '24 h per turn');

    // The generic Rating ladder of a game that keeps none sends to the game's own.
    $this->get(route('ladder.show', [Hyperbitcoinization::SLUG, 'live']))->assertRedirect(route('hyper.ladder'))->assertStatus(301);
});

test('the season ladder shows the free-for-all leaders by points', function () {
    HyperOn::play();
    openSeason(ladders: false);
    [$a, $b, $c] = User::factory()->count(3)->sequence(['name' => 'Ada Points'], ['name' => 'Ben Points'], ['name' => 'Cid Points'])->create();
    HyperOn::finishTable(app(HyperMatches::class)->create([['user' => $a, 'faction' => 'bitcoiner'], ['user' => $b, 'faction' => 'fed'], ['user' => $c, 'faction' => 'ezb']], seed: 7, creator: $a), [0, 1, 2]);

    // 3 players: 6 · 3 · 1 points (home no longer carries ladder cards: plan "Refactor und Design-Revamp", board Main).
    $this->get(route('hyper.ladder'))->assertOk()->assertSeeInOrder(['Ada Points', '6', 'Ben Points', '3', 'Cid Points', '1']);
});

test('/matches lists a match once a player won it, the winner alone, never a running one; row and cube open a new tab (user 2026-10-09)', function () {
    HyperOn::play();
    ['done' => $done, 'live' => $live, 'daily' => $daily] = hyperSurfaceMatches();

    $all = $this->get(route('matches.index'))->assertOk()->getContent();
    preg_match_all('#<a href="([^"]+)" target="_blank" wire:key="h-\d+" data-test="hyper-row"#', $all, $rows);
    $cubes = array_map(fn (string $cube): string => preg_match('#<a href="([^"]+)"\s+target="_blank"#', $cube, $link) === 1 ? $link[1] : 'no new tab', array_slice(explode('data-test="strip-cube" data-game="hyperbitcoinization"', $all), 1));

    preg_match('#data-test="hyper-row".*?</a>#s', $all, $row);

    expect($rows[1])->toBe([route('hyper.match', $done)])
        ->and($cubes)->toBe([route('hyper.match', $done)])
        ->and($row[0])->toContain('Anna Surface')->toContain('Winner')->not->toContain('Bert Surface')->not->toContain('Round ')
        ->and([$live->status, $daily->status])->toBe([HyperMatchStatus::Active, HyperMatchStatus::Active])
        ->and($all)->toContain('data-test="game-hyperbitcoinization"');

    // Its own filter: only its matches, and "done" keeps the finished one.
    $mine = $this->get(route('matches.index', ['game' => Hyperbitcoinization::SLUG]))->assertOk();
    $mine->assertSee('data-test="hyper-row"', false)->assertDontSee('data-test="chess-row"', false);
    $doneTable = Livewire\Livewire::withQueryParams(['game' => Hyperbitcoinization::SLUG])->test('pages::matches.index')->call('pickStatus', 'done')->html();
    preg_match_all('#<a href="([^"]+)" target="_blank" wire:key="h-\d+" data-test="hyper-row"#', $doneTable, $finished);
    expect($finished[1])->toBe([route('hyper.match', $done)]);
});

test('the sitemap lists the lobby and the season ladder, never a Rating ladder or a single match', function () {
    HyperOn::play();
    ['done' => $done] = hyperSurfaceMatches();

    $xml = $this->get(route('sitemap.section', ['pages', 1]))->assertOk()->getContent();

    expect($xml)->toContain('<loc>'.route('hyper.index').'</loc>', '<loc>'.route('hyper.ladder').'</loc>')
        ->not->toContain('/ladder/hyperbitcoinization/')
        ->not->toContain($done->ulid);
});

test('/rules explains the game: phases, units, cards, teams, season and forfeit, with links to the lobby and the ladder', function () {
    HyperOn::play();

    $rules = (string) str($this->get(route('rules'))->assertOk()->getContent())->after('data-test="doc-section-hyperbitcoinization"')->before('data-test="doc-section-blockfill"');

    expect($rules)->toContain('Recruit', 'Attack', 'Fortify', 'How to win', '51% Attack', 'Clan against clan', 'friendly match', 'forfeits it', route('hyper.index'), route('hyper.ladder'))
        ->and($rules)->toContain('12 · 9 · 7 · 5 · 3 · 1');

    app()->setLocale('de');
    $german = $this->get(route('rules', ['lang' => 'de']))->assertOk()->getContent();
    expect($german)->toContain('Zentralbanken stürzen', 'Hyperbitcoinization-Season-Punkte');
});

test('the lobby, the ladder, a match and its replay carry their own link preview, drawn for every state', function () {
    HyperOn::play();
    ['done' => $done, 'live' => $live] = hyperSurfaceMatches();

    $this->get(route('hyper.index'))->assertOk()->assertSee('/cards/en/page/page/hyper.png', false);
    $this->get(route('hyper.ladder'))->assertOk()->assertSee('/cards/en/page/page/hyper-ladder.png', false);
    $this->get(route('hyper.match', $done))->assertOk()->assertSee('/cards/en/page/hyper/'.$done->ulid.'.png', false)->assertSee('Anna Surface wins', false);
    $this->get(route('hyper.replay', $done))->assertOk()->assertSee('/cards/en/page/hyper/'.$done->ulid.'.png', false);

    foreach (['page/hyper', 'page/hyper-ladder', 'hyper/'.$done->ulid, 'hyper/'.$live->ulid] as $path) {
        $png = $this->get('/cards/en/page/'.$path.'.png')->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
        expect(getimagesizefromstring($png))->toMatchArray([0 => 1200, 1 => 630]);
    }

    $this->get('/cards/en/page/hyper/01aaaaaaaaaaaaaaaaaaaaaaaa.png')->assertNotFound();
});

test('a win, a clan win and the collected sats are moments to share; a loss without loot and a spectator have none', function () {
    HyperOn::play();
    ['anna' => $anna, 'bert' => $bert, 'done' => $done] = hyperSurfaceMatches();
    $posts = app(SharePosts::class);
    $done->seats->firstWhere('seat', 1)->forceFill(['loot' => 2.5])->save();

    $win = $posts->prepare($anna, 'hyper', $done->ulid);
    $loot = $posts->prepare($bert, 'hyper', $done->ulid);

    expect($win['content'])->toStartWith('Won a Hyperbitcoinization match against 3 opponents on TWENTY ONE Esports')
        ->toContain(route('hyper.match', $done))->not->toContain('nostr:npub')->not->toContain('#')
        ->and(collect($win['tags'])->where(0, 'p'))->toBeEmpty()
        ->and($loot['content'])->toStartWith('Collected 2.5 M sats of loot in a Hyperbitcoinization match');

    $done->seats->firstWhere('seat', 1)->forceFill(['loot' => 0])->save();
    expect(fn () => $posts->prepare($bert, 'hyper', $done->ulid))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare(User::factory()->create(), 'hyper', $done->ulid))->toThrow(ShareRefused::class);

    // A clan win names the team.
    $red = Clan::factory()->create(['owner_id' => $anna->id, 'name' => 'Red Pill', 'clantag' => 'RED']);
    $blue = Clan::factory()->create(['owner_id' => $bert->id, 'name' => 'Blue Clan', 'clantag' => 'BLU']);
    $team = HyperOn::finish(HyperOn::teamEndgame(HyperOn::teams($red, $blue, $anna, $bert)), $anna);
    expect($posts->prepare($anna, 'hyper', $team->ulid)['content'])->toStartWith('Won a 2v2 clan match of Hyperbitcoinization with Red Pill');

    // The lobby page lists the moments with their share button; the end screen links there.
    $this->actingAs($anna)->get(route('hyper.index'))->assertOk()
        ->assertSee('data-test="hyper-moments"', false)
        ->assertSee('data-kind="team"', false)
        ->assertSee('data-kind="win"', false);
    $this->actingAs($anna)->get(route('hyper.match', $done))->assertSee('data-test="hyper-share-link"', false);
    expect(HyperMoments::latest($bert))->toHaveCount(0);
});

test('switched off, no surface knows the game: no tile, card, filter, row, sitemap entry, rules section, preview or share', function () {
    HyperOn::play();
    ['anna' => $anna, 'done' => $done] = hyperSurfaceMatches();
    // Off after the fact, the routes still loaded as a stale route table would keep them: every surface asks the registry.
    config(['esports.hyper.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    $pages = [
        $this->actingAs($anna)->get(route('home'))->assertOk()->getContent(),
        $this->get(route('play'))->assertOk()->getContent(),
        $this->get(route('matches.index'))->assertOk()->getContent(),
        $this->get(route('rules'))->assertOk()->getContent(),
        $this->get(route('players.show', $anna->npub))->assertOk()->getContent(),
        $this->get(route('sitemap.section', ['pages', 1]))->assertOk()->getContent(),
    ];

    foreach ($pages as $html) {
        expect($html)->not->toContain('hyperbitcoinization"')->not->toContain('/hyperbitcoinization<')->not->toContain('Hyperbitcoinization season');
    }

    $this->get('/cards/en/page/page/hyper.png')->assertNotFound();
    $this->get('/cards/en/page/hyper/'.$done->ulid.'.png')->assertNotFound();
    expect(fn () => app(SharePosts::class)->prepare($anna, 'hyper', $done->ulid))->toThrow(ShareRefused::class);
});

test('switched off at boot, there is no route of the game and its name links nowhere special', function () {
    expect(Route::has('hyper.index'))->toBeFalse()
        ->and(app(GameRegistry::class)->find(Hyperbitcoinization::SLUG))->toBeNull()
        ->and(GameNames::page(Hyperbitcoinization::SLUG))->toBe(route('chess.lobby'));

    $this->get('/hyperbitcoinization')->assertNotFound();
    $this->get(route('ladder.show', [Hyperbitcoinization::SLUG, 'live']))->assertNotFound();
});

test('any player declines the rematch: it closes for everybody, who declined is said, and nobody opens another', function () {
    HyperOn::play();
    ['anna' => $anna, 'bert' => $bert, 'done' => $done] = hyperSurfaceMatches();

    $this->actingAs($anna)->postJson(route('hyper.rematch', $done))->assertOk()->assertJsonPath('closed', null)->assertJsonPath('table', fn ($table) => is_string($table));
    $this->actingAs($bert)->postJson(route('hyper.rematch.decline', $done))->assertOk()
        ->assertJsonPath('closed', HyperTable::DECLINED)
        ->assertJsonPath('by', 'Bert Surface');

    // Asking again only reports the closed table; the match page shows it after a reload.
    $this->actingAs($anna)->postJson(route('hyper.rematch', $done))->assertOk()->assertJsonPath('closed', HyperTable::DECLINED)->assertJsonPath('url', null);
    expect(HyperTable::query()->where('rematch_of', $done->id)->sole()->status)->toBe(HyperTable::CANCELLED);
    preg_match('#id="hyper-config">(.*?)</script>#s', (string) $this->actingAs($anna)->get(route('hyper.match', $done))->getContent(), $config);
    expect(json_decode($config[1], true)['rematch'])->toMatchArray(['closed' => 'declined', 'by' => 'Bert Surface']);

    // A spectator has no say; declining before anybody asked closes it just the same.
    $this->actingAs(User::factory()->create())->postJson(route('hyper.rematch.decline', $done))->assertForbidden();
    ['anna' => $carl, 'done' => $other] = hyperSurfaceMatches();
    $this->actingAs($carl)->postJson(route('hyper.rematch.decline', $other))->assertOk()->assertJsonPath('closed', HyperTable::DECLINED);
});

test('the rematch offer runs out: 10 minutes after a live end, 3 days after a correspondence end; the sweep closes an open one', function () {
    HyperOn::play();
    ['anna' => $anna, 'bert' => $bert, 'done' => $done] = hyperSurfaceMatches();

    expect(HyperLobby::rematchEndsAt($done)?->getTimestamp())->toBe($done->ended_at->copy()->addMinutes(10)->getTimestamp());
    $this->actingAs($anna)->postJson(route('hyper.rematch', $done))->assertOk()->assertJsonPath('ends_ms', $done->ended_at->copy()->addMinutes(10)->getTimestampMs());

    $this->travel(11)->minutes();
    $this->artisan('hyper:check-clocks')->expectsOutputToContain('closed 1 rematch(es)')->assertSuccessful();
    expect(HyperTable::query()->where('rematch_of', $done->id)->sole()->only(['status', 'closed']))->toBe(['status' => HyperTable::CANCELLED, 'closed' => HyperTable::EXPIRED]);
    $this->actingAs($bert)->postJson(route('hyper.rematch', $done))->assertOk()->assertJsonPath('closed', HyperTable::EXPIRED);

    // No table yet: past the window the ask is refused.
    ['anna' => $carl, 'done' => $late] = hyperSurfaceMatches();
    $this->travel(11)->minutes();
    $this->actingAs($carl)->postJson(route('hyper.rematch', $late))->assertUnprocessable()->assertJsonPath('reason', 'rematch_expired');
    expect(HyperTable::query()->where('rematch_of', $late->id)->exists())->toBeFalse();

    $daily = HyperMatch::query()->whereKey($late->id)->firstOrFail()->forceFill(['mode' => HyperMatch::CORRESPONDENCE]);
    expect(HyperLobby::rematchEndsAt($daily)?->diffInMinutes($daily->ended_at, true))->toBe((float) 3 * 24 * 60);
});

test('a long bot chain plays in runs of TURNS_PER_RUN turns, each run sending the next', function () {
    HyperOn::play();
    $anna = User::factory()->create();
    // Six seats: 50 bot turns are about eight rounds, far from any end.
    $match = HyperOn::versus($anna, User::factory()->create(), bots: 4);
    Queue::fake();
    app(HyperMatches::class)->leave($match, $anna);
    $match->refresh()->load('seats.user');
    // The other player leaves too: only bots play on.
    app(HyperMatches::class)->leave($match, $match->seats->firstWhere('seat', 1)->user);
    $match->refresh();
    $before = $match->ply;

    (new PlayHyperBots($match->id, $before))->handle(app(HyperMatches::class));
    $match->refresh();

    // A turn is several plies (one per action); each played turn hands on to the next seat once.
    $turns = HyperAction::query()->where('hyper_match_id', $match->id)->where('ply', '>', $before)->get()
        ->sum(fn (HyperAction $action): int => collect($action->events)->where('type', 'turn_started')->count());

    expect($match->status)->toBe(HyperMatchStatus::Active)
        ->and($turns)->toBe(PlayHyperBots::TURNS_PER_RUN);
    Queue::assertPushed(PlayHyperBots::class, fn (PlayHyperBots $job): bool => $job->matchId === $match->id && $job->ply === $match->ply);
    expect((new PlayHyperBots(1, 0))->timeout)->toBe(80)->toBeLessThan(config('queue.connections.redis.retry_after'))->and((new PlayHyperBots(1, 0))->tries)->toBe(3);
});

test('correcting a finished rated 1v1 tournament match reverts its season Elo and rates the corrected winner; a forfeit only reverts', function () {
    HyperOn::play();
    $season = openSeason(ladders: false);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $tournament = HyperOn::tournament(4, TournamentFormat::SingleElimination);
    $semi = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereHas('hyperMatch')->orderBy('id')->firstOrFail();
    $table = HyperMatch::query()->where('tournament_match_id', $semi->id)->with('seats')->sole();
    [$first, $second] = [$table->seats->firstWhere('seat', 0)->user_id, $table->seats->firstWhere('seat', 1)->user_id];
    HyperOn::finishTable($table, [0, 1]);
    $elo = fn (): array => HyperRating::query()->where(['season' => $season->slug, 'kind' => HyperRating::DUEL])->orderBy('user_id')->pluck('rating', 'user_id')->all();

    expect($table->refresh()->rated)->toBeTrue()
        ->and($elo())->toEqual([$first => 1020, $second => 980]);

    // The other slot really won: the old ±20 goes, the corrected ±20 comes, and the preview said so before.
    $semi->refresh()->load('slots.participant');
    $winnerSlot = $semi->slots->first(fn ($slot) => in_array($second, $slot->participant->memberIds(), true))->slot;
    $control = app(TournamentControl::class);
    expect($control->eloPreview($tournament, $semi->id, ['winners' => [$winnerSlot]]))->not->toBeNull();
    $control->setResult($tournament, $admin, $semi->id, ['winners' => [$winnerSlot]], 'The other player won');

    expect($elo())->toEqual([$first => 980, $second => 1020])
        ->and(HyperRatingChange::query()->where('hyper_match_id', $table->id)->pluck('score', 'hyper_rating_id')->values()->sort()->values()->all())->toBe([0.0, 1.0])
        ->and(HyperRating::query()->where('user_id', $second)->value('wins'))->toBe(1);

    // A forfeit moves no Elo: the match's Elo is taken back, nothing applied.
    $control->setResult($tournament, $admin, $semi->id, ['noshow' => 1 - $winnerSlot], 'No-show after all');
    expect($elo())->toEqual([$first => 1000, $second => 1000])
        ->and(HyperRatingChange::query()->where('hyper_match_id', $table->id)->exists())->toBeFalse();
});

test('the lobby counts down to the bots taking the free seats, every second, and the faction labels have room on a phone', function () {
    HyperOn::play();
    // Frozen: under a loaded parallel run a second could pass between opening the table and rendering it.
    $this->freezeTime();
    $anna = User::factory()->create();
    app(HyperLobby::class)->open($anna, 4, HyperMatch::LIVE, 0, bots: true);

    $html = $this->actingAs($anna)->get(route('hyper.index'))->assertOk()->getContent();
    preg_match('#data-test="hyper-lobby-autofill" data-seconds="(\d+)"#', $html, $seconds);

    expect((int) $seconds[1])->toBe(300)
        ->and($html)->toContain('setInterval(', 'Bots fill the free seats in 5:00.')
        ->and($html)->not->toContain('@js(')
        ->and(substr_count($html, 'grid grid-cols-3 gap-2 min-[480px]:grid-cols-4 sm:grid-cols-7'))->toBe(2);
});

test('the strip and /matches ask the same number of queries for one and four finished clan matches', function () {
    HyperOn::play();
    $seed = function (int $count): void {
        foreach (range(1, $count) as $i) {
            $match = HyperOn::teams(Clan::factory()->create(), Clan::factory()->create(), User::factory()->create(), User::factory()->create());
            $match->forceFill(['status' => HyperMatchStatus::Finished, 'ended_at' => now()->subSeconds($i)])->save();
            $match->seats()->where('team', 0)->update(['place' => 1]);
            $match->seats()->where('team', 1)->update(['place' => 2]);
        }
    };
    $count = function (): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('matches.index'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $seed(1);
    $one = $count();
    $seed(3);

    expect($count())->toBe($one);
});

test('the mempool gets one block per match a player won, with the winner alone; never a running match, a bot\'s win or a bots-only table (user 2026-10-09)', function () {
    HyperOn::play();
    [$anna, $bert] = [User::factory()->create(['name' => 'Anna Mempool']), User::factory()->create(['name' => 'Bert Mempool'])];
    $won = HyperOn::finishTable(HyperOn::versus($anna, $bert, bots: 1), [1, 0, 2]);
    // A bot (seat 2) took first place at this table.
    $botWon = HyperOn::finishTable(HyperOn::versus($anna, $bert, bots: 1), [0, 1, 2]);
    $botWon->seats()->where('seat', 0)->update(['place' => 3]);
    $botWon->seats()->where('seat', 2)->update(['place' => 1]);
    $running = HyperOn::versus($anna, $bert, bots: 1);
    $botsOnly = app(HyperMatches::class)->create([['bot' => true, 'faction' => 'fed'], ['bot' => true, 'faction' => 'goldbug']], seed: 6);

    $strip = MempoolStrip::build(null);
    $keys = collect([...$strip['finished'], ...$strip['running']])->pluck('key')->filter(fn (string $key): bool => str_starts_with($key, 'hyper-'))->values()->all();
    $block = collect($strip['finished'])->firstWhere('key', 'hyper-'.$won->id);

    expect($botsOnly->refresh()->status)->toBe(HyperMatchStatus::Finished)
        ->and($keys)->toBe(['hyper-'.$won->id])
        ->and(array_column($block['sides'], 'name'))->toBe(['Bert Mempool'])
        ->and($block['score'])->toBe('Winner')
        ->and(MempoolStrip::waiting())->toBe(0)
        ->and($running->status)->toBe(HyperMatchStatus::Active)
        ->and($botWon->id)->not->toBe($won->id);
});

test('the creator of an open correspondence table posts its invite on Nostr; a live table, someone else\'s or a started one, no (user 2026-10-09)', function () {
    HyperOn::play();
    [$anna, $bert] = User::factory()->count(2)->create();
    $lobby = app(HyperLobby::class);
    $posts = app(SharePosts::class);
    $daily = $lobby->open($anna, 3, HyperMatch::CORRESPONDENCE, 0);
    $live = $lobby->open($bert, 3, HyperMatch::LIVE, 0);

    $invite = $posts->prepare($anna, 'hyper-table', $daily->ulid);

    expect($invite['kind'])->toBe(1)
        ->and($invite['content'])->toContain('2 free seats')->toContain(route('hyper.table', $daily))->not->toContain('#')
        ->and(fn () => $posts->prepare($bert, 'hyper-table', $daily->ulid))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->prepare($bert, 'hyper-table', $live->ulid))->toThrow(ShareRefused::class);

    $this->actingAs($anna)->get(route('hyper.index'))->assertOk()->assertSee('Post the invite on Nostr');
    $this->actingAs($bert)->get(route('hyper.index'))->assertOk()->assertDontSee('Post the invite on Nostr');
});
