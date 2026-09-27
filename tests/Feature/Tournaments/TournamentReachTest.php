<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Illuminate\Support\Facades\Blade;

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
        ->toContain('href="'.route('tournaments.signup', $next).'"')
        ->and($html)->not->toContain($later->name)
        ->not->toContain($other->name)
        ->not->toContain('data-test="next-tournament-empty"')
        // Near the top: before the page's first section below it.
        ->and(strpos($html, 'data-test="next-tournament"'))->toBeLessThan(strpos($html, $section));
})->with([
    'rocket league' => ['/games/rocket-league', 'rocket-league', '3v3', 'aria-labelledby="rl-hr"'],
    'ea sports fc 26' => ['/games/ea-sports-fc-26', 'ea-sports-fc-26', '1v1', 'aria-labelledby="rl-hr"'],
    'ea sports fc 27' => ['/games/ea-sports-fc-27', 'ea-sports-fc-27', '1v1', 'aria-labelledby="rl-hr"'],
    'chess' => ['/chess', 'chess', 'blitz', 'data-test="lobby-daily"'],
]);

test('a game page without an open tournament says so and leads on; only admins and organizers get "Create a tournament"', function () {
    openFor('rocket-league', '3v3', ['status' => TournamentStatus::Draft, 'name' => 'Draft Cup']);
    openFor('rocket-league', '3v3', ['name' => 'Closed Cup', 'signup_closes_at' => now()->subMinute()]);

    foreach (['/games/rocket-league', '/chess'] as $url) {
        $this->get($url)->assertOk()
            ->assertSeeHtml('data-test="next-tournament-empty"')
            ->assertDontSeeHtml('data-test="next-tournament"')
            ->assertSeeHtml('href="'.route('tournaments.index').'" class')
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
