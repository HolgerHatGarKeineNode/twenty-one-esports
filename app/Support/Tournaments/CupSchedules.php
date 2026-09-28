<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * When a casual cup's series match is played (P25 S3, Rocket League and EA
 * Sports FC): in the round's window either player proposes one to three
 * times (the lineup and casual challenges' rules, SeriesService::schedule(),
 * each inside the window), the other accepts one within `answer_hours`
 * (at the latest by the first proposed time). The agreed time is stored on
 * the match (`schedule`); the league starts the match's series for it
 * (CasualCups::mayStart(), TournamentMatchMaker), with the scheduled casual
 * check-in and flow. Without an agreement the cup's auto slot applies.
 *
 * A new proposal replaces an unanswered one, from either side; once agreed
 * the time stands. Proposing and accepting count as trying to play
 * (CasualCups::actedSlots()). Every change happens in one transaction on the
 * match read inside it, so two clicks agree once.
 */
final class CupSchedules
{
    public function __construct(private CasualCupNotices $notices) {}

    /**
     * @param  list<int>  $proposals  unix seconds
     *
     * @throws SeriesRuleViolation
     */
    public function propose(TournamentMatch $match, User $user, array $proposals): TournamentMatch
    {
        $proposed = DB::transaction(function () use ($match, $user, $proposals): TournamentMatch {
            $locked = $this->open($match, $user);

            if (self::agreedAt($locked) !== null) {
                throw new SeriesRuleViolation('cup_agreed', __('You have agreed on a time already.'));
            }

            $windowEnd = $locked->round->window_ends_at?->getTimestamp() ?? 0;
            $answerBy = now()->addHours(max(1, (int) config('esports.casual_cups.answer_hours', 12)))->getTimestamp();
            $first = min(array_map(intval(...), $proposals === [] ? [0] : $proposals));
            ['proposals' => $times] = SeriesService::schedule($proposals, max(now()->getTimestamp() + 1, min($answerBy, $first)), null);

            foreach ($times as $time) {
                if ($time > $windowEnd) {
                    throw new SeriesRuleViolation('cup_window', __('Every suggested time has to lie before the round ends.'));
                }
            }

            $locked->forceFill(['schedule' => [
                'by' => $user->id,
                'proposals' => $times,
                'respond_by' => min($answerBy, $times[0]),
                'agreed_at' => null,
                'accepted_by' => null,
            ]])->save();

            return $locked;
        });

        $this->notices->timesProposed($proposed, $user);

        return $proposed;
    }

    /**
     * @throws SeriesRuleViolation
     */
    public function accept(TournamentMatch $match, User $user, int $start): TournamentMatch
    {
        $accepted = DB::transaction(function () use ($match, $user, $start): TournamentMatch {
            $locked = $this->open($match, $user);
            $schedule = $locked->schedule;

            if ($schedule === null || self::agreedAt($locked) !== null || (int) $schedule['by'] === $user->id) {
                throw new SeriesRuleViolation('cup_no_proposal', __('There is no proposal of your opponent to accept.'));
            }

            if ((int) $schedule['respond_by'] <= now()->getTimestamp() || ! in_array($start, $schedule['proposals'], true) || $start <= now()->getTimestamp()) {
                throw new SeriesRuleViolation('cup_proposal_closed', __('That time is no longer open. Propose new times.'));
            }

            $locked->forceFill(['schedule' => [...$schedule, 'agreed_at' => $start, 'accepted_by' => $user->id]])->save();

            return $locked;
        });

        $this->notices->timeAgreed($accepted, $user);

        return $accepted;
    }

    /** The agreed start, or null. */
    public static function agreedAt(TournamentMatch $match): ?CarbonImmutable
    {
        $at = $match->schedule['agreed_at'] ?? null;

        return is_int($at) ? CarbonImmutable::createFromTimestamp($at) : null;
    }

    /**
     * The match, read anew inside the transaction: a series match of a
     * running cup in its round's window, not started yet, played by this user.
     *
     * @throws SeriesRuleViolation
     */
    private function open(TournamentMatch $match, User $user): TournamentMatch
    {
        $locked = TournamentMatch::query()->with(['tournament', 'round', 'slots.participant', 'seriesMatch'])->lockForUpdate()->findOrFail($match->id);
        $players = $locked->slots->map(fn ($slot): ?int => $slot->participant?->memberIds()[0] ?? null)->all();
        $cup = $locked->tournament;

        if (! in_array($user->id, $players, true)) {
            throw new SeriesRuleViolation('not_your_match', __('This is not your match.'));
        }

        if (! $cup->isCasualCup() || $cup->profile()->isChess() || CasualCups::isEvening($cup) || $cup->status !== TournamentStatus::Running
            || $locked->status !== 'ready' || $locked->result !== null || $locked->seriesMatch !== null
            || ! ($locked->round->window_ends_at?->isFuture() ?? false)) {
            throw new SeriesRuleViolation('cup_not_schedulable', __('This match cannot be scheduled now.'));
        }

        return $locked;
    }
}
