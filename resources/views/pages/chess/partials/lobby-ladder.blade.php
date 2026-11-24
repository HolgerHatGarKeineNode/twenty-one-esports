{{--
    A ladder's top five in a lobby (template T1 "Ladder", GameLobby.dc.html), from the view the ladder page opens
    on (rated once it has a result, casual before), with the way to the whole ladder in its head. A board game's
    lobby includes it with `ladderGame` (its slug) and its lobby mode as `ladderMode` (correspondence for the board
    games since 2026-10-07, named by the game's mode name); the chess lobby passes `ladderMode` and `ladderModes`
    (plan "Schach Rapid und Clan": rapid first, blitz and daily by the switch, Livewire showLadder()).
    The table is the ladder template's (T5): rank, player, Elo right-aligned, record (rule R2).
--}}
@php
    $ladderGame ??= 'chess';
    $ladderMode ??= 'blitz';
    $ladderModes ??= [];
    $top = $this->ladderTop;
    $rated = $top['pool'] === 'rated';
    // A board game's chips (Blockli, 2026-10-11): Blitz and Rapid short as chess's, correspondence by the game's own name.
    $chipName = fn (string $mode): string => $ladderGame === 'chess' || in_array($mode, ['blitz', 'rapid'], true) ? \App\Support\Chess\ChessModes::short($mode) : \App\Support\GameNames::mode($ladderGame, $mode);
    $ladderName = $ladderModes !== [] ? $chipName($ladderMode)
        : ($ladderMode === 'blitz' ? null : \App\Support\GameNames::mode($ladderGame, $ladderMode));
@endphp

<section aria-labelledby="ladder-card-h" class="flex flex-col gap-3" data-test="lobby-ladder" data-pool="{{ $top['pool'] }}" data-mode="{{ $ladderMode }}">
    <div class="gp-head">
        <h2 id="ladder-card-h" class="gp-h">{{ $ladderName === null ? __('Blitz ladder') : __(':mode ladder', ['mode' => $ladderName]) }}</h2>
        {{-- Which ladder this is (plan "Season vs. Casual", P1): the Season once it has a result, else casual. --}}
        <span @class(['rv-tag', 'is-btc' => $rated]) data-test="lobby-ladder-pool">{{ \App\Support\Rating\Ratings::label($top['pool']) }}</span>
        <a href="{{ route('ladder.show', [$ladderGame, $ladderMode]) }}" @navigate(route('ladder.show', [$ladderGame, $ladderMode])) class="rv-lk inline-flex min-h-11 items-center lg:min-h-6" data-test="lobby-ladder-link">{{ __('Full ladder') }}</a>
    </div>

    @include('partials.season-casual-legend')

    <div class="gp-panel flex flex-col gap-2 p-2">
        @if (count($ladderModes) > 1)
            <div role="group" aria-label="{{ __('Ladder') }}" class="flex flex-wrap gap-2 px-2 pt-2" data-test="lobby-ladder-modes">
                @foreach ($ladderModes as $mode)
                    <button type="button" wire:click="showLadder('{{ $mode }}')" aria-pressed="{{ $mode === $ladderMode ? 'true' : 'false' }}" data-test="lobby-ladder-mode-{{ $mode }}" @class(['gm-chip', 'h-11' => $ladderGame !== 'chess'])>{{ $chipName($mode) }}</button>
                @endforeach
            </div>
        @endif

        @if ($top['rows']->isEmpty())
            <p class="m-0 px-2 py-3 text-[13px] leading-normal text-ink-2" data-test="lobby-ladder-empty">{{ $ladderName === null ? __('No results yet. Every finished blitz game counts.') : __('No results yet. Every finished :mode game counts.', ['mode' => $ladderName]) }}</p>
        @else
            <x-ladder.table row-test="lobby-ladder-row" :value-label="$rated ? __('Rated') : __('Casual Elo')"
                            :rows="$top['rows']->values()->map(fn ($row, int $index): array => ['rank' => $index + 1, 'user' => $row->user, 'value' => (int) $row->rating, 'key' => $row->id,
                                'record' => __(':w W, :d D, :l L', ['w' => $row->wins, 'd' => $row->draws, 'l' => $row->losses])])->all()" />
        @endif
    </div>
</section>
