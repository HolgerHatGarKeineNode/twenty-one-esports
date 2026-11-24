<?php

/*
|--------------------------------------------------------------------------
| "Your path to Season 1" (plan "Season vs. Casual", P4)
|--------------------------------------------------------------------------
|
| SeasonPath turns the gate of rated play into the steps a player can take:
| the profile standard's marks while it applies, the trust rank against the
| minimum, a mutual opponent (the concrete player when there is one). A held
| or barred account gets its message and no step; every step done reads
| "You can play Season 1" with the mutual opponents. The component renders
| that, one button per open step.
*/

use App\Models\FairPlayBar;
use App\Models\ImpersonationHold;
use App\Models\User;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\SeasonPath;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    config(['esports.fair_play.profile_standard.enabled' => true, 'esports.fair_play.profile_standard.since' => null, 'esports.fair_play.impersonation.enabled' => true]);
    pathRanks(80);
});

/**
 * A player with a signer who meets the profile standard, unless `$attributes` takes a mark away.
 *
 * @return array{0: User, 1: TestSigner}
 */
function pathPlayer(string $name, array $attributes = []): array
{
    $signer = new TestSigner;

    return [User::factory()->withPubkey($signer->pubkey)->create([
        'name' => $name, 'picture' => 'https://example.com/'.$name.'.png', 'nip05' => $name.'@example.com',
        'nip05_verified_at' => now()->subDays(20), 'created_at' => now()->subDays(8), ...$attributes,
    ]), $signer];
}

/** The two add each other, signed as the browser would. */
function pathListEachOther(array $a, array $b): void
{
    $opponents = app(Opponents::class);

    foreach ([[$a, $b], [$b, $a]] as [[$player, $signer], [$opponent]]) {
        $opponents->add($player, $opponent, $signer->signTemplates($opponents->prepareAdd($player, $opponent)));
        test()->travel(1)->seconds();
    }
}

/** Trust ranks computed, every player at `$rank`. */
function pathRanks(int $rank): void
{
    app()->bind(TrustFacts::class, fn () => new class($rank) implements TrustFacts
    {
        public function __construct(private int $rank) {}

        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            return ['trust' => array_fill_keys($players, $this->rank), 'anchors' => [], 'connected' => true];
        }
    });
}

/** @return array<string, array{0: bool, 1: string|null}> step key => [done, button target] */
function pathSteps(array $path): array
{
    return collect($path['steps'])->mapWithKeys(fn (array $step): array => [$step['key'] => [$step['done'], $step['href'] ?? $step['player']?->name]])->all();
}

test('below the profile standard the missing marks are steps, each with the settings page that fixes it', function () {
    openSeason();
    $anna = pathPlayer('anna', ['nip05' => null, 'nip05_verified_at' => null, 'picture' => null]);
    pathListEachOther($anna, pathPlayer('bert'));

    $path = app(SeasonPath::class)->for($anna[0]);

    expect($path['state'])->toBe('steps')
        ->and(pathSteps($path))->toBe([
            'known' => [true, null],
            'nip05' => [false, route('settings.nip05')],
            'profile' => [false, route('settings.profile')],
            'trust' => [true, null],
            'opponents' => [true, null],
        ]);
});

test('below the trust minimum the trust step names the rank against the minimum and leads to the opponents page', function () {
    openSeason();
    pathRanks(12);
    $anna = pathPlayer('anna');
    pathListEachOther($anna, pathPlayer('bert'));

    $path = app(SeasonPath::class)->for($anna[0]);

    expect($path['state'])->toBe('steps')
        ->and(pathSteps($path)['trust'])->toBe([false, route('settings.opponents')])
        ->and($path['steps'][3]['text'])->toStartWith('Trust rank 12, needs 50.');
});

test('without a mutual opponent the opponents step is open; with a player in context it is that player\'s add button', function () {
    openSeason();
    $anna = pathPlayer('anna');
    $bert = pathPlayer('bert');
    // Anna lists Bert, Bert does not list Anna: no mutual opponent.
    $opponents = app(Opponents::class);
    $opponents->add($anna[0], $bert[0], $anna[1]->signTemplates($opponents->prepareAdd($anna[0], $bert[0])));

    expect(pathSteps(app(SeasonPath::class)->for($anna[0]))['opponents'])->toBe([false, route('settings.opponents')])
        ->and(pathSteps(app(SeasonPath::class)->for($bert[0], opponent: $anna[0]))['opponent'])->toBe([false, 'anna']);
});

test('a held account gets the account check message and no step', function () {
    openSeason();
    [$anna] = pathPlayer('anna');
    ImpersonationHold::query()->create(['user_id' => $anna->id, 'pubkey' => $anna->pubkey, 'matched' => 'manual', 'detected_at' => now()]);

    $path = app(SeasonPath::class)->for($anna);

    expect([$path['state'], $path['kind'], $path['steps']])->toBe(['blocked', 'held', []])
        ->and($path['message'])->not->toBe('');
});

test('a barred account gets the bar\'s message and no step', function () {
    openSeason();
    [$anna] = pathPlayer('anna');
    [$admin] = pathPlayer('admin');
    FairPlayBar::query()->create(['user_id' => $anna->id, 'pubkey' => $anna->pubkey, 'reason' => 'Ticket 4711', 'until' => now()->addDays(3), 'barred_by_id' => $admin->id, 'barred_by_pubkey' => $admin->pubkey]);

    $path = app(SeasonPath::class)->for($anna);

    expect([$path['state'], $path['kind'], $path['steps']])->toBe(['blocked', 'barred', []]);
});

test('every step done reads eligible, with the mutual opponents by name', function () {
    openSeason();
    $anna = pathPlayer('anna');
    pathListEachOther($anna, pathPlayer('bert'));

    $path = app(SeasonPath::class)->for($anna[0]);

    expect($path['state'])->toBe('eligible')
        ->and(array_column($path['mutual'], 'name'))->toBe(['bert']);
});

test('the component shows an eligible player "You can play Season 1" and the mutual opponents, and no step', function () {
    openSeason();
    $anna = pathPlayer('anna');
    pathListEachOther($anna, pathPlayer('bert'));

    $html = Blade::render('<x-season.path :user="$user" />', ['user' => $anna[0]]);

    expect($html)->toContain('data-state="eligible"')->toContain('You can play Season 1')->toContain('data-test="season-path-mutual">bert<')
        ->not->toContain('data-test="season-path-step"');
});

test('the component lists the open steps with one button each, the done ones without', function () {
    openSeason();
    pathRanks(12);
    $anna = pathPlayer('anna', ['nip05' => null, 'nip05_verified_at' => null]);

    $html = Blade::render('<x-season.path :user="$user" />', ['user' => $anna[0]]);
    preg_match_all('/data-key="([a-z0-9]+)" data-done="(true|false)">(.*?)<\/li>/s', $html, $steps, PREG_SET_ORDER);
    $buttons = collect($steps)->mapWithKeys(function (array $step): array {
        preg_match('/<a href="([^"]+)" class="rv-b2 is-sm[^"]*" data-test="season-path-fix">/', $step[3], $link);

        return [$step[1] => [$step[2], $link[1] ?? null]];
    })->all();

    expect($html)->toContain('Your path to Season 1')
        ->and($buttons)->toBe([
            'known' => ['true', null],
            'nip05' => ['false', route('settings.nip05')],
            'profile' => ['true', null],
            'trust' => ['false', route('settings.opponents')],
            'opponents' => ['false', route('settings.opponents')],
        ]);
});

test('the component shows a blocked account its message without a button, and a guest nothing', function () {
    openSeason();
    [$anna] = pathPlayer('anna');
    ImpersonationHold::query()->create(['user_id' => $anna->id, 'pubkey' => $anna->pubkey, 'matched' => 'manual', 'detected_at' => now()]);

    $html = Blade::render('<x-season.path :user="$user" />', ['user' => $anna]);

    expect($html)->toContain('data-test="season-path-blocked"')->not->toContain('data-test="season-path-fix"')
        ->and(trim(Blade::render('<x-season.path />')))->toBe('');
});
