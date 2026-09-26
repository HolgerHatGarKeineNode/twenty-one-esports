<?php

use App\Enums\ReportStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Jobs\PublishNostrEvent;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\MatchNumber;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\SignedEvent;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

beforeEach(function () {
    Queue::fake();
    $this->series = app(SeriesService::class);
});

/**
 * A ready 3v3 lineup whose clan owner (the captain) holds a real key.
 *
 * @return array{0: Lineup, 1: User, 2: TestSigner}
 */
function seriesLineup(string $mode = '3v3', int $substitutes = 0): array
{
    $signer = new TestSigner;
    $captain = User::factory()->withPubkey($signer->pubkey)->create();
    $lineup = Lineup::factory()->mode($mode)->ready($substitutes)->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id])->id]);

    return [$lineup->load('clan', 'seats.user'), $captain, $signer];
}

function seriesDraft(Lineup $from, Lineup $to, bool $rated = false, int $bestOf = 3): ChallengeDraft
{
    $start = now()->addHour()->startOfMinute()->getTimestamp();

    return new ChallengeDraft($from->id, $to->id, $bestOf, $rated, [$start, $start + 3600], $start - 600, 'gl hf');
}

/**
 * Run one two-phase action the way the browser does: prepare, sign, submit.
 */
function seriesSigned(TestSigner $signer, array $templates): array
{
    return $signer->signTemplates($templates);
}

/**
 * Challenge, accept (first proposal) and move past the start.
 *
 * @return array{0: SeriesMatch, 1: array, 2: array}
 */
function acceptedSeries(bool $rated = false, int $bestOf = 3): array
{
    $service = app(SeriesService::class);
    $a = seriesLineup();
    $b = seriesLineup();
    $draft = seriesDraft($a[0], $b[0], $rated, $bestOf);

    $match = $service->challenge($a[1], $draft, seriesSigned($a[2], $service->prepareChallenge($a[1], $draft)['templates']));
    $start = $match->proposals[0];
    $service->answer($match, $b[1], 'accepted', $start, seriesSigned($b[2], $service->prepareAnswer($match, $b[1], 'accepted', $start)));

    test()->travelTo(now()->setTimestamp($start)->addMinutes(40));

    return [$match->refresh(), $a, $b];
}

/**
 * @param  list<array{0: int, 1: int}>  $goals
 */
function enterGames(SeriesMatch $match, User $captain, array $goals): void
{
    foreach ($goals as $index => [$challenger, $challenged]) {
        app(SeriesService::class)->saveLiveGame($match, $captain, $index, $challenger, $challenged, null);
    }
}

test('a casual series runs challenge, accept, submit final score, accept result, and signs nothing', function () {
    [$match, [, $captainA, $signerA], [, $captainB, $signerB]] = acceptedSeries();

    expect($match->status)->toBe(SeriesStatus::Accepted)
        ->and(MatchNumber::query()->find($match->number)?->used_at)->not->toBeNull();

    enterGames($match, $captainA, [[3, 1], [1, 3], [2, 1]]);
    $templates = $this->series->prepareReport($match, $captainA);
    $report = $this->series->report($match, $captainA, seriesSigned($signerA, $templates));

    expect($templates)->toBe([])
        ->and($match->refresh()->status)->toBe(SeriesStatus::Reported)
        ->and($report->score())->toBe(['challenger' => 2, 'challenged' => 1])
        ->and(collect($report->roster)->countBy('side')->all())->toBe(['challenger' => 3, 'challenged' => 3]);

    $this->series->respond($match, $captainB, 'confirmed', '', seriesSigned($signerB, $this->series->prepareResponse($match, $captainB, 'confirmed')));

    expect($match->refresh()->status)->toBe(SeriesStatus::Confirmed)
        ->and($match->winner)->toBe('challenger')
        ->and($match->resolution)->toBe(SeriesResolution::Confirmed)
        ->and($match->result_games)->toHaveCount(3)
        // NIP "Game registry": casual games never produce match-flow events.
        ->and(NostrEvent::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a challenge ends declined, withdrawn or expired, each by the right side only', function () {
    $a = seriesLineup();
    $b = seriesLineup();
    $open = fn () => $this->series->challenge($a[1], seriesDraft($a[0], $b[0]), []);

    $declined = $open();
    expect(fn () => $this->series->answer($declined, $a[1], 'declined', null, []))->toThrow(SeriesRuleViolation::class);
    $this->series->answer($declined, $b[1], 'declined', null, []);
    expect($declined->refresh()->status)->toBe(SeriesStatus::Declined);

    $withdrawn = $open();
    expect(fn () => $this->series->answer($withdrawn, $b[1], 'withdrawn', null, []))->toThrow(SeriesRuleViolation::class);
    $this->series->answer($withdrawn, $a[1], 'withdrawn', null, []);
    expect($withdrawn->refresh()->status)->toBe(SeriesStatus::Withdrawn);

    $expired = $open();
    $this->travelTo($expired->respond_by->copy()->addSecond());
    $this->artisan('series:expire-challenges')->assertSuccessful();
    expect($expired->refresh()->status)->toBe(SeriesStatus::Expired)
        ->and(fn () => $this->series->answer($expired, $b[1], 'accepted', $expired->proposals[0], []))->toThrow(SeriesRuleViolation::class);
});

test('a wrong series score is refused by the game rules', function (array $goals, string $message) {
    [$match, [, $captainA]] = acceptedSeries();
    enterGames($match, $captainA, $goals);

    expect(fn () => $this->series->report($match, $captainA, []))
        ->toThrow(SeriesRuleViolation::class, $message);
    expect($match->refresh()->status)->toBe(SeriesStatus::Accepted);
})->with([
    'series not finished' => [[[3, 1], [1, 3]], 'The series is not finished'],
    'a game after the series was decided' => [[[3, 1], [2, 1], [0, 1]], 'already decided before game 3'],
]);

test('only captains submit, and only the other lineup\'s captain accepts', function () {
    [$match, [$lineupA, $captainA, $signerA], [$lineupB, $captainB]] = acceptedSeries();
    $playerA = $lineupA->seats->firstWhere('user_id', '!=', $captainA->id)->user;
    enterGames($match, $captainA, [[3, 1], [2, 1]]);

    expect(fn () => $this->series->report($match, $playerA, []))->toThrow(SeriesRuleViolation::class, 'Only a captain')
        ->and(fn () => $this->series->saveLiveGame($match, $playerA, 2, 1, 0, null))->toThrow(SeriesRuleViolation::class);

    $this->series->report($match, $captainA, seriesSigned($signerA, $this->series->prepareReport($match, $captainA)));
    $playerB = $lineupB->seats->firstWhere('user_id', '!=', $captainB->id)->user;

    expect(fn () => $this->series->respond($match, $captainA, 'confirmed', '', []))->toThrow(SeriesRuleViolation::class, 'other lineup')
        ->and(fn () => $this->series->respond($match, $playerB, 'confirmed', '', []))->toThrow(SeriesRuleViolation::class, 'other lineup')
        ->and($match->refresh()->status)->toBe(SeriesStatus::Reported);
});

test('a dispute lands with the admins, a counter report replaces the first, and an admin outside both clans decides', function () {
    [$match, [, $captainA], [$lineupB, $captainB]] = acceptedSeries();
    enterGames($match, $captainA, [[3, 1], [2, 1]]);
    $this->series->report($match, $captainA, []);

    expect(fn () => $this->series->respond($match, $captainB, 'disputed', '', []))->toThrow(SeriesRuleViolation::class, 'Say what went wrong');
    $this->series->respond($match, $captainB, 'disputed', 'Game 2 ended 1 : 2 for us.', []);
    expect($match->refresh()->status)->toBe(SeriesStatus::Disputed)
        ->and(SeriesService::isOpenCase($match))->toBeTrue();

    // "Either side can submit again. The new one replaces the old."
    $this->series->saveLiveGame($match, $captainB, 1, 1, 2, null);
    $this->series->saveLiveGame($match, $captainB, 2, 0, 3, null);
    $counter = $this->series->report($match, $captainB, []);
    $this->series->respond($match, $captainA, 'disputed', 'Game 2 was ours.', []);

    expect($match->reports()->pluck('status')->all())->toBe([ReportStatus::Superseded, ReportStatus::Disputed]);

    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $ownClanAdmin = $lineupB->seats->firstWhere('user_id', '!=', $captainB->id)->user;
    Admin::query()->create(['pubkey' => $ownClanAdmin->pubkey]);

    $this->actingAs($admin)->get(route('admin.disputes'))->assertOk()->assertSee('>#'.$match->number.'<', false);

    expect(fn () => $this->series->decide($match, $ownClanAdmin, ['type' => 'void'], 'x'))->toThrow(SeriesRuleViolation::class, 'own clan')
        ->and(fn () => $this->series->decide($match, $admin, ['type' => 'report', 'report' => $counter->id], ''))->toThrow(SeriesRuleViolation::class, 'public reason');

    $this->series->decide($match, $admin, ['type' => 'report', 'report' => $counter->id], 'End-screen screenshots show game 2 at 1 : 2.');

    expect($match->refresh()->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Admin)
        ->and($match->winner)->toBe('challenged')
        ->and($match->resolved_by_id)->toBe($admin->id)
        ->and(SeriesService::isOpenCase($match))->toBeFalse();
});

test('rated play is refused until a ladder is open', function () {
    $a = seriesLineup();
    $b = seriesLineup();

    expect(fn () => $this->series->prepareChallenge($a[1], seriesDraft($a[0], $b[0], rated: true)))
        ->toThrow(SeriesRuleViolation::class, 'Block 0');
});

test('a rated series signs 2150, 2151, 2152 and 2153 that pass the NIP rules, and none of them carries the lobby', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $this->series = app(SeriesService::class);

    [$match, [, $captainA, $signerA], [, $captainB, $signerB]] = acceptedSeries(rated: true, bestOf: 5);
    $this->series->setLobby($match, $captainA, 'e21-lsr-mmp', 'hunter2-secret', 'EU');
    enterGames($match, $captainA, [[3, 1], [1, 3], [2, 1], [3, 2]]);
    $this->series->report($match, $captainA, seriesSigned($signerA, $this->series->prepareReport($match, $captainA)));
    $this->series->respond($match, $captainB, 'disputed', 'Game 4 went to overtime.', seriesSigned($signerB, $this->series->prepareResponse($match, $captainB, 'disputed', 'Game 4 went to overtime.')));

    // Every event but the league's own genesis of the open season (openSeason()).
    $events = NostrEvent::query()->where('kind', '!=', 2156)->orderBy('id')->get();

    expect($events->pluck('kind')->all())->toBe([2150, 2151, 2152, 2153])
        ->and($match->refresh()->challenge_event_id)->toBe($events[0]->id);

    foreach ($events as $stored) {
        $event = SignedEvent::fromInput($stored->payload());

        expect($event->hasValidSignature())->toBeTrue()
            ->and(app(EsportsEventRules::class)->check($event))->toBeNull()
            ->and($stored->raw)->not->toContain('hunter2-secret')
            ->and($stored->raw)->not->toContain('e21-lsr-mmp');
    }

    $challenge = SignedEvent::fromInput($events[0]->payload());
    $report = SignedEvent::fromInput($events[2]->payload());

    expect($challenge->tag('match'))->toBe((string) $match->number)
        ->and($challenge->tag('bo'))->toBe('5')
        ->and($report->tagsNamed('score'))->toBe([['1', 'challenger', '3', '1'], ['2', 'challenged', '1', '3'], ['3', 'challenger', '2', '1'], ['4', 'challenger', '3', '2']])
        ->and(collect($report->tagsNamed('p'))->filter(fn ($p) => isset($p[2]))->count())->toBe(6);
    Queue::assertPushed(PublishNostrEvent::class, 4);
});

test('the lobby reaches the two lineups and no one else, in no response', function () {
    [$match, [$lineupA, $captainA], [$lineupB]] = acceptedSeries();
    $this->series->setLobby($match, $captainA, 'e21-lsr-mmp', 'hunter2-secret', 'EU');
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $member = $lineupB->seats->last()->user;

    $this->actingAs($member)->get(route('matches.room', $match))->assertOk()->assertSee('hunter2-secret');

    $outsiders = [null, User::factory()->create(), $admin];
    $pages = [route('matches.index'), route('matches.show', $match), route('matches.room', $match), route('admin.disputes'), route('admin.disputes.show', $match)];

    foreach ($outsiders as $outsider) {
        auth()->logout();

        if ($outsider !== null) {
            $this->actingAs($outsider);
        }

        foreach ($pages as $page) {
            $response = $this->get($page);

            expect($response->getContent())->not->toContain('hunter2-secret')
                ->and($response->getContent())->not->toContain('e21-lsr-mmp');
        }
    }

    expect(json_encode($match->refresh()->toArray()))->not->toContain('hunter2-secret');
});

/**
 * @return array<string, int> lineup subject => rating, in the given pool
 */
function lineupRatings(SeriesMatch $match, string $pool): array
{
    return Rating::query()->where('pool', $pool)->where('game', 'rocket-league')->where('mode', $match->mode)
        ->whereIn('subject', ['lineup:'.$match->challenger_lineup_id, 'lineup:'.$match->challenged_lineup_id])
        ->orderBy('subject')->pluck('rating', 'subject')->all();
}

test('a confirmed casual series moves both lineups\' casual Elo once, and a second confirmation or a retry changes nothing', function () {
    [$match, [, $captainA], [, $captainB]] = acceptedSeries();
    enterGames($match, $captainA, [[3, 1], [2, 1]]);
    $this->series->report($match, $captainA, []);
    $this->series->respond($match, $captainB, 'confirmed', '', []);

    $after = lineupRatings($match, Rating::CASUAL);

    expect($after)->toBe(['lineup:'.$match->challenger_lineup_id => 1020, 'lineup:'.$match->challenged_lineup_id => 980])
        ->and(RatingChange::query()->where('source', RatingChange::SERIES)->where('source_id', $match->id)->orderBy('id')->pluck('delta')->all())->toBe([20, -20])
        ->and(Rating::query()->where('pool', Rating::RATED)->count())->toBe(0);

    expect(fn () => $this->series->respond($match->refresh(), $captainB, 'confirmed', '', []))->toThrow(SeriesRuleViolation::class)
        ->and(app(RatingService::class)->applySeries($match->refresh()))->toBeFalse()
        ->and(lineupRatings($match, Rating::CASUAL))->toBe($after)
        ->and(RatingChange::query()->count())->toBe(2);

    $this->get(route('matches.show', $match->number))->assertOk()->assertSee('casual Elo 1000 → 1020');
});

test('an admin decision rates the series inside the decision; a void rates nothing', function () {
    [$match, [, $captainA], [, $captainB]] = acceptedSeries();
    enterGames($match, $captainA, [[3, 1], [2, 1]]);
    $this->series->report($match, $captainA, []);
    $this->series->respond($match, $captainB, 'disputed', 'Game 2 was ours.', []);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->series->decide($match, $admin, ['type' => 'forfeit', 'winner' => 'challenged'], 'No-show in game 3.');
    expect(lineupRatings($match, Rating::CASUAL))->toBe(['lineup:'.$match->challenger_lineup_id => 980, 'lineup:'.$match->challenged_lineup_id => 1020]);

    [$void, [, $voidA], [, $voidB]] = acceptedSeries();
    enterGames($void, $voidA, [[3, 1], [2, 1]]);
    $this->series->report($void, $voidA, []);
    $this->series->respond($void, $voidB, 'disputed', 'Server crash.', []);
    $this->series->decide($void, $admin, ['type' => 'void'], 'Server crash, replay.');

    expect(lineupRatings($void, Rating::CASUAL))->toBe([]);
});

test('an admin decision that lands while the other captain accepts the result is not overwritten', function () {
    [$match, [, $captainA], [, $captainB]] = acceptedSeries();
    enterGames($match, $captainA, [[3, 1], [2, 1]]);
    $this->series->report($match, $captainA, []);

    // The admin's decision commits between the captain's check and write:
    // right after the report is claimed, the match is resolved for the other side.
    $decided = false;
    DB::listen(function ($query) use ($match, &$decided): void {
        if (! $decided && str_starts_with($query->sql, 'update "series_reports"')) {
            $decided = true;
            SeriesMatch::query()->whereKey($match->id)->update(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Forfeit, 'winner' => 'challenged']);
        }
    });

    expect(fn () => $this->series->respond($match, $captainB, 'confirmed', '', []))->toThrow(SeriesRuleViolation::class, 'decided in between')
        ->and($match->refresh()->resolution)->not->toBe(SeriesResolution::Confirmed)
        ->and(RatingChange::query()->count())->toBe(0);
});

test('a confirmed rated series on an open ladder moves the rated Elo, never the casual one', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $this->series = app(SeriesService::class);

    [$match, [, $captainA, $signerA], [, $captainB, $signerB]] = acceptedSeries(rated: true);
    enterGames($match, $captainA, [[1, 3], [0, 2]]);
    $this->series->report($match, $captainA, seriesSigned($signerA, $this->series->prepareReport($match, $captainA)));
    $this->series->respond($match, $captainB, 'confirmed', '', seriesSigned($signerB, $this->series->prepareResponse($match, $captainB, 'confirmed')));

    expect(lineupRatings($match, Rating::RATED))->toBe(['lineup:'.$match->challenger_lineup_id => 980, 'lineup:'.$match->challenged_lineup_id => 1020])
        ->and(Rating::query()->where('pool', Rating::RATED)->value('season'))->toBe('season-1')
        ->and(lineupRatings($match, Rating::CASUAL))->toBe([]);
});
