<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An admin confirmed a league report (`1984`): only from the next trust run
 * on can it count against its target (App\Support\SeasonChain\TrustAdmin).
 * A report has a confirmation, a dismissal ({@see TrustReportDismissal}) or
 * neither, which is waiting; should it ever have both, the dismissal wins.
 *
 * @property int $id
 * @property string $event_id the report's id
 * @property int|null $confirmed_by_id
 * @property string $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['event_id', 'confirmed_by_id', 'reason'])]
class TrustReportConfirmation extends Model {}
