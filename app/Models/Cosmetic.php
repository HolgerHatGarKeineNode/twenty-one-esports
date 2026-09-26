<?php

namespace App\Models;

use App\Support\Engagement\Cosmetics;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A cosmetic a player owns (P10), once per player
 * ({@see Cosmetics}). Never an input to ratings,
 * trust, blocks or rewards.
 *
 * @property int $id
 * @property int $user_id
 * @property string $cosmetic
 * @property string $source where it came from, e.g. `invite:<invite use id>`
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'cosmetic', 'source'])]
class Cosmetic extends Model {}
