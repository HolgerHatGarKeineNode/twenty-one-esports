<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Tournaments\TournamentPublisher;
use Carbon\CarbonImmutable;
use Tests\Support\TestSigner;

/*
 * The public tournament page as a landing and invite page: the call to
 * action follows the tournament and the viewer, who plays is listed with the
 * seed each entry would get now (open spots included, the viewer marked),
 * the organizer's description is shown escaped, and before the draw every
 * format shows its projected bracket.
 */

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

function casualElo(User $user, int $rating, string $game = 'chess', string $mode = 'blitz'): void
{
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => $game, 'mode' => $mode, 'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => $rating, 'results' => 10]);
}

/** A keyed player who signs up solo, with a casual Elo. */
function enteredPlayer(Tournament $tournament, string $name, int $elo): User
{
    [$user, $signer] = keyedPlayer();
    $user->forceFill(['name' => $name])->save();
    casualElo($user, $elo);
    soloSignup($tournament, $user, $signer);

    return $user;
}

test('the call to action follows the tournament and the viewer in every status', function (Closure $make, string $state, string $text) {
    [$tournament, $viewer] = $make();
    $request = $viewer === null ? $this : $this->actingAs($viewer);

    $request->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('data-cta="'.$state.'"', false)
        ->assertSee($text);
})->with([
    'sign-up open, guest' => [fn () => [openTournament(), null], 'open', 'spots left'],
    'sign-up open, signed up' => [function () {
        $tournament = openTournament();

        return [$tournament, enteredPlayer($tournament, 'hodlqueen', 1200)];
    }, 'entered', 'You’re in'],
    'full' => [function () {
        $tournament = openTournament(['capacity' => 2]);
        enteredPlayer($tournament, 'a', 1100);
        enteredPlayer($tournament, 'b', 1000);

        return [$tournament, User::factory()->create()];
    }, 'full', 'Sign-up is full'],
    'sign-up closed' => [function () {
        $tournament = openTournament();
        $tournament->forceFill(['signup_closes_at' => now()->subMinute()])->save();

        return [$tournament, null];
    }, 'closed', 'Sign-up closed'],
    'draw pending' => [function () {
        $tournament = openTournament();
        $tournament->forceFill(['status' => TournamentStatus::Drawing, 'signup_closes_at' => now()->subMinute(), 'draw_height' => 915001])->save();

        return [$tournament, null];
    }, 'closed', 'Bitcoin block 915001'],
    'running' => [fn () => [runningChess(TournamentFormat::SingleElimination, 4), null], 'live', 'Watch live'],
    'finished' => [function () {
        $tournament = runningChess(TournamentFormat::SingleElimination, 4);
        playOutAsDirector($tournament);

        return [$tournament, null];
    }, 'finished', 'See the results'],
    'called off' => [function () {
        $tournament = openTournament();
        $tournament->forceFill(['status' => TournamentStatus::Cancelled])->save();

        return [$tournament, null];
    }, 'cancelled', 'Called off'],
]);

test('the sign-up button leads to the sign-up page only while sign-up is open', function () {
    $open = openTournament();
    $closed = openTournament();
    $closed->forceFill(['signup_closes_at' => now()->subMinute()])->save();

    $this->get(route('tournaments.show', $open))->assertSee('data-test="to-signup"', false)->assertSee(route('tournaments.signup', $open), false);
    $this->get(route('tournaments.show', $closed))->assertDontSee('data-test="to-signup"', false)->assertSee('data-test="cta-closed"', false);
});

test('a draft shows its manager the publish form and no call to action', function () {
    $draft = Tournament::factory()->create(['created_by_id' => organizer()->id]);

    $this->actingAs($draft->creator)->get(route('tournaments.show', $draft))->assertOk()
        ->assertSee('data-cta="draft"', false)
        ->assertSee('data-test="publish-form"', false)
        ->assertDontSee('data-test="signup-cta"', false);
});

test('who plays lists the entries by Elo with their seed, marks the viewer and fills the rest with open spots', function () {
    $tournament = openTournament(['capacity' => 8]);
    enteredPlayer($tournament, 'lowroller', 900);
    $me = enteredPlayer($tournament, 'satsjaeger', 1300);
    enteredPlayer($tournament, 'midfield', 1100);

    $html = $this->actingAs($me)->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeInOrder(['satsjaeger', '1300 Elo', 'midfield', '1100 Elo', 'lowroller', '900 Elo'])
        ->assertSee('Seed 1 if sign-up closed now.')
        ->getContent();

    expect(substr_count($html, 'data-test="roster-entry"'))->toBe(3)
        ->and(substr_count($html, 'data-test="open-seat"'))->toBe(5)
        ->and(substr_count($html, 'data-test="you-chip"'))->toBe(1)
        ->and(preg_match('/data-test="roster-entry"\s+data-you[^>]*>(?:(?!data-test="roster-entry").)*?satsjaeger/s', $html))->toBe(1)
        // Signed up already: no "your spot?" link for the viewer.
        ->and($html)->not->toContain('data-test="your-seat"')
        ->and($html)->toContain('3 of 8 spots taken');

    // A guest sees the first open spot as theirs, and six open tiles at most plus the rest counted.
    $big = openTournament(['capacity' => 12]);
    enteredPlayer($big, 'solo', 1000);
    $guest = $this->get(route('tournaments.show', $big))->assertOk()->assertSee('data-test="your-seat"', false)->assertSee('+5 more open spots')->getContent();

    expect(substr_count($guest, 'data-test="open-seat"'))->toBe(6)->and($guest)->not->toContain('data-test="you-chip"');
});

test('after the draw who plays shows the seeded participants and no open spots', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);

    $html = $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeInOrder(['Player 1', '1490 Elo', 'Player 2', '1480 Elo'])
        ->getContent();

    expect(substr_count($html, 'data-test="roster-entry"'))->toBe(4)
        ->and($html)->not->toContain('data-test="open-seat"')
        ->and($html)->not->toContain('data-test="bracket-preview"')
        ->and($html)->toContain('data-live');
});

test('the description is shown escaped in the hero and published in front of the rules', function () {
    $tournament = Tournament::factory()->create([
        'created_by_id' => organizer()->id,
        'description' => "Stream on Zap.stream, pizza at the break.\n<script>alert(1)</script>",
    ]);
    $tournament = app(TournamentPublisher::class)->publish($tournament, $tournament->creator, CarbonImmutable::now()->addDay());

    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('data-test="tournament-description"', false)
        ->assertSee('Stream on Zap.stream, pizza at the break.')
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);

    expect($tournament->event->payload()['content'])->toStartWith("Stream on Zap.stream, pizza at the break.\n<script>alert(1)</script>\n\nChess blitz, swiss.");

    // Without one, the hero explains the format instead, and the 31923 starts with the rules.
    $plain = openTournament();
    $this->get(route('tournaments.show', $plain))->assertDontSee('data-test="tournament-description"', false);
    expect($plain->event->payload()['content'])->toStartWith('Chess blitz, swiss.');
});

test('the create form stores a description, trimmed, and nothing when it is left empty', function () {
    $organizer = organizer();

    Livewire\Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->set('name', 'Halving Cup')->set('description', '  Bring your own controller.  ')->set('date', now()->addWeek()->format('Y-m-d'))
        ->call('create')->assertHasNoErrors();
    Livewire\Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->set('name', 'Quiet Cup')->set('date', now()->addWeek()->format('Y-m-d'))
        ->call('create')->assertHasNoErrors();
    Livewire\Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->set('name', 'Long Cup')->set('description', str_repeat('a', 1001))->set('date', now()->addWeek()->format('Y-m-d'))
        ->call('create')->assertHasErrors(['description' => 'max']);

    expect(Tournament::query()->where('name', 'Halving Cup')->value('description'))->toBe('Bring your own controller.')
        ->and(Tournament::query()->where('name', 'Quiet Cup')->value('description'))->toBeNull()
        ->and(Tournament::query()->where('name', 'Long Cup')->exists())->toBeFalse();
});

test('before the draw every format shows its projected bracket with the entries in their seeds', function (TournamentFormat $format, string $marker) {
    $tournament = openTournament(['format' => $format, 'capacity' => 8]);
    enteredPlayer($tournament, 'topseed', 1500);

    $html = $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('data-test="bracket-preview" data-format="'.$format->value.'"', false)
        ->assertSee('data-test="projected-chip"', false)
        ->assertSee($marker)
        ->assertSee('topseed')
        ->getContent();

    // The chooser's animated preview, drawn for the planned size.
    expect($html)->toContain('class="tf-preview')->and($html)->toContain('Open spot');
})->with([
    'single elimination' => [TournamentFormat::SingleElimination, 'Round 1 if sign-up closed now'],
    'double elimination' => [TournamentFormat::DoubleElimination, 'Round 1 if sign-up closed now'],
    'round robin' => [TournamentFormat::RoundRobin, 'Round 1 if sign-up closed now'],
    'swiss' => [TournamentFormat::Swiss, 'Round 1 if sign-up closed now'],
    'two stage' => [TournamentFormat::TwoStage, 'Groups if sign-up closed now'],
]);

test('the invite card is the preview image of a published tournament and 404s for a draft', function () {
    $tournament = openTournament(['name' => 'Halving Cup']);
    enteredPlayer($tournament, 'satsjaeger', 1200);
    $draft = Tournament::factory()->create();

    $html = $this->get(route('tournaments.show', $tournament))->getContent();
    preg_match('/property="og:image" content="([^"]+)"/', $html, $image);

    expect($image[1] ?? '')->toContain('/cards/en/tournament-invite/'.$tournament->id.'-wide.png?v=');

    $png = $this->get(route('cards.tournament-invite', ['locale' => 'en', 'tournament' => $tournament->id, 'format' => 'wide'], false))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();

    $size = getimagesizefromstring($png);

    expect([$size[0] ?? null, $size[1] ?? null])->toBe([1200, 630]);

    $this->get(route('cards.tournament-invite', ['locale' => 'en', 'tournament' => $draft->id, 'format' => 'wide'], false))->assertNotFound();
});

test('the sign-up page seats the player: open spots and the countdown before, the confirmation with the seed and the invite after', function () {
    $tournament = openTournament(['capacity' => 8]);
    enteredPlayer($tournament, 'topseed', 1500);
    [$user, $signer] = keyedPlayer();
    casualElo($user, 1200);

    $page = Livewire\Livewire::actingAs($user)->test('pages::tournaments.signup', ['tournament' => $tournament])
        ->assertSee('data-test="arena"', false)
        ->assertSee('7 spots left')
        ->assertSee('Sign-up closes in')
        ->assertSee('data-test="enter-solo"', false)
        ->assertDontSee('data-test="my-entry"', false);

    $templates = $page->instance()->prepareSolo();
    $page->call('enterSolo', json_encode($signer->signTemplates($templates)))
        ->assertSet('justEntered', true)
        ->assertSee('data-just-entered', false)
        ->assertSee('You’re in!')
        ->assertSee('Seed 2 if sign-up closed now.')
        ->assertSee('Round 1 starts')
        ->assertSee('data-test="tournament-share"', false)
        ->assertSee('data-test="withdraw"', false);

    // A later visit shows the same confirmation without the burst.
    Livewire\Livewire::actingAs($user)->test('pages::tournaments.signup', ['tournament' => $tournament])
        ->assertSee('You’re in!')->assertDontSee('data-just-entered', false);
});
