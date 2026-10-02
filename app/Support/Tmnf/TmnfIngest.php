<?php

namespace App\Support\Tmnf;

use App\Support\Scores\JsonServerIngest;
use App\Support\Scores\ServerIngest;

/**
 * The finishes of the league's TMNF server (plan "Trackmania und Restposten"):
 * `tmnf:listen` hands them over read already (ingestEvents()); a bridge may
 * post the league's JSON finish format instead (parse() reads it as
 * JsonServerIngest does).
 */
final class TmnfIngest extends ServerIngest
{
    public function parse(array $payload): array
    {
        return (new JsonServerIngest)->parse($payload);
    }
}
