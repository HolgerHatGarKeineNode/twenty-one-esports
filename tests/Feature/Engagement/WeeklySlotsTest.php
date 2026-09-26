<?php

use App\Models\Admin;
use App\Models\SlotEvent;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Support\Engagement\WeeklySlots;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Weekly events (P10): an admin defines a recurring slot, the scheduler dates
 * it once per occurrence however often it runs, and home and the chess lobby
 * show the next ones.
 */

test('the scheduler dates the next occurrence once, however often it runs', function () {
    // Monday 2026-09-28, 10:00 UTC. Wednesday 20:00 Berlin (CEST) is 18:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'UTC'));
    $slot = WeeklySlot::factory()->create();

    $this->artisan('events:schedule-weekly')->assertSuccessful()->expectsOutput('Scheduled 1 event(s).');
    $this->artisan('events:schedule-weekly')->assertSuccessful()->expectsOutput('Scheduled 0 event(s).');

    expect(app(WeeklySlots::class)->schedule())->toBe(0)
        ->and(SlotEvent::query()->count())->toBe(1)
        ->and(SlotEvent::query()->sole()->only(['weekly_slot_id']))->toBe(['weekly_slot_id' => $slot->id])
        ->and(SlotEvent::query()->sole()->starts_at->toDateTimeString())->toBe('2026-09-30 18:00:00')
        ->and(SlotEvent::query()->sole()->ends_at->toDateTimeString())->toBe('2026-09-30 20:00:00');

    // A week later the next date is added next to it, still once.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'UTC'));
    app(WeeklySlots::class)->schedule();
    app(WeeklySlots::class)->schedule();

    expect(SlotEvent::query()->orderBy('starts_at')->get()->map(fn (SlotEvent $event) => $event->starts_at->toDateTimeString())->all())
        ->toBe(['2026-09-30 18:00:00', '2026-10-07 18:00:00']);
});

test('the wall-clock time holds across daylight saving time, and a running event is still the next one', function () {
    $slot = WeeklySlot::factory()->create(['weekday' => 3, 'time' => '20:00']);
    $slots = app(WeeklySlots::class);

    // Berlin leaves summer time on 2026-10-25: 20:00 is 18:00 UTC before and 19:00 UTC after.
    expect($slots->nextStart($slot, CarbonImmutable::parse('2026-10-19 12:00', 'UTC'))->toDateTimeString())->toBe('2026-10-21 18:00:00')
        ->and($slots->nextStart($slot, CarbonImmutable::parse('2026-10-26 12:00', 'UTC'))->toDateTimeString())->toBe('2026-10-28 19:00:00')
        // 21:00 Berlin on the evening itself: running until 22:00, so still this one.
        ->and($slots->nextStart($slot, CarbonImmutable::parse('2026-10-21 19:00', 'UTC'))->toDateTimeString())->toBe('2026-10-21 18:00:00')
        // After it ended: next week.
        ->and($slots->nextStart($slot, CarbonImmutable::parse('2026-10-21 20:30', 'UTC'))->toDateTimeString())->toBe('2026-10-28 19:00:00');
});

test('a paused slot is not dated and its dates leave home and the lobby', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'UTC'));
    $slot = WeeklySlot::factory()->create(['title' => 'RL Sunday', 'game' => 'rocket-league', 'mode' => '3v3', 'weekday' => 7]);
    WeeklySlot::factory()->create(['title' => 'Paused night', 'active' => false]);
    app(WeeklySlots::class)->schedule();

    expect(SlotEvent::query()->count())->toBe(1);

    $this->get(route('home'))->assertOk()->assertSee('RL Sunday')->assertSee('data-test="weekly-events"', false)->assertDontSee('Paused night');
    Livewire::test('pages::chess.lobby')->assertOk()->assertSee('RL Sunday');

    $slot->update(['active' => false]);

    $this->get(route('home'))->assertOk()->assertDontSee('RL Sunday')->assertDontSee('data-test="weekly-events"', false);
});

test('an event shows as live while it runs and disappears once it ended', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 18:30', 'UTC'));
    WeeklySlot::factory()->create();
    app(WeeklySlots::class)->schedule();

    $this->get(route('home'))->assertOk()->assertSee('Blitz night')->assertSee(__('running now'));

    $this->travelTo(CarbonImmutable::parse('2026-09-30 20:01', 'UTC'));

    // Only next week's date is left.
    expect(app(WeeklySlots::class)->upcoming()->map(fn (SlotEvent $event) => $event->starts_at->toDateTimeString())->all())->toBe(['2026-10-07 18:00:00']);
    $this->get(route('home'))->assertOk()->assertDontSee(__('running now'));
});

test('an admin adds a slot and it is dated at once; others cannot open the page', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'UTC'));
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    Livewire::actingAs($admin)->test('pages::admin.events')
        ->set('title', 'Friday Blitz Night')->set('ladder', 'chess/blitz')->set('weekday', 5)->set('time', '19:30')->set('timezone', 'Europe/Berlin')
        ->call('add')->assertHasNoErrors()->assertSee('Friday Blitz Night')
        ->set('title', 'Broken')->set('time', '25:00')->call('add')->assertHasErrors('time')
        ->set('time', '20:00')->set('ladder', 'chess/bullet')->call('add')->assertHasErrors('ladder');

    expect(WeeklySlot::query()->pluck('title')->all())->toBe(['Friday Blitz Night'])
        ->and(SlotEvent::query()->sole()->starts_at->toDateTimeString())->toBe('2026-10-02 17:30:00');

    $this->actingAs(User::factory()->create())->get(route('admin.events'))->assertForbidden();
});
