<?php

use App\Models\StackerRun;
use App\Models\Tournament;
use App\Support\Cards\ShareCard;
use App\Support\PageMeta;
use App\Support\Stacker\BlockfillMoments;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerReplays;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * A player's Blockfill moment (BlockfillMoments): the page a share post
 * links last, `/scores/blockfill/moment/{run}`. Public, for guests too, its
 * share card as the link preview; never indexed (one page per run). Only a
 * verified run that is a moment has one: anything else is a 404. Its owner
 * gets the share button; everyone else the way to play.
 *
 * The page is the run's replay (components/stacker/replay-viewer) under the
 * moment's headline: sharing a moment is its owner's consent to show that
 * one run, so this URL plays it for anyone, guests included. It opens no
 * other run: StackerReplays::canView() and `stacker.replay` stay as they
 * are. A shared moment keeps its replay (StackerRuns::keepWeekTop()); a run
 * that has none any more shows its share card and Play instead.
 */
new #[Layout('layouts::app', ['scripts' => ['resources/js/stacker/page.js']])] class extends Component {
    #[Locked]
    public int $runId;

    public function mount(string $run): void
    {
        $found = app(BlockfillMoments::class)->run($run);
        abort_if($found === null, 404);

        $this->runId = $found->id;
    }

    #[Computed]
    public function stackerRun(): StackerRun
    {
        $run = app(BlockfillMoments::class)->run($this->runId);
        abort_if($run === null, 404);

        return $run;
    }

    /**
     * @return array{kind: 'final'|'first'|'pb'|'place', place: int|null, final: bool, pb: bool, first: bool, week: string}
     */
    #[Computed]
    public function moment(): array
    {
        $moment = app(BlockfillMoments::class)->of($this->stackerRun);
        abort_if($moment === null, 404);

        return $moment;
    }

    #[Computed]
    public function week(): ?Tournament
    {
        return app(BlockfillWeeks::class)->find(BlockfillWeeks::startOf($this->stackerRun->submitted_at));
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $run = $this->stackerRun;
        $name = $run->user->displayName();
        $headline = BlockfillMoments::headline($this->moment['kind'], $this->moment['place']);
        $time = BlockfillMoments::time((int) $run->ticks);
        $card = ShareCard::blockfill($run, $this->moment);
        $title = __(':name in Blockfill: :time', ['name' => $name, 'time' => $time]);

        $view->title($title);
        $meta = app(PageMeta::class);
        $meta->noindex = true;
        $meta->title = $title;
        $meta->description = __(':headline: :name mined 40 blocks in :time in :week. Every run is replayed by the league before it counts.', [
            'headline' => $headline, 'name' => $name, 'time' => $time, 'week' => BlockfillMoments::weekTitle($this->moment['week']),
        ]);
        $meta->url = route('stacker.moment', $run->id);
        $meta->images = [[$card->url('wide'), ShareCard::FORMATS['wide'][0], ShareCard::FORMATS['wide'][1], $headline.' · '.$name.' · '.$time]];
    }
}; ?>

@php
    $run = $this->stackerRun;
    $moment = $this->moment;
    $user = $run->user;
    $card = ShareCard::blockfill($run, $moment);
    $week = $this->week;
    $own = auth()->id() === $run->user_id;
    $headline = BlockfillMoments::headline($moment['kind'], $moment['place']);
    $time = BlockfillMoments::time((int) $run->ticks);
@endphp

<div class="flex grow flex-col px-4 pb-10 lg:px-12" data-test="blockfill-moment">
    @if (StackerReplays::watchable($run))
        <x-stacker.replay-viewer :run="$run">
            @include('pages.stacker.partials.moment-head')
            @if ($week)
                <x-slot:actions>
                    <x-button variant="quiet" :href="route('tournaments.scores', $week)">{{ __('Week\'s board') }}</x-button>
                </x-slot:actions>
            @endif
        </x-stacker.replay-viewer>
    @else
        {{-- The replay is gone: the moment's card and the way to play --}}
        <div class="mx-auto flex w-full max-w-[960px] flex-col gap-6 pt-6 lg:gap-8 lg:pt-8">
            <header class="flex min-w-0 flex-col gap-3">
                @include('pages.stacker.partials.moment-head')
            </header>
            <img src="{{ $card->path('wide') }}" alt="{{ $headline }} · {{ $user->displayName() }} · {{ $time }}" width="1200" height="630"
                 class="aspect-[1200/630] h-auto w-full rounded-lg shadow-ring" data-test="blockfill-moment-card">
        </div>
    @endif

    @if ($own)
        <livewire:blockfill-share key="blockfill-share" />
    @endif
</div>
