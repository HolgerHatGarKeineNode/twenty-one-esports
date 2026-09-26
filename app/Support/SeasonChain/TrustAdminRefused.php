<?php

namespace App\Support\SeasonChain;

use RuntimeException;

/**
 * An admin trust decision the league refuses. The message is translated and
 * shown to the admin.
 */
final class TrustAdminRefused extends RuntimeException {}
