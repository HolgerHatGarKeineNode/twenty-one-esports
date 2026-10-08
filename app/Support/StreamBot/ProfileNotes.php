<?php

namespace App\Support\StreamBot;

use App\Models\BotPost;
use Carbon\CarbonImmutable;

/**
 * Variety and pacing of the stream bot's notes on its own profile, shared by
 * every kind of note there (TournamentNotes, FreePlaceNotes, PrideNotes,
 * WeeklyBoardNotes). Each note is signed with a note type ("tournament",
 * "free_places", "blockfill_top", "pride_win", …) and the wording (variant
 * of its StreamBotCopy template) it used, both kept on its bot_posts row.
 *
 * - Rotation: a note takes the wording after the last one its type signed,
 *   so the same wording never follows itself (deterministic, no dice); the
 *   first note of a type takes the first wording.
 * - Pacing: `esports.stream_bot.profile_limits.<type>` gives a type a
 *   `cooldown_minutes` after its last delivered note and a `daily_cap` of
 *   delivered notes per day in the bot's time zone (Berlin); 0 or missing is
 *   no limit. A note held back by either is not lost: its producer offers it
 *   again on a later run, unless its own window closed meanwhile.
 *
 * Rows from before the types existed carry none and count for neither.
 */
final class ProfileNotes
{
    /**
     * The wording the next note of `$type` takes among `$variants`: the one
     * after the type's last signed note, the first one for its first note.
     */
    public static function nextVariant(string $type, int $variants): int
    {
        if ($variants <= 1) {
            return 0;
        }

        $last = BotPost::query()->where('note_type', $type)->whereNotNull('variant')->whereNotNull('event')->orderByDesc('id')->value('variant');
        $next = $last === null ? 0 : ((int) $last + 1) % $variants;
        // Variant index modulo 3 is the shape (card, short, aside). A different
        // type must not repeat the shape the profile just used.
        $lastAny = BotPost::query()->whereNotNull('note_type')->whereNotNull('variant')->whereNotNull('event')->orderByDesc('id')->value('variant');

        if ($lastAny !== null && ($next % 3) === ((int) $lastAny % 3)) {
            return ($next + 1) % $variants;
        }

        return $next;
    }

    /**
     * Why the profile may not take another note now, null when it may.
     * Unreadable quiet hours stay quiet. A gap of 0 is no shared brake.
     */
    public static function hold(CarbonImmutable $now): ?string
    {
        if (StreamBot::quietAt($now) !== false) {
            return 'quiet hours';
        }

        $gap = max(0, (int) config('esports.stream_bot.profile_gap_minutes', 240));

        if ($gap === 0) {
            return null;
        }

        $last = BotPost::query()->where('kind', 1)->whereNotNull('published_at')->latest('published_at')->value('published_at');

        if ($last !== null && CarbonImmutable::parse($last)->utc()->greaterThan($now->subMinutes($gap))) {
            return 'the profile waits '.$gap.' minutes after its last note';
        }

        return null;
    }

    /** Whether `$subjectType` already delivered a kind-1 note for this subject today (Berlin). */
    public static function subjectSpokeToday(string $subjectType, int $subjectId, CarbonImmutable $now): bool
    {
        $start = $now->setTimezone(self::timezone())->startOfDay()->utc();

        return BotPost::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('kind', 1)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $start)
            ->exists();
    }

    /**
     * Pubkeys already tagged on a delivered note of `$noteType`.
     *
     * @return list<string>
     */
    public static function announcedPubkeys(string $noteType): array
    {
        $keys = [];

        foreach (BotPost::query()->where('note_type', $noteType)->whereNotNull('published_at')->whereNotNull('event')->pluck('event') as $event) {
            $tags = json_decode((string) $event, true)['tags'] ?? [];

            foreach (is_array($tags) ? $tags : [] as $tag) {
                if (is_array($tag) && ($tag[0] ?? null) === 'p' && is_string($tag[1] ?? null) && $tag[1] !== '') {
                    $keys[$tag[1]] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * How many notes of `$type` may go out now: none within its cooldown or
     * at its daily cap, one while a cooldown is set (the next one waits for
     * it), otherwise what is left of the cap; PHP_INT_MAX without limits.
     */
    public static function allowance(string $type, CarbonImmutable $now): int
    {
        $limits = (array) config('esports.stream_bot.profile_limits.'.$type, []);
        $cooldown = max(0, (int) ($limits['cooldown_minutes'] ?? 0));
        $cap = max(0, (int) ($limits['daily_cap'] ?? 0));
        $delivered = BotPost::query()->where('note_type', $type)->whereNotNull('published_at');

        if ($cooldown > 0 && (clone $delivered)->where('published_at', '>', $now->subMinutes($cooldown))->exists()) {
            return 0;
        }

        $left = PHP_INT_MAX;

        if ($cap > 0) {
            $today = (clone $delivered)->where('published_at', '>=', $now->setTimezone(self::timezone())->startOfDay()->utc())->count();
            $left = max(0, $cap - $today);
        }

        return $cooldown > 0 ? min(1, $left) : $left;
    }

    /**
     * Why `$type` may not post now, null when it may (for the dry runs and the log).
     */
    public static function blocked(string $type, CarbonImmutable $now): ?string
    {
        return self::allowance($type, $now) > 0 ? null : $type.' waits for its cooldown or daily cap (esports.stream_bot.profile_limits)';
    }

    private static function timezone(): string
    {
        return (string) config('esports.stream_bot.timezone', 'Europe/Berlin');
    }
}
