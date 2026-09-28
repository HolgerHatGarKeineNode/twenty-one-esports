<?php

namespace App\Support\FairPlay;

use RuntimeException;

/**
 * A fair-play decision the league refuses (P41). The message is translated
 * and shown to the admin.
 */
final class FairPlayRefused extends RuntimeException {}
