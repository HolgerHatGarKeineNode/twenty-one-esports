<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Series\Ladders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Publishing a draft opens its sign-up (P8b): the league key signs the
 * tournament as a NIP-52 time-based calendar event (`31923`, NIP
 * "Tournaments") and a new version of the league's calendar (`31924`, `d` =
 * `tournaments`) that lists every published tournament. Both go to the
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

        $league = LeagueKey::fromConfig()
            ?? throw new TournamentRuleViolation('no_league_key', __('The league key is not set up, so nothing can be published yet.'));

        return DB::transaction(function () use ($tournament, $signupClosesAt, $league): Tournament {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TournamentStatus::Draft) {
                throw new TournamentRuleViolation('not_draft', __('This tournament is published already.'));
            }

            $now = now()->getTimestamp();
            $locked->forceFill([
                'slug' => $locked->slug ?? Str::limit(Str::slug($locked->name), 50, '').'-'.$locked->id,
                'signup_closes_at' => $signupClosesAt,
                'status' => TournamentStatus::Signup,
                'published_at' => now(),
                // Frozen with the first version (NIP rev. 7): no open ladder now = unrated for the whole run.
                'ladder_address' => $locked->event_id === null ? Ladders::address($locked->game, $locked->mode) : $locked->ladder_address,
            ]);

            $event = $league->publish(Tournament::CALENDAR_EVENT, $this->tags($locked, $league->pubkey()), $this->content($locked), $now);
            $locked->event_id = $event->id;
            $locked->save();

            $this->publishCalendar($league, $now);

            return $locked;
        });
    }

    /**
     * The league calendar lists every published tournament (a new version
     * replaces the old one on the relays).
     */
    private function publishCalendar(LeagueKey $league, int $now): void
    {
        $tags = [['d', 'tournaments'], ['title', 'TWENTY ONE Esports tournaments']];

        $published = Tournament::query()->whereNotNull('event_id')->whereNotNull('slug')->orderBy('id')->pluck('slug');

        foreach ($published as $slug) {
            $tags[] = ['a', Tournament::CALENDAR_EVENT.':'.$league->pubkey().':'.$slug, ''];
        }

        $tags[] = ['alt', 'Calendar: TWENTY ONE Esports tournaments'];

        $league->publish(Tournament::CALENDAR, $tags, '', $now);
    }

    /**
     * NIP-52 tags of the tournament (NIP "Tournaments" table): one `D` per UTC
     * day of the timeframe, the ladder `a` only when it was frozen with the
     * first version (rated tournament); the pool's `zap` follows with P9.
     *
     * @return list<list<string>>
     */
    private function tags(Tournament $tournament, string $league): array
    {
        $start = $tournament->starts_at->getTimestamp();
        $profile = $tournament->profile();
        $end = $start + (int) ceil($tournament->plannedDuration() * ($profile->isDaily() ? 86400 : 60));
        $page = route('tournaments.show', $tournament);

        $tags = [
            ['d', (string) $tournament->slug],
            ['title', $tournament->name],
            ['summary', $this->summary($tournament)],
            ['start', (string) $start],
            ['end', (string) $end],
            ...array_map(fn (int $day): array => ['D', (string) $day], range(intdiv($start, 86400), intdiv(max($start, $end - 1), 86400))),
            ['start_tzid', (string) config('esports.preseason.display_timezone', 'UTC')],
            ['location', $page],
            ['r', route('rules')],
            ['t', 'esports'],
            ['t', $profile->isChess() ? 'chess' : 'rocketleague'],
            ['a', Tournament::CALENDAR.':'.$league.':tournaments', ''],
        ];

        if ($tournament->ladder_address !== null) {
            $tags[] = ['a', $tournament->ladder_address, ''];
        }

        $tags[] = ['alt', 'Tournament: '.$tournament->name.', '.$tournament->starts_at->utc()->format('Y-m-d H:i').' UTC'];

        return $tags;
    }

    private function summary(Tournament $tournament): string
    {
        $mode = $tournament->profile()->isChess() ? 'Chess '.($tournament->mode === 'correspondence' ? 'daily' : $tournament->mode) : 'Rocket League '.$tournament->mode;

        return $mode.', '.strtolower(str_replace('-', ' ', $tournament->format->value)).'.';
    }

    /**
     * Format, match size, seeding and prize split in words (NIP "Tournaments").
     */
    private function content(Tournament $tournament): string
    {
        $profile = $tournament->profile();
        $lines = [$this->summary($tournament)];
        $lines[] = $profile->entersTeams()
            ? 'Clan lineups are seeded by their rating at registration close; the mix teams of the solo draw follow in draw order.'
            : 'Players are seeded by their rating at registration close; equal ratings by who signed up first.';
        $lines[] = $tournament->isDirectorMode()
            ? 'Results are entered by the tournament directors.'
            : 'Players report results and the other side accepts them.';
        $lines[] = match (true) {
            $tournament->ladder_address === null => 'The matches are unrated: no ladder was open when the tournament was published, so they are casual for its whole run.',
            ! $profile->isChess() && ! $tournament->isDirectorMode() => 'The matches are casual for now: Rocket League series reported by the players are not rated yet.',
            default => 'Matches are rated on the ladder named here while it is open and the trust gate passes; otherwise casual.',
        };
        $lines[] = 'Tournament matches never mine season blocks. The prize pool is the tournament\'s own.';
        $lines[] = 'Page: '.route('tournaments.show', $tournament);

        return implode(' ', $lines);
    }
}
