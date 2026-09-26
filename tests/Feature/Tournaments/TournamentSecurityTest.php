<?php

use App\Enums\ClanRole;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanDeparture;
use App\Models\ClanMember;
use App\Models\NostrEvent;
use App\Models\RatingChange;
use App\Models\RelayDelivery;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentOrganizer;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\NoTrustFacts;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Tournaments\TournamentDraws;
use App\Support\Tournaments\TournamentMatchMaker;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Security gate of P8b
|--------------------------------------------------------------------------
|
| Interested directors (players, clan members, and whoever they appointed)
| cannot enter a match; director forfeits move no Elo; sign-up consents
| never reach a relay; one broken tournament does not stall the others;
| the draw waits for a block mined after its commitment, with confirmations;
| a tournament match has at most one series and one game per replay.
|
*/

beforeEach(function () {
    Queue::fake();
});

test('an organizer who plays cannot have the alt he appointed enter his win; an admin can', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $match = TournamentMatch::query()->where('status', 'ready')->with('slots.participant')->orderBy('id')->first();
    $organizer = User::query()->findOrFail($match->slots[0]->participant->user_id);
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);
    $tournament->forceFill(['created_by_id' => $organizer->id])->save();
    $alt = User::factory()->create();

    Livewire::actingAs($organizer)->test('pages::tournaments.director', ['tournament' => $tournament->refresh()])
        ->set('directorKey', $alt->npub)->call('addDirector')->assertHasNoErrors();

    $runner = app(TournamentRunner::class);

    expect(fn () => $runner->enterResult($match, $alt, ['result' => '1-0']))->toThrow(TournamentRuleViolation::class, 'interest')
        ->and(fn () => $runner->enterResult($match, $organizer, ['result' => '1-0']))->toThrow(TournamentRuleViolation::class)
        ->and($match->refresh()->result)->toBeNull();

    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $runner->enterResult($match, $admin, ['result' => '0-1']);

    expect($match->refresh()->result['user_id'])->toBe($admin->id);
});

test('a clan owner who is not on the entered lineup cannot enter that lineup\'s series', function () {
    [$tournament, [$lineup]] = directedSeries();
    $owner = $lineup->clan->owner;
    $participant = TournamentParticipant::query()->where('lineup_id', $lineup->id)->sole();
    $participant->forceFill(['members' => array_values(array_diff($participant->members, [$owner->id]))])->save();
    $tournament->directors()->attach($owner->id);

    expect(fn () => app(TournamentRunner::class)->enterResult(TournamentMatch::query()->sole(), $owner, ['games' => [[3, 1], [3, 1], [3, 1]]]))
        ->toThrow(TournamentRuleViolation::class, 'interest');
});

test('a no-show a director enters moves no Elo, in chess and in a series', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2);
    $runner = app(TournamentRunner::class);
    $runner->enterResult(TournamentMatch::query()->sole(), $tournament->creator, ['result' => 'noshow-1']);
    $runner->closeRound(TournamentRunner::currentRound($tournament), $tournament->creator);

    [$series] = directedSeries();
    $runner->enterResult(TournamentMatch::query()->where('tournament_id', $series->id)->sole(), $series->creator, ['noshow' => 0]);
    $runner->closeRound(TournamentRunner::currentRound($series), $series->creator);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and($series->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(RatingChange::query()->count())->toBe(0);
});

test('sign-up consents are never sent to a relay, not even by the republish sweep', function () {
    // Not a websocket URL: every send is recorded as a failed delivery at once, without a network.
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.relays' => ['http://relay.test']]);
    $tournament = openTournament();
    [$player, $signer] = keyedPlayer();
    soloSignup($tournament, $player, $signer);

    $this->travel(2)->minutes();
    $this->artisan('nostr:republish')->assertSuccessful();

    $delivered = RelayDelivery::query()->with('nostrEvent')->get()->map(fn ($delivery) => $delivery->nostrEvent->kind)->all();

    expect(NostrEvent::query()->where('kind', 22150)->count())->toBe(1)
        ->and($delivered)->not->toContain(22150)
        ->and($delivered)->toContain(31923);
});

test('a tournament whose mix team lost every account does not stall the next one', function () {
    [$broken] = directedSeries();
    $broken->forceFill(['results_mode' => TournamentResultsMode::Players])->save();
    SeriesMatch::query()->delete();
    $ghosts = TournamentParticipant::query()->where('tournament_id', $broken->id)->get();

    foreach ($ghosts as $ghost) {
        $ghost->forceFill(['lineup_id' => null, 'draw_position' => $ghost->id])->save();
        User::query()->whereIn('id', $ghost->members)->delete();
    }

    [$healthy] = directedSeries();
    SeriesMatch::query()->delete();

    app(TournamentDraws::class)->advanceDue();

    expect(SeriesMatch::query()->whereHas('tournamentMatch', fn ($q) => $q->where('tournament_id', $healthy->id))->count())->toBe(1);
});

test('the draw waits for confirmations and refuses a block mined before its commitment', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.bitcoin.confirmations' => 6]);
    $tip = 900000;
    $blockTime = 0;
    Http::fake(function ($request) use (&$tip, &$blockTime) {
        return match (true) {
            str_ends_with($request->url(), '/blocks/tip/height') => Http::response((string) $tip),
            str_contains($request->url(), '/block-height/') => Http::response(hash('sha256', $request->url())),
            default => Http::response(['timestamp' => $blockTime]),
        };
    });

    $tournament = openTournament(['capacity' => 4]);

    foreach (range(1, 2) as $i) {
        [$player, $signer] = keyedPlayer();
        soloSignup($tournament, $player, $signer);
    }

    $this->travel(25)->hours();
    $draws = app(TournamentDraws::class);
    $draws->close($tournament->refresh());
    $committed = $tournament->refresh()->draw_height;

    // Mined, but only 5 confirmations: wait.
    $tip = $committed + 4;
    $blockTime = now()->addMinutes(10)->getTimestamp();
    expect($draws->resolve($tournament->refresh()))->toBeFalse();

    // 6 confirmations, but the block is older than the commitment: re-committed to a later block.
    $tip = $committed + 5;
    $blockTime = now()->subHour()->getTimestamp();
    expect($draws->resolve($tournament->refresh()))->toBeFalse()
        ->and($tournament->refresh()->draw_height)->toBe($tip + 1)
        ->and($tournament->status)->toBe(TournamentStatus::Drawing);

    $tip = $tournament->draw_height + 5;
    $blockTime = now()->addMinutes(10)->getTimestamp();
    expect($draws->resolve($tournament->refresh()))->toBeTrue()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running);
});

test('a tournament match gets one series and one game per replay, and its games keep it', function () {
    [$tournament] = directedSeries();
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots.participant')->sole();

    expect(fn () => app(TournamentMatchMaker::class)->createSeries($tournament, $match, $match->slots[0]->participant, $match->slots[1]->participant))
        ->toThrow(UniqueConstraintViolationException::class);

    $chess = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    $game = ChessGame::query()->where('tournament_match_id', '!=', null)->sole();

    expect(fn () => ChessGame::factory()->create(['tournament_match_id' => $game->tournament_match_id, 'tournament_game' => $game->tournament_game]))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => $chess->delete())->toThrow(QueryException::class)
        ->and($game->refresh()->tournament_match_id)->not->toBeNull();
});

test('the draw copies the tournament\'s frozen ladder (none here) and is published only with one full mix team', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    Http::fake(['*/blocks/tip/height' => Http::response('900000')]);

    $unrated = openTournament(['capacity' => 4], rocketLeague: true);
    $short = openTournament(['capacity' => 4], rocketLeague: true);
    openSeason(['slug' => 'season-1']);

    foreach ([[$unrated, 3], [$short, 2]] as [$tournament, $solos]) {
        [$lineup, $captain, $signer] = keyedLineup();
        lineupSignup($tournament, $lineup, $captain, $signer);
        [$other, $captainB, $signerB] = keyedLineup();
        lineupSignup($tournament, $other, $captainB, $signerB);

        foreach (range(1, $solos) as $i) {
            [$player, $playerSigner] = keyedPlayer();
            soloSignup($tournament, $player, $playerSigner);
        }
    }

    $this->travel(25)->hours();
    $draws = app(TournamentDraws::class);
    $draws->close($unrated->refresh());
    $draws->close($short->refresh());

    $draw = NostrEvent::query()->findOrFail($unrated->refresh()->draw_event_id)->payload();

    expect(collect($draw['tags'])->where(0, 'a')->pluck(1)->all())->toBe([$unrated->address()])
        ->and($short->refresh()->status)->toBe(TournamentStatus::Drawing)
        ->and($short->draw_event_id)->toBeNull();
});

test('director results on a rated tournament: chess pinned at the pairing with clans, a no-show attested as forfeit without Elo, a series with its roster', function () {
    openSeason();
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $chess = calendared(runningChess(TournamentFormat::SingleElimination, 4, clans: true));
    [$played, $noShow] = TournamentMatch::query()->where('tournament_id', $chess->id)->where('status', 'ready')->orderBy('id')->get()->all();

    expect($played->pairing['gate'])->not->toBeNull()
        ->and($played->pairing['clans'])->toHaveCount(2);

    // Trust gone after the pairing: the pairing counts, not the close.
    app()->bind(TrustFacts::class, NoTrustFacts::class);
    $runner = app(TournamentRunner::class);
    $runner->enterResult($played, $chess->creator, ['result' => '1-0']);
    $runner->enterResult($noShow, $chess->creator, ['result' => 'noshow-1']);
    $runner->closeRound(TournamentRunner::currentRound($chess), $chess->creator);

    $tags = fn (int $matchId) => NostrEvent::query()->findOrFail(SeasonAttestation::query()
        ->where('source_id', ChessGame::query()->where('tournament_match_id', $matchId)->value('id'))->value('nostr_event_id'))->payload()['tags'];
    $playedGame = ChessGame::query()->where('tournament_match_id', $played->id)->sole();

    expect($playedGame->rated)->toBeTrue()
        ->and($playedGame->clans_at_accept)->toHaveCount(2)
        ->and($tags($played->id))->toContain(['resolution', 'admin'], ['entered-by', $chess->creator->pubkey], ['a', $chess->address(), ''])
        ->and(collect($tags($played->id))->where(0, 'clan')->count())->toBe(2)
        ->and(collect($tags($played->id))->where(0, 'elo')->count())->toBe(2)
        ->and($tags($noShow->id))->toContain(['resolution', 'forfeit'])
        ->and(collect($tags($noShow->id))->where(0, 'elo')->count())->toBe(0)
        ->and(RatingChange::query()->where('source_id', ChessGame::query()->where('tournament_match_id', $noShow->id)->value('id'))->count())->toBe(0);

    app()->bind(TrustFacts::class, TrustedFacts::class);
    [$rl] = directedSeries();
    calendared($rl);
    $runner->enterResult(TournamentMatch::query()->where('tournament_id', $rl->id)->sole(), $rl->creator, ['games' => [[3, 1], [2, 0], [1, 0]]]);
    $runner->closeRound(TournamentRunner::currentRound($rl), $rl->creator);
    $series = SeriesMatch::query()->whereHas('tournamentMatch', fn ($q) => $q->where('tournament_id', $rl->id))->sole();
    $seriesTags = NostrEvent::query()->findOrFail(SeasonAttestation::query()->where('source', 'series')->where('source_id', $series->id)->value('nostr_event_id'))->payload()['tags'];

    expect($series->rated)->toBeTrue()
        ->and($series->resolved_roster)->toHaveCount(6)
        ->and(collect($seriesTags)->filter(fn ($tag) => $tag[0] === 'p' && isset($tag[3]))->count())->toBe(6)
        ->and(collect($seriesTags)->where(0, 'clan')->count())->toBe(6)
        ->and($seriesTags)->toContain(['resolution', 'admin'], ['entered-by', $rl->creator->pubkey], ['a', $rl->refresh()->address(), ''])
        ->and(RatingChange::query()->where('source', 'series')->count())->toBe(2);
});

/** Give a test tournament its calendar event (as publishing would), so attestations can name it. */
function calendared(Tournament $tournament): Tournament
{
    $event = NostrEvent::fromSigned(SignedEvent::fromInput((new TestSigner)->sign(31923, [['d', (string) $tournament->slug], ['alt', 'Tournament']])));
    $tournament->forceFill(['event_id' => $event->id])->save();

    return $tournament->refresh();
}

test('a clanmate who left the clan after sign-up closed still has an interest, and so does the clan of a player who left it', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, clans: true);
    $tournament->forceFill(['signup_closes_at' => now()->subHour()])->save();
    $match = TournamentMatch::query()->where('status', 'ready')->with('slots.participant')->orderBy('id')->first();
    $player = User::query()->findOrFail($match->slots[0]->participant->user_id);
    $clan = Clan::query()->where('owner_id', $player->id)->sole();
    $mate = User::factory()->create();
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $mate->id, 'role' => ClanRole::Member, 'joined_at' => now()->subWeek()]);
    $tournament->directors()->attach($mate->id);

    // The director leaves (as the signed leave records it) and then enters his former clanmate's win.
    ClanMember::query()->where('user_id', $mate->id)->delete();
    ClanDeparture::query()->create(['clan_id' => $clan->id, 'clan_address' => $clan->address(), 'clan_name' => $clan->name, 'user_id' => $mate->id, 'pubkey' => $mate->pubkey, 'reason' => 'left', 'left_at' => now()]);

    expect(fn () => app(TournamentRunner::class)->enterResult($match, $mate, ['result' => '1-0']))->toThrow(TournamentRuleViolation::class, 'interest');

    // The other way round: the player left, the director is still in that clan.
    $other = TournamentMatch::query()->where('status', 'ready')->with('slots.participant')->orderByDesc('id')->first();
    $leaver = User::query()->findOrFail($other->slots[1]->participant->user_id);
    $leftClan = Clan::query()->where('owner_id', $leaver->id)->sole();
    $stayer = User::factory()->create();
    ClanMember::query()->create(['clan_id' => $leftClan->id, 'user_id' => $stayer->id, 'role' => ClanRole::Member, 'joined_at' => now()->subWeek()]);
    ClanMember::query()->where('user_id', $leaver->id)->delete();
    ClanDeparture::query()->create(['clan_id' => $leftClan->id, 'clan_address' => $leftClan->address(), 'clan_name' => $leftClan->name, 'user_id' => $leaver->id, 'pubkey' => $leaver->pubkey, 'reason' => 'left', 'left_at' => now()]);
    $leftClan->forceFill(['owner_id' => $stayer->id])->save();
    $tournament->directors()->attach($stayer->id);

    expect(fn () => app(TournamentRunner::class)->enterResult($other, $stayer, ['result' => '0-1']))->toThrow(TournamentRuleViolation::class, 'interest');
});

test('re-adding a director never changes who appointed them', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $match = TournamentMatch::query()->where('status', 'ready')->with('slots.participant')->orderBy('id')->first();
    $organizer = User::query()->findOrFail($match->slots[0]->participant->user_id);
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);
    $tournament->forceFill(['created_by_id' => $organizer->id])->save();
    $alt = User::factory()->create();
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    Livewire::actingAs($organizer)->test('pages::tournaments.director', ['tournament' => $tournament->refresh()])
        ->set('directorKey', $alt->npub)->call('addDirector')->assertHasNoErrors();
    Livewire::actingAs($admin)->test('pages::tournaments.director', ['tournament' => $tournament])
        ->set('directorKey', $alt->npub)->call('addDirector')->assertHasNoErrors();

    expect(DB::table('tournament_directors')->where('user_id', $alt->id)->value('added_by_id'))->toBe($organizer->id)
        ->and(fn () => app(TournamentRunner::class)->enterResult($match, $alt, ['result' => '1-0']))->toThrow(TournamentRuleViolation::class, 'interest');
});

test('regression (S1b): a director who left the player\'s clan while sign-up was still open is refused', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, clans: true);
    $tournament->forceFill(['published_at' => now()->subDays(3), 'signup_closes_at' => now()->subHour()])->save();
    $match = TournamentMatch::query()->where('status', 'ready')->with('slots.participant')->orderBy('id')->first();
    $clan = Clan::query()->where('owner_id', $match->slots[0]->participant->user_id)->sole();
    $mate = User::factory()->create();
    $tournament->directors()->attach($mate->id);

    // Left a day before sign-up closed, still inside the tournament's life.
    ClanDeparture::query()->create(['clan_id' => $clan->id, 'clan_address' => $clan->address(), 'clan_name' => $clan->name, 'user_id' => $mate->id, 'pubkey' => $mate->pubkey, 'reason' => 'left', 'left_at' => now()->subDay()]);

    expect(fn () => app(TournamentRunner::class)->enterResult($match, $mate, ['result' => '1-0']))->toThrow(TournamentRuleViolation::class, 'interest');
});
