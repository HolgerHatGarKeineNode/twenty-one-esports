<?php

use App\Models\StackerRun;
use App\Models\Tournament;
use App\Support\Cards\ShareCard;
use App\Support\PageMeta;
use App\Support\Stacker\BlockfillMoments;
use App\Support\Stacker\BlockfillWeeks;
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
 */
new #[Layout('layouts::app', ['section' => 'tournaments'])] class extends Component {
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
@endphp

<div class="mx-auto flex w-full max-w-[960px] flex-col gap-6 px-4 pt-6 pb-12 lg:gap-8 lg:px-12 lg:pt-8" data-test="blockfill-moment">
    <header class="flex min-w-0 flex-col gap-2">
        <span class="text-[13px] font-bold text-btc" data-test="blockfill-moment-headline">{{ BlockfillMoments::headline($moment['kind'], $moment['place']) }}</span>
        <h1 class="m-0 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2 font-display text-[28px] leading-[1.15] font-bold lg:text-4xl">
            <x-avatar :user="$user" :size="40" class="shrink-0 rounded-sm" />
            <a href="{{ route('players.show', $user->npub) }}" class="min-w-0 text-ink [overflow-wrap:anywhere] hover:text-btc-hi">{{ $user->displayName() }}</a>
        </h1>
        <p class="m-0 flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <b class="font-display text-[40px] leading-none font-extrabold tabular-nums lg:text-[48px]" data-test="blockfill-moment-time">{{ BlockfillMoments::time((int) $run->ticks) }}</b>
            <span class="text-[13px] text-ink-2">{{ __('40 blocks mined') }} · @if ($week)<a href="{{ route('tournaments.show', $week) }}" class="text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink">{{ BlockfillMoments::weekTitle($moment['week']) }}</a>@else{{ BlockfillMoments::weekTitle($moment['week']) }}@endif</span>
        </p>
    </header>

    <img src="{{ $card->path('wide') }}" alt="{{ BlockfillMoments::headline($moment['kind'], $moment['place']) }} · {{ $user->displayName() }} · {{ BlockfillMoments::time((int) $run->ticks) }}" width="1200" height="630"
         class="aspect-[1200/630] h-auto w-full rounded-lg shadow-ring" data-test="blockfill-moment-card">

    <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2">{{ __('Verified: the league replayed every input of this run and reached the same time.') }}</p>

    <div class="flex flex-wrap gap-2">
        @if ($own)
            <x-button icon="send" x-data x-on:click="window.dispatchEvent(new CustomEvent('blockfill-share', { detail: { moment: @js((string) $run->id) } }))" data-test="blockfill-moment-share">{{ __('Share this moment') }}</x-button>
        @endif
        <x-button :href="route('stacker.play')" :variant="$own ? 'secondary' : 'primary'" data-test="blockfill-moment-play">{{ __('Play Blockfill') }}</x-button>
    </div>

    @if ($own)
        <livewire:blockfill-share />
    @endif
</div>
