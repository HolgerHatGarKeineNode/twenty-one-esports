<?php

/*
| The slowest tests and files of a run, from the timings tests/Support/TestProfile.php wrote.
|
|   TEST_PROFILE_FILE=/tmp/profile.jsonl vendor/bin/pest --parallel
|   php scripts/test-profile.php /tmp/profile.jsonl [top=30]
|
| A test's seconds are the gap since the previous test of the same worker ended, so
| setUp and tearDown are in. The first test of a worker has no predecessor and counts
| as 0. The last block reports when each worker finished: one worker far behind the
| rest is a file that should be split (the runner hands out whole files).
*/

declare(strict_types=1);

$path = $argv[1] ?? null;
$top = (int) ($argv[2] ?? 30);

if ($path === null || ! is_file($path)) {
    fwrite(STDERR, "usage: php scripts/test-profile.php <profile.jsonl> [top]\n");
    exit(2);
}

$byWorker = [];

foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $row = json_decode($line, true);

    if (is_array($row)) {
        $byWorker[$row['p']][] = $row;
    }
}

$tests = [];
$files = [];
$first = INF;
$lastByWorker = [];

foreach ($byWorker as $pid => $rows) {
    usort($rows, fn (array $a, array $b): int => $a['e'] <=> $b['e']);
    $previous = null;

    foreach ($rows as $row) {
        $seconds = $previous === null ? 0.0 : $row['e'] - $previous;
        $previous = $row['e'];
        $first = min($first, $row['e']);
        $name = str_replace('__pest_evaluable_', '', $row['n']);
        $class = str_replace('P\\Tests\\', '', $row['f']);
        $tests[] = [$seconds, $class.'::'.substr($name, 0, 80)];
        $files[$class] = ($files[$class] ?? 0.0) + $seconds;
        $lastByWorker[$pid] = $row['e'];
    }
}

usort($tests, fn (array $a, array $b): int => $b[0] <=> $a[0]);
arsort($files);

printf("%d tests in %d workers, %.0f test-seconds\n\n", count($tests), count($byWorker), array_sum($files));
echo "slowest tests\n";

foreach (array_slice($tests, 0, $top) as [$seconds, $name]) {
    printf("%7.2f  %s\n", $seconds, $name);
}

echo "\nslowest files\n";

foreach (array_slice($files, 0, $top, true) as $class => $seconds) {
    printf("%7.1f  %s\n", $seconds, $class);
}

echo "\nworkers finished after (s from the first test)\n";
$finished = array_map(fn (float $end): int => (int) round($end - $first), $lastByWorker);
sort($finished);
echo implode(' ', $finished)."\n";
