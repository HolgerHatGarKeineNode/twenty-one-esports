<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Tmnf\TmnfWeeks;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentEditor;
use App\Support\Tournaments\TournamentPublisher;
use Pest\Browser\Support\ComputeUrl;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\BlockfillOn;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

/*
| The revamped tournament pages (plan "Refactor und Design-Revamp", P6; boards Tournaments, TournamentsPhone,
| Archive, Archive-Phone, TournamentDetail, TournamentDetailPhone and the T6 sub-pages) in a league that looks like
| the canvas: a featured organizer tournament with a pot, casual cups open for sign-up, a running Blockfill week, a
| finished organizer tournament with its bracket, pot and payouts, a finished casual cup and called-off cups.
| At 390 and 1440: the console and every response stay quiet, nothing runs past the page or out of its box, and the
| main parts are where the boards put them. DESIGN_SHOTS=1 also saves full-page screenshots for the side-by-side.
*/

beforeEach(function () {
    config(['session.driver' => 'database', 'esports.league.nsec' => (new TestSigner)->secret]);
    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/**
 * The canvas league of the tournament pages; returns [admin, featured, finished].
 *
 * @return array{0: User, 1: Tournament, 2: Tournament}
 */
function tourRevampLeague(): array
{
    BlockfillOn::play();
    BlockliOn::play();
    app()->forgetInstance(GameRegistry::class);
    leagueWeeksApproved(Blockfill::SLUG);
    app(BlockfillWeeks::class)->open();

    $admin = User::factory()->create(['name' => 'Erdöpfökasbrot']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    // The featured organizer tournament with its pot (rule R9).
    $featured = openTournament([
        'name' => 'Blockli Cup #1: Das erste Blockli-Turnier', 'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::RoundRobin,
        'options' => FormatOptions::defaults(GameProfile::for('blockli', 'correspondence'))->toArray(), 'capacity' => 12, 'starts_at' => now()->addDays(4)->setTime(17, 0),
    ]);
    $featured->forceFill(['pool_opened_at' => now(), 'pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 50_400, 'pot_balance_at' => now(), 'prize_target_sats' => 50_400, 'prize_split' => [100]])->save();
    foreach (['DerCaddy', 'El Presidento Ben', 'markusturm', 'Noerd', 'Anomark', 'webdiverblue', 'Johnny', 'el-z', 'Nidan21', 'Lotte'] as $name) {
        $user = User::factory()->create(['name' => $name]);
        TournamentSignup::query()->create(['tournament_id' => $featured->id, 'user_id' => $user->id, 'name' => $user->displayName(), 'members' => [$user->id]]);
    }

    // Casual cups open for sign-up.
    $saturday = now()->next('Saturday')->setTime(18, 0);
    foreach ([['rocket-league', '1v1', 'Rocket League Casual Cup EU #2', 8, 4, 'eu'], ['chess', 'blitz', 'Chess Casual Cup EU #2', 4, 0, 'eu'], ['chess', 'blitz', 'Chess Casual Cup US #1', 4, 1, 'us']] as $index => [$game, $mode, $name, $capacity, $taken, $region]) {
        $cup = Tournament::factory()->create([
            'name' => $name, 'game' => $game, 'mode' => $mode, 'format' => TournamentFormat::SingleElimination, 'capacity' => $capacity, 'status' => TournamentStatus::Signup,
            'options' => FormatOptions::defaults(GameProfile::for($game, $mode))->toArray(), 'starts_at' => $saturday->copy()->addHours($index * 6), 'signup_closes_at' => $saturday->copy()->addHours($index * 6)->subMinutes(10),
            'published_at' => now(), 'cup_series' => "{$game}-{$region}", 'cup_number' => 2, 'created_by_id' => null, 'slug' => "tour-revamp-cup-{$index}",
        ]);
        foreach (User::factory()->count($taken)->create() as $user) {
            TournamentSignup::query()->create(['tournament_id' => $cup->id, 'user_id' => $user->id, 'name' => $user->displayName(), 'members' => [$user->id]]);
        }
    }

    // A finished organizer tournament: six players, a match for third place, a pot and its payouts.
    $finished = runningChess(TournamentFormat::SingleElimination, 6, TournamentResultsMode::Director, ['thirdPlace' => true]);
    foreach (['AncapCrab', 'El Presidento Ben', 'Lux', 'MissMarple', 'Satoshi Stacker', 'Industrie_KPI'] as $index => $name) {
        TournamentParticipant::query()->where('tournament_id', $finished->id)->where('name', 'Player '.($index + 1))->update(['name' => $name]);
    }
    playOutAsDirector($finished);
    $finished->forceFill(['name' => '21,000 Sats, Zero Ball Control', 'published_at' => now()->subDays(8), 'created_by_id' => organizer()->id, 'starts_at' => now()->subDays(6),
        'pool_opened_at' => now()->subDays(8), 'pool_closed_at' => now()->subDays(5), 'pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 23_310, 'prize_target_sats' => 23_310, 'payouts_approved_at' => now()->subDays(5)])->save();
    $champion = app(TournamentChampion::class)->of($finished->refresh());
    TournamentPayout::query()->create(['tournament_id' => $finished->id, 'user_id' => $champion->user_id, 'participant_id' => $champion->id, 'pubkey' => User::query()->find($champion->user_id)->pubkey,
        'name' => 'Account Name Of The Champion', 'place' => 1, 'amount_sats' => 11_655, 'idempotency_key' => 'tour-revamp-1', 'status' => PayoutStatus::Paid]);

    // A finished casual cup and called-off cups.
    wonCasualCup(User::factory()->create(['name' => 'aHeck13']), User::factory()->create(['name' => 'Lotte2']))->forceFill(['published_at' => now()->subDays(9)])->save();
    foreach (['Nine Men\'s Morris Casual Cup EU #1', 'Checkers Casual Cup EU #1'] as $index => $name) {
        Tournament::factory()->create(['name' => $name, 'status' => TournamentStatus::Cancelled, 'published_at' => now()->subDays(3), 'starts_at' => now()->subDays(2 + $index), 'slug' => "tour-revamp-off-{$index}"]);
    }

    return [$admin->refresh(), $featured->refresh(), $finished->refresh()];
}

/** Opens `$path` at `$width` (German), guest or logged in, with the console collector in place. */
function tourRevampPage(?User $user, string $path, int $width): mixed
{
    $page = visit($user === null ? '/robots.txt' : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from($path));
    $page->waitForLoadState('networkidle');

    return $page;
}

/** Nothing runs past the viewport or out of its own box (the squeeze detector of HomeRevampTest). */
const TOUR_REVAMP_SQUEEZE = <<<'JS'
() => {
    const out = [];
    const width = document.documentElement.clientWidth;
    if (document.documentElement.scrollWidth > width) out.push('page ' + document.documentElement.scrollWidth + ' > ' + width);
    for (const el of document.querySelectorAll('main *, header *, footer *')) {
        if (!el.checkVisibility() || el.closest('.rv-chips-track, .rv-tabs, [aria-hidden=true], .sr-only, #game-hub, #account-menu, #more-sheet, .overflow-x-auto')) continue;
        const r = el.getBoundingClientRect();
        if (r.width === 0) continue;
        if (r.right > width + 1 || r.left < -1) out.push((el.dataset.test || el.tagName) + ' x ' + Math.round(r.left) + '..' + Math.round(r.right));
        if (['A', 'BUTTON'].includes(el.tagName) && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflow === 'visible') out.push('spill ' + (el.dataset.test || el.textContent.trim().slice(0, 30)));
    }
    return [...new Set(out)].slice(0, 20);
}
JS;

function tourRevampQuiet(mixed $page, string $label): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses')
        ->and($page->evaluate(TOUR_REVAMP_SQUEEZE))->toBe([], $label.': squeeze');
}

function tourRevampShot(mixed $page, string $name): void
{
    if (getenv('DESIGN_SHOTS')) {
        $page->screenshot(true, $name);
    }
}

test('the tournaments page and the archive stay quiet and inside the page for an admin, at 390 and 1440', function () {
    [$admin, $featured, $finished] = tourRevampLeague();
    $championName = app(TournamentChampion::class)->of($finished)->name;

    foreach ([1440, 390] as $width) {
        $page = tourRevampPage($admin, route('tournaments.index'), $width);
        tourRevampShot($page, "tournaments-{$width}");
        expect($page->evaluate('() => document.querySelector("[data-test=featured-tournament]")?.dataset.tournament'))->toBe((string) $featured->id)
            ->and($page->evaluate('() => [...document.querySelectorAll("[data-test=running-row]")].map((r) => r.dataset.kind)'))->toContain('week')
            ->and($page->evaluate('() => document.body.innerText.includes("Zero Ball Control")'))->toBeFalse();
        tourRevampQuiet($page, "tournaments {$width}");

        $archive = tourRevampPage($admin, route('tournaments.archive'), $width);
        tourRevampShot($archive, "archive-{$width}");
        expect($archive->evaluate('() => document.querySelector("[data-test=hof-card]")?.innerText ?? ""'))->toContain($championName)
            ->and($archive->evaluate('() => document.body.innerText.includes("Account Name Of The Champion")'))->toBeFalse();
        tourRevampQuiet($archive, "archive {$width}");
    }
});

test('a tournament page opens on its hero with the pot and the champion, the chat on the right, and names each player once', function () {
    [$admin, $featured, $finished] = tourRevampLeague();
    $championName = app(TournamentChampion::class)->of($finished)->name;

    foreach ([1440, 390] as $width) {
        $page = tourRevampPage($admin, route('tournaments.show', $finished), $width);
        tourRevampShot($page, "detail-finished-{$width}");
        expect($page->evaluate('() => document.querySelector("[data-test=champion-name]")?.innerText ?? ""'))->toBe($championName)
            ->and($page->evaluate('() => document.querySelector("[data-test=payout-name]")?.innerText ?? ""'))->toBe($championName)
            ->and($page->evaluate('() => document.body.innerText.includes("Account Name Of The Champion")'))->toBeFalse()
            ->and($page->evaluate('() => document.querySelector("[data-test=pool-state]")?.innerText ?? ""'))->not->toContain('Routing');
        tourRevampQuiet($page, "finished {$width}");

        $open = tourRevampPage($admin, route('tournaments.show', $featured), $width);
        tourRevampShot($open, "detail-open-{$width}");
        expect($open->evaluate('() => document.querySelector("[data-test=to-signup]")?.innerText.trim()'))->toBe('Eintragen');
        tourRevampQuiet($open, "open {$width}");
    }

    // At 1440 the chat stands in the right rail beside the tabs.
    $page = tourRevampPage($admin, route('tournaments.show', $finished), 1440);
    expect($page->evaluate('() => { const r = document.querySelector("[data-test=game-rail], [data-test=desk-rail]").getBoundingClientRect(); const t = document.querySelector("[data-test=tournament-tabs]").getBoundingClientRect(); return r.left > t.right && r.top <= t.top + 80; }'))->toBeTrue();
});

test('the T6 sub-pages share one head with their tabs and stay quiet, at 390 and 1440', function () {
    [$admin, $featured, $finished] = tourRevampLeague();
    $player = User::factory()->create(['name' => 'Neuling']);

    foreach ([1440, 390] as $width) {
        foreach ([
            'signup' => [$player, route('tournaments.signup', $featured), 'signup'],
            'draw' => [$admin, route('tournaments.draw', $featured), 'draw'],
            'pool' => [$admin, route('tournaments.pool', $featured), 'pool'],
            'live' => [$admin, route('tournaments.live', $featured), 'live'],
            'director' => [$admin, route('tournaments.director', $finished), 'director'],
        ] as $name => [$user, $path, $tab]) {
            $page = tourRevampPage($user, $path, $width);
            tourRevampShot($page, "sub-{$name}-{$width}");
            expect($page->evaluate('() => document.querySelector("[data-test=tournament-page-tabs] [aria-current]")?.dataset.test'))->toBe("page-tab-{$tab}");
            tourRevampQuiet($page, "{$name} {$width}");
        }
    }
});

test('the TMNF week board (ScoresTournament.dc.html) stays quiet and inside the page, at 390 and 1440', function () {
    config(['esports.tmnf.server.address' => 'tmnf.einundzwanzig.space:2350']);
    tmnfOn();
    leagueWeeksApproved(TrackmaniaNationsForever::SLUG);
    foreach (['el-z' => 16_130, 'El Presidento Ben' => 16_300, 'webdiverblue' => 16_320] as $index => $ms) {
        tmnfPlayer('driver_'.md5($index), linked: true, attributes: ['name' => $index]);
        tmnfFinish('driver_'.md5($index), $ms, now()->subHours(3));
    }
    $week = app(TmnfWeeks::class)->current();
    $viewer = User::factory()->create();

    foreach ([1440, 390] as $width) {
        $page = tourRevampPage($viewer, route('tournaments.scores', $week), $width);
        tourRevampShot($page, "scores-tmnf-{$width}");
        expect($page->evaluate('() => !!document.querySelector("[data-test=tmnf-join]")'))->toBeTrue();
        tourRevampQuiet($page, "tmnf {$width}");
    }
});

test('the game chips fold into "+N weitere" from sm up and keep the active chip in the row', function () {
    tourRevampLeague();
    $page = tourRevampPage(null, route('tournaments.index', ['game' => 'blockli']), 660);

    expect($page->evaluate('() => document.querySelector("[data-test=filter-game-more]")?.checkVisibility() ?? false'))->toBeTrue()
        ->and($page->evaluate('() => document.querySelector("[data-test=filter-game-blockli]").checkVisibility()'))->toBeTrue()
        ->and($page->evaluate('() => { const t = document.querySelector("[data-test=filter-game] .rv-chips-track"); return t.scrollWidth <= t.clientWidth + 1; }'))->toBeTrue();

    $page->locator('[data-test=filter-game-more]')->click();
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-chip-menu]")].some((a) => a.checkVisibility())', 3_000);
    expect($page->evaluate('() => [...document.querySelectorAll("[data-chip-menu]")].filter((a) => a.checkVisibility()).length'))->toBe(3);
});

test('the tournament pages fit every width from 390 to 1920 without a squeeze', function () {
    [$admin, $featured, $finished] = tourRevampLeague();
    // A game played on the player's own copy: the "you need your own copy" box with its platform chips in the hero.
    $ownCopy = openTournament(['name' => 'Halving Cup', 'capacity' => 24, 'description' => "Premiere! The rules are here:\nhttps://esports.einundzwanzig.space/rules#blockli-and-everything-else"], rocketLeague: true);
    $seen = [];

    foreach ([390, 768, 1024, 1280, 1440, 1920] as $width) {
        foreach (['/tournaments' => route('tournaments.index'), '/tournaments/archive' => route('tournaments.archive'), 'finished' => route('tournaments.show', $finished), 'open' => route('tournaments.show', $featured), 'own copy' => route('tournaments.show', $ownCopy)] as $label => $path) {
            $page = tourRevampPage($admin, $path, $width);
            $seen["{$label} {$width}"] = $page->evaluate(TOUR_REVAMP_SQUEEZE);
        }
    }

    fwrite(STDERR, "\n[tournaments squeeze] ".json_encode($seen));
    expect(array_filter($seen))->toBe([]);
});

test('positive control: the collector of this file sees a throw on the tournaments page', function () {
    $page = tourRevampPage(null, route('tournaments.index'), 390);
    $page->evaluate('() => { setTimeout(() => { throw new Error("tournaments positive control"); }); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("tournaments positive control"))', 5_000);

    expect(fn () => tourRevampQuiet($page, 'positive control'))->toThrow(ExpectationFailedException::class);
});

test('an entrant who has not voted on the start finds the vote card in the first screen, and long names stay inside their boxes at 390', function () {
    $tournament = Tournament::factory()->create(['name' => 'Blitz Abend mit Terminabstimmung', 'created_by_id' => organizer()->id, 'capacity' => 16, 'starts_at' => now()->addDays(11)->setTime(19, 0)]);
    app(TournamentEditor::class)->update($tournament, $tournament->creator, [
        'start_options' => [now()->addDays(11)->setTime(19, 0)->format('Y-m-d H:i'), now()->addDays(12)->setTime(18, 0)->format('Y-m-d H:i'), now()->addDays(13)->setTime(16, 0)->format('Y-m-d H:i')],
        'start_vote_until' => now()->addDays(9)->setTime(19, 0),
    ]);
    $tournament = app(TournamentPublisher::class)->publish($tournament->refresh(), $tournament->creator, now()->addDays(10)->setTime(19, 0));
    // With a pot, as the organizer tournaments that vote are: the pot and, for an entrant without a Lightning address, its hint stand in the hero too.
    $tournament->forceFill(['description' => 'Drei Abende zur Wahl, gespielt wird an dem, den die meisten schaffen.', 'pool_opened_at' => now(), 'pot_source' => Tournament::POT_WALLET,
        'pot_balance_sats' => 21_000, 'pot_balance_at' => now(), 'prize_target_sats' => 21_000, 'prize_split' => [100]])->save();

    // The longest names a Nostr profile realistically carries: one unbroken, the others with spaces.
    [$entrant, $key] = keyedPlayer();
    $entrant->forceFill(['name' => 'Satoshi_Nakamoto_Fanclub_Hamburg_2140'])->save();
    soloSignup($tournament, $entrant, $key);
    foreach (['Erdöpfökasbrot-Bitcoin-Maximalist-21M', 'El Presidento Ben von und zu Lightning', 'DerCaddy', 'webdiverblue'] as $name) {
        $user = User::factory()->create(['name' => $name]);
        TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $user->displayName(), 'members' => [$user->id]]);
    }

    foreach ([[390, 844], [1280, 800]] as [$width, $height]) {
        $page = tourRevampPage($entrant, route('tournaments.show', $tournament), $width);
        $page->setViewportSize($width, $height);
        // The card's top and its first tick, against the bottom of what is seen: the viewport above the bars fixed
        // to its bottom (a phone's action bar and tab bar), so the first start can be ticked without scrolling.
        $fit = $page->evaluate(<<<'JS'
            () => {
                window.scrollTo(0, 0);
                const card = document.querySelector('[data-test=start-vote]').getBoundingClientRect();
                const tick = document.querySelector('[data-test=start-vote-tick]').getBoundingClientRect();
                let floor = window.innerHeight;
                for (const el of document.querySelectorAll('body *')) {
                    if (getComputedStyle(el).position !== 'fixed' || !el.checkVisibility()) continue;
                    const r = el.getBoundingClientRect();
                    if (r.height > 0 && r.width > window.innerWidth / 2 && r.bottom >= window.innerHeight - 1 && r.top > window.innerHeight / 2) floor = Math.min(floor, r.top);
                }
                return { top: Math.round(card.top), tick: Math.round(tick.bottom), floor: Math.round(floor), scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth] };
            }
            JS);
        fwrite(STDERR, "\n[start vote] {$width}x{$height}: ".json_encode($fit));
        tourRevampShot($page, "vote-entrant-{$width}");

        expect($fit['top'])->toBeLessThan($height, "{$width}: the vote card starts in the first screen")
            // On a phone the bars fixed to the bottom hide what stands behind them: there the first tick has to clear them.
            ->and($width > 400 || $fit['tick'] <= $fit['floor'])->toBeTrue("{$width}: its first tick above the bars fixed to the bottom")
            ->and($fit['scroll'][0])->toBeLessThanOrEqual($fit['scroll'][1], "{$width}: no horizontal scroll");
        tourRevampQuiet($page, "vote entrant {$width}");
    }
});
