<?php

use App\Enums\ChessInviteStatus;
use App\Enums\Platform;
use App\Events\SeriesInviteChanged;
use App\Events\UserNotified;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualQueue;
use Illuminate\Support\Facades\Event;

/*
 * Direct casual 1v1 invites (P23, slice S1), as the blitz invites: only to
 * a player looking for this game, 120 seconds, accept and decline race on
 * the invite row.
 */

beforeEach(fn () => $this->freezeTime());

test('an accepted invite makes an invite match with the inviter as challenger', function () {
    Event::fake([UserNotified::class, SeriesInviteChanged::class]);
    [$anna, $bert] = User::factory()->count(2)->create(['looking_to_play' => 'rocket-league/1v1']);
    $invites = app(CasualInvites::class);

    $invite = $invites->invite($anna, $bert, 'rocket-league', Platform::Pc, false);

    expect($invite->expires_at->getTimestamp())->toBe(now()->addSeconds(120)->getTimestamp())
        ->and(casualAlerts())->toBe([[$bert->id, 'casual_invite']]);

    $match = $invites->accept($invite, $bert, Platform::Pc, false);

    expect($match->origin)->toBe('invite')
        ->and($match->sides)->toBe(['challenger' => [$anna->id], 'challenged' => [$bert->id]])
        ->and($match->created_by_id)->toBe($anna->id)
        ->and($match->best_of)->toBe(3)
        ->and($invite->refresh()->status)->toBe(ChessInviteStatus::Accepted)
        ->and($invite->series_match_id)->toBe($match->id)
        ->and(casualAlerts())->toContain([$anna->id, 'casual_match_found'], [$bert->id, 'casual_match_found']);

    Event::assertDispatched(SeriesInviteChanged::class, fn (SeriesInviteChanged $event) => $event->inviteId === $invite->id && $event->status === 'accepted');
});

test('only a player looking for this game can be invited', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create(['looking_to_play' => 'ea-sports-fc-26/1v1']);
    $carl->forceFill(['looking_to_play' => null])->save();
    $invites = app(CasualInvites::class);

    expect(casualRefusal(fn () => $invites->invite($anna, $bert, 'rocket-league', Platform::Pc, true)))->toBe('not_looking')
        ->and(casualRefusal(fn () => $invites->invite($anna, $carl, 'ea-sports-fc-26', Platform::Pc, true)))->toBe('not_looking')
        ->and(casualRefusal(fn () => $invites->invite($anna, $anna, 'ea-sports-fc-26', Platform::Pc, true)))->toBe('invite_self')
        ->and(SeriesInvite::query()->count())->toBe(0);
});

test('an invite can no longer be accepted after 120 seconds', function () {
    [$anna, $bert] = User::factory()->count(2)->create(['looking_to_play' => 'rocket-league/1v1']);
    $invites = app(CasualInvites::class);
    $invite = $invites->invite($anna, $bert, 'rocket-league', Platform::Pc, true);

    $this->travel(120)->seconds();

    expect(casualRefusal(fn () => $invites->accept($invite, $bert, Platform::Pc, true)))->toBe('invite_closed')
        ->and($invites->incoming($bert))->toHaveCount(0)
        ->and(SeriesMatch::query()->count())->toBe(0);
});

test('an accept and a decline of one invite race: whichever commits first wins', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create(['looking_to_play' => 'rocket-league/1v1']);
    $invites = app(CasualInvites::class);

    // Accepted in one tab, declined from a stale copy in another: the accept stands.
    $first = $invites->invite($anna, $bert, 'rocket-league', Platform::Pc, true);
    $stale = SeriesInvite::query()->findOrFail($first->id);
    $match = $invites->accept($first, $bert, Platform::Pc, true);
    $invites->close($stale, $bert);

    expect($first->refresh()->status)->toBe(ChessInviteStatus::Accepted)
        ->and($first->series_match_id)->toBe($match->id);

    // Declined first: the accept is refused and makes no match.
    $second = $invites->invite($carl, User::factory()->create(['looking_to_play' => 'rocket-league/1v1']), 'rocket-league', Platform::Pc, true);
    $stale = SeriesInvite::query()->findOrFail($second->id);
    $invites->close($second, $second->invitee);

    expect(casualRefusal(fn () => $invites->accept($stale, $second->invitee, Platform::Pc, true)))->toBe('invite_closed')
        ->and($second->refresh()->status)->toBe(ChessInviteStatus::Declined)
        ->and(SeriesMatch::query()->count())->toBe(1);
});

test('an invite between platforms that cannot play each other is refused on accept', function () {
    [$anna, $bert] = User::factory()->count(2)->create(['looking_to_play' => 'ea-sports-fc-26/1v1']);
    $invites = app(CasualInvites::class);
    $invite = $invites->invite($anna, $bert, 'ea-sports-fc-26', Platform::Switch, true);

    expect(casualRefusal(fn () => $invites->accept($invite, $bert, Platform::Pc, true)))->toBe('platforms_incompatible')
        ->and($invite->refresh()->status)->toBe(ChessInviteStatus::Pending);
});

test('an invite to a player searching the same game on a fitting platform makes the match at once', function () {
    [$anna, $bert] = User::factory()->count(2)->create(['looking_to_play' => 'rocket-league/1v1']);
    app(CasualQueue::class)->join($bert, 'rocket-league', Platform::Xbox);

    $invite = app(CasualInvites::class)->invite($anna, $bert, 'rocket-league', Platform::Xbox, false);

    expect($invite->status)->toBe(ChessInviteStatus::Accepted)
        ->and($invite->match?->casual['queue']['challenged'])->toBe(['platform' => 'xbox', 'crossplay' => true])
        ->and(app(CasualQueue::class)->entryOf($bert))->toBeNull();
});

test('a new invite withdraws the inviter\'s previous one', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create(['looking_to_play' => 'rocket-league/1v1']);
    $invites = app(CasualInvites::class);

    $first = $invites->invite($anna, $bert, 'rocket-league', Platform::Pc, true);
    $invites->invite($anna, $carl, 'rocket-league', Platform::Pc, true);

    expect($first->refresh()->status)->toBe(ChessInviteStatus::Withdrawn)
        ->and($invites->outgoing($anna)?->invitee_id)->toBe($carl->id);
});
