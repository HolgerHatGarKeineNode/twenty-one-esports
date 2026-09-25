<?php

/*
 * Starts Tests\Support\MiniRelay on the given port until killed:
 *   php tests/Support/mini-relay.php 7799 [events.json]
 * The optional JSON file (a list of events) is stored before the first client connects.
 * Used by tests/Browser/ChatAndDailyTest.php; never part of the app.
 */

require __DIR__.'/../../vendor/autoload.php';

use React\EventLoop\Loop;
use Tests\Support\MiniRelay;

$seed = isset($argv[2]) ? json_decode((string) file_get_contents($argv[2]), true) : [];

(new MiniRelay)->seed(is_array($seed) ? array_values($seed) : [])->run((int) ($argv[1] ?? 7799));

Loop::run();
