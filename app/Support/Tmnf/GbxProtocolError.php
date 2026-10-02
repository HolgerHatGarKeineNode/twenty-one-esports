<?php

namespace App\Support\Tmnf;

/**
 * The peer sent something that is no GBXRemote 2 frame or no XML-RPC body the client reads.
 */
final class GbxProtocolError extends GbxException {}
