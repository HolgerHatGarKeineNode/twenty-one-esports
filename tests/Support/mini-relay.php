<?php

/*
 * Starts Tests\Support\MiniRelay on the given port until killed:
 *   php tests/Support/mini-relay.php 7799
 * Used by tests/Browser/ChatAndDailyTest.php; never part of the app.
 */

require __DIR__.'/../../vendor/autoload.php';

use React\EventLoop\Loop;
use Tests\Support\MiniRelay;

(new MiniRelay)->run((int) ($argv[1] ?? 7799));

Loop::run();
