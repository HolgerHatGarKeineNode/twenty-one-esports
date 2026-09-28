@props(['match', 'state', 'players', 'viewer' => null])

{{--
    One series on a game page (P26): both sides with their face (the clan's
    logo or tag for a lineup, the player's picture for a roster side), the
    score in the middle, and whether it is live, next or final. The whole
    card is one link to the match page.

    `state`: live | next | final. `players` holds the users of the roster
    sides (keyed by id), loaded once for all cards.
--}}
@php
    use App\Enums\SeriesResolution;
    use App\Support\Series\SeriesPresenter;

    $wins = \App\Models\SeriesMatch::seriesScore($match->currentGames());
    $void = $match->resolution === SeriesResolution::Void;
    $forfeit = $match->resolution === SeriesResolution::Forfeit;
    $winner = in_array($match->winner, \App\Models\SeriesMatch::SIDES, true) ? $match->winner : null;
    $when = match ($state) {
        'live' => SeriesPresenter::time($match->start_at ?? now(), $viewer, 'H:i'),
        'next' => $match->start_at !== null ? SeriesPresenter::time($match->start_at, $viewer, 'D H:i') : __('open challenge'),
        default => $match->finished_at?->diffForHumans(['short' => true]) ?? '',
    };
@endphp

<a href="{{ route('matches.show', $match) }}" data-test="game-match" data-state="{{ $state }}"
   {{ $attributes->class(['flex min-w-0 flex-col gap-3 rounded-lg bg-card p-3 text-ink shadow-ring hover:bg-row-hover hover:text-ink lg:p-4', 'shadow-ring-btc' => $state === 'live']) }}>
    <span class="flex items-center justify-between gap-2 text-xs">
        @if ($state === 'live')
            <span class="inline-flex items-center gap-1.5 font-bold text-btc"><span class="size-2 shrink-0 animate-live rounded-full bg-btc" aria-hidden="true"></span>{{ __('Live since :time', ['time' => $when]) }}</span>
        @elseif ($state === 'next')
            <span class="truncate"><b>{{ __('Next') }}</b> <span class="text-ink-2">{{ $when }}</span></span>
        @else
            <span class="truncate text-ink-2">{{ __('Final, :when', ['when' => $when]) }}</span>
        @endif
        <span class="shrink-0 text-ink-3">{{ SeriesPresenter::format($match) }}</span>
    </span>

    <span class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2">
        @foreach (['challenger', 'score', 'challenged'] as $cell)
            @if ($cell === 'score')
                <span class="flex flex-col items-center px-1 text-center">
                    @if ($state === 'next' || $void || ($forfeit && $match->currentGames() === []))
                        <b class="font-display text-lg text-ink-3" data-test="game-match-score">{{ $void ? __('void') : ($forfeit ? __('forfeit') : 'vs') }}</b>
                    @else
                        <b class="font-display text-[26px] leading-none font-bold whitespace-nowrap tabular-nums lg:text-[30px]" data-test="game-match-score"><span @class(['text-ink-3' => $winner === 'challenged'])>{{ $wins['challenger'] }}</span><span class="text-ink-3"> : </span><span @class(['text-ink-3' => $winner === 'challenger'])>{{ $wins['challenged'] }}</span></b>
                    @endif
                </span>
            @else
                @php
                    $clan = $match->sideClan($cell);
                    $roster = collect($match->rosterSide($cell))->map(fn (int $id) => $players[$id] ?? null)->filter()->values();
                @endphp
                <span class="flex min-w-0 flex-col items-center gap-1.5 text-center">
                    <span class="relative flex size-11 shrink-0">
                        @if ($match->lineup($cell) !== null || $roster->isEmpty())
                            <x-clan-tag :clan="$clan" :tag="$match->sideTag($cell)" :tile="44" class="flex size-11 items-center justify-center rounded-md bg-btc-tint text-[11px] font-bold text-btc" />
                        @else
                            <x-avatar :user="$roster->first()" :size="44" class="rounded-md" />
                            @if ($roster->count() > 1)
                                <span class="absolute -right-1.5 -bottom-1 rounded-xs bg-raised px-1 text-[10px] leading-4 font-bold text-ink-2 shadow-ring">+{{ $roster->count() - 1 }}</span>
                            @endif
                        @endif
                    </span>
                    <b @class(['w-full truncate text-[13px] leading-5', 'text-ink-2 font-normal' => $winner !== null && $winner !== $cell])>{{ $match->sideName($cell) }}</b>
                </span>
            @endif
        @endforeach
    </span>
</a>
