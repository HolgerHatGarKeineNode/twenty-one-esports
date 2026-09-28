<?php

use App\Enums\TournamentFormat;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\NostrBar;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestSigner;

/*
 * P45: the Nostr bar on every kind of page that has a Nostr object, with the
 * same buttons in the same place: the object's NIP-19 entity, "Open in app"
 * as a nostr: link, message and follow only for a signed-in viewer and never
 * for their own key, and a zap only as a QR code (the Lightning address
 * never as text).
 */

beforeEach(fn () => Http::fake(fn () => Http::response([])));

/**
 * @return array{entity: string|null, context: string|null, buttons: list<string>}
 */
function nostrBarOn(string $html): array
{
    preg_match('~<section aria-label="On Nostr" data-test="nostr-bar" data-context="([a-z]+)"(?:\s+data-entity="([^"]+)")?~', $html, $bar);
    preg_match_all('~data-test="nostr-(open|share|message|zap|follow)"~', $html, $buttons);

    return ['entity' => ($bar[2] ?? '') ?: null, 'context' => $bar[1] ?? null, 'buttons' => array_values(array_unique($buttons[1]))];
}

function signedEvent(TestSigner $key, int $kind, array $tags = []): NostrEvent
{
    return NostrEvent::fromSigned(SignedEvent::fromInput($key->sign($kind, $tags)));
}

test('a player page: npub, open, share, zap as QR only; message and follow for another signed-in player only', function () {
    $player = User::factory()->create(['name' => 'satsjaeger', 'lud16' => 'satsjaeger@walletofsatoshi.com', 'profile_event_at' => now()]);
    $url = route('players.show', $player->npub);

    $guest = nostrBarOn($this->get($url)->assertOk()->assertDontSee('satsjaeger@walletofsatoshi.com')->getContent());
    expect($guest)->toBe(['entity' => $player->npub, 'context' => 'player', 'buttons' => ['open', 'share', 'zap']]);

    $visitor = User::factory()->create();
    $html = $this->actingAs($visitor)->get($url)->assertOk()->assertDontSee('satsjaeger@walletofsatoshi.com')->assertSee('href="nostr:'.$player->npub.'"', false)->getContent();
    expect(nostrBarOn($html)['buttons'])->toBe(['open', 'share', 'message', 'zap', 'follow'])
        ->and($html)->toContain('data-test="nostr-zap-qr"')->toContain('https://njump.me/'.$player->npub);

    // One's own page: no message, no follow.
    expect(nostrBarOn($this->actingAs($player)->get($url)->getContent())['buttons'])->toBe(['open', 'share', 'zap']);
});

test('a player without a Lightning address gets no zap button', function () {
    $player = User::factory()->create();

    expect(nostrBarOn($this->get(route('players.show', $player->npub))->getContent())['buttons'])->toBe(['open', 'share']);
});

test('a clan page: its 32150 naddr, message and follow to its owner', function () {
    $owner = User::factory()->create();
    $clan = Clan::factory()->create(['owner_id' => $owner->id, 'owner_pubkey' => $owner->pubkey]);

    $bar = nostrBarOn($this->actingAs(User::factory()->create())->get(route('clans.show', $clan))->assertOk()->getContent());

    expect($bar)->toBe(['entity' => $clan->naddr(), 'context' => 'clan', 'buttons' => ['open', 'share', 'message', 'follow']])
        ->and(NostrBar::clan($clan)->person['pubkey'])->toBe($owner->pubkey);
});

test('a published tournament: its 31923 naddr, message to its organizer; a draft shows no bar', function () {
    $league = new TestSigner;
    $organizer = User::factory()->create();
    $tournament = Tournament::factory()->rocketLeague(TournamentFormat::DoubleElimination)->signup()->create(['created_by_id' => $organizer->id, 'slug' => 'bar-cup']);
    $tournament->forceFill(['published_at' => now(), 'event_id' => signedEvent($league, 31923, [['d', 'bar-cup']])->id])->save();

    $bar = nostrBarOn($this->actingAs(User::factory()->create())->get(route('tournaments.show', $tournament))->assertOk()->getContent());

    expect($bar)->toBe(['entity' => NostrKeys::naddr(31923, $league->pubkey, 'bar-cup'), 'context' => 'tournament', 'buttons' => ['open', 'share', 'message', 'follow']]);

    $draft = Tournament::factory()->rocketLeague(TournamentFormat::DoubleElimination)->create(['created_by_id' => $organizer->id]);
    expect(nostrBarOn($this->actingAs($organizer)->get(route('tournaments.show', $draft))->getContent())['context'])->toBeNull();
});

test('a chess game: the league record as nevent once signed, message to the opponent for a player, nothing to write for a spectator', function () {
    $white = User::factory()->create();
    $black = User::factory()->create();
    $game = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $white->id, 'black_id' => $black->id]);
    $record = signedEvent(new TestSigner, 64);
    $game->forceFill(['record_event_id' => $record->id])->save();

    $asWhite = nostrBarOn($this->actingAs($white)->get(route('games.show', $game))->assertOk()->getContent());
    expect($asWhite)->toBe(['entity' => NostrKeys::nevent($record->event_id, $record->pubkey, 64), 'context' => 'game', 'buttons' => ['open', 'share', 'message', 'follow']]);

    expect(nostrBarOn($this->actingAs(User::factory()->create())->get(route('games.show', $game))->getContent())['buttons'])->toBe(['open', 'share']);

    // A running game has no record yet: share the page, no nostr link.
    $running = ChessGame::factory()->create(['white_id' => $white->id, 'black_id' => $black->id]);
    $html = $this->actingAs($white)->get(route('games.show', $running))->assertOk()->getContent();
    expect(nostrBarOn($html))->toBe(['entity' => null, 'context' => 'game', 'buttons' => ['share', 'message', 'follow']])
        ->and($html)->toContain('data-test="nostr-no-entity"');
});

test('a series match: the challenge as nevent, message to the other side for a captain, on the public page and in the room', function () {
    $owner = User::factory()->create();
    $clan = Clan::factory()->create(['owner_id' => $owner->id, 'owner_pubkey' => $owner->pubkey]);
    $otherOwner = User::factory()->create();
    $otherClan = Clan::factory()->create(['owner_id' => $otherOwner->id, 'owner_pubkey' => $otherOwner->pubkey]);
    $challenge = signedEvent(new TestSigner, 2150);
    $match = SeriesMatch::factory()->accepted()->create([
        'challenger_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create(['clan_id' => $clan->id])->id,
        'challenged_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create(['clan_id' => $otherClan->id])->id,
        'challenge_event_id' => $challenge->id,
    ]);

    $page = nostrBarOn($this->actingAs($owner)->get(route('matches.show', $match->number))->assertOk()->getContent());
    expect($page['entity'])->toBe(NostrKeys::nevent($challenge->event_id, $challenge->pubkey, 2150))
        ->and($page['buttons'])->toBe(['open', 'share', 'message', 'follow'])
        ->and(NostrBar::opponentContact($match, $owner)?->id)->toBe($otherOwner->id);

    $room = nostrBarOn($this->actingAs($owner)->get(route('matches.room', $match))->assertOk()->getContent());
    expect($room['context'])->toBe('room')->and($room['buttons'])->toBe(['open', 'share', 'message', 'follow']);

    // A spectator can open and share, nothing more.
    expect(nostrBarOn($this->actingAs(User::factory()->create())->get(route('matches.show', $match->number))->getContent())['buttons'])->toBe(['open', 'share']);
});

test('the season page and the stream page have the bar, with a follow of the league and no message', function () {
    $season = openSeason();

    $mining = nostrBarOn($this->actingAs(User::factory()->create())->get(route('mining'))->assertOk()->getContent());
    expect($mining['context'])->toBe('season')
        ->and($mining['entity'])->toStartWith('nevent1')
        ->and($mining['buttons'])->not->toContain('message')->toContain('follow');

    $live = nostrBarOn($this->actingAs(User::factory()->create())->get(route('live'))->assertOk()->getContent());
    expect($live['context'])->toBe('stream')->and($live['buttons'])->not->toContain('message')->not->toContain('zap');
});

test('the Lightning address of a player never reaches the page, as text or in the bar\'s data', function () {
    $player = User::factory()->create(['lud16' => 'secretname@getalby.com', 'profile_event_at' => now()]);

    $this->actingAs(User::factory()->create())->get(route('players.show', $player->npub))
        ->assertOk()->assertDontSee('secretname@getalby.com')->assertDontSee('secretname', false);
});
