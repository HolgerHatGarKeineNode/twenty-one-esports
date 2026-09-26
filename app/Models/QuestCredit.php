<?php

namespace App\Models;

use App\Support\Engagement\Quests;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One result counted toward one weekly quest of one player (P10). Unique
 * per player, quest, week and result, so a result counts once however often
 * it is reported ({@see Quests}).
 *
 * @property int $id
 * @property int $user_id
 * @property string $quest
 * @property string $period ISO week, e.g. `2026-W39`
 * @property string $source `chess:<game id>` or `series:<match id>`
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'quest', 'period', 'source'])]
class QuestCredit extends Model {}
