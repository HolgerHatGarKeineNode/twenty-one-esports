<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Cards\PageCard;
use App\Support\Cards\ShareCard;
use App\Support\Cards\ShareMoments;
use App\Support\GameNames;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
 * An Age of Empires II lobby tournament (P10) is one match for up to 8
 * players: every place that names a tournament's mode and format says "One
 * lobby match" and never "1v1" or "Free for All", as the stream slides
 * already do. A chess or Rocket League tournament keeps its mode and format.
 */

beforeEach(function () {
    $this->withoutVite();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/** An open AoE2 lobby tournament with one entry. */
function labelLobby(): Tournament
{
    $tournament = openTournament(['name' => 'Lobby Night', 'game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll,
        'options' => FormatOptions::defaults(GameProfile::for('age-of-empires-2', '1v1'))->toArray(), 'capacity' => 16, 'results_mode' => TournamentResultsMode::Players]);
    labelEntry($tournament);

    return $tournament->refresh();
}

function labelEntry(Tournament $tournament): TournamentParticipant
{
    $user = User::factory()->create(['name' => 'Player 1']);

    return TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => 'Player 1', 'rating' => 1500, 'members' => [$user->id]]);
}

function labelAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** Calls a private method of a card: the drawn line has no other seam than the PNG. */
function cardLine(object $card, string $method): string
{
    return (fn (): string => $this->{$method}())->call($card);
}

/**
 * Every place that names the tournament's mode and format, as the text it shows.
 *
 * @return array<string, Closure(Tournament, mixed): string>
 */
function labelPlaces(): array
{
    return [
        'tournament page' => fn (Tournament $t, $test): string => $test->get(route('tournaments.show', $t))->assertOk()->getContent(),
        'tournaments list' => fn (Tournament $t, $test): string => $test->get(route('tournaments.index'))->assertOk()->getContent(),
        'home' => fn (Tournament $t, $test): string => $test->get(route('home'))->assertOk()->getContent(),
        'game page' => fn (Tournament $t, $test): string => $test->get(GameNames::page($t->game))->assertOk()->getContent(),
        'admin list' => fn (Tournament $t, $test): string => Livewire::actingAs(labelAdmin())->test('pages::admin.tournaments')->html(),
        'sign-up page' => fn (Tournament $t, $test): string => $test->actingAs(User::factory()->create())->get(route('tournaments.signup', $t))->assertOk()->getContent(),
        'tv' => fn (Tournament $t, $test): string => $test->get(route('tournaments.tv', $t))->assertOk()->getContent(),
        'calendar event' => fn (Tournament $t, $test): string => str_replace(["\r\n ", '\\,'], ['', ','], $test->get(route('tournaments.calendar', $t))->assertOk()->getContent()),
        'page card' => fn (Tournament $t, $test): string => cardLine(PageCard::tournament($t), 'tournamentLine'),
        'invite card' => fn (Tournament $t, $test): string => cardLine(ShareCard::tournamentInvite($t), 'inviteLine'),
        'winner card' => fn (Tournament $t, $test): string => ShareMoments::tournament($t, $t->participants()->firstOrFail())['detail'],
    ];
}

test('an AoE2 lobby tournament names one lobby match, never a 1v1 or Free for All', function (string $place) {
    $tournament = labelLobby();

    $text = html_entity_decode(labelPlaces()[$place]($tournament, $this), ENT_QUOTES | ENT_HTML5);

    // The 1v1 ladder keeps its name where the ladders are listed (home's "<b>…1v1</b>"), and a game's
    // mode list says "1v1, 2v2, 3v3": neither is the tournament's chip.
    expect($text)->toContain('One lobby match')
        ->not->toMatch('/Definitive Edition 1v1(?!<\/b>)/')
        ->not->toContain('(1v1)')
        ->not->toContain('1v1, One lobby match')
        ->not->toMatch('/text-ink-2">\s*1v1\s*<\/span>/')
        ->not->toContain('Free for All');
})->with(array_keys(labelPlaces()));

test('in German an AoE2 lobby tournament is "Ein Lobby-Match" on its page', function () {
    $tournament = labelLobby();

    $this->get(route('tournaments.show', $tournament).'?lang=de')->assertOk()
        ->assertSee('Ein Lobby-Match')
        ->assertDontSee('Definitive Edition 1v1')
        ->assertDontSee('Free for All');
});

test('a chess and a Rocket League tournament keep their mode and format', function (bool $rocketLeague, string $gameLine, string $ladder, string $heroChip, string $format) {
    $tournament = openTournament(['name' => 'Control Cup'], $rocketLeague);
    labelEntry($tournament);
    $tournament->refresh();

    foreach (['tournament page', 'tournaments list', 'home', 'admin list', 'tv', 'calendar event', 'page card', 'invite card', 'winner card'] as $place) {
        $text = html_entity_decode(labelPlaces()[$place]($tournament, $this), ENT_QUOTES | ENT_HTML5);
        // The winner card names the ladder ("Chess blitz"), home's hero chip mode and format, every other place game and mode.
        $named = match ($place) {
            'winner card' => $ladder,
            'home' => $heroChip,
            default => $gameLine,
        };

        expect([$place => [str_contains($text, $named), str_contains($text, $format), str_contains($text, 'One lobby match')]])
            ->toBe([$place => [true, true, false]]);
    }
})->with([
    'chess' => [false, 'Chess Blitz 5+3', 'Chess blitz', 'Blitz 5+3, Swiss', 'Swiss'],
    'Rocket League' => [true, 'Rocket League 3v3', 'Rocket League 3v3', '3v3, Single Elimination', 'Single Elimination'],
]);

test('behind another tournament\'s hero, the AoE2 lobby tournament\'s card on the tournaments list says one lobby match', function () {
    $hero = openTournament(['name' => 'Earlier Cup']);
    $hero->forceFill(['signup_closes_at' => now()->addHours(12)])->save();
    labelLobby();

    $html = html_entity_decode($this->get(route('tournaments.index'))->assertOk()->getContent(), ENT_QUOTES | ENT_HTML5);

    expect($html)->toContain('data-test="organizer-card"')->toContain('<p class="m-0 text-[13px] leading-normal text-ink-2">Age of Empires II: Definitive Edition, One lobby match</p>')
        ->not->toContain('Definitive Edition 1v1, Free for All');
});

test('the cup board names an AoE2 lobby cup by its game, and a running one "One lobby match"', function () {
    Queue::fake();
    config(['esports.casual_cups.enabled' => ['age-of-empires-2']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    cupTick();

    preg_match('/data-test="cup-next-facts">([^<]*)</', $this->get(route('tournaments.index'))->assertOk()->getContent(), $facts);

    expect(html_entity_decode($facts[1] ?? '', ENT_QUOTES | ENT_HTML5))->toStartWith('Age of Empires II: Definitive Edition tournament, ');

    Tournament::query()->casualCup()->update(['status' => TournamentStatus::Running]);

    expect($this->get(route('tournaments.index'))->assertOk()->getContent())
        ->toContain('data-test="cup-format">One lobby match</span>')
        ->not->toContain('data-test="cup-format">Free for All');

    // Over, the cups are listed under the board with their format and game.
    Tournament::query()->casualCup()->update(['status' => TournamentStatus::Finished]);
    $row = str(html_entity_decode($this->get(route('tournaments.index'))->assertOk()->getContent(), ENT_QUOTES | ENT_HTML5))
        ->after('data-test="tournament-item"')->before('</li>')->toString();

    expect(preg_replace('/\s+/', ' ', strip_tags($row)))->toContain('One lobby match Age of Empires II: Definitive Edition Finished')
        ->not->toContain('1v1')->not->toContain('Free for All');
});
