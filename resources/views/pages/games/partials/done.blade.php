{{--
    A finished or aborted game (ChessGameDone.dc.html): result, facts, replay
    with the move list as buttons, and the game file (PGN) to copy or
    download. Casual in P5a: the Elo and Hashrate columns of the design say so.
--}}
@php
    use App\Enums\ChessGameStatus;
    use App\Support\Chess\ChessPgn;

    $aborted = $game->status === ChessGameStatus::Aborted;
    $winner = match ($game->result) { '1-0' => 'w', '0-1' => 'b', default => null };
    $result = str_replace(['1/2', '-'], ['½', '–'], (string) $game->result);
    $reason = $game->end_reason ? __($game->end_reason->label()) : '';
    $headline = $aborted
        ? __('Game aborted')
        : ($winner === null ? __('Draw · :reason', ['reason' => mb_strtolower($reason)]) : __(':name wins · :reason', ['name' => $players[$winner]['name'], 'reason' => mb_strtolower($reason)]));
    $replay = $this->replay();
    $lastMove = $game->moves->last();
    $clock = fn (int $ms) => intdiv(intdiv($ms + 999, 1000), 60).':'.str_pad((string) (intdiv($ms + 999, 1000) % 60), 2, '0', STR_PAD_LEFT);
    $moveCount = intdiv($game->ply + 1, 2);
    $pgn = ChessPgn::of($game);
@endphp

<div class="flex flex-col gap-5 px-4 pt-5 pb-8 lg:px-12 lg:pt-7 lg:pb-10" data-test="chess-game-done">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ __('Game') }}</h1>
        <span class="text-sm text-btc">{{ $game->number() }}</span>
        <button type="button" aria-label="{{ __('Copy game link') }}" x-data x-on:click="navigator.clipboard?.writeText(window.location.href)"
                class="flex size-8 cursor-pointer items-center justify-center rounded-md bg-well text-ink-2"><x-icon name="copy" :size="14" /></button>
        <span class="grow"></span>
        <span class="text-[13px] text-btc">{{ __('Casual · Blitz 5+3') }}</span>
        @unless ($aborted)
            <span class="flex h-[34px] items-center gap-2 rounded-md bg-[#122016] px-3 text-[13px] font-bold text-win"><x-icon name="check" :size="16" />{{ __('Saved') }}</span>
        @endunless
    </div>

    {{-- Result banner --}}
    <section aria-label="{{ __('Result') }}" class="flex flex-col gap-5 rounded-lg bg-[linear-gradient(90deg,#241A0C,#121215_60%)] px-5 py-6 lg:flex-row lg:items-center lg:gap-9 lg:px-7 lg:py-8">
        <b class="font-display text-[56px] leading-none font-extrabold text-btc lg:text-[80px]" data-test="final-result">{{ $aborted ? '–' : $result }}</b>
        <span class="flex min-w-0 grow flex-col gap-2">
            <span class="font-display text-lg font-bold lg:text-[22px]">{{ $headline }}</span>
            <span class="text-xs text-ink-2 lg:text-[13px]">
                @if ($aborted)
                    {{ __('No result, nothing recorded: the game ended before both sides made their first move.') }}
                @else
                    {{ $lastMove ? $moveCount.'. '.($game->ply % 2 === 0 ? '… ' : '').$lastMove->san.' · ' : '' }}{{ __('Blitz 5+3') }} · {{ trans_choice(':count move|:count moves', $moveCount) }} · {{ __('time left :white vs :black', ['white' => $clock($game->white_ms), 'black' => $clock($game->black_ms)]) }}
                @endif
            </span>
        </span>
        <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-6 gap-y-1.5 text-[13px]">
            @foreach (['w', 'b'] as $pc)
                <span class="flex min-w-0 items-center gap-2"><x-avatar :name="$players[$pc]['name']" :src="$players[$pc]['avatar']" :size="20" class="rounded-sm" /><a href="{{ $players[$pc]['url'] }}" class="truncate text-ink hover:text-ink">{{ $players[$pc]['name'] }}</a>@if ($players[$pc]['member'])<x-member-badge />@endif</span>
                <span class="text-ink-2">{{ $pc === 'w' ? __('White') : __('Black') }} · {{ __('casual') }}</span>
            @endforeach
        </div>
    </section>

    {{-- Facts --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:gap-5">
        <div class="rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach ([
                [__('Played'), ($game->created_at?->isoFormat('ddd YYYY-MM-DD · HH:mm') ?? '').($game->ended_at ? ' '.__('to').' '.$game->ended_at->isoFormat('HH:mm') : '')],
                [__('Time control'), __('Blitz 5+3')],
                [__('Kind'), __('Casual · no rating')],
                [__('Ending'), $aborted ? __('Aborted before both first moves') : __(':reason, decided by the server', ['reason' => $reason])],
            ] as [$key, $value])
                <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center border-b border-hairline py-2 text-sm last:border-0 lg:grid-cols-[180px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span>{{ $value }}</span></div>
            @endforeach
        </div>
        <div class="rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach ([[__('Rating'), __('casual, no Elo before Season 1')], [__('Hashrate'), __('casual games do not count')], [__('Moves'), trans_choice(':count half-move|:count half-moves', $game->ply)], [__('Game number'), $game->number()]] as [$key, $value])
                <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center border-b border-hairline py-2 text-sm last:border-0 lg:grid-cols-[180px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span>{{ $value }}</span></div>
            @endforeach
        </div>
    </div>

    {{-- Replay --}}
    <h2 class="m-0 font-display text-xl font-bold">{{ __('Replay') }}</h2>
    <div x-data="chessReplay(@js([...$replay, 'flipped' => $color === 'b', 'pgn' => $pgn, 'filename' => 'twentyone-game-'.$game->id.'.pgn', 'labels' => ['start' => __('Start position'), 'after' => __('Position after :move')]]))"
         class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,496px)_300px_minmax(0,1fr)] lg:gap-7">
        <div class="flex flex-col gap-4">
            <x-chess.board frame="#F7931A" class="lg:mt-4 lg:max-w-[480px]" />
            <div class="flex items-center gap-3">
                <button type="button" aria-label="{{ __('First move') }}" x-on:click="go(0)" class="btn-w flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink"><x-icon name="first" :size="16" /></button>
                <button type="button" aria-label="{{ __('Previous move') }}" x-on:click="go(index - 1)" class="btn-w flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink"><x-icon name="prev" :size="16" /></button>
                <button type="button" aria-label="{{ __('Next move') }}" x-on:click="go(index + 1)" class="btn-w flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink"><x-icon name="next" :size="16" /></button>
                <button type="button" aria-label="{{ __('Last move') }}" x-on:click="go(fens.length - 1)" class="btn-w flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink"><x-icon name="last" :size="16" /></button>
                <span class="flex min-w-0 flex-col gap-0.5 pl-2">
                    <b class="text-sm" x-text="index === 0 ? '{{ __('Start') }}' : Math.ceil(index / 2) + (index % 2 === 0 ? '… ' : '. ') + moves[index - 1].san"></b>
                    <span class="text-xs text-ink-2" x-text="`${index} / ${fens.length - 1}`"></span>
                </span>
            </div>
        </div>

        <div class="flex flex-col rounded-lg bg-card lg:max-h-[560px]">
            <span class="flex items-baseline justify-between border-b border-hairline px-4 pt-3 pb-2"><span class="text-[15px] font-bold">{{ __('Moves') }}</span><span class="text-[11px] text-ink-3">{{ __('click to jump') }}</span></span>
            <ol class="m-0 list-none overflow-y-auto px-2 py-0" data-test="replay-moves">
                @forelse ($replay['moves'] as $i => $move)
                    @if ($i % 2 === 0)
                        <li class="grid h-11 grid-cols-[40px_minmax(0,1fr)_minmax(0,1fr)] items-center border-b border-hairline px-2 text-sm">
                            <span class="text-ink-3">{{ intdiv($i, 2) + 1 }}.</span>
                    @endif
                            <button type="button" x-on:click="go({{ $i + 1 }})" :aria-current="index === {{ $i + 1 }} ? 'step' : 'false'"
                                    :class="index === {{ $i + 1 }} ? 'bg-btc-press text-btc-hi' : 'text-ink'" class="flex h-9 cursor-pointer items-center rounded-sm border-0 bg-transparent px-1.5 text-left text-sm">{{ $move['san'] }}</button>
                    @if ($i % 2 === 1 || $loop->last)
                        </li>
                    @endif
                @empty
                    <li class="px-2 py-3 text-[13px] text-ink-2">{{ __('No moves were played.') }}</li>
                @endforelse
            </ol>
        </div>

        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-3 rounded-lg bg-card px-5 py-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <b class="text-[15px]">{{ __('Game file') }}</b>
                    <span class="flex gap-2">
                        <x-button variant="quiet" icon="copy" class="whitespace-nowrap" x-on:click="copyMoves()">{{ __('Copy moves') }}</x-button>
                        <x-button variant="quiet" icon="download" class="whitespace-nowrap" x-on:click="download()">{{ __('Download game') }}</x-button>
                    </span>
                </div>
                <span class="text-xs leading-normal text-ink-2">{{ __('All moves with players, result and time control, in the standard chess file format (PGN). Opens in any chess app.') }}</span>
            </div>
            <x-proof toggle="show" class="border-0 bg-proof-fill shadow-[inset_0_0_0_1px_var(--color-proof-ring)]" :rows="[[__('Moves'), __('league server only, not published one by one')], [__('Record'), __('PGN of the game, published on Nostr (NIP-64) from the next release')]]" />
        </div>
    </div>
</div>
