<?php

namespace App\Support\Tmnf;

/**
 * The server answered a call with an XML-RPC fault (`faultCode`, `faultString`),
 * e.g. -1000 "Permission denied." for a call above the authenticated level.
 */
final class GbxFault extends GbxException {}
