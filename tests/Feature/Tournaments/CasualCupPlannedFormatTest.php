<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Models\Tournament;
use App\Support\Cards\PageCardFacts;
use App\Support\Cards\ShareMoments;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\TournamentSignups;
use App\Support\TwentyOne\Stream\TournamentSlides;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| A casual cup's format follows its sign-ups
|--------------------------------------------------------------------------
|
| A cup is stored as a double elimination until its close, but it plays
| what CasualCups::formatFor() picks for the players who are in. Every
| surface during sign-up names and draws that planned format
| (CasualCups::plannedFormat()), and every sign-up or withdrawal changes it
| at once (user, 2026-10-03: "Das muss dynamisch passieren und immer das
| perfekte Format wählen" and "bei jeder neuen Anmeldung am besten").
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess'], 'esports.bitcoin.confirmations' => 1]);
    // No block yet: the draw waits, the format is already chosen.
    Http::fake(fn ($request) => str_ends_with($request->url(), '/blocks/tip/height') ? Http::response('900000') : Http::response('', 404));
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    cupTick();
});

/** The bracket preview's tag on the tournament page: [format, players]. */
function plannedPreview(string $html): array
{
    preg_match('/data-test="bracket-preview" data-format="([^"]+)" data-players="(\d+)"/', $html, $tag);

    return [$tag[1] ?? null, isset($tag[2]) ? (int) $tag[2] : null];
}

test('the planned format is what the cup would play if sign-up closed now', function (int $signups, TournamentFormat $format, int $iterations) {
    $cup = openCup();
    cupSignups($cup, $signups);
    $planned = CasualCups::plannedFormat($cup->refresh());

    expect($planned['format'])->toBe($format)
        ->and($planned['players'])->toBe($signups)
        ->and($format === TournamentFormat::RoundRobin ? $planned['options']['iterations'] : 0)->toBe($iterations)
        // The stored format stays: the close and the sign-up rules key off it.
        ->and($cup->format)->toBe(TournamentFormat::DoubleElimination)
        ->and(CasualCups::isEvening($cup))->toBeFalse();
})->with([
    '2: a duel of three games' => [2, TournamentFormat::RoundRobin, 3],
    '5: round robin' => [5, TournamentFormat::RoundRobin, 1],
    '6: round robin' => [6, TournamentFormat::RoundRobin, 1],
    '8: round robin' => [8, TournamentFormat::RoundRobin, 1],
    '9: double elimination' => [9, TournamentFormat::DoubleElimination, 0],
    '12: double elimination' => [12, TournamentFormat::DoubleElimination, 0],
]);

test('with nobody or one player in, the plan is the duel two players would play', function () {
    $cup = openCup();

    expect(CasualCups::plannedFormat($cup))->toMatchArray(['format' => TournamentFormat::RoundRobin, 'players' => 2]);

    cupSignups($cup, 1);

    expect(CasualCups::plannedFormat($cup->refresh())['players'])->toBe(2);
});

test('the page of a cup with 6 sign-ups previews a round robin of 6, not a 16-place double elimination', function () {
    $cup = openCup();
    cupSignups($cup, 6);

    $html = $this->get(route('tournaments.show', $cup))->assertOk()->getContent();
    $bracket = str($html)->after('id="bracket"')->before('</section>')->toString();

    expect(plannedPreview($html))->toBe(['round-robin', 6])
        ->and($bracket)->toContain('Format follows the sign-ups: 6 players → Round Robin', 'Round Robin for 6 players.', 'Round 1 if sign-up closed now')
        ->not->toContain('Double Elimination', 'Open spot', 'for 16 players', 'bye')
        // Round 1 of a round robin of 6: three pairings, everyone plays.
        ->and(substr_count($bracket, 'data-test="projected-match"'))->toBe(3)
        // The hero's format chip and the description name the planned format too.
        ->and($html)->toContain('data-test="tournament-format">Round Robin</span>')
        ->not->toContain('Double Elimination');
});

test('the preview follows the sign-ups: a 7th keeps the round robin, a 9th turns it into a double elimination of 9', function () {
    $cup = openCup();
    cupSignups($cup, 6);
    $page = fn () => $this->get(route('tournaments.show', $cup))->assertOk()->getContent();

    expect(plannedPreview($page()))->toBe(['round-robin', 6]);

    cupSignups($cup, 1);
    $seven = $page();

    expect(plannedPreview($seven))->toBe(['round-robin', 7])
        ->and($seven)->toContain('Format follows the sign-ups: 7 players → Round Robin');

    cupSignups($cup, 2);
    $nine = $page();
    $bracket = str($nine)->after('id="bracket"')->before('</section>')->toString();

    expect(plannedPreview($nine))->toBe(['double-elimination', 9])
        ->and($bracket)->toContain('Format follows the sign-ups: 9 players → Double Elimination', 'Double Elimination for 9 players.')
        ->not->toContain('Open spot', 'for 16 players')
        ->and($nine)->toContain('data-test="tournament-format">Double Elimination</span>');
});

test('the format chosen at the close is the planned format of that moment', function (int $signups) {
    $cup = openCup();
    cupSignups($cup, $signups);
    $this->travelTo($cup->refresh()->signup_closes_at);
    $planned = CasualCups::plannedFormat($cup->refresh());

    cupTick();
    $cup->refresh();

    expect($cup->status)->toBe(TournamentStatus::Drawing)
        ->and($cup->format)->toBe($planned['format'])
        // Loose: the stored JSON gives 1.0 back as 1.
        ->and($cup->options)->toEqual(FormatOptions::fromArray($planned['options'], $cup->profile())->toArray())
        // Once switched or closed the cup follows its sign-ups no more: the plan is the stored format.
        ->and(CasualCups::followsSignups($cup))->toBeFalse()
        ->and(CasualCups::plannedFormat($cup)['format'])->toBe($cup->format);
})->with([2, 5, 6, 8, 9, 12]);

test('a sign-up that makes it 9 names the double elimination in its own response, and tells every open page', function () {
    Event::fake([TournamentChanged::class]);
    $cup = openCup();
    cupSignups($cup, 8);
    [$ninth, $signer] = keyedPlayer();

    $page = Livewire::actingAs($ninth)->test('pages::tournaments.signup', ['tournament' => $cup->refresh()])
        ->assertSeeHtml('data-test="signup-format">Round Robin</dd>');

    $page->call('enterSolo', json_encode($signer->signTemplates($page->instance()->prepareSolo())))
        ->assertSet('justEntered', true)
        ->assertSeeHtml('data-test="signup-format">Double Elimination</dd>')
        ->assertSee('Format follows the sign-ups: 9 players → Double Elimination');

    Event::assertDispatched(TournamentChanged::class, fn (TournamentChanged $event): bool => $event->tournamentId === $cup->id && $event->reason === 'signup');
});

test('a withdrawal that makes it 8 again turns the plan back into a round robin and tells every open page', function () {
    $cup = openCup();
    cupSignups($cup, 8);
    [$ninth, $signer] = keyedPlayer();
    soloSignup($cup->refresh(), $ninth, $signer);

    expect(CasualCups::plannedFormat($cup)['format'])->toBe(TournamentFormat::DoubleElimination);

    Event::fake([TournamentChanged::class]);
    $signups = app(TournamentSignups::class);
    $signups->withdraw($cup, $ninth, $signer->signTemplates($signups->prepareWithdraw($cup, $ninth)));

    expect(CasualCups::plannedFormat($cup)['format'])->toBe(TournamentFormat::RoundRobin)
        ->and(plannedPreview($this->get(route('tournaments.show', $cup))->getContent()))->toBe(['round-robin', 8]);
    Event::assertDispatched(TournamentChanged::class, fn (TournamentChanged $event): bool => $event->tournamentId === $cup->id && $event->reason === 'withdrawn');
});

test('the tournament page listens for the sign-ups and polls as the fallback', function () {
    $cup = openCup();

    $this->get(route('tournaments.show', $cup))->assertOk()
        ->assertSee('x-data="tournamentLive({ id: '.$cup->id.' })"', false)
        ->assertSee('wire:poll.15s.visible', false);
});

test('a special tournament keeps its own format and places in the preview', function () {
    $tournament = openTournament(['format' => TournamentFormat::DoubleElimination, 'capacity' => 16]);

    foreach (range(1, 6) as $ignored) {
        [$player, $signer] = keyedPlayer();
        soloSignup($tournament, $player, $signer);
    }

    $html = $this->get(route('tournaments.show', $tournament))->assertOk()->getContent();

    expect(CasualCups::followsSignups($tournament))->toBeFalse()
        ->and(plannedPreview($html))->toBe(['double-elimination', 16])
        ->and($html)->toContain('Open spot')
        ->not->toContain('data-test="format-follows"');
});

test('the stream slide and the link previews of a cup with 6 sign-ups name and draw the round robin', function () {
    $cup = openCup();
    cupSignups($cup, 6);
    $slide = app(TournamentSlides::class)->data($cup->refresh(), now()->getTimestampMs());

    expect($slide['format'])->toBe('Round Robin')
        ->and($slide['preview']['matches'])->toHaveCount(3)
        ->and($slide['preview']['byes'])->toBe([])
        ->and($slide['howItRuns']['steps'][0])->toBe(['title' => '5 rounds', 'line' => 'Everyone plays everyone.'])
        ->and(PageCardFacts::tournament($cup)['format'])->toBe(TournamentFormat::RoundRobin->value)
        ->and(ShareMoments::tournamentInvite($cup)['format'])->toBe(TournamentFormat::RoundRobin->value);
});

test('a cup of two is named a duel, planned or stored, in English and German; a third player makes it a round robin', function () {
    $cup = openCup();
    cupSignups($cup, 2);
    $cup->refresh();

    expect(Lobbies::formatLabel($cup))->toBe('Duel')
        ->and(CasualCups::followNote($cup))->toBe('Format follows the sign-ups: 2 players → Duel');

    app()->setLocale('de');
    expect(Lobbies::formatLabel($cup))->toBe('Duell');
    app()->setLocale('en');

    cupSignups($cup, 1);
    expect(Lobbies::formatLabel($cup->refresh()))->toBe('Round Robin');

    // Switched at the close to the chess duel (a round robin of three games) and drawn with two.
    $cup->signups()->active()->latest('id')->firstOrFail()->forceFill(['withdrawn_at' => now()])->save();
    $cup->forceFill(['status' => TournamentStatus::Drawing, 'format' => TournamentFormat::RoundRobin, 'options' => ['iterations' => 3, 'rankBy' => 'points']])->save();

    expect(Lobbies::formatLabel($cup->refresh()))->toBe('Duel');
});
