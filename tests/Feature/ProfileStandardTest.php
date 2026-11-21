<?php

use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\RatedChess;
use App\Support\FairPlay\FairPlay;
use App\Support\FairPlay\ProfileStandard;
use App\Support\LeagueTime;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\TrustFacts;
use Carbon\CarbonImmutable;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Fair play: the profile standard of rated play (plan "Profile standard and
| impersonation", P1)
|--------------------------------------------------------------------------
|
| With `esports.fair_play.profile_standard.enabled` on, rated play needs an
| account known to the league for 7 days since its first login, a NIP-05
| (checked, or the league's own name) and a name and a picture. Whoever
| played rated in the live season before the switch-on date is exempt.
| FairPlay::barred() reports kind `standard`; the player reads only the
| missing steps.
|
*/

beforeEach(function () {
    $this->freezeSecond();
    $this->season = openSeason(['slug' => 'season-1', 'genesis_at' => now()->subDays(30)->startOfSecond(), 'ends_at' => now()->addDays(60)->startOfSecond()]);
    config(['esports.fair_play.profile_standard.enabled' => true, 'esports.fair_play.profile_standard.since' => null]);
});

/** A player who meets all three hard marks, unless `$attributes` takes one away. */
function psPlayer(array $attributes = []): User
{
    return User::factory()->create([
        'name' => 'ada',
        'picture' => 'https://example.com/ada.png',
        'nip05' => 'ada@example.com',
        'nip05_verified_at' => now()->subDays(20),
        'created_at' => now()->subDays(8),
        ...$attributes,
    ]);
}

/** A decisive rated result of the live season that names `$winner` and `$loser`, attested `$daysAgo` days ago. */
function psAttested(Season $season, User $winner, User $loser, float $daysAgo): SeasonAttestation
{
    static $number = 0;
    $number++;

    return SeasonAttestation::query()->create([
        'season_id' => $season->id, 'source' => 'chess', 'source_id' => 800_000 + $number, 'label' => '#'.$number,
        'game' => 'chess', 'mode' => 'blitz', 'ladder_address' => 'chess/blitz', 'attested_at' => now()->subSeconds((int) round($daysAgo * 86400)),
        'candidate' => ['game' => 'chess', 'winners' => [$winner->pubkey], 'losers' => [$loser->pubkey]],
        'era' => 1, 'event_id' => hash('sha256', 'standard-'.$number),
    ]);
}

test('switch off: an account without any mark is not barred and reads no message, as before the standard existed', function () {
    config(['esports.fair_play.profile_standard.enabled' => false]);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    config(['esports.chess.rated_queue' => true]);
    $fresh = User::factory()->create(['name' => '', 'picture' => null, 'created_at' => now()]);

    expect(ProfileStandard::missing($fresh))->toBe(['known', 'nip05', 'profile'])
        ->and(FairPlay::barred([$fresh->pubkey]))->toBe([])
        ->and(FairPlay::message($fresh->pubkey, $fresh))->toBeNull()
        ->and(app(RatedChess::class)->refusal($fresh, 'blitz'))->toBeNull();
});

test('each hard mark alone bars rated play, and a player with all three passes', function (array $without, string $mark) {
    $met = psPlayer();
    $short = psPlayer($without);

    $barred = FairPlay::barred([$met->pubkey, $short->pubkey]);

    expect(array_keys($barred))->toBe([$short->pubkey])
        ->and($barred[$short->pubkey]['kind'])->toBe('standard')
        ->and(ProfileStandard::missing($short))->toBe([$mark]);
})->with([
    'known for 6 days only' => [['created_at' => now()->subDays(6)], 'known'],
    'no NIP-05' => [['nip05' => null, 'nip05_verified_at' => null], 'nip05'],
    'NIP-05 named but never checked' => [['nip05_verified_at' => null], 'nip05'],
    'no picture' => [['picture' => null], 'profile'],
    'empty name' => [['name' => ' '], 'profile'],
]);

test('the league\'s own NIP-05 name counts as much as a checked address', function () {
    $own = psPlayer(['nip05' => null, 'nip05_verified_at' => null, 'nip05_name' => 'ada']);

    expect(ProfileStandard::missing($own))->toBe([])
        ->and(FairPlay::isBarred($own->pubkey))->toBeFalse();
});

test('a fresh account missing only time is barred until 7 days after its first login, to the second; missing more, it has no end', function () {
    $fresh = psPlayer(['created_at' => now()->subDays(2)]);
    $bare = psPlayer(['created_at' => now()->subDays(2), 'picture' => null]);
    $until = CarbonImmutable::instance($fresh->created_at)->addDays(7);

    $barred = FairPlay::barred([$fresh->pubkey, $bare->pubkey]);

    expect($barred[$fresh->pubkey]['until']?->equalTo($until))->toBeTrue()
        ->and($barred[$bare->pubkey]['until'])->toBeNull();

    $this->travelTo($until->subSecond());
    expect(FairPlay::isBarred($fresh->pubkey))->toBeTrue();

    $this->travel(1)->seconds();
    expect(FairPlay::isBarred($fresh->pubkey))->toBeFalse()
        ->and(FairPlay::barred([$bare->pubkey])[$bare->pubkey]['kind'])->toBe('standard');
});

test('a player who played rated in the live season before the switch-on date is exempt from all three marks', function () {
    config(['esports.fair_play.profile_standard.since' => now()->subDays(3)->toIso8601String()]);
    $bare = ['created_at' => now()->subDays(20), 'nip05' => null, 'nip05_verified_at' => null, 'picture' => null];
    [$winner, $loser, $late, $opponent] = [psPlayer($bare), psPlayer($bare), psPlayer($bare), psPlayer()];
    psAttested($this->season, $winner, $loser, 5);
    // Played only after the switch-on date: not exempt.
    psAttested($this->season, $late, $opponent, 1);

    expect(array_keys(FairPlay::barred([$winner->pubkey, $loser->pubkey, $late->pubkey])))->toBe([$late->pubkey])
        ->and(ProfileStandard::isExempt($winner))->toBeTrue()
        ->and(ProfileStandard::isExempt($loser))->toBeTrue()
        ->and(ProfileStandard::isExempt($late))->toBeFalse();
});

test('a rated draw before the switch-on date exempts too, a casual result or one of another season does not', function () {
    config(['esports.fair_play.profile_standard.since' => now()->subDays(3)->toIso8601String()]);
    $bare = ['nip05' => null, 'nip05_verified_at' => null];
    [$drew, $casual, $earlier] = [psPlayer($bare), psPlayer($bare), psPlayer($bare)];
    $change = function (User $player, string $pool, string $season): void {
        $rating = Rating::query()->create(['pool' => $pool, 'season' => $season, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$player->id, 'user_id' => $player->id, 'rating' => 1000, 'results' => 1, 'draws' => 1]);
        $row = RatingChange::query()->create(['rating_id' => $rating->id, 'source' => RatingChange::CHESS, 'source_id' => $player->id, 'score' => 0.5, 'before' => 1000, 'after' => 1000, 'delta' => 0, 'results_before' => 0]);
        $row->forceFill(['created_at' => now()->subDays(5)])->save();
    };
    $change($drew, Rating::RATED, 'season-1');
    $change($casual, Rating::CASUAL, '');
    $change($earlier, Rating::RATED, 'season-0');

    expect(array_keys(FairPlay::barred([$drew->pubkey, $casual->pubkey, $earlier->pubkey])))->toEqualCanonicalizing([$casual->pubkey, $earlier->pubkey]);
});

test('without a switch-on date the cut-off is now: whoever played rated in the live season so far is exempt', function () {
    $bare = psPlayer(['nip05' => null, 'nip05_verified_at' => null]);

    expect(FairPlay::isBarred($bare->pubkey))->toBeTrue();

    psAttested($this->season, $bare, psPlayer(), 0.01);

    expect(FairPlay::isBarred($bare->pubkey))->toBeFalse();
});

test('a match accepted before the switch keeps its pinned gate: it is rated, while new rated play is refused', function () {
    config(['esports.fair_play.profile_standard.enabled' => false, 'esports.chess.rated_queue' => true]);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    [$a, $b] = [psPlayer(['nip05' => null, 'nip05_verified_at' => null]), psPlayer(['nip05' => null, 'nip05_verified_at' => null])];
    $queue = app(ChessQueue::class);
    $queue->join($a, 'blitz', rated: true);
    $game = $queue->join($b, 'blitz', rated: true);

    config(['esports.fair_play.profile_standard.enabled' => true]);

    expect(app(RatedChess::class)->refusal($a, 'blitz'))->toStartWith('Before you can play rated:')
        ->and(fn () => $queue->join(psPlayer(['nip05_verified_at' => null]), 'blitz', rated: true))->toThrow(ChessRuleViolation::class, 'Set a NIP-05 address');

    $service = app(ChessGameService::class);
    $service->move($game->refresh(), $game->white, 'e2e4');
    $service->move($game->refresh(), $game->black, 'e7e5');
    $service->resign($game->refresh(), $game->black);

    expect(Rating::query()->where('pool', Rating::RATED)->where('user_id', $game->white_id)->value('rating'))->toBeGreaterThan(1000);
});

test('the player reads only the missing steps, in English and German; another player reads no step at all', function () {
    $fresh = psPlayer(['created_at' => now()->subDays(2), 'picture' => null]);
    $wait = 'Wait until '.LeagueTime::stamp(CarbonImmutable::instance($fresh->created_at)->addDays(7)).': an account plays rated from 7 days after its first login here.';

    expect(FairPlay::message($fresh->pubkey, $fresh))
        ->toBe('Before you can play rated: '.$wait.' Set a name and a profile picture in your Nostr client. Casual play stays open.')
        ->and(RatedTrustGate::message(RatedTrustGate::FAIR_PLAY, [psPlayer()->pubkey, $fresh->pubkey]))
        ->toBe('ada cannot play rated yet: the account does not meet the profile standard for rated play.');

    $noAddress = psPlayer(['nip05_verified_at' => null]);
    app()->setLocale('de');
    $german = FairPlay::message($noAddress->pubkey, $noAddress);

    expect($german)->toBe('Bevor du gewertet spielen kannst: Lege eine NIP-05-Adresse fest, zum Beispiel den eigenen Namen der Liga unter Einstellungen › Nostr-Adresse. Casual bleibt offen.')
        ->and(mb_strtolower($german.FairPlay::message($fresh->pubkey, $fresh)))->not->toContain('gesicht')->not->toContain('face');
});

test('the opponents page lists the missing steps with a link to the league\'s own NIP-05 name, and nothing once the standard is met', function () {
    $short = psPlayer(['nip05_verified_at' => null, 'picture' => null]);

    $this->actingAs($short)->get(route('settings.opponents'))->assertOk()
        ->assertSee('data-test="rated-standard-nip05"', false)
        ->assertSee('data-test="rated-standard-profile"', false)
        ->assertDontSee('data-test="rated-standard-known"', false)
        ->assertSee('href="'.route('settings.nip05').'"', false);

    $this->actingAs(psPlayer())->get(route('settings.opponents'))->assertOk()->assertDontSee('data-test="rated-standard"', false);
});

test('a pubkey without a user row lacks every mark and is barred', function () {
    $pubkey = str_repeat('ab', 32);

    expect(FairPlay::barred([$pubkey])[$pubkey] ?? null)->toMatchArray(['kind' => 'standard', 'until' => null]);
});

test('the exemption counts only the live season, results before the switch-on date and rating changes that were not reverted', function () {
    config(['esports.fair_play.profile_standard.since' => now()->subDays(3)->toIso8601String()]);
    $bare = ['nip05' => null, 'nip05_verified_at' => null];
    [$otherSeason, $afterCutoff, $reverted, $opponent] = [psPlayer($bare), psPlayer($bare), psPlayer($bare), psPlayer()];

    $earlier = openSeason(['slug' => 'season-0', 'genesis_at' => now()->subDays(90)->startOfSecond(), 'ends_at' => now()->subDays(40)->startOfSecond()]);
    psAttested($earlier, $otherSeason, $opponent, 5);

    $change = function (User $player, float $daysAgo, bool $isReverted): void {
        $rating = Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-1', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$player->id, 'user_id' => $player->id, 'rating' => 1000, 'results' => 1, 'draws' => 1]);
        $row = RatingChange::query()->create(['rating_id' => $rating->id, 'source' => RatingChange::CHESS, 'source_id' => $player->id, 'score' => 0.5, 'before' => 1000, 'after' => 1000, 'delta' => 0, 'results_before' => 0]);
        $row->forceFill(['created_at' => now()->subSeconds((int) round($daysAgo * 86400)), 'reverted_at' => $isReverted ? now() : null])->save();
    };
    $change($afterCutoff, 1, false);
    $change($reverted, 5, true);

    expect(array_keys(FairPlay::barred([$otherSeason->pubkey, $afterCutoff->pubkey, $reverted->pubkey])))
        ->toEqualCanonicalizing([$otherSeason->pubkey, $afterCutoff->pubkey, $reverted->pubkey]);
});

test('the switch-on date is read in the league\'s zone: a result half an hour after local midnight does not exempt', function () {
    $since = CarbonImmutable::parse('2026-12-01 00:00', LeagueTime::zone());
    $this->travelTo($since->addDay());
    $season = openSeason(['slug' => 'season-tz', 'genesis_at' => $since->subDays(30), 'ends_at' => $since->addDays(60)]);
    config(['esports.fair_play.profile_standard.since' => '2026-12-01']);
    [$late, $opponent] = [psPlayer(['nip05' => null, 'nip05_verified_at' => null, 'created_at' => $since->subDays(20)]), psPlayer()];
    psAttested($season, $late, $opponent, 0);
    SeasonAttestation::query()->where('season_id', $season->id)->update(['attested_at' => $since->addMinutes(30)->utc()]);

    expect(ProfileStandard::since()?->equalTo($since))->toBeTrue()
        ->and(ProfileStandard::isExempt($late))->toBeFalse();
});

test('the chess lobby names the profile standard, not a bar, and links to the missing steps', function () {
    config(['esports.chess.rated_queue' => true]);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $fresh = psPlayer(['created_at' => now()->subDays(2), 'picture' => null]);

    Livewire\Livewire::actingAs($fresh)->test('pages::chess.lobby')
        ->assertSee('profile not ready')
        ->assertSee(route('settings.opponents').'#rated-standard', false)
        ->assertDontSee('>barred<', false);
});
