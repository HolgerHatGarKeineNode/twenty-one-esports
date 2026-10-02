<?php

namespace App\Support\Tmnf;

/**
 * One callback the server sent by itself (a `methodCall` frame), e.g.
 * `TrackMania.PlayerFinish` with [PlayerUid, Login, TimeOrScore].
 */
final readonly class TmnfCallback
{
    /**
     * @param  list<mixed>  $params
     */
    public function __construct(public string $method, public array $params) {}
}
