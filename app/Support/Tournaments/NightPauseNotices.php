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
use App\Support\Series\SeriesPresenter;
use App\Support\Settings\LeagueSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * What the players of a running tournament are told about its night pause
 * (NightPause; user, 2026-10-10: "mit eindeutigen großen Hinweisen versehen
 * werden, damit die Leute das nicht übersehen und ihren 'Termin' verpassen"):
 *
 * - {@see state()}: `soon` from `warn_minutes` before the pause starts,
 *   `paused` while it runs; read by the banner (<x-tournament-night-pause>)
 *   and the dock (UpcomingEvents);
 * - {@see forPlayer()}: a player's open match in it, the opponent and what
 *   is due when play goes on (the check-in of a match that starts then, or
 *   the next deadline of a series under way);
 * - {@see tick()} (`tournaments:tick`): two messages to each player with an
 *   open match, through the Notifier as `tournament_news` (the kind an
 *   organizer's pause and resume use: bell, push and Nostr DM as the player
 *   chose, opt-out and digest included): when the pause starts, and
 *   `resume_reminder_minutes` before it ends. Each once per player, pause
 *   and kind: the row in `tournament_reminders` (subject `night:<id>`, the
 *   pause start or end as `due_at`) is written before the message. The
 *   restart reminder goes out at once even for a player who chose the daily
 *   digest (user, 2026-10-10: "Termin-Erinnerung sofort"); DMs off stay off.
 *   A casual cup's player hears it only when it matters that night: a match
 *   under way, or a round window that ends before the next pause (a window
 *   of days would otherwise send two messages a night).
 */
final class NightPauseNotices
{
    public function __construct(private Notifier $notifier) {}

    /**
     * The night pause as the players see it now: under way (`paused`) or
     * starting within `warn_minutes` (`soon`); null otherwise, and for a
     * tournament that is not running.
     *
     * @return array{state: 'soon'|'paused', starts: CarbonImmutable, resumes: CarbonImmutable, until: string, resume: string}|null
     */
    public static function state(Tournament $tournament, ?CarbonInterface $at = null): ?array
    {
        if ($tournament->status !== TournamentStatus::Running) {
            return null;
        }

        $at ??= now();
        $window = NightPause::windowOf($tournament);

        if ($window === null) {
            return null;
        }

        $labels = ['until' => NightPause::untilLabel($window['until']), 'resume' => $window['resume']];
        $current = NightPause::current($window, $at);

        // Before its start a pause that is over by then is no pause of this tournament: its players wait for the start, which
        // the tournament page counts down (NightPause::holdsUp()). No banner, no dock item, no message.
        if ($current !== null) {
            return NightPause::holdsUp($tournament, $current[1], $at) ? ['state' => 'paused', 'starts' => $current[0], 'resumes' => $current[1], ...$labels] : null;
        }

        $next = NightPause::next($window, $at);
        $warn = max(0, (int) config('esports.tournaments.night_pause.warn_minutes', 60)) * 60;

        if ($next !== null && $next[0]->getTimestamp() - $at->getTimestamp() <= $warn && NightPause::holdsUp($tournament, $next[1], $at)) {
            return ['state' => 'soon', 'starts' => $next[0], 'resumes' => $next[1], ...$labels];
        }

        return null;
    }

    /**
     * The open matches of a tournament: two sides known, no result, not
     * held. A running chess or board game finishes in the pause and is
     * left out once it ends; a series stays until it is decided.
     *
     * @return list<TournamentMatch>
     */
    public static function openMatches(Tournament $tournament): array
    {
        return array_values(TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'ready')->where('bracket', '!=', 'bye')
            ->whereNull('result')->whereNull('held')->whereNull('lobby')
            ->with(['round', 'slots.participant', 'seriesMatch.latestReport', 'chessGame', 'boardGame'])->orderBy('id')->get()
            ->filter(fn (TournamentMatch $match): bool => count($match->slots) === 2 && $match->slots->every(fn ($slot): bool => $slot->participant !== null))
            ->all());
    }

    /**
     * A player's open match in the tournament and what is due when play
     * goes on at `$resumes`; null without one.
     *
     * @return array{match: TournamentMatch, opponent: string, url: string, due: CarbonInterface|null, due_kind: 'checkin'|'deadline'|null}|null
     */
    public static function forPlayer(Tournament $tournament, User $user, CarbonImmutable $resumes): ?array
    {
        foreach (self::openMatches($tournament) as $match) {
            $info = self::infoFor($tournament, $match, $user->id, $resumes);

            if ($info !== null) {
                return $info;
            }
        }

        return null;
    }

    /**
     * Send what is due now; one tournament that fails is reported and the
     * others go on.
     *
     * @return int the messages sent
     */
    public function tick(): int
    {
        $sent = 0;
        $before = max(1, (int) config('esports.tournaments.night_pause.resume_reminder_minutes', 30));

        foreach (Tournament::query()->where('status', TournamentStatus::Running)->whereNull('paused_at')->orderBy('id')->get() as $tournament) {
            try {
                $state = self::state($tournament);

                if ($state === null || $state['state'] !== 'paused') {
                    continue;
                }

                $left = $state['resumes']->getTimestamp() - now()->getTimestamp();
                // The start message until the resume reminder is due; then only the reminder (a scheduler down at midnight
                // sends one message, not two at once).
                [$kind, $dueAt, $minutes] = $left > $before * 60 ? ['night_start', $state['starts'], 0] : ['night_resume', $state['resumes'], $before];
                $sent += $this->tell($tournament, $state, $kind, $dueAt, $minutes);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /**
     * @param  array{state: 'soon'|'paused', starts: CarbonImmutable, resumes: CarbonImmutable, until: string, resume: string}  $state
     * @param  'night_start'|'night_resume'  $kind
     */
    private function tell(Tournament $tournament, array $state, string $kind, CarbonImmutable $dueAt, int $minutes): int
    {
        $sent = 0;

        foreach (self::openMatches($tournament) as $match) {
            if (! self::mattersTonight($tournament, $match, $state['resumes'])) {
                continue;
            }

            foreach ($match->slots as $slot) {
                foreach ($slot->participant?->memberIds() ?? [] as $userId) {
                    $inserted = TournamentReminder::query()->insertOrIgnore([
                        'tournament_id' => $tournament->id,
                        'tournament_match_id' => $match->id,
                        'user_id' => $userId,
                        'subject' => 'night:'.$tournament->id,
                        'state' => $kind,
                        'due_at' => $dueAt->toDateTimeString(),
                        'minutes_before' => $minutes,
                        'created_at' => now()->toDateTimeString(),
                    ]);
                    $player = $inserted > 0 ? User::query()->find($userId) : null;
                    $info = $player === null ? null : self::infoFor($tournament, $match, $userId, $state['resumes']);

                    if ($player !== null && $info !== null) {
                        $this->send($tournament, $player, $state, $kind, $info);
                        $sent++;
                    }
                }
            }
        }

        return $sent;
    }

    /**
     * @param  array{state: 'soon'|'paused', starts: CarbonImmutable, resumes: CarbonImmutable, until: string, resume: string}  $state
     * @param  'night_start'|'night_resume'  $kind
     * @param  array{match: TournamentMatch, opponent: string, url: string, due: CarbonInterface|null, due_kind: 'checkin'|'deadline'|null}  $info
     */
    private function send(Tournament $tournament, User $player, array $state, string $kind, array $info): void
    {
        $locale = $player->locale ?? (string) config('app.locale');
        $time = fn (CarbonInterface $at): string => SeriesPresenter::time($at, $player, 'H:i');
        $due = $info['due'] === null ? '' : ' '.($info['due_kind'] === 'checkin'
            ? __('Check in by :time.', ['time' => $time($info['due'])], $locale)
            : __('Your next deadline: :time.', ['time' => $time($info['due'])], $locale));

        $notice = $kind === 'night_start'
            ? new Notice(
                __('Night pause in :tournament', ['tournament' => $tournament->name], $locale),
                __('No new match starts and no deadline runs until :resume. Your match against :name goes on then.', ['resume' => $time($state['resumes']), 'name' => $info['opponent']], $locale).$due,
                $info['url'], null, __('Open tournament', [], $locale))
            : new Notice(
                __(':tournament goes on in :minutes minutes', ['tournament' => $tournament->name, 'minutes' => max(1, (int) ceil(($state['resumes']->getTimestamp() - now()->getTimestamp()) / 60))], $locale),
                __('Your match against :name goes on at :resume.', ['name' => $info['opponent'], 'resume' => $time($state['resumes'])], $locale).$due,
                $info['url'], null, __('Open match', [], $locale));

        $this->notifier->send($player, NotificationKind::TournamentNews, $notice, immediate: $kind === 'night_resume');
    }

    /**
     * Every open match of a tournament on one day; a casual cup's only with
     * a game or series under way, or a round window that ends before the
     * pause after `$resumes`.
     */
    private static function mattersTonight(Tournament $tournament, TournamentMatch $match, CarbonImmutable $resumes): bool
    {
        if (! $tournament->isCasualCup() || CasualCups::isUnderWay($match)) {
            return true;
        }

        $endsAt = $match->round->window_ends_at;
        $next = NightPause::next(NightPause::windowOf($tournament), $resumes);

        return $endsAt !== null && ($next === null || $endsAt->getTimestamp() <= $next[0]->getTimestamp());
    }

    /**
     * @return array{match: TournamentMatch, opponent: string, url: string, due: CarbonInterface|null, due_kind: 'checkin'|'deadline'|null}|null
     */
    private static function infoFor(Tournament $tournament, TournamentMatch $match, int $userId, CarbonImmutable $resumes): ?array
    {
        $mine = $match->slots->search(fn ($slot): bool => in_array($userId, $slot->participant?->memberIds() ?? [], true));

        if ($mine === false) {
            return null;
        }

        $opponent = (string) ($match->slots->first(fn ($slot, $index): bool => $index !== $mine)?->participant->name ?? '');
        $series = $match->seriesMatch;
        $series = $series !== null && ! $match->isReplaced($series->id) && $series->status->isRunning() ? $series : null;

        if ($series !== null) {
            $next = $series->isCasualPairing() ? $series->casualNextDeadline() : $series->nextDeadline();

            return ['match' => $match, 'opponent' => $opponent, 'url' => route('matches.room', $series), 'due' => $next['at'] ?? null, 'due_kind' => $next === null ? null : 'deadline'];
        }

        // A casual cup's match starts at its agreed time (or the auto slot), not at the restart.
        $due = $tournament->isCasualCup() ? self::cupCheckInBy($tournament, $match) : self::checkInBy($tournament, $resumes);

        return ['match' => $match, 'opponent' => $opponent, 'url' => route('tournaments.show', $tournament), 'due' => $due, 'due_kind' => $due === null ? null : 'checkin'];
    }

    /** A cup series match's check-in close for its agreed time; null without one. */
    private static function cupCheckInBy(Tournament $tournament, TournamentMatch $match): ?CarbonInterface
    {
        $agreed = $tournament->profile()->isSeries() ? CupSchedules::agreedAt($match) : null;

        return $agreed === null ? null : NightPause::addSeconds(NightPause::windowOf($tournament), $agreed, (int) LeagueSettings::get('esports.casual.checkin_after_minutes') * 60);
    }

    /**
     * The check-in of a match the league starts at `$resumes`: a series'
     * lobby check-in on the round clock, a chess or board game's first
     * move; null where nothing is due by a time (Hyperbitcoinization).
     */
    private static function checkInBy(Tournament $tournament, CarbonImmutable $resumes): ?CarbonImmutable
    {
        $profile = $tournament->profile();

        return match (true) {
            $profile->isSeries() => $resumes->addMinutes((int) config('esports.tournaments.auto_noshow_minutes', 30)),
            $profile->isChess() || $profile->isBoard() || $profile->isPong() => $resumes->addSeconds(TournamentDeadlines::checkinSeconds($tournament)),
            default => null,
        };
    }
}
