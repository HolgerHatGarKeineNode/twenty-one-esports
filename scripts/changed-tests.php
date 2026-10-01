<?php

/*
|--------------------------------------------------------------------------
| Which test files does a diff reach?  (the path map behind the push gate)
|--------------------------------------------------------------------------
|
|   php scripts/changed-tests.php --suite=default|browser [--base REF] [--head REF] [--explain]
|   php scripts/changed-tests.php --suite=browser --files=<file with one path per line | ->
|
| Prints `ALL` (run the whole suite) or one test file per line (possibly none).
| Used by scripts/test-changed.sh (default suite) and
| `scripts/test-browser.sh --changed` (Browser suite). Stateless on purpose: a
| fresh worktree has no Pest TIA graph, and recording one is a full coverage
| run. This is a static map and therefore a heuristic: the scheduled full runs
| (see scripts/test-changed.sh) are the net under it. --explain says on stderr
| why each test was picked.
|
| How a changed file reaches tests
| - a changed test file runs itself;
| - an app/ class, factory, seeder or tests/Support class is found in tests by
|   its short class name, tests/Support function files by their function names,
|   other tests/ files (fixtures) by their file name;
| - narrow by default: a changed file selects the tests that name it (class, view,
|   component tag) or quote what its diff changed (a data-test value, a string, a
|   method name, an element's text). Only in the default suite a file that no
|   test names that way is also followed UP through the views, routes and
|   classes that use it, as `--wide` does for every file (and for the browser
|   suite). The browser suite does not widen on its own: a view that no
|   browser test names selects no browser file (the daily full run, route
|   sweep included, is the net for that).
| - the chain, a Blade view is followed UP through the views that include it (@include,
|   <x-component>, <livewire:...>, view('...')) to the pages, and from a page
|   to the routes that serve it: the tests that name the page, or the route's
|   name or URI, are picked. A view's referrers in app/ are leaves (their own
|   tests, not their users'). An app class is followed up through at most two
|   hops of classes that use it, and through every view that uses it;
| - a changed route line is read from the diff (its name, URI, class, page);
|   a changed lang key is searched, as written, in views and app/;
| - global: config/, migrations, composer files, phpunit.xml, tests/Pest.php,
|   the base TestCase, a tests/Support function file without any name in it,
|   and, for the browser suite, package files, vite config and CSS. They run
|   everything, and so does a chain that grows past MAX_REACH files (a layout,
|   a base model: too wide to select from).
| - docs, scripts, dot-directories and the like are ignored; a changed source
|   file that no test and no route reaches selects nothing (--explain says so).
*/

declare(strict_types=1);

const MAX_REACH = 160;
const APP_HOPS = 2;

$options = getopt('', ['suite:', 'base:', 'head:', 'files:', 'explain', 'wide', 'root:']);

// --root: another tree to map (tests/Unit/ChangedTestsMapTest.php builds a small one).
$root = isset($options['root']) && is_string($options['root']) ? $options['root'] : dirname(__DIR__);
chdir($root);
$suite = $options['suite'] ?? 'default';
$explain = array_key_exists('explain', $options);
$wide = array_key_exists('wide', $options);

function fail(string $message): never
{
    fwrite(STDERR, "changed-tests: {$message}\n");
    exit(2);
}

$suiteDirs = match ($suite) {
    'default' => ['tests/Feature', 'tests/Unit', 'tests/Nostr'],
    'browser' => ['tests/Browser'],
    default => fail("unknown --suite={$suite} (default|browser)"),
};

function note(string $message): void
{
    global $explain;

    if ($explain) {
        fwrite(STDERR, "changed-tests: {$message}\n");
    }
}

/** @return list<string> */
function git(string ...$arguments): array
{
    exec('git '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>/dev/null', $lines, $code);

    return $code === 0 ? array_values(array_filter($lines, fn (string $line): bool => $line !== '')) : [];
}

// --- the changed files ---------------------------------------------------------

/** @var array<string, list<string>>|null $diffLines the changed lines of routes/ and lang/ files, when the diff is at hand */
$diffLines = null;

if (isset($options['files']) && is_string($options['files'])) {
    $list = $options['files'] === '-' ? stream_get_contents(STDIN) : file_get_contents($options['files']);
    $changed = array_values(array_filter(array_map('trim', explode("\n", (string) $list))));
} else {
    $base = $options['base'] ?? (git('rev-parse', '--verify', '-q', 'origin/master') !== [] ? 'origin/master' : 'master');
    // --head names another commit to look at (a past commit as a sample diff): then the working tree is not part of it.
    $head = $options['head'] ?? 'HEAD';
    $workingTree = $head === 'HEAD';
    $changed = array_values(array_unique([
        ...git('diff', '--name-only', "{$base}...{$head}"),
        ...($workingTree ? [...git('diff', '--name-only', 'HEAD'), ...git('ls-files', '--others', '--exclude-standard')] : []),
    ]));
    $diffLines = [];

    foreach ($changed as $file) {
        if (str_starts_with($file, 'routes/') || str_starts_with($file, 'lang/')) {
            $diffLines[$file] = array_values(array_filter(
                [...git('diff', '-U0', "{$base}...{$head}", '--', $file), ...($workingTree ? git('diff', '-U0', 'HEAD', '--', $file) : [])],
                fn (string $line): bool => preg_match('/^[+-](?![+-])/', $line) === 1,
            ));
        }
    }
}

if ($changed === []) {
    exit(0);
}

// --- the files they could reach ------------------------------------------------

/** @return list<string> */
function files(string $dir, string $suffix): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getPathname(), $suffix)) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

/** @var array<string, string> $tests */
$tests = [];

foreach ($suiteDirs as $dir) {
    foreach (files($dir, 'Test.php') as $path) {
        $tests[$path] = (string) file_get_contents($path);
    }
}

/** @var array<string, string> $sources files that can refer to a view, a class or a route */
$sources = [];

foreach ([...files('app', '.php'), ...files('resources/views', '.blade.php'), ...files('routes', '.php')] as $path) {
    $sources[$path] = (string) file_get_contents($path);
}

function mentions(string $haystack, string $needle): bool
{
    return $needle !== '' && preg_match('/(?<![A-Za-z0-9_])'.preg_quote($needle, '/').'(?![A-Za-z0-9_])/', $haystack) === 1;
}

/**
 * The strings by which other files refer to $path.
 *
 * @return list<string>
 */
function refsOf(string $path): array
{
    if (preg_match('#^resources/views/(.+)\.blade\.php$#', $path, $m) === 1) {
        $dotted = str_replace('/', '.', str_replace('⚡', '', $m[1]));
        $refs = [$dotted];

        if (str_starts_with($dotted, 'pages.')) {
            $refs[] = 'pages::'.substr($dotted, strlen('pages.'));
        }

        if (str_starts_with($dotted, 'components.')) {
            $tag = substr($dotted, strlen('components.'));
            array_push($refs, 'x-'.$tag, 'livewire:'.$tag, $tag);
        }

        return $refs;
    }

    if (str_ends_with($path, '.php')) {
        return [basename($path, '.php')];
    }

    return [basename($path), preg_replace('/\.[^.]+$/', '', basename($path))];
}

/**
 * The name and the static URI prefix of every route statement of routes/*.php that mentions $ref.
 *
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function routeRefsOf(array $sources, string $ref): array
{
    $out = [];

    foreach ($sources as $path => $text) {
        if (! str_starts_with($path, 'routes/') || ! mentions($text, $ref)) {
            continue;
        }

        foreach (preg_split('/;\s*\n/', $text) ?: [] as $statement) {
            if (mentions($statement, $ref)) {
                array_push($out, ...routeTokens($statement));
            }
        }
    }

    return $out;
}

/**
 * Route names and the static start of a URI in a piece of routes/ code. A name is a quoted
 * string in a test (`route('x')`), a URI is a path (`visit('/x')`).
 *
 * @return list<string>
 */
function routeTokens(string $code): array
{
    $out = [];

    if (preg_match_all("/->name\\(\\s*['\"]([^'\"]+)['\"]/", $code, $names) > 0) {
        foreach ($names[1] as $name) {
            array_push($out, "route('{$name}'", "route(\"{$name}\"", "routeIs('{$name}'");
        }
    }

    if (preg_match("/Route::\\w+\\(\\s*['\"]([^'\"{]*)/", $code, $uri) === 1 && trim($uri[1], '/') !== '') {
        $out[] = '/'.trim($uri[1], '/');
    }

    return $out;
}

// --- what each changed file selects --------------------------------------------

$selected = [];
$all = false;

function pick(string $test, string $because): void
{
    global $selected;

    $selected[$test][] = $because;
}

function testsNaming(string $token, string $because, bool $word = true): int
{
    global $tests;

    $count = 0;

    foreach ($tests as $test => $body) {
        if ($word ? mentions($body, $token) : str_contains($body, $token)) {
            pick($test, $because);
            $count++;
        }
    }

    return $count;
}

function everything(string $because): void
{
    global $all;

    note($because.', runs everything');
    $all = true;
}

$ignore = '#^(docs/|scripts/|\.[a-z]|storage/|LICENSE|THIRD-PARTY|README|AGENTS|CLAUDE|opencode\.json|boost\.json|pint\.json|phpstan\.neon|artisan$|tests/js/|tests/Integration/|tests/Relay/|.*\.md$)#i';
$globalDefault = '#^(phpunit\.xml|composer\.(json|lock)|tests/Pest\.php|tests/TestCase\.php|bootstrap/|config/|database/migrations/)#';
$globalBrowser = '#^(package(-lock)?\.json|vite\.config\.js|resources/css/|tailwind)#';

/** @var list<array{0: string, 1: int}> $queue the source files to follow upwards, with how many class hops they may still climb */
$queue = [];
/** @var list<string> $seeds */
$seeds = [];

foreach ($changed as $file) {
    if (preg_match($ignore, $file) === 1) {
        continue;
    }

    if (preg_match($globalDefault, $file) === 1) {
        everything("{$file}: a global file");

        continue;
    }

    if ($suite === 'browser' && preg_match($globalBrowser, $file) === 1) {
        everything("{$file}: shapes every page");

        continue;
    }

    if (str_starts_with($file, 'tests/')) {
        if (str_ends_with($file, 'Test.php')) {
            isset($tests[$file]) && pick($file, 'changed itself');
        } elseif (str_starts_with($file, 'tests/Support/') && str_ends_with($file, '.php')) {
            $text = is_file($file) ? (string) file_get_contents($file) : '';
            preg_match_all('/^(?:final |abstract )?(?:class|trait|interface|enum) (\w+)/m', $text, $classes);
            preg_match_all('/^function (\w+)\(/m', $text, $functions);
            $names = [...$classes[1], ...$functions[1]];

            if ($names === [] && is_file($file)) {
                everything("{$file}: shared setup without a name to look for");
            }

            foreach ($names as $name) {
                testsNaming($name, "{$file} ({$name})");
            }
        } else {
            foreach (refsOf($file) as $ref) {
                testsNaming($ref, $file, word: false);
            }
        }

        continue;
    }

    if (str_starts_with($file, 'routes/')) {
        if ($diffLines === null) {
            everything("{$file}: no diff to read the route from");

            continue;
        }

        foreach ($diffLines[$file] ?? [] as $line) {
            $code = substr($line, 1);

            if (preg_match('#^\s*(//|\*|/\*|\}|\)|\]|$)#', $code) === 1 && routeTokens($code) === []) {
                continue;
            }

            $tokens = routeTokens($code);

            foreach ($tokens as $token) {
                testsNaming($token, "{$file}: {$token}", word: false);
            }

            if (preg_match_all('/\b([A-Z]\w+)::class|[\'"](pages::[\w.\-]+)[\'"]/', $code, $targets, PREG_SET_ORDER) > 0) {
                foreach ($targets as $target) {
                    $name = ($target[1] ?? '') !== '' ? $target[1] : $target[2];
                    $tokens[] = $name;
                    testsNaming($name, "{$file}: {$name}");
                }
            }

            if ($tokens === []) {
                everything("{$file}: the changed line '".trim($code)."' names no route");
            }
        }

        continue;
    }

    if (str_starts_with($file, 'lang/')) {
        if ($diffLines === null) {
            everything("{$file}: no diff to read the keys from");

            continue;
        }

        foreach ($diffLines[$file] ?? [] as $line) {
            $key = preg_match('/^[+-]\s*"((?:[^"\\\\]|\\\\.)+)"\s*:/', $line, $found) === 1
                || preg_match("/^[+-]\\s*'((?:[^'\\\\]|\\\\.)+)'\\s*=>/", $line, $found) === 1
                ? stripcslashes($found[1])
                : null;

            if ($key === null) {
                continue;
            }

            testsNaming($key, "{$file}: '{$key}'", word: false);

            foreach ($sources as $path => $text) {
                if (! str_starts_with($path, 'routes/') && str_contains($text, $key)) {
                    $queue[] = [$path, 1];
                    $seeds[] = $path;
                }
            }
        }

        continue;
    }

    $queue[] = [$file, str_starts_with($file, 'app/') ? APP_HOPS : 0];
    $seeds[] = $file;
}

/**
 * What the diff of $file added or removed that a test would quote: data-test values, string
 * literals, method and property names, the text of a Blade element. Empty without a diff.
 *
 * @return list<string>
 */
function diffLiterals(string $file): array
{
    global $base, $head, $workingTree;

    if (! isset($base, $head)) {
        return [];
    }

    $lines = [...git('diff', '-U0', "{$base}...{$head}", '--', $file), ...($workingTree ? git('diff', '-U0', 'HEAD', '--', $file) : [])];
    $found = [];

    foreach ($lines as $line) {
        if (preg_match('/^[+-](?![+-])/', $line) !== 1) {
            continue;
        }

        $code = substr($line, 1);

        foreach ([
            '/data-test=["\']([\w\-:.]{6,})["\']/',
            "/'((?:[^'\\\\]|\\\\.){8,})'/",
            '/"((?:[^"\\\\]|\\\\.){8,})"/',
            '/(?:->|::)([a-z]+[A-Z]\w{5,})\b/',
            '/>([^<>{}@$]{8,})</',
        ] as $pattern) {
            if (preg_match_all($pattern, $code, $matches) > 0) {
                array_push($found, ...array_map('stripcslashes', $matches[1]));
            }
        }
    }

    return array_values(array_unique(array_map('trim', $found)));
}

// Narrow first (the default): a changed file selects the tests that name it or quote what its diff
// changed. Only a file that no test names this way is followed upwards through the views, routes
// and classes that use it, as --wide does for every file.
if (! $wide) {
    $widen = [];

    foreach ($queue as [$file, $hops]) {
        $named = 0;

        foreach (refsOf($file) as $ref) {
            $named += testsNaming($ref, "{$file} ({$ref})");
        }

        foreach (diffLiterals($file) as $literal) {
            $count = 0;

            foreach ($tests as $body) {
                $count += str_contains($body, $literal) ? 1 : 0;
            }

            if ($count > 0 && $count <= 25) {
                $named += testsNaming($literal, "{$file}: '{$literal}'", word: false);
            }
        }

        if ($named === 0 && $suite === 'default') {
            note("{$file}: no test names it or what changed in it, followed upwards");
            $widen[] = [$file, $hops];
        } elseif ($named === 0) {
            note("{$file}: no browser test names it or what changed in it (--wide follows it upwards)");
        }
    }

    $queue = $widen;
}

// Breadth-first, upwards: a file -> the files that refer to it -> ...
$visited = [];
$frontier = [];

foreach ($queue as [$file, $hops]) {
    if (! isset($visited[$file])) {
        $visited[$file] = true;
        $frontier[] = [$file, $hops];
    }
}

$routeTokensSeen = [];

while ($frontier !== [] && ! $all) {
    [$file, $hops] = array_shift($frontier);

    foreach (refsOf($file) as $ref) {
        testsNaming($ref, "{$file} ({$ref})");

        foreach (routeRefsOf($sources, $ref) as $token) {
            if (isset($routeTokensSeen[$token])) {
                continue;
            }

            $routeTokensSeen[$token] = true;
            testsNaming($token, "{$file} -> route {$token}", word: false);
        }

        if ($hops < 0) {
            continue; // a leaf: its own tests, not its users'
        }

        $isView = str_starts_with($file, 'resources/views/');

        foreach ($sources as $path => $text) {
            if (isset($visited[$path]) || str_starts_with($path, 'routes/') || ! mentions($text, $ref)) {
                continue;
            }

            $pathIsView = str_starts_with($path, 'resources/views/');

            if ($pathIsView) {
                $nextHops = 0;
            } elseif ($isView) {
                $nextHops = -1;
            } elseif ($hops > 0) {
                $nextHops = $hops - 1;
            } else {
                continue;
            }

            note("  {$path} uses {$file} as '{$ref}'");
            $visited[$path] = true;
            $frontier[] = [$path, $nextHops];

            if (count($visited) > MAX_REACH) {
                everything("{$file}: more than ".MAX_REACH.' files lie above it, too wide to select from');

                break 3;
            }
        }
    }
}

if ($all) {
    echo "ALL\n";

    exit(0);
}

foreach ($seeds as $seed) {
    $reached = false;

    foreach ($selected as $reasons) {
        foreach ($reasons as $reason) {
            $reached = $reached || str_contains($reason, $seed);
        }
    }

    $reached || note("{$seed}: no test of this suite reaches it (neither by name nor by a route)");
}

ksort($selected);

foreach ($selected as $test => $reasons) {
    echo $test."\n";
    note("  {$test} <- ".implode('; ', array_slice(array_unique($reasons), 0, 3)));
}
