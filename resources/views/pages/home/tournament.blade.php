<section aria-labelledby="t-h" class="flex flex-col gap-3 rounded-lg bg-card p-4 lg:gap-3.5 lg:px-6 lg:py-5">
    <span class="flex items-baseline justify-between">
        <h2 id="t-h" class="m-0 text-[15px] font-bold">{{ __('Next tournament') }}</h2>
        <a href="{{ route('tournaments.index') }}" class="inline-flex min-h-11 items-center text-xs lg:min-h-6">{{ __('All tournaments') }}</a>
    </span>
    <a href="{{ route('tournaments.index') }}" class="flex items-baseline justify-between gap-3 text-ink hover:text-ink">
        <span class="font-display text-lg font-bold lg:text-xl">Halving Cup</span>
        <span class="whitespace-nowrap text-xs text-ink-2">{{ __(':n of :total teams in', ['n' => 5, 'total' => 8]) }}</span>
    </a>
    <div role="progressbar" aria-label="{{ __('Teams registered') }}" aria-valuemin="0" aria-valuemax="8" aria-valuenow="5" class="flex h-4 overflow-hidden rounded-sm bg-raised lg:h-5">
        <span class="animate-fill" style="width: 62.5%; background: linear-gradient(90deg, #B9640A, #F7931A)"></span>
    </div>

    <div class="grid grid-cols-2 gap-x-2 gap-y-3 lg:hidden">
        <span class="flex flex-col gap-0.5"><b class="text-sm">{{ __('Rocket League 3v3') }}</b><span class="text-[11px] text-ink-3">{{ __('Bo3, double elimination') }}</span></span>
        <span class="flex flex-col gap-0.5"><b class="text-sm text-btc">{{ __('[POOL] sats') }}</b><span class="text-[11px] text-ink-3">{{ __('open prize pool') }}</span></span>
        <span class="flex flex-col gap-0.5"><b class="text-sm">[DATE]</b><span class="text-[11px] text-ink-3">{{ __('entries close') }}</span></span>
        <span class="flex flex-col gap-0.5"><b class="text-sm">{{ __(':n solo', ['n' => 4]) }}</b><span class="text-[11px] text-ink-3">{{ __('drawn into mix teams') }}</span></span>
    </div>
    <div class="hidden grid-cols-4 gap-3 text-center lg:grid">
        <span class="flex flex-col gap-1"><b class="inline-flex items-center justify-center gap-1.5 text-base"><x-icon name="rocket-league" :size="16" />3v3</b><span class="text-[11px] text-ink-3">Rocket League</span></span>
        <span class="flex flex-col gap-1"><b class="text-base">BO3</b><span class="text-[11px] text-ink-3">{{ __('Double elimination') }}</span></span>
        <span class="flex flex-col gap-1"><b class="text-base text-btc">[POOL]</b><span class="text-[11px] text-ink-3">{{ __('sats, open prize pool') }}</span></span>
        <span class="flex flex-col gap-1"><b class="text-base">[DATE]</b><span class="text-[11px] text-ink-3">{{ __('Entries close') }}</span></span>
    </div>

    <span class="text-xs leading-[1.6] text-ink-2">{{ __('No clan? Enter solo and get drawn into a mix team with a meme name like Tick Tock Next Block.') }}</span>

    <a href="{{ route('tournaments.index') }}" class="tr grid min-h-11 grid-cols-[20px_minmax(0,1fr)] items-center gap-2.5 rounded-md border border-line p-2.5 text-[13px] text-ink hover:text-ink lg:mt-0.5 lg:grid-cols-[20px_minmax(0,1fr)_auto] lg:px-2.5 lg:py-2">
        <span class="inline-flex text-btc lg:hidden"><x-icon name="tournaments" :size="18" /></span>
        <span class="hidden lg:inline-flex"><x-icon name="pawn" :size="18" /></span>
        <span class="flex flex-col gap-1 lg:gap-0.5">
            <span class="lg:hidden">{{ __('Blitz Night, chess 5+3, [DATE]') }}</span>
            <span class="hidden lg:inline">{{ __('Blitz Night · chess 5+3 · [DATE]') }}</span>
            <span class="text-[11px] leading-normal text-ink-3">{{ __('Everyone plays. The sats go to members only.') }}</span>
            <span class="inline-flex h-[22px] items-center self-start whitespace-nowrap rounded-sm bg-btc-tint px-2 text-[11px] font-bold text-btc lg:hidden">{{ __("Members' prize pool") }}</span>
        </span>
        <span class="hidden h-[22px] items-center whitespace-nowrap rounded-sm bg-btc-tint px-2 text-[11px] font-bold text-btc lg:inline-flex">{{ __("Members' prize pool") }}</span>
    </a>
</section>
