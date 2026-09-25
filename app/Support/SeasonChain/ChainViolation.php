<?php

namespace App\Support\SeasonChain;

use RuntimeException;

/**
 * A request the season chain refuses because it would break an invariant of
 * NIP rev. 5: an attestation out of order (a halving would reverse), a
 * parameter change that reaches back behind an attested result, a void of a
 * block that does not exist.
 */
final class ChainViolation extends RuntimeException {}
