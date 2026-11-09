<?php

use App\Enums\InviteLinkType;
use App\Enums\SeriesStatus;
use App\Games\GameRegistry;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Chess\ChessTeamMatches;
use App\Support\FairPlay\FairPlay;
use App\Support\Formats;
use App\Support\GameNames;
use App\Support\Invites\InviteLinkRefused;
use App\Support\Invites\InviteLinks;
use App\Support\Nostr\RejectedEvent;
use App\Support\PreSeason;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\Ladders;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\GameProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Challenge a clan: the five-step wizard of ChallengeCreate.dc.html (plan "Canvas and live alignment", P4), one step
 * on screen at a time at every width: 1 lineup, 2 opponent (or an invite link instead), 3 rated or casual and the
 * format, 4 suggested times and the reply deadline, 5 the summary and send. "Next" validates the step on the server
 * (`next()`, only that step's rules) before the following one opens; "Back" and the done steps of the stepper go
 * back with every value kept. Sending validates every step again and goes through the same SeriesService path as
 * before, so its rules (clan captain only, ready lineups, rated gates) still decide.
 *
 * Before Block 0 every series is casual: "Rated" is shown disabled with the Block 0 countdown. A chess `rapid`
 * lineup challenges to a clan team match (NIP rev. 9.22, plan "Schach Rapid und Clan", P4): 2 or 3 boards instead
 * of a series length, rated or friendly, and the reply due before the lineup lock.
 *
 * Dates are typed, not picked from the browser's native field: that one shows the browser's locale ("mm/dd/yyyy"
 * on an en-US browser) inside a German page (R2). A date reads "17.10.2026" in German, "17 Oct 2026" in English;
 * both, "17.10." and "2026-10-17" are understood either way. Times are 24-hour "20:00".
 */
new #[Title('New challenge')] #[Layout('layouts::app', ['section' => 'games'])] class extends Component {
    #[Url(as: 'lineup', except: null)]
    public ?int $lineupId = null;

    #[Url(as: 'to', except: null)]
    public ?int $opponentId = null;

    /** Preselects a lineup of this game (the "Challenge a clan" button of a game page). */
    #[Url(as: 'game', except: null)]
    public ?string $game = null;

    /** The step on screen, 1 to 5; it moves only through next(), back() and backTo(). */
    #[Locked]
    public int $step = 1;

    /** No opponent picked: the challenge becomes an invite link at the end (the board's "Per Link einladen"). */
    public bool $viaLink = false;

    public bool $rated = false;

    public int $bestOf = 3;

    /** A chess team match: the number of boards, 2 or 3 (the challenger picks, user 2026-10-05). */
    public int $boards = 2;

    public bool $now = false;

    /** @var list<array{date: string, time: string}> */
    public array $times = [];

    public string $replyDate = '';

    public string $replyTime = '';

    public string $search = '';

    public string $error = '';

    public function mount(): void
    {
        $zone = $this->zone();
        $first = CarbonImmutable::now($zone)->addDay()->setTime(20, 0);
        $this->times = [['date' => $this->showDate($first), 'time' => $first->format('H:i')]];
        $reply = $first->subHours(2);
        $this->replyDate = $this->showDate($reply);
        $this->replyTime = $reply->format('H:i');

        $mine = $this->myLineups;

        if ($this->lineupId === null || ! $mine->contains('id', $this->lineupId)) {
            $this->lineupId = $mine->firstWhere('game', $this->game)?->id ?? $mine->first()?->id;
        }

        $this->fitBestOf();
    }

    /** Validates the step on screen and opens the next one. */
    public function next(): void
    {
        $this->error = '';
        $this->validateStep($this->step);
        $this->step = min(5, $this->step + 1);
    }

    /** One step back; every value stays. */
    public function back(): void
    {
        $this->error = '';
        $this->resetErrorBag();
        $this->step = max(1, $this->step - 1);
    }

    /** A done step of the stepper: back to it, never ahead of the step on screen. */
    public function backTo(int $step): void
    {
        if ($step >= 1 && $step < $this->step) {
            $this->error = '';
            $this->resetErrorBag();
            $this->step = $step;
        }
    }

    /**
     * Only the rules of one step (the board's step 1 to 4; step 5 sends).
     *
     * @throws ValidationException
     */
    private function validateStep(int $step): void
    {
        match ($step) {
            1 => $this->validate(['lineupId' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                $lineup = $this->myLineups->firstWhere('id', (int) $value);
                if ($lineup === null) {
                    $fail(__('Pick your lineup first.'));
                } elseif (! $lineup->isReady()) {
                    $fail(__('This lineup is not complete yet: fill its seats on your clan page first.'));
                }
            }]], ['lineupId.required' => __('Pick your lineup first.')]),
            2 => $this->validate(['opponentId' => [$this->viaLink ? 'nullable' : 'required', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                $row = $this->opponents->first(fn (array $row): bool => $row['lineup']->id === (int) $value);
                if ($row === null) {
                    $fail(__('Pick an opponent, or invite one by link.'));
                } elseif ($row['state'] !== 'ok') {
                    $fail(__(':clan cannot be challenged right now: :reason.', ['clan' => $row['lineup']->clan->name, 'reason' => $row['note']]));
                }
            }]], ['opponentId.required' => __('Pick an opponent, or invite one by link.')]),
            3 => $this->validate([
                'rated' => ['boolean', function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value && ! $this->ratedOpen()) {
                        $fail(__('Rated play is not open for this lineup yet.'));
                    } elseif ($value && $this->viaLink) {
                        $fail(__('Invite links are for casual matches. Switch to casual to make one.'));
                    }
                }],
                'bestOf' => ['integer', function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->isTeamMatch() && ! in_array((int) $value, $this->bestOfs(), true)) {
                        $fail(__('Pick a format.'));
                    }
                }],
                'boards' => ['integer', function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->isTeamMatch() && ! in_array((int) $value, $this->lineup?->gameMode()->boards ?? [], true)) {
                        $fail(__('Pick a format.'));
                    }
                }],
            ]),
            4 => $this->validateTimes(),
            default => null,
        };
    }

    /**
     * Step 4: every suggested time a date and a time, the reply deadline too, before the first suggested time and
     * after now. "Challenge now" needs none of it.
     *
     * @throws ValidationException
     */
    private function validateTimes(): void
    {
        if ($this->now) {
            return;
        }

        $zone = $this->zone();
        $rules = [];

        foreach (array_keys($this->times) as $index) {
            $rules["times.{$index}.date"] = ['required', fn (string $attribute, mixed $value, Closure $fail) => $this->dateOf((string) $value) === null ? $fail(__('Type a date like :example.', ['example' => $this->showDate(CarbonImmutable::now($zone)->addDay())])) : null];
            $rules["times.{$index}.time"] = ['required', fn (string $attribute, mixed $value, Closure $fail) => $this->timeOf((string) $value) === null ? $fail(__('Type a time like 20:00.')) : null];
        }

        $rules['replyDate'] = ['required', fn (string $attribute, mixed $value, Closure $fail) => $this->dateOf((string) $value) === null ? $fail(__('Type a date like :example.', ['example' => $this->showDate(CarbonImmutable::now($zone)->addDay())])) : null];
        $rules['replyTime'] = ['required', function (string $attribute, mixed $value, Closure $fail) use ($zone): void {
            if ($this->timeOf((string) $value) === null) {
                $fail(__('Type a time like 20:00.'));

                return;
            }

            $reply = $this->parse($this->replyDate, (string) $value, $zone);
            $starts = array_filter(array_map(fn (array $row): ?int => $this->parse($row['date'], $row['time'], $zone), $this->times));

            if ($reply !== null && $reply <= now()->getTimestamp()) {
                $fail(__('The reply deadline has to be in the future.'));
            } elseif ($reply !== null && $starts !== [] && $reply >= min($starts)) {
                $fail(__('The reply deadline has to be before the first suggested time.'));
            }
        }];

        $messages = ['required' => __('Every suggested time needs a date and a time.'), 'replyDate.required' => __('Set a reply deadline.'), 'replyTime.required' => __('Set a reply deadline.')];

        $this->validate($rules, $messages);
    }

    public function pickLineup(int $id): void
    {
        $this->lineupId = $id;
        $this->opponentId = null;
        $this->resetErrorBag('lineupId');
        unset($this->lineup, $this->opponents);
        $this->fitBestOf();
    }

    /** Keeps the series length one the picked lineup's mode allows (RL Bo3/Bo5, FC Bo1/Bo3). */
    private function fitBestOf(): void
    {
        $allowed = $this->bestOfs();

        if ($allowed !== [] && ! in_array($this->bestOf, $allowed, true)) {
            $this->bestOf = in_array(3, $allowed, true) ? 3 : $allowed[0];
        }
    }

    /** @return list<int> */
    private function bestOfs(): array
    {
        return $this->lineup?->gameMode()->bestOf ?? [];
    }

    public function pickOpponent(int $id): void
    {
        $this->opponentId = $id;
        $this->viaLink = false;
        $this->error = '';
        $this->resetErrorBag('opponentId');
    }

    /** "No opponent here yet? Invite by link": on to the format and the times, the link is made at the end. */
    public function inviteByLink(): void
    {
        $this->error = '';

        if ($this->isTeamMatch()) {
            $this->error = __('A team match goes to a clan you pick. Invite links are for series.');

            return;
        }

        $this->viaLink = true;
        $this->opponentId = null;
        $this->rated = false;
        $this->resetErrorBag('opponentId');
        $this->step = 3;
    }

    /** P57: an add from the "you do not list each other" notice; the refusal it answered is gone. */
    #[On('opponent-list-changed')]
    public function opponentListChanged(): void
    {
        $this->error = '';
    }

    public function addTime(): void
    {
        if (count($this->times) < 3) {
            $last = end($this->times) ?: ['date' => '', 'time' => ''];
            $this->times[] = ['date' => $last['date'], 'time' => $last['time']];
        }
    }

    public function removeTime(int $index): void
    {
        if (count($this->times) > 1) {
            unset($this->times[$index]);
            $this->times = array_values($this->times);
            $this->resetErrorBag();
        }
    }

    /**
     * Every step again, in order: a step that no longer holds (a lineup emptied meanwhile) is opened with its error.
     */
    private function stepsHold(): bool
    {
        foreach ([1, 2, 3, 4] as $step) {
            try {
                $this->validateStep($step);
            } catch (ValidationException $invalid) {
                $this->step = $step;
                $this->setErrorBag($invalid->validator->errors());

                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareSend(): ?array
    {
        if (! $this->stepsHold()) {
            return null;
        }

        $draft = $this->draft();

        if ($draft === null) {
            return null;
        }

        try {
            return app(SeriesService::class)->prepareChallenge($this->user(), $draft)['templates'];
        } catch (SeriesRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return null;
        }
    }

    public function send(string $signed): void
    {
        $draft = $this->draft();

        if ($draft === null) {
            return;
        }

        try {
            $events = json_decode($signed, true);
            $match = app(SeriesService::class)->challenge($this->user(), $draft, is_array($events) && array_is_list($events) ? $events : [['malformed']]);
        } catch (SeriesRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return;
        } catch (RejectedEvent) {
            $this->error = __('The signed event was refused. Please try again.');

            return;
        }

        session()->flash('status', __('Challenge :number sent to :clan.', ['number' => $match->label(), 'clan' => $match->challenged_name]));
        $this->redirectRoute('matches.room', $match);
    }

    private function draft(): ?ChallengeDraft
    {
        $this->error = '';

        if ($this->lineupId === null || $this->opponentId === null) {
            $this->error = __('Pick your lineup and an opponent first.');

            return null;
        }

        $schedule = $this->schedule();

        return $schedule === null ? null : new ChallengeDraft($this->lineupId, $this->opponentId, $this->bestOf, $this->rated, $schedule[0], $schedule[1], boards: $this->isTeamMatch() ? $this->boards : null);
    }

    /** The picked lineup plays clan team matches over boards (chess rapid). */
    private function isTeamMatch(): bool
    {
        return $this->lineup !== null && app(GameRegistry::class)->isTeamMatchMode($this->lineup->game, $this->lineup->mode);
    }

    /**
     * Rated is open for the picked lineup: its ladder runs (after Block 0), trust ranks exist (RatedTrustGate), and
     * fair play does not hold the player back (P41).
     */
    private function ratedOpen(): bool
    {
        $lineup = $this->lineup;
        $user = $this->user();

        return $lineup !== null && Ladders::isOpen($lineup->game, $lineup->mode) && app(RatedTrustGate::class)->isAvailable() && FairPlay::message($user->pubkey, $user) === null;
    }

    /**
     * Invite by link (P6b): the same challenge without an opponent. Whoever
     * opens the link and captains a ready lineup of this mode takes it; the
     * match number is reserved then, not now. Casual only: a rated challenge
     * names the other captains in its signed event.
     */
    public function createLink(InviteLinks $links): void
    {
        $this->error = '';

        if ($this->lineupId === null) {
            $this->error = __('Pick your lineup first.');

            return;
        }

        if ($this->rated) {
            $this->error = __('Invite links are for casual matches. Switch to casual to make one.');

            return;
        }

        if ($this->isTeamMatch()) {
            $this->error = __('A team match goes to a clan you pick. Invite links are for series.');

            return;
        }

        $schedule = $this->schedule();

        if ($schedule === null) {
            return;
        }

        try {
            $link = $links->create($this->user(), InviteLinkType::Series, [
                'lineup_id' => $this->lineupId,
                'best_of' => $this->bestOf,
                'proposals' => $schedule[0],
                'respond_by' => $schedule[1],
                'uses' => 'once',
            ]);
        } catch (InviteLinkRefused $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->redirectRoute('invites.link', $link);
    }

    /**
     * The suggested starts and the reply deadline, as unix seconds.
     *
     * @return array{0: list<int>, 1: int}|null
     */
    private function schedule(): ?array
    {
        $zone = $this->zone();

        if ($this->now) {
            // A team match "now" leaves room for the lineup lock: the reply is due by the lock, the start after it.
            $lock = $this->isTeamMatch() ? ChessTeamMatches::lockMinutes() : 0;
            $start = now()->addMinutes((int) config('esports.series.now_minutes', 10) + $lock)->startOfMinute()->getTimestamp();

            return [[$start], $start - $lock * 60];
        }

        $proposals = [];

        foreach ($this->times as $row) {
            $time = $this->parse($row['date'], $row['time'], $zone);

            if ($time === null) {
                $this->error = __('Every suggested time needs a date and a time.');

                return null;
            }

            $proposals[] = $time;
        }

        $reply = $this->parse($this->replyDate, $this->replyTime, $zone);

        if ($reply === null) {
            $this->error = __('Set a reply deadline.');

            return null;
        }

        return [$proposals, $reply];
    }

    private function parse(string $date, string $time, string $zone): ?int
    {
        $day = $this->dateOf($date);
        $clock = $this->timeOf($time);

        if ($day === null || $clock === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$day} {$clock}", $zone)?->getTimestamp();
    }

    /**
     * A typed date as Y-m-d, or null: "17.10.2026", "17.10.26", "17.10." (the next 17 October), "2026-10-17" and
     * "17 Oct 2026", with or without a weekday in front ("Sa. 17.10.").
     */
    public function dateOf(string $value): ?string
    {
        $text = trim((string) preg_replace('/^\p{L}+\.?,?\s*/u', '', trim($value)));

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})?$/', $text, $m) === 1) {
            [$day, $month] = [(int) $m[1], (int) $m[2]];
            $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
            $year = $year !== null && $year < 100 ? 2000 + $year : $year;

            if ($year === null) {
                $today = CarbonImmutable::now($this->zone())->startOfDay();
                $year = $today->year;

                if (checkdate($month, $day, $year) && CarbonImmutable::create($year, $month, $day, 0, 0, 0, $this->zone())->lt($today)) {
                    $year++;
                }
            }
        } elseif (preg_match('/^(\d{1,2})\s+(\p{L}{3,})\.?\s+(\d{4})$/u', $text, $m) === 1) {
            $parsed = CarbonImmutable::createFromFormat('!j M Y', "{$m[1]} ".mb_substr($m[2], 0, 3)." {$m[3]}");
            if ($parsed === null) {
                return null;
            }
            [$year, $month, $day] = [$parsed->year, $parsed->month, $parsed->day];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /** A typed time as H:i, or null: "20:00", "20.00", "2000", "20". */
    public function timeOf(string $value): ?string
    {
        if (preg_match('/^(\d{1,2})(?:[:.]?(\d{2}))?$/', trim($value), $m) !== 1) {
            return null;
        }

        [$hour, $minute] = [(int) $m[1], (int) ($m[2] ?? 0)];

        return $hour < 24 && $minute < 60 ? sprintf('%02d:%02d', $hour, $minute) : null;
    }

    /** A date the way the page's language writes it: "17.10.2026" / "17 Oct 2026". */
    private function showDate(CarbonImmutable $at): string
    {
        return app()->getLocale() === 'de' ? $at->format('d.m.Y') : $at->locale('en')->isoFormat('D MMM YYYY');
    }

    /** The weekday of a typed date, in the page's language ("Samstag"), so a typo shows before sending; '' when unreadable. */
    public function weekdayOf(string $value): string
    {
        $day = $this->dateOf($value);

        return $day === null ? '' : CarbonImmutable::createFromFormat('!Y-m-d', $day, $this->zone())->locale(app()->getLocale())->isoFormat('dddd');
    }

    /**
     * The player's lineups of every series game they captain, in registry
     * order, the biggest mode first; then the team match lineups (chess
     * rapid).
     *
     * @return Collection<int, Lineup>
     */
    #[Computed]
    public function myLineups(): Collection
    {
        $user = $this->user();
        $clanId = $user->clanMember?->clan_id;

        if ($clanId === null) {
            return collect();
        }

        $teamModes = app(GameRegistry::class)->teamMatchModes();
        $games = array_values(array_unique([...array_keys(app(GameRegistry::class)->series()), ...array_keys($teamModes)]));

        return Lineup::query()->with(['clan', 'seats.user.clanMember'])
            ->where('clan_id', $clanId)->whereIn('game', $games)
            ->get()
            ->filter(fn (Lineup $lineup) => app(GameRegistry::class)->isSeries($lineup->game) || in_array($lineup->mode, $teamModes[$lineup->game] ?? [], true))
            ->filter(fn (Lineup $lineup) => $lineup->isActingCaptain($user))
            ->sortBy([fn (Lineup $a, Lineup $b) => array_search($a->game, $games, true) <=> array_search($b->game, $games, true), fn (Lineup $a, Lineup $b) => $b->gameMode()->teamSize <=> $a->gameMode()->teamSize])
            ->values();
    }

    #[Computed]
    public function lineup(): ?Lineup
    {
        return $this->myLineups->firstWhere('id', $this->lineupId);
    }

    /**
     * Other clans' lineups of the same mode, with why they can or cannot be
     * challenged right now.
     *
     * @return Collection<int, array{lineup: Lineup, state: string, note: string}>
     */
    #[Computed]
    public function opponents(): Collection
    {
        $mine = $this->lineup;

        if ($mine === null) {
            return collect();
        }

        $term = trim($this->search);
        $running = SeriesMatch::query()
            ->whereIn('status', [SeriesStatus::Open, SeriesStatus::Accepted, SeriesStatus::Reported, SeriesStatus::Disputed])
            ->where(fn ($query) => $query->where('challenger_lineup_id', $mine->id)->orWhere('challenged_lineup_id', $mine->id))
            ->get();

        return Lineup::query()->with(['clan.owner', 'seats.user.clanMember'])
            ->where('game', $mine->game)->where('mode', $mine->mode)->where('clan_id', '!=', $mine->clan_id)
            ->when($term !== '', fn ($query) => $query->whereHas('clan', fn ($query) => $query->whereLike('name', "%{$term}%")->orWhereLike('clantag', "%{$term}%")))
            ->get()
            ->sortBy('clan.name')
            ->map(function (Lineup $lineup) use ($running) {
                $open = $running->first(fn (SeriesMatch $match) => $match->challenger_lineup_id === $lineup->id || $match->challenged_lineup_id === $lineup->id);

                return match (true) {
                    $open !== null => ['lineup' => $lineup, 'state' => 'busy', 'note' => __(':number open', ['number' => $open->label()])],
                    ! $lineup->isReady() => ['lineup' => $lineup, 'state' => 'not_ready', 'note' => __('lineup not complete')],
                    // A team match needs a player per board on both sides.
                    $this->isTeamMatch() && count($lineup->activeSeats()) < $this->boards => ['lineup' => $lineup, 'state' => 'not_ready', 'note' => __('needs :n players', ['n' => $this->boards])],
                    default => ['lineup' => $lineup, 'state' => 'ok', 'note' => __('ready')],
                };
            })
            ->values();
    }

    private function zone(): string
    {
        return PreSeason::timezoneFor(auth()->user() instanceof User ? auth()->user() : null);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $me = auth()->user();
    $mine = $this->myLineups;
    $lineup = $this->lineup;
    $opponents = $this->opponents;
    $picked = $opponents->first(fn ($row) => $row['lineup']->id === $opponentId);
    $ladderOpen = $lineup !== null && Ladders::isOpen($lineup->game, $lineup->mode);
    // After Block 0 rated play also needs trust ranks (RatedTrustGate); without them it stays locked.
    $trustReady = app(RatedTrustGate::class)->isAvailable();
    // Fair play (P41): a linked second account or a player locked after false reports sees why, with the end.
    $fairPlay = $ladderOpen && $trustReady ? FairPlay::message($me->pubkey, $me) : null;
    $ratedOpen = $ladderOpen && $trustReady && $fairPlay === null;
    $block0 = PreSeason::block0At();
    $zone = PreSeason::timezoneFor($me);
    $steps = [1 => __('Lineup'), 2 => __('Opponent'), 3 => __('Rated or casual'), 4 => __('Times'), 5 => __('Send')];
    $nextLabels = [1 => __('Next: Opponent'), 2 => __('Next: Rated or casual'), 3 => __('Next: Times'), 4 => __('Next: Send')];
    $headGame = $lineup?->game ?? $mine->first()?->game ?? $game;
    $gameName = $headGame === null ? '' : GameNames::game($headGame);
    $bestOfs = $lineup?->gameMode()->bestOf ?? [3, 5];
    // A chess rapid lineup challenges to a team match over boards (NIP rev. 9.22): boards instead of a series length, friendly instead of casual.
    $team = $lineup !== null && $lineup->gameMode()->boards !== [];
    $boardCounts = $team ? $lineup->gameMode()->boards : [];
    $lockMinutes = ChessTeamMatches::lockMinutes();
    $nowMinutes = (int) config('esports.series.now_minutes', 10) + ($team ? $lockMinutes : 0);
    $casualLabel = $team ? __('Friendly') : __('Casual');
    $minutes = fn (int $bo): int => $lineup === null ? 0 : (int) (ceil(GameProfile::for($lineup->game, $lineup->mode)->slot($bo) / 5) * 5);
    $formatText = fn (int $bo): string => $bo === 1 ? __('One game, about :minutes min', ['minutes' => $minutes($bo)]) : __('First to :wins games, up to :bo games, about :minutes min', ['wins' => intdiv($bo, 2) + 1, 'bo' => $bo, 'minutes' => $minutes($bo)]);
    // P57: a rated challenge needs the sender and a captain of the other lineup to list each other (RatedTrustGate::forChallenge).
    $captains = $rated && $ratedOpen && $picked !== null ? array_values(array_diff(app(SeriesService::class)->captainPubkeys($picked['lineup']), [$me->pubkey])) : [];
    $needsMutual = $captains === [] || collect($captains)->contains(fn (string $pubkey): bool => in_array($pubkey, app(Opponents::class)->mutual($me), true))
        ? collect() : User::query()->whereIn('pubkey', $captains)->orderBy('id')->get();
    $ready = $opponents->where('state', 'ok')->count();
    // Shared classes of the wizard (ChallengeCreate.dc.html): a choice row, its radio dot, a typed field.
    $choice = 'flex min-h-14 w-full cursor-pointer items-center gap-4 rounded-[4px] border bg-ground px-4 py-3 text-left text-ink disabled:cursor-not-allowed disabled:opacity-60';
    $field = 'h-11 w-full min-w-0 rounded-[4px] border bg-ground px-3 text-sm text-ink placeholder:text-ink-3';
    $proposals = collect($times)->map(fn (array $row): ?int => $this->parse($row['date'], $row['time'], $zone))->filter()->values();
    $replyAt = $this->parse($replyDate, $replyTime, $zone);
@endphp

{{-- One step at a time (ChallengeCreate.dc.html), the same card at every width; R11: own radio rows, no native select,
     checkbox or date field; every button label on one line. --}}
<div class="flex grow flex-col gap-6 px-4 pt-5 pb-10 lg:mx-auto lg:w-full lg:max-w-[1440px] lg:px-12 lg:pt-8" data-test="challenge-create" data-step="{{ $step }}">
    <header class="flex flex-wrap items-center gap-x-6 gap-y-2 border-l-[3px] border-btc pl-4 lg:pl-5">
        @if ($headGame !== null)
            <x-game-cover :game="$headGame" size="thumb" class="w-16 rounded-xs lg:w-20" data-test="challenge-game-cover" />
        @endif
        <div class="flex min-w-0 flex-col gap-1">
            <h1 class="m-0 font-display text-[28px] leading-[1.15] font-bold lg:text-[36px]">{{ __('Challenge a clan') }}</h1>
            @if ($headGame !== null)
                <span class="text-[13px] text-ink-2">{{ __(':game ladder, Pre-Season', ['game' => $gameName]) }}</span>
            @endif
        </div>
        @if ($lineup !== null)
            <span class="rv-tag lg:ms-auto" data-test="challenge-ready-count">{{ trans_choice(':count clan with a ready :mode lineup|:count clans with a ready :mode lineup', $ready, ['mode' => $lineup->mode]) }}</span>
        @endif
    </header>

    @if ($mine->isEmpty())
        <div class="mx-auto w-full max-w-[720px] rounded-[4px] bg-card px-4 py-5 lg:px-6" data-test="no-lineup">
            <x-empty-state :heading="$me->clanMember ? __('You captain no lineup yet') : __('You aren\'t in a clan yet')"
                           :text="$me->clanMember ? __('Only a lineup\'s captain sends challenges. Ask your captain, or set up a lineup.') : __('Start a clan and invite your friends, or accept an invite. Invites show up here as soon as a captain sends one.')">
                @if ($me->clanMember)
                    <x-button :href="route('clans.show', $me->clanMember->clan)">{{ __('Open your clan') }}</x-button>
                @else
                    <x-button :href="route('clans.create')">{{ __('Start a clan') }}</x-button>
                    <x-button variant="quiet" :href="route('clans.index')">{{ __('Browse clans') }}</x-button>
                @endif
            </x-empty-state>
        </div>
    @else
        <section class="mx-auto flex w-full max-w-[720px] flex-col gap-6 rounded-[4px] border border-hairline bg-card px-4 py-5 lg:px-6 lg:py-6" aria-labelledby="step-h" data-test="wizard-step-{{ $step }}">
            {{-- The stepper: five numbered steps (they are a sequence), the done ones a way back, the bar under them how far. --}}
            <div class="flex flex-col gap-3">
                <span class="rv-tag self-start text-btc-hi" data-test="wizard-step-count">{{ __('Step :n of 5', ['n' => $step]) }}</span>
                <ol class="m-0 grid list-none grid-cols-5 p-0" aria-label="{{ __('Steps') }}" data-test="wizard-stepper">
                    @foreach ($steps as $n => $label)
                        <li @class(['flex flex-col gap-2 border-b-2 pb-2', 'border-win' => $n < $step, 'border-btc' => $n === $step, 'border-line' => $n > $step])>
                            @if ($n < $step)
                                <button type="button" wire:click="backTo({{ $n }})" class="-my-2 flex min-h-11 cursor-pointer items-center gap-1.5 bg-transparent p-0 text-left text-ink-2 hover:text-ink" aria-label="{{ __('Back to step :n, :label', ['n' => $n, 'label' => $label]) }}" data-test="wizard-back-to-{{ $n }}">
                                    <span class="inline-flex h-6 min-w-6 items-center justify-center rounded-xs bg-well px-1 text-xs tabular-nums shadow-ring">{{ $n }}</span><x-icon name="check" :size="14" class="text-win" />
                                </button>
                            @else
                                <span class="flex min-h-7 items-center" @if ($n === $step) aria-current="step" @endif>
                                    <span @class(['inline-flex h-6 min-w-6 items-center justify-center rounded-xs px-1 text-xs tabular-nums', 'bg-btc font-bold text-on-btc' => $n === $step, 'bg-well text-ink-3 shadow-ring' => $n > $step])>{{ $n }}</span>
                                    <span class="sr-only">{{ $label }}</span>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>

            <h2 id="step-h" class="m-0 font-display text-xl font-semibold lg:text-2xl" data-test="wizard-step-title">{{ $steps[$step] }}</h2>

            @if ($step === 1)
                {{-- 1 Lineup: the lineups this player captains, one row each. --}}
                <div role="radiogroup" aria-labelledby="step-h" class="flex flex-col gap-2">
                    @foreach ($mine as $option)
                        @php($on = $lineupId === $option->id)
                        <button type="button" role="radio" wire:key="lu-{{ $option->id }}" wire:click="pickLineup({{ $option->id }})" aria-checked="{{ $on ? 'true' : 'false' }}" data-test="lineup-{{ $option->game }}-{{ $option->mode }}"
                                @class([$choice, 'border-btc bg-btc-press' => $on, 'border-line hover:border-edge' => ! $on])>
                            <span @class(['flex size-5 shrink-0 items-center justify-center rounded-full border-2', 'border-btc' => $on, 'border-edge' => ! $on]) aria-hidden="true">@if ($on)<span class="size-2.5 rounded-full bg-btc"></span>@endif</span>
                            <span class="flex min-w-0 grow flex-col gap-0.5">
                                <b class="text-sm [overflow-wrap:anywhere]">{{ GameNames::game($option->game) }} {{ $option->mode }}, {{ $option->clan->name }}</b>
                                <span @class(['text-xs', 'text-ink-2' => $option->isReady(), 'text-btc-hi' => ! $option->isReady()])>{{ $option->isReady() ? __('ready') : __('incomplete, needs :n players', ['n' => $option->gameMode()->lineupMinimum()]) }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>
                @error('lineupId')<p class="m-0 text-[13px] text-loss" role="alert" data-test="step-error">{{ $message }}</p>@enderror
            @elseif ($step === 2)
                {{-- 2 Opponent: same mode only; or an invite link instead. --}}
                <div class="flex flex-col gap-3">
                    <p class="m-0 text-[13px] text-ink-2">{{ __('Opponent in :mode', ['mode' => $lineup?->mode ?? '']) }}, {{ __('same mode only') }}</p>
                    <label for="op-search" class="sr-only">{{ __('Search opponent') }}</label>
                    <span class="relative block">
                        <x-icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
                        <input id="op-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search opponent') }}" class="{{ $field }} border-edge pl-9">
                    </span>
                    <div role="radiogroup" aria-labelledby="step-h" class="flex flex-col gap-2">
                        @forelse ($opponents as $row)
                            @php($opp = $row['lineup'])
                            @php($on = $opponentId === $opp->id)
                            <button type="button" role="radio" wire:key="o-{{ $opp->id }}" wire:click="pickOpponent({{ $opp->id }})" aria-checked="{{ $on ? 'true' : 'false' }}" @disabled($row['state'] !== 'ok') data-test="pick-opponent"
                                    @class([$choice, 'border-btc bg-btc-press' => $on, 'border-line hover:border-edge' => ! $on])>
                                <span @class(['flex size-5 shrink-0 items-center justify-center rounded-full border-2', 'border-btc' => $on, 'border-edge' => ! $on]) aria-hidden="true">@if ($on)<span class="size-2.5 rounded-full bg-btc"></span>@endif</span>
                                <span class="flex min-w-0 grow flex-col gap-0.5">
                                    <span class="flex min-w-0 items-center gap-2"><b class="truncate text-sm">{{ $opp->clan->name }}</b><x-clan-tag :clan="$opp->clan" compact /></span>
                                    <span class="text-xs text-ink-2">{{ __('captain :name', ['name' => $opp->clan->owner?->displayName() ?? '']) }}, <span data-test="opponent-players-{{ $opp->id }}">{{ $team ? count($opp->activeSeats()).' / '.$this->boards : $opp->activeCount().' / '.$opp->gameMode()->teamSize }}</span>, <span @class(['text-btc-hi' => $row['state'] !== 'ok'])>{{ $row['note'] }}</span></span>
                                </span>
                            </button>
                        @empty
                            <p class="m-0 py-2 text-[13px] text-ink-2">{{ __('No other clan has a :mode lineup yet.', ['mode' => $lineup?->mode ?? '']) }}</p>
                        @endforelse
                    </div>
                    @error('opponentId')<p class="m-0 text-[13px] text-loss" role="alert" data-test="step-error">{{ $message }}</p>@enderror
                    @unless ($team)
                        <p class="m-0 flex flex-wrap items-center gap-x-2 text-[13px] text-ink-2" data-test="series-invite-link">
                            {{ __('No opponent here yet?') }}
                            <button type="button" wire:click="inviteByLink" class="inline-flex min-h-11 cursor-pointer items-center bg-transparent p-0 text-btc underline underline-offset-4 hover:text-btc-hi" data-test="invite-by-link">{{ __('Invite by link') }}</button>
                        </p>
                    @endunless
                </div>
            @elseif ($step === 3)
                {{-- 3 Rated or casual, and the format. --}}
                <div class="flex flex-col gap-3">
                    <div role="radiogroup" aria-labelledby="step-h" class="flex flex-col gap-2">
                        @if ($ratedOpen && ! $viaLink)
                            <button type="button" role="radio" wire:click="$set('rated', true)" aria-checked="{{ $rated ? 'true' : 'false' }}" data-test="type-rated" @class([$choice, 'items-start', 'border-btc bg-btc-press' => $rated, 'border-line hover:border-edge' => ! $rated])>
                                <span @class(['mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border-2', 'border-btc' => $rated, 'border-edge' => ! $rated]) aria-hidden="true">@if ($rated)<span class="size-2.5 rounded-full bg-btc"></span>@endif</span>
                                <span class="flex min-w-0 flex-col gap-0.5"><b class="text-sm">{{ __('Rated') }}</b><span class="text-xs leading-normal text-ink-2">{{ $team ? __('Every board counts for rapid Elo and clan Hashrate. One rated team match per pair of clans a week. Needs a connection with their captain.') : __('Counts for Elo and clan Hashrate, and a win can mine a block. Needs a connection with their captain.') }}</span></span>
                            </button>
                        @else
                            <span role="radio" aria-checked="false" aria-disabled="true" class="{{ $choice }} cursor-not-allowed items-start border-line opacity-60" data-test="type-rated-locked">
                                <span class="mt-0.5 size-5 shrink-0 rounded-full border-2 border-edge" aria-hidden="true"></span>
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <b class="text-sm">{{ __('Rated') }}</b>
                                    @if ($viaLink)
                                        <span class="text-xs leading-normal text-ink-2">{{ __('Invite links are for casual matches. Switch to casual to make one.') }}</span>
                                    @elseif ($fairPlay !== null)
                                        <span class="text-xs leading-normal text-ink-2" data-test="rated-fair-play">{{ $fairPlay }}</span>
                                    @elseif ($ladderOpen && ! $trustReady)
                                        <span class="text-xs leading-normal text-ink-2" data-test="rated-needs-trust">{{ __('Rated play opens once trust ranks are computed') }}</span>
                                    @else
                                        <span class="text-xs leading-normal text-ink-2">{{ __('Counts for Elo and clan Hashrate, and a win can mine a block.') }}</span><span class="text-xs text-ink-2">{{ __('from Block 0') }}</span>
                                    @endif
                                    @if (! $ladderOpen && $block0 && $block0->isFuture())
                                        <span class="text-xs font-bold text-btc">{{ __('Block 0: :time', ['time' => Formats::date($block0, true, $zone)]) }}</span>
                                    @endif
                                </span>
                            </span>
                        @endif
                        <button type="button" role="radio" wire:click="$set('rated', false)" aria-checked="{{ $rated ? 'false' : 'true' }}" data-test="type-casual" @class([$choice, 'items-start', 'border-btc bg-btc-press' => ! $rated, 'border-line hover:border-edge' => $rated])>
                            <span @class(['mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border-2', 'border-btc' => ! $rated, 'border-edge' => $rated]) aria-hidden="true">@if (! $rated)<span class="size-2.5 rounded-full bg-btc"></span>@endif</span>
                            <span class="flex min-w-0 flex-col gap-0.5"><b class="text-sm">{{ $casualLabel }}</b><span class="text-xs leading-normal text-ink-2">{{ $team ? __('Boards unrated, no Hashrate. Play as many as you like.') : __('Just for fun. No rating change, no connection needed. Open to every clan.') }}</span></span>
                        </button>
                    </div>
                    @error('rated')<p class="m-0 text-[13px] text-loss" role="alert" data-test="step-error">{{ $message }}</p>@enderror
                    @unless ($ladderOpen)
                        <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Until Block 0 every series is casual: you can challenge any clan, no connection needed. Rated opens at Block 0, with a connection between both captains.') }}</p>
                    @endunless
                    @if ($needsMutual->isNotEmpty())
                        <x-opponents.needs-mutual :players="$needsMutual" :heading="trans_choice('You and :clan\'s captain do not list each other as opponents yet, so this challenge cannot be rated.|You and none of :clan\'s captains list each other as opponents yet, so this challenge cannot be rated.', $needsMutual->count(), ['clan' => $picked['lineup']->clan->name])">
                            <x-button variant="quiet" wire:click="$set('rated', false)" data-test="needs-mutual-casual">{{ __('Make it casual instead') }}</x-button>
                        </x-opponents.needs-mutual>
                    @endif
                </div>

                <div class="flex flex-col gap-3">
                    <h3 id="fmt-h" class="m-0 text-sm font-bold">{{ $team ? __('Boards') : __('Format') }}</h3>
                    <div role="radiogroup" aria-labelledby="fmt-h" class="flex flex-wrap gap-2">
                        @if ($team)
                            @foreach ($boardCounts as $count)
                                <button type="button" role="radio" wire:click="$set('boards', {{ $count }})" aria-checked="{{ $boards === $count ? 'true' : 'false' }}" data-test="boards-{{ $count }}"
                                        @class(['rv-b2', 'border-btc! bg-btc! font-bold text-on-btc!' => $boards === $count])>{{ trans_choice(':count board|:count boards', $count) }}</button>
                            @endforeach
                        @else
                            @foreach ($bestOfs as $bo)
                                <button type="button" role="radio" wire:click="$set('bestOf', {{ $bo }})" aria-checked="{{ $bestOf === $bo ? 'true' : 'false' }}" data-test="bo-{{ $bo }}"
                                        @class(['rv-b2', 'border-btc! bg-btc! font-bold text-on-btc!' => $bestOf === $bo])>{{ __('Best of :n', ['n' => $bo]) }}</button>
                            @endforeach
                        @endif
                    </div>
                    <p class="m-0 text-xs leading-normal text-ink-2" data-test="format-text">{{ $team ? __(':n players per clan play rapid 10+5 at the same time, the boards ordered by rapid Elo', ['n' => $boards]) : $formatText($bestOf) }}</p>
                    @error('bestOf')<p class="m-0 text-[13px] text-loss" role="alert" data-test="step-error">{{ $message }}</p>@enderror
                    @error('boards')<p class="m-0 text-[13px] text-loss" role="alert" data-test="step-error">{{ $message }}</p>@enderror
                </div>
            @elseif ($step === 4)
                {{-- 4 Times: up to three suggestions, the other clan picks one; the reply deadline. --}}
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-1">
                        <h3 class="m-0 text-sm font-bold">{{ __('Suggested times') }}</h3>
                        <p class="m-0 text-xs text-ink-2">{{ __('up to 3, :clan picks one', ['clan' => $picked['lineup']->clan->name ?? __('the other clan')]) }}</p>
                    </div>
                    <x-ui.check wire:click="$toggle('now')" :checked="$now" data-test="challenge-now"><b>{{ __('Challenge now') }}</b> <span class="text-ink-2">{{ $team ? __('start in :n minutes; they accept within :m minutes, then both captains pick their players', ['n' => $nowMinutes, 'm' => $nowMinutes - $lockMinutes]) : __('start in :n minutes, they have until then to accept', ['n' => $nowMinutes]) }}</span></x-ui.check>
                    @unless ($now)
                        @foreach ($times as $index => $row)
                            @php($weekday = $this->weekdayOf($row['date']))
                            <div wire:key="t-{{ $index }}" class="grid grid-cols-[minmax(0,1fr)_96px_44px] items-start gap-2 sm:grid-cols-[minmax(0,1fr)_120px_44px] sm:gap-3" data-test="time-row">
                                <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">
                                    <span class="flex justify-between gap-2"><span>{{ __('Time :n', ['n' => $index + 1]) }}</span><span class="text-ink" data-test="time-weekday">{{ $weekday }}</span></span>
                                    <input type="text" inputmode="numeric" autocomplete="off" wire:model.live.blur="times.{{ $index }}.date" aria-label="{{ __('Date of time :n', ['n' => $index + 1]) }}" placeholder="{{ app()->getLocale() === 'de' ? 'TT.MM.JJJJ' : 'DD Mon YYYY' }}" data-test="time-date"
                                           @class([$field, 'border-loss' => $errors->has("times.{$index}.date"), 'border-edge' => ! $errors->has("times.{$index}.date")])>
                                    @error("times.{$index}.date")<span class="text-loss" role="alert" data-test="step-error">{{ $message }}</span>@enderror
                                </label>
                                <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">
                                    <span>{{ __('Time of day') }}</span>
                                    <input type="text" inputmode="numeric" autocomplete="off" wire:model.live.blur="times.{{ $index }}.time" aria-label="{{ __('Hour of time :n', ['n' => $index + 1]) }}" placeholder="20:00" data-test="time-hour"
                                           @class([$field, 'border-loss' => $errors->has("times.{$index}.time"), 'border-edge' => ! $errors->has("times.{$index}.time")])>
                                    @error("times.{$index}.time")<span class="text-loss" role="alert" data-test="step-error">{{ $message }}</span>@enderror
                                </label>
                                @if (count($times) > 1)
                                    <button type="button" wire:click="removeTime({{ $index }})" aria-label="{{ __('Remove time :n', ['n' => $index + 1]) }}" class="mt-5 inline-flex size-11 cursor-pointer items-center justify-center rounded-[4px] border border-line bg-well text-ink-2 hover:text-ink"><x-icon name="close" :size="16" /></button>
                                @endif
                            </div>
                        @endforeach
                        @if (count($times) < 3)
                            <div><button type="button" wire:click="addTime" class="rv-b2" data-test="add-time">{{ __('Add a time') }}</button></div>
                        @endif
                        <div class="grid grid-cols-[minmax(0,1fr)_96px_44px] items-start gap-2 border-t border-hairline pt-4 sm:grid-cols-[minmax(0,1fr)_120px_44px] sm:gap-3">
                            <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">
                                <span class="flex justify-between gap-2"><span>{{ __('Reply by') }}</span><span class="text-ink" data-test="reply-weekday">{{ $this->weekdayOf($replyDate) }}</span></span>
                                <input type="text" inputmode="numeric" autocomplete="off" wire:model.live.blur="replyDate" aria-label="{{ __('Reply by, date') }}" placeholder="{{ app()->getLocale() === 'de' ? 'TT.MM.JJJJ' : 'DD Mon YYYY' }}" data-test="reply-date"
                                       @class([$field, 'border-loss' => $errors->has('replyDate'), 'border-edge' => ! $errors->has('replyDate')])>
                                @error('replyDate')<span class="text-loss" role="alert" data-test="step-error">{{ $message }}</span>@enderror
                            </label>
                            <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">
                                <span>{{ __('Time of day') }}</span>
                                <input type="text" inputmode="numeric" autocomplete="off" wire:model.live.blur="replyTime" aria-label="{{ __('Reply by, hour') }}" placeholder="20:00" data-test="reply-hour"
                                       @class([$field, 'border-loss' => $errors->has('replyTime'), 'border-edge' => ! $errors->has('replyTime')])>
                                @error('replyTime')<span class="text-loss" role="alert" data-test="step-error">{{ $message }}</span>@enderror
                            </label>
                        </div>
                        <p class="m-0 text-xs leading-normal text-ink-2">{{ $team ? __('No reply by then and the challenge expires. The deadline has to be at least :minutes minutes before the first suggested time: the captains pick their players until then.', ['minutes' => $lockMinutes]) : __('No reply by then and the challenge expires. The deadline has to be before the first suggested time.') }} {{ __('Times in :zone.', ['zone' => $zone]) }}</p>
                    @endunless
                </div>
            @else
                {{-- 5 Send: what goes out, then the one action. --}}
                <dl class="m-0 flex flex-col" data-test="challenge-summary">
                    @foreach (array_filter([
                        [__('Match kind'), ($lineup ? GameNames::game($lineup->game).' '.$lineup->mode : '').', '.($team ? __('Team match, :boards', ['boards' => trans_choice(':count board|:count boards', $boards)]) : __('Best of :n', ['n' => $bestOf])), 'kind'],
                        [__('The other clan'), $viaLink ? __('whoever opens your link first with a ready :mode lineup', ['mode' => $lineup?->mode ?? '']) : ($picked ? $picked['lineup']->clan->name.', '.__('captain :name', ['name' => $picked['lineup']->clan->owner?->displayName() ?? '']) : '–'), 'opponent'],
                        [__('At stake'), $rated ? __('Elo and clan Hashrate') : ($team ? __('nothing, friendly') : __('nothing, casual')), 'stake'],
                        [__('Times'), $now ? __('now, in :n min', ['n' => $nowMinutes]) : $proposals->map(fn (int $at): string => Formats::date(CarbonImmutable::createFromTimestamp($at), true, $zone))->implode('; '), 'times'],
                        $now ? null : [__('Reply by'), $replyAt ? Formats::date(CarbonImmutable::createFromTimestamp($replyAt), true, $zone) : '–', 'reply'],
                        [__('Record'), $rated ? __('challenge, kind 2150') : __('a casual challenge stays with the league (no kind 2150)'), 'record'],
                        [__('Match number'), $viaLink ? __('reserved when someone takes the link') : __('reserved when you send'), 'number'],
                    ]) as [$key, $value, $test])
                        <div class="flex flex-col gap-0.5 border-b border-hairline py-3 first:pt-0" data-test="summary-{{ $test }}">
                            <dt class="text-xs text-ink-2">{{ $key }}</dt>
                            <dd class="m-0 text-sm [overflow-wrap:anywhere]">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p class="m-0 text-xs leading-normal text-ink-2">{{ $viaLink ? __('You get a link to send to another captain. Whoever opens it first and accepts plays you, at one of your times.') : __(':clan\'s captains see it right away. You can withdraw it while it\'s open.', ['clan' => $picked['lineup']->clan->name ?? __('The other clan')]) }}</p>
            @endif

            @if ($error)<p class="m-0 text-[13px] text-loss" role="alert" data-test="challenge-error">{{ $error }}</p>@endif

            {{-- The step's actions: back on the left, the way on on the right (the board's "Zurück" | "Weiter: …"). --}}
            {{-- The signer's scope on every step: a morph from step 4 to 5 adds no x-data Alpine would start (busy undefined). --}}
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline pt-5" x-data="nostrAction({ pubkey: @js($me->pubkey), messages: @js(\App\Support\Nostr\SignerMessages::labels()) })" data-test="wizard-actions">
                @if ($step > 1)
                    <button type="button" wire:click="back" class="rv-b2 h-12 px-4 sm:px-5" data-test="wizard-back">{{ __('Back') }}</button>
                @else
                    <span></span>
                @endif
                @if ($step < 5)
                    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="rv-b ms-auto h-12 px-3 sm:px-5" data-test="next-step">{{ $nextLabels[$step] }}</button>
                @elseif ($viaLink)
                    <button type="button" wire:click="createLink" wire:loading.attr="disabled" class="rv-b ms-auto h-12 px-3 sm:px-5" data-test="create-series-link"><x-icon name="link" :size="16" class="max-sm:hidden" />{{ __('Make the invite link') }}</button>
                @else
                    <button type="button" x-on:click="run('prepareSend', 'send')" :disabled="busy" class="rv-b ms-auto h-12 px-3 sm:px-5" data-test="send-challenge"><x-icon name="shield-check" :size="18" class="max-sm:hidden" />{{ __('Send challenge') }}</button>
                    <p x-show="error" x-text="error" class="m-0 basis-full text-[13px] text-loss" role="alert"></p>
                @endif
            </div>
        </section>
        <x-proof class="mx-auto w-full max-w-[720px]" :rows="[[__('Sent as'), $me->shortNpub().' ('.$me->displayName().')']]" />
    @endif
</div>
