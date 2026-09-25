<?php

namespace App\Support\Clans;

use RuntimeException;

/**
 * A clan action the league refuses before anything is signed, e.g. a second
 * pending invite or an owner leaving without a captain to take over. The
 * message is already translated and meant for the player.
 */
final class ClanRuleViolation extends RuntimeException {}
