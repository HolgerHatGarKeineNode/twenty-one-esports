<?php

namespace App\Support\SeasonChain;

use RuntimeException;

/**
 * A change to a player's opponent list the league refuses. The message is
 * translated and shown to the player.
 */
final class OpponentListRefused extends RuntimeException {}
