<?php

namespace App\Support\Tmnf;

/**
 * The server could not be reached, closed the connection or did not answer in
 * time. The listener reconnects after a backoff (TmnfListener).
 */
final class GbxUnavailable extends GbxException {}
