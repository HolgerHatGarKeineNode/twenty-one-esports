<?php

namespace App\Support\Badges;

use RuntimeException;

/**
 * A badge the league will not add to the player's profile list, or a share
 * post it will not take. The message is translated and shown to the player.
 */
final class ProfileBadgesRefused extends RuntimeException {}
