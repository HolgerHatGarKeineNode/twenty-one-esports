<?php

namespace App\Support\Tmnf;

/**
 * Opens the listener's session on the TMNF server (TmnfServer::open()); bound
 * in the container so a test hands `tmnf:listen` a session over recorded frames.
 */
class TmnfConnector
{
    /**
     * @throws GbxException
     */
    public function open(): TmnfServer
    {
        return TmnfServer::open();
    }
}
