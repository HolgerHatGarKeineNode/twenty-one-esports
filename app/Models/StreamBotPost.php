<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One kind-1311 message of the stream chat bot (P22, App\Support\StreamBot).
 *
 * `posted_at` is the event's `created_at`; `next_due_at` the earliest moment
 * of the next post (interval plus jitter, drawn once when this one went out).
 * A post no relay accepted stays in the log (and delays the next try) but
 * counts for nothing else: not for the daily cap, not as the bot's last word
 * in the chat, not for the rotation.
 *
 * @property int $id
 * @property string $builder
 * @property string $fact_key
 * @property string $event_id
 * @property string $content
 * @property int $relays_accepted
 * @property int $relays_total
 * @property Carbon $posted_at
 * @property Carbon $next_due_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['builder', 'fact_key', 'event_id', 'content', 'relays_accepted', 'relays_total', 'posted_at', 'next_due_at'])]
class StreamBotPost extends Model
{
    protected function casts(): array
    {
        return [
            'relays_accepted' => 'integer',
            'relays_total' => 'integer',
            'posted_at' => 'datetime',
            'next_due_at' => 'datetime',
        ];
    }

    /**
     * Posts at least one relay accepted: the ones that are really in the chat.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function delivered(Builder $query): void
    {
        $query->where('relays_accepted', '>', 0);
    }
}
