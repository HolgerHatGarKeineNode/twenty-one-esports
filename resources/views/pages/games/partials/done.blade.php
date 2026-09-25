{{--
    A finished or aborted game (ChessGameDone.dc.html): result, facts, replay
    with the move list as buttons, the game file (PGN) to copy or download,
    and the Proof with the players' NIP-64 record. Casual until P7: the Elo
    and Hashrate columns of the design say so.

    Daily games add ChessStates "Deadline missed" when time ran out; a record
    that is not signed or not on a relay yet shows ChessStates "Public record
    delayed". The record is signed by the player's app without a click where
    a signer is at hand (resources/js/chess.js publishRecord), else by button.
--}}
@php
    use App\Enums\ChessEndReason;
    use App\Enums\ChessGameStatus;
    use App\Support\Chess\ChessPgn;
    use App\Support\Nostr\NostrKeys;

    $aborted = $game->status === ChessGameStatus::Aborted;
    $daily = $game->isCorrespondence();
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
    $timeControl = $daily ? __('Daily chess (1 move/day)') : __('Blitz 5+3');
    $record = $game->recordEvent;
    $relays = $record?->deliveries()->get() ?? collect();
    $accepted = $relays->where('accepted', true)->count();
    $configured = count(config('esports.relays', []));
    $recorder = $record === null ? null : ($record->pubkey === $game->white->pubkey ? 'w' : 'b');
    $viewerZone = auth()->user()->timezone ?? config('app.timezone');
    $days = $game->ended_at && $game->created_at ? max(1, (int) ceil($game->created_at->diffInHours($game->ended_at) / 24)) : 1;
    $missedDeadline = $daily && $game->end_reason === ChessEndReason::Timeout;
    $publicState = $record === null ? 'unsigned' : ($configured === 0 ? 'no-relay' : ($accepted > 0 ? 'published' : 'retrying'));
@endphp

<div class="flex flex-col gap-5 px-4 pb-8 lg:px-12 lg:pb-10" data-test="chess-game-done">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ $daily ? __('Daily chess') : __('Game') }}</h1>
        <span class="text-sm text-btc">{{ $game->number() }}</span>
        <button type="button" aria-label="{{ __('Copy game link') }}" x-data x-on:click="navigator.clipboard?.writeText(window.location.href)"
                class="flex size-8 cursor-pointer items-center justify-center rounded-md bg-well text-ink-2"><x-icon name="copy" :size="14" /></button>
        <span class="grow"></span>
        <span class="text-[13px] text-btc">{{ $daily ? __('Casual · Daily chess') : __('Casual · Blitz 5+3') }}</span>
        @unless ($aborted)
            <span class="flex h-[34px] items-center gap-2 rounded-md bg-[#122016] px-3 text-[13px] font-bold text-win" data-test="saved-badge"><x-icon name="check" :size="16" />{{ $record ? __('Saved & verified') : __('Saved') }}</span>
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
                @elseif ($daily)
                    {{ $lastMove ? $moveCount.'. '.($game->ply % 2 === 0 ? '… ' : '').$lastMove->san.' · ' : '' }}{{ __('Daily chess') }} · {{ trans_choice(':count move|:count moves', $moveCount) }} · {{ trans_choice(':count day|:count days', $days) }}
                @else
                    {{ $lastMove ? $moveCount.'. '.($game->ply % 2 === 0 ? '… ' : '').$lastMove->san.' · ' : '' }}{{ __('Blitz 5+3') }} · {{ trans_choice(':count move|:count moves', $moveCount) }} · {{ __('time left :white vs :black', ['white' => $clock($game->white_ms), 'black' => $clock($game->black_ms)]) }}
                @endif
            </span>
        </span>
        <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-6 gap-y-1.5 text-[13px]">
            @foreach (['w', 'b'] as $pc)
                <span class="flex min-w-0 items-center gap-2"><x-avatar :name="$players[$pc]['name']" :src="$players[$pc]['avatar']" :size="20" class="rounded-sm" /><a href="{{ $players[$pc]['url'] }}" class="truncate text-ink hover:text-ink">{{ $players[$pc]['name'] }}</a>@if ($players[$pc]['member'])<x-member-badge />@endif @if ($pc !== ($color ?? null))<x-copy-npub :npub="$players[$pc]['npub']" :name="$players[$pc]['name']" />@endif</span>
                <span class="text-ink-2">{{ $pc === 'w' ? __('White') : __('Black') }} · {{ __('casual') }}</span>
            @endforeach
        </div>
    </section>

    {{-- Deadline missed (ChessStates) --}}
    @if ($missedDeadline && $color !== null)
        @php($lost = $color !== $winner)
        <section aria-labelledby="dl-h" class="flex flex-col gap-3 rounded-lg bg-card px-5 py-4 lg:max-w-[560px]" data-test="deadline-missed">
            <span class="flex items-center justify-between gap-3">
                <span id="dl-h" class="flex items-center gap-2 text-base font-bold {{ $lost ? 'text-loss' : 'text-win' }}"><x-icon name="clock" :size="18" />{{ $lost ? __('Out of time') : __('Won on time') }}</span>
                <b class="font-display text-lg">{{ $result }}</b>
            </span>
            <span class="text-[13px] leading-normal text-ink-2">
                {{ __(':number vs :name, you play :color.', ['number' => $game->number(), 'name' => $players[$color === 'w' ? 'b' : 'w']['name'], 'color' => $color === 'w' ? __('White') : __('Black')]) }}
                {{ $lost ? __('Your time for move :n ran out :at.', ['n' => $moveCount + ($game->ply % 2 === 0 ? 1 : 0), 'at' => $game->ended_at?->timezone($viewerZone)->isoFormat('ddd YYYY-MM-DD HH:mm')]) : __('Their time ran out :at.', ['at' => $game->ended_at?->timezone($viewerZone)->isoFormat('ddd YYYY-MM-DD HH:mm')]) }}
            </span>
            <div class="flex flex-col">
                <div class="grid h-10 grid-cols-[110px_minmax(0,1fr)] items-center border-b border-hairline text-[13px]"><span class="text-ink-2">{{ __('Daily Elo') }}</span><span>{{ __('casual, no Elo change') }}</span></div>
                <div class="grid h-10 grid-cols-[110px_minmax(0,1fr)] items-center border-b border-hairline text-[13px]"><span class="text-ink-2">{{ __('Hashrate') }}</span><span>{{ __('casual games do not count') }}</span></div>
            </div>
            @if ($lost)
                <a href="{{ route('settings.chess') }}" class="text-[13px] font-bold text-ink hover:text-btc-hi">{{ __('Turn on reminders before the deadline') }}</a>
            @endif
        </section>
    @endif

    {{-- Facts --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:gap-5">
        <div class="rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach ([
                [__('Played'), ($game->created_at?->isoFormat('ddd YYYY-MM-DD · HH:mm') ?? '').($game->ended_at ? ' '.__('to').' '.($daily ? $game->ended_at->isoFormat('ddd YYYY-MM-DD · HH:mm') : $game->ended_at->isoFormat('HH:mm')) : '')],
                [__('Time control'), $timeControl],
                [__('Kind'), __('Casual · no rating')],
                [__('Ending'), $aborted ? __('Aborted before both first moves') : __(':reason, decided by the server', ['reason' => $reason])],
            ] as [$key, $value])
                <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center border-b border-hairline py-2 text-sm last:border-0 lg:grid-cols-[180px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span>{{ $value }}</span></div>
            @endforeach
        </div>
        <div class="rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach ([[__('Rating'), __('casual, no Elo before Block 0')], [__('Hashrate'), __('casual games do not count')], [__('Moves'), trans_choice(':count half-move|:count half-moves', $game->ply)], [__('Game number'), $game->number()]] as [$key, $value])
                <div class="grid min-h-11 grid-cols-[120px_minmax(0,1fr)] items-center border-b border-hairline py-2 text-sm last:border-0 lg:grid-cols-[180px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span>{{ $value }}</span></div>
            @endforeach
        </div>
    </div>

    {{-- Public record delayed (ChessStates): nothing for the players to do --}}
    @if (! $aborted && $publicState === 'retrying')
        <section aria-labelledby="pr-h" class="flex flex-col gap-3 rounded-lg bg-card px-5 py-4 lg:max-w-[560px]" data-test="record-delayed">
            <span id="pr-h" class="flex items-center gap-2 text-base font-bold"><x-icon name="wifi" :size="18" class="text-loss" />{{ __('Result counts, the public copy follows') }}</span>
            <span class="text-[13px] leading-normal text-ink-2">{{ __('Your result is final and already saved. Publishing the verifiable copy is taking longer than usual; we retry on our own.') }}</span>
            <div class="flex flex-col text-[13px]">
                <span class="flex h-9 items-center gap-2.5 border-b border-hairline"><x-icon name="check" :size="16" class="text-win" /><span class="grow">{{ __('Result :result, saved', ['result' => $result]) }}</span><span class="text-xs text-win">{{ __('done') }}</span></span>
                <span class="flex h-9 items-center gap-2.5 border-b border-hairline"><x-icon name="check" :size="16" class="text-win" /><span class="grow">{{ __('Game record signed by :name', ['name' => $players[$recorder ?? 'w']['name']]) }}</span><span class="text-xs text-win">{{ __('automatic') }}</span></span>
                <span class="flex h-9 items-center gap-2.5 border-b border-hairline"><x-icon name="close" :size="16" class="text-loss" /><span class="grow">{{ __('Public copy') }}</span><span class="text-xs text-loss">{{ __('retrying') }}</span></span>
            </div>
        </section>
    @endif

    {{-- Replay --}}
    <h2 class="m-0 font-display text-xl font-bold">{{ __('Replay') }}</h2>
    <div x-on:keydown.window="hotkey($event)" x-data="chessReplay(@js([...$replay, 'flipped' => $color === 'b', 'pgn' => $pgn, 'filename' => 'twentyone-game-'.$game->id.'.pgn', 'labels' => ['start' => __('Start position'), 'after' => __('Position after :move')]]))"
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

        <div class="flex min-w-0 flex-col gap-4">
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

            {{-- Proof: the NIP-64 record --}}
            <details class="group rounded-md bg-proof-fill shadow-[inset_0_0_0_1px_var(--color-proof-ring)]" data-test="record-proof"
                     @if ($color !== null && ! $aborted && $record === null) x-data x-init="window.chessPublishRecord && window.nostr && window.chessPublishRecord($wire, @js(auth()->user()?->pubkey)).then(() => $wire.$refresh())" @endif>
                <summary class="flex min-h-11 cursor-pointer items-center gap-2.5 px-4 text-[13px] text-ink-2">
                    <x-icon name="shield-check" :size="16" class="text-proof" /><b class="text-ink">{{ __('Proof') }}</b><span>{{ __('Verifiable record') }}</span><span class="grow"></span>
                    <span class="text-xs text-proof group-open:hidden">{{ __('show') }}</span><span class="hidden text-xs text-proof group-open:inline">{{ __('hide') }}</span>
                </summary>
                <div class="flex flex-col px-4 pb-3">
                    <span class="pb-1.5 text-xs text-ink-2">{{ __('PGN as NIP-64 event, kind 64, relay :relay', ['relay' => config('esports.relays')[0] ?? __('none configured')]) }}</span>
                    @if ($record)
                        <div class="grid min-h-12 grid-cols-[24px_minmax(0,1fr)_auto] items-center gap-2.5 border-t border-[#2A2440] text-[13px]" data-test="record-row">
                            <x-icon name="shield-check" :size="18" class="text-proof" />
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span><span class="whitespace-nowrap">{{ $players[$recorder]['name'] }}</span> <span class="text-ink-2">· {{ __('published the PGN') }}</span></span>
                                <span class="truncate text-xs text-proof" title="{{ NostrKeys::nevent($record->event_id, $record->pubkey, 64) }}">{{ NostrKeys::shortNevent($record->event_id, $record->pubkey, 64) }}</span>
                            </span>
                            <span class="flex items-center gap-1 text-xs {{ $publicState === 'published' ? 'text-win' : 'text-ink-3' }}"><x-icon name="check" :size="14" />{{ $record->created_at?->timezone($viewerZone)->format('H:i') }}</span>
                        </div>
                        <div class="grid min-h-9 grid-cols-[24px_minmax(0,1fr)] items-center gap-2.5 border-t border-[#2A2440] text-xs text-ink-2">
                            <span></span>
                            <span>
                                @switch ($publicState)
                                    @case('published') {{ __('On :count of :total relays', ['count' => $accepted, 'total' => $configured]) }} @break
                                    @case('no-relay') {{ __('No relay configured here, so it is kept by the league only') }} @break
                                    @default {{ __('Waiting for the relays, we retry on our own') }}
                                @endswitch
                            </span>
                        </div>
                    @elseif (! $aborted)
                        <div class="flex flex-col gap-2 border-t border-[#2A2440] py-3 text-[13px] text-ink-2" data-test="record-missing">
                            <span>{{ __('Not signed yet. Either player can sign the game record; the first one counts.') }}</span>
                            @if ($color !== null)
                                <x-button variant="quiet" icon="shield-check" class="self-start" x-data
                                          x-on:click="window.chessPublishRecord?.($wire, {{ \Illuminate\Support\Js::from(auth()->user()?->pubkey) }}, { connect: true }).then(() => $wire.$refresh())">{{ __('Sign the game record') }}</x-button>
                            @endif
                        </div>
                    @else
                        <span class="border-t border-[#2A2440] py-3 text-[13px] text-ink-2">{{ __('An aborted game gets no record.') }}</span>
                    @endif
                    <pre tabindex="0" aria-label="{{ __('PGN of the game') }}" class="mt-2 mb-0 h-[196px] overflow-auto rounded-md bg-ground px-3.5 py-3 font-mono text-xs leading-[1.6] whitespace-pre-wrap text-ink-2 shadow-[inset_0_0_0_1px_#2A2440]">{{ $pgn }}</pre>
                </div>
            </details>
        </div>
    </div>
</div>
