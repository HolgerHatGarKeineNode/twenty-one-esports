<?php

namespace App\Models;

use App\Enums\StackerRunStatus;
use Database\Factories\StackerRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of Blockfill, our own stacking game (plan "Blockfill", P2), from
 * the issued one-time token to the verifier's verdict
 * (App\Support\Stacker\StackerRuns). The token is stored only as its sha256.
 * Runs without a verified time go after `esports.blockfill.prune_days`
 * (`model:prune`, scheduled daily in routes/console.php).
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $seed 32 hex digits
 * @property string $engine frozen engine version, e.g. "bf1"
 * @property StackerRunStatus $status
 * @property array{das: int, arr: int, sdf: int}|null $settings
 * @property Carbon $issued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $verified_at
 * @property int|null $ticks claimed (verified once status is verified)
 * @property string|null $state_hash
 * @property string|null $replay base64url input log
 * @property string|null $reason
 * @property array<string, mixed>|null $flags
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'token_hash', 'seed', 'engine', 'status', 'settings', 'issued_at', 'started_at', 'submitted_at', 'verified_at', 'ticks', 'state_hash', 'replay', 'reason', 'flags'])]
class StackerRun extends Model
{
    /** @use HasFactory<StackerRunFactory> */
    use HasFactory;

    use MassPrunable;

    /**
     * Milliseconds: the wall-clock bracket compares issue, start and submission
     * against the played time (ticks of 1/60 s).
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return [
            'status' => StackerRunStatus::class,
            'settings' => 'array',
            'flags' => 'array',
            'issued_at' => 'datetime',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'ticks' => 'integer',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('status', '!=', StackerRunStatus::Verified)
            ->where('created_at', '<', now()->subDays((int) config('esports.blockfill.prune_days'))->format($this->getDateFormat()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
