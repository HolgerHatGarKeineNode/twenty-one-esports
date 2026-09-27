<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Jobs\PublishTournamentCalendar;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Prizes\PrizePool;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Series\Ladders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Publishing a draft opens its sign-up (P8b): the league key signs the
 * tournament as a NIP-52 time-based calendar event (`31923`, NIP
 * "Tournaments") and a new version of the league's calendar (`31924`, `d` =
 * `tournaments`) that lists every published tournament; the calendar, shared
 * by all tournaments, through its one coalescing writer
 * (PublishTournamentCalendar). Both go to the
 * league relays after the commit (LeagueKey::publish, PublishNostrEvent);
 * never to a public relay unless `esports.relays` names one.
 *
 * The events never carry the bracket, the entries, the pool amount or any
 * result: those change during the tournament and stay league data.
 *
 * Fail closed: without the league key nothing is published and the draft
 * stays a draft.
 */
final class TournamentPublisher
{
    /** How far ahead of the clock a tournament's 31923 may be signed ({@see nextSignedAt()}). */
    public const MAX_AHEAD = 2;

    /**
     * @throws TournamentRuleViolation
     */
    public function publish(Tournament $tournament, User $user, CarbonImmutable $signupClosesAt): Tournament
    {
        if (! Gate::forUser($user)->allows('manage-tournament', $tournament)) {
            throw new TournamentRuleViolation('not_manager', __('Only the organizer of this tournament or an admin can publish it.'));
        }

        if ($tournament->status !== TournamentStatus::Draft) {
            throw new TournamentRuleViolation('not_draft', __('This tournament is published already.'));
        }

        if (! $signupClosesAt->isFuture() || $signupClosesAt->greaterThan($tournament->starts_at)) {
            throw new TournamentRuleViolation('deadline', __('Sign-up has to close in the future, at the latest when the tournament starts.'));
        }

        return $this->openSignup($tournament, $signupClosesAt);
    }

    /**
     * Publish without an organizer: the league's own automatic casual cups
     * (P25, CasualCups), which the user approved for public posting. Same
     * events and rules as publish(), in the caller's transaction if any.
     *
     * @throws TournamentRuleViolation without the league key
     */
    public function openSignup(Tournament $tournament, CarbonImmutable $signupClosesAt): Tournament
    {
        $league = LeagueKey::fromConfig()
            ?? throw new TournamentRuleViolation('no_league_key', __('The league key is not set up, so nothing can be published yet.'));

        return DB::transaction(function () use ($tournament, $signupClosesAt, $league): Tournament {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TournamentStatus::Draft) {
                throw new TournamentRuleViolation('not_draft', __('This tournament is published already.'));
            }

            $slug = $locked->slug ?? Str::limit(Str::slug($locked->name), 50, '').'-'.$locked->id;

            // A prize pot set on the draft opens with the first version (P9).
            if ($locked->hasOwnWallet() && $locked->pool_opened_at === null && PrizePool::canOpen()) {
                $locked->forceFill(['pool_opened_at' => now()]);
            }

            $eventAt = $this->nextSignedAt($league, $slug);
            $locked->forceFill([
                'slug' => $slug,
                'signup_closes_at' => $signupClosesAt,
                'status' => TournamentStatus::Signup,
                'published_at' => now(),
                // Frozen with the first version (NIP rev. 7): no open ladder now = unrated for the whole run.
                // A casual cup never freezes one (P25: casual only, no rated Elo).
                'ladder_address' => $locked->isCasualCup() ? null : ($locked->event_id === null ? Ladders::address($locked->game, $locked->mode) : $locked->ladder_address),
            ]);

            $event = $league->publish(Tournament::CALENDAR_EVENT, $this->tags($locked, $league->pubkey()), $this->content($locked), $eventAt);
            $locked->event_id = $event->id;
            $locked->save();

            PublishTournamentCalendar::dispatch();

            return $locked;
        });
    }

    /**
     * A new version of a published tournament after an edit (NIP
     * "Tournaments": a change of time or rules is a new version of the same
     * address), and the league calendar through its job. The `d` never changes; the ladder
     * is the stored one, frozen with the first version (a game or mode
     * correction before the draw re-derives it, TournamentEditor). Runs in
     * the caller's transaction, which holds the lock on the tournament.
     *
     * Each version is signed at least one second after the one it replaces
     * ({@see nextSignedAt()}).
     *
     * The prize pot republishes too (P9): its prizes in `content` when it
     * opens or they change; at its close the `end` moves to the close.
     *
     * @throws TournamentRuleViolation without the league key, or after too many changes in a row
     */
    public function republish(Tournament $locked): void
    {
        if ($locked->event_id === null || $locked->slug === null) {
            return;
        }

        $league = LeagueKey::fromConfig()
            ?? throw new TournamentRuleViolation('no_league_key', __('The league key is not set up, so nothing can be published yet.'));

        $eventAt = $this->nextSignedAt($league, $locked->slug);

        $locked->event_id = $league->publish(Tournament::CALENDAR_EVENT, $this->tags($locked, $league->pubkey()), $this->content($locked), $eventAt)->id;
        $locked->save();

        PublishTournamentCalendar::dispatch();
    }

    /**
     * When the next version of this tournament's own 31923 may be signed:
     * now, and at least one second after the version it replaces (two
     * versions in one second leave the relays to keep the lower id, NIP-01,
     * which may be the old one). The floor is this tournament's alone, so
     * nobody else's edits can refuse it. Never more than MAX_AHEAD seconds
     * in the future; past the cap the change is refused and can be repeated
     * a moment later (the edit page saves one change every 2 s anyway).
     *
     * @throws TournamentRuleViolation
     */
    private function nextSignedAt(LeagueKey $league, string $slug): int
    {
        $now = now()->getTimestamp();
        $previous = (int) NostrEvent::query()->where('pubkey', $league->pubkey())->where('kind', Tournament::CALENDAR_EVENT)->where('d', $slug)->max('signed_at');
        $at = max($now, $previous + 1);

        if ($at > $now + self::MAX_AHEAD) {
            throw new TournamentRuleViolation('too_fast', __('Too many changes in a row. Wait a few seconds and save again.'));
        }

        return $at;
    }

    /**
     * The league calendar lists every published tournament (a new version
     * replaces the old one on the relays). Its only writer, run by the
     * coalescing PublishTournamentCalendar job: serialized by a lock, each
     * version is signed at the current second and after the previous one.
     * When the previous one was signed in this very second, the writer waits
     * for the next second instead of dating the calendar ahead of the clock,
     * so a tournament published later always lands in a newer calendar.
     */
    public function publishCalendar(): ?NostrEvent
    {
        $league = LeagueKey::fromConfig();

        if ($league === null) {
            return null;
        }

        return Cache::lock('tournament-calendar-writer', 30)->block(20, function () use ($league): NostrEvent {
            $previous = (int) NostrEvent::query()->where('pubkey', $league->pubkey())->where('kind', Tournament::CALENDAR)->where('d', 'tournaments')->max('signed_at');

            if ($previous >= now()->getTimestamp()) {
                Sleep::until(CarbonImmutable::createFromTimestamp($previous + 1));
            }

            return $this->signCalendar($league, max(now()->getTimestamp(), $previous + 1));
        });
    }

    private function signCalendar(LeagueKey $league, int $signedAt): NostrEvent
    {
        $tags = [['d', 'tournaments'], ['title', 'TWENTY ONE Esports tournaments']];

        $published = Tournament::query()->whereNotNull('event_id')->whereNotNull('slug')->orderBy('id')->pluck('slug');

        foreach ($published as $slug) {
            $tags[] = ['a', Tournament::CALENDAR_EVENT.':'.$league->pubkey().':'.$slug, ''];
        }

        $tags[] = ['alt', 'Calendar: TWENTY ONE Esports tournaments'];

        return $league->publish(Tournament::CALENDAR, $tags, '', $signedAt);
    }

    /**
     * NIP-52 tags of the tournament (NIP "Tournaments" table): one `D` per UTC
     * day of the timeframe, the ladder `a` only when it was frozen with the
     * first version (rated tournament), and `end` at the pot's close once it
     * closed (P9). No `zap` tag: a tournament pot is its own wallet, never
     * zapped through the league's pool key.
     *
     * @return list<list<string>>
     */
    private function tags(Tournament $tournament, string $league): array
    {
        $start = $tournament->starts_at->getTimestamp();
        $profile = $tournament->profile();
        // A casual cup runs over days of round windows (P25): it ends at the latest after its hard cap.
        $end = $tournament->pool_closed_at?->getTimestamp()
            ?? ($tournament->isCasualCup()
                ? $start + CasualCups::maxDays() * 86400
                : $start + (int) ceil($tournament->plannedDuration() * ($profile->isDaily() ? 86400 : 60)));
        $page = route('tournaments.show', $tournament);
        // NIP-52 has no status for a called-off event (P18): the new version says it in title and summary.
        $calledOff = $tournament->status === TournamentStatus::Cancelled;

        $tags = [
            ['d', (string) $tournament->slug],
            ['title', ($calledOff ? 'Called off: ' : '').$tournament->name],
            ['summary', ($calledOff ? 'Called off. ' : '').$this->summary($tournament)],
            ['start', (string) $start],
            ['end', (string) $end],
            ...array_map(fn (int $day): array => ['D', (string) $day], range(intdiv($start, 86400), intdiv(max($start, $end - 1), 86400))),
            ['start_tzid', (string) config('esports.preseason.display_timezone', 'UTC')],
            ['location', $page],
            ['r', route('rules')],
            ['t', 'esports'],
            ['t', str_replace('-', '', $tournament->game)],
            ['a', Tournament::CALENDAR.':'.$league.':tournaments', ''],
        ];

        if ($tournament->ladder_address !== null) {
            $tags[] = ['a', $tournament->ladder_address, ''];
        }

        $tags[] = ['alt', ($calledOff ? 'Called off tournament: ' : 'Tournament: ').$tournament->name.', '.$tournament->starts_at->utc()->format('Y-m-d H:i').' UTC'];

        return $tags;
    }

    /**
     * The prizes in words (NIP rev. 9): percents of the pot or fixed sats per
     * place, both paid from the tournament's own wallet.
     */
    private function prizes(Tournament $tournament): string
    {
        $fee = PrizePool::WALLET_FEE_PERCENT.' % (at least '.PrizePool::WALLET_FEE_MIN.' sats)';

        if ($tournament->prizeMode() === Tournament::PRIZES_FIXED) {
            $fixed = $tournament->prizeFixed();

            return 'Prizes: '.implode(', ', array_map(fn (int $sats, int $index): string => 'place '.($index + 1).' '.$sats.' sats', $fixed, array_keys($fixed)))
                .', fixed, paid from the tournament\'s own wallet once it holds their sum and '.$fee.' for routing fees; tied places share the sum of their amounts, a team\'s share is split equally among its roster, sats are rounded down and the rest stays in that wallet.';
        }

        $split = $tournament->prizeSplit();

        return 'Prize split: '.implode(', ', array_map(fn (int $percent, int $index): string => 'place '.($index + 1).' '.$percent.' %', $split, array_keys($split)))
            .' of the pot, held in the tournament\'s own wallet and paid from it after '.$fee.' is held back for routing fees; tied places share their percentages, a team\'s share is split equally among its roster, sats are rounded down and the rest stays in that wallet.';
    }

    private function summary(Tournament $tournament): string
    {
        $mode = $tournament->profile()->isChess() ? 'Chess '.($tournament->mode === 'correspondence' ? 'daily' : $tournament->mode) : app(GameRegistry::class)->name($tournament->game).' '.$tournament->mode;

        return $mode.', '.strtolower(str_replace('-', ' ', $tournament->format->value)).'.';
    }

    /**
     * Format, match size, seeding and prize split in words (NIP "Tournaments"),
     * after the organizer's description when there is one.
     */
    private function content(Tournament $tournament): string
    {
        $profile = $tournament->profile();
        $lines = [$this->summary($tournament)];
        $lines[] = $tournament->isCasualCup()
            ? 'A casual cup the league opens on its own: players are seeded at random from the draw\'s block hash, and each round is played within its window; what is not played by the deadline the league decides (whoever tried to play advances, else a visible draw of lots).'
            : ($profile->entersTeams()
            ? 'Clan lineups are seeded by their rating at registration close; the mix teams of the solo draw follow in draw order.'
            : 'Players are seeded by their rating at registration close; equal ratings by who signed up first.');
        $lines[] = $tournament->isDirectorMode()
            ? 'Results are entered by the tournament directors.'
            : 'Players report results and the other side accepts them.';
        $lines[] = match (true) {
            $tournament->ladder_address === null => 'The matches are unrated: no ladder was open when the tournament was published, so they are casual for its whole run.',
            ! $profile->isChess() && ! $tournament->isDirectorMode() => 'A series is rated on the ladder named here if, at its pairing, that ladder is open, the trust gate passes and the two sides are not of one clan; the league signs the pairing, and the rating counts once the other side confirms the result or an admin decides a dispute. Mix teams play casual.',
            default => 'Matches are rated on the ladder named here while it is open and the trust gate passes; otherwise casual.',
        };
        $lines[] = 'Tournament matches never mine season blocks. The prize pool is the tournament\'s own.';

        if ($tournament->pool_opened_at !== null && $tournament->hasOwnWallet()) {
            $lines[] = $this->prizes($tournament);
        }
        $lines[] = 'Page: '.route('tournaments.show', $tournament);
        $rules = implode(' ', $lines);
        $description = trim((string) $tournament->description);

        // The organizer's own words come first, as their own paragraph; the rules follow unchanged.
        $content = $description === '' ? $rules : $description."\n\n".$rules;

        // Called off (P18): said first, in the league's words; the organizer's typed reason stays league data.
        return $tournament->status === TournamentStatus::Cancelled
            ? "This tournament was called off by the league. Its open matches are closed, and nothing is rated after the call-off.\n\n".$content
            : $content;
    }
}
