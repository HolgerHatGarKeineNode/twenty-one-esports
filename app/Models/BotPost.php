<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One note of the stream bot on its own profile (App\Support\StreamBot\TournamentNotes):
 * which subject it is about, and whether it went out.
 *
 * The row exists from the first claim; `attempted_at` is the claim (no
 * other run touches the row for `esports.stream_bot.tournament_notes.retry_minutes`),
 * `event` the signed event once signed (a retry sends it again unchanged),
 * `published_at` set once at least one relay accepted it. A row with
 * `published_at` is never posted again.
 *
 * `slot` tells apart several notes of one kind on one subject: the
 * free-places notes (App\Support\StreamBot\FreePlaceNotes) have one row per
 * tournament and slot ("168h" … "3h"); the one-per-subject notes keep ''.
 * A NIP-09 deletion of a tournament note whose start changed is a row of
 * kind 5 on the same tournament, slot: the first 16 characters of the
 * deleted note's id; `published_at` once every relay took it.
 *
 * `note_type` and `variant` are set when a note is signed (ProfileNotes):
 * what kind of note it is, for its type's cooldown and daily cap, and the
 * wording it used, so the type's next note takes the next one.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property int $kind
 * @property string $slot
 * @property string|null $note_type
 * @property int|null $variant
 * @property string|null $event_id
 * @property string|null $event
 * @property int $attempts
 * @property int $relays_accepted
 * @property int $relays_total
 * @property Carbon|null $attempted_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['subject_type', 'subject_id', 'kind', 'slot', 'note_type', 'variant', 'event_id', 'event', 'attempts', 'relays_accepted', 'relays_total', 'attempted_at', 'published_at'])]
class BotPost extends Model
{
    public const SUBJECT_TOURNAMENT = 'tournament';

    /** A tournament's free-places reminders (P49), one row per slot. */
    public const SUBJECT_FREE_PLACES = 'tournament_places';

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'kind' => 'integer',
            'variant' => 'integer',
            'attempts' => 'integer',
            'relays_accepted' => 'integer',
            'relays_total' => 'integer',
            'attempted_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
