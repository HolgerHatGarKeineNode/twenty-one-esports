<?php

use App\Enums\InviteLinkType;
use App\Enums\TournamentStatus;
use App\Models\Cosmetic;
use App\Models\InviteLink;
use App\Models\InviteLinkUse;
use App\Models\User;
use App\Support\Cards\SharePosts;
use App\Support\Engagement\Cosmetics;
use App\Support\Invites\InviteLinkRefused;
use App\Support\Invites\InviteLinks;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
 * The personal tournament link (P47): one per player and tournament, the
 * invite in the "I'm in" post and the invite DM. Opening it shows the
 * tournament page; the sign-up of the invited player credits the inviter
 * with the same referral as every other invite link (a use and the "Brought a
 * friend" frame for both), once.
 */

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

test('every player has one link per tournament, open until sign-up closes, outside the cap of open links', function () {
    $tournament = openTournament(['name' => 'Testnet Cup']);
    $anna = User::factory()->create();
    $links = app(InviteLinks::class);

    $first = $links->forTournament($anna, $tournament);
    $again = $links->forTournament($anna, $tournament);

    expect($first->type)->toBe(InviteLinkType::Tournament)
        ->and($first->tournament_id)->toBe($tournament->id)
        ->and($again->id)->toBe($first->id)
        ->and($again->code)->toBe($first->code)
        ->and($first->max_uses)->toBeNull()
        ->and($first->expires_at->equalTo($tournament->signup_closes_at))->toBeTrue()
        ->and($links->forTournament(User::factory()->create(), $tournament)->code)->not->toBe($first->code)
        ->and(InviteLink::query()->count())->toBe(2);

    // The cap of open links counts game and clan links only.
    InviteLink::factory()->count(InviteLinks::MAX_OPEN_PER_INVITER - 1)->create(['inviter_id' => $anna->id]);
    expect($links->create($anna, InviteLinkType::Blitz)->type)->toBe(InviteLinkType::Blitz);

    // Not made the way game links are, and not for a tournament that takes no sign-ups.
    expect(fn () => $links->create(User::factory()->create(), InviteLinkType::Tournament))->toThrow(InviteLinkRefused::class);

    $tournament->forceFill(['status' => TournamentStatus::Running])->save();
    expect(fn () => $links->forTournament(User::factory()->create(), $tournament))->toThrow(InviteLinkRefused::class)
        ->and($links->tournamentUrlFor(User::factory()->create(), $tournament))->toBeNull()
        ->and($links->tournamentUrlFor(null, openTournament()))->toBeNull();
});

test('the link opens the tournament page, and the invited player\'s sign-up credits the inviter once', function () {
    $tournament = openTournament(['name' => 'Testnet Cup']);
    $anna = User::factory()->create();
    $link = app(InviteLinks::class)->forTournament($anna, $tournament);
    [$ben, $benKey] = keyedPlayer();
    $this->travel(5)->seconds();

    // A guest follows the link: the tournament page, no landing of its own, no card of its own.
    $this->get('/i/'.$link->code)->assertRedirect(route('tournaments.show', $tournament));
    $this->get('/i/'.$link->code.'/card-wide.png')->assertNotFound();

    $this->actingAs($ben)->get('/i/'.$link->code)->assertRedirect(route('tournaments.show', $tournament));
    expect(session('invite.tournament'))->toMatchArray(['code' => $link->code, 'tournament_id' => $tournament->id]);

    $page = Livewire::actingAs($ben)->test('pages::tournaments.signup', ['tournament' => $tournament]);
    $page->call('enterSolo', json_encode($benKey->signTemplates($page->instance()->prepareSolo())))->assertSet('justEntered', true);

    $use = InviteLinkUse::query()->sole();

    expect($use->invite_link_id)->toBe($link->id)
        ->and($use->inviter_id)->toBe($anna->id)
        ->and($use->user_id)->toBe($ben->id)
        ->and($use->was_new)->toBeFalse()
        ->and($link->refresh()->uses)->toBe(1)
        ->and(app(Cosmetics::class)->owns($anna, Cosmetics::INVITE_FRAME))->toBeTrue()
        ->and(app(Cosmetics::class)->owns($ben, Cosmetics::INVITE_FRAME))->toBeTrue();

    // The credit shows where every referral shows: the frame on the inviter's player page.
    $this->get(route('players.show', $anna->npub))->assertOk()->assertSee('data-test="invite-frame-chip"', false);

    // Crediting again (a second call, a withdraw and a new sign-up) changes nothing.
    expect(app(InviteLinks::class)->creditTournamentSignup($tournament, $ben, ['code' => $link->code, 'tournament_id' => $tournament->id, 'seen_at' => 0]))->toBeNull()
        ->and(InviteLinkUse::query()->count())->toBe(1)
        ->and(Cosmetic::query()->count())->toBe(2);
});

test('an account made on the way in is a new referral; own, foreign and closed links credit nothing', function () {
    $tournament = openTournament();
    $anna = User::factory()->create();
    $link = app(InviteLinks::class)->forTournament($anna, $tournament);
    $links = app(InviteLinks::class);
    $seen = now()->getTimestamp();

    $this->travel(5)->seconds();
    $newcomer = User::factory()->create();
    expect($links->creditTournamentSignup($tournament, $newcomer, ['code' => $link->code, 'tournament_id' => $tournament->id, 'seen_at' => $seen])->was_new)->toBeTrue();

    // The inviter's own link: nothing remembered, nothing credited.
    expect($links->rememberTournamentLink($link, $anna))->toBeNull()
        ->and($links->creditTournamentSignup($tournament, $anna, ['code' => $link->code, 'tournament_id' => $tournament->id, 'seen_at' => $seen]))->toBeNull();

    // A link of another tournament, a forged code, a revoked link: nothing.
    $other = openTournament();
    $cara = User::factory()->create();
    expect($links->creditTournamentSignup($other, $cara, ['code' => $link->code, 'tournament_id' => $other->id, 'seen_at' => $seen]))->toBeNull()
        ->and($links->creditTournamentSignup($tournament, $cara, ['code' => str_repeat('a', 22), 'tournament_id' => $tournament->id, 'seen_at' => $seen]))->toBeNull()
        ->and($links->creditTournamentSignup($tournament, $cara, null))->toBeNull();

    $link->forceFill(['revoked_at' => now()])->save();
    expect($links->creditTournamentSignup($tournament, $cara, ['code' => $link->code, 'tournament_id' => $tournament->id, 'seen_at' => $seen]))->toBeNull()
        ->and(InviteLinkUse::query()->count())->toBe(1);
});

test('the "I\'m in" post invites with the entrant\'s personal link instead of the public page', function () {
    $tournament = openTournament(['name' => 'Testnet Cup']);
    [$anna, $annaKey] = keyedPlayer();
    soloSignup($tournament, $anna, $annaKey);
    $tournament->refresh();

    $posts = app(SharePosts::class);
    $template = $posts->prepare($anna, 'signup', (string) $tournament->id);
    $link = InviteLink::query()->where(['inviter_id' => $anna->id, 'tournament_id' => $tournament->id])->sole();
    $personal = rtrim((string) config('app.url'), '/').'/i/'.$link->code;

    expect($template['content'])->toStartWith("I’m in Testnet Cup on TWENTY ONE Esports (Chess blitz). Join me:\n{$personal}\n\n")
        ->and($template['content'])->not->toContain('/tournaments/'.$tournament->id)
        ->and($template['tags'])->toContain(['r', $personal]);

    // The same link on the next preview and in the signed post the league checks.
    expect($posts->prepare($anna, 'signup', (string) $tournament->id)['content'])->toBe($template['content'])
        ->and($posts->submit($anna, 'signup', (string) $tournament->id, $annaKey->sign($template['kind'], $template['tags'], $template['content'], max($template['created_at'], now()->getTimestamp())))->kind)->toBe(1)
        ->and(InviteLink::query()->count())->toBe(1);

    // The tournament page's share buttons carry the same link for her, the page link for a guest.
    $this->actingAs($anna)->get(route('tournaments.show', $tournament))->assertOk()->assertSee(urlencode($personal), false);
    auth()->logout();
    $this->get(route('tournaments.show', $tournament))->assertOk()->assertDontSee($link->code, false);
});
