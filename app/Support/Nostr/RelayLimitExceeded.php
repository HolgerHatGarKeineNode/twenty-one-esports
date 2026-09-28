<?php

namespace App\Support\Nostr;

use RuntimeException;
use WebSocket\Exception\ConnectionLevelInterface;
use WebSocket\Exception\ExceptionInterface;

/**
 * A relay went past the deadline or the byte budget of one connection
 * ({@see BoundedSocketStream}). One of the websocket library's own exception
 * types, so the library passes it on as it is instead of turning it into a
 * bare "Connection error", and the delivery record says why.
 */
final class RelayLimitExceeded extends RuntimeException implements ConnectionLevelInterface, ExceptionInterface {}
