<?php

/*
| The Blockfill week's leaderboard page and scores/blockfill as a way into
| the game: the Play button first (to /blockfill), "How it works" to the
| rules, the week's facts as chips, the three steps (Play, Verified,
| Leaderboard), the board with medals, gaps and the time to beat, and the
| way around: last week, the points ladder, the calendar and every run. An
| empty week invites the first run; a finished week shows its winner. No
| manual submission on Blockfill.
*/

use App\Games\Blockfill;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Stacker\BlockfillRules;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\BlockfillOn;

beforeEach(function () {
    $this->withoutVite();
    BlockfillOn::play();
    // A Wednesday: this week started on Monday 2026-10-05 00:00 Berlin.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    $this->weeks = app(BlockfillWeeks::class);
    // The admins approved the weeks around now (the approval itself: LeagueWeekApprovalTest).
    leagueWeeksApproved(Blockfill::SLUG);
});

function blockfillPageRun(User $user, int $ticks, CarbonImmutable $at): void
{
    $run = StackerRun::factory()->for($user)->verified($ticks)->create(['submitted_at' => $at, 'week' => StackerRuns::weekOf($at)]);
    app(BlockfillWeeks::class)->record($run, $at);
}

/** The value of `$attribute` on the element carrying `data-test="$test"`, or null. */
function blockfillPageAttr(string $html, string $test, string $attribute = 'href'): ?string
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $node = (new DOMXPath($dom))->query('//*[@data-test="'.$test.'"]')->item(0);

    return $node instanceof DOMElement ? html_entity_decode($node->getAttribute($attribute)) : null;
}

test('the running week leads with Play now, How it works and the week as chips, and links last week, the points ladder, the calendar and the runs', function () {
    blockfillPageRun(User::factory()->create(['name' => 'Last Week Winner']), 2800, CarbonImmutable::parse('2026-10-01 12:00:00'));
    blockfillPageRun($first = User::factory()->create(['name' => 'Hal Finney Fan']), 2900, now()->subHour());
    blockfillPageRun($me = User::factory()->create(['name' => 'Ada Blockspace']), 3000, now()->subMinutes(30));
    $week = $this->weeks->current();
    $last = $this->weeks->previous();

    $html = $this->actingAs($me)->get(route('tournaments.scores', $week))->assertOk()->getContent();

    expect(blockfillPageAttr($html, 'play-now'))->toBe(route('stacker.play'))
        ->and(blockfillPageAttr($html, 'how-it-works'))->toBe(route('rules').'#blockfill')
        ->and(blockfillPageAttr($html, 'nav-last-week'))->toBe(route('tournaments.scores', $last))
        ->and(blockfillPageAttr($html, 'nav-points'))->toBe(route('scores.show', Blockfill::SLUG).'#points')
        ->and(blockfillPageAttr($html, 'nav-calendar'))->toBe(route('tournaments.calendar', $week))
        ->and(blockfillPageAttr($html, 'nav-runs'))->toBe(route('matches.index', ['game' => Blockfill::SLUG]))
        ->and(blockfillPageAttr($html, 'beat-this'))->toBe(route('stacker.play'))
        ->and(preg_match('/data-test="score-row" data-place="(\d+)"\s+data-mine/', $html, $mine))->toBe(1)
        ->and($mine[1])->toBe('2');

    // Play comes before the board, the steps name the way in, the facts are chips: players and the countdown.
    expect(strpos($html, 'data-test="play-now"'))->toBeLessThan(strpos($html, 'data-test="score-leaderboard"'));
    $this->get(route('tournaments.scores', $week))
        ->assertSeeInOrder([__('Play'), __('Verified'), __('Leaderboard')])
        ->assertSeeHtml('data-test="chip-players"')
        ->assertSeeHtml('data-test="chip-closes"')
        ->assertSeeHtml('data-test="time-to-beat"')
        ->assertSee('0:48.333')
        ->assertSee('+1.667')
        ->assertDontSeeHtml('data-test="score-submit"')
        ->assertDontSee(__('Submit your value'));

    Livewire::test('pages::scores.tournament', ['tournament' => $week])->call('$refresh')->assertOk();
});

test('an empty week still leads with Play now and invites the first run', function () {
    $week = $this->weeks->open();

    $html = $this->get(route('tournaments.scores', $week))->assertOk()
        ->assertSeeHtml('data-test="score-empty-week"')
        ->assertSee(__('No time yet this week. The first verified run takes #1.'))
        ->assertDontSeeHtml('data-test="time-to-beat"')
        ->assertSeeHtml('data-test="first-place-free"')
        ->assertDontSeeHtml('data-test="chip-players"')
        ->getContent();

    expect(blockfillPageAttr($html, 'play-now'))->toBe(route('stacker.play'))
        ->and(blockfillPageAttr($html, 'how-it-works'))->toBe(route('rules').'#blockfill')
        ->and(blockfillPageAttr($html, 'nav-points'))->toBe(route('scores.show', Blockfill::SLUG).'#points');
});

test('a finished week shows its winner and still leads to this week\'s game', function () {
    blockfillPageRun(User::factory()->create(['name' => 'Satoshi Stacker']), 2710, CarbonImmutable::parse('2026-10-01 18:00:00'));
    blockfillPageRun(User::factory()->create(['name' => 'Hal Finney Fan']), 2900, now()->subHour());
    expect(app(ScoreLeaderboards::class)->tick()['finalized'])->toBe(1);
    $last = $this->weeks->previous()->refresh();

    $html = $this->get(route('tournaments.scores', $last))->assertOk()
        ->assertSeeHtml('data-test="week-winner"')
        ->assertSee('Satoshi Stacker')
        ->assertDontSeeHtml('data-test="chip-closes"')
        ->assertDontSeeHtml('data-test="beat-this"')
        ->getContent();

    expect(blockfillPageAttr($html, 'play-now'))->toBe(route('stacker.play'))
        ->and(blockfillPageAttr($html, 'nav-this-week'))->toBe(route('tournaments.scores', $this->weeks->current()));
});

test('scores/blockfill leads with Play now and this week, and links the rules, the points ladder and the runs', function () {
    blockfillPageRun(User::factory()->create(['name' => 'Last Week Winner']), 2800, CarbonImmutable::parse('2026-10-01 12:00:00'));
    blockfillPageRun(User::factory()->create(['name' => 'Hal Finney Fan']), 2900, now()->subHour());

    $html = $this->get(route('scores.show', Blockfill::SLUG))->assertOk()
        ->assertSeeHtml('id="points"')
        ->assertSeeHtml('data-test="time-to-beat"')
        ->getContent();

    expect(blockfillPageAttr($html, 'play-now'))->toBe(route('stacker.play'))
        ->and(blockfillPageAttr($html, 'how-it-works'))->toBe(route('rules').'#blockfill')
        ->and(blockfillPageAttr($html, 'nav-this-week'))->toBe(route('tournaments.scores', $this->weeks->current()))
        ->and(blockfillPageAttr($html, 'nav-last-week'))->toBe(route('tournaments.scores', $this->weeks->previous()))
        ->and(blockfillPageAttr($html, 'nav-points'))->toBe(route('scores.show', Blockfill::SLUG).'#points')
        ->and(blockfillPageAttr($html, 'nav-runs'))->toBe(route('matches.index', ['game' => Blockfill::SLUG]));
    expect(strpos($html, 'data-test="play-now"'))->toBeLessThan(strpos($html, 'data-test="score-leaderboard"'));

    Livewire::test('pages::scores.show', ['game' => Blockfill::SLUG])->call('$refresh')->assertOk();
});

test('the German page says the same in German', function () {
    $week = $this->weeks->open();

    $this->get(route('tournaments.scores', $week).'?lang=de')->assertOk()
        ->assertSee('Jetzt spielen')
        ->assertSee('So funktioniert es')
        ->assertSee('Noch keine Zeit diese Woche. Der erste geprüfte Lauf holt #1.')
        ->assertDontSee('Play now');
});

test('the week\'s own page leads with the cover and Play now, the rules and facts as chips, the podium and the board, and links onward; no text walls', function () {
    blockfillPageRun(User::factory()->create(['name' => 'Last Week Winner']), 2800, CarbonImmutable::parse('2026-10-01 12:00:00'));
    foreach (['Hal Finney Fan' => 2900, 'Ada Blockspace' => 3000, 'Nakamoto' => 3100, 'Mempool Max' => 3200, 'Satoshi Stacker' => 3300] as $name => $ticks) {
        blockfillPageRun(${'u'.$ticks} = User::factory()->create(['name' => $name]), $ticks, now()->subHours(3)->addMinutes($ticks / 100));
    }
    $week = $this->weeks->current();

    $html = $this->actingAs($u3000)->get(route('tournaments.show', $week))->assertOk()
        ->assertSeeHtml('data-test="blockfill-hero"')
        ->assertSeeHtml('data-game-cover="blockfill"')
        ->assertSeeHtml('data-test="time-to-beat"')
        ->assertSeeHtml('data-test="blockfill-steps"')
        ->assertSeeHtml('data-test="week-podium"')
        // The rules of the week as chips: its blocks and its speed.
        ->assertSeeInOrder(['data-test="chip-rule"', '40 blocks', 'data-test="chip-rule"', 'Steady 1 row/s'], false)
        ->assertSeeInOrder(['data-test="week-facts"', __('Fastest time wins'), __('A tie goes to the earlier run'), __('Places score points on the ladder'), __('Mines no season blocks')], false)
        // No paragraphs: not How it works, not the facts, not the questions, not the roster above the board.
        ->assertDontSeeHtml('data-test="how-it-works"><')
        ->assertDontSeeHtml('id="how-h"')
        ->assertDontSeeHtml('data-test="facts"')
        ->assertDontSeeHtml('id="faq-h"')
        ->assertDontSeeHtml('data-test="entries"')
        ->assertDontSeeHtml('data-test="to-blockfill"')
        ->getContent();

    // Play now first, then the podium, then the rows from 4th place on (the podium holds 1 to 3).
    preg_match_all('/data-test="score-row" data-place="(\d+)"/', $html, $rows);
    expect(blockfillPageAttr($html, 'play-now'))->toBe(route('stacker.play'))
        ->and(strpos($html, 'data-test="play-now"'))->toBeLessThan(strpos($html, 'data-test="week-podium"'))
        ->and(strpos($html, 'data-test="week-podium"'))->toBeLessThan(strpos($html, 'data-test="score-leaderboard"'))
        ->and(preg_match_all('/data-test="podium-\d"/', $html))->toBe(3)
        ->and($rows[1])->toBe(['4', '5'])
        ->and(blockfillPageAttr($html, 'to-scores'))->toBe(route('tournaments.scores', $week))
        ->and(blockfillPageAttr($html, 'nav-points'))->toBe(route('scores.show', Blockfill::SLUG).'#points')
        ->and(blockfillPageAttr($html, 'nav-runs'))->toBe(route('matches.index', ['game' => Blockfill::SLUG]))
        ->and(blockfillPageAttr($html, 'nav-last-week'))->toBe(route('tournaments.scores', $this->weeks->previous()));

    Livewire::actingAs($u3000)->test('pages::tournaments.show', ['tournament' => $week])->call('$refresh')->assertOk();
});

test('an empty week\'s own page leads with Play now and the empty podium, in English and German', function (string $locale, string $play, string $empty) {
    $week = $this->weeks->open();

    $html = $this->get(route('tournaments.show', $week).'?lang='.$locale)->assertOk()
        ->assertSee($play)
        ->assertSeeHtml('data-test="first-place-free"')
        ->assertSeeHtml('data-test="score-empty-week"')
        ->assertDontSeeHtml('data-test="week-podium"')
        ->getContent();

    expect(html_entity_decode($html, ENT_QUOTES))->toContain($empty)
        ->and(blockfillPageAttr($html, 'play-now'))->toBe(route('stacker.play'));
})->with([
    'en' => ['en', 'Play now', 'No time yet this week. The first verified run takes #1.'],
    'de' => ['de', 'Jetzt spielen', 'Noch keine Zeit diese Woche. Der erste geprüfte Lauf holt #1.'],
]);

test('the week page\'s search description names the week\'s blocks, never the rule id', function () {
    $week = $this->weeks->open();

    $html = $this->get(route('tournaments.show', $week))->assertOk()->getContent();
    preg_match('#<meta name="description" content="([^"]*)">#', $html, $match);
    $description = html_entity_decode($match[1] ?? '', ENT_QUOTES);

    expect($description)->toContain('Blockfill tournament ('.BlockfillRules::weekBlocks($week).')')
        ->and($description)->not->toContain(Blockfill::MODE);
});
