<?php

use App\Support\Cards\PageCard;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestSigner;

require_once __DIR__.'/../../Support/seo_routes.php';

/*
|--------------------------------------------------------------------------
| Every public GET route has its own preview (P54 follow-up)
|--------------------------------------------------------------------------
|
| The route table itself is the list: every GET route of the app is either
| crawled with a seeded fixture or skipped with its reason
| (tests/Support/seo_routes.php), so a new public route without a fixture
| fails here instead of shipping with the brand card. Each crawled page,
| in English and German, describes itself and names its own drawn card, not
| the static brand card; titles and descriptions differ from URL to URL.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    seoEverySwitchOn();
});

/**
 * Pages that may keep the static brand card, with the reason. Empty: every
 * public page has a card of its own.
 *
 * @var array<string, string>
 */
const SEO_BRAND_CARD_ALLOWED = [];

test('every GET route is either crawled with a fixture or skipped with its reason', function () {
    $fixtures = seoRouteFixtures();
    $missing = [];
    $public = [];

    foreach (seoGetRoutes() as [$route, $reason]) {
        if ($reason !== null) {
            continue;
        }

        $public[] = (string) $route->getName();

        if (! array_key_exists((string) $route->getName(), $fixtures)) {
            $missing[] = $route->uri().' ('.($route->getName() ?? 'unnamed').')';
        }
    }

    expect($missing)->toBe([])
        // A fixture of a route that is gone or skipped is stale.
        ->and(array_values(array_diff(array_keys($fixtures), $public)))->toBe([]);
});

test('every public page describes itself and names a card of its own, with a title and description no other URL has', function () {
    $this->app['env'] = 'production';
    $failures = [];
    $titles = [];
    $descriptions = [];

    foreach (seoRouteFixtures() as $name => $fixture) {
        foreach ($fixture() as $url) {
            foreach (['en', 'de'] as $locale) {
                $response = $this->get($url.(str_contains($url, '?') ? '&' : '?').'lang='.$locale);

                if ($response->status() !== 200) {
                    $failures[] = "{$locale} {$url}: HTTP {$response->status()}";

                    continue;
                }

                $tags = seoTagsOf((string) $response->getContent());

                if ($tags['og_title'] === '' || $tags['description'] === '') {
                    $failures[] = "{$locale} {$url}: no title or description of its own";
                }

                if ($tags['og_image'] === '' || (seoIsBrandImage($tags['og_image']) && ! array_key_exists($name, SEO_BRAND_CARD_ALLOWED))) {
                    $failures[] = "{$locale} {$url}: og:image is ".($tags['og_image'] === '' ? 'missing' : 'the brand card');
                }

                // A drawn card's URL must lead to a card, not to the 404 of an unknown type or key.
                if (preg_match('#/cards/[a-z]+/page/([a-z]+)/([^/?]+)\.png#', $tags['og_image'], $card) === 1 && PageCard::resolve($card[1], urldecode($card[2])) === null) {
                    $failures[] = "{$locale} {$url}: og:image {$card[1]}/{$card[2]} resolves to no card";
                }

                if ($tags['twitter'] !== 'summary_large_image') {
                    $failures[] = "{$locale} {$url}: twitter:card is '{$tags['twitter']}'";
                }

                $titles[$locale][$tags['title']][] = $url;
                $descriptions[$locale][$tags['description']][] = $url;
            }
        }
    }

    foreach (['title' => $titles, 'description' => $descriptions] as $kind => $byLocale) {
        foreach ($byLocale as $locale => $seen) {
            foreach ($seen as $text => $urls) {
                if (count($urls) > 1) {
                    $failures[] = "{$locale} {$kind} '{$text}' on ".implode(', ', $urls);
                }
            }
        }
    }

    expect($failures)->toBe([]);
});

test('the styleguide is internal: noindex without a preview where it exists, a 404 in production', function () {
    $tags = seoTagsOf((string) $this->get(route('styleguide'))->assertOk()->getContent());

    expect($tags['robots'])->toBe('noindex, nofollow')
        ->and($tags['og_image'])->toBe('')
        ->and($tags['canonical'])->toBe('');

    $this->app['env'] = 'production';
    $this->get(route('styleguide'))->assertNotFound();
});
