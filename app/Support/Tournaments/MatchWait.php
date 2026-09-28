<?php

namespace App\Support\Tournaments;

use App\Models\User;
use App\Support\LeagueTime;
use App\Support\PreSeason;
use Carbon\CarbonImmutable;

/**
 * What one open tournament match waits for (P18, slice 5; read by
 * {@see TournamentWaits}): its state, the players it waits on, since when,
 * and when the league decides on its own and what then happens.
 *
 * The texts are English keys with their parameters, translated where they
 * are shown (the panel, the player's countdown, a reminder in the
 * recipient's language).
 *
 * States: `first_move` (chess, the first move is due), `move` (daily chess,
 * a move is due), `checkin` (a cup series, not checked in), `cup_time` (a
 * cup series match, no time agreed yet), `scheduled` (a cup series match,
 * its agreed time is ahead), `not_started` (no game or series yet), `noshow`
 * (a no-show was reported or claimed), `lobby` / `join` (a cup series'
 * lobby is not shared or not joined), `report` (nobody reported), `response`
 * (a report is not answered), `overdue` (nobody reported by the deadline:
 * the admins decide), `disputed`, `held` (set aside after a correction),
 * `director` (the directors enter it), `playing` (a game under way, nothing
 * to wait for).
 */
final readonly class MatchWait
{
    /**
     * @param  array{0: string, 1: string}  $sides  the two sides' names, slot 0 and 1
     * @param  list<int>  $players  every player of both sides
     * @param  list<array{user_id: int, name: string}>  $waitingOn  the players the match waits on
     * @param  array<string, string>  $params  the parameters of `$consequence` and `$action`
     * @param  string|null  $subject  what the reminders are stored against (`series:<id>`, `chess:<id>`, `match:<id>`)
     * @param  CarbonImmutable|null  $timeAt  the moment `:time` names (in `$params` in the league's time), for a recipient's own zone
     */
    public function __construct(
        public int $matchId,
        public string $label,
        public array $sides,
        public array $players,
        public string $state,
        public array $waitingOn,
        public ?CarbonImmutable $since,
        public ?CarbonImmutable $decidesAt,
        public ?string $consequence,
        public ?string $action,
        public array $params,
        public bool $needsAdmin,
        public bool $paused,
        public bool $remindable,
        public ?string $subject,
        public string $url,
        public ?CarbonImmutable $timeAt = null,
    ) {}

    /** The state's short label, translated. */
    public function stateLabel(?string $locale = null): string
    {
        return __(match ($this->state) {
            'first_move' => 'No first move',
            'move' => 'Move due',
            'checkin' => 'Not checked in',
            'cup_time' => 'Time not agreed',
            'scheduled' => 'Time agreed',
            'not_started' => 'Not started',
            'noshow' => 'No-show reported',
            'lobby' => 'Lobby not shared',
            'join' => 'Lobby not joined',
            'report' => 'No report',
            'response' => 'Report unanswered',
            'overdue' => 'No report, overdue',
            'disputed' => 'Disputed',
            'held' => 'On hold',
            'director' => 'Result pending',
            default => 'Playing',
        }, [], $locale);
    }

    /** What happens at `$decidesAt` (or who decides without one), translated; null for nothing. */
    public function consequenceText(?string $locale = null, ?User $recipient = null): ?string
    {
        return $this->consequence === null ? null : (string) __($this->consequence, $this->paramsFor($recipient, $locale), $locale);
    }

    /** What a waited-on player has to do, translated; null for nothing. */
    public function actionText(?string $locale = null, ?User $recipient = null): ?string
    {
        return $this->action === null ? null : (string) __($this->action, $this->paramsFor($recipient, $locale), $locale);
    }

    /**
     * The parameters, with `:time` in the recipient's zone and language when
     * there is one; the league's time otherwise (the panel, the pages).
     *
     * @return array<string, string>
     */
    private function paramsFor(?User $recipient, ?string $locale): array
    {
        if ($recipient === null || $this->timeAt === null || ! array_key_exists('time', $this->params)) {
            return $this->params;
        }

        return [...$this->params, 'time' => LeagueTime::stamp($this->timeAt, PreSeason::timezoneFor($recipient), $locale ?? $recipient->locale ?? (string) config('app.locale'))];
    }

    /** A countdown to an automatic decision runs: a deadline, and the tournament's pause does not hold it. */
    public function counts(): bool
    {
        return $this->decidesAt !== null && ! $this->paused;
    }

    public function waitsOn(int $userId): bool
    {
        return in_array($userId, array_column($this->waitingOn, 'user_id'), true);
    }

    /**
     * The sort key of the panel, most urgent first: what only an organizer
     * or admin can move (oldest first), then the countdowns (nearest
     * first), then the paused ones, then the rest.
     *
     * @return array{0: int, 1: int}
     */
    public function urgency(): array
    {
        return match (true) {
            $this->needsAdmin => [0, $this->since?->getTimestamp() ?? 0],
            $this->counts() => [1, (int) $this->decidesAt?->getTimestamp()],
            $this->decidesAt !== null => [2, $this->decidesAt->getTimestamp()],
            default => [3, $this->matchId],
        };
    }
}
