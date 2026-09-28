<?php

namespace App\Support\Comments;

use RuntimeException;

/**
 * A comment, like or RSVP the league will not prepare or take. The message is
 * translated and shown to the player.
 */
final class CommentRefused extends RuntimeException {}
