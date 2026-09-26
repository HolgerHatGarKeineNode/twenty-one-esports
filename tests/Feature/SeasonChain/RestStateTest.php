<?php

/*
 * The rest state (SEASON-CHAIN.md decision 5, P7c): before Block 0 and
 * between seasons the server refuses rated play and mining with a friendly
 * message and the countdown; casual and daily games and every preparation go
 * on. One test per action group, and the live season as the control that the
 * refusals come from the rest state.
 */

use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\ChessChallenge;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\DailyChallenges;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Tests\Support\TrustedFacts;

beforeEach(function () {
    $this->freezeTime();
    config(['esports.preseason.block0_at' => now()->addDays(2)->addHours(4)->toIso8601String()]);
});

/** @return array{0: Lineup, 1: User} */
function restLineup(): array
{
    $captain = User::factory()->create();

    return [Lineup::factory()->mode('3v3')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id])->id]), $captain];
}

function restDraft(Lineup $from, Lineup $to, bool $rated): ChallengeDraft
{
    $start = now()->addHour()->startOfMinute()->getTimestamp();

    return new ChallengeDraft($from->id, $to->id, 3, $rated, [$start], $start - 600, '');
}

/** A finished series between two lineups, as the result flow leaves it. */
function restFinishedSeries(bool $rated): SeriesMatch
{
    return SeriesMatch::factory()->accepted()->create([
        'rated' => $rated,
        'status' => SeriesStatus::Confirmed,
        'resolution' => SeriesResolution::Confirmed,
        'winner' => 'challenger',
        'result_games' => [['winner' => 'challenger', 'challenger' => 2, 'challenged' => 1], ['winner' => 'challenger', 'challenged' => 0, 'challenger' => 1]],
        'finished_at' => now(),
    ]);
}

test('rated queue: refused before Block 0 with the countdown, the casual queue works', function () {
    $player = User::factory()->create();
    $queue = app(ChessQueue::class);

    try {
        $queue->join($player, 'blitz', rated: true);
        $this->fail('The rated queue was not refused.');
    } catch (ChessRuleViolation $violation) {
        expect($violation->reason)->toBe('rated_not_open')
            ->and($violation->getMessage())->toContain('Casual until Block 0')
            ->and($violation->getMessage())->toContain('(in 2 d 04:00:00)');
    }

    expect($queue->join($player))->toBeNull()
        ->and($queue->entryOf($player)?->rated)->toBeFalse();
});

test('rated challenges: refused before Block 0 with the countdown, a casual challenge works', function () {
    [$a, $captain] = restLineup();
    [$b] = restLineup();
    $series = app(SeriesService::class);

    expect(fn () => $series->prepareChallenge($captain, restDraft($a, $b, rated: true)))
        ->toThrow(SeriesRuleViolation::class, 'Casual until Block 0. Rated play and mining start')
        ->and($series->prepareChallenge($captain, restDraft($a, $b, rated: false))['templates'])->toBe([]);
});

test('ladder movement: a rated result moves no rated rating before Block 0, a casual one moves the casual Elo', function () {
    $ratings = app(RatingService::class);

    expect($ratings->applySeries(restFinishedSeries(rated: true)))->toBeFalse()
        ->and(Rating::query()->where('pool', Rating::RATED)->count())->toBe(0)
        ->and($ratings->applySeries(restFinishedSeries(rated: false)))->toBeTrue()
        ->and(Rating::query()->where('pool', Rating::CASUAL)->count())->toBe(2)
        ->and(Rating::query()->where('pool', Rating::RATED)->count())->toBe(0);
});

test('mining: no attestation and no block before Block 0', function () {
    expect(app(SeasonChains::class)->attestSeries(restFinishedSeries(rated: true)))->toBeNull()
        ->and(SeasonAttestation::query()->count())->toBe(0)
        ->and(NostrEvent::query()->count())->toBe(0);
});

test('daily games and preparation work before Block 0', function () {
    [$lineup, $captain] = restLineup();
    $opponent = User::factory()->create();

    $challenge = app(DailyChallenges::class)->challenge($captain, $opponent);
    expect($challenge)->toBeInstanceOf(ChessChallenge::class);

    $this->actingAs($captain);

    foreach (['clans.create', 'gaming.edit', 'chess.challenge', 'chess.lobby', 'me.correspondence'] as $route) {
        $this->get(route($route))->assertOk();
    }

    $this->get(route('clans.manage', $lineup->clan))->assertOk();
    $this->post(route('notify.block0'))->assertRedirect();

    expect($captain->refresh()->notify_block0_at)->not->toBeNull();
});

test('between seasons: the same refusals, with the season end instead of a countdown', function () {
    Season::factory()->ended()->create();
    [$a, $captain] = restLineup();
    [$b] = restLineup();

    expect(Seasons::state())->toBe('between')
        ->and(fn () => app(SeriesService::class)->prepareChallenge($captain, restDraft($a, $b, rated: true)))
        ->toThrow(SeriesRuleViolation::class, 'The season has ended')
        ->and(fn () => app(ChessQueue::class)->join($captain, 'blitz', rated: true))
        ->toThrow(ChessRuleViolation::class, 'The season has ended')
        ->and(app(SeasonChains::class)->attestSeries(restFinishedSeries(rated: true)))->toBeNull()
        ->and(app(RatingService::class)->applySeries(restFinishedSeries(rated: true)))->toBeFalse();
});

test('control: in a live season the same rated challenge is prepared and signed as 2150', function () {
    openSeason();
    // Trusted players who list each other: the rated trust gate is not what this control tests.
    app()->bind(TrustFacts::class, TrustedFacts::class);
    [$a, $captain] = restLineup();
    [$b] = restLineup();

    $plan = app(SeriesService::class)->prepareChallenge($captain, restDraft($a, $b, rated: true));

    expect(Seasons::state())->toBe('live')
        ->and($plan['templates'])->toHaveCount(1)
        ->and($plan['templates'][0]['kind'])->toBe(2150);
});
