<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Blaze\Config;

/*
|--------------------------------------------------------------------------
| Blaze compiles the anonymous components (performance plan P4)
|--------------------------------------------------------------------------
|
| The nine hottest components carry @blaze, the rest of
| resources/views/components is compiled by AppServiceProvider::configureBlaze();
| the shell and the components that mount a Livewire child stay on Blade.
|
*/

test('the hot components carry @blaze, and only the icon memoizes', function (string $name, string $directive) {
    expect(strtok(file_get_contents(resource_path("views/components/{$name}.blade.php")), "\n"))->toBe($directive);
})->with([
    ['icon', '@blaze(memo: true)'],
    ['button', '@blaze'],
    ['member-badge', '@blaze'],
    ['rank-badge', '@blaze'],
    ['rating', '@blaze'],
    ['clan-tag', '@blaze'],
    ['game-cover', '@blaze'],
    ['avatar', '@blaze'],
    ['player-link', '@blaze'],
]);

test('the components directory compiles with Blaze, except the shell and the components with a Livewire child', function () {
    $config = app(Config::class);
    $path = fn (string $name): string => resource_path("views/components/{$name}.blade.php");

    expect($config->shouldCompile($path('empty-state')))->toBeTrue()
        ->and($config->shouldCompile($path('tournaments/next-empty')))->toBeTrue()
        ->and($config->shouldCompile($path('shell/header')))->toBeFalse()
        ->and($config->shouldCompile($path('shell/footer')))->toBeFalse()
        ->and($config->shouldCompile($path('nostr-login')))->toBeTrue()
        ->and($config->shouldCompile($path('upcoming/row')))->toBeFalse()
        ->and($config->shouldCompile($path('upcoming/when')))->toBeFalse()
        ->and($config->shouldCompile($path('opponents/needs-mutual')))->toBeFalse()
        ->and($config->shouldFold($path('icon')))->toBeFalse();
});

test('an escaped Alpine binding reaches the icon as :class, so the accordion chevron turns', function () {
    $html = Blade::render('<x-doc-section id="one" title="One">Body</x-doc-section>');

    expect($html)->toContain(':class="open ? \'rotate-180\' : \'\'"')
        ->not->toContain('::class=');
});

test('a memoized icon keeps its own attributes', function () {
    $html = Blade::render('<x-icon name="check" class="a" /><x-icon name="check" class="b" /><x-icon name="check" class="a" />');

    expect(substr_count($html, 'class="shrink-0 a"'))->toBe(2)
        ->and(substr_count($html, 'class="shrink-0 b"'))->toBe(1);
});

test('a component file used as a plain view (@include, view()) is left to Blade, or Blaze would print nothing', function () {
    // Every @include/view() of a file under components/ must be in configureBlaze()'s compile: false list.
    $used = [];
    foreach ([resource_path('views'), app_path(), base_path('routes')] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')
                && preg_match_all("/(?:@include(?:If|When|First)?|view|@each)\\(\\s*'components\\.([a-z0-9._-]+)'/", (string) file_get_contents($file->getPathname()), $found)) {
                array_push($used, ...$found[1]);
            }
        }
    }

    expect(array_values(array_unique($used)))->toBe(['game-channel-poll'])
        ->and(trim(Blade::render("@include('components.game-channel-poll')")))->not->toBe('');
});
