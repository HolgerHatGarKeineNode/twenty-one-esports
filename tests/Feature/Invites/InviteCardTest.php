<?php

use App\Enums\InviteLinkType;
use App\Models\Clan;
use App\Models\InviteLink;
use App\Models\User;
use App\Support\Clans\ClanLogos;
use App\Support\Invites\InviteLinks;
use App\Support\Nostr\Blockpile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
});

test('the preview card is a PNG in both sizes, for a crawler without a session', function (string $format, int $width, int $height) {
    $link = app(InviteLinks::class)->create(User::factory()->create(['name' => 'satsjäger']), InviteLinkType::Blitz);

    $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Signal link preview)'])
        ->get(route('invites.card', ['code' => $link->code, 'format' => $format]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertHeaderMissing('Set-Cookie');

    $size = getimagesizefromstring($response->getContent());
    expect([$size[0], $size[1], $size['mime']])->toBe([$width, $height, 'image/png']);
})->with([
    'wide, og:image' => ['wide', 1200, 630],
    'square, for messengers' => ['square', 1080, 1080],
]);

test('a card is drawn once and served from the cache until what it shows changes', function () {
    $anna = User::factory()->create(['name' => 'satsjäger']);
    $link = app(InviteLinks::class)->create($anna, InviteLinkType::Daily);
    $url = route('invites.card', ['code' => $link->code, 'format' => 'wide']);

    $first = $this->get($url)->getContent();
    $cached = Storage::disk('local')->files('invite-cards');
    $this->get($url);

    expect($cached)->toHaveCount(1)
        ->and(Storage::disk('local')->files('invite-cards'))->toBe($cached);

    $anna->forceFill(['name' => 'satoshi'])->save();
    $second = $this->get($url)->getContent();

    expect($second)->not->toBe($first)
        ->and(Storage::disk('local')->files('invite-cards'))->toHaveCount(1)
        ->and(Storage::disk('local')->files('invite-cards'))->not->toBe($cached);
});

test('every link type renders, the Rocket League and clan cards included', function (InviteLink $link) {
    $this->get(route('invites.card', ['code' => $link->code, 'format' => 'wide']))->assertOk()->assertHeader('Content-Type', 'image/png');
})->with([
    'clan' => fn () => InviteLink::factory()->clan(Clan::factory()->create(['name' => 'Laser Eyes 🚀 mit sehr langem Namen']))->create(),
    'series' => fn () => InviteLink::factory()->create(['type' => InviteLinkType::Series, 'options' => ['lineup_id' => 0, 'mode' => '3v3', 'best_of' => 3]]),
]);

test('an unknown code has no card', function () {
    $this->get(route('invites.card', ['code' => str_repeat('A', 22), 'format' => 'wide']))->assertNotFound();
});

test('the raster Blockpile draws exactly the shapes of the SVG avatar', function () {
    $pubkey = bin2hex(random_bytes(32));
    preg_match_all('/<(?:rect|path) ([^>]*)\/>/', Blockpile::svg($pubkey), $elements);

    $fromSvg = array_map(function (string $attributes): array {
        preg_match('/fill="([^"]+)"/', $attributes, $fill);

        if (! preg_match('/ d="([^"]+)"/', ' '.$attributes, $d)) {
            return ['points' => [0, 0, 96, 0, 96, 96, 0, 96], 'fill' => $fill[1]];
        }

        // Replay the path: M x y, then relative l/v steps, z.
        preg_match_all('/([MlLvz])([^MlLvz]*)/', $d[1], $steps, PREG_SET_ORDER);
        $points = [];
        [$x, $y] = [0, 0];

        foreach ($steps as [, $command, $args]) {
            $numbers = array_map('intval', preg_split('/[\s,]+|(?=-)/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if ($command === 'M') {
                [$x, $y] = $numbers;
                $points[] = [$x, $y];
            } elseif ($command === 'l') {
                foreach (array_chunk($numbers, 2) as [$dx, $dy]) {
                    [$x, $y] = [$x + $dx, $y + $dy];
                    $points[] = [$x, $y];
                }
            } elseif ($command === 'v') {
                $y += $numbers[0];
                $points[] = [$x, $y];
            }
        }

        return ['points' => array_merge(...$points), 'fill' => $fill[1]];
    }, $elements[1]);

    expect(Blockpile::polygons($pubkey))->toBe($fromSvg);
});

test('a clan card shows the clan logo, and a logo uploaded later redraws the card', function () {
    Storage::fake('public');
    $clan = Clan::factory()->create(['name' => 'Super Toxic', 'clantag' => 'ST', 'picture' => null]);
    $link = InviteLink::factory()->clan($clan)->create();
    $url = route('invites.card', ['code' => $link->code, 'format' => 'wide']);

    $before = $this->get($url)->assertOk()->getContent();

    // A pure green logo, stored the way the clan edit stores it.
    $image = imagecreatetruecolor(512, 512);
    imagefill($image, 0, 0, imagecolorallocate($image, 0, 200, 0));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    $logos = app(ClanLogos::class);
    $logos->store($png);
    $clan->forceFill(['picture' => $logos->urlFor($png)])->save();

    $after = $this->get($url)->assertOk()->getContent();

    expect($after)->not->toBe($before);

    // Somewhere on the card there is now the logo's green, which no card had before.
    $green = function (string $bytes): int {
        $card = imagecreatefromstring($bytes);
        $hits = 0;
        for ($x = 0; $x < imagesx($card); $x += 6) {
            for ($y = 0; $y < imagesy($card); $y += 6) {
                ['red' => $r, 'green' => $g, 'blue' => $b] = imagecolorsforindex($card, imagecolorat($card, $x, $y));
                $hits += ($g > 170 && $r < 40 && $b < 40) ? 1 : 0;
            }
        }

        return $hits;
    };

    expect($green($before))->toBe(0)
        ->and($green($after))->toBeGreaterThan(20);
});

test('a clan card never fetches a remote picture: a portal logo keeps the tag tile', function () {
    Storage::fake('public');
    Http::preventStrayRequests();
    $clan = Clan::factory()->create(['clantag' => 'LSR', 'picture' => 'https://portal.example/logo.png']);
    $link = InviteLink::factory()->clan($clan)->create();

    $this->get(route('invites.card', ['code' => $link->code, 'format' => 'square']))->assertOk()->assertHeader('Content-Type', 'image/png');
});

test('the preview card URL changes with what the card shows, so messengers fetch a new logo', function () {
    Storage::fake('public');
    $clan = Clan::factory()->create(['picture' => null]);
    $link = InviteLink::factory()->clan($clan)->create();
    $cardUrl = fn (): string => collect(Str::matchAll('/property="og:image" content="([^"]+)"/', $this->get($link->url())->getContent()))->first();

    $before = $cardUrl();
    $clan->forceFill(['picture' => url('/storage/clan-logos/'.str_repeat('a', 64).'.png')])->save();

    expect($before)->toContain('v=')->and($cardUrl())->not->toBe($before);
});
