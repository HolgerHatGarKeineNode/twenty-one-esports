<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\TournamentSponsor;
use App\Models\User;
use App\Support\Cards\ShareCard;
use App\Support\LeagueTime;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/*
| Reaching tournaments (user, 2026-09-28): every game page shows the game's
| next tournament open for sign-up near the top, or says there is none and
| leads on; the prize pool, edit and payouts are buttons for the people who
| may use them (<x-tournaments.manage-actions>) on the admin list, the
| tournament page and the edit page, and for nobody else.
*/

/** @param  array<string, mixed>  $attributes */
function openFor(string $game, string $mode, array $attributes = []): Tournament
{
    return Tournament::factory()->signup()->create([
        'game' => $game, 'mode' => $mode, 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::defaults(GameProfile::for($game, $mode))->toArray(),
        'signup_closes_at' => now()->addDays(2), 'starts_at' => now()->addDays(3), ...$attributes,
    ]);
}

test('every game page shows its own next tournament near the top: name, start with zone, places and the way in', function (string $url, string $game, string $mode, string $section) {
    $later = openFor($game, $mode, ['name' => 'Later Cup', 'signup_closes_at' => now()->addDays(6), 'starts_at' => now()->addDays(7)]);
    $next = openFor($game, $mode, ['name' => 'Soonest Cup']);
    $other = openFor($game === 'chess' ? 'rocket-league' : 'chess', $game === 'chess' ? '3v3' : 'blitz', ['name' => 'Other Game Cup', 'signup_closes_at' => now()->addHours(5)]);

    $html = $this->actingAs(User::factory()->create())->get($url)->assertOk()->getContent();
    $card = str($html)->after('data-test="next-tournament" data-tournament="')->before('</section>')->toString();

    expect($card)->toStartWith($next->id.'"')
        ->toContain('Soonest Cup')
        ->toContain('data-test="next-tournament-start"')
        ->toContain(LeagueTime::stamp($next->starts_at))
        ->toContain('data-test="next-tournament-places"')
        ->toContain('data-test="next-tournament-countdown"')
        ->toContain('data-test="next-tournament-seats"')
        ->toContain('data-game-cover="'.$game.'"')
        ->toContain('href="'.route('tournaments.signup', $next).'"')
        ->and($html)->not->toContain($later->name)
        ->not->toContain($other->name)
        ->not->toContain('data-test="next-tournament-empty"')
        // Near the top: before the page's first section below it.
        ->and(strpos($html, 'data-test="next-tournament"'))->toBeLessThan(strpos($html, $section));
})->with([
    'rocket league' => ['/games/rocket-league', 'rocket-league', '3v3', 'data-test="game-matches"'],
    'ea sports fc 26' => ['/games/ea-sports-fc-26', 'ea-sports-fc-26', '1v1', 'data-test="game-matches"'],
    'ea sports fc 27' => ['/games/ea-sports-fc-27', 'ea-sports-fc-27', '1v1', 'data-test="game-matches"'],
    'chess' => ['/chess', 'chess', 'blitz', 'data-test="lobby-daily"'],
]);

test('a game page without an open tournament says so and leads on; only admins and organizers get "Create a tournament"', function () {
    openFor('rocket-league', '3v3', ['status' => TournamentStatus::Draft, 'name' => 'Draft Cup']);
    openFor('rocket-league', '3v3', ['name' => 'Closed Cup', 'signup_closes_at' => now()->subMinute()]);

    // Rocket League leads to its own tournaments (plan "RL-Startseite": the prize band's button), chess to every tournament.
    foreach (['/games/rocket-league' => route('tournaments.index', ['game' => 'rocket-league']), '/chess' => route('tournaments.index')] as $url => $all) {
        $this->get($url)->assertOk()
            ->assertSeeHtml('data-test="next-tournament-empty"')
            ->assertDontSeeHtml('data-test="next-tournament"')
            ->assertSeeHtml('href="'.e($all).'" class')
            ->assertDontSeeHtml('data-test="next-tournament-create"')
            ->assertDontSee('Draft Cup')
            ->assertDontSee('Closed Cup');
    }

    $this->actingAs(User::factory()->create())->get('/games/rocket-league')->assertDontSeeHtml('data-test="next-tournament-create"');
    $this->actingAs(organizer())->get('/games/rocket-league')->assertSeeHtml('data-test="next-tournament-create"');
    $this->actingAs(anAdmin())->get('/chess')->assertSeeHtml('data-test="next-tournament-create"');
});

test('the prize pool, edit and payouts are buttons for whoever may use them, on the admin list, the tournament page and the edit page', function () {
    fakeWallet();
    $owner = organizer();
    $unset = openTournament(['created_by_id' => $owner->id, 'name' => 'No Pot Yet']);
    $withPot = publishForPool(Tournament::factory()->create(['created_by_id' => $owner->id, 'status' => TournamentStatus::Signup]), ownPotWallet(0));
    $draft = Tournament::factory()->create(['created_by_id' => $owner->id, 'status' => TournamentStatus::Draft]);

    // The owner (an organizer): pool set up or not, edit; never payouts (admins only).
    $list = $this->actingAs($owner)->get(route('admin.tournaments'))->assertOk()->getContent();
    expect(substr_count($list, 'data-test="manage-pool"'))->toBe(2)
        ->and($list)->toContain('href="'.route('tournaments.pool', $unset).'" class')
        ->and(substr_count($list, 'data-test="manage-edit"'))->toBe(3)
        ->and($list)->not->toContain('data-test="manage-payouts"');

    $this->get(route('tournaments.show', $unset))->assertOk()->assertSeeHtml('data-test="manage-bar"')
        ->assertSeeInOrder(['data-test="manage-bar"', __('Set up the prize pool'), 'data-test="tournament-hero"'], false);
    $this->get(route('tournaments.show', $withPot))->assertSeeInOrder(['data-test="manage-bar"', __('Prize pool'), 'data-test="tournament-hero"'], false);
    $this->get(route('tournaments.show', $draft))->assertSeeHtml('data-test="manage-edit"')->assertDontSeeHtml('data-test="manage-pool"');
    $this->get(route('admin.tournaments.edit', $unset))->assertOk()->assertSeeHtml('data-test="manage-pool"')->assertDontSeeHtml('data-test="manage-edit"');

    // An admin: everything, payouts once the pot is open in its own wallet.
    $admin = anAdmin();
    $this->actingAs($admin)->get(route('tournaments.show', $withPot))->assertSeeHtml('href="'.route('admin.payouts', ['tournament' => $withPot->id]).'"');
    $this->get(route('tournaments.show', $unset))->assertSeeHtml('data-test="manage-pool"')->assertDontSeeHtml('data-test="manage-payouts"');
    expect(substr_count($this->get(route('admin.tournaments'))->getContent(), 'data-test="manage-payouts"'))->toBe(1);

    // Another organizer, a player, a guest: none of it.
    foreach ([organizer(), User::factory()->create()] as $viewer) {
        $this->actingAs($viewer)->get(route('tournaments.show', $withPot))->assertOk()
            ->assertDontSeeHtml('data-test="manage-bar"')->assertDontSeeHtml('data-test="manage-actions"')
            ->assertDontSeeHtml('href="'.route('tournaments.pool', $withPot).'"');
        // The component gates on its own, whatever page renders it.
        expect(Blade::render('<x-tournaments.manage-actions :tournament="$t" />', ['t' => $withPot]))->not->toContain('data-test="manage-');
    }
    auth()->logout();
    expect(Blade::render('<x-tournaments.manage-actions :tournament="$t" />', ['t' => $withPot]))->not->toContain('data-test="manage-');
    $this->get(route('tournaments.show', $withPot))->assertOk()->assertDontSeeHtml('data-test="manage-actions"')->assertDontSeeHtml(route('admin.payouts'));
});

test('the prize pot heads the tournament page: the pot, the podium and the paid sponsors with logo before the call to action', function () {
    fakeWallet();
    $tournament = publishForPool(Tournament::factory()->create(['created_by_id' => organizer()->id, 'status' => TournamentStatus::Signup,
        'prize_mode' => Tournament::PRIZES_FIXED, 'prize_fixed' => [60000, 30000, 10000]]), ownPotWallet(0));
    $tournament->forceFill(['pot_can_receive' => true])->save();

    // No paid sponsor yet: it says so, and anyone can add to the pot; "Add a sponsor" is the organizer's.
    $this->actingAs(User::factory()->create())->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeInOrder(['data-test="tournament-hero"', 'data-test="prize-pool"', 'data-test="pool-sats">'.ShareCard::sats(100000).'<',
            'data-test="pool-left"', 'data-test="pool-podium"', 'data-test="pool-no-sponsor"', 'href="#pot-fill"', 'data-test="signup-cta"', 'id="pot-fill"'], false)
        ->assertDontSeeHtml('data-test="pool-add-sponsor"');
    $this->actingAs($tournament->creator)->get(route('tournaments.show', $tournament))
        ->assertSeeHtml('href="'.route('tournaments.pool', $tournament).'#sponsors-h"');

    // A paid sponsor shows with name and logo; a pledge alone does not.
    $paid = new TournamentSponsor;
    $paid->forceFill(['tournament_id' => $tournament->id, 'name' => 'Satoshi Pizza', 'pledged_sats' => 5000, 'logo_path' => 'sponsor-logos/pizza.png'])->save();
    $pledge = new TournamentSponsor;
    $pledge->forceFill(['tournament_id' => $tournament->id, 'name' => 'Pledge Only', 'pledged_sats' => 5000])->save();
    (new IncomingPayment)->forceFill(['pot' => 'tournament:'.$tournament->id, 'tournament_id' => $tournament->id, 'sponsor_id' => $paid->id, 'source' => 'sponsor',
        'amount_sats' => 5000, 'bolt11' => 'lnbc1', 'payment_hash' => hash('sha256', 'reach'), 'status' => IncomingPaymentStatus::Settled,
        'expires_at' => now()->addHour(), 'settled_at' => now()])->save();

    $this->get(route('tournaments.show', $tournament))
        ->assertSeeInOrder(['data-test="prize-pool"', 'data-test="pool-sponsor"', 'sponsor-logos/pizza.png', 'Satoshi Pizza', 'data-test="signup-cta"'], false)
        ->assertDontSee('Pledge Only')
        ->assertDontSeeHtml('data-test="pool-no-sponsor"');
});

test('a special tournament heads every page even when a casual cup starts sooner; the cup is only a side mention', function () {
    $cup = openFor('chess', 'blitz', ['name' => 'Chess Casual Cup EU #1', 'cup_series' => 'chess-eu', 'cup_number' => 1,
        'signup_closes_at' => now()->addHours(2), 'starts_at' => now()->addHours(3), 'published_at' => now()]);
    $special = openFor('chess', 'blitz', ['name' => 'Friday Blitz Special', 'published_at' => now()]);
    $rlCup = openFor('rocket-league', '3v3', ['name' => 'Rocket League Casual Cup EU #1', 'cup_series' => 'rocket-league-eu', 'cup_number' => 1, 'published_at' => now()]);

    $pages = [
        '/chess' => 'data-test="next-tournament" data-tournament="',
        route('tournaments.index') => 'data-test="next-tournament" data-tournament="',
        route('home') => 'data-test="home-hero" data-tournament="',
    ];

    foreach ($pages as $url => $hero) {
        $html = $this->get($url)->assertOk()->getContent();

        expect(str($html)->after($hero)->before('"')->toString())->toBe((string) $special->id, $url)
            ->and($html)->toContain('data-test="cup-mention"')
            ->and(strpos($html, 'Chess Casual Cup EU #1'))->toBeGreaterThan(strpos($html, $hero), $url);
    }

    // A game with only a cup: the empty state and the cup's row, never a cup poster.
    $this->get('/games/rocket-league')->assertOk()
        ->assertSeeHtml('data-test="next-tournament-empty"')
        ->assertDontSeeHtml('data-test="next-tournament"')
        ->assertSeeHtml('data-test="cup-mention"')
        ->assertSeeHtml('href="'.route('tournaments.show', $rlCup).'"');

    // Only cups open anywhere: no hero at all on home, and none on the index.
    $special->forceFill(['status' => TournamentStatus::Draft])->save();
    $this->get(route('home'))->assertOk()->assertDontSeeHtml('data-test="home-hero"')->assertSeeHtml('data-test="cup-mention"');
    $this->get(route('tournaments.index'))->assertOk()->assertDontSeeHtml('data-test="next-tournament"');
    expect($cup->refresh()->isCasualCup())->toBeTrue();
});

test('a draft puts Publish tournament first on the admin list, in a banner at the top of its page and on its edit page, for whoever may publish it', function () {
    $owner = organizer();
    $draft = Tournament::factory()->create(['created_by_id' => $owner->id, 'status' => TournamentStatus::Draft, 'name' => 'Draft Cup']);
    $published = Tournament::factory()->signup()->create(['created_by_id' => $owner->id, 'name' => 'Open Cup', 'published_at' => now()]);
    $publishHref = 'href="'.route('tournaments.show', $draft).'#publish"';

    // The list: one Publish, the draft's, orange and before Edit; none for the published row.
    foreach ([$owner, anAdmin()] as $manager) {
        $list = $this->actingAs($manager)->get(route('admin.tournaments'))->assertOk()->getContent();
        $draftRow = str($list)->after('>Draft Cup<')->before('</tr>')->toString();
        $openRow = str($list)->after('>Open Cup<')->before('</tr>')->toString();
        expect(substr_count($list, 'data-test="manage-publish"'))->toBe(1)
            ->and($draftRow)->toContain($publishHref)
            ->and(strpos($draftRow, 'data-test="manage-publish"'))->toBeLessThan(strpos($draftRow, 'data-test="manage-edit"'))
            ->and(str($draftRow)->before('data-test="manage-publish"')->afterLast('<a ')->toString())->toContain('bg-btc ')
            ->and($openRow)->toContain('data-test="manage-edit"')->not->toContain('manage-publish');
    }

    // The page: the banner with the form above the hero; the bar above it carries no second Publish.
    $this->actingAs($owner)->get(route('tournaments.show', $draft))->assertOk()
        ->assertSeeInOrder(['data-test="manage-bar"', 'id="publish"', 'data-test="draft-banner"', __('Players cannot see this tournament yet'),
            'data-test="publish-form"', __('Publish tournament'), 'data-test="tournament-hero"'], false)
        ->assertDontSeeHtml('data-test="manage-publish"');
    $this->get(route('tournaments.show', $published))->assertOk()->assertDontSeeHtml('data-test="draft-banner"')->assertDontSeeHtml('data-test="publish-form"');

    // The edit page: Save and publish at the top in the actions and beside the save, both a save first, never a plain link that drops edits.
    $edit = $this->get(route('admin.tournaments.edit', $draft))->assertOk()->getContent();
    $top = str($edit)->after('data-test="admin-actions"')->before('</header>')->toString();
    expect($top)->toContain('wire:click="saveAndPublish"')->toContain('data-test="edit-publish-top"')->not->toContain('manage-publish')->not->toContain('#publish')
        ->and(str($edit)->after('data-test="edit-save"')->before('</span>')->toString())->toContain('data-test="edit-publish"')->toContain('wire:click="saveAndPublish"')->not->toContain('#publish');
    $this->get(route('admin.tournaments.edit', $published))->assertOk()->assertDontSeeHtml('saveAndPublish')->assertDontSeeHtml('data-test="manage-publish"');

    // A tournament director who may not manage it sees the draft, but no banner and no Publish.
    $director = User::factory()->create();
    $draft->directors()->attach($director->id, ['added_by_id' => $owner->id]);
    $this->actingAs($director)->get(route('tournaments.show', $draft))->assertOk()
        ->assertDontSeeHtml('data-test="draft-banner"')->assertDontSeeHtml('data-test="publish-form"')->assertDontSeeHtml('#publish"');

    // Another organizer's list does not hold the draft; a player has no list; a player and a guest get the draft's 404.
    $this->actingAs(organizer())->get(route('admin.tournaments'))->assertOk()->assertDontSee('Draft Cup')->assertDontSeeHtml('manage-publish');
    $this->actingAs(User::factory()->create())->get(route('admin.tournaments'))->assertForbidden();
    $this->get(route('tournaments.show', $draft))->assertNotFound();
    expect(Blade::render('<x-tournaments.manage-actions :tournament="$t" />', ['t' => $draft]))->not->toContain('manage-publish');
    auth()->logout();
    $this->get(route('tournaments.show', $draft))->assertNotFound();
    expect(Blade::render('<x-tournaments.manage-actions :tournament="$t" />', ['t' => $draft]))->not->toContain('manage-publish');
});

test('a new draft lands on its page with the notice in the banner, and saving a draft says to publish it when ready', function () {
    $draft = Tournament::factory()->create(['created_by_id' => organizer()->id, 'status' => TournamentStatus::Draft, 'name' => 'Draft Cup']);

    $this->actingAs($draft->creator)->withSession(['status' => __(':name was created as a draft.', ['name' => 'Draft Cup'])])
        ->get(route('tournaments.show', $draft))->assertOk()
        ->assertSeeInOrder(['data-test="draft-banner"', 'data-test="draft-notice"', 'Draft Cup was created as a draft.', 'data-test="publish-form"'], false);

    Livewire::actingAs($draft->creator)->test('pages::admin.tournament-edit', ['tournament' => $draft])
        ->set('name', 'Draft Cup Renamed')
        ->call('save')
        ->assertSet('error', '')
        ->assertSet('notice', __('Saved. Publish it when ready.'))
        ->assertSeeHtml('data-test="edit-publish"');

    expect($draft->refresh()->name)->toBe('Draft Cup Renamed')->and($draft->status)->toBe(TournamentStatus::Draft);
});

test('Save and publish on the edit page stores the edits before it opens the publish form, and a refused save stays with its errors', function () {
    $draft = Tournament::factory()->create(['created_by_id' => organizer()->id, 'status' => TournamentStatus::Draft, 'name' => 'Draft Cup']);

    Livewire::actingAs($draft->creator)->test('pages::admin.tournament-edit', ['tournament' => $draft])
        ->set('name', '')
        ->call('saveAndPublish')
        ->assertHasErrors(['name' => 'required'])
        ->assertNoRedirect();
    expect($draft->refresh()->name)->toBe('Draft Cup');

    Livewire::actingAs($draft->creator)->test('pages::admin.tournament-edit', ['tournament' => $draft])
        ->set('name', 'Draft Cup Renamed')
        ->call('saveAndPublish')
        ->assertHasNoErrors()
        ->assertSet('error', '')
        ->assertRedirect(route('tournaments.show', $draft).'#publish');
    expect($draft->refresh()->name)->toBe('Draft Cup Renamed')->and($draft->status)->toBe(TournamentStatus::Draft);

    // Only a draft has it.
    $open = Tournament::factory()->signup()->create(['created_by_id' => $draft->created_by_id, 'published_at' => now()]);
    Livewire::actingAs($draft->creator)->test('pages::admin.tournament-edit', ['tournament' => $open])
        ->call('saveAndPublish')->assertForbidden();
});
