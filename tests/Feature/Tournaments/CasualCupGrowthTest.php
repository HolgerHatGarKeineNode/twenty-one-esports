<?php

use App\Enums\TournamentStatus;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\TournamentRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Growing casual cups (P27)
|--------------------------------------------------------------------------
|
| A cup opens with 4 places and grows to 8 and 16 whenever only one place is
| left, until one hour before sign-up closes; each growth is one new 31923
| version. A full cup grows instead of starting; full at its last size, or
| full once growth is frozen, it starts at once.
|
*/

beforeEach(function () {
    Queue::fake();
    Http::fake(['*/blocks/tip/height' => Http::response('900000')]);
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

/** The cup's 31923 versions. */
function growthVersions(Tournament $cup): int
{
    return NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->where('d', $cup->slug)->count();
}

/** `$n` players sign up with no growth in between (the league's tick comes later). */
function growthSignups(Tournament $cup, int $n): void
{
    foreach (range(1, $n) as $ignored) {
        [$player, $signer] = keyedPlayer();
        soloSignup($cup->refresh(), $player, $signer);
    }
}

test('a cup opens with 4 places and grows to 8 at 3 of 4, to 16 at 7 of 8, and no further; one version per growth', function () {
    cupTick();
    $cup = openCup();
    $versions = growthVersions($cup);

    growthSignups($cup, 2);
    cupTick();
    expect($cup->refresh()->capacity)->toBe(4);

    growthSignups($cup, 1);
    $done = cupTick();
    $content = NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->where('d', $cup->slug)->latest('id')->firstOrFail()->payload()['content'];

    expect($done['cups']['grown'])->toBe(1)
        ->and($cup->refresh()->capacity)->toBe(8)
        ->and(growthVersions($cup))->toBe($versions + 1)
        ->and($content)->toContain('Places: 8 for now; the league adds places as they fill, up to 16, until 60 minutes before sign-up closes.');

    growthSignups($cup, 3);
    cupTick();
    expect($cup->refresh()->capacity)->toBe(8);

    growthSignups($cup, 1);
    cupTick();
    expect($cup->refresh()->capacity)->toBe(16);

    growthSignups($cup, 8);
    cupTick();

    // 15 of 16: the last size; nothing more to grow, and no further version.
    expect($cup->refresh()->capacity)->toBe(16)
        ->and($cup->status)->toBe(TournamentStatus::Signup)
        ->and(growthVersions($cup))->toBe($versions + 2);
});

test('a full cup grows instead of starting early, and at its last size it starts at once', function () {
    config(['esports.casual_cups.sizes' => [4, 8]]);
    cupTick();
    $cup = openCup();

    growthSignups($cup, 4);
    cupTick();

    expect($cup->refresh()->capacity)->toBe(8)
        ->and($cup->status)->toBe(TournamentStatus::Signup);

    growthSignups($cup, 4);
    cupTick();

    expect($cup->refresh()->status)->toBe(TournamentStatus::Drawing)
        ->and($cup->capacity)->toBe(8);
});

test('from one hour before the close nothing grows; a cup full then starts at once, else plays with whoever signed up', function () {
    cupTick();
    $cup = openCup();
    growthSignups($cup, 3);
    $versions = growthVersions($cup);

    $this->travelTo($cup->signup_closes_at->subMinutes(60));
    cupTick();

    expect($cup->refresh()->capacity)->toBe(4)
        ->and(growthVersions($cup))->toBe($versions);

    growthSignups($cup, 1);
    cupTick();

    expect($cup->refresh()->capacity)->toBe(4)
        ->and($cup->status)->toBe(TournamentStatus::Drawing)
        ->and($cup->signup_closes_at->lessThanOrEqualTo(now()))->toBeTrue();
});

test('growth is taken once: a second run with the same capacity changes nothing, and a sign-up waits for it', function () {
    cupTick();
    $cup = openCup();
    growthSignups($cup, 4);
    [$fifth, $signer] = keyedPlayer();
    $stale = $cup->refresh();
    $versions = growthVersions($cup);

    // Full at 4: the fifth is refused until the league grows the cup.
    expect(fn () => soloSignup($cup->refresh(), $fifth, $signer))->toThrow(TournamentRuleViolation::class, 'This tournament is full.');

    $cups = app(CasualCups::class);

    expect($cups->grow($stale, 4))->toBeTrue()
        ->and($cups->grow($stale, 4))->toBeFalse()
        ->and($cup->refresh()->capacity)->toBe(8)
        ->and(growthVersions($cup))->toBe($versions + 1);

    soloSignup($cup->refresh(), $fifth, $signer);

    expect($cup->signups()->active()->count())->toBe(5);
});

test('the cup page always shows the places of the current size', function () {
    cupTick();
    $cup = openCup();
    growthSignups($cup, 3);
    cupTick();

    Livewire::test('pages::tournaments.show', ['tournament' => $cup->refresh()])
        ->assertSeeHtml('aria-valuemax="8"')
        ->assertSee('of 8 spots taken');
});

test('a cup opened with every place before P27 is fitted to the growing sign-up once, with one new version', function () {
    cupTick();
    $cup = openCup();
    $cup->update(['capacity' => 16]);
    growthSignups($cup, 2);
    $versions = growthVersions($cup);
    $cups = app(CasualCups::class);

    expect($cups->fitCapacity($cup->refresh()))->toBeTrue()
        ->and($cup->refresh()->capacity)->toBe(4)
        ->and(growthVersions($cup))->toBe($versions + 1)
        ->and($cups->fitCapacity($cup->refresh()))->toBeFalse()
        ->and(growthVersions($cup))->toBe($versions + 1);

    $cup->update(['capacity' => 16]);
    growthSignups($cup, 3);
    $cups->fitCapacity($cup->refresh());
    expect($cup->refresh()->capacity)->toBe(8);

    $this->travelTo($cup->signup_closes_at->subMinutes(30));
    $cup->update(['capacity' => 16]);
    expect($cups->fitCapacity($cup->refresh()))->toBeFalse()
        ->and($cup->refresh()->capacity)->toBe(16);
});
