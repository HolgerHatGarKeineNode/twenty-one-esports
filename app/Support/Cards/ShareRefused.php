<?php

namespace App\Support\Cards;

use RuntimeException;

/**
 * A share post the league will not prepare or take. The message is
 * translated and shown to the player.
 */
final class ShareRefused extends RuntimeException {}
