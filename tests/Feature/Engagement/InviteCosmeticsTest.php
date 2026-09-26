<?php

use App\Models\Cosmetic;
use App\Models\InviteLink;
use App\Models\User;
use App\Support\Engagement\Cosmetics;
use App\Support\Invites\InviteLinkRefused;
use App\Support\Invites\InviteLinks;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * "Invite a friend, a cosmetic for both" (P10): an accepted invite link
 * credits the frame to the inviter and to the player who took it, once per
 * player however many links they share or take.
 */

beforeEach(function () {
    Queue::fake();
    Http::fake(fn () => Http::response([]));
});

test('an accepted link gives both players the frame, once each, and the player page shows it', function () {
    $inviter = User::factory()->create();
    [$friend, $second] = User::factory()->count(2)->create();
    $links = app(InviteLinks::class);

    $links->accept(InviteLink::factory()->create(['inviter_id' => $inviter->id]), $friend);
    $links->accept(InviteLink::factory()->create(['inviter_id' => $inviter->id]), $second);

    expect(Cosmetic::query()->where('cosmetic', Cosmetics::INVITE_FRAME)->orderBy('user_id')->pluck('user_id')->all())
        ->toBe(collect([$inviter->id, $friend->id, $second->id])->sort()->values()->all())
        ->and(Cosmetic::query()->where('user_id', $inviter->id)->count())->toBe(1);

    $this->get(route('players.show', $friend->npub))->assertOk()
        ->assertSee('data-test="invite-frame-chip"', false)
        ->assertSee('data-test="invite-frame"', false);
    $this->get(route('players.show', User::factory()->create()->npub))->assertOk()
        ->assertDontSee('data-test="invite-frame-chip"', false);
});

test('a refused link credits nobody', function () {
    $inviter = User::factory()->create();
    $link = InviteLink::factory()->create(['inviter_id' => $inviter->id]);

    expect(fn () => app(InviteLinks::class)->accept($link, $inviter))->toThrow(InviteLinkRefused::class);

    $friend = User::factory()->create();
    app(InviteLinks::class)->accept($link, $friend);

    // One-time link, taken: the next taker is refused and gets nothing.
    $late = User::factory()->create();
    expect(fn () => app(InviteLinks::class)->accept($link->refresh(), $late))->toThrow(InviteLinkRefused::class)
        ->and(Cosmetic::query()->pluck('user_id')->sort()->values()->all())->toBe(collect([$inviter->id, $friend->id])->sort()->values()->all());
});
