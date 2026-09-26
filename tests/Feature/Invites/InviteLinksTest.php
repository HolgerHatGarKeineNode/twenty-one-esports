<?php

use App\Enums\ChessGameStatus;
use App\Enums\InviteLinkType;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\InviteLink;
use App\Models\InviteLinkUse;
use App\Models\Lineup;
use App\Models\MatchNumber;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Invites\InviteCard;
use App\Support\Invites\InviteLinkRefused;
use App\Support\Invites\InviteLinks;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    Http::fake(fn () => Http::response([]));
    $this->links = app(InviteLinks::class);
});

/**
 * Take a link and return the refusal code, or null when it worked.
 */
function inviteRefusal(InviteLink $link, User $user, array $choice = []): ?string
{
    try {
        app(InviteLinks::class)->accept($link, $user, $choice);
    } catch (InviteLinkRefused $refused) {
        return $refused->reason;
    }

    return null;
}

/**
 * A ready Rocket League lineup captained by its clan owner.
 */
function inviteLineup(string $mode = '3v3', array $clan = []): Lineup
{
    $owner = User::factory()->create();

    return Lineup::factory()->mode($mode)->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $owner->id, ...$clan])->id])->load('clan');
}

function inviteSeriesLink(Lineup $lineup): InviteLink
{
    $start = now()->addDay()->startOfMinute()->getTimestamp();

    return app(InviteLinks::class)->create($lineup->clan->owner, InviteLinkType::Series, [
        'lineup_id' => $lineup->id, 'best_of' => 3, 'proposals' => [$start, $start + 3600], 'respond_by' => $start - 600,
    ]);
}

/* ---------- The code ---------------------------------------------------------------------------------------------- */

test('a link code is 22 random base62 characters, different every time, and the only way in', function () {
    $inviter = User::factory()->create();
    $first = $this->links->create($inviter, InviteLinkType::Blitz);
    $second = $this->links->create($inviter, InviteLinkType::Blitz);

    // 62^22 ≈ 2^131: at least 128 bits, not derived from the id.
    expect($first->code)->toMatch('/^[A-Za-z0-9]{22}$/')
        ->and($second->code)->toMatch('/^[A-Za-z0-9]{22}$/')
        ->and($first->code)->not->toBe($second->code);

    $this->get('/i/'.$first->code)->assertOk();
    $this->get('/i/'.substr($first->code, 0, 21).(str_ends_with($first->code, 'A') ? 'B' : 'A'))->assertNotFound();
    $this->get('/i/'.$first->id)->assertNotFound();
});

test('every link type has its landing, for a guest and for a logged-in player', function (Closure $makeLink, string $heading) {
    $link = $makeLink();

    $this->get($link->url())->assertOk()->assertSee($heading)->assertSeeHtml('data-test="login-google"');
    $this->actingAs(User::factory()->create())->get($link->url())->assertOk()->assertSee($heading)->assertDontSeeHtml('data-test="login-google"');
})->with([
    'blitz' => [fn () => app(InviteLinks::class)->create(User::factory()->create(['name' => 'satsjäger']), InviteLinkType::Blitz), 'satsjäger challenges you to blitz chess'],
    'daily' => [fn () => app(InviteLinks::class)->create(User::factory()->create(['name' => 'satsjäger']), InviteLinkType::Daily), 'satsjäger challenges you to daily chess'],
    'series' => [fn () => inviteSeriesLink(inviteLineup(clan: ['name' => 'Laser Eyes'])), 'Laser Eyes challenges your team to Rocket League'],
    'clan' => [fn () => app(InviteLinks::class)->create(($clan = Clan::factory()->create(['name' => 'Laser Eyes']))->owner, InviteLinkType::Clan, ['clan' => $clan]), 'invites you to join Laser Eyes'],
]);

/* ---------- Game links are open --------------------------------------------------------------------------------- */

test('a stranger takes an open blitz link: a casual live game starts, the inviter is told, the referral is stored', function () {
    [$anna, $stranger] = User::factory()->count(2)->create();
    $link = $this->links->create($anna, InviteLinkType::Blitz);

    Livewire::actingAs($stranger)->test('pages::invites.link', ['link' => $link])
        ->assertSee(__(':name challenges you to blitz chess', ['name' => $anna->displayName()]))
        ->call('accept')
        ->assertRedirect(route('games.show', ChessGame::query()->sole()));

    $game = ChessGame::query()->sole();
    expect($game->mode)->toBe('blitz')
        ->and($game->rated)->toBeFalse()
        ->and($game->status)->toBe(ChessGameStatus::Active)
        ->and([$game->white_id, $game->black_id])->toEqualCanonicalizing([$anna->id, $stranger->id])
        ->and($link->refresh()->uses)->toBe(1);

    $use = InviteLinkUse::query()->sole();
    expect($use->only(['inviter_id', 'user_id', 'chess_game_id', 'was_new']))
        ->toBe(['inviter_id' => $anna->id, 'user_id' => $stranger->id, 'chess_game_id' => $game->id, 'was_new' => false]);
    expect($anna->notifications()->sole()->data['title'])->toBe(__(':name took your blitz invite', ['name' => $stranger->displayName()]));
});

test('a daily link starts a daily game with the colour the inviter chose', function () {
    [$anna, $stranger] = User::factory()->count(2)->create();
    $link = $this->links->create($anna, InviteLinkType::Daily, ['color' => 'black']);

    $game = $this->links->accept($link, $stranger);

    expect($game)->toBeInstanceOf(ChessGame::class)
        ->and($game->mode)->toBe(ChessGame::CORRESPONDENCE)
        ->and($game->black_id)->toBe($anna->id)
        ->and($game->white_id)->toBe($stranger->id)
        ->and($anna->notifications()->sole()->data['title'])->toBe(__(':name accepted your daily challenge', ['name' => $stranger->displayName()]));
});

test('a link for several friends gives each a game, but nobody takes it twice', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $link = $this->links->create($anna, InviteLinkType::Daily, ['uses' => 'several']);

    expect(inviteRefusal($link, $bert))->toBeNull()
        ->and(inviteRefusal($link, $carl))->toBeNull()
        ->and(inviteRefusal($link, $bert))->toBe('already_taken')
        ->and(ChessGame::query()->count())->toBe(2)
        ->and($link->refresh()->uses)->toBe(2);
});

test('two strangers race for a one-time link: exactly one gets the game', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $link = $this->links->create($anna, InviteLinkType::Daily);
    // Both loaded the page while the link was open: the same stale model.
    $seenByBert = InviteLink::query()->find($link->id);
    $seenByCarl = InviteLink::query()->find($link->id);

    $results = [inviteRefusal($seenByBert, $bert), inviteRefusal($seenByCarl, $carl)];

    expect($results)->toBe([null, 'used_up'])
        ->and(ChessGame::query()->count())->toBe(1)
        ->and(InviteLinkUse::query()->pluck('user_id')->all())->toBe([$bert->id])
        ->and($link->refresh()->uses)->toBe(1);
});

test('a refused link is not used up: a busy inviter keeps the blitz link for later', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $link = $this->links->create($anna, InviteLinkType::Blitz);
    $running = ChessGame::factory()->create(['white_id' => $anna->id]);

    expect(inviteRefusal($link, $bert))->toBe('inviter_busy')
        ->and($link->refresh()->uses)->toBe(0)
        ->and(InviteLinkUse::query()->count())->toBe(0)
        ->and(ChessGame::query()->pluck('id')->all())->toBe([$running->id]);
});

/* ---------- States ------------------------------------------------------------------------------------------------ */

test('each closed state is refused and shown for what it is', function (Closure $close, string $reason, string $heading) {
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $link = $this->links->create($anna, InviteLinkType::Blitz);
    $close($link, $carl);

    expect(inviteRefusal($link, $bert))->toBe($reason);

    Livewire::actingAs($bert)->test('pages::invites.link', ['link' => $link])
        ->assertSee($heading)
        ->assertDontSee('data-test="accept-invite"', false);
})->with([
    'expired' => [fn (InviteLink $link) => test()->travel(25)->hours(), 'expired', 'This invite has run out'],
    'used up by someone else' => [fn (InviteLink $link, User $carl) => app(InviteLinks::class)->accept($link, $carl), 'used_up', 'This invite is already taken'],
    'cancelled by the inviter' => [fn (InviteLink $link) => app(InviteLinks::class)->revoke($link, $link->inviter), 'revoked', 'This invite was cancelled'],
]);

test('the inviter sees their own link to share, and cannot take it', function () {
    $anna = User::factory()->create();
    $link = $this->links->create($anna, InviteLinkType::Blitz);

    expect(inviteRefusal($link, $anna))->toBe('own_link');

    Livewire::actingAs($anna)->test('pages::invites.link', ['link' => $link])
        ->assertSee('Your invite is ready to share')
        ->assertSee($link->url())
        ->assertSeeHtml('data-test="copy-link"')
        ->call('revoke');

    expect($link->refresh()->revoked_at)->not->toBeNull();
});

test('a player who took the link finds their game there again', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $link = $this->links->create($anna, InviteLinkType::Daily);
    $game = $this->links->accept($link, $bert);

    Livewire::actingAs($bert)->test('pages::invites.link', ['link' => $link])
        ->assertSee('You took this invite')
        ->assertSeeHtml('href="'.route('games.show', $game).'"');
});

test('only the inviter can cancel a link', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $link = $this->links->create($anna, InviteLinkType::Blitz);

    expect(fn () => $this->links->revoke($link, $bert))->toThrow(InviteLinkRefused::class);
    expect($link->refresh()->revoked_at)->toBeNull();
});

/* ---------- Guest → login → straight into the game ----------------------------------------------------------------- */

test('a guest logs in from the invite and lands in the game, counted as a new player', function () {
    $anna = User::factory()->create();
    $link = $this->links->create($anna, InviteLinkType::Daily);

    $this->get($link->url())->assertOk()->assertSeeHtml('data-test="login-nostr"');

    // The landing's login button asks for its challenge with the code.
    $challenge = $this->postJson(route('auth.nostr.challenge', ['invite' => $link->code]))->assertOk()->json('challenge');
    $this->postJson(route('auth.nostr.login'), ['event' => (new TestSigner)->loginEvent($challenge)])
        ->assertOk()
        ->assertJson(['redirect' => $link->url()]);

    $this->get($link->url())->assertRedirect(route('games.show', ChessGame::query()->sole()));

    $newcomer = User::query()->whereKeyNot($anna->id)->sole();
    expect(ChessGame::query()->playedBy($newcomer)->exists())->toBeTrue()
        ->and(InviteLinkUse::query()->sole()->only(['user_id', 'was_new']))->toBe(['user_id' => $newcomer->id, 'was_new' => true]);
});

test('viewing an invite alone never takes it: only a login started on it does', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $link = $this->links->create($anna, InviteLinkType::Daily);

    $this->get($link->url())->assertOk();
    $this->actingAs($bert)->get($link->url())->assertOk()->assertSeeHtml('data-test="accept-invite"');

    expect(ChessGame::query()->count())->toBe(0);
});

/* ---------- Rocket League ------------------------------------------------------------------------------------------- */

test('a captain takes a series link with a ready lineup: the match number is reserved only now', function () {
    $home = inviteLineup();
    $away = inviteLineup();
    $link = inviteSeriesLink($home);

    expect(MatchNumber::query()->count())->toBe(0);

    $start = $link->option('proposals')[1];
    $match = $this->links->accept($link, $away->clan->owner, ['lineup_id' => $away->id, 'start' => $start]);

    expect($match)->toBeInstanceOf(SeriesMatch::class)
        ->and($match->status)->toBe(SeriesStatus::Accepted)
        ->and($match->rated)->toBeFalse()
        ->and($match->start_at->getTimestamp())->toBe($start)
        ->and([$match->challenger_lineup_id, $match->challenged_lineup_id])->toBe([$home->id, $away->id])
        ->and(MatchNumber::query()->sole()->only(['id', 'user_id']))->toBe(['id' => $match->number, 'user_id' => $home->clan->owner_id])
        ->and($home->clan->owner->notifications()->where('data->title', __(':clan took your challenge link', ['clan' => $away->clan->name]))->exists())->toBeTrue();
});

test('a series link needs a lineup the taker captains, and refuses before using the link up', function () {
    $home = inviteLineup();
    $link = inviteSeriesLink($home);
    $stranger = User::factory()->create();

    expect(inviteRefusal($link, $stranger, ['lineup_id' => inviteLineup()->id, 'start' => $link->option('proposals')[0]]))->toBe('no_lineup')
        ->and($link->refresh()->uses)->toBe(0)
        ->and(SeriesMatch::query()->count())->toBe(0)
        ->and(MatchNumber::query()->count())->toBe(0);
});

/* ---------- Making links -------------------------------------------------------------------------------------------- */

test('the chess challenge page makes a daily link and opens it to share', function () {
    $anna = User::factory()->create();

    Livewire::actingAs($anna)->test('pages::chess.challenge')
        ->assertDontSee('data-test="link-game-blitz"', false)
        ->set('linkUses', 'several')
        ->call('createLink')
        ->assertRedirect(InviteLink::query()->sole()->url());

    expect(InviteLink::query()->sole()->only(['type', 'inviter_id', 'max_uses']))->toBe(['type' => InviteLinkType::Daily, 'inviter_id' => $anna->id, 'max_uses' => null]);
});

test('a link with an expiry that is not offered is refused with a message', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::chess.challenge')
        ->set('linkHours', 999)
        ->call('createLink')
        ->assertSet('linkError', 'Pick how long the link works.')
        ->assertNoRedirect();

    expect(InviteLink::query()->count())->toBe(0);
});

test('the challenge page makes a casual series link from the captain\'s lineup and times', function () {
    $home = inviteLineup();

    Livewire::actingAs($home->clan->owner)->test('pages::challenges.create', ['lineup' => $home->id])
        ->call('createLink')
        ->assertRedirect(InviteLink::query()->sole()->url());

    $link = InviteLink::query()->sole();
    expect($link->type)->toBe(InviteLinkType::Series)
        ->and($link->option('lineup_id'))->toBe($home->id)
        ->and($link->max_uses)->toBe(1)
        ->and(MatchNumber::query()->count())->toBe(0);
});

/* ---------- What crawlers see --------------------------------------------------------------------------------------- */

test('the landing carries title, description, preview images and noindex in the first response', function () {
    $anna = User::factory()->create(['name' => 'satsjäger']);
    $link = $this->links->create($anna, InviteLinkType::Blitz);

    $this->withHeaders(['User-Agent' => 'TelegramBot (like TwitterBot)'])->get($link->url())
        ->assertOk()
        ->assertSeeHtml('<meta name="robots" content="noindex, nofollow">')
        ->assertSeeHtml('<title>satsjäger challenges you to blitz chess – TWENTY ONE esports</title>')
        ->assertSeeHtml('<meta property="og:title" content="satsjäger challenges you to blitz chess">')
        ->assertSeeHtml('<meta property="og:description" content="Blitz chess 5+3, casual.')
        ->assertSeeHtml('<meta property="og:image" content="'.route('invites.card', ['code' => $link->code, 'format' => 'wide', 'v' => (new InviteCard($link))->fingerprint('wide')]).'">')
        ->assertSeeHtml('<meta property="og:image" content="'.route('invites.card', ['code' => $link->code, 'format' => 'square', 'v' => (new InviteCard($link))->fingerprint('square')]).'">')
        ->assertSeeHtml('<meta property="og:url" content="'.$link->url().'">');
});

test('the preview speaks the inviter\'s language', function () {
    $anna = User::factory()->create(['name' => 'satsjäger', 'locale' => 'de']);
    $link = $this->links->create($anna, InviteLinkType::Daily);

    $this->get($link->url())->assertOk()
        ->assertSeeHtml('<meta property="og:title" content="satsjäger fordert dich zu Fernschach heraus">');
});

test('a player name is escaped on the landing and in the preview tags', function () {
    $anna = User::factory()->create(['name' => '<script>alert(1)</script>"x']);
    $link = $this->links->create($anna, InviteLinkType::Blitz);

    $this->get($link->url())->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});
