<?php

namespace App\Support\StreamBot;

use App\Games\Blockfill;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Support\LeagueTime;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreWindow;
use App\Support\Stacker\BlockfillWeeks;
use Carbon\CarbonImmutable;

/**
 * The stream bot's Blockfill week notes on its own profile (plan
 * "Blockfill", P6): the week once it is open, its new first place, its
 * winner and top 3 (WeeklyBoardNotes). The first-place notes are at least
 * `esports.stream_bot.blockfill_notes.top_minutes` apart.
 */
final class BlockfillNotes extends WeeklyBoardNotes
{
    public const SUBJECT = 'blockfill_week';

    public function __construct(TournamentNotes $notes, StreamBotPublisher $publisher, private BlockfillWeeks $weeks, ScoreRuns $runs)
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
        return $week->isBlockfillWeek();
    }

    protected function slug(): string
    {
        return Blockfill::SLUG;
    }

    protected function subject(): string
    {
        return self::SUBJECT;
    }

    protected function label(): string
    {
        return 'Blockfill';
    }

    protected function topMinutes(): int
    {
        return (int) config('esports.stream_bot.blockfill_notes.top_minutes', self::TOP_MINUTES);
    }

    protected function weekNote(Tournament $week): string
    {
        return StreamBotCopy::render('blockfill_note_week', 0, [
            'name' => StreamBotCopy::clean($week->title(), 40),
            'ends' => LeagueTime::stamp(ScoreWindow::of($week)->end),
            'url' => route('stacker.play'),
        ]);
    }

    protected function winnerNote(Tournament $week, array $values): string
    {
        return StreamBotCopy::render('blockfill_note_winner', 0, [...$values, 'url' => route('scores.show', Blockfill::SLUG)]);
    }

    protected function topNote(Tournament $week, ScoreRun $run, array $values): string
    {
        return StreamBotCopy::render('blockfill_note_top', 0, [...$values, 'url' => route('stacker.play')]);
    }
}
