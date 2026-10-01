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
