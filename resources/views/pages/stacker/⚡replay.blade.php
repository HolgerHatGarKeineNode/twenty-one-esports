<?php

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Games\ScoreMetric;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\PageMeta;
use App\Support\Stacker\StackerReplays;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * The replay of one Blockfill run (plan "Blockfill", P5): the stored inputs
 * played again on the shared engine and drawn by the game's own renderer
 * (resources/js/stacker/replay-page.js), inside `wire:ignore`. The chain of
 * 40 mined blocks is the seek bar: a cube stands at the moment its row was
 * cleared, a click jumps there. Play at 0.5x to 4x, step one tick.
 *
 * Who may watch: StackerReplays::canView() (everybody a verified run, the
 * player and admins a held one). A run without a replay to show answers
 * 404, one the viewer may not watch 403. Of the player only their avatar
 * and name show.
 */
new #[Layout('layouts::app', ['scripts' => ['resources/js/stacker/page.js']])] class extends Component
{
    public StackerRun $run;

    public function mount(StackerRun $run): void
    {
        abort_unless(StackerReplays::watchable($run), 404);
        abort_unless(app(StackerReplays::class)->canView($this->viewer(), $run), 403);
        $this->run = $run;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Blockfill replay'));
        app(PageMeta::class)->describe(__('Blockfill replay'), __('A Blockfill run played again from its inputs, block by block.'));
    }

    #[Computed]
    public function week(): ?Tournament
    {
        return app(StackerReplays::class)->weekOf($this->run);
    }

    public function viewer(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}; ?>

@php
    $run = $this->run;
    $user = $run->user;
    $metric = ScoreMetric::time();
    $time = $metric->format(Blockfill::milliseconds((int) $run->ticks));
    $week = $this->week;
    $viewer = $this->viewer();
    $held = $run->status === StackerRunStatus::Review;
    $hints = $viewer?->isAdmin() ? StackerReplays::hintLines($run) : [];
@endphp

<div class="flex grow flex-col px-4 pb-10 lg:px-12" data-test="replay-page">
    <x-stacker.replay-viewer :run="$run" :hints="$hints">
        <h1 class="m-0 font-display text-[24px] leading-[1.15] font-extrabold lg:text-[36px]" data-test="replay-title">{{ __('Replay of :time', ['time' => $time]) }}</h1>
        <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-ink-2">
            <span class="flex min-w-0 items-center gap-2" data-test="replay-player">
                @if ($user)
                    <x-avatar :user="$user" :size="28" class="shrink-0 rounded-sm" />
                    <b class="min-w-0 truncate text-[15px] whitespace-nowrap text-ink">{{ $user->displayName() }}</b>
                @else
                    <b class="text-[15px] whitespace-nowrap text-ink-2">{{ __('Deleted player') }}</b>
                @endif
            </span>
            @if ($week)
                <a href="{{ route('tournaments.scores', $week) }}" class="inline-flex min-h-11 items-center font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink" data-test="replay-week">{{ $week->title() }}</a>
            @endif
        </div>
        @if ($held)
            <p class="m-0 self-start rounded-xs bg-btc-chip px-2 py-1 text-xs leading-snug font-bold text-btc-hi shadow-[inset_0_0_0_1px_var(--color-btc-ring)]" data-test="replay-held">{{ __('Held for an admin\'s check: counts once approved') }}</p>
        @endif
        <x-slot:actions>
                <x-button :href="route('stacker.play')" data-test="replay-play-game">{{ __('Play Blockfill') }}</x-button>
                @if ($week)
                    <x-button variant="quiet" :href="route('tournaments.scores', $week)">{{ __('Week\'s board') }}</x-button>
                @endif
        </x-slot:actions>
    </x-stacker.replay-viewer>
</div>
