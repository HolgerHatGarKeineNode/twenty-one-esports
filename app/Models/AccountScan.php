<?php

namespace App\Models;

use App\Support\FairPlay\AccountScans;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An AI scan an admin started for one account ({@see AccountScans}): a hint,
 * never a decision.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $pubkey
 * @property string $status `done` | `failed`
 * @property string|null $verdict `fine` | `unclear` | `suspicious`
 * @property list<string>|null $reasons
 * @property array<string, mixed> $inputs exactly what the model saw
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string $cost_usd
 * @property string|null $error
 * @property int|null $started_by_id
 * @property string $started_by_pubkey
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $startedBy
 */
#[Fillable(['user_id', 'pubkey', 'status', 'verdict', 'reasons', 'inputs', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost_usd', 'error', 'started_by_id', 'started_by_pubkey', 'started_at', 'finished_at'])]
class AccountScan extends Model
{
    public const DONE = 'done';

    public const FAILED = 'failed';

    public const VERDICTS = ['fine', 'unclear', 'suspicious'];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'inputs' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }
}
