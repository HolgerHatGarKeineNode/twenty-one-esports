<?php

namespace App\Support\TwentyOne\Stream;

use RuntimeException;

/**
 * A picture for the stream that could not be fetched or redrawn; the
 * message is safe for a log line (no query string, no bytes).
 */
class StreamImageFailed extends RuntimeException {}
