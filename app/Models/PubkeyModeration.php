<?php

namespace App\Models;

use App\Enums\ModerationAction;
use App\Support\Nostr\NostrKeys;
use Database\Factories\PubkeyModerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An admin muted or banned a Nostr key site-wide (App\Support\Moderation\SiteModeration).
 * The row is the log entry as well: undoing sets `lifted_at` and keeps it.
 * Never shown to anyone but admins (no public list, no label on a player).
 *
 * @property int $id
 * @property string $pubkey hex
 * @property ModerationAction $action
 * @property string $reason
 * @property string $actor_pubkey the admin who did it
 * @property Carbon|null $lifted_at
 * @property string|null $lifted_by_pubkey the admin who undid it
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['pubkey', 'action', 'reason', 'actor_pubkey', 'lifted_at', 'lifted_by_pubkey'])]
class PubkeyModeration extends Model
{
    /** @use HasFactory<PubkeyModerationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['action' => ModerationAction::class, 'lifted_at' => 'datetime'];
    }

    /**
     * Steps in force: not undone.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('lifted_at');
    }

    public function npub(): string
    {
        return NostrKeys::hexToNpub($this->pubkey);
    }
}
