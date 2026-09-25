<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A player muted another pubkey in the game chat, for themselves only (plan:
 * no moderation, "Stummschalten für sich selbst"). The browser keeps a copy
 * in localStorage and hides that pubkey's messages; nothing is published.
 *
 * @property int $id
 * @property int $user_id
 * @property string $muted_pubkey
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'muted_pubkey'])]
class ChatMute extends Model {}
