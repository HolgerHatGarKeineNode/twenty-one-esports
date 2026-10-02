<?php

namespace App\Support\Tmnf;

use RuntimeException;

/**
 * Anything that went wrong talking to a TMNF dedicated server over GBXRemote 2.
 */
class GbxException extends RuntimeException {}
