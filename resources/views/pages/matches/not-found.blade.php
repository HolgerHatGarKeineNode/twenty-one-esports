{{-- "Match not found" from States.dc.html: an unknown or not-yet-known match number (404). --}}
<x-layouts::app :title="__('Match :number not found', ['number' => '#'.$number])" section="matches">
    <div class="flex grow items-center justify-center px-4 py-16">
        <div class="flex w-full max-w-[560px] flex-col items-center gap-3 rounded-lg bg-card px-6 py-10 text-center" data-test="match-not-found">
            <h1 class="m-0 font-display text-[26px] font-bold">{{ __('Match :number not found', ['number' => '#'.$number]) }}</h1>
            <p class="m-0 flex items-center gap-2 text-[13px] text-ink-2"><span class="size-3.5 animate-spin rounded-full border-2 border-btc border-t-transparent" aria-hidden="true"></span>{{ __('Still syncing, give it a few seconds …') }}</p>
            @if ($latest)
                <p class="m-0 text-xs text-ink-3">{{ __('The latest confirmed match is :number.', ['number' => '#'.$latest]) }}</p>
            @endif
            <x-button variant="quiet" :href="route('matches.index')" class="mt-2">{{ __('Latest matches') }}</x-button>
        </div>
    </div>
</x-layouts::app>
