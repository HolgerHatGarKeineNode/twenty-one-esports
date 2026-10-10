<?php

use App\Enums\TournamentStatus;
use App\Events\BoardGameStarted;
use App\Games\Blockli;
use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Games\TrackmaniaNationsForever;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Board\BoardInvites;
use App\Support\GameNames;
use App\Support\Invites\InviteGames;
use App\Support\Navigation\ShellNavigation;
use App\Support\Nostr\NostrKeys;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\TournamentGames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlockliOn;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

/*
| Nine men's morris, checkers and Blockli are correspondence only (user,
| 2026-10-07: "Blitz und andere Schnellspiel Matchmaking Modes aus Mühle,
| Dame und Blockli ausbauen … nur bei Schach sinnvoll"). No surface offers
| their blitz: not the registry, the menus, /play, home, a lobby, the rules,
| the invite module, the tournament chooser, the sitemap or a casual cup; an
| old blitz ladder address leads to the correspondence ladder. The open
| blitz cups are called off with esports:cancel-board-blitz-cups.
*/

const BOARD_GAMES_NO_BLITZ = [NineMensMorris::SLUG, Checkers::SLUG, Blockli::SLUG];

beforeEach(function () {
    Queue::fake();
    NineMensMorrisOn::play();
    CheckersGame::play();
    BlockliOn::play();
});

test('the three board games offer correspondence only, one move a day, and their lobby mode is correspondence', function () {
    $registry = app(GameRegistry::class);

    foreach (BOARD_GAMES_NO_BLITZ as $slug) {
        $game = $registry->get($slug);

        expect(array_keys($game->modes()))->toBe(['correspondence'], $slug)
            ->and($game->mode('correspondence')?->timeControl)->toBe('1/86400')
            ->and($registry->mode($slug, 'blitz'))->toBeNull()
            ->and($game->lobbyMode())->toBe('correspondence')
            // The invite module and the tournament chooser offer only that.
            ->and(app(InviteGames::class)->find($slug)['modes'])->toBe(['correspondence'])
            ->and(TournamentGames::keyOf($slug, 'blitz'))->toBeNull()
            ->and(TournamentGames::keyOf($slug, 'correspondence'))->not->toBeNull();
    }

    // Chess keeps its fast modes.
    expect($registry->mode('chess', 'blitz')?->timeControl)->toBe('300+3');
});

test('no surface offers blitz for them: menus, /play, home, their lobbies, the rules and the sitemap', function () {
    $this->actingAs(User::factory()->create());

    // The context bar and the hub: "Play correspondence" first, no "Play blitz", no second link to the same page.
    foreach (ShellNavigation::current()->games() as $game) {
        if (in_array($game['slug'], BOARD_GAMES_NO_BLITZ, true)) {
            expect(array_column($game['actions'], 'label'))->toContain('Play correspondence')->not->toContain('Play blitz')->not->toContain('Correspondence')
                ->and(array_column($game['actions'], 'icon'))->not->toContain('bolt');
        }
    }

    $play = $this->get(route('play'))->assertOk()->getContent();
    $home = $this->get(route('home'))->assertOk()->getContent();

    foreach (BOARD_GAMES_NO_BLITZ as $slug) {
        expect(preg_match('~data-test="play-game-'.$slug.'".*?</li>~s', $play, $card))->toBe(1, $slug)
            ->and($card[0])->toContain('Play correspondence')->not->toContain('Blitz')->not->toContain('5+3')->not->toContain('Play blitz')
            ->and(preg_match('~data-test="game-tile" data-game="'.$slug.'".*?</a>~s', $home, $tile))->toBe(1)
            ->and($tile[0])->not->toContain('Blitz')->not->toContain('5+3');

        $lobby = $this->get(route('board.lobby', $slug))->assertOk()->getContent();
        preg_match('~<meta name="description" content="([^"]*)"~', $lobby, $description);

        expect($lobby)->not->toContain('data-test="play-blitz"')->not->toContain('data-test="find-opponent"')->not->toContain('Blitz 5+3 ·')->not->toContain('Play blitz')->not->toContain('>5+3<')
            ->toContain('data-test="play-correspondence"', 'data-mode="correspondence"')
            ->and($description[1] ?? '')->toContain('one move a day')->not->toContain('blitz');
    }

    expect($play)->not->toContain('Blitz 5+3 on a board');

    // The rules: one move a day as the time control of each, never blitz 5+3.
    $rules = $this->get(route('rules'))->assertOk()->getContent();
    foreach (BOARD_GAMES_NO_BLITZ as $slug) {
        expect(preg_match('~id="'.$slug.'".*?</section>~s', $rules, $section))->toBe(1, $slug)
            ->and($section[0])->toContain('1 move a day')->not->toContain('Blitz')->not->toContain('5+3');
    }

    // The sitemap lists their correspondence ladders, no blitz one.
    $sitemap = $this->get(route('sitemap.section', ['section' => 'pages', 'file' => 1]))->assertOk()->getContent();
    foreach (BOARD_GAMES_NO_BLITZ as $slug) {
        expect($sitemap)->toContain(route('ladder.show', [$slug, 'correspondence']))->not->toContain(route('ladder.show', [$slug, 'blitz']));
    }
});

test('an old blitz ladder address leads to the correspondence ladder; chess keeps its blitz ladder', function () {
    foreach (BOARD_GAMES_NO_BLITZ as $slug) {
        $this->get(route('ladder.show', [$slug, 'blitz']))->assertStatus(301)->assertRedirect(route('ladder.show', [$slug, 'correspondence']));
        $this->get(route('ladder.show', [$slug, 'correspondence']))->assertOk();
    }

    $this->get(route('ladder.show', ['chess', 'blitz']))->assertOk();
    $this->get(route('ladder.show', [Blockli::SLUG, 'rapid']))->assertNotFound();
});

test('Blockli stands right after TMNF in every list, then nine men\'s morris and checkers (user, 2026-10-07)', function () {
    tmnfOn();
    $slugs = array_keys(app(GameRegistry::class)->all());
    $tmnf = array_search(TrackmaniaNationsForever::SLUG, $slugs, true);

    expect($tmnf)->not->toBeFalse()
        ->and($slugs[$tmnf + 1] ?? null)->toBe(Blockli::SLUG)
        ->and(array_slice($slugs, -3))->toBe([Blockli::SLUG, NineMensMorris::SLUG, Checkers::SLUG]);
});

/**
 * An open casual cup of `$game` in `$mode` with `$players` signed up, as the cups made before 2026-10-07.
 */
function boardBlitzCup(string $game, string $mode, int $players, string $region = 'eu'): Tournament
{
    $cup = Tournament::factory()->signup()->create([
        'name' => GameNames::game($game).' Casual Cup '.strtoupper($region).' #1', 'game' => $game, 'mode' => $mode,
        'cup_series' => "{$game}-{$region}", 'cup_number' => 1, 'cup_open_series' => "{$game}-{$region}",
        'starts_at' => now()->addDays(3), 'signup_closes_at' => now()->addDays(3),
    ]);

    foreach (User::factory()->count($players)->create() as $player) {
        TournamentSignup::query()->create(['tournament_id' => $cup->id, 'user_id' => $player->id, 'name' => $player->displayName(), 'members' => [$player->id]]);
    }

    return $cup;
}

test('esports:cancel-board-blitz-cups calls off the open blitz cups of the board games, tells their players, and a second run finds nothing', function () {
    $board = User::factory()->withPubkey((new TestSigner)->pubkey)->create();
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)], 'esports.league.nsec' => (new TestSigner)->secret]);

    $blitz = [boardBlitzCup(NineMensMorris::SLUG, 'blitz', 2), boardBlitzCup(Checkers::SLUG, 'blitz', 1, 'us'), boardBlitzCup(Blockli::SLUG, 'blitz', 0)];
    $chess = boardBlitzCup('chess', 'blitz', 1);
    $special = Tournament::factory()->signup()->create(['game' => Checkers::SLUG, 'mode' => 'blitz']);

    // A dry run lists them and changes nothing.
    $this->artisan('esports:cancel-board-blitz-cups', ['--dry-run' => true])
        ->expectsOutputToContain('3 cup(s) would be called off')
        ->assertSuccessful();
    expect(Tournament::query()->where('status', TournamentStatus::Cancelled)->count())->toBe(0);

    $this->artisan('esports:cancel-board-blitz-cups')->expectsOutputToContain('Called off: #'.$blitz[0]->id)->assertSuccessful();

    foreach ($blitz as $cup) {
        expect($cup->refresh()->status)->toBe(TournamentStatus::Cancelled);
    }

    $players = TournamentSignup::query()->whereIn('tournament_id', array_map(fn (Tournament $cup): int => $cup->id, $blitz))->pluck('user_id');

    expect($players)->toHaveCount(3)
        ->and($chess->refresh()->status)->toBe(TournamentStatus::Signup)
        // A tournament an organizer made is no cup: left to its organizer.
        ->and($special->refresh()->status)->toBe(TournamentStatus::Signup);

    // Every player signed up is told, with the reason.
    foreach ($players as $id) {
        $notice = User::query()->findOrFail($id)->notifications()->get()->last();
        expect(json_encode($notice?->data))->toContain('called off')->toContain('one move a day');
    }

    // Idempotent: nothing left to call off, nobody told twice.
    $notices = DB::table('notifications')->count();
    $this->artisan('esports:cancel-board-blitz-cups')->expectsOutputToContain('nothing to call off')->assertSuccessful();
    expect(DB::table('notifications')->count())->toBe($notices);
});

test('the cancel command refuses to run without an admin to log the call-off under', function () {
    config(['esports.board' => []]);
    $cup = boardBlitzCup(Blockli::SLUG, 'blitz', 1);

    $this->artisan('esports:cancel-board-blitz-cups')->expectsOutputToContain('No admin')->assertFailed();
    expect($cup->refresh()->status)->toBe(TournamentStatus::Signup);

    $this->artisan('esports:cancel-board-blitz-cups', ['--actor' => User::factory()->create()->pubkey])->assertFailed();
    expect($cup->refresh()->status)->toBe(TournamentStatus::Signup)
        ->and(CasualCups::enabledGames())->not->toContain(Blockli::SLUG);
});

test('an accepted correspondence invite takes the inviter to the board too, and only the inviter gets the extra pull', function () {
    // User, 2026-10-08: "Ja, beide zum Brett" - the invitee goes there by its own redirect.
    BlockliOn::play();
    Event::fake([BoardGameStarted::class]);
    [$inviter, $invitee] = User::factory()->count(2)->create();
    $invitee->forceFill(['looking_to_play' => Blockli::SLUG.'/correspondence'])->save();

    $invites = app(BoardInvites::class);
    $game = $invites->accept($invites->invite($inviter, $invitee, Blockli::SLUG), $invitee);

    expect($game->mode)->toBe('correspondence');
    Event::assertDispatched(BoardGameStarted::class, fn ($event) => $event->gameId === $game->id && $event->userIds === [$inviter->id] && $event->url === route('board.show', $game));
    Event::assertDispatchedTimes(BoardGameStarted::class, 1);
});
