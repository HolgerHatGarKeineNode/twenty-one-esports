<?php

/*
| Highscore attempts on /matches (App\Support\Matches\ScoreAttempts): the runs
| of Blockfill and of every other registered score game, in the mempool strip
| and in the table next to the matches. Verified is "done", waiting for the
| verifier or an admin is "to confirm" and shows no value; practice,
| rejected and director entries never show. Players only by Nostr face and
| name. With Blockfill's switch off none of its runs shows.
*/

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Games\ScoreDemo;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ScoreRun;
use App\Models\SeriesMatch;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Stacker\BlockfillWeeks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\BlockfillOn;
use Tests\Support\ScoreDemoOn;

/** 5:16.500 in ticks of 1/60 s. */
const ATTEMPT_TICKS = 18990;

function attemptPlayer(string $name): User
{
    return User::factory()->create(['name' => $name, 'gamer_tags' => ['steam' => 'STEAM-SECRET-'.$name]]);
}

/** The table rows of attempts on a page: "game:state" in page order. */
function attemptRows(string $html): array
{
    preg_match_all('/data-test="score-row" data-game="([^"]+)" data-state="([^"]+)"/', $html, $matches, PREG_SET_ORDER);

    return array_map(fn (array $match): string => $match[1].':'.$match[2], $matches);
}

test('a verified Blockfill run shows in the strip and in the done list, with face, name, time and its week', function () {
    BlockfillOn::play();
    $week = app(BlockfillWeeks::class)->open();
    $ben = attemptPlayer('El Presidento Ben');
    $run = StackerRun::factory()->verified(ATTEMPT_TICKS)->create(['user_id' => $ben->id]);
    SeriesMatch::factory()->accepted()->create();

    $html = $this->get(route('matches.index'))->assertOk()->getContent();

    expect(stripCubes($html))->toContain(Blockfill::SLUG.':fin')
        ->and(attemptRows($html))->toBe([Blockfill::SLUG.':done'])
        ->and($html)->toContain('wire:key="run-'.$run->id.'"')
        ->toContain('El Presidento Ben')
        ->toContain('5:16.500')
        ->toContain('data-avatar="'.$ben->pubkey.'"')
        ->toContain('href="'.route('tournaments.scores', $week).'"')
        // Nothing of the player but face and name: never a game account.
        ->not->toContain('STEAM-SECRET');

    Livewire::withQueryParams(['status' => 'done'])->test('pages::matches.index')
        ->assertSeeHtml('wire:key="run-'.$run->id.'"')
        ->assertSeeHtml('data-test="status-done"')
        ->assertSee(__('done').' 1');
    Livewire::withQueryParams(['status' => 'to_confirm'])->test('pages::matches.index')
        ->assertDontSeeHtml('wire:key="run-'.$run->id.'"');
});

test('a verified run of a week nobody opened links the game\'s leaderboards', function () {
    BlockfillOn::play();
    $run = StackerRun::factory()->verified(ATTEMPT_TICKS)->create();

    $this->get(route('matches.index'))->assertOk()
        ->assertSee('href="'.route('scores.show', Blockfill::SLUG).'"', false)
        ->assertSee('wire:key="run-'.$run->id.'"', false);
});

test('a run waiting for the verifier waits to confirm, unconfirmed and without its claimed time', function () {
    BlockfillOn::play();
    $pending = StackerRun::factory()->create(['status' => StackerRunStatus::Pending, 'ticks' => ATTEMPT_TICKS, 'submitted_at' => now()]);
    $verifying = StackerRun::factory()->create(['status' => StackerRunStatus::Verifying, 'ticks' => ATTEMPT_TICKS, 'submitted_at' => now()]);

    $html = $this->get(route('matches.index'))->assertOk()->getContent();

    expect(stripCubes($html))->toBe([Blockfill::SLUG.':live', Blockfill::SLUG.':live'])
        ->and(attemptRows($html))->toBe([Blockfill::SLUG.':waiting', Blockfill::SLUG.':waiting'])
        ->and($html)->toContain(__('unconfirmed'))->not->toContain('5:16.500');

    $waiting = Livewire::withQueryParams(['status' => 'to_confirm'])->test('pages::matches.index')
        ->assertSeeHtml('wire:key="run-'.$pending->id.'"')
        ->assertSeeHtml('wire:key="run-'.$verifying->id.'"')
        ->assertSee(__('to confirm').' 2')
        ->assertSee(__('done').' 0');

    $waiting->call('pickStatus', 'done')->assertDontSeeHtml('wire:key="run-'.$pending->id.'"');
});

test('practice, rejected, abandoned and issued runs never show, next to a verified one that does', function () {
    BlockfillOn::play();
    $verified = StackerRun::factory()->verified(ATTEMPT_TICKS)->create();

    foreach ([StackerRunStatus::Practice, StackerRunStatus::Rejected, StackerRunStatus::Abandoned, StackerRunStatus::Issued] as $status) {
        StackerRun::factory()->create(['status' => $status, 'ticks' => ATTEMPT_TICKS - 600, 'submitted_at' => now()]);
    }

    $html = $this->get(route('matches.index'))->assertOk()->getContent();

    expect(stripCubes($html))->toBe([Blockfill::SLUG.':fin'])
        ->and(attemptRows($html))->toBe([Blockfill::SLUG.':done'])
        ->and($html)->toContain('wire:key="run-'.$verified->id.'"')->not->toContain('5:06.500');

    Livewire::test('pages::matches.index')->assertSee(__('to confirm').' 0')->assertSee(__('done').' 1');
});

test('the game filter has Blockfill and shows only its runs; the season chain has none', function () {
    BlockfillOn::play();
    $run = StackerRun::factory()->verified(ATTEMPT_TICKS)->create();
    $series = SeriesMatch::factory()->accepted()->create();

    $page = Livewire::test('pages::matches.index')
        ->assertSeeHtml('data-test="game-'.Blockfill::SLUG.'"')
        ->assertSeeHtml('wire:key="m-'.$series->id.'"')
        ->call('pickGame', Blockfill::SLUG)
        ->assertSet('game', Blockfill::SLUG)
        ->assertSeeHtml('wire:key="run-'.$run->id.'"')
        ->assertDontSeeHtml('wire:key="m-'.$series->id.'"');

    expect(attemptRows($page->html()))->toBe([Blockfill::SLUG.':done'])
        ->and(substr_count($page->html(), 'data-test="match-row"'))->toBe(0);

    // Attempts mine nothing: the season chain lists none, the casual one does.
    $page->call('pickChain', 'season')->assertDontSeeHtml('wire:key="run-'.$run->id.'"');
    $page->call('pickChain', 'casual')->assertSeeHtml('wire:key="run-'.$run->id.'"');

    // A score game with no run yet says so and offers the game.
    StackerRun::query()->delete();
    Livewire::withQueryParams(['game' => Blockfill::SLUG])->test('pages::matches.index')
        ->assertSee(__('No runs yet'))
        ->assertSeeHtml('href="'.route('stacker.play').'"');
});

test('the clan filter keeps the runs of its members', function () {
    BlockfillOn::play();
    $clan = Clan::factory()->create();
    $member = attemptPlayer('Clan Runner');
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);
    $mine = StackerRun::factory()->verified(ATTEMPT_TICKS)->create(['user_id' => $member->id]);
    $other = StackerRun::factory()->verified(ATTEMPT_TICKS + 60)->create();

    Livewire::withQueryParams(['clan' => $clan->slug])->test('pages::matches.index')
        ->assertSeeHtml('wire:key="run-'.$mine->id.'"')
        ->assertDontSeeHtml('wire:key="run-'.$other->id.'"')
        ->assertSee(__('done').' 1')
        ->set('clan', '')
        ->assertSeeHtml('wire:key="run-'.$mine->id.'"')
        ->assertSeeHtml('wire:key="run-'.$other->id.'"');
});

test('runs page with the matches, 20 rows a page, newest first', function () {
    BlockfillOn::play();
    $this->freezeTime();

    foreach (range(1, 25) as $i) {
        StackerRun::factory()->verified(ATTEMPT_TICKS + $i)->create(['created_at' => now()->subMinutes($i)]);
    }

    SeriesMatch::factory()->accepted()->create(['created_at' => now()->subMinutes(30)]);

    $first = Livewire::test('pages::matches.index');
    expect(attemptRows($first->html()))->toHaveCount(20)
        ->and($first->html())->toContain('aria-label="'.__('Next page').'"');

    $second = $first->call('gotoPage', 2);
    expect(attemptRows($second->html()))->toHaveCount(5)
        ->and(substr_count($second->html(), 'data-test="match-row"'))->toBe(1);
});

test('with Blockfill switched off none of its runs shows and it has no filter, while another score game still shows', function () {
    // Another score game keeps the leaderboard routes registered: only Blockfill's own switch decides.
    ScoreDemoOn::play();
    $run = StackerRun::factory()->verified(ATTEMPT_TICKS)->create();
    StackerRun::factory()->create(['status' => StackerRunStatus::Pending, 'ticks' => ATTEMPT_TICKS, 'submitted_at' => now()]);
    $demo = ScoreRun::query()->create(['user_id' => attemptPlayer('Demo Runner')->id, 'game' => ScoreDemo::SLUG, 'mode' => 'time-trial', 'course' => 'demo-1',
        'unit' => 'ms', 'achieved_at' => now(), 'value' => 61_250, 'source' => 'fake', 'verified_at' => now()]);

    $html = $this->get(route('matches.index', ['game' => Blockfill::SLUG]))->assertOk()->getContent();

    expect(stripCubes($html))->toBe([ScoreDemo::SLUG.':fin'])
        ->and(attemptRows($html))->toBe([ScoreDemo::SLUG.':done'])
        ->and($html)->toContain('wire:key="score-'.$demo->id.'"')
        ->not->toContain('data-test="game-'.Blockfill::SLUG.'"')
        ->not->toContain('wire:key="run-'.$run->id.'"');

    Livewire::withQueryParams(['game' => Blockfill::SLUG])->test('pages::matches.index')->assertSet('game', 'all');
});

test('every other score game shows its runs: a verified one done, a manual one waiting, a director entry never', function () {
    ScoreDemoOn::play();
    $player = attemptPlayer('Demo Runner');
    $base = ['user_id' => $player->id, 'game' => ScoreDemo::SLUG, 'mode' => 'time-trial', 'course' => 'demo-1', 'unit' => 'ms', 'achieved_at' => now()];
    $done = ScoreRun::query()->create([...$base, 'value' => 61_250, 'source' => 'fake', 'verified_at' => now()]);
    $manual = ScoreRun::query()->create([...$base, 'value' => 59_000, 'source' => ScoreRun::MANUAL, 'proof_url' => 'https://example.com/proof']);
    $director = ScoreRun::query()->create([...$base, 'value' => 1_000, 'source' => ScoreRun::DIRECTOR, 'verified_at' => now()]);
    $rejected = ScoreRun::query()->create([...$base, 'value' => 2_000, 'source' => ScoreRun::MANUAL, 'rejected_at' => now()]);
    $nobody = ScoreRun::query()->create([...$base, 'user_id' => null, 'account_id' => 'acc-1', 'value' => 3_000, 'source' => 'fake']);

    $page = Livewire::withQueryParams(['game' => ScoreDemo::SLUG])->test('pages::matches.index')
        ->assertSeeHtml('wire:key="score-'.$done->id.'"')
        ->assertSeeHtml('wire:key="score-'.$manual->id.'"')
        ->assertDontSeeHtml('wire:key="score-'.$director->id.'"')
        ->assertDontSeeHtml('wire:key="score-'.$rejected->id.'"')
        ->assertDontSeeHtml('wire:key="score-'.$nobody->id.'"')
        ->assertSee('1:01.250')
        ->assertDontSee('0:59.000')
        ->assertSee(__('to confirm').' 1')
        ->assertSee(__('done').' 1');

    expect(attemptRows($page->html()))->toEqualCanonicalizing([ScoreDemo::SLUG.':done', ScoreDemo::SLUG.':waiting'])
        ->and(stripCubes($this->get(route('matches.index'))->getContent()))->toBe([ScoreDemo::SLUG.':fin', ScoreDemo::SLUG.':live']);
});

test('/matches asks the same number of queries for 1, 5 and 25 runs', function () {
    BlockfillOn::play();
    app(BlockfillWeeks::class)->open();

    $seed = function (int $count): void {
        foreach (range(1, $count) as $i) {
            StackerRun::factory()->verified(ATTEMPT_TICKS + $i)->create();
            StackerRun::factory()->create(['status' => StackerRunStatus::Pending, 'ticks' => ATTEMPT_TICKS, 'submitted_at' => now()]);
        }
    };
    $count = function (): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('matches.index'))->assertOk()->assertSee('data-test="score-row"', false);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $seed(1);
    $one = $count();
    $seed(4);
    $five = $count();
    $seed(20);
    $twentyFive = $count();

    expect($five)->toBe($one)->and($twentyFive)->toBe($five)->and(StackerRun::query()->count())->toBe(50);
});
