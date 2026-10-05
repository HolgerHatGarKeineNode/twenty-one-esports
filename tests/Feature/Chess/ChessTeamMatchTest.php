<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\ClanRole;
use App\Enums\LineupRole;
use App\Enums\Platform;
use App\Enums\ReportStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\MatchNumber;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\SeriesMatchBoard;
use App\Models\SeriesQueueEntry;
use App\Models\SeriesReport;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\ChessTeamMatches;
use App\Support\Engagement\ClanHashrate;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\CasualChallenges;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualMatches;
use App\Support\Series\CasualQueue;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\Ladders;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\FixtureBoardGame;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/*
 * Chess team matches, plan "Schach Rapid und Clan" P4 (NIP rev. 9.22, "Chess
 * team matches"): challenge and answer with `boards`, the rated pair limit,
 * the guards that keep the series report flow off a team match, the hidden
 * lineup, the lock (board order, forfeit, void) and the reservation of the
 * locked players; P5: the start of the boards, board forfeit and void, the
 * team result, its hashrate and attestations, and the live view.
 */

beforeEach(function () {
    Queue::fake();
    $this->series = app(SeriesService::class);
    $this->teamMatches = app(ChessTeamMatches::class);
});

/**
 * A ready chess rapid lineup: the clan owner as captain (a real key) plus
 * `$players - 1` more active players.
 *
 * @return array{0: Lineup, 1: User, 2: TestSigner}
 */
function teamLineup(int $players = 3): array
{
    $signer = new TestSigner;
    $captain = User::factory()->withPubkey($signer->pubkey)->create();
    $lineup = Lineup::factory()->game('chess', 'rapid')->ready($players - 2)->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id])->id]);

    return [$lineup->load('clan', 'seats.user'), $captain, $signer];
}

function teamDraft(Lineup $from, Lineup $to, bool $rated = false, int $boards = 2, ?int $start = null): ChallengeDraft
{
    $start ??= now()->addHours(2)->startOfMinute()->getTimestamp();

    return new ChallengeDraft($from->id, $to->id, 1, $rated, [$start, $start + 3600], $start - 3600, 'gl hf', $boards);
}

/**
 * @param  array{0: Lineup, 1: User, 2: TestSigner}  $a
 * @param  array{0: Lineup, 1: User, 2: TestSigner}  $b
 */
function teamChallengeAndAccept(array $a, array $b, bool $rated = false, int $boards = 2): SeriesMatch
{
    $service = app(SeriesService::class);
    $draft = teamDraft($a[0], $b[0], $rated, $boards);
    $match = $service->challenge($a[1], $draft, $a[2]->signTemplates($service->prepareChallenge($a[1], $draft)['templates']));
    $start = $match->proposals[0];
    $service->answer($match, $b[1], 'accepted', $start, $b[2]->signTemplates($service->prepareAnswer($match, $b[1], 'accepted', $start)));

    return $match->refresh();
}

/**
 * @return array{0: SeriesMatch, 1: array{0: Lineup, 1: User, 2: TestSigner}, 2: array{0: Lineup, 1: User, 2: TestSigner}}
 */
function acceptedTeamMatch(bool $rated = false, int $boards = 2): array
{
    $a = teamLineup();
    $b = teamLineup();

    return [teamChallengeAndAccept($a, $b, $rated, $boards), $a, $b];
}

/** @return list<int> */
function activeIds(Lineup $lineup): array
{
    return array_map(fn ($seat) => $seat->user_id, $lineup->fresh()->activeSeats());
}

function travelToLock(SeriesMatch $match): void
{
    test()->travelTo(ChessTeamMatches::lockAt($match->refresh())->copy()->addSecond());
}

test('a friendly team match is challenged and accepted with its boards, takes a number and signs nothing', function () {
    [$match] = acceptedTeamMatch(boards: 3);

    expect($match->status)->toBe(SeriesStatus::Accepted)
        ->and($match->isTeamMatch())->toBeTrue()
        ->and($match->boards)->toBe(3)
        ->and($match->best_of)->toBe(1)
        ->and($match->rated)->toBeFalse()
        ->and(MatchNumber::query()->find($match->number)?->used_at)->not->toBeNull()
        // NIP rev. 9.22: a friendly is league data, nothing is signed.
        ->and(NostrEvent::query()->count())->toBe(0);
});

test('a rated team match signs a 2150 with boards and the rapid ladder but no bo and no zap, and the accept pins the gate', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $this->series = app(SeriesService::class);

    [$match, [, $captainA]] = acceptedTeamMatch(rated: true, boards: 2);
    $challenge = SignedEvent::fromInput($match->challengeEvent->payload());

    expect($match->rated)->toBeTrue()
        ->and($match->ladder_address)->toBe(Ladders::address('chess', 'rapid'))
        ->and($challenge->pubkey)->toBe($captainA->pubkey)
        ->and($challenge->tag('boards'))->toBe('2')
        ->and($challenge->tagsNamed('bo'))->toBe([])
        ->and($challenge->tagsNamed('zap'))->toBe([])
        ->and(collect($challenge->tagsNamed('a'))->pluck(0)->all())->toContain(Ladders::address('chess', 'rapid'))
        ->and(app(EsportsEventRules::class)->check($challenge))->toBeNull()
        ->and($match->answer_event_id)->not->toBeNull()
        ->and($match->gate_at_accept['sides']['challenger'] ?? [])->toHaveCount(3);
});

test('a team match needs 2 or 3 boards, enough players for them, and a reply due before the lineup lock', function () {
    $a = teamLineup(players: 2);
    $b = teamLineup(players: 3);
    $start = now()->addHours(2)->startOfMinute()->getTimestamp();

    expect(fn () => $this->series->prepareChallenge($a[1], teamDraft($a[0], $b[0], boards: 4)))->toThrow(SeriesRuleViolation::class, 'Pick 2 or 3 boards.')
        ->and(fn () => $this->series->prepareChallenge($a[1], teamDraft($a[0], $b[0], boards: 3)))->toThrow(SeriesRuleViolation::class, 'at least 3 active players')
        ->and(fn () => $this->series->prepareChallenge($a[1], new ChallengeDraft($a[0]->id, $b[0]->id, 1, false, [$start], $start - 600, null, 2)))
        ->toThrow(SeriesRuleViolation::class, '30 minutes before the first suggested time');
});

test('only one rated team match per pair of clans in seven days: a second is refused, a friendly is not, and a week later rated opens again', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $this->series = app(SeriesService::class);
    $a = teamLineup();
    $b = teamLineup();

    $first = teamChallengeAndAccept($a, $b, rated: true);
    // The first one is played and decided two hours later.
    $first->update(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Void, 'winner' => 'none', 'finished_at' => now()]);

    $refused = fn () => $this->series->prepareChallenge($b[1], teamDraft($b[0], $a[0], rated: true));

    expect($refused)->toThrow(SeriesRuleViolation::class, 'rated team match in the last 7 days')
        ->and(fn () => $this->series->prepareChallenge($a[1], teamDraft($a[0], $b[0], rated: true)))->toThrow(SeriesRuleViolation::class, 'rated team match in the last 7 days');

    $friendly = teamChallengeAndAccept($a, $b, rated: false);
    expect($friendly->rated)->toBeFalse();
    $friendly->update(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Void, 'winner' => 'none', 'finished_at' => now()]);

    // Another clan pair is not limited by it.
    $c = teamLineup();
    expect($this->series->prepareChallenge($a[1], teamDraft($a[0], $c[0], rated: true))['templates'])->toHaveCount(1);

    $this->travelTo(now()->setTimestamp($first->start_at->getTimestamp())->addDays(7)->addMinute());
    expect($this->series->prepareChallenge($b[1], teamDraft($b[0], $a[0], rated: true))['templates'])->toHaveCount(1);
});

test('a declined rated team match does not count against the pair limit', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $this->series = app(SeriesService::class);
    $a = teamLineup();
    $b = teamLineup();
    $draft = teamDraft($a[0], $b[0], rated: true);
    $match = $this->series->challenge($a[1], $draft, $a[2]->signTemplates($this->series->prepareChallenge($a[1], $draft)['templates']));
    $this->series->answer($match, $b[1], 'declined', null, $b[2]->signTemplates($this->series->prepareAnswer($match, $b[1], 'declined')));

    expect($this->series->prepareChallenge($a[1], teamDraft($a[0], $b[0], rated: true))['templates'])->toHaveCount(1);
});

test('a team match has no lobby, score sheet, who played, no-show report, result report or response', function () {
    [$match, [, $captainA], [, $captainB]] = acceptedTeamMatch();
    $this->travelTo($match->start_at->copy()->addMinutes(30));

    foreach ([
        fn () => $this->series->setLobby($match, $captainA, 'lobby', 'pw', null),
        fn () => $this->series->saveLiveGame($match, $captainA, 0, null, null, 'challenger'),
        fn () => $this->series->setRoster($match, $captainA, [$captainA->id]),
        fn () => $this->series->reportNoShow($match, $captainA),
        fn () => $this->series->prepareReport($match, $captainA),
        fn () => $this->series->report($match, $captainA, []),
        fn () => $this->series->prepareResponse($match, $captainB, 'confirmed'),
    ] as $action) {
        expect($action)->toThrow(SeriesRuleViolation::class, 'A team match has no score sheet');
    }

    expect($match->refresh()->status)->toBe(SeriesStatus::Accepted);
});

test('the league never auto-confirms, marks overdue or forfeits a team match on a series deadline', function () {
    [$match, [, $captainA]] = acceptedTeamMatch();
    // Deadlines as a players-mode tournament pins them, all of them run out.
    $match->update(['deadlines' => ['noshow_minutes' => 5, 'report_minutes' => 10, 'response_minutes' => 10]]);
    $this->travelTo($match->start_at->copy()->addHours(3));

    expect($this->series->markOverdue($match))->toBeFalse()
        ->and($match->refresh()->overdue_at)->toBeNull();

    $match->update(['noshow_side' => 'challenger', 'noshow_reported_at' => now()->subHour()]);
    expect($this->series->forfeitNoShow($match))->toBeFalse()
        ->and($match->refresh()->status)->toBe(SeriesStatus::Accepted);

    $match->update(['noshow_side' => null, 'noshow_reported_at' => null, 'status' => SeriesStatus::Reported]);
    SeriesReport::query()->create(['series_match_id' => $match->id, 'user_id' => $captainA->id, 'side' => 'challenger', 'games' => [['winner' => 'challenger', 'challenger' => null, 'challenged' => null]], 'roster' => [], 'status' => ReportStatus::Open])
        ->forceFill(['created_at' => now()->subHour()])->save();

    expect($this->series->autoConfirm($match))->toBeFalse()
        ->and($match->refresh()->status)->toBe(SeriesStatus::Reported)
        ->and($match->resolution)->toBeNull();
});

test('a captain names exactly as many players as boards from his lineup until the lock, and the other side cannot see whom', function () {
    [$match, [$lineupA, $captainA], [$lineupB, $captainB]] = acceptedTeamMatch();
    $idsA = activeIds($lineupA);
    $playerA = User::query()->find($idsA[1]);
    $playerA->forceFill(['name' => 'Hidden Knight'])->save();

    expect(fn () => $this->teamMatches->name($match, $captainA, [$idsA[0]]))->toThrow(SeriesRuleViolation::class, 'Pick exactly 2 players')
        ->and(fn () => $this->teamMatches->name($match, $captainA, [$idsA[0], activeIds($lineupB)[0]]))->toThrow(SeriesRuleViolation::class, 'Only active players of your lineup')
        ->and(fn () => $this->teamMatches->name($match, $playerA, [$idsA[0], $idsA[1]]))->toThrow(SeriesRuleViolation::class, 'Only a captain');

    $this->teamMatches->name($match, $captainA, [$idsA[0], $idsA[1]]);

    expect(SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', 'challenger')->pluck('user_id')->sort()->values()->all())->toBe([$idsA[0], $idsA[1]])
        ->and(ChessTeamMatches::visibleTo($match->refresh(), 'challenger', $captainB))->toBeFalse()
        ->and($this->teamMatches->lineupFor($match, 'challenger', $captainB))->toBeNull()
        ->and($this->teamMatches->lineupFor($match, 'challenger', $playerA)?->pluck('user_id')->sort()->values()->all())->toBe([$idsA[0], $idsA[1]]);

    // The opponent captain's lineup step says that a lineup is named, never whom.
    Livewire::actingAs($captainB)->test('team-lineup', ['match' => $match])
        ->assertOk()
        ->assertSeeHtml('data-test="team-lineup-hidden-challenger"')
        ->assertSee('Lineup named. You see it at the lock.')
        ->assertDontSee('Hidden Knight')
        ->call('$refresh')->assertOk();

    // Its own side sees the pick.
    Livewire::actingAs($captainA)->test('team-lineup', ['match' => $match])
        ->assertSee('Hidden Knight')
        ->assertSet('picked', [$idsA[0], $idsA[1]]);

    // At the lock both lineups become public.
    $this->teamMatches->name($match, $captainB, array_slice(activeIds($lineupB), 0, 2));
    travelToLock($match);
    expect($this->teamMatches->lock($match))->toBe('locked');

    Livewire::actingAs($captainB)->test('team-lineup', ['match' => $match])
        ->assertSee('Hidden Knight')
        ->assertSeeHtml('data-test="team-lineup-locked"');

    expect(fn () => $this->teamMatches->name($match, $captainA, [$idsA[0], $idsA[2]]))->toThrow(SeriesRuleViolation::class, 'until 30 minutes before the start');
});

test('in a rated team match a captain can name only the players the accept pinned as Trusted', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $this->series = app(SeriesService::class);
    $this->teamMatches = app(ChessTeamMatches::class);
    [$match, [$lineupA, $captainA]] = acceptedTeamMatch(rated: true);

    // A player seated after the accept is not pinned: the gate never saw him.
    $late = User::factory()->create();
    ClanMember::query()->create(['clan_id' => $lineupA->clan_id, 'user_id' => $late->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
    LineupSeat::query()->create(['lineup_id' => $lineupA->id, 'user_id' => $late->id, 'role' => LineupRole::Substitute, 'accepted_at' => now()]);

    expect(fn () => $this->teamMatches->name($match, $captainA, [$captainA->id, $late->id]))->toThrow(SeriesRuleViolation::class, 'Trusted when the match was accepted');

    $this->teamMatches->name($match, $captainA, array_slice(activeIds($lineupA), 0, 2));
    expect(ChessTeamMatches::hasNamed($match->refresh(), 'challenger'))->toBeTrue();
});

test('the lock orders each side by rapid Elo, ties by more results and then the older account, and freezes the ratings', function () {
    [$match, [$lineupA, $captainA], [$lineupB, $captainB]] = acceptedTeamMatch(boards: 3);
    [$a1, $a2, $a3] = activeIds($lineupA);
    [$b1, $b2, $b3] = activeIds($lineupB);
    $casual = fn (int $userId, int $rating, int $results) => Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'rapid', 'subject' => 'user:'.$userId, 'user_id' => $userId, 'rating' => $rating, 'results' => $results]);
    // A blitz rating never counts for the rapid order.
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$a3, 'user_id' => $a3, 'rating' => 2000, 'results' => 50]);

    // Side A: plain Elo order, the player without a rapid rating at the start rating last.
    $casual($a1, 1050, 9);
    $casual($a2, 1100, 3);
    // Side B: all at 1000. More results first; then the older account.
    $casual($b3, 1000, 4);
    User::query()->whereKey($b1)->update(['created_at' => now()->subDays(2)]);
    User::query()->whereKey($b2)->update(['created_at' => now()->subDays(30)]);

    $this->teamMatches->name($match, $captainA, [$a3, $a1, $a2]);
    $this->teamMatches->name($match, $captainB, [$b1, $b2, $b3]);
    travelToLock($match);

    expect($this->teamMatches->lockDue())->toBe(['locked' => 1, 'forfeited' => 0, 'voided' => 0]);

    $boards = fn (string $side) => SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', $side)->orderBy('board')->get(['user_id', 'board', 'rating', 'rating_pool', 'rating_results'])->toArray();

    expect($match->refresh()->lineup_locked_at)->not->toBeNull()
        ->and($boards('challenger'))->toBe([
            ['user_id' => $a2, 'board' => 1, 'rating' => 1100, 'rating_pool' => 'casual', 'rating_results' => 3],
            ['user_id' => $a1, 'board' => 2, 'rating' => 1050, 'rating_pool' => 'casual', 'rating_results' => 9],
            ['user_id' => $a3, 'board' => 3, 'rating' => 1000, 'rating_pool' => 'start', 'rating_results' => 0],
        ])
        ->and(array_column($boards('challenged'), 'user_id'))->toBe([$b3, $b2, $b1])
        // A second tick finds nothing to do.
        ->and($this->teamMatches->lockDue())->toBe(['locked' => 0, 'forfeited' => 0, 'voided' => 0]);
});

test('in a live season the rated rapid rating orders the boards before the casual one', function () {
    openSeason(['slug' => 'season-1']);
    $player = User::factory()->create();
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'rapid', 'subject' => 'user:'.$player->id, 'user_id' => $player->id, 'rating' => 1500, 'results' => 20]);
    Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-1', 'game' => 'chess', 'mode' => 'rapid', 'subject' => 'user:'.$player->id, 'user_id' => $player->id, 'rating' => 900, 'results' => 2]);

    expect($this->teamMatches->standing($player))->toBe(['rating' => 900, 'pool' => 'rated', 'results' => 2]);
});

test('a side without a lineup at the lock loses the whole team match by forfeit, attested once and unrated; neither side named: void', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $this->series = app(SeriesService::class);
    $this->teamMatches = app(ChessTeamMatches::class);
    [$match, [$lineupA, $captainA]] = acceptedTeamMatch(rated: true);
    $this->teamMatches->name($match, $captainA, array_slice(activeIds($lineupA), 0, 2));

    // Before the lock nothing happens.
    $this->travelTo(ChessTeamMatches::lockAt($match)->copy()->subMinute());
    expect($this->teamMatches->lockDue())->toBe(['locked' => 0, 'forfeited' => 0, 'voided' => 0]);

    travelToLock($match);
    expect($this->teamMatches->lockDue())->toBe(['locked' => 0, 'forfeited' => 1, 'voided' => 0]);

    $match->refresh();
    $attestation = SeasonAttestation::query()->where('source', SeasonAttestation::SERIES)->where('source_id', $match->id)->sole();
    $event = SignedEvent::fromInput(NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload());

    expect($match->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Forfeit)
        ->and($match->winner)->toBe('challenger')
        ->and($match->finished_at)->not->toBeNull()
        ->and($match->lineup_locked_at)->not->toBeNull()
        ->and($event->tag('resolution'))->toBe('forfeit')
        ->and($event->tag('winner'))->toBe('challenger')
        ->and($event->tagsNamed('elo'))->toBe([])
        ->and($event->tagsNamed('board'))->toBe([])
        ->and(Rating::query()->where('mode', 'rapid')->count())->toBe(0)
        // The reservation never starts for a decided match.
        ->and(ChessTeamMatches::reservationOf($captainA))->toBeNull()
        ->and($this->teamMatches->lockDue())->toBe(['locked' => 0, 'forfeited' => 0, 'voided' => 0]);

    [$void] = acceptedTeamMatch();
    travelToLock($void);
    expect($this->teamMatches->lock($void))->toBe('void')
        ->and($void->refresh()->resolution)->toBe(SeriesResolution::Void)
        ->and($void->winner)->toBe('none');
});

test('from the lock the named players are out of every queue and start no other game, and nobody can start one against them', function () {
    [$match, [$lineupA, $captainA], [$lineupB, $captainB]] = acceptedTeamMatch();
    $named = array_slice(activeIds($lineupA), 0, 2);
    $free = User::factory()->create();
    $this->teamMatches->name($match, $captainA, $named);
    $this->teamMatches->name($match, $captainB, array_slice(activeIds($lineupB), 0, 2));
    $player = User::query()->findOrFail($named[1]);

    // Before the lock the player still plays: a queue search is allowed.
    expect(ChessTeamMatches::reservationOf($player))->toBeNull();
    app(ChessQueue::class)->join($player);
    expect(ChessQueueEntry::query()->where('user_id', $player->id)->exists())->toBeTrue();

    travelToLock($match);
    $this->teamMatches->lock($match);

    FixtureBoardGame::play();

    $reason = function (Closure $action): ?string {
        try {
            $action();
        } catch (ChessRuleViolation|BoardRuleViolation $violation) {
            return $violation->reason;
        }

        return null;
    };

    expect(ChessQueueEntry::query()->where('user_id', $player->id)->exists())->toBeFalse()
        ->and(ChessTeamMatches::reservationOf($player)?->id)->toBe($match->id)
        ->and($reason(fn () => app(ChessQueue::class)->join($player)))->toBe(ChessTeamMatches::RESERVED)
        ->and($reason(fn () => app(ChessInvites::class)->invite($player, $free)))->toBe(ChessTeamMatches::RESERVED)
        ->and($reason(fn () => app(ChessInvites::class)->invite($free, $player)))->toBe(ChessTeamMatches::OTHER_RESERVED)
        ->and($reason(fn () => app(ChessGameService::class)->start($player, $free)))->toBe(ChessTeamMatches::RESERVED)
        ->and($reason(fn () => app(ChessGameService::class)->start($free, $player, 'correspondence')))->toBe(ChessTeamMatches::RESERVED)
        ->and($reason(fn () => app(BoardGameService::class)->start(FixtureBoardGame::SLUG, $free, $player)))->toBe(ChessTeamMatches::RESERVED)
        // A player of the lineup who was not named is free.
        ->and(ChessTeamMatches::reservationOf(User::query()->findOrFail(activeIds($lineupA)[2])))->toBeNull();

    // Once the team match is over, the reservation ends.
    $match->update(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Void, 'winner' => 'none', 'finished_at' => now()]);
    expect(ChessTeamMatches::reservationOf($player))->toBeNull();
});

test('the match room shows a team match its lineup step instead of the score sheet, and every touched page answers a roundtrip', function () {
    [$match, [$lineupA, $captainA], [, $captainB]] = acceptedTeamMatch();

    Livewire::actingAs($captainA)->test('pages::matches.room', ['match' => $match])
        ->assertOk()
        ->assertSeeHtml('data-test="team-lineup"')
        ->assertDontSeeHtml('data-test="games"')
        ->assertDontSeeHtml('data-test="report-noshow"')
        ->assertSee('2 boards')
        ->call('sync')->assertDispatched('team-lineup-sync')
        ->call('$refresh')->assertOk();

    Livewire::actingAs($captainA)->test('team-lineup', ['match' => $match])
        ->call('toggle', activeIds($lineupA)[0])
        ->call('toggle', activeIds($lineupA)[1])
        ->assertSee('2 of 2 picked')
        ->call('save')
        ->assertSet('error', '')
        ->assertSee('Lineup saved.')
        ->call('$refresh')->assertOk();

    expect(ChessTeamMatches::hasNamed($match, 'challenger'))->toBeTrue();

    // The public match page of a team match still renders.
    $this->actingAs($captainB)->get(route('matches.show', $match))->assertOk();
});

test('the challenge form offers a chess rapid lineup boards and friendly or rated, and sends a team match', function () {
    $a = teamLineup();
    $b = teamLineup();

    Livewire::actingAs($a[1])->test('pages::challenges.create', ['lineup' => $a[0]->id])
        ->assertOk()
        ->assertSet('lineupId', $a[0]->id)
        ->assertSeeHtml('data-test="boards-3"')
        ->assertSee('Friendly')
        ->assertDontSeeHtml('data-test="series-invite-link"')
        // Players of a team lineup out of the boards it is challenged to (2 by default), not out of a series team size.
        ->assertSeeHtml('data-test="opponent-players-'.$b[0]->id.'">3 / 2<')
        ->call('pickOpponent', $b[0]->id)
        ->set('boards', 3)
        ->set('now', true)
        ->call('send', '[]')
        ->assertSet('error', '');

    $match = SeriesMatch::query()->sole();

    expect($match->boards)->toBe(3)
        ->and($match->rated)->toBeFalse()
        // "Now" leaves room for the lineup lock: the start 40 minutes ahead, the reply due by the lock.
        ->and($match->proposals[0] - now()->getTimestamp())->toBeGreaterThan(39 * 60)
        ->and($match->respond_by->getTimestamp())->toBe($match->proposals[0] - 30 * 60);

    Livewire::actingAs($a[1])->test('pages::challenges.create', ['lineup' => $a[0]->id])->call('$refresh')->assertOk();
});

test('a board game of a team match takes no match number of its own, a board exists once, and a game is never both a tournament game and a board', function () {
    [$match] = acceptedTeamMatch();
    $numbers = MatchNumber::query()->count();
    $board = ChessGame::factory()->create(['mode' => 'rapid', 'series_match_id' => $match->id, 'board' => 1]);

    expect($board->number)->toBeNull()
        ->and(MatchNumber::query()->count())->toBe($numbers)
        // A solo game still takes one.
        ->and(ChessGame::factory()->create()->number)->not->toBeNull()
        ->and(fn () => DB::transaction(fn () => ChessGame::factory()->create(['mode' => 'rapid', 'series_match_id' => $match->id, 'board' => 1])))
        ->toThrow(UniqueConstraintViolationException::class);

    // Refused in the model's creating hook, before any insert: the id needs no tournament match row.
    expect(fn () => ChessGame::factory()->create(['series_match_id' => $match->id, 'board' => 2, 'tournament_match_id' => 999_999]))
        ->toThrow(LogicException::class, 'not both');
});

test('the three team match migrations roll back and up again, and refuse to roll back while team data exists', function () {
    $files = [
        'series' => require database_path('migrations/2026_10_05_135637_add_team_match_columns_to_series_matches_table.php'),
        'boards' => require database_path('migrations/2026_10_05_135638_create_series_match_boards_table.php'),
        'games' => require database_path('migrations/2026_10_05_135639_add_series_match_to_chess_games_table.php'),
    ];
    [$match, [$lineupA, $captainA]] = acceptedTeamMatch();
    $this->teamMatches->name($match, $captainA, array_slice(activeIds($lineupA), 0, 2));
    $board = ChessGame::factory()->create(['mode' => 'rapid', 'series_match_id' => $match->id, 'board' => 1]);

    expect(fn () => $files['games']->down())->toThrow(RuntimeException::class, '1 chess game(s) are boards of a team match');
    $board->delete();
    $files['games']->down();

    expect(fn () => $files['boards']->down())->toThrow(RuntimeException::class, '2 team match lineup row(s) exist');
    SeriesMatchBoard::query()->delete();
    $files['boards']->down();

    expect(fn () => $files['series']->down())->toThrow(RuntimeException::class, '1 chess team match(es) exist');
    $match->delete();
    $files['series']->down();

    expect(Schema::hasColumn('series_matches', 'boards'))->toBeFalse()
        ->and(Schema::hasTable('series_match_boards'))->toBeFalse()
        ->and(Schema::hasColumn('chess_games', 'series_match_id'))->toBeFalse();

    $files['series']->up();
    $files['boards']->up();
    $files['games']->up();

    expect(Schema::hasColumns('series_matches', ['boards', 'lineup_locked_at']))->toBeTrue()
        ->and(Schema::hasColumns('series_match_boards', ['series_match_id', 'side', 'user_id', 'board', 'rating', 'rating_pool', 'rating_results']))->toBeTrue()
        ->and(Schema::hasColumns('chess_games', ['series_match_id', 'board']))->toBeTrue();
});

test('from the lock a named player is refused by the casual 1v1 queue, its pairing, its invites and a challenge accept', function () {
    [$match, [$lineupA, $captainA], [$lineupB, $captainB]] = acceptedTeamMatch();
    $named = array_slice(activeIds($lineupA), 0, 2);
    $this->teamMatches->name($match, $captainA, $named);
    $this->teamMatches->name($match, $captainB, array_slice(activeIds($lineupB), 0, 2));
    $player = User::query()->findOrFail($named[1]);
    // He shows he is looking for a casual Rocket League 1v1, so anyone may invite him.
    $player->forceFill(['looking_to_play' => 'rocket-league/'.CasualMatches::mode()])->save();
    [$inviter, $challenger, $searcher, $free] = User::factory()->count(4)->create();

    // Sent before the lock: an invite to the player and a scheduled challenge of him.
    $invite = app(CasualInvites::class)->invite($inviter, $player, 'rocket-league', Platform::Pc, true);
    $times = [$match->start_at->copy()->addDay()->setTime(20, 0)->getTimestamp()];
    $challenge = app(CasualChallenges::class)->challenge($challenger, $player, 'rocket-league', Platform::Pc, true, $times, $match->start_at->copy()->addDay()->setTime(12, 0)->getTimestamp());

    travelToLock($match);
    $this->teamMatches->lock($match);
    // The invite is kept open across the jump to the lock (invites last minutes).
    $invite->forceFill(['expires_at' => now()->addMinutes(5)])->save();

    $reason = function (Closure $action): ?string {
        try {
            $action();
        } catch (SeriesRuleViolation $violation) {
            return $violation->reason;
        }

        return null;
    };
    $matches = SeriesMatch::query()->count();

    expect(app(CasualMatches::class)->busyReason($player))->toBe(ChessTeamMatches::RESERVED)
        ->and($reason(fn () => app(CasualQueue::class)->join($player, 'rocket-league', Platform::Pc)))->toBe(ChessTeamMatches::RESERVED)
        ->and($reason(fn () => app(CasualInvites::class)->invite($free, $player, 'rocket-league', Platform::Pc, true)))->toBe(ChessTeamMatches::OTHER_RESERVED)
        ->and($reason(fn () => app(CasualInvites::class)->accept($invite, $player, Platform::Pc, true)))->toBe(ChessTeamMatches::RESERVED)
        ->and($reason(fn () => app(CasualChallenges::class)->accept($challenge, $player, $times[0], Platform::Pc, true)))->toBe(ChessTeamMatches::RESERVED);

    // A search written before the lock (a race): the queue never pairs him, it drops his entry.
    SeriesQueueEntry::query()->create(['user_id' => $player->id, 'game' => 'rocket-league', 'mode' => '1v1', 'platform' => 'pc', 'crossplay' => true, 'joined_at' => now()->subMinute()]);

    expect(app(CasualQueue::class)->join($searcher, 'rocket-league', Platform::Pc))->toBeNull()
        ->and(SeriesQueueEntry::query()->where('user_id', $player->id)->exists())->toBeFalse()
        ->and(SeriesMatch::query()->count())->toBe($matches);
});

test('an accepted friendly of the same two clans in the window never blocks a rated team match', function () {
    $a = teamLineup();
    $b = teamLineup();
    // A friendly of the two clans, played inside the seven days.
    teamChallengeAndAccept($a, $b, rated: false)->forceFill(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Admin, 'winner' => 'challenger', 'finished_at' => now()])->save();

    expect(ChessTeamMatches::ratedPairBlocked($a[0]->id, $b[0]->id))->toBeFalse();

    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $rated = teamChallengeAndAccept($a, $b, rated: true);

    expect($rated->rated)->toBeTrue()
        ->and($rated->status)->toBe(SeriesStatus::Accepted);
});

/*
 * P5: start, board forfeit and void, team result, hashrate, attestations
 * and the live view (NIP rev. 9.22, "Start and board forfeit", "Team result").
 */

/**
 * An accepted team match whose captains named their players, locked.
 *
 * @return array{0: SeriesMatch, 1: array{0: Lineup, 1: User, 2: TestSigner}, 2: array{0: Lineup, 1: User, 2: TestSigner}}
 */
function lockedTeamMatch(bool $rated = false, int $boards = 2): array
{
    [$match, $a, $b] = acceptedTeamMatch($rated, $boards);
    $teamMatches = app(ChessTeamMatches::class);
    $teamMatches->name($match, $a[1], array_slice(activeIds($a[0]), 0, $boards));
    $teamMatches->name($match, $b[1], array_slice(activeIds($b[0]), 0, $boards));
    travelToLock($match);
    expect($teamMatches->lock($match))->toBe('locked');

    return [$match->refresh(), $a, $b];
}

/**
 * At the start: every board started, keyed by board.
 *
 * @return array<int, ChessGame>
 */
function startedBoards(SeriesMatch $match): array
{
    test()->travelTo($match->refresh()->start_at->copy()->addSecond());
    app(ChessTeamMatches::class)->startDue();

    return ChessGame::query()->with(['white', 'black'])->where('series_match_id', $match->id)->orderBy('board')->get()->keyBy('board')->all();
}

/** Play these moves in turn, White first; returns the game as it is now. */
function playMoves(ChessGame $game, array $ucis): ChessGame
{
    $service = app(ChessGameService::class);

    foreach ($ucis as $uci) {
        $game = $game->refresh()->loadMissing(['white', 'black']);
        $service->move($game, $game->turn() === 'w' ? $game->white : $game->black, $uci, $game->ply + 1);
    }

    return $game->refresh();
}

/** Two moves, then `$loser` ('w' or 'b') resigns: a rated result with a record. */
function decideBoard(ChessGame $game, string $loser): ChessGame
{
    $game = playMoves($game, ['e2e4', 'e7e5'])->loadMissing(['white', 'black']);
    app(ChessGameService::class)->resign($game, $loser === 'w' ? $game->white : $game->black);

    return $game->refresh();
}

function drawBoard(ChessGame $game): ChessGame
{
    $game = playMoves($game, ['e2e4', 'e7e5'])->loadMissing(['white', 'black']);
    app(ChessGameService::class)->offerDraw($game, $game->white);
    app(ChessGameService::class)->acceptDraw($game->refresh(), $game->black);

    return $game->refresh();
}

/** A rated team match needs a live season with the rapid ladder and trusted players. */
function ratedTeamSetup(): void
{
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
}

/** The attestation of a board as a signed event, or null. */
function boardAttestation(ChessGame $game): ?SignedEvent
{
    $row = SeasonAttestation::query()->where('source', SeasonAttestation::CHESS)->where('source_id', $game->id)->first();

    return $row === null ? null : SignedEvent::fromInput(NostrEvent::query()->findOrFail($row->nostr_event_id)->payload());
}

test('at the start each board starts once with its own first-move window, and a second tick or a second insert creates nothing', function () {
    [$match, [$lineupA], [$lineupB]] = lockedTeamMatch(boards: 2);
    $teamMatches = app(ChessTeamMatches::class);

    // Before the start nothing starts.
    $this->travelTo($match->start_at->copy()->subSecond());
    expect($teamMatches->startDue())->toBe(0);

    $this->travelTo($match->start_at->copy()->addSeconds(20));
    $this->artisan('teammatches:tick')->expectsOutputToContain('started 2 board(s)')->assertSuccessful();
    $this->artisan('teammatches:tick')->expectsOutputToContain('started 0 board(s)')->assertSuccessful();

    $games = ChessGame::query()->where('series_match_id', $match->id)->orderBy('board')->get();
    $player = fn (string $side, int $board) => SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', $side)->where('board', $board)->value('user_id');

    expect($games)->toHaveCount(2)
        ->and($teamMatches->startDue())->toBe(0)
        ->and($teamMatches->startBoard($match, 1))->toBeNull()
        ->and($games->pluck('board')->all())->toBe([1, 2])
        ->and($games->pluck('mode')->unique()->all())->toBe(['rapid'])
        ->and($games->pluck('number')->unique()->all())->toBe([null])
        ->and($games->pluck('rated')->unique()->all())->toBe([false])
        // The challenger has White on odd boards.
        ->and($games[0]->white_id)->toBe($player('challenger', 1))
        ->and($games[0]->black_id)->toBe($player('challenged', 1))
        ->and($games[1]->white_id)->toBe($player('challenged', 2))
        ->and($games[1]->black_id)->toBe($player('challenger', 2))
        // 600 s from the agreed start, not from the (late) tick.
        ->and($games[0]->first_move_seconds)->toBe(600)
        ->and($games[0]->deadline_ms)->toBe($match->start_at->getTimestampMs() + 600_000)
        ->and($games[0]->number())->toBe('#'.$match->number.'/1');

    // The backstop under the lock (a no-op on SQLite): the unique index refuses a second board 1.
    expect(fn () => DB::transaction(fn () => ChessGame::factory()->create(['mode' => 'rapid', 'series_match_id' => $match->id, 'board' => 1])))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a board starts for its reserved players and for one still in a live game, while every other game stays refused', function () {
    [$match, [$lineupA], [$lineupB]] = lockedTeamMatch(boards: 2);
    $busy = User::query()->findOrFail(SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', 'challenged')->where('board', 1)->value('user_id'));
    $free = User::factory()->create();
    // A live blitz game from before the lock.
    ChessGame::factory()->create(['white_id' => $busy->id, 'black_id' => $free->id, 'mode' => 'blitz']);

    expect(ChessTeamMatches::reservationOf($busy)?->id)->toBe($match->id);

    $games = startedBoards($match);
    $reason = function (Closure $action): ?string {
        try {
            $action();
        } catch (ChessRuleViolation $violation) {
            return $violation->reason;
        }

        return null;
    };

    expect($games)->toHaveCount(2)
        ->and($games[1]->black_id)->toBe($busy->id)
        // The reservation still refuses every game that is not a board of this team match.
        // (a daily game, which a running live game does not refuse on its own).
        ->and($reason(fn () => app(ChessGameService::class)->start($games[2]->white, User::factory()->create(), 'correspondence')))->toBe(ChessTeamMatches::RESERVED)
        ->and($reason(fn () => app(ChessGameService::class)->start(User::factory()->create(), $games[2]->black, 'correspondence')))->toBe(ChessTeamMatches::RESERVED);

    // The busy player misses the first move after White's: only that board is lost, by forfeit.
    $board = playMoves($games[1], ['e2e4']);
    $this->travelTo(now()->addSeconds(601));
    app(ChessGameService::class)->checkClock($board);

    expect($board->refresh()->end_reason)->toBe(ChessEndReason::Forfeit)
        ->and($board->result)->toBe('1-0')
        ->and($games[2]->refresh()->status)->not->toBe(ChessGameStatus::Finished);
});

test('a rated team match whose pinned ladder is no longer open starts casual boards, never rated without a ladder', function () {
    ratedTeamSetup();
    [$open] = lockedTeamMatch(rated: true);
    $rated = startedBoards($open);

    expect(collect($rated)->every(fn (ChessGame $game): bool => $game->rated && $game->ladder_address === Ladders::address('chess', 'rapid') && $game->gate_at_accept !== null))->toBeTrue();

    [$closed] = lockedTeamMatch(rated: true);
    $closed->forceFill(['ladder_address' => str_replace('season-1', 'season-0', (string) $closed->ladder_address)])->save();
    $casual = startedBoards($closed);

    expect($closed->rated)->toBeTrue()
        ->and(collect($casual)->every(fn (ChessGame $game): bool => ! $game->rated && $game->ladder_address === null && $game->gate_at_accept === null && $game->clans_at_accept === null))->toBeTrue();

    decideBoard($casual[1], 'b');
    expect(Rating::query()->where('mode', 'rapid')->where('pool', Rating::RATED)->count())->toBe(0);
});

test('a board forfeit counts one board point, is unrated and is attested with the team result', function () {
    ratedTeamSetup();
    [$match] = lockedTeamMatch(rated: true);
    $games = startedBoards($match);
    $service = app(ChessGameService::class);

    // Board 1: the challenger (White) never moves, the challenged Black opened the board.
    $service->markPresent($games[1], $games[1]->black);
    // Board 2: a draw.
    drawBoard($games[2]);

    expect(boardAttestation($games[2]->refresh()))->not->toBeNull();

    $this->travelTo($match->start_at->copy()->addSeconds(601));
    $service->checkClock($games[1]);
    $match->refresh();
    $forfeit = boardAttestation($games[1]->refresh());

    expect($games[1]->end_reason)->toBe(ChessEndReason::Forfeit)
        ->and($games[1]->result)->toBe('0-1')
        ->and(RatingChange::query()->where('source', RatingChange::CHESS)->where('source_id', $games[1]->id)->exists())->toBeFalse()
        // ½ : 1½, the challenged side wins on board points.
        ->and($match->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Admin)
        ->and($match->winner)->toBe('challenged')
        ->and($match->finished_at)->not->toBeNull()
        ->and(ChessTeamMatches::score(ChessTeamMatches::boardResults($match)))->toBe(['challenger' => 1, 'challenged' => 3])
        ->and($forfeit->tag('resolution'))->toBe('forfeit')
        ->and($forfeit->tag('winner'))->toBe('challenged')
        ->and($forfeit->tagsNamed('elo'))->toBe([])
        ->and($forfeit->tagsNamed('block'))->toBe([])
        ->and($forfeit->tag('match'))->toBe((string) $match->number)
        ->and(ChessTeamMatches::reservationOf($games[1]->white))->toBeNull();
});

test('a board where neither player moved is void and counts no point; the other boards decide', function () {
    ratedTeamSetup();
    [$match] = lockedTeamMatch(rated: true);
    $games = startedBoards($match);

    // Board 2 (the challenged side White): the challenger's Black resigns after two moves.
    decideBoard($games[2], 'b');
    // Board 1: nobody moves, Black never opens it.
    $this->travelTo($match->start_at->copy()->addSeconds(601));
    app(ChessGameService::class)->checkClock($games[1]);
    $match->refresh();
    $void = boardAttestation($games[1]->refresh());

    expect($games[1]->status)->toBe(ChessGameStatus::Aborted)
        ->and($match->winner)->toBe('challenged')
        ->and($match->resolution)->toBe(SeriesResolution::Admin)
        ->and(ChessTeamMatches::score(ChessTeamMatches::boardResults($match)))->toBe(['challenger' => 0, 'challenged' => 2])
        ->and($void->tag('resolution'))->toBe('void')
        ->and($void->tag('winner'))->toBe('none')
        ->and($void->tagsNamed('board')[0][3])->toBe('*')
        ->and($void->tagsNamed('elo'))->toBe([]);
});

test('a side that made no first move on any board loses the whole team match by forfeit, attested once and unrated', function () {
    ratedTeamSetup();
    [$match] = lockedTeamMatch(rated: true);
    $games = startedBoards($match);
    $service = app(ChessGameService::class);

    // Board 1: the challenger's White moves, the challenged Black never does. Board 2: the challenged White never
    // moves, the challenger's Black is there.
    playMoves($games[1], ['e2e4']);
    $service->markPresent($games[2], $games[2]->black);
    $this->travelTo($match->start_at->copy()->addSeconds(1200));
    $service->checkClock($games[1]);
    $service->checkClock($games[2]);
    $match->refresh();

    $attestation = SeasonAttestation::query()->where('source', SeasonAttestation::SERIES)->where('source_id', $match->id)->sole();
    $event = SignedEvent::fromInput(NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload());

    expect($match->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Forfeit)
        ->and($match->winner)->toBe('challenger')
        ->and($match->finished_at)->not->toBeNull()
        ->and($event->tag('resolution'))->toBe('forfeit')
        ->and($event->tagsNamed('elo'))->toBe([])
        ->and($event->tagsNamed('board'))->toBe([])
        // No board attestation: the whole team match is the one forfeit.
        ->and(SeasonAttestation::query()->where('source', SeasonAttestation::CHESS)->count())->toBe(0)
        ->and(app(ChessTeamMatches::class)->settle($match))->toBeNull();
});

test('a rated 2:1 team win adds the team bonus once, and every board is attested with the lineups, its board, the challenge number and its events', function () {
    ratedTeamSetup();
    [$match, [$lineupA], [$lineupB]] = lockedTeamMatch(rated: true, boards: 3);
    $games = startedBoards($match);

    // Board 1 and 3: the challenger plays White; board 2: Black.
    decideBoard($games[1], 'b');
    decideBoard($games[2], 'w');
    decideBoard($games[3], 'w');
    $match->refresh();
    $clanA = $lineupA->clan->address();
    $clanB = $lineupB->clan->address();
    $hashrate = app(ClanHashrate::class)->breakdown('season-1');

    expect($match->winner)->toBe('challenger')
        ->and($match->finished_at)->not->toBeNull()
        ->and(ChessTeamMatches::score(ChessTeamMatches::boardResults($match)))->toBe(['challenger' => 4, 'challenged' => 2])
        ->and($hashrate[$clanA]['teamWins'])->toBe(1)
        ->and($hashrate[$clanA]['bonus'])->toBe(5)
        ->and($hashrate[$clanB]['bonus'] ?? 0)->toBe(0)
        ->and($hashrate[$clanA]['points'])->toBe(2 * 3 + 1 * 1 + 5)
        ->and($hashrate[$clanB]['points'])->toBe(1 * 3 + 2 * 1);

    $board = $games[2]->refresh();
    $event = boardAttestation($board);
    $record = SignedEvent::fromInput(NostrEvent::query()->findOrFail($board->record_event_id)->payload());
    $challenger = User::query()->findOrFail(SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', 'challenger')->where('board', 2)->value('user_id'));
    $challenged = User::query()->findOrFail(SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', 'challenged')->where('board', 2)->value('user_id'));
    $ladder = Ladders::address('chess', 'rapid');

    // NIP rev. 9.22 "League Attestation", "Chess": e challenge, answer and record; the ladder and both lineups with
    // roles; the players by side with their lineup role; one board row; winner by side, not by colour.
    expect(array_slice($event->tags, 0, 10))->toBe([
        ['e', $match->challengeEvent->event_id, '', $match->challengeEvent->pubkey],
        ['e', $match->answerEvent->event_id, '', $match->answerEvent->pubkey],
        ['e', $record->id, '', $record->pubkey],
        ['a', $ladder, ''],
        ['a', $match->challenger_lineup_address, '', 'challenger'],
        ['a', $match->challenged_lineup_address, '', 'challenged'],
        ['p', $challenger->pubkey, '', 'challenger', 'player'],
        ['p', $challenged->pubkey, '', 'challenged', 'player'],
        ['board', '2', $challenged->pubkey, $challenger->pubkey, '0-1'],
        ['resolution', 'admin'],
    ])
        // Black won board 2, and Black is the challenger side there.
        ->and($event->tag('winner'))->toBe('challenger')
        ->and($event->tagsNamed('elo'))->toHaveCount(2)
        ->and($event->tag('match'))->toBe((string) $match->number)
        ->and(collect($event->tagsNamed('gate'))->pluck(0)->all())->toContain($challenger->pubkey, $challenged->pubkey)
        ->and(collect($event->tagsNamed('clan'))->mapWithKeys(fn (array $tag) => [$tag[0] => $tag[1]])->all())->toBe([$challenged->pubkey => $clanB, $challenger->pubkey => $clanA])
        ->and(SeasonAttestation::query()->where('source', SeasonAttestation::CHESS)->where('source_id', $board->id)->value('board'))->toBe(2)
        // NIP "Game Record": e challenge, the challenge's a (both lineups, the ladder), the board, the players.
        ->and(array_slice($record->tags, 0, 7))->toBe([
            ['e', $match->challengeEvent->event_id, '', $match->challengeEvent->pubkey],
            ['a', $match->challenger_lineup_address, ''],
            ['a', $match->challenged_lineup_address, ''],
            ['a', $ladder, ''],
            ['board', '2'],
            ['p', $challenged->pubkey, '', 'white'],
            ['p', $challenger->pubkey, '', 'black'],
        ])
        // No team result event: three board attestations and nothing for the series.
        ->and(SeasonAttestation::query()->where('source', SeasonAttestation::CHESS)->count())->toBe(3)
        ->and(SeasonAttestation::query()->where('source', SeasonAttestation::SERIES)->count())->toBe(0);
});

test('a 1½:1½ team draw has no winner and no bonus, each clan keeps its board points, and the result time is set', function () {
    ratedTeamSetup();
    [$match, [$lineupA], [$lineupB]] = lockedTeamMatch(rated: true, boards: 3);
    $games = startedBoards($match);

    decideBoard($games[1], 'b');
    drawBoard($games[2]);
    decideBoard($games[3], 'w');
    $match->refresh();
    $hashrate = app(ClanHashrate::class)->breakdown('season-1');
    $clanA = $lineupA->clan->address();
    $clanB = $lineupB->clan->address();

    expect($match->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Admin)
        ->and($match->winner)->toBe('none')
        ->and($match->finished_at)->not->toBeNull()
        ->and(ChessTeamMatches::points(ChessTeamMatches::score(ChessTeamMatches::boardResults($match))['challenger']))->toBe('1½')
        ->and($hashrate[$clanA]['bonus'])->toBe(0)
        ->and($hashrate[$clanB]['bonus'])->toBe(0)
        ->and($hashrate[$clanA]['teamWins'])->toBe(0)
        ->and($hashrate[$clanA]['points'])->toBe(3 + 2 + 1)
        ->and($hashrate[$clanB]['points'])->toBe(1 + 2 + 3);
});

test('a friendly team match is decided on board points and earns no hashrate', function () {
    openSeason(['slug' => 'season-1']);
    [$match] = lockedTeamMatch(rated: false);
    $games = startedBoards($match);
    $events = NostrEvent::query()->count();

    decideBoard($games[1], 'b');
    decideBoard($games[2], 'w');

    expect($match->refresh()->winner)->toBe('challenger')
        ->and($match->rated)->toBeFalse()
        ->and(app(ClanHashrate::class)->breakdown('season-1'))->toBe([])
        // No record, no attestation: a friendly is league data.
        ->and(NostrEvent::query()->count())->toBe($events);
});

test('the team match page shows every board with its clocks and the team score, live for guests, and answers a roundtrip', function () {
    [$match] = lockedTeamMatch(boards: 2);

    // After the lock, before the start: the boards with their players, no game yet.
    $this->get(route('matches.show', $match))->assertOk()
        ->assertSee('Team match #'.$match->number)
        ->assertSeeHtml('data-test="team-match-boards"')
        ->assertSeeHtml('data-test="team-board-2"')
        ->assertDontSeeHtml('data-test="flow"')
        // The websocket is on for guests on this page.
        ->assertSeeHtml('name="alert-settings"');

    $games = startedBoards($match);
    playMoves($games[1], ['e2e4', 'e7e5']);
    decideBoard($games[2], 'w');

    Livewire::test('team-match-boards', ['match' => $match])
        ->assertOk()
        ->assertSeeHtml('data-test="team-board-clock-1-w"')
        ->assertSeeHtml('data-game="'.$games[1]->id.'"')
        ->assertSeeHtml('href="'.route('games.show', $games[1]).'"')
        ->assertSeeInOrder([$match->sideName('challenger'), '1 : 0', $match->sideName('challenged')])
        ->call('$refresh')->assertOk();

    $this->get(route('matches.show', $match))->assertOk()->assertSee('Live');
    $this->get(route('games.show', $games[1]))->assertOk()->assertSee('#'.$match->number.'/1');
});
