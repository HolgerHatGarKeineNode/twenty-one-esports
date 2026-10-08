<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One action of a Hyperbitcoinization match, as the rules core took it (HyperGame::apply), with every event
 * it caused, unfiltered. The match's seed plus its actions in ply order replay it; who sees which event
 * field is App\Support\Hyper\HyperView's business when it is read.
 *
 * `source`: `player` (sent by the seat's player), `bot` (a bot seat, or a seat a bot took over), `timer`
 * (the turn ran out and the server ended it), `leave` (the player left mid-turn and the server ended it),
 * `server` (ply 0: the setup, `{"type":"start"}`, whose events are the deal and the first turn).
 *
 * @property int $id
 * @property int $hyper_match_id
 * @property int $ply 1 = the first action
 * @property int $seat
 * @property string $source
 * @property array<string, mixed> $action
 * @property list<array<string, mixed>> $events
 * @property Carbon|null $created_at
 * @property-read HyperMatch $match
 */
#[Fillable(['hyper_match_id', 'ply', 'seat', 'source', 'action', 'events', 'created_at'])]
class HyperAction extends Model
{
    public const PLAYER = 'player';

    public const BOT = 'bot';

    public const TIMER = 'timer';

    public const LEAVE = 'leave';

    public const SERVER = 'server';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'ply' => 'integer',
            'seat' => 'integer',
            'action' => 'array',
            'events' => 'array',
        ];
    }

    /**
     * @return BelongsTo<HyperMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(HyperMatch::class, 'hyper_match_id');
    }
}
