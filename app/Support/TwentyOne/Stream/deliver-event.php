<?php

/**
 * Deliver one signed Nostr event. Stdin is the JSON job, stdout one JSON line.
 *
 * No Laravel boot and no session file: the stream supervisor starts this so a
 * hung relay cannot stall the encoder. The parent reaps the line.
 */

use App\Support\TwentyOne\Stream\DetachedPublish;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$raw = stream_get_contents(STDIN);

echo DetachedPublish::deliver(is_string($raw) ? $raw : '');
