<?php

use App\Models\User;
use App\Support\Membership;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 1));
});

/**
 * Fake the Verein list endpoint with the pubkeys that paid per year.
 *
 * @param  array<int, list<string>>  $paidByYear
 */
function fakeVerein(array $paidByYear): void
{
    Http::fake(function (Request $request) use ($paidByYear) {
        $year = (int) basename(parse_url($request->url(), PHP_URL_PATH));

        return Http::response(array_map(
            fn (string $pubkey) => ['id' => 1, 'npub' => 'npub1ignored', 'pubkey' => $pubkey, 'nip05_handle' => null],
            $paidByYear[$year] ?? [],
        ));
    });
}

test('membership follows the paid years', function (array $paidYears, int $graceYears, bool $expected) {
    config(['esports.membership.grace_years' => $graceYears]);
    $user = User::factory()->create();
    fakeVerein(array_fill_keys($paidYears, [$user->pubkey]));

    expect(app(Membership::class)->refresh($user))->toBeTrue()
        ->and($user->fresh()->is_member)->toBe($expected)
        ->and($user->fresh()->member_checked_at)->not->toBeNull();
})->with([
    'paid this year' => [[2026], 1, true],
    'paid last year, within grace' => [[2025], 1, true],
    'paid last year, no grace' => [[2025], 0, false],
    'paid two years ago' => [[2024], 1, false],
]);

test('an unreachable Verein keeps the last known state', function (Closure $fake) {
    $checkedAt = now()->subDays(3);
    $user = User::factory()->create(['is_member' => true, 'member_checked_at' => $checkedAt]);
    Http::fake(['*' => $fake]);

    expect(app(Membership::class)->refresh($user))->toBeFalse();

    $user->refresh();
    expect($user->is_member)->toBeTrue()
        ->and($user->member_checked_at->getTimestamp())->toBe($checkedAt->getTimestamp());
})->with([
    'server error' => fn () => Http::response('down', 500),
    'connection failure' => fn () => Http::failedConnection(),
]);

test('a stale membership is refreshed after a request, a fresh one is not', function () {
    $stale = User::factory()->create(['member_checked_at' => now()->subHours(25)]);
    $fresh = User::factory()->create(['member_checked_at' => now()->subHours(23)]);
    fakeVerein([2026 => [$stale->pubkey, $fresh->pubkey]]);

    $this->actingAs($fresh)->get(route('dashboard'))->assertOk();
    Http::assertNothingSent();

    $this->actingAs($stale)->get(route('dashboard'))->assertOk();
    expect($stale->fresh()->is_member)->toBeTrue();
});
