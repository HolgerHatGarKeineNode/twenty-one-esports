<?php

use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Support\Clans\ClanLogos;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| <x-clan-tag>: the clan's logo in front of its tag, local logos only
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake('public');
});

/** A logo as the manage page stores it: `clan-logos/<sha256>.png` on the public disk. */
function localClanLogo(): string
{
    $png = 'png-bytes-'.fake()->uuid();
    $logos = app(ClanLogos::class);
    $logos->store($png);

    return $logos->urlFor($png);
}

test('a clan with an uploaded logo shows the logo in front of the tag', function () {
    $clan = Clan::factory()->create(['clantag' => 'LSR', 'picture' => localClanLogo()]);

    $html = Blade::render('<x-clan-tag :clan="$clan" size="sm" />', ['clan' => $clan]);

    expect($html)->toContain('<img src="'.$clan->picture.'" alt="" width="20" height="20" loading="lazy"')
        ->and($html)->toContain('>LSR</span>')
        ->and(strpos($html, '<img'))->toBeLessThan(strpos($html, 'LSR'));
});

test('a clan without a logo shows only the tag chip', function () {
    $clan = Clan::factory()->create(['clantag' => 'LSR', 'picture' => null]);

    $html = Blade::render('<x-clan-tag :clan="$clan" />', ['clan' => $clan]);

    expect($html)->not->toContain('<img')
        ->and(trim($html))->toBe('<span class="inline-flex shrink-0 items-center justify-center rounded-sm bg-btc-tint font-bold text-btc h-6 min-w-9 px-1 text-[11px]">LSR</span>');
});

test('a foreign logo URL is never requested: only the tag chip renders', function (string $picture) {
    $clan = Clan::factory()->create(['clantag' => 'LSR', 'picture' => $picture]);

    $chip = Blade::render('<x-clan-tag :clan="$clan" />', ['clan' => $clan]);
    $tile = Blade::render('<x-clan-tag :clan="$clan" :tile="40" />', ['clan' => $clan]);

    expect($chip)->not->toContain('<img')->and($chip)->toContain('>LSR</span>')
        ->and($tile)->not->toContain('<img')->and($tile)->toContain('LSR')
        ->and($chip.$tile)->not->toContain($picture);
})->with([
    'portal logo' => 'https://portal.einundzwanzig.space/storage/meetups/kempten.png',
    'our directory on a foreign host' => 'https://evil.example/storage/clan-logos/'.str_repeat('a', 64).'.png',
    'our prefix with a path trick' => 'http://localhost/storage/clan-logos/../../secret.png',
]);

test('a logo tile keeps the tag for screen readers', function () {
    $clan = Clan::factory()->create(['clantag' => 'LSR', 'picture' => localClanLogo()]);

    $html = Blade::render('<x-clan-tag :clan="$clan" :tile="44" class="size-11" />', ['clan' => $clan]);

    expect($html)->toContain('width="44" height="44" loading="lazy"')
        ->and($html)->toContain('<span class="sr-only">LSR</span>');
});

test('the match list reads the clans of its rows in one query, however many rows', function () {
    $clanQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get(route('matches.index'))->assertOk();
        DB::disableQueryLog();

        return count(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql) => str_contains($sql, 'from "clans" where "clans"."id"')));
    };

    SeriesMatch::factory()->accepted()->create();
    $few = $clanQueries();

    SeriesMatch::factory()->accepted()->count(4)->create();

    expect($few)->toBe(2)
        ->and($clanQueries())->toBe($few);
});
