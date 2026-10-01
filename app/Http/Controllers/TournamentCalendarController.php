<?php

namespace App\Http\Controllers;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\Scores\ScoreWindow;
use App\Support\Tournaments\Lobbies;
use Carbon\CarbonInterface;
use Illuminate\Http\Response;

/**
 * A published tournament as an iCalendar file (RFC 5545), for "Add to
 * calendar" on the tournament page: public, no session. Every time is UTC
 * (the trailing Z), so each calendar shows it in its owner's zone and no
 * zone table can disagree. The end is the planned end (start plus the
 * format's planned duration); drafts do not exist here.
 */
class TournamentCalendarController extends Controller
{
    public function __invoke(Tournament $tournament): Response
    {
        abort_if($tournament->status === TournamentStatus::Draft || $tournament->published_at === null, 404);
        // A Blockfill week while Blockfill is switched off (P6): no page to point to.
        abort_if($tournament->isSwitchedOffBlockfillWeek(), 404);

        $planned = $tournament->plannedDuration();
        // A score leaderboard ends with its window (a Blockfill week: the next Monday 00:00 Berlin, 167 to 169 hours).
        $end = $tournament->profile()->isScore() ? ScoreWindow::of($tournament)->end : ($tournament->profile()->isDaily()
            ? $tournament->starts_at->copy()->addDays((int) max(1, ceil($planned)))
            : $tournament->starts_at->copy()->addMinutes((int) max(30, ceil($planned))));
        $url = route('tournaments.show', $tournament);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//TWENTY ONE Esports//Tournaments//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:tournament-'.$tournament->id.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'),
            'DTSTAMP:'.$this->utc(now()),
            'DTSTART:'.$this->utc($tournament->starts_at),
            'DTEND:'.$this->utc($end),
            // A Blockfill week in the request's language (P6); any other tournament its own name.
            'SUMMARY:'.$this->text($tournament->title()),
            // A lobby tournament (P10): its game and "One lobby match", never "1v1, Free for All".
            'DESCRIPTION:'.$this->text(Lobbies::gameLine($tournament).', '.Lobbies::formatLabel($tournament).". \n".$url),
            'URL:'.$url,
            'LOCATION:'.$this->text($tournament->on_site ? __('On site') : __('Online')),
            'STATUS:'.($tournament->status === TournamentStatus::Cancelled ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return response(implode("\r\n", array_map($this->fold(...), $lines))."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="tournament-'.$tournament->id.'.ics"',
        ]);
    }

    private function utc(CarbonInterface $at): string
    {
        return $at->copy()->utc()->format('Ymd\THis\Z');
    }

    /** A TEXT value: backslash, semicolon, comma and newline escaped (RFC 5545 3.3.11). */
    private function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $value);
    }

    /** Lines longer than 75 octets continue on the next line after one space (RFC 5545 3.1), never inside a UTF-8 character. */
    private function fold(string $line): string
    {
        $out = '';
        $current = '';

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ").$current;
                $current = '';
            }
            $current .= $char;
        }

        return $out === '' ? $current : $out."\r\n ".$current;
    }
}
