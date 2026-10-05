<?php

namespace App\Support\Moderation;

use RuntimeException;

/**
 * No relay answered the read of the league's mute list to EOSE, so no new
 * version was signed: one signed without the newest list could wipe a
 * client's private items ({@see LeagueMuteList}).
 */
final class MuteListUnreadable extends RuntimeException {}
