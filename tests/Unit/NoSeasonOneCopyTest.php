<?php

/*
 * The first chain season is the Pre-Season, and rated play starts at Block 0.
 * "Season 1" names a season that does not exist, so no string a player can
 * see may say it: Blade templates (outside {{-- --}} comments), PHP string
 * literals in app/ and the page components (outside PHP comments), the
 * front-end JS and every lang/*.json key and value.
 */

const SEASON_ONE = '/\bSeason 1\b/i';

/**
 * Lines of one source file that carry "Season 1" in something other than a
 * comment.
 *
 * @return list<string>
 */
function seasonOneHits(string $path, string $source): array
{
    $hits = [];

    if (str_ends_with($path, '.json')) {
        foreach (json_decode($source, true, flags: JSON_THROW_ON_ERROR) as $key => $value) {
            if (preg_match(SEASON_ONE, $key.' '.$value) === 1) {
                $hits[] = "{$path}: \"{$key}\"";
            }
        }

        return $hits;
    }

    if (str_ends_with($path, '.js')) {
        $source = preg_replace(['#/\*.*?\*/#s', '#(^|[^:])//[^\n]*#'], ['', '$1'], $source);
    } else {
        // PHP and Blade: drop PHP comments token by token, then Blade comments.
        $kept = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $kept .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        $source = preg_replace_callback('/\{\{--.*?--\}\}/s', fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $kept);
    }

    foreach (explode("\n", $source) as $number => $line) {
        if (preg_match(SEASON_ONE, $line) === 1) {
            $hits[] = "{$path}:".($number + 1).': '.trim($line);
        }
    }

    return $hits;
}

/**
 * @return list<string>
 */
function userFacingSources(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['app', 'resources/views', 'resources/js'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (preg_match('/\.(php|js)$/', $file->getFilename()) === 1) {
                $files[] = $file->getPathname();
            }
        }
    }

    return [...$files, ...glob("{$root}/lang/*.json")];
}

test('no user-facing string says "Season 1"', function () {
    $files = userFacingSources();
    $hits = [];

    foreach ($files as $path) {
        array_push($hits, ...seasonOneHits($path, file_get_contents($path)));
    }

    expect(count($files))->toBeGreaterThan(50)
        ->and($hits)->toBe([]);
});

test('the scan flags "Season 1" in copy and skips it in comments', function (string $path, string $source, int $expected) {
    expect(seasonOneHits($path, $source))->toHaveCount($expected);
})->with([
    'blade copy' => ['x.blade.php', "<span>{{ __('from Season 1') }}</span>", 1],
    'blade comment' => ['x.blade.php', "{{-- rated opens with Season 1 --}}\n<span>ok</span>", 0],
    'php literal' => ['x.php', "<?php\n\$label = 'Season 1';", 1],
    'php comment' => ['x.php', "<?php\n// Season 1 thresholds\n/** Season 1 */\n\$x = 1;", 0],
    'js literal' => ['x.js', "const t = 'Rated games start with Season 1.';", 1],
    'js comment' => ['x.js', "// Season 1\nconst url = 'https://example.org';", 0],
    'json key' => ['de.json', '{"Season 1": "Saison"}', 1],
    'json value' => ['de.json', '{"Pre-Season": "Season 1"}', 1],
]);
