<?php

namespace App\Support\Tournaments;

use App\Enums\NotificationKind;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentReminder;
use App\Models\User;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\Pages\RulesPage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Reminders to the players a tournament match waits on (P18, slice 5),
 * through the Notifier (the bell, and a browser push while the player is
 * away; minutes to act are too few for a Nostr DM,
 * NotificationKind::TournamentReminder):
 *
 * 1. **Automatic** ({@see tick()}, run by `tournaments:tick`): at each point
 *    of `esports.tournaments.reminders` (minutes) before the league decides
 *    a waiting match on its own (TournamentWaits), once per player, state,
 *    deadline and point. The row in `tournament_reminders` is written
 *    before the notification (insert-or-ignore on its unique key), so a
 *    second or concurrent tick sends nothing. Several points passed at once
 *    (a deadline set close) send one reminder. A point the wait began
 *    inside is skipped, and so is everything a pause holds: a paused
 *    tournament's series run no deadline.
 * 2. **By hand** ({@see remind()}, the "Who blocks what" panel): an
 *    organizer of the tournament or an admin (gate `manage-tournament`)
 *    reminds one waited-on player, once per `remind_every_minutes` per
 *    player and match, written to the moderation log.
 */
final class TournamentReminders
{
    public function __construct(private Notifier $notifier, private TournamentModeration $moderation) {}

    /**
     * Send the automatic reminders that are due. One tournament that fails
     * is reported and the others go on.
     *
     * @return int the reminders sent
     */
    public function tick(): int
    {
        $points = self::points();

        if ($points === []) {
            return 0;
        }

        $sent = 0;

        foreach (Tournament::query()->where('status', TournamentStatus::Running)->orderBy('id')->get() as $tournament) {
            try {
                foreach (TournamentWaits::of($tournament) as $wait) {
                    $sent += $this->due($tournament, $wait, $points);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /**
     * Remind one player of one match by hand.
     *
     * @throws TournamentRuleViolation
     */
    public function remind(Tournament $tournament, User $actor, int $matchId, int $userId): void
    {
        if (! Gate::forUser($actor)->allows('manage-tournament', $tournament)) {
            throw new TournamentRuleViolation('not_manager', __('Only the organizer of this tournament or an admin can do this.'));
        }

        $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->with(['round', 'slots.participant', 'seriesMatch.latestReport', 'chessGame'])->find($matchId);
        $tournament = $tournament->refresh();
        $wait = $match === null || $tournament->status !== TournamentStatus::Running ? null : TournamentWaits::forMatch($tournament, $match->setRelation('tournament', $tournament));
        $player = User::query()->find($userId);

        if ($wait === null || $player === null || $wait->action === null || ! $wait->waitsOn($userId)) {
            throw new TournamentRuleViolation('not_waiting', __('This match no longer waits for this player.'));
        }

        $throttle = 'tournament-remind:'.$wait->matchId.':'.$userId;
        $minutes = max(1, (int) config('esports.tournaments.remind_every_minutes', 10));

        if (RateLimiter::tooManyAttempts($throttle, 1)) {
            throw new TournamentRuleViolation('too_many', __('You reminded this player a moment ago. Try again in :minutes min.', [
                'minutes' => max(1, (int) ceil(RateLimiter::availableIn($throttle) / 60)),
            ]));
        }

        RateLimiter::hit($throttle, $minutes * 60);
        $this->moderation->log($tournament, $actor, 'reminded', subject: $player->displayName().' ('.$wait->label.')', reason: $wait->stateLabel());
        $this->send($tournament, $player, $wait, $actor);
    }

    /**
     * The reminder points in minutes, largest first.
     *
     * @return list<int>
     */
    public static function points(): array
    {
        $points = array_values(array_unique(array_filter(array_map(intval(...), (array) config('esports.tournaments.reminders', [])), fn (int $minutes): bool => $minutes > 0)));
        rsort($points);

        return $points;
    }

    /**
     * @param  list<int>  $points
     */
    private function due(Tournament $tournament, MatchWait $wait, array $points): int
    {
        if (! $wait->remindable || ! $wait->counts() || $wait->subject === null || $wait->decidesAt === null) {
            return 0;
        }

        $at = $wait->decidesAt;
        $left = $at->getTimestamp() - now()->getTimestamp();

        // Passed, and the wait was already on at that point.
        $due = array_values(array_filter($points, fn (int $minutes): bool => $left > 0 && $left <= $minutes * 60
            && ($wait->since === null || $wait->since->lt($at->subMinutes($minutes)))));

        if ($due === []) {
            return 0;
        }

        $sent = 0;

        foreach ($wait->waitingOn as ['user_id' => $userId]) {
            $inserted = TournamentReminder::query()->insertOrIgnore(array_map(fn (int $minutes): array => [
                'tournament_id' => $tournament->id,
                'tournament_match_id' => $wait->matchId,
                'user_id' => $userId,
                'subject' => $wait->subject,
                'state' => $wait->state,
                'due_at' => $at->toDateTimeString(),
                'minutes_before' => $minutes,
                'created_at' => now()->toDateTimeString(),
            ], $due));

            $player = $inserted > 0 ? User::query()->find($userId) : null;

            if ($player !== null) {
                $this->send($tournament, $player, $wait);
                $sent++;
            }
        }

        return $sent;
    }

    private function send(Tournament $tournament, User $player, MatchWait $wait, ?User $sender = null): void
    {
        $locale = $player->locale ?? (string) config('app.locale');
        $action = (string) $wait->actionText($locale, $player);
        $body = $wait->counts() && $wait->decidesAt !== null
            ? __('The league decides in :time. :action', ['time' => RulesPage::largestUnit(max(1, (int) ceil(($wait->decidesAt->getTimestamp() - now()->getTimestamp()) / 60)), $locale), 'action' => $action], $locale)
            : $action;

        $this->notifier->send($player, NotificationKind::TournamentReminder, new Notice(
            __('Your match in :tournament', ['tournament' => $tournament->name], $locale),
            $body,
            $wait->url,
            null,
            __('Open match', [], $locale),
        ), sender: $sender);
    }
}
