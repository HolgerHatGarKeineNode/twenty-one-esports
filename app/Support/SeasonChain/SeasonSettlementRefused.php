<?php

namespace App\Support\SeasonChain;

use RuntimeException;

/**
 * A season settlement action the league refuses (a void, the approval of
 * the list, the approval of an address). The message is translated and
 * shown to the admin.
 */
final class SeasonSettlementRefused extends RuntimeException {}
