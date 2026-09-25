<section aria-labelledby="hr-h" class="flex flex-col gap-1 rounded-lg bg-card p-4 lg:gap-1.5 lg:px-6 lg:py-5">
    <span class="flex items-baseline justify-between">
        <h2 id="hr-h" class="m-0 text-[15px] font-bold">
            <span class="lg:hidden">{{ __('Clan Hashrate, this week') }}</span>
            <span class="hidden lg:inline">{{ __('Clan Hashrate · this week') }}</span>
        </h2>
        <a href="{{ route('clans.index') }}" class="inline-flex min-h-11 items-center text-xs lg:min-h-6">{{ __('All :n clans', ['n' => 8]) }}</a>
    </span>
    <p class="mt-0 mb-1.5 text-xs leading-[1.6] text-ink-2 lg:mb-2">
        {{ __('Points from every game a member plays: win 3, draw 2, loss 1, won team match +5.') }}<span class="hidden lg:inline"> {{ __('Showing up counts.') }}</span>
    </p>

    @foreach ($hashrate['rows'] as $row)
        <a href="{{ route('clans.show', strtolower($row['tag'])) }}" title="{{ $row['tip'] }}"
           class="tr grid min-h-11 grid-cols-[16px_36px_minmax(0,1fr)_32px] items-center gap-2 rounded-sm text-xs text-ink hover:text-ink lg:h-10 lg:min-h-0 lg:grid-cols-[20px_190px_minmax(0,1fr)_44px] lg:gap-3 lg:px-2 lg:text-[13px]">
            <span class="text-ink-3">{{ $row['rank'] }}</span>
            <span class="flex min-w-0 items-center gap-2">
                <span class="inline-flex h-5 w-full shrink-0 items-center justify-center rounded-sm bg-btc-tint px-1 text-[10px] font-bold text-btc lg:w-auto lg:min-w-[34px]">{{ $row['tag'] }}</span>
                <span class="hidden truncate lg:inline">{{ $row['name'] }}</span>
            </span>
            <span class="flex min-w-0 flex-col gap-1 lg:block">
                <span class="truncate lg:hidden">{{ $row['name'] }}</span>
                <span class="block h-2 rounded-r-[3px] bg-raised lg:h-3.5 lg:rounded-r-sm">
                    <span class="block h-2 animate-fill rounded-r-[3px] bg-btc lg:h-3.5 lg:rounded-r-sm" style="width: {{ $row['width'] }}"></span>
                </span>
            </span>
            <b class="text-right">{{ $row['points'] }}</b>
        </a>
    @endforeach

    <span class="pt-1.5 text-xs text-ink-3 lg:mt-1.5 lg:pt-0">{{ __(':n points this week across all clans.', ['n' => $hashrate['total']]) }}</span>
</section>
