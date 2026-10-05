<?php

namespace App\Support\Moderation;

use RuntimeException;

/**
 * The relay quorum of the league's mute list was not read to EOSE (no
 * NIP-65 relay list, no readable write relay, one of them not answering),
 * or a newer list reached it right before signing, so no new version was
 * signed: one signed without the newest list could wipe a client's private
 * items ({@see LeagueMuteList}).
 */
final class MuteListUnreadable extends RuntimeException {}
