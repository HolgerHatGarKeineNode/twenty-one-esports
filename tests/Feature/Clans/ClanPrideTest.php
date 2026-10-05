<?php

use App\Enums\ChessEndReason;
use App\Enums\ClanRole;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Clans\ClanPride;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * The proud moments on /clans (ClanPride): only what the league recorded,
 * nothing for a quiet clan, the strongest lit moment on top of the page.
 */

beforeEach(function () {
    Queue::fake();
    Http::fake(fn () => Http::response([]));
});

/** A clan founded two months ago whose owner joined then: no "new" moment. */
function prideQuietClan(array $attributes = []): Clan
{
    $clan = Clan::factory()->create(['created_at' => now()->subDays(60), ...$attributes]);
    $clan->members()->update(['joined_at' => now()->subDays(60)]);

    return $clan->refresh();
}

function prideJoin(Clan $clan, ?User $user = null, ?DateTimeInterface $at = null): User
{
    $user ??= User::factory()->create();
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $user->id, 'role' => ClanRole::Member, 'joined_at' => $at ?? now()->subDays(60)]);

    return $user;
}

test('no clan, or only quiet clans: no moment and no spotlight, and the page says nothing about moments', function () {
    expect(app(ClanPride::class)->read())->toBe([])
        ->and(ClanPride::spotlight([]))->toBeNull();

    $this->get(route('clans.index'))->assertOk()
        ->assertSee('data-test="clans-empty"', false)
        ->assertDontSee('data-test="clan-moment"', false);

    $clan = prideQuietClan();
    // A game outside the window and a drawn one never count.
    ChessGame::factory()->finished('1-0')->create(['white_id' => $clan->owner_id, 'ended_at' => now()->subDays(40)]);
    ChessGame::factory()->finished('1/2-1/2')->create(['white_id' => $clan->owner_id, 'ended_at' => now()->subDay()]);

    expect(app(ClanPride::class)->read())->toBe([]);

    $this->get(route('clans.index'))->assertOk()
        ->assertSee('data-test="clan-card" data-clan="'.$clan->slug.'"', false)
        ->assertDontSee('data-test="clan-moment"', false)
        ->assertDontSee('data-test="clan-spotlight"', false);
});

test('new clans and new players are soft moments that never take the spotlight', function () {
    $new = Clan::factory()->create();
    prideJoin($new, at: now());
    $old = prideQuietClan();
    prideJoin($old, at: now()->subDays(2));
    prideJoin($old, at: now()->subDays(3));

    $moments = app(ClanPride::class)->read();

    expect($moments[$new->id][0])->toMatchArray(['type' => 'founded', 'count' => 2])
        ->and($moments[$old->id][0])->toMatchArray(['type' => 'joined', 'count' => 2])
        ->and(ClanPride::spotlight($moments))->toBeNull();

    $this->get(route('clans.index'))->assertOk()
        ->assertSee(__(':count players joined this week', ['count' => 2]))
        ->assertDontSee('data-test="clan-spotlight"', false);
});

test('a won series names the other side and its score, lights the card and takes the spotlight', function () {
    $winner = Lineup::factory()->mode('3v3')->ready()->create();
    $loser = Lineup::factory()->mode('3v3')->ready()->create();
    Clan::query()->whereKey([$winner->clan_id, $loser->clan_id])->update(['created_at' => now()->subDays(60)]);
    ClanMember::query()->update(['joined_at' => now()->subDays(60)]);
    SeriesMatch::factory()->create([
        'challenger_lineup_id' => $loser->id, 'challenged_lineup_id' => $winner->id, 'status' => SeriesStatus::Confirmed, 'winner' => 'challenged',
        'result_games' => [['winner' => 'challenged', 'challenger' => 1, 'challenged' => 3], ['winner' => 'challenger', 'challenger' => 2, 'challenged' => 0], ['winner' => 'challenged', 'challenger' => 0, 'challenged' => 1]],
        'finished_at' => now()->subDays(2),
    ]);
    // An open series and one decided 40 days ago are no moment.
    SeriesMatch::factory()->create(['challenger_lineup_id' => $winner->id, 'challenged_lineup_id' => $loser->id]);
    SeriesMatch::factory()->create(['challenger_lineup_id' => $loser->id, 'challenged_lineup_id' => $winner->id, 'status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'finished_at' => now()->subDays(40)]);

    $moments = app(ClanPride::class)->read();
    $loserClan = $loser->clan;

    expect($moments[$winner->clan_id][0])->toMatchArray(['type' => 'series', 'opponent' => $loserClan->name, 'opponent_tag' => $loserClan->clantag, 'score' => '2:1', 'mode' => '3v3'])
        ->and($moments[$winner->clan_id][1])->toMatchArray(['type' => 'wins', 'count' => 1])
        ->and($moments)->not->toHaveKey($loser->clan_id)
        ->and(ClanPride::spotlight($moments))->toBe($winner->clan_id);

    $html = $this->get(route('clans.index'))->assertOk()
        ->assertSeeInOrder(['data-test="clan-spotlight" data-clan="'.$winner->clan->slug.'"', __('Beat :opponent :score in :mode', ['opponent' => $loserClan->name, 'score' => '2:1', 'mode' => '3v3'])], false)
        ->getContent();

    // The spotlight clan is not a second time on screen: its grid card waits hidden for a search (the search filters
    // in the browser, performance plan P7); the loser's card shows, unlit.
    expect($html)->toMatch('/<article[^>]*style="display: none;"[^>]*data-test="clan-card" data-clan="'.preg_quote($winner->clan->slug, '/').'"/')
        ->and($html)->toMatch('/<article(?![^>]*display: none)[^>]*data-test="clan-card" data-clan="'.preg_quote($loserClan->slug, '/').'"\s*>/');
});

test('a streak needs 3 wins in a row up to the last game, and the week\'s wins count only after joining', function () {
    $clan = prideQuietClan();
    $streaker = prideJoin($clan);
    $late = prideJoin($clan, at: now()->subDays(2));
    $opponent = User::factory()->create();

    foreach ([1, 2, 3, 4] as $hours) {
        ChessGame::factory()->finished($hours % 2 === 0 ? '1-0' : '0-1')->create([
            'white_id' => $hours % 2 === 0 ? $streaker->id : $opponent->id, 'black_id' => $hours % 2 === 0 ? $opponent->id : $streaker->id, 'ended_at' => now()->subHours($hours),
        ]);
    }
    // Before those four: a loss, so the streak is 4, not 5.
    ChessGame::factory()->finished('0-1')->create(['white_id' => $streaker->id, 'black_id' => $opponent->id, 'ended_at' => now()->subHours(5)]);
    ChessGame::factory()->finished('1-0')->create(['white_id' => $streaker->id, 'black_id' => $opponent->id, 'ended_at' => now()->subHours(6)]);
    // The late joiner won before joining (not the clan's) and after (the clan's); two wins are no streak.
    ChessGame::factory()->finished('1-0')->create(['white_id' => $late->id, 'black_id' => $opponent->id, 'ended_at' => now()->subDays(3)]);
    ChessGame::factory()->finished('1-0')->create(['white_id' => $late->id, 'black_id' => $opponent->id, 'ended_at' => now()->subDay()]);

    $moments = app(ClanPride::class)->read()[$clan->id];

    expect($moments[0])->toMatchArray(['type' => 'streak', 'player' => $streaker->displayName(), 'count' => 4])
        ->and(collect($moments)->where('type', 'streak'))->toHaveCount(1)
        // Streaker: 4 this week + 1 older this week (6 h ago) = 5; late joiner: 1.
        ->and(collect($moments)->firstWhere('type', 'wins'))->toMatchArray(['count' => 6]);
});

test('a tournament podium is the strongest moment, for the clan of each placed player', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, clans: true);
    playOutAsDirector($tournament);
    $bySeed = TournamentParticipant::query()->where('tournament_id', $tournament->id)->with('user.clanMember')->get()->keyBy('seed');

    $moments = app(ClanPride::class)->read();
    $first = $moments[$bySeed[1]->user->clanMember->clan_id][0];

    expect($first)->toMatchArray(['type' => 'tournament', 'place' => 1, 'player' => 'Player 1', 'tournament' => $tournament->name])
        ->and($moments[$bySeed[2]->user->clanMember->clan_id][0])->toMatchArray(['type' => 'tournament', 'place' => 2])
        ->and($moments[$bySeed[3]->user->clanMember->clan_id][0])->toMatchArray(['type' => 'tournament', 'place' => 3])
        ->and($moments[$bySeed[4]->user->clanMember->clan_id][0])->toMatchArray(['type' => 'tournament', 'place' => 3])
        ->and(ClanPride::spotlight($moments))->toBe($bySeed[1]->user->clanMember->clan_id);

    $this->get(route('clans.index'))->assertOk()
        ->assertSee(e(__(':player won :tournament', ['player' => 'Player 1', 'tournament' => $tournament->name])), false);
});

test('/clans reads the same number of queries for 2 and for 12 clans with players and moments', function () {
    $queries = function (): int {
        cache()->flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('clans.index'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $world = function (int $clans): void {
        foreach (range(1, $clans) as $index) {
            $lineup = Lineup::factory()->mode('3v3')->ready()->create();
            prideJoin($lineup->clan, at: now()->subDay());
            ChessGame::factory()->finished('1-0')->create(['white_id' => $lineup->clan->owner_id, 'ended_at' => now()->subHours($index)]);
        }
    };

    $world(2);
    $small = $queries();
    $world(10);

    expect(Clan::query()->count())->toBe(12)
        ->and($queries())->toBe($small);
});

test('a series or chess game won by forfeit is no moment: no "Beat X 0:0", no streak link, no week\'s win', function () {
    $winner = Lineup::factory()->mode('3v3')->ready()->create();
    $loser = Lineup::factory()->mode('3v3')->ready()->create();
    Clan::query()->whereKey([$winner->clan_id, $loser->clan_id])->update(['created_at' => now()->subDays(60)]);
    ClanMember::query()->update(['joined_at' => now()->subDays(60)]);
    SeriesMatch::factory()->create(['challenger_lineup_id' => $winner->id, 'challenged_lineup_id' => $loser->id, 'status' => SeriesStatus::Resolved,
        'resolution' => SeriesResolution::Forfeit, 'winner' => 'challenger', 'result_games' => [], 'finished_at' => now()->subDay()]);
    $clan = prideQuietClan();
    $player = prideJoin($clan);
    foreach ([1, 2, 3] as $hours) {
        ChessGame::factory()->finished('1-0', ChessEndReason::Forfeit)->create(['white_id' => $player->id, 'black_id' => User::factory()->create()->id, 'ended_at' => now()->subHours($hours)]);
    }

    expect(app(ClanPride::class)->read())->toBe([]);
});
