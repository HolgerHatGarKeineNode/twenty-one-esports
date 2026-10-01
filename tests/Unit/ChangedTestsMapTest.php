<?php

// scripts/changed-tests.php decides which test files the push gate runs. A map that
// wrongly says "nothing" is a hole in the gate that no run would ever show, so what it
// must pick, and when it must give up and say ALL, is pinned here against a tiny tree.

/**
 * @param  array<string, string>  $files
 */
function changedTestsTree(array $files): string
{
    $root = sys_get_temp_dir().'/changed-tests-'.bin2hex(random_bytes(4));

    foreach ($files as $path => $content) {
        @mkdir(dirname("{$root}/{$path}"), 0777, true);
        file_put_contents("{$root}/{$path}", $content);
    }

    return $root;
}

/**
 * @param  list<string>  $changed
 * @return list<string> the files the map picks, or ['ALL']
 */
function changedTestsPicked(string $root, string $suite, array $changed, string ...$flags): array
{
    $list = $root.'/changed.list';
    file_put_contents($list, implode("\n", $changed)."\n");

    $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, dirname(__DIR__, 2).'/scripts/changed-tests.php', "--suite={$suite}", "--root={$root}", "--files={$list}", ...$flags]));
    exec($command.' 2>/dev/null', $lines, $code);

    expect($code)->toBe(0);

    return array_values(array_filter($lines));
}

beforeEach(function () {
    $this->root = changedTestsTree([
        'app/Support/Clans/ClanRoster.php' => "<?php\nfinal class ClanRoster {}\n",
        'app/Support/Quiet/QuietHours.php' => "<?php\nfinal class QuietHours {}\n",
        'resources/views/components/clans/badge.blade.php' => '<span>badge</span>',
        'resources/views/components/clans/crest.blade.php' => '<span>crest</span>',
        'resources/views/pages/clans/⚡show.blade.php' => '<div><x-clans.crest /></div>',
        'routes/web.php' => "<?php\nRoute::livewire('clans/{clan}', 'pages::clans.show')->name('clans.show');\n",
        'tests/Feature/ClanRosterTest.php' => "<?php\ntest('roster', fn () => ClanRoster::class);\n",
        'tests/Feature/ClanBadgeTest.php' => "<?php\ntest('badge', fn () => view('<x-clans.badge'));\n",
        'tests/Feature/ClanPageTest.php' => "<?php\ntest('page', fn () => \$this->get(route('clans.show', 1)));\n",
        'tests/Feature/OtherTest.php' => "<?php\ntest('other', fn () => 1);\n",
        'tests/Browser/ClanPageTest.php' => "<?php\ntest('browser page', fn () => visit('/clans/1'));\n",
        'tests/Browser/RouteSweepTest.php' => "<?php\ntest('sweep', fn () => 1);\n",
        'tests/Browser/OtherTest.php' => "<?php\ntest('other', fn () => 1);\n",
    ]);
});

it('picks the tests that name a changed class, and a changed test itself', function () {
    expect(changedTestsPicked($this->root, 'default', ['app/Support/Clans/ClanRoster.php']))->toBe(['tests/Feature/ClanRosterTest.php'])
        ->and(changedTestsPicked($this->root, 'default', ['tests/Feature/OtherTest.php']))->toBe(['tests/Feature/OtherTest.php']);
});

it('picks the tests that name a changed component by its tag', function () {
    expect(changedTestsPicked($this->root, 'default', ['resources/views/components/clans/badge.blade.php']))->toBe(['tests/Feature/ClanBadgeTest.php']);
});

it('follows a component nobody names up to the page, its route and the tests that visit it', function () {
    // the default suite widens a file no test names; the browser suite does not, unless asked to
    expect(changedTestsPicked($this->root, 'default', ['resources/views/components/clans/crest.blade.php']))->toBe(['tests/Feature/ClanPageTest.php'])
        ->and(changedTestsPicked($this->root, 'browser', ['resources/views/components/clans/crest.blade.php']))->toBe([])
        ->and(changedTestsPicked($this->root, 'browser', ['resources/views/components/clans/crest.blade.php'], '--wide'))->toBe(['tests/Browser/ClanPageTest.php']);
});

it('runs everything for a file that shapes every test, and nothing for a file no test can reach', function () {
    expect(changedTestsPicked($this->root, 'default', ['config/app.php']))->toBe(['ALL'])
        ->and(changedTestsPicked($this->root, 'default', ['database/migrations/2026_01_01_create_x.php']))->toBe(['ALL'])
        ->and(changedTestsPicked($this->root, 'default', ['tests/Pest.php']))->toBe(['ALL'])
        ->and(changedTestsPicked($this->root, 'browser', ['package.json']))->toBe(['ALL'])
        ->and(changedTestsPicked($this->root, 'browser', ['resources/css/app.css'], '--wide'))->toBe(['ALL'])
        ->and(changedTestsPicked($this->root, 'browser', ['resources/css/app.css']))->toBe([])
        ->and(changedTestsPicked($this->root, 'default', ['docs/plans/x.md', 'scripts/test-browser.sh']))->toBe([])
        ->and(changedTestsPicked($this->root, 'default', ['app/Support/Quiet/QuietHours.php']))->toBe([]);
});

it('does not run a browser file for a change of the default suite, nor the other way round', function () {
    expect(changedTestsPicked($this->root, 'browser', ['tests/Feature/OtherTest.php']))->toBe([])
        ->and(changedTestsPicked($this->root, 'default', ['tests/Browser/OtherTest.php']))->toBe([]);
});
