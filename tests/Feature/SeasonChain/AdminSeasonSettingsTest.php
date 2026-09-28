<?php

/*
 * AdminSeason, P35: the rating, rank and hashrate draft for Block 0 (the
 * board only (P39), logged, locked once a season is released and frozen onto it), the
 * read-only soft-reset preview (NIP "Season transition") and the review of
 * the last ended season.
 */

use App\Models\Admin;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonSettingChange;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Rating\EloRating;
use App\Support\Rating\RankTiers;
use App\Support\Rating\RatingSettings;
use App\Support\Rating\SoftReset;
use App\Support\SeasonChain\SeasonReleaseRefused;
use App\Support\SeasonChain\SeasonReview;
use App\Support\Series\Ladders;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    $this->admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $this->admin->pubkey]);
    config(['esports.board' => [NostrKeys::hexToNpub($this->admin->pubkey)]]);
});

/** A rated row of season `$slug`. */
function ratedRow(string $slug, User $user, int $rating, int $results = 5, string $game = 'chess', string $mode = 'blitz'): Rating
{
    return Rating::query()->create(['pool' => Rating::RATED, 'season' => $slug, 'game' => $game, 'mode' => $mode, 'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => $rating, 'results' => $results, 'wins' => $results]);
}

test('the page no longer lists what is not built, and shows the three new sections', function () {
    $this->actingAs($this->admin)->get(route('admin.season'))
        ->assertOk()
        ->assertDontSee('Not built here')
        ->assertSee('data-test="season-settings"', false)
        ->assertSee('data-test="season-soft-reset"', false)
        ->assertSee('data-test="season-review"', false)
        ->assertSee('No season has ended yet.');
});

test('a player who is not an admin can neither open the page nor save the settings', function () {
    $player = User::factory()->create();
    // A board member counts as an admin; this one is only on the admin list.
    config(['esports.board' => []]);

    $this->actingAs($player)->get(route('admin.season'))->assertForbidden();

    // Opened as an admin, then the admin role is gone: the action authorizes again.
    $page = Livewire::actingAs($this->admin)->test('pages::admin.season');
    Admin::query()->where('pubkey', $this->admin->pubkey)->delete();

    $page->set('rating.k', '24')->call('saveSettings')->assertForbidden();

    expect(SeasonSettingChange::query()->count())->toBe(0);
});

test('an admin who is not on the board sees the draft read-only, and the action refuses', function () {
    $other = User::factory()->create();
    Admin::query()->create(['pubkey' => $other->pubkey]);

    $page = Livewire::actingAs($other)->test('pages::admin.season')
        ->assertSet('rating.k', '32')
        ->assertSee('Only a board member on the public admin list can change these values.')
        ->assertDontSee('data-test="save-settings"', false)
        ->assertSeeHtml('data-test="settings-fields-rating" disabled');

    $page->set('rating.k', '24')->call('saveSettings')
        ->assertSet('settingsError', 'Only a board member on the public admin list can change these values.');

    expect(SeasonSettingChange::query()->count())->toBe(0)
        ->and(fn () => RatingSettings::saveDraft($other, array_replace_recursive(RatingSettings::defaults(), ['rating' => ['k' => 24]])))
        ->toThrow(SeasonReleaseRefused::class, 'Only a board member on the public admin list can change these values.');

    // A board member sees the same form editable.
    Livewire::actingAs($this->admin)->test('pages::admin.season')
        ->assertSee('data-test="save-settings"', false)
        ->assertDontSeeHtml('data-test="settings-fields-rating" disabled');
});

test('an admin saves the draft: logged with who and what, and in force before Block 0', function () {
    Livewire::actingAs($this->admin)->test('pages::admin.season')
        ->assertSet('rating.k', '32')
        ->assertSet('tiers.silver-3', '1000')
        ->set('rating.k', '24')
        ->set('rating.daily_pair_limit', '')
        ->set('tiers.grand-champion-3', '1500')
        ->set('hashrate.win', '4')
        ->call('saveSettings')
        ->assertSet('settingsError', '')
        ->assertSee('Saved. Block 0 releases the season with these values.')
        ->assertSee('K-factor: 32 → 24')
        ->assertSee('Grand Champion III: 1425 → 1500')
        ->assertSee('by satsjaeger');

    $change = SeasonSettingChange::query()->sole();

    expect($change->changed_by_id)->toBe($this->admin->id)
        ->and($change->changed_by_pubkey)->toBe($this->admin->pubkey)
        ->and($change->changes)->toBe([
            'rating.k' => [32, 24],
            'rating.daily_pair_limit' => [3, null],
            'tiers.grand-champion-3' => [1425, 1500],
            'hashrate.win' => [3, 4],
        ])
        ->and(RatingSettings::inForce()['rating']['k'])->toBe(24)
        ->and(EloRating::fromConfig('rating')->k)->toBe(24)
        ->and(EloRating::fromConfig('casual')->k)->toBe(32)
        ->and(RankTiers::fromConfig()->tierFor(1450, 5))->toBe('grand-champion-2')
        ->and(RatingSettings::inForce()['rating']['daily_pair_limit'])->toBeNull();
});

test('saving unchanged values logs nothing', function () {
    Livewire::actingAs($this->admin)->test('pages::admin.season')
        ->call('saveSettings')
        ->assertSee('Nothing changed.');

    expect(SeasonSettingChange::query()->count())->toBe(0);
});

test('a value out of range, a word or ranks out of order are refused, and nothing is logged', function (string $field, string $value, string $error) {
    Livewire::actingAs($this->admin)->test('pages::admin.season')
        ->set($field, $value)
        ->call('saveSettings')
        ->assertSet('settingsError', $error);

    expect(SeasonSettingChange::query()->count())->toBe(0);
})->with([
    'k of 0' => ['rating.k', '0', 'K-factor must be a whole number from 1 to 100.'],
    'a start of 5000' => ['rating.start', '5000', 'Start rating must be a whole number from 100 to 3000.'],
    'a word' => ['rating.scale', 'lots', 'Rating scale must be a whole number from 100 to 1000.'],
    'a negative hashrate' => ['hashrate.loss', '-1', 'Hashrate for a loss must be a whole number from 0 to 100.'],
    'an empty tier' => ['tiers.gold-1', '', 'Gold I must be a whole number from 0 to 5000.'],
    'a rank below the one under it' => ['tiers.gold-1', '1000', 'Gold I must start above the rank below it.'],
]);

test('keys added from the browser are ignored and the lowest rank stays at 0', function () {
    Livewire::actingAs($this->admin)->test('pages::admin.season')
        ->set('tiers.bronze-1', '500')
        ->set('tiers.mythic-1', '9000')
        ->set('rating.bonus', '7')
        ->set('rating.k', '30')
        ->call('saveSettings')
        ->assertSet('settingsError', '');

    $values = SeasonSettingChange::query()->sole()->values;

    expect($values['tiers']['bronze-1'])->toBe(0)
        ->and($values['tiers'])->not->toHaveKey('mythic-1')
        ->and($values['rating'])->not->toHaveKey('bonus')
        ->and(array_keys($values['tiers']))->toBe(array_keys(config('season.tiers')));
});

test('once a season is released the settings are locked and show what the season froze', function () {
    SeasonSettingChange::query()->create(['changed_by_pubkey' => $this->admin->pubkey, 'changed_by_id' => $this->admin->id,
        'values' => array_replace_recursive(RatingSettings::defaults(), ['rating' => ['k' => 24]]), 'changes' => ['rating.k' => [32, 24]]]);
    openSeason(['rating_parameters' => array_replace_recursive(RatingSettings::defaults(), ['rating' => ['k' => 20]])]);

    $page = Livewire::actingAs($this->admin)->test('pages::admin.season')
        ->assertSet('rating.k', '20')
        ->assertSee('frozen at Block 0 in the signed ladders')
        ->assertDontSee('data-test="save-settings"', false);

    // The action refuses too, not just the disabled form.
    $page->set('rating.k', '28')->call('saveSettings')
        ->assertSet('settingsError', 'A season has been released: its rating, rank and hashrate values are frozen in its signed ladders and cannot change.');

    expect(SeasonSettingChange::query()->count())->toBe(1)
        // The season's values are in force, not the draft.
        ->and(EloRating::fromConfig('rating')->k)->toBe(20);
});

test('a season released before P35 carries no values and reads config/season.php', function () {
    openSeason();

    expect(Season::query()->sole()->rating_parameters)->toBeNull()
        ->and(RatingSettings::inForce())->toBe(RatingSettings::defaults());
});

test('the release freezes the draft onto the season and into the ladder tags', function () {
    $signer = new TestSigner;
    $board = User::factory()->withPubkey($signer->pubkey)->create();
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)], 'esports.league.nsec' => (new TestSigner)->secret, 'esports.trust.nsec' => (new TestSigner)->secret]);

    $page = Livewire::actingAs($board)->test('pages::admin.season')
        ->set('rating.k', '24')
        ->set('rating.start', '1200')
        ->set('tiers.bronze-2', '1100')
        ->set('tiers.bronze-3', '1125')
        ->set('tiers.silver-1', '1150')
        ->set('tiers.silver-2', '1175')
        ->set('tiers.silver-3', '1200')
        ->set('tiers.gold-1', '1225')
        ->set('tiers.gold-2', '1250')
        ->set('tiers.gold-3', '1275')
        ->set('tiers.platinum-1', '1300')
        ->set('tiers.platinum-2', '1325')
        ->set('tiers.platinum-3', '1350')
        ->set('tiers.diamond-1', '1375')
        ->set('tiers.diamond-2', '1400')
        ->set('tiers.diamond-3', '1425')
        ->set('tiers.champion-1', '1450')
        ->set('tiers.champion-2', '1475')
        ->set('tiers.champion-3', '1500')
        ->set('tiers.grand-champion-1', '1525')
        ->set('tiers.grand-champion-2', '1575')
        ->set('tiers.grand-champion-3', '1625')
        ->call('saveSettings')
        ->assertSet('settingsError', '')
        ->set('draft.message', 'Pre-Season: every fair win is a block')
        ->call('saveDraft')
        ->assertSet('draftError', '')
        ->set('supply', '2 100 000');

    $page->call('release', json_encode($signer->signTemplates($page->instance()->prepareRelease())))
        ->assertSet('releaseError', '')
        ->assertSee('frozen at Block 0 in the signed ladders');

    $season = Season::query()->sole();
    $tags = NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => 'chess/blitz/pre-season'])->sole()->payload()['tags'];

    expect($season->rating_parameters)->toBe(RatingSettings::draft())
        ->and(SeasonSettingChange::query()->latest('id')->first()->values['chain']['message'])->toBe('Pre-Season: every fair win is a block')
        ->and($season->rating_parameters['rating']['k'])->toBe(24)
        ->and($tags)->toContain(['rating', 'elo', '1200', '24', '400'], ['tier', 'silver-3', '1200'], ['tier', 'grand-champion-3', '1625'])
        ->and(EloRating::fromConfig('rating')->start)->toBe(1200);
});

test('soft reset: the NIP formula, rounded halves away from zero, in exact decimals', function (int $final, int $startA, int $startB, string $factor, int $seed) {
    expect(SoftReset::seed($final, $startA, $startB, (int) SoftReset::factorMilli($factor)))->toBe($seed);
})->with([
    'half carried, NIP example (1002 → 1001)' => [1002, 1000, 1000, '0.5', 1001],
    'half up' => [1015, 1000, 1000, '0.5', 1008],
    'half down, away from zero' => [985, 1000, 1000, '0.5', 992],
    'hard reset' => [1300, 1000, 1000, '0', 1000],
    'full carry-over' => [1300, 1000, 1000, '1', 1300],
    'new start rating' => [1100, 1000, 1200, '0.5', 1250],
    '50 x 0.29 is 14.5, not 14.4999' => [1050, 1000, 1000, '0.29', 1015],
    'comma as decimal mark' => [1100, 1000, 1000, '0,25', 1025],
]);

test('soft reset: a factor outside 0 to 1 or with more than three decimals is no factor', function (string $input) {
    expect(SoftReset::factorMilli($input))->toBeNull();
})->with(['1.5', '-0.5', 'half', '', '0.1234', '2']);

test('the preview lists every rated row with a result, 25 a page, with the seed of the formula', function () {
    $season = openSeason();
    $players = User::factory()->count(26)->create();

    foreach ($players as $index => $player) {
        ratedRow($season->slug, $player, 1100 - $index);
    }

    // No rated result: nothing to carry over.
    ratedRow($season->slug, User::factory()->create(['name' => 'Never played']), 1000, 0);
    // Another pool and another season stay out.
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$players[0]->id, 'user_id' => $players[0]->id, 'rating' => 1500, 'results' => 9]);

    $page = Livewire::actingAs($this->admin)->test('pages::admin.season')
        ->assertSee('the season is still live: ratings so far')
        ->assertDontSee('Never played');

    $seeds = fn () => collect($page->instance()->resetRows->items())->map(fn (Rating $row): int => SoftReset::seed($row->rating, 1000, 1000, 500))->all();

    expect($page->instance()->resetRows->total())->toBe(26)
        ->and($page->instance()->resetRows->count())->toBe(25)
        ->and($seeds()[0])->toBe(1050);

    $page->assertSeeHtml('data-test="reset-seed">1050<')
        // f = 0.2: 1000 + round(100 * 0.2) = 1020
        ->set('resetFactor', '0.2')
        ->assertSeeHtml('data-test="reset-seed">1020<')
        ->call('gotoPage', 2, 'reset')
        ->assertSee($players[25]->displayName())
        ->set('resetFactor', '1.5')
        ->assertSee('The factor is a number from 0 to 1 with at most three decimals.')
        ->assertDontSeeHtml('data-test="reset-table"');
});

test('the preview of a lineup ladder names the clan', function () {
    $season = openSeason();
    $lineup = Lineup::factory()->create(['game' => 'rocket-league', 'mode' => '2v2']);
    Rating::query()->create(['pool' => Rating::RATED, 'season' => $season->slug, 'game' => 'rocket-league', 'mode' => '2v2', 'subject' => 'lineup:'.$lineup->id, 'lineup_id' => $lineup->id, 'rating' => 1040, 'results' => 6]);

    Livewire::actingAs($this->admin)->test('pages::admin.season')
        ->assertSee($lineup->clan->name)
        ->assertSeeHtml('data-test="reset-seed">1020<');
});

test('the review of an ended season shows each ladder\'s champion, the chain and no payouts', function () {
    $season = openSeason(['genesis_at' => now()->subDays(30), 'ends_at' => now()->subHour()]);
    $champion = User::factory()->create(['name' => 'Hodl Queen']);
    ratedRow($season->slug, $champion, 1180, 12);
    ratedRow($season->slug, User::factory()->create(['name' => 'Runner Up']), 1120, 12);
    ratedRow($season->slug, User::factory()->create(['name' => 'Few Games']), 1400, 0);
    SeasonAttestation::query()->create([
        'season_id' => $season->id, 'source' => 'chess', 'source_id' => 1, 'label' => '#1', 'game' => 'chess', 'mode' => 'blitz',
        'ladder_address' => 'chess/blitz', 'attested_at' => now()->subDays(20), 'candidate' => null, 'height' => 1, 'era' => 1,
        'reward_per_player' => 20_000, 'reward' => 20_000, 'event_id' => hash('sha256', 'block-1'),
    ]);

    Livewire::actingAs($this->admin)->test('pages::admin.season')
        ->assertSee('Hodl Queen')
        ->assertSee('1180 Elo · Diamond I · 12 rated results')
        ->assertDontSee('Few Games')
        ->assertSee('No season payouts have been made.');

    $review = app(SeasonReview::class)->of($season);

    expect($review['champions'])->toHaveCount(1)
        ->and($review['champions'][0]['name'])->toBe('Hodl Queen')
        ->and($review['blocks'])->toBe(1);
});

test('a queue worker forgets the values in force before every job, so a change made elsewhere reaches the next job', function () {
    expect(RatingSettings::inForce()['rating']['k'])->toBe(32);

    $values = RatingSettings::defaults();
    $values['rating']['k'] = 27;
    // Written by another process: a raw row, so no model event of this one forgets the memo.
    DB::table('season_setting_changes')->insert(['changed_by_id' => $this->admin->id, 'changed_by_pubkey' => $this->admin->pubkey, 'values' => json_encode($values), 'changes' => json_encode(['rating.k' => [32, 27]]), 'created_at' => now(), 'updated_at' => now()]);

    expect(RatingSettings::inForce()['rating']['k'])->toBe(32);

    event(new JobProcessing('database', Mockery::mock(Job::class)->shouldIgnoreMissing()));

    expect(RatingSettings::inForce()['rating']['k'])->toBe(27);
});
