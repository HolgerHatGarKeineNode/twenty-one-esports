<?php

use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Comments\CommentRefused;
use App\Support\Comments\CommentTarget;
use App\Support\Comments\NostrAuthors;
use App\Support\Comments\NostrComments;
use App\Support\Comments\Rsvps;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use App\Support\Tournaments\TournamentSignups;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/**
 * Comments, likes and RSVPs on Nostr (P48, NIP "Comments, likes and RSVPs",
 * rev. 9.9): the exact event shapes (NIP-22 root and parent, NIP-25, NIP-52),
 * that the league takes only the event it prepared, signed by the player,
 * within the rules 38 to 40, and that nothing is offered where there is no
 * league event.
 */
beforeEach(function () {
    Queue::fake();
    config(['esports.relays' => ['wss://league.example'], 'app.url' => 'https://esports.example']);
});

function commentSign(TestSigner $signer, array $template): array
{
    return $signer->sign($template['kind'], $template['tags'], $template['content'], max($template['created_at'], now()->getTimestamp()));
}

/** A published tournament signed by a throwaway league key; returns [tournament, league key]. */
function commentTournament(): array
{
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);

    return [openTournament(['name' => 'Testnet Cup'])->refresh(), $league];
}

test('a comment on a tournament: NIP-22 root A/K/P and the same item as parent a (with the version e)/k/p, no hashtag', function () {
    [$tournament, $league] = commentTournament();
    [$anna, $annaKey] = keyedPlayer();
    $address = '31923:'.$league->pubkey.':'.$tournament->slug;
    $version = $tournament->event->event_id;

    $comments = app(NostrComments::class);
    $template = $comments->commentTemplate($anna, 'tournament', (string) $tournament->id, "  Good luck everyone!\r\nSee you #there  ");

    expect($template['kind'])->toBe(1111)
        ->and($template['content'])->toBe("Good luck everyone!\nSee you #there")
        ->and($template['tags'])->toBe([
            ['A', $address, 'wss://league.example'],
            ['K', '31923'],
            ['P', $league->pubkey, 'wss://league.example'],
            ['a', $address, 'wss://league.example'],
            ['e', $version, 'wss://league.example'],
            ['k', '31923'],
            ['p', $league->pubkey, 'wss://league.example'],
            ['alt', 'Comment on a tournament in TWENTY ONE Esports'],
        ]);

    $stored = $comments->submitComment($anna, 'tournament', (string) $tournament->id, "  Good luck everyone!\r\nSee you #there  ", commentSign($annaKey, $template));

    expect($stored->kind)->toBe(1111)
        ->and($stored->pubkey)->toBe($anna->pubkey)
        ->and($stored->queued_at)->not->toBeNull()
        ->and(collect($stored->payload()['tags'])->where(0, 't')->all())->toBe([]);
});

test('a comment on a rated game roots on the league record (E with author), on a rated series on its challenge; casual pages have none', function () {
    [$anna, $annaKey] = keyedPlayer();
    $league = new TestSigner;
    $record = NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(64, [['alt', 'Chess game']], "1. f3 e5 2. g4 Qh4# 0-1\n")));
    $game = ChessGame::factory()->rated()->finished('0-1')->create(['record_event_id' => $record->id]);

    $template = app(NostrComments::class)->commentTemplate($anna, 'game', (string) $game->id, 'GG');

    expect($template['tags'])->toBe([
        ['E', $record->event_id, 'wss://league.example', $league->pubkey],
        ['K', '64'],
        ['P', $league->pubkey, 'wss://league.example'],
        ['e', $record->event_id, 'wss://league.example', $league->pubkey],
        ['k', '64'],
        ['p', $league->pubkey, 'wss://league.example'],
        ['alt', 'Comment on a chess game in TWENTY ONE Esports'],
    ]);

    expect(app(NostrComments::class)->submitComment($anna, 'game', (string) $game->id, 'GG', commentSign($annaKey, $template))->kind)->toBe(1111);

    // A series: the root of its match chain, the challenge (2150), by its author.
    $challenger = new TestSigner;
    $challenge = NostrEvent::fromSigned(SignedEvent::fromInput($challenger->sign(2150, [['alt', 'Challenge']], '')));
    $series = SeriesMatch::factory()->create(['challenge_event_id' => $challenge->id]);
    $seriesTags = app(NostrComments::class)->commentTemplate($anna, 'series', (string) $series->number, 'Close one')['tags'];

    expect(array_slice($seriesTags, 0, 3))->toBe([
        ['E', $challenge->event_id, 'wss://league.example', $challenger->pubkey],
        ['K', '2150'],
        ['P', $challenger->pubkey, 'wss://league.example'],
    ]);

    // Casual: no league event, nothing to comment on; a draft tournament neither.
    $casualGame = ChessGame::factory()->finished('1-0')->create();
    $casualSeries = SeriesMatch::factory()->create();

    expect(CommentTarget::resolve('game', (string) $casualGame->id))->toBeNull()
        ->and(CommentTarget::resolve('series', (string) $casualSeries->number))->toBeNull()
        ->and(CommentTarget::resolve('tournament', '999999'))->toBeNull()
        ->and(CommentTarget::resolve('nope', '1'))->toBeNull()
        ->and(CommentTarget::resolve('game', '1e3'))->toBeNull()
        ->and(fn () => app(NostrComments::class)->commentTemplate($anna, 'game', (string) $casualGame->id, 'hi'))->toThrow(CommentRefused::class);
});

test('a like: NIP-25 content "+", e the event (the current version), a the address, p its author, k its kind', function () {
    [$tournament, $league] = commentTournament();
    [$anna, $annaKey] = keyedPlayer();

    $comments = app(NostrComments::class);
    $template = $comments->reactionTemplate($anna, 'tournament', (string) $tournament->id);

    expect($template['kind'])->toBe(7)
        ->and($template['content'])->toBe('+')
        ->and($template['tags'])->toBe([
            ['e', $tournament->event->event_id, 'wss://league.example', $league->pubkey],
            ['a', $tournament->address(), 'wss://league.example', $league->pubkey],
            ['p', $league->pubkey, 'wss://league.example'],
            ['k', '31923'],
            ['alt', 'Like of a tournament in TWENTY ONE Esports'],
        ]);

    expect($comments->submitReaction($anna, 'tournament', (string) $tournament->id, commentSign($annaKey, $template))->kind)->toBe(7);
});

test('the league takes only the prepared event, signed by the player: another text, another key, a forged signature are refused', function () {
    [$tournament] = commentTournament();
    [$anna, $annaKey] = keyedPlayer();
    $comments = app(NostrComments::class);
    $template = $comments->commentTemplate($anna, 'tournament', (string) $tournament->id, 'Hello');

    // The signed text is not the text handed in.
    expect(fn () => $comments->submitComment($anna, 'tournament', (string) $tournament->id, 'Hello!', commentSign($annaKey, $template)))
        ->toThrow(RejectedEvent::class, 'not_the_prepared_event');

    // Signed by someone else.
    expect(fn () => $comments->submitComment($anna, 'tournament', (string) $tournament->id, 'Hello', commentSign(new TestSigner, $template)))
        ->toThrow(RejectedEvent::class, 'foreign_author');

    // A junk signature.
    $forged = commentSign($annaKey, $template);
    $forged['sig'] = str_repeat('0', 128);

    expect(fn () => $comments->submitComment($anna, 'tournament', (string) $tournament->id, 'Hello', $forged))
        ->toThrow(RejectedEvent::class, 'invalid_signature')
        ->and(NostrEvent::query()->where('kind', 1111)->count())->toBe(0);
});

test('empty and too long comments are refused before anything is signed, and the league counts every attempt', function () {
    [$tournament] = commentTournament();
    [$anna, $annaKey] = keyedPlayer();
    $comments = app(NostrComments::class);
    config(['esports.comments.max_length' => 10, 'esports.comments.per_hour.comments' => 2]);

    expect(fn () => $comments->commentTemplate($anna, 'tournament', (string) $tournament->id, " \n\t "))->toThrow(CommentRefused::class, 'Write something first.')
        ->and(fn () => $comments->commentTemplate($anna, 'tournament', (string) $tournament->id, str_repeat('x', 11)))->toThrow(CommentRefused::class, 'Keep it to 10 characters.')
        ->and($comments->commentTemplate($anna, 'tournament', (string) $tournament->id, str_repeat('ä', 10))['content'])->toBe(str_repeat('ä', 10));

    $template = $comments->commentTemplate($anna, 'tournament', (string) $tournament->id, 'one');
    $comments->submitComment($anna, 'tournament', (string) $tournament->id, 'one', commentSign($annaKey, $template));

    // A refused attempt counts as well: the third attempt within the hour is over the limit.
    expect(fn () => $comments->submitComment($anna, 'tournament', (string) $tournament->id, 'one', ['junk']))->toThrow(RejectedEvent::class)
        ->and(fn () => $comments->submitComment($anna, 'tournament', (string) $tournament->id, 'one', commentSign($annaKey, $template)))->toThrow(CommentRefused::class, 'That was a lot this hour.');

    RateLimiter::clear('nostr-comments:'.$anna->id);
});

test('rule 38: a comment needs one root and the same parent, matching K/k and P/p, no I, no t, text within bounds', function () {
    $rules = app(EsportsEventRules::class);
    $signer = new TestSigner;
    $author = str_repeat('b', 64);
    $address = '31923:'.$author.':cup-1';
    $id = str_repeat('c', 64);
    $scope = [['A', $address, ''], ['K', '31923'], ['P', $author], ['a', $address, ''], ['e', $id], ['k', '31923'], ['p', $author], ['alt', 'Comment']];
    $check = fn (array $tags, string $content = 'hi') => $rules->check(SignedEvent::fromInput($signer->sign(1111, $tags, $content)));
    $replace = fn (array $tags, string $name, array $tag) => array_map(fn (array $t) => $t[0] === $name ? $tag : $t, $tags);

    expect($check($scope))->toBeNull()
        ->and($check([['E', $id, '', $author], ['K', '64'], ['P', $author], ['e', $id, '', $author], ['k', '64'], ['p', $author], ['alt', 'Comment']]))->toBeNull()
        ->and($check($replace($scope, 'K', ['K', '1'])))->toBe('comment_scope')
        ->and($check($replace($scope, 'k', ['k', '1111'])))->toBe('comment_scope')
        ->and($check($replace($scope, 'p', ['p', str_repeat('d', 64)])))->toBe('comment_scope')
        ->and($check($replace($scope, 'a', ['a', '31923:'.$author.':other'])))->toBe('comment_scope')
        ->and($check($replace($scope, 'A', ['A', '31923:'.str_repeat('d', 64).':cup-1'])))->toBe('comment_scope')
        ->and($check([...$scope, ['I', 'https://example.com']]))->toBe('comment_scope')
        ->and($check([...$scope, ['E', $id, '', $author]]))->toBe('comment_scope')
        ->and($check([...$scope, ['t', 'esports']]))->toBe('comment_hashtag')
        ->and($check($scope, '   '))->toBe('comment_content')
        ->and($check($scope, str_repeat('x', 1001)))->toBe('comment_content')
        ->and($check(array_values(array_filter($scope, fn (array $t) => $t[0] !== 'alt'))))->toBe('alt_missing');
});

test('rule 39: a like is "+" with one e, one p, one k and at most one a of that kind and author', function () {
    $rules = app(EsportsEventRules::class);
    $signer = new TestSigner;
    $author = str_repeat('b', 64);
    $like = [['e', str_repeat('c', 64)], ['a', '31923:'.$author.':cup'], ['p', $author], ['k', '31923'], ['alt', 'Like']];
    $check = fn (array $tags, string $content = '+') => $rules->check(SignedEvent::fromInput($signer->sign(7, $tags, $content)));

    expect($check($like))->toBeNull()
        ->and($check($like, '-'))->toBe('reaction_shape')
        ->and($check($like, '🤙'))->toBe('reaction_shape')
        ->and($check([...$like, ['e', str_repeat('d', 64)]]))->toBe('reaction_shape')
        ->and($check([['e', str_repeat('c', 64)], ['a', '30023:'.$author.':cup'], ['p', $author], ['k', '31923'], ['alt', 'Like']]))->toBe('reaction_shape')
        ->and($check([['e', str_repeat('c', 64)], ['p', $author], ['alt', 'Like']]))->toBe('reaction_shape');
});

test('rule 40: an RSVP names one 31923 as a and as d, accepted or declined, no e, no fb, no text', function () {
    $rules = app(EsportsEventRules::class);
    $signer = new TestSigner;
    $address = '31923:'.str_repeat('b', 64).':cup';
    $rsvp = fn (string $status = 'accepted', array $extra = [], string $d = '') => [['d', $d === '' ? $address : $d], ['a', $address, ''], ['status', $status], ['p', str_repeat('b', 64)], ['alt', 'RSVP'], ...$extra];
    $check = fn (array $tags, string $content = '') => $rules->check(SignedEvent::fromInput($signer->sign(31925, $tags, $content)));

    expect($check($rsvp()))->toBeNull()
        ->and($check($rsvp('declined')))->toBeNull()
        ->and($check($rsvp('tentative')))->toBe('rsvp_status')
        ->and($check($rsvp('accepted', [], 'random-uuid')))->toBe('rsvp_address')
        ->and($check($rsvp('accepted', [['e', str_repeat('c', 64)]])))->toBe('rsvp_shape')
        ->and($check($rsvp('accepted', [['fb', 'busy']])))->toBe('rsvp_shape')
        ->and($check($rsvp(), 'see you'))->toBe('rsvp_shape')
        ->and($check([['d', '31922:'.str_repeat('b', 64).':cup'], ['a', '31922:'.str_repeat('b', 64).':cup'], ['status', 'accepted'], ['alt', 'RSVP']]))->toBe('rsvp_address');
});

test('RSVP: "accepted" once in, d and a the tournament address, no e; "declined" only after pulling out as someone who said "accepted"', function () {
    [$tournament, $league] = commentTournament();
    [$anna, $annaKey] = keyedPlayer();
    [$bert] = keyedPlayer();
    $rsvps = app(Rsvps::class);
    $address = '31923:'.$league->pubkey.':'.$tournament->slug;

    // Not in: nothing to answer.
    expect($rsvps->offer($anna, $tournament))->toBeNull()
        ->and(fn () => $rsvps->template($anna, $tournament, Rsvps::ACCEPTED))->toThrow(CommentRefused::class);

    soloSignup($tournament, $anna, $annaKey);
    $template = $rsvps->template($anna, $tournament, Rsvps::ACCEPTED);

    expect($rsvps->offer($anna, $tournament))->toBe(Rsvps::ACCEPTED)
        ->and($template['kind'])->toBe(31925)
        ->and($template['content'])->toBe('')
        ->and($template['tags'])->toBe([
            ['d', $address],
            ['a', $address, 'wss://league.example'],
            ['status', 'accepted'],
            ['p', $league->pubkey, 'wss://league.example'],
            ['alt', 'RSVP to a tournament in TWENTY ONE Esports: accepted'],
        ])
        ->and(fn () => $rsvps->template($anna, $tournament, Rsvps::DECLINED))->toThrow(CommentRefused::class);

    $rsvps->submit($anna, $tournament, Rsvps::ACCEPTED, commentSign($annaKey, $template));

    expect($rsvps->said($anna, $tournament))->toBe(Rsvps::ACCEPTED)
        ->and($rsvps->offer($anna, $tournament))->toBeNull();

    // Pulling out: "declined", which replaces "accepted" (same d, a newer created_at).
    $signups = app(TournamentSignups::class);
    $signups->withdraw($tournament, $anna, $annaKey->signTemplates($signups->prepareWithdraw($tournament, $anna)));
    $this->travel(2)->seconds();

    expect($rsvps->offer($anna, $tournament))->toBe(Rsvps::DECLINED);

    $declined = $rsvps->template($anna, $tournament, Rsvps::DECLINED);
    $stored = $rsvps->submit($anna, $tournament, Rsvps::DECLINED, commentSign($annaKey, $declined));

    expect($stored->d)->toBe($address)
        ->and($rsvps->said($anna, $tournament))->toBe(Rsvps::DECLINED)
        ->and($rsvps->offer($anna, $tournament))->toBeNull()
        // Someone who never said "accepted" is never asked to decline.
        ->and($rsvps->offer($bert, $tournament))->toBeNull();
});

test('RSVP: nothing is offered for a finished or called-off tournament', function () {
    [$tournament] = commentTournament();
    [$anna, $annaKey] = keyedPlayer();
    soloSignup($tournament, $anna, $annaKey);

    foreach ([TournamentStatus::Finished, TournamentStatus::Cancelled] as $status) {
        $tournament->forceFill(['status' => $status])->save();

        expect(app(Rsvps::class)->offer($anna, $tournament->refresh()))->toBeNull();
    }
});

test('authors: league accounts by name with their page, anyone else by short npub, not in the league; junk ignored', function () {
    $anna = User::factory()->create(['name' => 'anna']);
    $stranger = str_repeat('e', 64);
    $authors = NostrAuthors::of([$anna->pubkey, $stranger, 'junk', ['array']]);

    expect(array_keys($authors))->toBe([$anna->pubkey, $stranger])
        ->and($authors[$anna->pubkey])->toMatchArray(['name' => 'anna', 'href' => '/players/'.$anna->npub, 'player' => true])
        ->and($authors[$stranger]['player'])->toBeFalse()
        ->and($authors[$stranger]['href'])->toBeNull()
        ->and($authors[$stranger]['name'])->toBe(substr(NostrKeys::hexToNpub($stranger), 0, 10).'…'.substr(NostrKeys::hexToNpub($stranger), -4));
});

test('the comment section renders only where there is a league event, and hands the browser the target to read', function () {
    [$tournament, $league] = commentTournament();

    Livewire::test('nostr-comments', ['type' => 'tournament', 'target' => (string) $tournament->id])
        ->assertSeeHtml('data-test="nostr-comments"')
        ->assertSeeHtml('data-test="comment-login"')
        ->assertSee($tournament->address(), false);

    $casual = ChessGame::factory()->finished('1-0')->create();

    Livewire::test('nostr-comments', ['type' => 'game', 'target' => (string) $casual->id])
        ->assertDontSeeHtml('data-test="nostr-comments"');

    // A guest cannot prepare anything.
    Livewire::test('nostr-comments', ['type' => 'tournament', 'target' => (string) $tournament->id])
        ->call('prepareComment', 'hi')->assertForbidden();
});

test('the component answers a refusal as an error for the page, not an exception', function () {
    [$tournament] = commentTournament();
    [$anna] = keyedPlayer();

    Livewire::actingAs($anna)->test('nostr-comments', ['type' => 'tournament', 'target' => (string) $tournament->id])
        ->call('prepareComment', '   ')
        ->assertReturned(['error' => 'Write something first.'])
        ->call('submitComment', 'hi', '{"kind":1111}')
        ->assertReturned(['error' => 'The confirmation did not match. Please try again.']);
});
