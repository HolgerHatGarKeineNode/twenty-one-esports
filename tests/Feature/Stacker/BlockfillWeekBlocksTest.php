<?php

/*
| A Blockfill week on its own rules (BlockfillRules): every text that named
| "40 blocks" names the week's own count (the run's, where a run is in view;
| "the week's blocks" where no week is), and every comparison of times stays
| within one rule set (a 40-block time is no rival of a 60-block one).
| Week 41 of 2026 runs on 60 blocks, a level every 5 (t60e5g1s1c9).
*/

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Cards\PageCardFacts;
use App\Support\Cards\ShareCard;
use App\Support\Cards\SharePosts;
use App\Support\GameNames;
use App\Support\Invites\InviteGames;
use App\Support\Stacker\BlockfillMoments;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerReplays;
use App\Support\Stacker\StackerRuns;
use App\Support\StreamBot\BlockfillNotes;
use App\Support\TwentyOne\Stream\BlockfillSlides;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BlockfillOn;

const RULES_60 = 't60e5g1s1c9';
const WEEK_41_RUNS = '2026-10-05';

beforeEach(function () {
    Cache::flush();
    $this->withoutVite();
    // A Wednesday of week 41 (Monday 2026-10-05 00:00 Berlin).
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    BlockfillOn::play();
    leagueWeeksApproved(Blockfill::SLUG, ['difficulty' => RULES_60], before: 0, after: 0);
    $this->artisan('blockfill:weeks')->assertSuccessful();
});

/** A verified run of `$user` on `$engine` with `$ticks`, handed in `$minutesAgo` ago, with a replay. */
function weekBlocksRun(User $user, string $engine, int $ticks, int $minutesAgo = 30, StackerRunStatus $status = StackerRunStatus::Verified): StackerRun
{
    $at = CarbonImmutable::now()->subMinutes($minutesAgo);

    return StackerRun::factory()->for($user)->verified($ticks)->create([
        'engine' => $engine, 'status' => $status, 'week' => WEEK_41_RUNS, 'replay' => 'AAAA',
        'issued_at' => $at, 'started_at' => $at, 'submitted_at' => $at, 'verified_at' => $status === StackerRunStatus::Verified ? $at : null, 'created_at' => $at,
    ]);
}

/** A slide of the set as the stream renders it, read fresh. */
function weekBlocksSvg(string $scene): string
{
    Cache::flush();

    return SceneRenderer::fromConfig()->svg([...app(SceneSource::class)->rotation($scene, null, [], 0, (int) now()->getTimestampMs(), app(StreamStats::class)->all()), 'viewers' => null], RotationPlanner::VIEWS[$scene]);
}

test('every text that named 40 blocks names the week\'s own blocks: pages, meta, cards, posts, notes, replays and the stream', function () {
    $week = app(BlockfillWeeks::class)->current();

    // No run yet: the stream's teaser, the call to play and the empty moment slide.
    $empty = ['f1' => weekBlocksSvg('f1'), 'f4' => weekBlocksSvg('f4'), 'f5' => weekBlocksSvg('f5'), 'f3' => weekBlocksSvg('f3')];

    $ada = User::factory()->create(['name' => 'Ada']);
    $run = weekBlocksRun($ada, RULES_60, 5000);
    app(BlockfillWeeks::class)->record($run, now());
    $moment = app(BlockfillMoments::class)->of($run->refresh());
    $post = app(SharePosts::class)->prepare($ada, 'blockfill', (string) $run->id);
    $teaser = weekBlocksSvg('f1');

    expect($empty['f1'])->toContain('60 blocks to mine')->not->toContain('40 blocks')
        ->and($empty['f4'])->toContain('Mine 60 blocks. Your best time this week counts.')
        ->and($empty['f5'])->toContain('Mine 60 blocks.')
        ->and($empty['f3'])->toContain('Mine 60 blocks.')
        ->and($teaser)->toContain('chain: 60 blocks')->not->toContain('40 blocks')
        ->and(substr_count($teaser, 'fill="#F7931A"/>'))->toBeGreaterThanOrEqual(60)
        ->and($moment['kind'] ?? null)->toBe('first')
        ->and(ShareCard::blockfill($run, $moment)->facts['goal'])->toBe(60)
        ->and($post['content'])->toStartWith('New first place in Blockfill Week 41, 2026: 60 blocks mined in 1:23.333')
        ->and(StackerReplays::viewerConfig($run)['t'])->toMatchArray(['block' => 'Block :n of 60', 'slider' => ':time, block :n of 60'])
        ->and(PageCardFacts::blockfill()['goal'])->toBe(60)
        ->and((fn (): array => $this->extras($week, 'week'))->call(app(BlockfillNotes::class))['blocks'])->toBe('60')
        // Where no week is in view the mode is the week's blocks; on a week's own page its count.
        ->and(GameNames::mode(Blockfill::SLUG, Blockfill::MODE))->toBe('The week\'s blocks');

    $this->get(route('rules'))->assertOk()
        ->assertSee('The league\'s own stacking game: mine the week\'s blocks (60 in week 41) as fast as you can.')
        ->assertDontSee('40 blocks');
    $this->get(route('stacker.play'))->assertOk()->assertSee('Mine 60 blocks as fast as you can: the league', false);
    $this->get(route('tournaments.show', $week))->assertOk()->assertSee('· 60 blocks')->assertDontSee('40 blocks');
    $this->get(route('scores.show', Blockfill::SLUG))->assertOk()->assertSeeHtml('data-test="score-board-mode">60 blocks');
    $html = $this->get(route('stacker.moment', $run->id))->assertOk()->getContent();
    preg_match('~<meta property="og:description" content="([^"]+)"~', $html, $description);
    expect(html_entity_decode($description[1] ?? ''))->toContain('Ada mined 60 blocks in 1:23.333 in Blockfill Week 41, 2026.');
});

test('times compare only within one rule set: replays kept, held runs, the stream\'s badges, an invite\'s best and the player\'s best on these rules', function () {
    config(['esports.blockfill.replay_keep_top' => 1, 'esports.blockfill.replay_keep_shared' => 0]);
    $runs = app(StackerRuns::class);
    [$ada, $bob, $cy] = [User::factory()->create(['name' => 'Ada']), User::factory()->create(['name' => 'Bob']), User::factory()->create(['name' => 'Cy'])];

    // A 40-block run of last week's rules (issued before Monday), faster as it is shorter; then the week's 60-block runs.
    $old = weekBlocksRun($bob, 'bf1', 2000, 50);
    $ruledAda = weekBlocksRun($ada, RULES_60, 5000, 40);
    $ruledCy = weekBlocksRun($cy, RULES_60, 5600, 30);
    $runs->keepWeekTop(WEEK_41_RUNS);
    $fresh = collect(app(BlockfillSlides::class)->data()['fresh'])->mapWithKeys(fn (array $row): array => [$row['name'] => $row['badge']])->all();

    // Held runs of one player, one per rule set: neither supersedes the other.
    $heldOld = weekBlocksRun($cy, 'bf1', 1900, 20, StackerRunStatus::Review);
    $heldRuled = weekBlocksRun($cy, RULES_60, 5100, 10, StackerRunStatus::Review);
    $runs->keepHeld($heldRuled, now());

    expect([$old->refresh()->replay, $ruledAda->refresh()->replay, $ruledCy->refresh()->replay])->toBe(['AAAA', 'AAAA', null])
        ->and([$heldOld->refresh()->status, $heldRuled->refresh()->status])->toBe([StackerRunStatus::Review, StackerRunStatus::Review])
        // the 60-block leader is the week's #1; the faster 40-block run is no #1 of this week
        ->and($fresh)->toMatchArray(['Ada' => 'top', 'Bob' => null])
        ->and(app(InviteGames::class)->best($bob, Blockfill::SLUG))->toBeNull()
        ->and(app(InviteGames::class)->best($ada, Blockfill::SLUG))->toBe(Blockfill::milliseconds(5000));

    $this->actingAs($ada)->get(route('stacker.play'))->assertOk()->assertSee('Best on these rules')->assertDontSee('All-time best');
});
