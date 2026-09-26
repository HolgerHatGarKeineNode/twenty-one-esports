<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A league report that counted in a season (security re-check round 4): it
 * keeps its place in the caps for the rest of that season
 * (App\Support\SeasonChain\TrustJob). A dismissal or an exclusion of its
 * author stops it counting against the target, not its place.
 *
 * @property int $id
 * @property int $season_id
 * @property string $event_id
 * @property string $author
 * @property string $target
 * @property string $subtree the anchor whose cap it uses
 * @property Carbon|null $created_at
 */
#[Fillable(['season_id', 'event_id', 'author', 'target', 'subtree'])]
class TrustCountedReport extends Model
{
    public const UPDATED_AT = null;
}
