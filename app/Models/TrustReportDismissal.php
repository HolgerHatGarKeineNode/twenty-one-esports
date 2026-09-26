<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An admin dismissed a league report (`1984`): it stops counting from the
 * next trust run on (App\Support\SeasonChain\TrustAdmin).
 *
 * @property int $id
 * @property string $event_id the report's id
 * @property int|null $dismissed_by_id
 * @property string $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['event_id', 'dismissed_by_id', 'reason'])]
class TrustReportDismissal extends Model {}
