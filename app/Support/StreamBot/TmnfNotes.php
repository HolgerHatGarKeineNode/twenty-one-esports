<?php

namespace App\Support\StreamBot;

use App\Games\TrackmaniaNationsForever;
use App\Models\Tournament;
use App\Support\Scores\ScoreRuns;
use App\Support\Tmnf\TmnfWeeks;
use Carbon\CarbonImmutable;

/**
 * The stream bot's TMNF week notes on its own profile (plan "Trackmania und
 * Restposten", P2), as Blockfill's (WeeklyBoardNotes): the week and its
 * track once it is open, its winner and top 3. The players are tagged by
 * their Nostr key, never by a TMNF login. Its new first places go to the
 * stream chat (StreamBotBuilders `tmnf_top`, user 2026-10-02); on the
 * profile only with `esports.stream_bot.tmnf_notes.top_on_profile`, then at
 * least `top_minutes` apart.
 */
final class TmnfNotes extends WeeklyBoardNotes
{
    public const SUBJECT = 'tmnf_week';

    public function __construct(TournamentNotes $notes, StreamBotPublisher $publisher, private TmnfWeeks $weeks, ScoreRuns $runs)
    {
        parent::__construct($notes, $publisher, $runs);
    }

    protected function enabled(): bool
    {
        return $this->weeks->game() !== null;
    }

    protected function current(CarbonImmutable $now): ?Tournament
    {
        return $this->weeks->current($now);
    }

    protected function isWeek(Tournament $week): bool
    {
        return $week->isTmnfWeek();
    }

    protected function slug(): string
    {
        return TrackmaniaNationsForever::SLUG;
    }

    protected function subject(): string
    {
        return self::SUBJECT;
    }

    protected function label(): string
    {
        return 'TMNF';
    }

    protected function topMinutes(): int
    {
        return (int) config('esports.stream_bot.tmnf_notes.top_minutes', self::TOP_MINUTES);
    }

    /** Off by default: TMNF's new best times go to the stream chat (StreamBotBuilders `tmnf_top`, user 2026-10-02). */
    protected function topOnProfile(): bool
    {
        return (bool) config('esports.stream_bot.tmnf_notes.top_on_profile', false);
    }

    protected function prefix(): string
    {
        return 'tmnf_note';
    }

    /** The week's track and page (How to join), the weeks' page for a winner. */
    protected function extras(Tournament $week, string $kind): array
    {
        return [
            'track' => self::track($week),
            'url' => $kind === 'winner' ? route('scores.show', TrackmaniaNationsForever::SLUG) : route('tournaments.show', $week),
        ];
    }

    /** The week's track by name, as the notes and the stream chat name it. */
    public static function track(Tournament $week): string
    {
        return StreamBotCopy::clean(TmnfWeeks::track($week->score_course)['name'] ?? (string) $week->score_course, 40);
    }
}
