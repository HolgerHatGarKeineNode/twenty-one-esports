<?php

/*
| Sharing a Blockfill moment: a verified personal best, a new first place of
| the week, a week place (so far, and final once the week ended). Each has a
| public moment page whose link preview is its own share card, a kind 1 note
| through the share button, and a share sheet for its owner only. A run the
| league has not verified is no moment.
*/

use App\Enums\StackerRunStatus;
use App\Games\GameRegistry;
use App\Jobs\VerifyStackerRun;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Cards\Canvas;
use App\Support\Cards\ShareCard;
use App\Support\Cards\SharePosts;
use App\Support\Cards\ShareRefused;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Stacker\BlockfillMoments;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use App\Support\Stacker\Verifier;
use App\Support\StreamBot\StreamBotCopy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BlockfillOn;
use Tests\Support\FakeStackerVerifier;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    Cache::flush();
    $this->app->instance(Verifier::class, new FakeStackerVerifier);
    // A Wednesday of ISO week 41: the week started on Monday 2026-10-05 00:00 Berlin.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    BlockfillOn::play();
});

/** A run of `$user` with `$ticks` handed in `$hoursAgo` hours ago, through the real verdict job (or left `$status`). */
function shareRun(User $user, int $ticks, float $hoursAgo, StackerRunStatus $status = StackerRunStatus::Verifying, ?string $token = null): StackerRun
{
    $at = CarbonImmutable::now()->subMinutes((int) round($hoursAgo * 60));
    $run = StackerRun::factory()->for($user)->create([
        'status' => $status, 'issued_at' => $at, 'started_at' => $at, 'submitted_at' => $at,
        'ticks' => $ticks, 'state_hash' => '00000000', 'replay' => 'AAAA',
        ...($token === null ? [] : ['token_hash' => StackerRuns::hashToken($token)]),
        // A run left unverified still names its week here, so only its status keeps it from being a moment.
        ...($status === StackerRunStatus::Verifying ? [] : ['week' => StackerRuns::weekOf($at)]),
    ]);

    if ($status === StackerRunStatus::Verifying) {
        VerifyStackerRun::dispatchSync($run->id);
    }

    return $run->refresh();
}

/**
 * Every kind of moment in week 41: Bert's new first place, Carl's personal
 * best behind it, Ada's place so far (her best is from the week before).
 *
 * @return array{ada: User, bert: User, carl: User, first: StackerRun, pb: StackerRun, place: StackerRun}
 */
function shareMomentsOfWeek(): array
{
    $ada = User::factory()->create(['name' => 'Satoshi Nakamoto']);
    $bert = User::factory()->create(['name' => 'HalvingHodler21']);
    $carl = User::factory()->create(['name' => 'Lightning Larry']);

    shareRun($ada, 2000, 24 * 7);
    $first = shareRun($bert, 2800, 5);
    $pb = shareRun($carl, 2900, 4);
    $place = shareRun($ada, 3100, 3);

    return compact('ada', 'bert', 'carl', 'first', 'pb', 'place');
}

test('a verified personal best has a public moment page whose link preview is its own share card', function () {
    $player = User::factory()->create(['name' => 'Satoshi Nakamoto']);
    $run = shareRun($player, 3000, 2);

    expect(app(BlockfillMoments::class)->of($run))->toMatchArray(['pb' => true, 'first' => true, 'place' => 1, 'final' => false]);

    $page = $this->get('/scores/blockfill/moment/'.$run->id)->assertOk()
        ->assertSee('0:50.000')->assertSee('Satoshi Nakamoto')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

    preg_match('~<meta property="og:image" content="([^"]+)"~', $page->getContent(), $image);
    $card = ShareCard::blockfill($run, app(BlockfillMoments::class)->of($run));

    expect($image[1] ?? null)->toBe($card->url('wide'))
        ->and($image[1])->toContain('/cards/en/blockfill/'.$run->id.'-wide.png?v=');

    $png = $this->get(substr($image[1], strlen(config('app.url'))))->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
    expect(getimagesizefromstring($png)[0] ?? null)->toBe(1200)
        ->and(getimagesizefromstring($png)[1] ?? null)->toBe(630);

    // Fingerprinted and cached: the same card is drawn once, the file name carries what it shows.
    expect(Storage::disk('local')->files('share-cards/blockfill/'.$run->id))->toHaveCount(1);
    $this->get(substr($image[1], strlen(config('app.url'))))->assertOk();
    expect(Storage::disk('local')->files('share-cards/blockfill/'.$run->id))->toHaveCount(1);

    $this->get('/cards/en/blockfill/'.$run->id.'-story.png')->assertOk()->assertHeader('Content-Type', 'image/png');
});

test('each kind of moment: a new first place, a personal best, a place so far, and the final place once the week ended', function () {
    $m = shareMomentsOfWeek();
    $moments = app(BlockfillMoments::class);

    expect($moments->of($m['first'])['kind'])->toBe('first')
        ->and($moments->of($m['pb'])['kind'])->toBe('pb')
        ->and($moments->of($m['place']))->toMatchArray(['kind' => 'place', 'place' => 3, 'final' => false]);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 23:30:00'));
    app(ScoreLeaderboards::class)->tick();

    expect(app(BlockfillWeeks::class)->find(BlockfillWeeks::startOf($m['first']->submitted_at))->status->value)->toBe('finished')
        ->and($moments->of($m['first']))->toMatchArray(['kind' => 'final', 'place' => 1, 'final' => true])
        ->and($moments->of($m['place']))->toMatchArray(['kind' => 'final', 'place' => 3]);
});

test('an unverified run is no moment: no page, no card, no share, no button on the result screen', function () {
    $player = User::factory()->create();
    $token = str_repeat('a', StackerRuns::TOKEN_LENGTH);
    $pending = shareRun($player, 3000, 1, StackerRunStatus::Pending, $token);
    $practice = shareRun($player, 3500, 1, StackerRunStatus::Practice);

    foreach ([$pending, $practice] as $run) {
        $this->get('/scores/blockfill/moment/'.$run->id)->assertNotFound();
        $this->get('/cards/en/blockfill/'.$run->id.'-wide.png')->assertNotFound();
        expect(fn () => app(SharePosts::class)->prepare($player, 'blockfill', (string) $run->id))->toThrow(ShareRefused::class);
        Livewire::actingAs($player)->test('blockfill-share')->call('openSheet', (string) $run->id)->assertDontSeeHtml('data-test="blockfill-share-sheet"');
    }

    $this->actingAs($player)->getJson('/stacker/runs/'.$token)->assertOk()->assertJson(['status' => 'pending', 'moment' => null]);

    // Positive control: the same player's verified run is shared, and the result screen gets its id.
    $verifiedToken = str_repeat('b', StackerRuns::TOKEN_LENGTH);
    $verified = shareRun($player, 2900, 0.5, token: $verifiedToken);
    $this->actingAs($player)->getJson('/stacker/runs/'.$verifiedToken)->assertOk()->assertJson(['status' => 'verified', 'moment' => (string) $verified->id]);
    Livewire::actingAs($player)->test('blockfill-share')->call('openSheet', (string) $verified->id)->assertSeeHtml('data-test="blockfill-share-sheet"');
});

test('only the owner is offered the share: not another player, not a guest, not on another player\'s row', function () {
    $m = shareMomentsOfWeek();
    $stranger = User::factory()->create();
    $id = (string) $m['first']->id;

    expect(fn () => app(SharePosts::class)->prepare($stranger, 'blockfill', $id))->toThrow(ShareRefused::class)
        ->and(fn () => app(SharePosts::class)->prepare($m['ada'], 'blockfill', $id))->toThrow(ShareRefused::class)
        ->and(app(SharePosts::class)->prepare($m['bert'], 'blockfill', $id)['kind'])->toBe(1);

    Livewire::actingAs($stranger)->test('blockfill-share')->call('openSheet', $id)->assertDontSeeHtml('data-test="blockfill-share-sheet"');
    Livewire::actingAs($m['bert'])->test('blockfill-share')->call('openSheet', $id)->assertSeeHtml('data-test="blockfill-share-sheet"')->assertSeeHtml('data-test="share-button"');

    // The moment page: public, the share button only for its owner.
    auth()->logout();
    $this->get('/scores/blockfill/moment/'.$id)->assertOk()->assertDontSee('data-test="blockfill-moment-share"', false);
    $this->actingAs($stranger)->get('/scores/blockfill/moment/'.$id)->assertOk()->assertDontSee('data-test="blockfill-moment-share"', false);
    // Its click names the run in plain script: a Blade directive inside a component's attribute is never compiled.
    $this->actingAs($m['bert'])->get('/scores/blockfill/moment/'.$id)->assertOk()->assertSee('data-test="blockfill-moment-share"', false)
        ->assertSee("{ detail: { moment: '".$id."' } }", false)->assertDontSee('@js(', false);

    // scores/blockfill, the week page and the game page: one share button, in the viewer's own row, with their own run.
    $week = app(BlockfillWeeks::class)->current();

    foreach (['/scores/blockfill', '/tournaments/'.$week->id, '/blockfill'] as $url) {
        $this->actingAs($stranger)->get($url)->assertOk()->assertDontSee('data-test="score-row-share"', false);
        $own = $this->actingAs($m['bert'])->get($url)->assertOk()->getContent();
        expect(substr_count($own, 'data-test="score-row-share"'))->toBe(1, $url)
            ->and($own)->toContain("moment: '".$id."'")
            ->and($own)->toContain('data-test="blockfill-share"');
    }
});

test('the note passes the copy rules in English and German: a link last, no hashtag, no fee, no face wording', function () {
    $m = shareMomentsOfWeek();
    $posts = app(SharePosts::class);
    $cases = [[$m['bert'], $m['first']], [$m['carl'], $m['pb']], [$m['ada'], $m['place']]];
    $notes = [];

    foreach (['en', 'de'] as $locale) {
        app()->setLocale($locale);

        foreach ($cases as [$user, $run]) {
            $notes[] = $posts->prepare($user, 'blockfill', (string) $run->id);
        }
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-12 23:30:00'));
    app(ScoreLeaderboards::class)->tick();

    foreach (['en', 'de'] as $locale) {
        app()->setLocale($locale);
        $notes[] = $posts->prepare($m['bert'], 'blockfill', (string) $m['first']->id);
    }

    app()->setLocale('en');

    expect($notes)->toHaveCount(8)
        ->and(array_unique(array_map(fn (array $note): string => strtok($note['content'], "\n"), $notes)))->toHaveCount(8);

    foreach ($notes as $note) {
        $lines = explode("\n", $note['content']);

        expect(StreamBotCopy::violations($note['content'], $note['tags']))->toBe([], $note['content'])
            ->and($note['content'])->not->toContain('#')
            ->and($note['content'])->not->toMatch('/\bfees?\b|geb(ü|ue)hr|\bface\b|gesicht/iu')
            ->and(end($lines))->toMatch('~^'.preg_quote(config('app.url'), '~').'/scores/blockfill/moment/\d+$~')
            ->and($note['tags'][0][1])->toStartWith('url '.config('app.url').'/cards/');
    }

    expect($notes[0]['content'])->toStartWith('New first place in Blockfill Week 41, 2026: 40 blocks mined in 0:46.666')
        ->and($notes[3]['content'])->toStartWith('Neuer erster Platz in Blockfill Woche 41, 2026');
});

test('no text on a Blockfill card is cut or runs off the edge, in English or German, wide and story', function () {
    $m = shareMomentsOfWeek();
    $long = User::factory()->create(['name' => 'Lightning Network Lions Max']);
    shareRun($long, 2000, 24 * 7);
    $longRun = shareRun($long, 359_999, 2);
    $moments = app(BlockfillMoments::class);
    $runs = [$m['first'], $m['pb'], $m['place'], $longRun];

    $cards = fn (): array => array_map(fn (StackerRun $run): ShareCard => ShareCard::blockfill($run->refresh(), $moments->of($run)), $runs);
    $built = $cards();

    $this->travelTo(CarbonImmutable::parse('2026-10-12 23:30:00'));
    app(ScoreLeaderboards::class)->tick();
    $built = [...$built, ...$cards()];

    expect(array_map(fn (ShareCard $card): string => $card->facts['kind'], $built))->toBe(['first', 'pb', 'place', 'place', 'final', 'final', 'final', 'final']);

    $cuts = [];

    foreach (['en', 'de'] as $locale) {
        app()->setLocale($locale);

        foreach ($built as $card) {
            foreach (array_keys(ShareCard::FORMATS) as $format) {
                Canvas::$cuts = [];
                $card->render($format);

                foreach (Canvas::$cuts as $cut) {
                    $cuts[] = "{$locale} {$format} {$card->facts['kind']} {$card->key}: {$cut}";
                }
            }
        }
    }

    Canvas::$cuts = null;
    app()->setLocale('en');

    expect($cuts)->toBe([]);
});

test('with Blockfill switched off a moment has no page, no share and no share button; its card stays for notes posted before', function () {
    $player = User::factory()->create();
    $run = shareRun($player, 3000, 2);
    $this->get('/cards/en/blockfill/'.$run->id.'-wide.png')->assertOk();

    config(['esports.blockfill.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    $this->get('/cards/en/blockfill/'.$run->id.'-wide.png')->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(app(BlockfillMoments::class)->run($run->id))->toBeNull()
        ->and(fn () => app(SharePosts::class)->prepare($player, 'blockfill', (string) $run->id))->toThrow(ShareRefused::class)
        ->and(app(BlockfillMoments::class)->shareableOn($player, Tournament::query()->where('game', 'blockfill')->sole()))->toBeNull();
    Livewire::actingAs($player)->test('blockfill-share')->call('openSheet', (string) $run->id)->assertDontSeeHtml('data-test="blockfill-share-sheet"');
});

/*
| The moment's page plays the run (the user, 2026-10-01: a shared link lands on
| the replay). Sharing is the owner's consent for that one run only.
*/

test('a guest opening a moment\'s link sees that run\'s replay under the moment, with the link preview unchanged', function () {
    $player = User::factory()->create(['name' => 'Satoshi Nakamoto']);
    $run = shareRun($player, 3000, 2);
    $moment = app(BlockfillMoments::class)->of($run);
    $card = ShareCard::blockfill($run, $moment);

    $html = $this->get('/scores/blockfill/moment/'.$run->id)->assertOk()
        ->assertSee('data-test="replay"', false)
        ->assertSee('data-test="replay-chain"', false)
        ->assertSee('data-test="blockfill-moment-headline"', false)
        ->assertSee('data-test="blockfill-moment-play"', false)
        ->assertDontSee('data-test="blockfill-moment-card"', false)
        ->getContent();
    // The viewer starts from this run (its config, rendered as JSON.parse('…') by @js).
    preg_match("~stackerReplay\\(JSON\\.parse\\('(.*?)'\\)\\)~s", $html, $config);
    preg_match('~<meta property="og:image" content="([^"]+)"~', $html, $image);
    preg_match('~<meta property="og:title" content="([^"]+)"~', $html, $title);
    preg_match('~<meta property="og:description" content="([^"]+)"~', $html, $description);

    expect(json_decode(json_decode('"'.($config[1] ?? '').'"'), true))->toMatchArray(['replay' => 'AAAA', 'ticks' => 3000])
        ->and($image[1] ?? null)->toBe($card->url('wide'))
        ->and($title[1] ?? null)->toBe('Satoshi Nakamoto in Blockfill: 0:50.000')
        ->and(html_entity_decode($description[1] ?? ''))->toBe('New first place of the week: Satoshi Nakamoto mined 40 blocks in 0:50.000 in Blockfill Week 41, 2026. Every run is replayed by the league before it counts.')
        ->and($html)->toContain('<meta name="robots" content="noindex, nofollow">');
});

test('a moment page plays its own run; a run that is no moment has none, its replay page is open as every verified one', function () {
    $m = shareMomentsOfWeek();
    $other = shareRun($m['ada'], 3300, 1);

    $this->get('/scores/blockfill/moment/'.$m['first']->id)->assertOk()->assertSee('data-test="replay"', false);
    $this->get(route('stacker.replay', $m['first']))->assertOk();
    $this->get(route('stacker.replay', $other))->assertOk();
    // a run that is no moment has no moment page, so no replay through it
    expect(app(BlockfillMoments::class)->of($other))->toBeNull();
    $this->get('/scores/blockfill/moment/'.$other->id)->assertNotFound();
});

test('a moment keeps its replay when its week\'s fastest push it out, up to its own bound per week; any other run drops it', function () {
    config(['esports.blockfill.replay_keep_top' => 1, 'esports.blockfill.replay_keep_shared' => 2]);
    [$ada, $bert, $carl, $dora] = User::factory()->count(4)->create();

    $adas = shareRun($ada, 3000, 5);
    // slower than her best, no record, not her place: no moment, so no replay outside the fastest
    $slower = shareRun($ada, 3300, 4.5);
    expect(app(BlockfillMoments::class)->of($slower->refresh()))->toBeNull()
        ->and($slower->replay)->toBeNull();

    // Bert's faster run takes the one place of the fastest; Ada's run, her best, stays watchable from her moment's link.
    shareRun($bert, 2000, 4);
    $carls = shareRun($carl, 3100, 3.5);
    expect($adas->refresh()->replay)->toBe('AAAA')
        ->and($adas->flags['moment'] ?? null)->toBeTrue()
        ->and($carls->refresh()->replay)->toBe('AAAA');

    // Opening the share sheet marks a run shared: every way out (Nostr, the system sheet, the link) starts there.
    Livewire::actingAs($carl)->test('blockfill-share')->call('openSheet', (string) $carls->id)->assertSeeHtml('data-test="blockfill-share-sheet"');
    expect($carls->refresh()->flags)->toHaveKey('shared');

    // Bounded per week: Dora's faster moment takes the second place of the kept moments, Carl's slowest drops out.
    $doras = shareRun($dora, 2900, 3);
    expect($doras->refresh()->replay)->toBe('AAAA')
        ->and($adas->refresh()->replay)->toBe('AAAA')
        ->and($carls->refresh()->replay)->toBeNull()
        ->and(StackerRun::query()->where('week', StackerRuns::weekOf(now()))->whereNotNull('replay')->count())->toBe(3);
});

test('a moment whose replay is gone shows its card and Play, no viewer and no error', function () {
    $player = User::factory()->create(['name' => 'Satoshi Nakamoto']);
    $run = shareRun($player, 3000, 2);
    $run->forceFill(['replay' => null])->save();

    $this->get('/scores/blockfill/moment/'.$run->id)->assertOk()
        ->assertSee('data-test="blockfill-moment-card"', false)
        ->assertSee('data-test="blockfill-moment-play"', false)
        ->assertSee('0:50.000')
        ->assertDontSee('data-test="replay"', false);
});
