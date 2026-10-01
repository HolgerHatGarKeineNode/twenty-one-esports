<?php

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Games\ScoreMetric;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Scores\ManualSubmissions;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Scores\ScoreWindow;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * A score leaderboard's values (plan "AoE2 und Trackmania", P4): the whole
 * table for everyone (league names only, never a game account or a proof
 * link), the submission form and the player's own submissions for its
 * entries, and for its directors and admins the course, a correction with a
 * reason (the log below is public, as a director log is), and the end once
 * the window closed. Routed only while a score game is registered.
 */
new #[Layout('layouts::app', ['section' => 'tournaments'])] class extends Component {
    public Tournament $tournament;

    public string $value = '';

    /** When the value was set, `Y-m-d\TH:i` in the league's zone (LeagueTime). */
    public string $achievedAt = '';

    public string $proofUrl = '';

    public string $course = '';

    public ?int $correctUser = null;

    public string $correctValue = '';

    public string $correctReason = '';

    public string $flash = '';

    public function mount(Tournament $tournament): void
    {
        abort_unless($tournament->isVisibleTo(auth()->user()) && app(GameRegistry::class)->isScore($tournament->game), 404);

        $this->tournament = $tournament;
        $this->course = (string) $tournament->score_course;
        $this->achievedAt = LeagueTime::input(now());
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Leaderboard').': '.$this->tournament->title());
        $meta = app(\App\Support\PageMeta::class)->describe(__('Leaderboard').': '.$this->tournament->title(),
            __(':tournament: every player\'s best value inside the window, best first.', ['tournament' => $this->tournament->title()]));
        $meta->noindex = true;

        // The link preview: the first three with their values. A draft or private leaderboard keeps the brand card.
        if (\App\Support\Cards\PageCard::hasPublicLeaderboard($this->tournament)) {
            $meta->card(fn () => \App\Support\Cards\PageCard::leaderboard($this->tournament));
        }
    }

    #[Computed]
    public function game(): ScoreGame
    {
        $game = app(GameRegistry::class)->get($this->tournament->game);
        abort_unless($game instanceof ScoreGame, 404);

        return $game;
    }

    #[Computed]
    public function metric(): ScoreMetric
    {
        return app(ScoreRuns::class)->metricOf($this->tournament);
    }

    /**
     * @return list<ScoreStanding>
     */
    #[Computed]
    public function standings(): array
    {
        return $this->tournament->participants()->exists() ? app(ScoreRuns::class)->standings($this->tournament) : [];
    }

    #[Computed]
    public function entered(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $this->tournament->participants()->where('user_id', $user->id)->exists();
    }

    #[Computed]
    public function canDirect(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ScoreLeaderboards::mayDirect($this->tournament, $user);
    }

    /**
     * The viewer's own submissions, newest first, with what became of them.
     *
     * @return Collection<int, ScoreRun>
     */
    #[Computed]
    public function mine(): Collection
    {
        return ScoreRun::query()->where(['tournament_id' => $this->tournament->id, 'user_id' => auth()->id(), 'source' => ScoreRun::MANUAL])->latest('id')->limit(20)->get();
    }

    /**
     * Every director entry, newest first: the audit trail of the corrections.
     *
     * @return Collection<int, ScoreRun>
     */
    #[Computed]
    public function corrections(): Collection
    {
        return ScoreRun::query()->where(['tournament_id' => $this->tournament->id, 'source' => ScoreRun::DIRECTOR])->with(['user', 'verifiedBy'])->latest('id')->limit(50)->get();
    }

    public function submit(ManualSubmissions $submissions): void
    {
        $user = $this->viewer();
        $this->resetErrorBag();
        $this->validate(['achievedAt' => ['required', LeagueTime::rule()], 'value' => ['required', 'string', 'max:32'], 'proofUrl' => ['required', 'string', 'max:500']]);

        try {
            $submissions->submit($this->tournament->refresh(), $user, $this->value, LeagueTime::parse($this->achievedAt), $this->proofUrl);
        } catch (TournamentRuleViolation $violation) {
            $this->addError(in_array($violation->reason, ['value', 'achieved_at', 'proof_url'], true) ? ['value' => 'value', 'achieved_at' => 'achievedAt', 'proof_url' => 'proofUrl'][$violation->reason] : 'submit', $violation->getMessage());

            return;
        }

        $this->reset('value', 'proofUrl');
        $this->flash = __('Submitted. It counts once an admin has checked your proof.');
        unset($this->mine);
    }

    public function saveCourse(ScoreLeaderboards $leaderboards): void
    {
        $this->resetErrorBag();

        try {
            $leaderboards->setCourse($this->tournament, $this->viewer(), $this->course);
        } catch (TournamentRuleViolation $violation) {
            $this->addError('course', $violation->getMessage());

            return;
        }

        $this->tournament->refresh();
        $this->flash = __('The course is set.');
        unset($this->standings);
    }

    public function pickCorrection(int $userId): void
    {
        $this->correctUser = $userId;
        $this->correctValue = '';
        $this->correctReason = '';
        $this->resetErrorBag();
    }

    public function correct(ScoreLeaderboards $leaderboards): void
    {
        $this->resetErrorBag();

        if ($this->correctUser === null) {
            return;
        }

        $value = trim($this->correctValue) === '' ? null : $this->metric->parse($this->correctValue);

        if (trim($this->correctValue) !== '' && $value === null) {
            $this->addError('correctValue', $this->metric->unit === 'ms' ? __('Enter the time as m:ss.mmm, for example 1:23.456.') : __('Enter the score as a whole number.'));

            return;
        }

        try {
            $leaderboards->correct($this->tournament->refresh(), $this->viewer(), $this->correctUser, $value, $this->correctReason);
        } catch (TournamentRuleViolation $violation) {
            $this->addError($violation->reason === 'reason' ? 'correctReason' : 'correctValue', $violation->getMessage());

            return;
        }

        $this->correctUser = null;
        $this->flash = __('The value was corrected. The reason is in the log below.');
        unset($this->standings, $this->corrections);
    }

    public function finalize(ScoreLeaderboards $leaderboards): void
    {
        $this->resetErrorBag();

        try {
            $leaderboards->finalize($this->tournament->refresh(), $this->viewer());
        } catch (TournamentRuleViolation $violation) {
            $this->addError('finalize', $violation->getMessage());

            return;
        }

        $this->tournament->refresh();
        $this->flash = __('The leaderboard is final. Places and prizes follow from it.');
        unset($this->standings);
    }

    private function viewer(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $tournament = $this->tournament;
    $game = $this->game;
    $metric = $this->metric;
    $window = ScoreWindow::of($tournament);
    $running = $tournament->status === TournamentStatus::Running;
    $standings = $this->standings;
    $staff = $this->canDirect;
    $grace = (int) config('esports.score_games.manual.grace_minutes', 60);
    $submitOpen = $running && $game->acceptsManual() && $tournament->score_course !== null && $window->hasStarted() && now()->lessThan($window->end->addMinutes($grace));
    $label = __($game->courseLabel());
    $statusOf = fn (ScoreRun $run): array => match (true) {
        $run->verified_at !== null => [__('counts'), 'text-win'],
        $run->rejected_at !== null => [__('rejected: :reason', ['reason' => (string) $run->note]), 'text-loss'],
        default => [__('waits for an admin'), 'text-ink-2'],
    };
    $field = 'h-11 w-full min-w-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
@endphp

<div class="flex flex-col gap-6 px-4 pt-6 pb-12 lg:gap-8 lg:px-12 lg:pt-8" data-test="score-tournament" @if ($running) wire:poll.30s.visible @endif>
    <header class="flex flex-col gap-2">
        <a href="{{ route('tournaments.show', $tournament) }}" class="inline-flex min-h-6 items-center gap-1.5 self-start text-xs text-ink-2 hover:text-ink">
            <x-icon name="prev" :size="14" />{{ $tournament->title() }}
        </a>
        <h1 class="m-0 font-display text-[28px] leading-[1.15] font-bold lg:text-4xl">{{ __('Leaderboard') }}</h1>
        <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2">
            {{ \App\Support\GameNames::full($tournament->game, $tournament->mode) }} ·
            {{ $metric->lowerIsBetter() ? __('the fastest time wins') : __('the highest score wins') }} ·
            {{ __('a tie goes to the earlier record') }}
        </p>
    </header>

    <dl class="m-0 grid gap-2 sm:grid-cols-3" data-test="score-facts">
        @foreach ([
            ['flag', $label, $tournament->score_course ?? __('not set yet'), 'course'],
            ['clock', __('Window opens'), LeagueTime::stamp($window->start), 'opens'],
            ['lock', __('Window closes'), LeagueTime::stamp($window->end), 'closes'],
        ] as [$icon, $term, $fact, $key])
            <div class="flex gap-3 rounded-md bg-card px-3.5 py-3" data-fact="{{ $key }}">
                <x-icon :name="$icon" :size="18" class="mt-0.5 shrink-0 text-btc" />
                <span class="flex min-w-0 flex-col gap-0.5">
                    <dt class="text-xs text-ink-3">{{ $term }}</dt>
                    <dd @class(['m-0 text-[13px] leading-normal [overflow-wrap:anywhere]', 'font-mono' => $key === 'course'])>{{ $fact }}</dd>
                </span>
            </div>
        @endforeach
    </dl>

    @if ($flash !== '')
        <p class="m-0 text-[13px] text-win" role="status" data-test="score-flash">{{ $flash }}</p>
    @endif

    <section aria-labelledby="table-h" class="flex flex-col gap-3 rounded-lg bg-card px-2 py-4 lg:px-6 lg:py-5">
        <h2 id="table-h" class="m-0 px-2 text-[15px] font-bold lg:px-0">{{ $tournament->status === TournamentStatus::Finished ? __('Final standings') : __('Standings') }}</h2>
        @include('pages.scores.partials.leaderboard', ['standings' => $standings, 'metric' => $metric, 'viewerId' => auth()->id(), 'staff' => $staff])
    </section>

    @if ($this->entered)
        <section id="submit" aria-labelledby="submit-h" class="flex scroll-mt-24 flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="score-submit">
            <div class="flex flex-col gap-1">
                <h2 id="submit-h" class="m-0 text-[15px] font-bold">{{ __('Submit your value') }}</h2>
                <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2">{{ __('Your best inside the window, with a link that proves it: a replay, a leaderboard page or a screenshot. An admin checks it; only the league team sees the link.') }}</p>
            </div>
            @if ($submitOpen)
                <form wire:submit="submit" class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,2fr)_auto] lg:items-start">
                    <label class="flex min-w-0 flex-col gap-1.5 text-xs text-ink-2">
                        {{ $metric->unit === 'ms' ? __('Time (m:ss.mmm)') : __('Points') }}
                        <input wire:model="value" inputmode="{{ $metric->unit === 'ms' ? 'text' : 'numeric' }}" autocomplete="off" placeholder="{{ $metric->unit === 'ms' ? '1:23.456' : '12345' }}" class="{{ $field }} font-mono" data-test="score-value-input">
                        @error('value')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                    </label>
                    <x-berlin-datetime-input model="achievedAt" :label="__('Set at')" :value="$achievedAt" test="score-achieved-at" />
                    <label class="flex min-w-0 flex-col gap-1.5 text-xs text-ink-2">
                        {{ __('Proof link (https)') }}
                        <input wire:model="proofUrl" type="url" autocomplete="off" placeholder="https://" class="{{ $field }}" data-test="score-proof-input">
                        @error('proofUrl')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                    </label>
                    <span class="flex flex-col gap-1.5 lg:pt-[22px]">
                        <x-button type="submit" icon="send" data-test="score-submit-button">{{ __('Submit') }}</x-button>
                    </span>
                    @error('submit')<p class="m-0 text-[13px] text-loss lg:col-span-4" role="alert">{{ $message }}</p>@enderror
                </form>
            @else
                <p class="m-0 text-[13px] text-ink-2" data-test="score-submit-closed">{{ match (true) {
                    ! $game->acceptsManual() => __('This game takes no submissions: its values are read automatically.'),
                    ! $running && $tournament->status !== TournamentStatus::Finished => __('Submissions open with the window, once sign-up has closed.'),
                    ! $window->hasStarted() => __('Submissions open with the window.'),
                    default => __('Submissions are closed for this leaderboard.'),
                } }}</p>
            @endif

            @if ($this->mine->isNotEmpty())
                <ul class="m-0 flex list-none flex-col p-0" data-test="score-mine">
                    @foreach ($this->mine as $run)
                        @php([$state, $tone] = $statusOf($run))
                        <li wire:key="mine-{{ $run->id }}" class="flex min-h-11 flex-wrap items-center gap-x-4 gap-y-1 border-t border-hairline py-2 text-[13px]">
                            <b class="font-mono tabular-nums">{{ $run->formatted() }}</b>
                            <span class="text-xs text-ink-2">{{ LeagueTime::stamp($run->achieved_at) }}</span>
                            <span class="{{ $tone }} text-xs">{{ $state }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    @if ($staff)
        <section aria-labelledby="direct-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="score-direct">
            <div class="flex flex-col gap-1">
                <h2 id="direct-h" class="m-0 text-[15px] font-bold">{{ __('For the directors') }}</h2>
                <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2">{{ __('Set the course before the window opens. Correct a value with a reason: it overrides every source for that player, and the log below keeps every entry. Nobody corrects their own value.') }}</p>
            </div>

            <form wire:submit="saveCourse" class="flex flex-col gap-2 sm:flex-row sm:items-end" data-test="score-course-form">
                <label class="flex min-w-0 grow flex-col gap-1.5 text-xs text-ink-2 sm:max-w-[420px]">
                    {{ $label }}
                    <input wire:model="course" maxlength="64" autocomplete="off" spellcheck="false" class="{{ $field }} font-mono" data-test="score-course-input" @disabled($window->hasStarted() && $tournament->score_course !== null)>
                    @error('course')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                </label>
                @unless ($window->hasStarted() && $tournament->score_course !== null)
                    <x-button type="submit" variant="secondary" data-test="score-course-save">{{ __('Save') }}</x-button>
                @endunless
            </form>

            @if ($running && $standings !== [])
                <div class="flex flex-col gap-2 border-t border-hairline pt-4">
                    <h3 class="m-0 text-[13px] font-bold">{{ __('Correct a value') }}</h3>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($standings as $row)
                            <button type="button" wire:click="pickCorrection({{ (int) $row->participant->user_id }})" wire:key="pick-{{ $row->participant->id }}" data-test="score-pick"
                                    @class(['inline-flex min-h-11 cursor-pointer items-center rounded-md border px-3 text-[13px]', 'border-btc bg-btc-chip font-bold text-ink' => $correctUser === $row->participant->user_id, 'border-edge bg-ground text-ink-2' => $correctUser !== $row->participant->user_id])>{{ $row->participant->name }}</button>
                        @endforeach
                    </div>
                    @if ($correctUser !== null)
                        <form wire:submit="correct" class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] lg:items-start" data-test="score-correct-form">
                            <label class="flex min-w-0 flex-col gap-1.5 text-xs text-ink-2">
                                {{ $metric->unit === 'ms' ? __('Time (empty: no valid value)') : __('Score (empty: no valid value)') }}
                                <input wire:model="correctValue" autocomplete="off" class="{{ $field }} font-mono" data-test="score-correct-value">
                                @error('correctValue')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                            </label>
                            <label class="flex min-w-0 flex-col gap-1.5 text-xs text-ink-2">
                                {{ __('Reason (public in the log)') }}
                                <input wire:model="correctReason" maxlength="500" autocomplete="off" class="{{ $field }}" data-test="score-correct-reason">
                                @error('correctReason')<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                            </label>
                            <span class="lg:pt-[22px]"><x-button type="submit" data-test="score-correct-save">{{ __('Correct') }}</x-button></span>
                        </form>
                    @endif
                </div>
            @endif

            @if ($running && $window->hasEnded())
                <div class="flex flex-col gap-2 border-t border-hairline pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2">{{ __('The window has closed. Ending the leaderboard writes the places; prizes follow from them. The league ends it on its own :hours hours after the window, once no submission waits.', ['hours' => (int) config('esports.score_games.review_hours', 24)]) }}</p>
                    <x-button wire:click="finalize" wire:confirm="{{ __('End the leaderboard with these places?') }}" data-test="score-finalize">{{ __('End the leaderboard') }}</x-button>
                </div>
                @error('finalize')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
            @endif
        </section>
    @endif

    @if ($this->corrections->isNotEmpty())
        <details class="group rounded-card bg-card" data-test="score-log">
            <summary class="flex min-h-12 cursor-pointer items-center gap-3 px-4 lg:px-6">
                <h2 class="m-0 text-[15px] font-bold">{{ __('Director log') }}</h2>
                <span class="grow text-xs text-ink-3">{{ __('public, newest first') }}</span>
                <x-icon name="chevron-down" :size="16" class="transition-transform group-open:rotate-180" />
            </summary>
            <div class="flex flex-col gap-2 px-4 pb-4 lg:px-6">
                @foreach ($this->corrections as $entry)
                    <p class="m-0 flex flex-wrap gap-x-3 border-t border-hairline pt-2 text-xs leading-normal" wire:key="log-{{ $entry->id }}">
                        <span class="shrink-0 text-ink-3">{{ LeagueTime::stamp($entry->created_at ?? now()) }}</span>
                        <span><b>{{ $entry->user?->displayName() ?? __('Deleted account') }}: {{ $entry->formatted() }}</b> {{ __('by :name', ['name' => $entry->verifiedBy?->displayName() ?? __('Deleted account')]) }} · {{ $entry->note }}</span>
                    </p>
                @endforeach
            </div>
        </details>
    @endif
</div>
