<?php

namespace App\Support\StreamBot;

use App\Games\TrackmaniaNationsForever;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Support\LeagueTime;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreWindow;
use App\Support\Tmnf\TmnfWeeks;
use Carbon\CarbonImmutable;

/**
 * The stream bot's TMNF week notes on its own profile (plan "Trackmania und
 * Restposten", P2), as Blockfill's (WeeklyBoardNotes): the week and its
 * track once it is open, its new first place, its winner and top 3. The
 * players are tagged by their Nostr key, never by a TMNF login. The
 * first-place notes are at least `esports.stream_bot.tmnf_notes.top_minutes` apart.
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

    protected function weekNote(Tournament $week): string
    {
        return StreamBotCopy::render('tmnf_note_week', 0, [
            'name' => StreamBotCopy::clean($week->title(), 40),
            'track' => $this->track($week),
            'ends' => LeagueTime::stamp(ScoreWindow::of($week)->end),
            'url' => route('tournaments.show', $week),
        ]);
    }

    protected function winnerNote(Tournament $week, array $values): string
    {
        return StreamBotCopy::render('tmnf_note_winner', 0, [...$values, 'track' => $this->track($week), 'url' => route('scores.show', TrackmaniaNationsForever::SLUG)]);
    }

    protected function topNote(Tournament $week, ScoreRun $run, array $values): string
    {
        return StreamBotCopy::render('tmnf_note_top', 0, [...$values, 'track' => $this->track($week), 'url' => route('tournaments.show', $week)]);
    }

    private function track(Tournament $week): string
    {
        return StreamBotCopy::clean(TmnfWeeks::track($week->score_course)['name'] ?? (string) $week->score_course, 40);
    }
}
