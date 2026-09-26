<?php

namespace App\Support\SeasonChain;

use RuntimeException;

/**
 * A season-chain admin action the league refuses (release of Block 0, a
 * parameter change). The message is translated and shown to the admin.
 */
final class SeasonReleaseRefused extends RuntimeException {}
