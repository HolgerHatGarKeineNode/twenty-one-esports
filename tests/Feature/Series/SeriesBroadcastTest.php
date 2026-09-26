<?php

/*
 * Series changes are broadcast (P7c, the P5f note): every step of a series
 * reaches the private channel of every player of both lineups and both clan
 * owners, so their match dock refreshes at once instead of on the 120 s poll.
 */

use App\Events\SeriesMatchChanged;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\User;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\Event;

test('each step of a series is broadcast to both lineups and both owners, after the commit', function () {
    Event::fake([SeriesMatchChanged::class]);
    $service = app(SeriesService::class);

    $side = function (): array {
        $owner = User::factory()->create();
        $lineup = Lineup::factory()->mode('2v2')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $owner->id])->id]);

        return [$lineup->load('seats', 'clan'), $owner];
    };
    [$a, $ownerA] = $side();
    [$b, $ownerB] = $side();
    $start = now()->addHour()->startOfMinute()->getTimestamp();
    $draft = new ChallengeDraft($a->id, $b->id, 3, false, [$start], $start - 600, '');

    $match = $service->challenge($ownerA, $draft, []);
    $service->answer($match, $ownerB, 'accepted', $start, []);
    $this->travelTo(now()->setTimestamp($start)->addMinutes(20));
    $service->saveLiveGame($match, $ownerA, 0, 3, 1, null);
    $service->saveLiveGame($match, $ownerA, 1, 2, 0, null);
    $service->report($match, $ownerA, []);
    $service->respond($match, $ownerB, 'confirmed', '', []);

    $expected = collect([$a, $b])->flatMap(fn (Lineup $lineup) => $lineup->seats->whereNotNull('accepted_at')->pluck('user_id'))
        ->push($ownerA->id, $ownerB->id)->unique()->sort()->values()->all();

    $sent = Event::dispatched(SeriesMatchChanged::class)->map(fn (array $args) => $args[0]);

    expect($sent->pluck('status')->all())->toBe(['open', 'accepted', 'accepted', 'accepted', 'reported', 'confirmed'])
        ->and($sent->pluck('number')->unique()->all())->toBe([$match->number])
        ->and(collect($sent->first()->userIds)->sort()->values()->all())->toBe($expected)
        ->and($sent->first()->broadcastAs())->toBe('series.changed')
        ->and(array_map(fn ($channel) => $channel->name, $sent->first()->broadcastOn()))->toContain('private-App.Models.User.'.$ownerB->id);
});
