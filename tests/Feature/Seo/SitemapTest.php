<?php

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Http\Controllers\RobotsController;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Seo\Sitemap;

/*
|--------------------------------------------------------------------------
| sitemap.xml and robots.txt (P14)
|--------------------------------------------------------------------------
*/

/**
 * The <loc> values of a sitemap document, parsed as XML (fails the test if it is not).
 *
 * @return list<string>
 */
function sitemapLocs(string $xml): array
{
    $document = new SimpleXMLElement($xml);
    $locs = [];

    foreach ($document->children() as $entry) {
        $locs[] = (string) $entry->loc;
    }

    return $locs;
}

test('the index lists one file per section that has pages', function () {
    User::factory()->create();

    $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    expect(sitemapLocs($response->getContent()))->toBe([
        route('sitemap.section', ['pages', 1]),
        route('sitemap.section', ['players', 1]),
    ]);
});

test('a section longer than one file is split', function () {
    $games = ChessGame::factory()->count(3)->finished()->create();
    app()->instance(Sitemap::class, new Sitemap(perFile: 2));

    expect(sitemapLocs($this->get('/sitemap.xml')->getContent()))->toContain(route('sitemap.section', ['games', 1]), route('sitemap.section', ['games', 2]))
        ->and(sitemapLocs($this->get(route('sitemap.section', ['games', 2]))->assertOk()->getContent()))->toBe([route('games.show', $games[2]), route('games.show', $games[2]).'?lang=de']);

    $this->get(route('sitemap.section', ['games', 3]))->assertNotFound();
});

test('the fixed pages come in both languages with their alternates', function () {
    $xml = $this->get(route('sitemap.section', ['pages', 1]))->assertOk()->getContent();
    $locs = sitemapLocs($xml);

    expect($locs)->toContain(route('home').'/', route('home').'/?lang=de', route('clans.index'), route('clans.index').'?lang=de',
        route('matches.index'), route('chess.lobby'), route('games.rocket-league'), route('mining'),
        route('ladder.show', ['chess', 'blitz']), route('ladder.show', ['rocket-league', '3v3']))
        ->and($xml)->toContain('<xhtml:link rel="alternate" hreflang="x-default" href="'.route('clans.index').'"/>');
});

test('players, clans, played series and finished chess games are listed with lastmod', function () {
    $player = User::factory()->create(['updated_at' => '2026-09-20 12:00:00']);
    $clan = Clan::factory()->create();
    $played = SeriesMatch::factory()->accepted()->create(['status' => SeriesStatus::Confirmed]);
    $open = SeriesMatch::factory()->create(['status' => SeriesStatus::Open]);
    $withdrawn = SeriesMatch::factory()->create(['status' => SeriesStatus::Withdrawn]);
    $finished = ChessGame::factory()->finished()->create();
    $live = ChessGame::factory()->create(['status' => ChessGameStatus::Active]);
    $aborted = ChessGame::factory()->create(['status' => ChessGameStatus::Aborted]);

    $players = $this->get(route('sitemap.section', ['players', 1]))->assertOk()->getContent();
    $matches = sitemapLocs($this->get(route('sitemap.section', ['matches', 1]))->assertOk()->getContent());
    $games = sitemapLocs($this->get(route('sitemap.section', ['games', 1]))->assertOk()->getContent());

    expect(sitemapLocs($players))->toContain(route('players.show', $player->npub), route('players.show', $player->npub).'?lang=de')
        ->and($players)->toContain('<lastmod>2026-09-20T12:00:00+00:00</lastmod>')
        ->and(sitemapLocs($this->get(route('sitemap.section', ['clans', 1]))->getContent()))->toContain(route('clans.show', $clan))
        ->and($matches)->toBe([route('matches.show', $played->number), route('matches.show', $played->number).'?lang=de'])
        ->and($matches)->not->toContain(route('matches.show', $open->number), route('matches.show', $withdrawn->number))
        ->and($games)->toBe([route('games.show', $finished), route('games.show', $finished).'?lang=de'])
        ->and($games)->not->toContain(route('games.show', $live), route('games.show', $aborted));
});

test('no private page is ever listed', function () {
    User::factory()->create();
    Clan::factory()->create();
    SeriesMatch::factory()->accepted()->create();

    $all = [];

    foreach (sitemapLocs($this->get('/sitemap.xml')->getContent()) as $file) {
        array_push($all, ...sitemapLocs($this->get($file)->assertOk()->getContent()));
    }

    expect($all)->not->toBeEmpty();

    foreach ($all as $url) {
        expect(parse_url($url, PHP_URL_PATH) ?? '/')->not->toMatch('#^/(admin|settings|__test|invites|i/|me(/|$)|login|clans/create|challenges|chess/challenge|locale|styleguide)|/(manage|room|card)$#');
    }
});

test('a file past the end, an unknown section and page zero are 404', function () {
    $this->get(route('sitemap.section', ['players', 1]))->assertNotFound();
    $this->get(route('sitemap.section', ['pages', 2]))->assertNotFound();
    $this->get('/sitemaps/users-1.xml')->assertNotFound();
    $this->get('/sitemaps/pages-0.xml')->assertNotFound();
});

test('robots.txt keeps crawlers out of private areas and points to the sitemap', function () {
    $response = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect($response->getContent())->toBe(implode("\n", [
        'User-agent: *',
        'Disallow: /admin',
        'Disallow: /settings',
        'Disallow: /__test',
        'Disallow: /invites/',
        'Disallow: /me$',
        'Disallow: /me/',
        'Disallow: /clans/create',
        'Disallow: /clans/*/manage',
        'Disallow: /challenges/',
        'Disallow: /chess/challenge',
        'Disallow: /matches/*/room',
        'Disallow: /players/*/card',
        'Disallow: /locale/',
        'Disallow: /styleguide',
        '',
        'Sitemap: '.route('sitemap'),
    ])."\n")
        ->and(RobotsController::DISALLOW)->not->toContain('/i/');
});

test('robots.txt and the sitemap set no cookie', function () {
    expect($this->get('/robots.txt')->headers->getCookies())->toBe([])
        ->and($this->get('/sitemap.xml')->headers->getCookies())->toBe([]);
});
