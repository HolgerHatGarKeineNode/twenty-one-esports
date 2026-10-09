{{--
    Proof of Pong on the player page (plan "Proof of Pong", P4, PlayerStats::pong() / PongLadder::card()): the Elo
    (provisional until enough rated matches; the start value until the first), the place on the ladder, wins and
    losses of the finished live matches and the figure the player picked most. A card of the ladder grid in
    partials/stats, shaped like the cards next to it.

    $pong: array{rating: int, provisional: bool, rated: bool, rank: int|null, matches: int, wins: int, losses: int, figure: array{id: string, name: string}|null}
--}}
<li wire:key="pong-card" class="flex min-w-0 flex-col gap-3 rounded-card bg-card p-4" data-test="player-pong" data-game="{{ \App\Games\ProofOfPong::SLUG }}">
    <a href="{{ \Illuminate\Support\Facades\Route::has('pong.ladder') ? route('pong.ladder') : url('proof-of-pong') }}" class="grid min-h-11 grid-cols-[48px_minmax(0,1fr)] items-center gap-3 text-ink hover:text-ink">
        <x-game-cover :game="\App\Games\ProofOfPong::SLUG" size="thumb" class="w-12 rounded-tag" />
        <span class="flex min-w-0 flex-col">
            <b class="truncate text-[13px]">Proof of Pong</b>
            <span class="truncate text-xs text-ink-3">{{ trans_choice(':count finished match|:count finished matches', $pong['matches']) }}</span>
        </span>
    </a>
    <span class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <b class="font-display text-[30px] leading-none font-bold tabular-nums" data-test="player-pong-elo">{{ $pong['rated'] ? $pong['rating'] : '–' }}</b>
        <span class="text-xs text-ink-3">{{ $pong['rated'] && $pong['provisional'] ? __('Elo, provisional') : __('Elo') }}</span>
        @if ($pong['rank'] !== null)
            <span class="text-xs text-ink-2" data-test="player-pong-rank">#{{ $pong['rank'] }}</span>
        @endif
        <span class="ml-auto text-xs text-ink-3 tabular-nums" data-test="player-pong-record">{{ trans_choice(':count win|:count wins', $pong['wins']) }} · {{ trans_choice(':count loss|:count losses', $pong['losses']) }}</span>
    </span>
    @if ($pong['figure'] !== null)
        <span class="flex min-w-0 items-center gap-2 text-xs text-ink-2" data-test="player-pong-figure">
            <img src="/pong/art/por-{{ $pong['figure']['id'] }}.webp" alt="" width="32" height="32" loading="lazy" class="size-8 shrink-0 rounded-sm bg-ground object-contain object-bottom">
            <span class="min-w-0 truncate">{{ __('Plays as :name', ['name' => $pong['figure']['name']]) }}</span>
        </span>
    @endif
</li>
