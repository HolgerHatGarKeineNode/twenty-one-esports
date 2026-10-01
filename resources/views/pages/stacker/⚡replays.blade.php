<?php

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Games\ScoreMetric;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Cards\PageCard;
use App\Support\PageMeta;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerReplays;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Every Blockfill replay the viewer may watch, in one place: the Replays tab
 * of Blockfill's context bar. Three shelves, each read through
 * StackerReplays, so nothing shows that StackerReplays::canView() would
 * refuse:
 * - Your replays (logged in): your runs that keep a replay, newest first,
 *   with time, week and the place the run holds on its week's board;
 * - Top replays: an ended week's first ten (the newest ended week, or the
 *   one picked in the week chips, `?week=<slug>`), its first place large;
 * - for admins, the runs held for a check, with the way to the review list.
 * `?player=<npub>` (a player page's Blockfill card) shows only that
 * player's replays the viewer may watch.
 */
new #[Layout('layouts::app')] class extends Component
{
    #[Url]
    public string $week = '';

    #[Url]
    public string $player = '';

    public function rendering(\Illuminate\View\View $view): void
    {
        $title = $this->subject() !== null ? __('Blockfill replays of :name', ['name' => $this->subject()->displayName()]) : __('Blockfill replays');
        $view->title($title);
        app(PageMeta::class)->describe($title, __('Watch the fastest Blockfill runs of each finished week again, block by block, and your own.'))
            ->card(fn () => PageCard::page('blockfill-replays'));
    }

    public function viewer(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** The player of `?player=`, if any. */
    #[Computed]
    public function subject(): ?User
    {
        return $this->player === '' ? null : User::query()->where('npub', $this->player)->first();
    }

    /**
     * @return list<Tournament>
     */
    #[Computed]
    public function weeks(): array
    {
        return app(StackerReplays::class)->endedWeeks();
    }

    /** The week of Top replays: the one picked, else the newest ended week. */
    #[Computed]
    public function shownWeek(): ?Tournament
    {
        $weeks = $this->weeks;

        return collect($weeks)->first(fn (Tournament $week): bool => $week->slug === $this->week) ?? ($weeks[0] ?? null);
    }

    /**
     * @return list<array{place: int, name: string, run: StackerRun, href: string}>
     */
    #[Computed]
    public function top(): array
    {
        return app(StackerReplays::class)->top($this->shownWeek);
    }

    /**
     * @return list<array{run: StackerRun, week: ?Tournament, place: ?int, href: string}>
     */
    #[Computed]
    public function mine(): array
    {
        $viewer = $this->viewer();

        return $viewer === null ? [] : app(StackerReplays::class)->ofPlayer($viewer, $viewer);
    }

    /**
     * @return list<array{run: StackerRun, week: ?Tournament, place: ?int, href: string}>
     */
    #[Computed]
    public function theirs(): array
    {
        $subject = $this->subject;

        return $subject === null ? [] : app(StackerReplays::class)->ofPlayer($subject, $this->viewer());
    }

    /**
     * @return array{runs: list<StackerRun>, total: int}
     */
    #[Computed]
    public function held(): array
    {
        return app(StackerReplays::class)->held($this->viewer());
    }

    public function time(StackerRun $run): string
    {
        return ScoreMetric::time()->format(Blockfill::milliseconds((int) $run->ticks));
    }

    /** "Week 40, 2026" in the page's language. */
    public function weekLabel(?Tournament $week, bool $year = true): string
    {
        if ($week === null) {
            return '';
        }

        $local = $week->starts_at->toImmutable()->setTimezone(BlockfillWeeks::TIMEZONE);

        return $year ? __('Week :week, :year', ['week' => $local->isoWeek(), 'year' => $local->isoWeekYear()]) : __('Week :week', ['week' => $local->isoWeek()]);
    }
}; ?>

@php
    $viewer = $this->viewer();
    $subject = $this->subject;
    $filtered = $this->player !== '';
    $weeks = $this->weeks;
    $shown = $this->shownWeek;
    $top = $this->top;
    $featured = $top[0] ?? null;
    $rest = array_slice($top, 1);
    $held = $this->held;
    $medal = [1 => 'bg-rank-gold', 2 => 'bg-rank-silver', 3 => 'bg-rank-bronze'];
    // One row of a replay list: the whole row is the link, the play square at its end shows where it leads.
    $row = 'group grid min-h-14 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 rounded-md px-3 py-2 text-ink hover:bg-row-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-btc';
    $playSquare = 'flex size-11 shrink-0 items-center justify-center rounded-md border border-line bg-well text-btc-hi group-hover:border-btc group-hover:bg-btc group-hover:text-on-btc';
    $section = 'flex min-w-0 flex-col gap-4 rounded-lg bg-card px-2 py-4 lg:px-4 lg:py-5';
@endphp

<div class="flex flex-col gap-6 px-4 pt-6 pb-12 lg:gap-8 lg:px-12 lg:pt-8" data-test="replays-page">
    {{-- Header: the game's cover, the page's name (or the player's), and Play --}}
    <header class="flex min-w-0 flex-wrap items-center gap-x-6 gap-y-4">
        <x-game-cover game="blockfill" size="thumb" loading="eager" class="w-24 rounded-md lg:w-32" />
        <div class="flex min-w-0 grow basis-60 flex-col gap-2">
            @if ($filtered)
                <a href="{{ route('stacker.replays') }}" class="inline-flex min-h-11 items-center gap-1.5 self-start text-[13px] text-ink-2 hover:text-ink" data-test="replays-all">
                    <x-icon name="prev" :size="14" />{{ __('All replays') }}
                </a>
            @endif
            <h1 class="m-0 flex min-w-0 items-center gap-3 font-display text-[26px] leading-[1.15] font-bold lg:text-4xl" data-test="replays-title">
                @if ($subject)
                    <span aria-hidden="true" class="shrink-0"><x-avatar :user="$subject" :size="40" class="rounded-md" /></span>
                    <span class="min-w-0 truncate">{{ __('Replays of :name', ['name' => $subject->displayName()]) }}</span>
                @else
                    {{ __('Replays') }}
                @endif
            </h1>
        </div>
        <x-button :href="route('stacker.play')" icon="bolt" class="h-14 px-6 text-base max-sm:grow" data-test="replays-play">{{ __('Play Blockfill') }}</x-button>
    </header>

    @if ($filtered)
        {{-- One player's replays: theirs that the viewer may watch, all of them for the player themselves --}}
        <section aria-labelledby="theirs-h" class="{{ $section }} lg:max-w-[720px]" data-test="replays-player">
            <h2 id="theirs-h" class="sr-only">{{ $subject ? __('Replays of :name', ['name' => $subject->displayName()]) : __('Replays') }}</h2>
            @if ($this->theirs === [])
                <x-empty-state class="px-2" :heading="__('No replay to watch yet')"
                               :text="__('An ended week\'s first ten replays are public. Play a ranked run and set a time of your own.')" data-test="replays-player-empty">
                    <x-button :href="route('stacker.play')">{{ __('Play Blockfill') }}</x-button>
                </x-empty-state>
            @else
                @include('pages.stacker.partials.replay-runs', ['entries' => $this->theirs, 'test' => 'replays-player-row'])
            @endif
        </section>
    @else
        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,400px)_minmax(0,1fr)] lg:gap-8">
            {{-- The left column: your replays, and for admins the held runs --}}
            <div class="flex min-w-0 flex-col gap-6 lg:gap-8">
                <section aria-labelledby="mine-h" class="{{ $section }}" data-test="replays-mine">
                    <h2 id="mine-h" class="m-0 px-2 text-[15px] font-bold">{{ __('Your replays') }}</h2>
                    @if ($viewer === null)
                        <x-empty-state class="px-2" :heading="__('Your runs, played again')" :text="__('Log in and play ranked runs: the replays the league keeps of yours show up here.')" data-test="replays-mine-guest">
                            <x-button :href="route('login')">{{ __('Log in') }}</x-button>
                            <x-button :href="route('stacker.play')" variant="secondary">{{ __('Play Blockfill') }}</x-button>
                        </x-empty-state>
                    @elseif ($this->mine === [])
                        <x-empty-state class="px-2" :heading="__('No replay of yours yet')" :text="__('Play ranked runs: the replays the league keeps of yours show up here.')" data-test="replays-mine-empty">
                            <x-button :href="route('stacker.play')">{{ __('Play Blockfill') }}</x-button>
                        </x-empty-state>
                    @else
                        @include('pages.stacker.partials.replay-runs', ['entries' => $this->mine, 'test' => 'replays-mine-row'])
                    @endif
                </section>

                @if ($held['total'] > 0)
                    <section aria-labelledby="held-h" class="{{ $section }}" data-test="replays-held">
                        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-2">
                            <h2 id="held-h" class="m-0 text-[15px] font-bold">{{ __('Held for a check') }}</h2>
                            <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-md bg-btc-chip px-2 text-[13px] font-bold text-btc-hi tabular-nums" data-test="replays-held-count">{{ $held['total'] }}</span>
                        </div>
                        <ul class="m-0 flex list-none flex-col p-0">
                            @foreach ($held['runs'] as $run)
                                <li wire:key="held-{{ $run->id }}">
                                    <a href="{{ route('stacker.replay', $run) }}" class="{{ $row }}" data-test="replays-held-row">
                                        <span class="flex min-w-0 items-center gap-3">
                                            @if ($run->user)
                                                <span aria-hidden="true" class="shrink-0"><x-avatar :user="$run->user" :size="28" class="rounded-sm" /></span>
                                            @endif
                                            <span class="flex min-w-0 flex-col">
                                                <b class="truncate text-[13px] whitespace-nowrap">{{ $run->user?->displayName() ?? __('Deleted player') }}</b>
                                                <span class="text-xs text-ink-2">{{ trans_choice(':count hint|:count hints', count(StackerReplays::hintLines($run))) }}</span>
                                            </span>
                                            <b class="ml-auto shrink-0 font-display text-[15px] tabular-nums">{{ $this->time($run) }}</b>
                                        </span>
                                        <span class="{{ $playSquare }}"><x-icon name="play" :size="18" /><span class="sr-only">{{ __('Watch replay') }}</span></span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        <x-button :href="route('admin.blockfill')" variant="secondary" class="mx-2 self-start" data-test="replays-review-list">{{ __('Open the review list') }}</x-button>
                    </section>
                @endif
            </div>

            {{-- Top replays: an ended week's first ten, the first place large --}}
            <section aria-labelledby="top-h" class="{{ $section }}" data-test="replays-top">
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-2">
                    <h2 id="top-h" class="m-0 text-[15px] font-bold">{{ $shown ? __('Top replays of :week', ['week' => $this->weekLabel($shown)]) : __('Top replays') }}</h2>
                    @if (count($weeks) > 1)
                        <nav aria-label="{{ __('Finished weeks') }}" data-test="replays-weeks">
                            <ul class="m-0 flex list-none flex-wrap gap-1 p-0">
                                @foreach ($weeks as $week)
                                    <li>
                                        <a href="{{ route('stacker.replays', $week->is($weeks[0]) ? [] : ['week' => $week->slug]) }}" @if ($shown?->is($week)) aria-current="page" @endif
                                           class="inline-flex h-11 items-center rounded-md border px-3 text-[13px] tabular-nums aria-[current=page]:border-btc aria-[current=page]:bg-btc-chip aria-[current=page]:font-bold aria-[current=page]:text-btc-hi border-line text-ink-2 hover:text-ink"
                                           data-test="replays-week">{{ $this->weekLabel($week, false) }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </nav>
                    @endif
                </div>

                @if ($featured === null)
                    <x-empty-state class="px-2" :heading="$shown ? __('No replay kept from this week') : __('No week has ended yet')"
                                   :text="__('Each Monday the first ten of the week just ended go public here. Set a time and be among them.')" data-test="replays-top-empty">
                        <x-button :href="route('stacker.play')">{{ __('Play Blockfill') }}</x-button>
                    </x-empty-state>
                @else
                    @php($user = $featured['run']->user)
                    {{-- The week's winner: the medal, the face, the time large, and Watch --}}
                    <a href="{{ $featured['href'] }}" class="group mx-0 grid min-w-0 grid-cols-[auto_minmax(0,1fr)] items-center gap-x-4 gap-y-4 rounded-lg bg-raised p-4 text-ink shadow-[inset_0_0_0_1px_var(--color-line)] hover:text-ink hover:shadow-[inset_0_0_0_1px_var(--color-rank-gold)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-btc sm:grid-cols-[auto_minmax(0,1fr)_auto] lg:p-6"
                       data-test="replays-featured">
                        <span class="relative shrink-0" aria-hidden="true">
                            @if ($user)
                                <x-avatar :user="$user" :size="72" class="rounded-md" />
                            @endif
                            <span class="absolute -right-2 -bottom-2 flex size-8 items-center justify-center rounded-full bg-rank-gold font-display text-[15px] font-bold text-on-btc ring-4 ring-raised">1</span>
                        </span>
                        <span class="flex min-w-0 flex-col gap-1">
                            <span class="sr-only">{{ __('Place :place', ['place' => 1]) }}</span>
                            <b class="truncate text-[15px] whitespace-nowrap" data-test="replays-featured-name">{{ $featured['name'] }}</b>
                            <b class="font-display text-[36px] leading-none font-bold tabular-nums lg:text-[48px]" data-test="replays-featured-time">{{ $this->time($featured['run']) }}</b>
                        </span>
                        <span class="col-span-2 inline-flex h-12 items-center justify-center gap-2 rounded-md bg-btc px-6 text-[15px] font-bold text-on-btc sm:col-span-1">
                            <x-icon name="play" :size="18" />{{ __('Watch replay') }}
                        </span>
                    </a>

                    @if ($rest !== [])
                        <ol class="m-0 flex list-none flex-col p-0" data-test="replays-top-list">
                            @foreach ($rest as $entry)
                                @php($user = $entry['run']->user)
                                <li wire:key="top-{{ $entry['run']->id }}">
                                    <a href="{{ $entry['href'] }}" class="{{ $row }}" data-test="replays-top-row" data-place="{{ $entry['place'] }}">
                                        {{-- Below sm the time goes under the name: beside it, the name kept 9 letters at 375 px --}}
                                        <span class="grid min-w-0 grid-cols-[32px_minmax(0,1fr)] items-center gap-x-3 sm:grid-cols-[32px_minmax(0,1fr)_auto]">
                                            @if (isset($medal[$entry['place']]))
                                                <span class="flex size-7 items-center justify-center justify-self-center rounded-full font-display text-[13px] font-bold text-on-btc tabular-nums {{ $medal[$entry['place']] }}">{{ $entry['place'] }}</span>
                                            @else
                                                <span class="text-center font-display text-base font-bold text-ink-2 tabular-nums">{{ $entry['place'] }}</span>
                                            @endif
                                            <span class="flex min-w-0 items-center gap-2.5">
                                                @if ($user)
                                                    <span aria-hidden="true" class="shrink-0"><x-avatar :user="$user" :size="28" class="rounded-sm" /></span>
                                                @endif
                                                <span class="flex min-w-0 flex-col">
                                                    <b class="truncate text-[13px] whitespace-nowrap">{{ $entry['name'] }}</b>
                                                    <b class="font-display text-[15px] tabular-nums sm:hidden">{{ $this->time($entry['run']) }}</b>
                                                </span>
                                            </span>
                                            <b class="font-display text-[15px] tabular-nums max-sm:hidden">{{ $this->time($entry['run']) }}</b>
                                        </span>
                                        <span class="{{ $playSquare }}"><x-icon name="play" :size="18" /><span class="sr-only">{{ __('Watch replay') }}</span></span>
                                    </a>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                @endif
            </section>
        </div>
    @endif
</div>
