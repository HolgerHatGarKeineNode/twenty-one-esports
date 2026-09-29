<?php

namespace App\Support\Lightning;

use RuntimeException;

/**
 * A zap of a winner that cannot go ahead (P47, {@see WinnerZaps}); the
 * message is for the player.
 */
final class ZapRefused extends RuntimeException {}
