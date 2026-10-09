{{--
    Hyperbitcoinization on the player page (plan "Hyperbitcoinization", P3, PlayerStats::hyper()): the sats
    collected as loot in finished matches, game points only (no money, nothing paid out), with the matches
    played and won. A card of the ladder grid in partials/stats, shaped like the cards next to it.

    $hyper: array{sats: float, matches: int, wins: int, cups?: int} (`cups`: weekend cups won, P5, the winner badge)
--}}
<li wire:key="hyper-card" class="flex min-w-0 flex-col gap-3 rounded-card bg-card p-4" data-test="player-hyper" data-game="hyperbitcoinization">
    <a href="{{ \Illuminate\Support\Facades\Route::has('hyper.index') ? route('hyper.index') : url('hyperbitcoinization') }}" class="grid min-h-11 grid-cols-[48px_minmax(0,1fr)] items-center gap-3 text-ink hover:text-ink">
        <x-game-cover :game="\App\Games\Hyperbitcoinization::SLUG" size="thumb" class="w-12 rounded-tag" />
        <span class="flex min-w-0 flex-col">
            <b class="truncate text-[13px]">Hyperbitcoinization</b>
            <span class="truncate text-xs text-ink-3">{{ trans_choice(':count finished match|:count finished matches', $hyper['matches']) }}</span>
        </span>
    </a>
    <span class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <b class="font-display text-[30px] leading-none font-bold tabular-nums" data-test="player-hyper-sats">{{ rtrim(rtrim(number_format($hyper['sats'], 1, '.', ''), '0'), '.') }}</b>
        <span class="text-xs text-ink-3">{{ __('M sats collected') }}</span>
        <span class="ml-auto text-xs text-ink-3" data-test="player-hyper-wins">{{ trans_choice(':count win|:count wins', $hyper['wins']) }}</span>
    </span>
    @if (($hyper['cups'] ?? 0) > 0)
        <span class="inline-flex h-7 items-center gap-1.5 self-start rounded-tag bg-btc-chip px-2.5 text-xs font-bold text-btc-hi" data-test="player-hyper-cups">
            <x-icon name="trophy" :size="14" />{{ trans_choice('Weekend Cup winner|Weekend Cup winner ×:count', $hyper['cups']) }}
        </span>
    @endif
    <span class="text-xs text-ink-3">{{ __('Loot from the matches. Game points, no money.') }}</span>
</li>
