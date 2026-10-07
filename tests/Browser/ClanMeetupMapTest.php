<?php

use App\Models\Clan;
use App\Models\User;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
| The meetup map on /clans: Darmstadt and Aschaffenburg lie 35 km apart, so their labels met (user,
| 2026-10-07: "kann man Überlappungen fixen durch eine Logik?"). Every label must cover no other dot
| or label and stay inside the map, at 390 and 1280 px, with a quiet console.
*/

const MEETUP_MAP_MEASURE = <<<'JS'
    () => {
        const map = document.querySelector('[data-test=meetup-map]');
        const box = map.getBoundingClientRect();
        const rect = (el) => { const r = el.getBoundingClientRect(); return { x: r.left - box.left, y: r.top - box.top, w: r.width, h: r.height, name: el.innerText.trim() || 'dot' }; };
        const labels = [...map.querySelectorAll('[data-pin-label]')].map(rect);
        const dots = [...map.querySelectorAll('[data-pin-dot]')].map(rect);
        const hit = (a, b) => Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x) > 0.5 && Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y) > 0.5;
        const overlaps = [];
        labels.forEach((a, i) => {
            labels.forEach((b, j) => { if (j > i && hit(a, b)) overlaps.push(a.name + ' x ' + b.name); });
            dots.forEach((d, j) => { if (j !== i && hit(a, d)) overlaps.push(a.name + ' x dot ' + j); });
        });
        const outside = labels.filter((l) => l.x < 0 || l.y < 0 || l.x + l.w > box.width + 0.5 || l.y + l.h > box.height + 0.5).map((l) => l.name);

        return { count: labels.length, placed: map.querySelectorAll('[data-pin-label][data-placed]').length, overlaps, outside };
    }
    JS;

test('the meetup map places every label beside its dot without covering another, at 390 and 1280 px', function () {
    $cities = [['HH21', 'Hamburg', 53.55, 9.99], ['DAR', 'Darmstadt', 49.87, 8.65], ['ASH', 'Aschaffenburg', 49.97, 9.15], ['BMK', 'Kempten', 47.73, 10.31]];
    foreach ($cities as [$tag, $city, $lat, $lon]) {
        Clan::factory()->create(['clantag' => $tag, 'meetup_city' => $city, 'meetup_latitude' => $lat, 'meetup_longitude' => $lon]);
    }
    $viewer = User::factory()->create();

    foreach ([390, 1280] as $width) {
        $page = visit(route('testing.login', ['user' => $viewer, 'to' => '/robots.txt']))->page();
        $page->context()->addInitScript(BrowserConsole::COLLECTOR);
        $page->setViewportSize($width, 900);
        $page->goto(ComputeUrl::from('/clans?lang=de'));
        BrowserWait::until($page, '() => document.querySelectorAll("[data-pin-label][data-placed]").length === 4', 10_000);
        $m = $page->evaluate(MEETUP_MAP_MEASURE);

        expect($m['count'])->toBe(4, "{$width}: labels")
            ->and($m['overlaps'])->toBe([], "{$width}: overlapping labels")
            ->and($m['outside'])->toBe([], "{$width}: labels outside the map")
            ->and($page->evaluate('() => window.__errors'))->toBe([], "{$width}: console")
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "{$width}: responses");
    }
});
