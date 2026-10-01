<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A dedicated game server of ours that reports finishes to the league (plan
 * "AoE2 und Trackmania", P4; App\Support\Scores\ServerIngest). It sends its
 * token as a bearer token; only the SHA-256 of it is stored, so a leaked
 * database gives no token away. Revoking sets `revoked_at`: the token is
 * refused from then on, the runs it reported stay.
 *
 * @property int $id
 * @property string $name
 * @property string $game
 * @property string $token_hash
 * @property int|null $created_by_id
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'game', 'token_hash', 'created_by_id', 'revoked_at', 'last_seen_at'])]
#[Hidden(['token_hash'])]
class ScoreServer extends Model
{
    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
