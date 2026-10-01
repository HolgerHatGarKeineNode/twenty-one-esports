{{--
    The head of a moment's page (pages/stacker/⚡moment): what the moment is, whose run, its time and week, and the one
    action, Play Blockfill, large; its owner also gets Share. $run, $moment, $user, $week, $own, $headline, $time.
--}}
<span class="text-[13px] font-bold text-btc-hi" data-test="blockfill-moment-headline">{{ $headline }}</span>
<h1 class="m-0 flex min-w-0 items-center gap-3 font-display text-[24px] leading-[1.15] font-bold lg:text-[32px]">
    <span aria-hidden="true" class="shrink-0"><x-avatar :user="$user" :size="40" class="rounded-sm" /></span>
    <a href="{{ route('players.show', $user->npub) }}" class="min-w-0 truncate text-ink hover:text-btc-hi" data-test="blockfill-moment-player">{{ $user->displayName() }}</a>
</h1>
<p class="m-0 flex flex-wrap items-baseline gap-x-3 gap-y-1">
    <b class="font-display text-[40px] leading-none font-extrabold tabular-nums lg:text-[48px]" data-test="blockfill-moment-time">{{ $time }}</b>
    @if ($week)
        <a href="{{ route('tournaments.scores', $week) }}" class="inline-flex min-h-11 items-center text-[13px] font-bold text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink" data-test="blockfill-moment-week">{{ \App\Support\Stacker\BlockfillMoments::weekTitle($moment['week']) }}</a>
    @else
        <span class="text-[13px] text-ink-2">{{ \App\Support\Stacker\BlockfillMoments::weekTitle($moment['week']) }}</span>
    @endif
</p>
<div class="flex flex-wrap gap-3 pt-1">
    <x-button :href="route('stacker.play')" icon="bolt" class="h-14 grow px-6 text-base sm:grow-0" data-test="blockfill-moment-play">{{ __('Play Blockfill') }}</x-button>
    @if ($own)
        <x-button variant="secondary" icon="send" class="h-14 px-5" x-data x-on:click="window.dispatchEvent(new CustomEvent('blockfill-share', { detail: { moment: '{{ $run->id }}' } }))" data-test="blockfill-moment-share">{{ __('Share this moment') }}</x-button>
    @endif
</div>
