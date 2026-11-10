<?php

namespace App\Support\Tournaments;

use App\Enums\NotificationKind;
use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\LeagueTime;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\PreSeason;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The start vote of a one-day tournament (DerCaddy, 2026-11-09: „Blitz und
 * Rapid Turniere, in denen die Mitspieler bis zu einem bestimmten Tag für
 * den Tag und die Uhrzeit abstimmen können“, the lean form Claude proposed):
 *
 * - the organizer offers two to four starts and a close for the vote on the
 *   edit page (TournamentEditor). Until the vote is settled `starts_at` is the
 *   earliest of them, so every rule about the start (sign-up closes at the
 *   latest then, the calendar's `start`) holds for each offered start;
 * - each entry ticks every start it can make, until the vote closes: the
 *   person who may pull the entry out votes for it (the solo player, a
 *   lineup's captain). One entry, one voice; a withdrawn entry's voice drops;
 * - an entry that has not voted counts for the EARLIEST offered start: the one
 *   it signed up and consented to (user decision 2026-11-10). An entry that
 *   ticked starts and then unticked all of them (`start_fits = []`), or whose
 *   ticks were all taken out of the offer since, has no tick left and counts
 *   the same: it never said the earliest start does not suit it;
 * - when the vote closes ({@see settleDue()}, `tournaments:tick`), the start
 *   with the most entries that can make it wins, a tie goes to the earlier
 *   one. The league sets it, publishes the new version of the tournament
 *   (a change of time is a new version, NIP "Tournaments"), logs it and tells
 *   every player how many entries can make it. Every entry whose ticks do not
 *   include the winning start (a non-voter's: the earliest) hears that it can
 *   pull out until sign-up closes: the vote closes at least {@see GAP_MINUTES}
 *   before that, so the draw always runs on the settled start.
 *
 * The vote is league data like the entries: never published, never signed.
 * Only an online tournament of a minute game has it (Blitz, Rapid and every
 * other game played on one evening): a casual cup has its round windows,
 * correspondence its days, a tournament on site its venue's date.
 */
final class TournamentStartVote
{
    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 4;

    /** The vote closes at least this long before sign-up does: time to pull out for whoever cannot make the start. */
    public const GAP_MINUTES = 60;

    /** How an offered start is stored (Tournament::$start_options, TournamentSignup::$start_fits): UTC, to the minute. */
    public const FORMAT = 'Y-m-d H:i';

    /** The reason of the league's log line when it settles a vote. */
    public const SETTLED = 'The start vote closed: the start most entries can make.';

    public function __construct(private TournamentPublisher $publisher, private TournamentModeration $moderation, private TournamentSignups $signups, private Notifier $notifier) {}

    /**
     * Whether the tournament can put its start to a vote: online, a minute
     * game, not a casual cup (TournamentDeadlines::isSingleDay()), and none of
     * the league's own weeks or a score window.
     */
    public static function offers(Tournament $tournament): bool
    {
        return TournamentDeadlines::isSingleDay($tournament) && ! $tournament->isLeagueWeek() && ! $tournament->profile()->isScore();
    }

    /** A moment as an offered start is stored. */
    public static function key(CarbonInterface $at): string
    {
        return $at->toImmutable()->utc()->format(self::FORMAT);
    }

    /** An offered start as a moment. */
    public static function at(string $key): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!'.self::FORMAT, $key, 'UTC') ?: throw new \InvalidArgumentException("Not an offered start: {$key}");
    }

    /**
     * The offered starts, earliest first; empty without a vote.
     *
     * @return list<CarbonImmutable>
     */
    public static function options(Tournament $tournament): array
    {
        return array_map(self::at(...), $tournament->start_options ?? []);
    }

    /**
     * Distinct starts as stored, earliest first.
     *
     * @param  list<CarbonInterface>  $starts
     * @return list<string>
     */
    public static function normalize(array $starts): array
    {
        $keys = array_values(array_unique(array_map(self::key(...), $starts)));
        sort($keys);

        return $keys;
    }

    /**
     * The start as an outward channel names it (stream notes, the sign-up
     * page, the stream's slides): one stamp, or while the vote is open every
     * offered start and when the vote closes, so no channel announces the
     * earliest offered start as THE start.
     */
    public static function startLine(Tournament $tournament, ?string $zone = null, ?string $locale = null): string
    {
        if (! $tournament->hasOpenStartVote()) {
            return LeagueTime::stamp($tournament->starts_at, $zone, $locale);
        }

        return __('by vote: :options, vote closes :close', [
            'options' => implode(' / ', array_map(fn (CarbonImmutable $at): string => LeagueTime::stamp($at, $zone, $locale), self::options($tournament))),
            'close' => LeagueTime::stamp($tournament->start_vote_until ?? $tournament->starts_at, $zone, $locale),
        ], $locale);
    }

    /** Entries tick their starts now: sign-up is open, the vote is not settled and has not closed. */
    public static function isOpen(Tournament $tournament): bool
    {
        return $tournament->hasOpenStartVote() && $tournament->isSignupOpen()
            && $tournament->start_vote_until !== null && $tournament->start_vote_until->isFuture();
    }

    /**
     * The rules an offered vote has to meet, with the tournament as it will
     * be saved (TournamentEditor) or published (TournamentPublisher).
     *
     * @param  list<string>  $options  normalized ({@see normalize()})
     *
     * @throws TournamentRuleViolation
     */
    public static function assertValid(Tournament $tournament, array $options, ?CarbonInterface $until, ?CarbonInterface $signupClosesAt): void
    {
        if (! self::offers($tournament)) {
            throw new TournamentRuleViolation('start_vote_unavailable', __('Only an online tournament of a game played on one evening can put its start to a vote.'));
        }

        if (count($options) < self::MIN_OPTIONS || count($options) > self::MAX_OPTIONS) {
            throw new TournamentRuleViolation('start_vote_options', __('Offer two to four different starts for the vote.'));
        }

        foreach ($options as $option) {
            if (! self::at($option)->isFuture()) {
                throw new TournamentRuleViolation('start_vote_options', __('Every start you offer has to be in the future.'));
            }
        }

        if ($until === null || ! $until->isFuture()) {
            throw new TournamentRuleViolation('start_vote_until', __('The vote has to close in the future.'));
        }

        self::assertGap($until, $signupClosesAt ?? self::at($options[0]));
    }

    /**
     * The vote closes at least GAP_MINUTES before sign-up does (or, in a
     * draft, before the earliest offered start).
     *
     * @throws TournamentRuleViolation
     */
    public static function assertGap(CarbonInterface $until, CarbonInterface $signupClosesAt): void
    {
        if ($until->greaterThan($signupClosesAt->toImmutable()->subMinutes(self::GAP_MINUTES))) {
            throw new TournamentRuleViolation('start_vote_until', __('The vote has to close at least an hour before sign-up closes, so whoever cannot make the chosen start can still pull out.'));
        }
    }

    /**
     * The entry this user votes for: the one they may pull out (their solo
     * entry, or the lineup they captain); null for anyone else.
     */
    public function entryOf(Tournament $tournament, ?User $user): ?TournamentSignup
    {
        if ($user === null || $tournament->start_options === null) {
            return null;
        }

        $signup = $this->signups->entryOf($tournament, $user);

        return $signup !== null && ($signup->isSolo() || ($signup->lineup?->isActingCaptain($user) ?? false)) ? $signup : null;
    }

    /**
     * Tick or untick one offered start for the user's entry; the entry's
     * starts after it.
     *
     * @return list<string>
     *
     * @throws TournamentRuleViolation
     */
    public function toggle(Tournament $tournament, User $user, string $option): array
    {
        return DB::transaction(function () use ($tournament, $user, $option): array {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if (! self::isOpen($locked)) {
                throw new TournamentRuleViolation('start_vote_closed', __('The vote on the start has closed.'));
            }

            if (! in_array($option, $locked->start_options ?? [], true)) {
                throw new TournamentRuleViolation('start_vote_option', __('This start is not offered (any more). Reload the page.'));
            }

            $entry = $this->entryOf($locked, $user);

            if ($entry === null) {
                throw new TournamentRuleViolation('start_vote_entry', __('Only entries vote on the start: the player of a solo entry, the captain for a lineup.'));
            }

            // Only the starts still offered: one the organizer took out since drops on the next tick.
            $fits = array_values(array_intersect($entry->start_fits ?? [], $locked->start_options ?? []));
            $fits = in_array($option, $fits, true) ? array_values(array_diff($fits, [$option])) : [...$fits, $option];
            sort($fits);

            $entry->forceFill(['start_fits' => $fits])->save();

            return $fits;
        });
    }

    /**
     * The offered starts an entry can make: its ticks still on offer, or,
     * without one (never voted, unticked all, or its starts were taken out),
     * the earliest offered start, the one it signed up for.
     *
     * @param  list<string>  $options  normalized ({@see normalize()})
     * @return list<string>
     */
    public static function fitsOf(TournamentSignup $signup, array $options): array
    {
        $fits = array_values(array_intersect($signup->start_fits ?? [], $options));

        return $fits !== [] || $options === [] ? $fits : [$options[0]];
    }

    /**
     * Whether the entry has a tick on a start still offered (else it counts for the earliest).
     *
     * @param  list<string>  $options
     */
    public static function hasVoted(TournamentSignup $signup, array $options): bool
    {
        return array_intersect($signup->start_fits ?? [], $options) !== [];
    }

    /**
     * Per offered start the entries that can make it (an entry without a
     * tick counts for the earliest), the entries that ticked a start and all
     * active entries.
     *
     * @return array{counts: array<string, int>, voted: int, entries: int}
     */
    public static function tally(Tournament $tournament): array
    {
        $options = $tournament->start_options ?? [];
        $counts = array_fill_keys($options, 0);
        $voted = 0;
        $entries = 0;

        foreach (TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->get(['id', 'start_fits']) as $signup) {
            $entries++;
            $voted += self::hasVoted($signup, $options) ? 1 : 0;

            foreach (self::fitsOf($signup, $options) as $fit) {
                $counts[$fit]++;
            }
        }

        return ['counts' => $counts, 'voted' => $voted, 'entries' => $entries];
    }

    /**
     * The start that wins: most entries that can make it, the earlier on a
     * tie (so with no vote at all the earliest). Only a start still ahead.
     *
     * @param  array<string, int>  $counts
     */
    public static function winner(array $counts): ?string
    {
        $best = null;

        // Earliest first (normalize()): a later start wins only with more entries.
        foreach ($counts as $option => $count) {
            if (self::at((string) $option)->isFuture() && ($best === null || $count > $counts[$best])) {
                $best = (string) $option;
            }
        }

        return $best;
    }

    /**
     * Settle every vote that has closed (`tournaments:tick`, before sign-ups
     * close: TournamentDraws::advanceDue()). One that fails is reported and
     * the others go on; the next tick tries it again.
     */
    public function settleDue(): int
    {
        $settled = 0;

        $due = Tournament::query()->whereNotNull('start_options')->whereNull('start_decided_at')
            ->where('status', TournamentStatus::Signup)->where('start_vote_until', '<=', now())->orderBy('id')->get();

        foreach ($due as $tournament) {
            try {
                $settled += $this->settle($tournament) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $settled;
    }

    /**
     * Set the winning start, publish it and tell the players; once.
     */
    public function settle(Tournament $tournament): bool
    {
        $told = DB::transaction(function () use ($tournament): ?array {
            $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($tournament->id);

            if (! $locked->hasOpenStartVote() || $locked->status !== TournamentStatus::Signup
                || $locked->start_vote_until === null || $locked->start_vote_until->isFuture()) {
                return null;
            }

            $tally = self::tally($locked);
            $winner = self::winner($tally['counts']);
            $old = $locked->starts_at->toImmutable();
            $start = $winner === null ? $old : self::at($winner);

            $locked->forceFill(['starts_at' => $start, 'start_decided_at' => now()])->save();

            $this->moderation->logByLeague($locked, 'start_voted', $locked->name, self::SETTLED, [
                'starts_at' => [self::key($old), self::key($start)],
                'start_votes' => [null, array_map(fn (string $option, int $count): string => $option.' UTC: '.$count, array_keys($tally['counts']), array_values($tally['counts']))],
            ]);

            // Always a new version: the one out there said the start is put to a vote (TournamentPublisher::content()),
            // and a moved start is a new version of the same address anyway (NIP "Tournaments").
            $this->publisher->republish($locked);

            Broadcasts::send(new TournamentChanged($locked->id, 'edited'));

            return [$locked, $tally];
        });

        if ($told === null) {
            return false;
        }

        [$settled, $tally] = $told;
        $this->tell($settled, $tally);

        return true;
    }

    /**
     * Whether the settled start is not among the entry's ticks (a non-voter's:
     * the earliest offered start), so it may want to pull out.
     */
    public static function missedBy(Tournament $tournament, TournamentSignup $signup): bool
    {
        $options = $tournament->start_options ?? [];

        return $options !== [] && ! in_array(self::key($tournament->starts_at), self::fitsOf($signup, $options), true);
    }

    /**
     * One tournament news per player of every active entry, in their own
     * language and zone: the start that won and how many entries can make it,
     * and for an entry whose ticks do not include it (a non-voter's: the
     * earliest start), that it can pull out until sign-up closes; that hint
     * only while sign-up is still open.
     *
     * @param  array{counts: array<string, int>, voted: int, entries: int}  $tally
     */
    private function tell(Tournament $tournament, array $tally): void
    {
        $signups = TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->orderBy('id')->get();
        $told = [];
        $canMake = $tally['counts'][self::key($tournament->starts_at)] ?? 0;
        $closes = $tournament->signup_closes_at;
        $canPullOut = $closes !== null && $closes->isFuture();

        foreach ($signups as $signup) {
            $cannot = $canPullOut && self::missedBy($tournament, $signup);

            foreach (User::query()->whereKey(TournamentModeration::entrants($signup))->get() as $player) {
                if (isset($told[$player->id])) {
                    continue;
                }

                $told[$player->id] = true;
                $locale = $player->locale ?? (string) config('app.locale');
                $zone = PreSeason::timezoneFor($player);
                $start = LeagueTime::stamp($tournament->starts_at, $zone, $locale);

                try {
                    $this->notifier->send($player, NotificationKind::TournamentNews, new Notice(
                        __(':tournament starts :time', ['tournament' => $tournament->name, 'time' => $start], $locale),
                        trans_choice('The vote on the start is over: :count of :entries entries can make this start.|The vote on the start is over: :count of :entries entries can make this start.', $canMake, ['count' => $canMake, 'entries' => $tally['entries']], $locale)
                            .($cannot ? ' '.__('Your entry did not tick it. If you cannot play then, withdraw it by :close; otherwise you play as planned.', ['close' => LeagueTime::stamp($closes, $zone, $locale)], $locale) : ''),
                        route($cannot ? 'tournaments.signup' : 'tournaments.show', $tournament),
                        null,
                        __('Open tournament', [], $locale),
                    ));
                } catch (Throwable $e) {
                    // One player's notice never keeps the others from theirs; the start is set either way.
                    report($e);
                }
            }
        }
    }
}
