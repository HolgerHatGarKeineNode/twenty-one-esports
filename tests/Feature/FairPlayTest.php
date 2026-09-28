<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\PayoutStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\AccountLink;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\FairPlayVoid;
use App\Models\FalseReport;
use App\Models\Lineup;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Chess\RatedChess;
use App\Support\FairPlay\AccountLinks;
use App\Support\FairPlay\FairPlay;
use App\Support\FairPlay\FairPlayRefused;
use App\Support\LeagueTime;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutPlan;
use App\Support\Payouts\PayoutRunner;
use App\Support\Rating\EloRating;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Fair play (P41)
|--------------------------------------------------------------------------
|
| Multi-accounts: an admin links the accounts of one player to the main
| account. The others lose rated play (the trust gate leaves them out, a
| captain among them refuses with a message) and prizes (the plan skips
| them, unpaid payouts are withheld, never re-routed); results between the
| accounts are voided through the rating revert path. Unlinking restores
| rated play and prizes; voided results stay void.
|
| False reports: an admin rules a disputed report false, the reporting side
| loses by forfeit and the reporter gets a confirmed false report; two
| within 30 days lock rated play for 7 days from the last one.
|
*/

beforeEach(function () {
    Queue::fake();
});

function fpAdmin(): User
{
    $admin = User::factory()->create(['name' => 'Referee']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** Rated chess open: a live season and every player Trusted and connected. */
function fpRated(): void
{
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
}

function fpChessRating(User $user): ?Rating
{
    return Rating::query()->where(['pool' => Rating::RATED, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id])->first();
}

/** A rated blitz game `$white` won, rated through the service. */
function fpRatedWin(User $white, User $black): ChessGame
{
    $game = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $white->id, 'black_id' => $black->id]);
    app(RatingService::class)->applyChessGame($game);

    return $game->refresh();
}

/**
 * A ready 3v3 lineup whose clan owner (the captain) holds a real key.
 *
 * @return array{0: Lineup, 1: User, 2: TestSigner}
 */
function fpLineup(): array
{
    $signer = new TestSigner;
    $captain = User::factory()->withPubkey($signer->pubkey)->create();
    $lineup = Lineup::factory()->game('rocket-league', '3v3')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id])->id]);

    return [$lineup->load('clan', 'seats.user'), $captain, $signer];
}

/**
 * A running players-mode RL 1v1 tournament match between two keyed solo
 * players, rated while a season is open: its series, challenger first.
 *
 * @return array{0: SeriesMatch, 1: array{0: User, 1: TestSigner}, 2: array{0: User, 1: TestSigner}}
 */
function fpDuel(): array
{
    $tournament = Tournament::factory()->rocketLeague()->create([
        'mode' => '1v1', 'options' => FormatOptions::defaults(GameProfile::for('rocket-league', '1v1'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'created_by_id' => organizer()->id,
    ]);
    $tournament = app(TournamentPublisher::class)->publish($tournament, $tournament->creator, CarbonImmutable::now()->addHour());
    $tournament->forceFill(['status' => TournamentStatus::Running])->save();
    $sides = [keyedPlayer(), keyedPlayer()];

    foreach ($sides as $index => [$player]) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $player->displayName(), 'rating' => 1100 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('9a', 32));
    app(TournamentRunner::class)->sync($tournament);
    $series = SeriesMatch::query()->where('tournament_match_id', $tournament->matches()->value('id'))->sole();
    $challenger = $series->rosterSide('challenger')[0] === $sides[0][0]->id ? $sides[0] : $sides[1];

    return [$series, $challenger, $challenger === $sides[0] ? $sides[1] : $sides[0]];
}

/** `$winner` reports a clean win, signed, and `$other` confirms it, signed. */
function fpPlay(SeriesMatch $series, array $winner, array $other): SeriesMatch
{
    $service = app(SeriesService::class);
    $side = $series->refresh()->captainSideOf($winner[0]);

    foreach (range(0, intdiv($series->best_of, 2)) as $index) {
        $service->saveLiveGame($series, $winner[0], $index, $side === 'challenger' ? 3 : 1, $side === 'challenger' ? 1 : 3, null);
    }

    $service->report($series, $winner[0], $winner[1]->signTemplates($service->prepareReport($series, $winner[0])));
    $service->respond($series->refresh(), $other[0], 'confirmed', '', $other[1]->signTemplates($service->prepareResponse($series->refresh(), $other[0], 'confirmed', '')));

    return $series->refresh();
}

/**
 * A casual 3v3 series reported by A's captain and disputed by B's.
 *
 * @return array{0: SeriesMatch, 1: User, 2: User}
 */
function fpDisputed(): array
{
    $service = app(SeriesService::class);
    [$lineupA, $captainA, $signerA] = fpLineup();
    [$lineupB, $captainB, $signerB] = fpLineup();
    $start = now()->addHour()->startOfMinute()->getTimestamp();
    $draft = new ChallengeDraft($lineupA->id, $lineupB->id, 3, false, [$start, $start + 3600], $start - 600, 'gl hf');
    $match = $service->challenge($captainA, $draft, $signerA->signTemplates($service->prepareChallenge($captainA, $draft)['templates']));
    $service->answer($match, $captainB, 'accepted', $start, $signerB->signTemplates($service->prepareAnswer($match, $captainB, 'accepted', $start)));
    test()->travelTo(now()->setTimestamp($start)->addMinutes(40));

    foreach ([[3, 1], [2, 1]] as $index => [$a, $b]) {
        $service->saveLiveGame($match, $captainA, $index, $a, $b, null);
    }

    $service->report($match, $captainA, []);
    $service->respond($match->refresh(), $captainB, 'disputed', 'We won game 2.', []);

    return [$match->refresh(), $captainA, $captainB];
}

/** A confirmed false report of this player, decided `$daysAgo` days ago. */
function fpFalseReport(User $player, float $daysAgo): FalseReport
{
    return FalseReport::query()->create(['user_id' => $player->id, 'pubkey' => $player->pubkey, 'created_at' => now()->subSeconds((int) round($daysAgo * 86400))]);
}

/* ---------- Authorization -------------------------------------------------------------------------------------- */

test('only admins reach the page and the action, and no admin decides about his own accounts', function () {
    [$main, $second, $player] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
    $admin = fpAdmin();

    $this->actingAs($player)->get(route('admin.fair-play'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.fair-play'))->assertOk()->assertSee('data-test="admin-fair-play"', false)->assertSee(__('No accounts are linked.'));

    expect(fn () => app(AccountLinks::class)->link($player, $main->pubkey, $second->pubkey, 'Same person'))->toThrow(FairPlayRefused::class, 'Only admins')
        ->and(fn () => app(AccountLinks::class)->link($admin, $main->pubkey, $admin->pubkey, 'My old key'))->toThrow(FairPlayRefused::class, 'your own accounts')
        ->and(fn () => app(AccountLinks::class)->link($admin, $admin->pubkey, $second->pubkey, 'My old key'))->toThrow(FairPlayRefused::class, 'your own accounts')
        ->and(fn () => app(AccountLinks::class)->link($admin, $main->pubkey, $second->pubkey, '   '))->toThrow(FairPlayRefused::class, 'Give a reason')
        ->and(fn () => app(AccountLinks::class)->link($admin, $main->pubkey, $main->pubkey, 'Same'))->toThrow(FairPlayRefused::class, 'two different')
        ->and(AccountLink::query()->count())->toBe(0);

    // A player without the admin gate cannot call the page's action either.
    Livewire::actingAs($player)->test('pages::admin.fair-play')->assertForbidden();
});

test('a link cannot chain: an account linked already, or a main account of others, is refused with the way out', function () {
    [$main, $second, $third] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
    $admin = fpAdmin();
    $links = app(AccountLinks::class);
    $links->link($admin, $main->pubkey, $second->pubkey, 'Same device, same Lightning address');

    expect(fn () => $links->link($admin, $third->pubkey, $second->pubkey, 'x'))->toThrow(FairPlayRefused::class, 'linked already')
        ->and(fn () => $links->link($admin, $third->pubkey, $main->pubkey, 'x'))->toThrow(FairPlayRefused::class, 'main account of other accounts')
        ->and(fn () => $links->link($admin, $second->pubkey, $third->pubkey, 'x'))->toThrow(FairPlayRefused::class, 'itself linked')
        ->and(fn () => $links->link($admin, $main->npub, 'npub1nonsense', 'x'))->toThrow(FairPlayRefused::class, 'npub');

    // An npub works as well as hex; a second account of the same main is fine.
    $links->link($admin, $main->npub, $third->npub, 'Also his');

    expect(AccountLink::query()->active()->pluck('linked_user_id')->all())->toBe([$second->id, $third->id]);
});

/* ---------- Rated eligibility ---------------------------------------------------------------------------------- */

test('a linked account is left out of every rated pin; as a gatekeeper it refuses with a message naming the main account', function () {
    fpRated();
    $main = User::factory()->create(['name' => 'Satoshi']);
    [$second, $other] = [User::factory()->create(['name' => 'Nakamoto']), User::factory()->create()];
    $gate = app(RatedTrustGate::class);

    expect($gate->refusal([$second->pubkey, $other->pubkey], [$second->pubkey, $other->pubkey]))->toBeNull();

    app(AccountLinks::class)->link(fpAdmin(), $main->pubkey, $second->pubkey, 'Same person');
    $pin = $gate->pin([$second->pubkey, $other->pubkey, $main->pubkey], [$main->pubkey, $other->pubkey]);

    expect($gate->refusal([$second->pubkey, $other->pubkey], [$second->pubkey, $other->pubkey]))->toBe(RatedTrustGate::FAIR_PLAY)
        ->and($pin->isEligible($second->pubkey))->toBeFalse()
        // The main account and everybody else play on.
        ->and($pin->isEligible($main->pubkey))->toBeTrue()
        ->and($pin->isEligible($other->pubkey))->toBeTrue()
        ->and($pin->refusal())->toBeNull();

    $this->actingAs($second);
    expect(RatedTrustGate::message(RatedTrustGate::FAIR_PLAY, [$other->pubkey, $second->pubkey]))
        ->toBe('An admin linked this account to your main account Satoshi. Only the main account plays rated matches and wins prizes.');

    $this->actingAs($other);
    expect(RatedTrustGate::message(RatedTrustGate::FAIR_PLAY, [$second->pubkey]))->toBe('Nakamoto is a second account of another player and plays no rated matches.');
});

test('a linked captain cannot send a rated challenge; the refusal says why, and casual stays open', function () {
    fpRated();
    [$lineupA, $captainA] = fpLineup();
    [$lineupB] = fpLineup();
    $start = now()->addHour()->startOfMinute()->getTimestamp();
    $draft = fn (bool $rated) => new ChallengeDraft($lineupA->id, $lineupB->id, 3, $rated, [$start, $start + 3600], $start - 600, null);
    app(AccountLinks::class)->link(fpAdmin(), User::factory()->create(['name' => 'Main'])->pubkey, $captainA->pubkey, 'Same person');
    $this->actingAs($captainA);

    expect(fn () => app(SeriesService::class)->prepareChallenge($captainA, $draft(true)))
        ->toThrow(SeriesRuleViolation::class, 'An admin linked this account to your main account Main.')
        ->and(app(SeriesService::class)->prepareChallenge($captainA, $draft(false))['templates'])->toBe([]);

    $this->get(route('challenges.create'))->assertOk()->assertSee('data-test="rated-fair-play"', false);
});

/* ---------- Results between the accounts ----------------------------------------------------------------------- */

test('linking voids a rated chess game between the accounts through the revert path, and the Elo is as if never played', function () {
    fpRated();
    [$main, $second, $stranger] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
    $between = fpRatedWin($second, $main);
    $kept = fpRatedWin($main, $stranger);
    $engine = EloRating::fromConfig('rating');
    $strangerBefore = fpChessRating($stranger)->rating;

    expect(fpChessRating($second)->rating)->toBeGreaterThan($engine->start);

    $done = app(AccountLinks::class)->link(fpAdmin(), $main->pubkey, $second->pubkey, 'Same person');
    $void = FairPlayVoid::query()->sole();
    $between->refresh();

    expect($done['voided'])->toBe(1)
        ->and([$between->status, $between->result, $between->end_reason])->toBe([ChessGameStatus::Aborted, null, ChessEndReason::Voided])
        ->and($void->only(['source', 'source_id', 'match_number']))->toBe(['source' => 'chess', 'source_id' => $between->id, 'match_number' => $between->number])
        ->and($void->previous)->toBe(['status' => 'finished', 'result' => '1-0', 'end_reason' => 'resignation'])
        ->and($void->elo['applied'])->toBeNull()
        // The second account is back at the start; the main account keeps only its game against the stranger.
        ->and(fpChessRating($second)->only(['rating', 'results', 'wins']))->toBe(['rating' => $engine->start, 'results' => 0, 'wins' => 0])
        ->and(fpChessRating($main)->results)->toBe(1)
        // Delta only: a later result keeps the delta it was rated with.
        ->and(fpChessRating($stranger)->rating)->toBe($strangerBefore)
        ->and($kept->refresh()->result)->toBe('1-0')
        // The audit trail: the reverted rows stay.
        ->and(RatingChange::query()->withoutGlobalScope(RatingChange::LIVE)->where('source_id', $between->id)->whereNotNull('reverted_at')->count())->toBe(2);

    // A later result between them rates nothing, rated or casual.
    $again = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $second->id, 'black_id' => $main->id]);
    $casual = ChessGame::factory()->finished('0-1')->create(['white_id' => $main->id, 'black_id' => $second->id]);

    expect(app(RatingService::class)->applyChessGame($again))->toBeFalse()
        ->and(app(RatingService::class)->applyChessGame($casual))->toBeFalse();
});

test('linking voids a rated tournament series between the accounts: void, Elo reverted, the tournament match marked', function () {
    fpRated();
    [$series, $a, $b] = fpDuel();
    $series = fpPlay($series, $a, $b);
    $engine = EloRating::fromConfig('rating');
    $won = $engine->rate($engine->start, $engine->start, 1.0, 0, 0);
    $rating = fn (User $user): Rating => Rating::query()->where(['pool' => Rating::RATED, 'game' => 'rocket-league', 'mode' => '1v1', 'subject' => 'user:'.$user->id])->sole();
    $admin = fpAdmin();

    expect($rating($a[0])->rating)->toBe($won['challenger']);

    app(AccountLinks::class)->link($admin, $b[0]->pubkey, $a[0]->pubkey, 'The winner is the loser’s second account');
    $series->refresh();

    expect([$series->status, $series->resolution, $series->winner, $series->resolved_by_id])->toBe([SeriesStatus::Resolved, SeriesResolution::Void, 'none', $admin->id])
        ->and($series->resolution_reason)->toBe(AccountLinks::VOID_REASON)
        ->and([$rating($a[0])->rating, $rating($b[0])->rating, $rating($a[0])->results])->toBe([$engine->start, $engine->start, 0])
        ->and(FairPlayVoid::query()->sole()->elo)->toBe(['reverted' => [$won['challenger_delta'], $won['challenged_delta']], 'applied' => null])
        ->and($series->tournamentMatch->result)->toMatchArray(['void' => 'linked_accounts', 'unrated' => true]);
});

/* ---------- Prizes --------------------------------------------------------------------------------------------- */

test('payout planning skips a linked account: its share stays in the pot, nobody else gets more', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 100_000);
    $plan = app(PayoutPlan::class)->compute($tournament, 99_000);
    $winner = $plan['rows'][0]['user'];

    app(AccountLinks::class)->link(fpAdmin(), User::factory()->create()->pubkey, $winner->pubkey, 'Same person');
    $skipped = app(PayoutPlan::class)->compute($tournament, 99_000);

    expect(collect($skipped['rows'])->pluck('user.id')->all())->not->toContain($winner->id)
        ->and(collect($skipped['rows'])->map(fn (array $row): array => [$row['place'], $row['amount']])->all())->toBe([[2, 29_700], [3, 9_900], [3, 9_900]])
        ->and($skipped['remainder'])->toBe($plan['remainder'] + 49_500);

    app(PayoutApproval::class)->approve($tournament, anAdmin());

    expect($tournament->payouts()->where('pubkey', $winner->pubkey)->exists())->toBeFalse()
        ->and($tournament->payouts()->count())->toBe(3);
});

test('an unpaid prize of an account linked after the approval is withheld, never paid, and comes back on unlink', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 100_000);
    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $payout = $tournament->payouts()->where('place', 1)->sole();
    $admin = fpAdmin();

    $done = app(AccountLinks::class)->link($admin, User::factory()->create()->pubkey, $payout->pubkey, 'Same person');
    app(PayoutRunner::class)->run($payout, true);

    expect($done['withheld'])->toBe(1)
        ->and($payout->refresh()->only(['status', 'reason']))->toBe(['status' => PayoutStatus::Open, 'reason' => AccountLinks::WITHHELD])
        ->and($payout->reasonText())->toContain('Withheld')
        ->and($wallet->paid)->toBe([])
        ->and(fn () => app(PayoutApproval::class)->approveAddress($payout, $admin, (string) $payout->lud16))->toThrow(TournamentRuleViolation::class, 'withheld');

    // The admin page shows it withheld, without an approve button.
    $this->actingAs($admin)->get(route('admin.payouts', ['tournament' => $tournament->id]))->assertOk()
        ->assertSee(__('Withheld: an admin linked this account to another account of the same player. Only the main account wins prizes; an admin reviews it.'));

    // A payout that was pending again while linked (a race with the link) is refused by the runner too.
    $payout->forceFill(['status' => PayoutStatus::Pending, 'reason' => null])->save();
    app(PayoutRunner::class)->run($payout, true);
    expect($payout->refresh()->status)->toBe(PayoutStatus::Open)->and($wallet->paid)->toBe([]);

    $released = app(AccountLinks::class)->unlink($admin, AccountLink::query()->sole(), 'Two siblings, not one person');
    app(PayoutRunner::class)->run($payout->refresh(), true);

    expect($released)->toBe(1)
        ->and($payout->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($wallet->paid)->toHaveCount(1);
});

/* ---------- Unlink --------------------------------------------------------------------------------------------- */

test('unlinking restores rated play, keeps voided results void and keeps the whole record', function () {
    fpRated();
    [$main, $second] = [User::factory()->create(), User::factory()->create()];
    $game = fpRatedWin($second, $main);
    $admin = fpAdmin();
    $other = fpAdmin();
    app(AccountLinks::class)->link($admin, $main->pubkey, $second->pubkey, 'Same person');
    $link = AccountLink::query()->sole();

    expect(FairPlay::isBarred($second->pubkey))->toBeTrue();

    app(AccountLinks::class)->unlink($other, $link, 'Siblings, checked on a call');
    $link->refresh();

    expect(FairPlay::isBarred($second->pubkey))->toBeFalse()
        ->and(app(RatedTrustGate::class)->refusal([$second->pubkey, $main->pubkey], [$second->pubkey, $main->pubkey]))->toBeNull()
        ->and($game->refresh()->end_reason)->toBe(ChessEndReason::Voided)
        ->and(fpChessRating($second)->results)->toBe(0)
        ->and($link->only(['linked_by_id', 'reason', 'unlinked_by_id', 'unlink_reason']))->toBe(['linked_by_id' => $admin->id, 'reason' => 'Same person', 'unlinked_by_id' => $other->id, 'unlink_reason' => 'Siblings, checked on a call'])
        ->and($link->unlinked_at)->not->toBeNull()
        ->and(fn () => app(AccountLinks::class)->unlink($other, $link, 'again'))->toThrow(FairPlayRefused::class, 'undone already');

    // Linking again is a new row; the old one stays.
    app(AccountLinks::class)->link($admin, $main->pubkey, $second->pubkey, 'It was him after all');
    expect(AccountLink::query()->count())->toBe(2)->and(AccountLink::query()->active()->count())->toBe(1);
});

test('the admin page links and unlinks with a reason and reports what the link did', function () {
    [$main, $second] = [User::factory()->create(['name' => 'Mainly']), User::factory()->create(['name' => 'Alty'])];
    $admin = fpAdmin();
    ChessGame::factory()->finished('1-0')->create(['white_id' => $second->id, 'black_id' => $main->id]);

    $page = Livewire::actingAs($admin)->test('pages::admin.fair-play')
        ->set('main', $main->pubkey)->set('linked', $second->pubkey)->call('link')
        ->assertSee(__('Give a reason, up to :max characters.', ['max' => 280]))
        ->set('reason', 'Same Lightning address')->call('link')
        ->assertSet('notice', 'Linked. Results voided between the accounts: 1. Prizes withheld: 0.')
        ->assertSee('Alty')->assertSee('Same Lightning address')->assertSee('1 result voided');

    $link = AccountLink::query()->sole();
    $page->call('unlink', $link->id)->assertSee(__('Give a reason, up to :max characters.', ['max' => 280]))
        ->set('reason', 'Wrong call')->call('unlink', $link->id)
        ->assertSet('notice', 'Unlinked. Rated play and prizes are back; prizes released: 0. Voided results stay void.')
        ->assertSee(__('No accounts are linked.'))->assertSee('Wrong call')
        ->call('$refresh')->assertOk();
});

/* ---------- False reports -------------------------------------------------------------------------------------- */

test('an admin rules a disputed report false: the reporting side loses by forfeit and the reporter gets a confirmed false report', function () {
    [$match, $reporter, $disputer] = fpDisputed();
    $report = $match->latestReport;
    $admin = fpAdmin();

    $this->actingAs($admin)->get(route('admin.disputes.show', $match))->assertOk()->assertSee('data-test="decide-false-report"', false);

    app(SeriesService::class)->decide($match, $admin, ['type' => 'false_report', 'report' => $report->id], 'The end screen shows game 2 lost.');
    $match->refresh();
    $record = FalseReport::query()->sole();

    expect([$match->status, $match->resolution, $match->winner])->toBe([SeriesStatus::Resolved, SeriesResolution::Forfeit, 'challenged'])
        ->and($record->only(['user_id', 'pubkey', 'series_match_id', 'series_report_id', 'decided_by_id']))
        ->toBe(['user_id' => $reporter->id, 'pubkey' => $reporter->pubkey, 'series_match_id' => $match->id, 'series_report_id' => $report->id, 'decided_by_id' => $admin->id])
        // One false report is not a lock.
        ->and(FairPlay::lockedUntil($reporter->pubkey))->toBeNull()
        ->and(FairPlay::lockedUntil($disputer->pubkey))->toBeNull();
});

test('only a report the other captain disputed can be ruled false, while the match is disputed', function () {
    [$match] = fpDisputed();
    $admin = fpAdmin();
    app(SeriesService::class)->decide($match, $admin, ['type' => 'void'], 'Nobody can tell.');

    expect(fn () => app(SeriesService::class)->decide($match->refresh(), $admin, ['type' => 'false_report', 'report' => $match->latestReport->id], 'x'))
        ->toThrow(SeriesRuleViolation::class)
        ->and(FalseReport::query()->count())->toBe(0);
});

test('two confirmed false reports within 30 days lock rated play for 7 days from the last; further apart they do not', function () {
    $this->freezeSecond();
    [$apart, $close, $edge] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];

    // 34 days apart: the second one alone does not lock, recent as it is.
    fpFalseReport($apart, 35);
    fpFalseReport($apart, 1);
    fpFalseReport($close, 20);
    $last = fpFalseReport($close, 2);
    // Exactly 30 days apart still counts; the lock runs from the last one.
    fpFalseReport($edge, 36);
    fpFalseReport($edge, 6);

    expect(FairPlay::lockedUntil($apart->pubkey))->toBeNull()
        ->and(FairPlay::lockedUntil($close->pubkey)?->equalTo(CarbonImmutable::instance($last->created_at)->addDays(7)))->toBeTrue()
        ->and(FairPlay::lockedUntil($edge->pubkey)?->equalTo(now()->addDay()))->toBeTrue();

    // The lock ends after 7 days.
    $this->travel(5)->days();
    expect(FairPlay::lockedUntil($close->pubkey))->toBeNull()
        ->and(FairPlay::lockedUntil($edge->pubkey))->toBeNull();
});

test('the numbers come from the config: 3 reports in 10 days lock for 2 days', function () {
    $this->freezeSecond();
    config(['esports.fair_play' => ['false_reports' => 3, 'window_days' => 10, 'lock_days' => 2]]);
    $player = User::factory()->create();
    fpFalseReport($player, 9);
    fpFalseReport($player, 1);

    expect(FairPlay::lockedUntil($player->pubkey))->toBeNull();

    fpFalseReport($player, 0.5);

    expect(FairPlay::lockedUntil($player->pubkey)?->equalTo(now()->subHours(12)->addDays(2)))->toBeTrue();
});

test('a locked player sees the end of the lock where rated play is refused: chess lobby, rated challenge, the gate', function () {
    $this->freezeSecond();
    fpRated();
    config(['esports.chess.rated_queue' => true]);
    [$lineupA, $captainA] = fpLineup();
    [$lineupB] = fpLineup();
    fpFalseReport($captainA, 10);
    $last = fpFalseReport($captainA, 1);
    $until = LeagueTime::stamp(CarbonImmutable::instance($last->created_at)->addDays(7));
    $this->actingAs($captainA);
    $own = 'You cannot play rated until '.$until.': 2 false result reports were confirmed within 30 days. Casual play stays open.';
    $start = now()->addHour()->startOfMinute()->getTimestamp();

    expect(app(RatedChess::class)->refusal($captainA, 'blitz'))->toBe($own)
        ->and(fn () => app(SeriesService::class)->prepareChallenge($captainA, new ChallengeDraft($lineupA->id, $lineupB->id, 3, true, [$start], $start - 600, null)))
        ->toThrow(SeriesRuleViolation::class, $own)
        ->and(app(RatedTrustGate::class)->refusal([$captainA->pubkey], [$captainA->pubkey, $lineupB->clan->owner->pubkey]))->toBe(RatedTrustGate::FAIR_PLAY);

    $this->get(route('chess.lobby'))->assertOk()->assertSee($own);

    $this->actingAs(User::factory()->create());
    expect(FairPlay::message($captainA->pubkey))->toBe($captainA->displayName().' cannot play rated until '.$until.' after confirmed false result reports.');
});

/* ---------- The rules ------------------------------------------------------------------------------------------ */

test('the rules state both fair-play rules with the numbers in force', function () {
    config(['esports.fair_play' => ['false_reports' => 3, 'window_days' => 14, 'lock_days' => 5]]);

    $this->get(route('rules'))->assertOk()
        ->assertSee('3 within 14 days')
        ->assertSee('5 days')
        ->assertSee('3 confirmed false reports within 14 days mean no rated play for 5 days from the last one.')
        ->assertSee('If an admin rules a disputed result report false, the reporting side loses the match')
        ->assertSee('only the main account plays rated matches and wins prizes, and results between the accounts are void');

    $this->get(route('rules', ['lang' => 'de']))->assertOk()->assertSee('Ein Spieler, ein Konto');
});
