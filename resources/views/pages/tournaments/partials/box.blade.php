{{--
    One tournament match as a box: both sides with their score, the result
    label, a link to the normal match, and the director marker.

    $box: TournamentView::box()
--}}
@php
    $done = $box['status'] === 'done';
    $skipped = $box['status'] === 'skipped';
    // Held (P18): played before a correction changed its sides; it waits for the organizer or an admin.
    $held = $box['held'] ?? false;
    // Up now: both sides are known and the match is waiting for its result.
    $live = ! $held && $box['status'] === 'ready' && $box['bracket'] !== 'bye' && collect($box['sides'])->every(fn (array $side): bool => $side['known']);
@endphp
{{-- The board's match (TournamentDetail.dc.html): one 40 px row per side, the winner raised and bold with its score in orange; the match's number, state and director marker in a quiet line above. --}}
<div @class([
        'relative flex min-w-0 flex-col rounded-[4px] border text-[13px]',
        'border-line bg-card' => ! $live,
        'tl-live border-btc bg-btc-chip' => $live,
        'opacity-50' => $skipped,
    ]) data-test="match-box" data-key="{{ $box['key'] }}" data-status="{{ $box['status'] }}" @if ($live) data-live @endif>
    <span class="flex items-center justify-between gap-2 px-3 pt-1.5 text-[11px] text-ink-3">
        <span>
            @if ($box['number'])
                <a href="{{ $box['href'] }}" class="font-bold text-ink-2 hover:text-ink">#{{ $box['number'] }}</a>
            @elseif ($box['href'])
                {{-- A board game's match has no number: its key opens the board, so anyone can watch it (DerCaddy 2026-10-15); the hit area reaches 44 px without moving the line. --}}
                <a href="{{ $box['href'] }}" class="relative inline-flex items-center gap-1 font-bold text-ink-2 after:absolute after:-inset-x-2 after:-inset-y-3.5 hover:text-ink" aria-label="{{ __('Watch :match', ['match' => strtoupper($box['key'])]) }}" data-test="match-box-watch"><x-icon name="eye" :size="12" />{{ strtoupper($box['key']) }}</a>
            @else
                {{ strtoupper($box['key']) }}
            @endif
        </span>
        <span>
            @if ($skipped)
                {{ __('not needed') }}
            @elseif ($done)
                {{ __('done') }}
            @elseif ($held)
                <span class="font-bold text-loss" data-test="held">{{ __('on hold') }}</span>
            @elseif ($live)
                <span class="inline-flex items-center gap-1.5 font-bold text-btc-hi"><span class="size-1.5 animate-live rounded-full bg-btc"></span>{{ __('Up now') }}</span>
            @elseif ($box['status'] === 'ready')
                {{ __('ready') }}
            @else
                {{ __('waiting') }}
            @endif
        </span>
    </span>
    @foreach ($box['sides'] as $side)
        <span @class(['flex min-h-10 min-w-0 items-center gap-2 px-3 py-1', 'bg-raised' => $side['won'], 'rounded-b-[3px]' => $loop->last])>
            @if (($side['seed'] ?? null) !== null)
                <span class="w-4 flex-none text-[11px] text-ink-3 tabular-nums">{{ $side['seed'] }}</span>
            @endif
            @if ($side['tag'])
                <x-clan-tag :clan="$side['clan']" :tag="$side['tag']" />
            @elseif ($side['mix'])
                <span class="inline-flex h-5 items-center rounded-xs bg-raised px-1.5 text-xs text-ink-2">{{ __('mix') }}</span>
            @endif
            {{-- Wraps instead of truncating: two long names that differ only at the end stay apart (2026-10-04, live page). --}}
            <span @class(['min-w-0 grow leading-snug [overflow-wrap:anywhere]', 'font-bold text-ink' => $side['won'], 'text-ink-2' => $side['known'] && ! $side['won'] && $done, 'text-ink-3' => ! $side['known']])>{{ $side['name'] }}</span>
            @if ($side['score'] !== null)
                <span @class(['font-display tabular-nums', 'font-bold text-btc' => $side['won'], 'text-ink-3' => ! $side['won']])>{{ $side['score'] }}</span>
            @endif
        </span>
    @endforeach
    @if ($box['legs'] ?? null)
        {{-- A two-leg pairing under way: the standing after the finished legs and the game on now (DerCaddy 2026-10-15: a won first leg showed nowhere). --}}
        <span class="border-t border-hairline px-3 py-1.5 text-[11px] leading-snug text-ink-2" data-test="match-legs">{{ __('Standing :score · game :n under way', ['score' => $box['legs']['label'], 'n' => $box['legs']['game']]) }}</span>
    @endif
    @if ($box['decision'] ?? null)
        {{-- A drawn decider settled by the race standing (Blockli), with the margin: the score alone would read ½–½. --}}
        <span class="border-t border-hairline px-3 py-1.5 text-[11px] leading-snug text-ink-2" data-test="match-decision">{{ $box['decision'] }}</span>
    @endif
    @if (($box['games'] ?? []) !== [])
        {{-- Every game of the pairing, the earlier legs too (DerCaddy 2026-10-30: only the latest could be opened from the bracket); the score from the first side's view, as above. --}}
        <span class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-hairline px-3 py-1.5 text-[11px] leading-snug text-ink-3" data-test="match-games">
            <span>{{ __('Games') }}</span>
            @foreach ($box['games'] as $game)
                <a href="{{ $game['href'] }}" class="relative inline-flex items-center gap-1 font-bold text-ink-2 tabular-nums after:absolute after:-inset-x-1.5 after:-inset-y-4 hover:text-ink" aria-label="{{ __('Watch game :n of :match', ['n' => $game['n'], 'match' => strtoupper($box['key'])]) }}" data-test="match-game">
                    <x-icon name="eye" :size="12" />
                    @if ($game['score'] !== null)
                        <span>{{ $game['n'] }}<span class="font-normal text-ink-3">: {{ $game['score'] }}</span></span>
                    @elseif ($game['live'])
                        <span>{{ $game['n'] }}</span><span class="inline-flex items-center gap-1 font-normal text-btc-hi"><span class="size-1.5 rounded-full bg-btc"></span>{{ __('live') }}</span>
                    @else
                        <span>{{ $game['n'] }}</span>
                    @endif
                </a>
            @endforeach
        </span>
    @endif
    @if ($box['director'])
        <div class="px-3 pb-2">@include('pages.tournaments.partials.marker', ['marker' => $box['director']])</div>
    @endif
</div>
