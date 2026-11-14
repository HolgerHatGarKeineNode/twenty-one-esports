<?php

use App\Enums\ClanRole;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Clans\ClanLogos;
use App\Support\Nostr\ProfileCache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| /clans as cards with faces and proud moments
|--------------------------------------------------------------------------
|
| With 0, 2 and 12 clans, at 375 and 1440 px, in English and German: no
| horizontal overflow, the cards in name order and one column (375) or three
| (1440), every card with its mark and its players' faces, the logos decoded,
| the spotlight on the won series, the streak card lit, a clean console and
| no response >= 400, also after a search round trip. The collector's
| positive control runs on the first page.
|
| CLAN_PRIDE_SHOTS=<dir> additionally writes the screenshots there.
|
*/

/** Every card and the spotlight, scrolled into view one by one so lazy pictures load, then measured. */
const CLAN_PRIDE_MEASURE = <<<'JS'
    async () => {
        // Shown rows only: a search hides rows in the browser (performance plan P7). Clans.dc.html (P7): one table.
        const rows = [...document.querySelectorAll('[data-test=clan-card]')].filter((el) => el.checkVisibility());
        for (const row of rows) {
            row.scrollIntoView({ block: 'center' });
            await Promise.all([...row.querySelectorAll('img')].map((img) => img.complete ? null : new Promise((done) => { img.addEventListener('load', done, { once: true }); img.addEventListener('error', done, { once: true }); setTimeout(done, 3000); })));
        }
        window.scrollTo(0, 0);
        const table = document.querySelector('[data-test=clan-grid]');
        const edge = table ? table.getBoundingClientRect().right : 0;
        const rect = (el) => { const r = el.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y + scrollY), w: Math.round(r.width), h: Math.round(r.height) }; };
        const row = (el) => ({
            clan: el.dataset.clan,
            ...rect(el),
            mark: el.querySelector('[data-test$=-mark]') ? rect(el.querySelector('[data-test$=-mark]')) : null,
            logos: [...el.querySelectorAll('img[data-clan-logo]')].map((img) => [img.naturalWidth, img.loading]),
            moment: el.querySelector('[data-test=clan-moment]')?.innerText ?? null,
            // Nothing of a row reaches past the table's card (a sideways scroller would hide it).
            wider: [...el.querySelectorAll('*')].filter((c) => c.checkVisibility() && c.getBoundingClientRect().right > edge + 1).length,
        });
        return {
            spotlight: [...document.querySelectorAll('[data-test=clan-spotlight]')].map(row),
            cards: rows.map(row),
            tableScrolls: table ? table.scrollWidth > table.clientWidth : false,
            empty: document.querySelector('[data-test=clans-empty]') !== null,
            lang: document.documentElement.lang,
        };
    }
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    $this->logoRoot = public_path('__test-clan-pride-'.bin2hex(random_bytes(4)));
    config(['filesystems.disks.public.root' => $this->logoRoot, 'filesystems.disks.public.url' => '/'.basename($this->logoRoot)]);
    Storage::forgetDisk('public');
});

afterEach(function () {
    File::deleteDirectory($this->logoRoot);
});

function clanPridePage(User $viewer, string $locale, int $width): Page
{
    $page = visit(route('testing.login', ['user' => $viewer, 'to' => '/robots.txt']))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);

    // The session keeps the last language: set it every time.
    $page->goto(ComputeUrl::from('/locale/'.$locale));

    $page->goto(ComputeUrl::from('/clans'));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined && document.fonts.status === "loaded"', 10_000);

    return $page;
}

function clanPrideShot(Page $page, string $name): void
{
    $dir = getenv('CLAN_PRIDE_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

function clanPrideLogo(int $seed): string
{
    $image = imagecreatetruecolor(ClanLogos::SIZE, ClanLogos::SIZE);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 40 + 60 * $seed, 90, 200 - 50 * $seed));
    imagefilledellipse($image, 256, 256, 300, 300, (int) imagecolorallocate($image, 250, 250, 250));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    app(ClanLogos::class)->store($png);

    return app(ClanLogos::class)->urlFor($png);
}

/** $count clans founded two months ago, each with a 3v3 lineup and its players, long names among them. */
function clanPrideWorld(int $count, int $offset = 0): void
{
    $names = ['Orange Pill Squad Allgäu', 'Laser Eyes', 'HODL Rockets Kempten', 'Mempool Maniacs', 'Block 21', 'Nonce Hunters Zürich',
        'Stack Sats Crew', 'Lightning Boost', 'Difficulty Adjusters', 'Cold Storage', 'Halving Heroes', 'Genesis Guild'];

    foreach (range($offset, $offset + $count - 1) as $index) {
        // A ready 3v3 lineup brings its clan three players.
        $lineup = Lineup::factory()->mode('3v3')->ready()->create();
        $lineup->clan->forceFill(['name' => $names[$index], 'meetup_city' => $index % 3 === 0 ? 'Kempten' : null, 'created_at' => now()->subDays(60)])->save();
        ClanMember::query()->where('clan_id', $lineup->clan_id)->update(['joined_at' => now()->subDays(60)]);
    }
}

test('the clan table with marks and proud moments at 0, 2 and 12 clans, 375 and 1440 px, English and German', function () {
    $viewer = User::factory()->create(['name' => 'Vera Viewer']);
    $controlled = false;
    $streakName = '';

    foreach ([0, 2, 12] as $size) {
        if ($size === 2) {
            clanPrideWorld(2);
        }

        if ($size === 12) {
            clanPrideWorld(10, 2);
            // Logos after the first visit (the URL carries the test server's origin), on three clans.
            foreach (Clan::query()->orderBy('name')->take(3)->get() as $index => $clan) {
                $clan->update(['picture' => clanPrideLogo($index)]);
            }
            // A won series and a streak of 4: each the line under its clan's name.
            $winner = Clan::query()->where('name', 'Mempool Maniacs')->sole()->lineups()->sole();
            $loser = Clan::query()->where('name', 'Block 21')->sole()->lineups()->sole();
            SeriesMatch::factory()->create(['challenger_lineup_id' => $winner->id, 'challenged_lineup_id' => $loser->id, 'status' => SeriesStatus::Confirmed, 'winner' => 'challenger',
                'result_games' => [['winner' => 'challenger', 'challenger' => 3, 'challenged' => 1], ['winner' => 'challenger', 'challenger' => 2, 'challenged' => 0]], 'finished_at' => now()->subDay()]);
            // The streak is a player's with the longest name the league keeps of a Nostr profile, in one word: 301 letters
            // go in and ProfileCache cuts them. The factory's random name decided this test before: with 17 letters the
            // German table scrolled sideways at 375 px (358 in 341 px), with 25 the English one too (384 in 341 px).
            $signer = new TestSigner;
            $streaker = User::factory()->withPubkey($signer->pubkey)->create(['name' => null, 'profile_event_at' => null]);
            ProfileCache::apply($streaker, $signer->sign(0, content: (string) json_encode(['name' => str_repeat('Satoshi', 43)])));
            $streakName = (string) $streaker->refresh()->name;
            ClanMember::query()->create(['clan_id' => Clan::query()->where('name', 'Genesis Guild')->sole()->id, 'user_id' => $streaker->id, 'role' => ClanRole::Member, 'joined_at' => now()->subDays(60)]);
            foreach (range(1, 4) as $hours) {
                ChessGame::factory()->finished('1-0')->create(['white_id' => $streaker->id, 'ended_at' => now()->subHours($hours)]);
            }
        }

        cache()->flush();

        foreach ([[375, 'en'], [1440, 'en'], [375, 'de'], [1440, 'de']] as [$width, $locale]) {
            $page = clanPridePage($viewer, $locale, $width);

            if (! $controlled) {
                // Positive control: a thrown error and a 500 answer reach the collector.
                $page->evaluate('() => { setTimeout(() => { throw new Error("pride-probe"); }); return fetch("/__test/server-error"); }');
                BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("pride-probe")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
                $page->goto(ComputeUrl::from('/clans'));
                BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined', 10_000);
                $controlled = true;
            }

            $m = $page->evaluate(CLAN_PRIDE_MEASURE);
            $widths = $page->evaluate(BrowserConsole::WIDTHS);
            fwrite(STDERR, "\n[clan-pride] {$size} clans {$width}px {$locale} doc ".json_encode($widths).' '.json_encode($m, JSON_UNESCAPED_UNICODE)."\n");
            clanPrideShot($page, "clan-pride-{$size}-{$width}-{$locale}");

            $gridNames = array_map(fn (array $card): string => Clan::query()->where('slug', $card['clan'])->value('name'), $m['cards']);
            $sorted = $gridNames;
            sort($sorted);

            // Before Block 0 the table is by name; no spotlight any more (Clans.dc.html, P7).
            expect($m['lang'])->toBe($locale)
                ->and($widths[0])->toBeLessThanOrEqual($widths[1])
                ->and($m['empty'])->toBe($size === 0)
                ->and($m['spotlight'])->toBe([])
                ->and(count($m['cards']))->toBe($size)
                ->and($gridNames)->toBe($sorted)
                ->and($m['tableScrolls'])->toBeFalse()
                ->and(collect($m['cards'])->every(fn (array $card): bool => $card['mark'] !== null && $card['mark']['w'] === 32 && $card['wider'] === 0 && $card['h'] >= 44
                    && collect($card['logos'])->every(fn (array $logo): bool => $logo[0] > 0)))->toBeTrue();

            if ($size === 12) {
                $moments = collect($m['cards'])->mapWithKeys(fn (array $card): array => [Clan::query()->where('slug', $card['clan'])->value('name') => $card['moment']]);

                // The row really carries the long name: at least the 50 letters the league's own profile form takes, no space.
                expect($moments['Mempool Maniacs'])->toContain('Block 21')->toContain('2:0')
                    ->and(mb_strlen($streakName))->toBeGreaterThanOrEqual(50)
                    ->and($streakName)->not->toContain(' ')
                    ->and($moments['Genesis Guild'])->toContain($streakName)->toContain($locale === 'de' ? '4 Partien in Folge' : '4 games in a row');

                // A search (filtered in the browser since performance plan P7): one row shown.
                $page->locator('#clan-q')->fill('genesis');
                BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=clan-card]")].filter((el) => el.checkVisibility()).length === 1', 10_000);
            }

            expect($page->evaluate('() => window.__errors'))->toBe([])
                ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
        }
    }

});
