<?php

namespace App\Livewire\Concerns;

use App\Models\Tournament;
use App\Support\LeagueTime;
use App\Support\Tournaments\TournamentStartVote;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;

/**
 * The start vote's fields (TournamentStartVote) on the create page and the
 * edit page (DerCaddy, 2026-11-11: „Schöner wäre, wenn ich die Auswahl
 * direkt zu Beginn bei der Erstellung des Turniers treffen kann“): the check,
 * the 2nd to 4th offered start (the start field is the 1st) and the close of
 * the vote, in the league's zone. Deferred like every field of these pages;
 * the view is pages.admin.partials.tournament-start-vote.
 */
trait EditsStartVote
{
    /** The entries vote on the start: the start field and up to three more. */
    public bool $voteStart = false;

    /** @var list<string> the other offered starts as `Y-m-d\TH:i` in the league's zone; '' = not offered */
    public array $startOptions = ['', '', ''];

    /** The close of the vote as `Y-m-d\TH:i` in the league's zone. */
    public string $voteUntil = '';

    /**
     * The fields as stored: the offered starts after the first (that one is the start, the earliest) and the close;
     * without a vote, a close two days before the start to begin with.
     */
    protected function fillStartVote(?Tournament $tournament, CarbonInterface $startsAt): void
    {
        $this->voteStart = $tournament?->hasOpenStartVote() ?? false;
        $others = $this->voteStart ? array_slice(TournamentStartVote::options($tournament), 1) : [];
        $this->startOptions = array_pad(array_map(LeagueTime::input(...), $others), TournamentStartVote::MAX_OPTIONS - 1, '');
        $this->voteUntil = LeagueTime::input($tournament?->start_vote_until ?? $startsAt->copy()->subDays(2));
    }

    /**
     * The rules of the fields while the check is on.
     *
     * @return array<string, mixed>
     */
    protected function startVoteRules(?CarbonInterface $storedUntil = null): array
    {
        if (! $this->voteStart) {
            return [];
        }

        return [
            'startOptions.*' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (trim((string) $value) !== '') {
                    LeagueTime::rule()($attribute, trim((string) $value), $fail);
                }
            }],
            'voteUntil' => ['required', LeagueTime::rule($storedUntil)],
        ];
    }

    /**
     * The vote as it will be saved (TournamentStartVote checks it): the start and the other offered starts, earliest
     * first; the start becomes the earliest of them. With the check off, no vote.
     *
     * @return array{starts_at: CarbonImmutable, start_options: list<string>|null, start_vote_until: CarbonImmutable|null}
     */
    protected function chosenStartVote(CarbonImmutable $startsAt, ?Tournament $tournament = null): array
    {
        if (! $this->voteStart) {
            return ['starts_at' => $startsAt, 'start_options' => null, 'start_vote_until' => null];
        }

        $stored = $tournament === null ? [] : TournamentStartVote::options($tournament);
        $starts = [$startsAt];

        foreach ($this->startOptions as $typed) {
            if (($typed = trim((string) $typed)) !== '') {
                // An unchanged value keeps its stored instant (LeagueTime::parse), as the start does.
                $starts[] = LeagueTime::parse($typed, collect($stored)->first(fn (CarbonImmutable $at): bool => LeagueTime::input($at) === $typed));
            }
        }

        usort($starts, fn (CarbonInterface $a, CarbonInterface $b): int => $a <=> $b);

        return [
            'starts_at' => $starts[0],
            'start_options' => TournamentStartVote::normalize($starts),
            'start_vote_until' => LeagueTime::parse($this->voteUntil, $tournament?->start_vote_until),
        ];
    }
}
