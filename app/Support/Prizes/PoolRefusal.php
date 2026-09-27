<?php

namespace App\Support\Prizes;

use RuntimeException;

/**
 * A zap or invoice the league's endpoint does not take. The message is safe
 * to show and to return as an LNURL `reason`.
 */
final class PoolRefusal extends RuntimeException {}
