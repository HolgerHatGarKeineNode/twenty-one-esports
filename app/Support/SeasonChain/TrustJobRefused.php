<?php

namespace App\Support\SeasonChain;

use RuntimeException;

/**
 * The trust job did not run (a key or the member list is missing). The
 * message names what is missing, never a secret; the last ranks stay.
 */
final class TrustJobRefused extends RuntimeException {}
