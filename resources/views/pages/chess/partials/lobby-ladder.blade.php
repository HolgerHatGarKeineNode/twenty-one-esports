{{--
    A ladder's top five in a lobby, from the view the ladder page opens on
    (rated once it has a result, casual before), and the way to the whole
    ladder. A board game's lobby includes it with `ladderGame` (its slug)
    and shows its blitz ladder; the chess lobby passes `ladderMode` and
    `ladderModes` (plan "Schach Rapid und Clan": rapid first, blitz and daily
    by the switch, Livewire showLadder()).
--}}
@php
    $ladderGame ??= 'chess';
    $ladderMode ??= 'blitz';
    $ladderModes ??= [];
    $top = $this->ladderTop;
    $rated = $top['pool'] === 'rated';
    $ladderName = $ladderModes === [] ? null : \App\Support\Chess\ChessModes::short($ladderMode);
@endphp

<section aria-labelledby="ladder-card-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:col-span-3 lg:px-5 xl:max-2xl:col-span-12" data-test="lobby-ladder" data-pool="{{ $top['pool'] }}" data-mode="{{ $ladderMode }}">
    <span class="flex items-baseline justify-between gap-3">
        <h2 id="ladder-card-h" class="m-0 text-[15px] font-bold">{{ $ladderName === null ? __('Blitz ladder') : __(':mode ladder', ['mode' => $ladderName]) }}</h2>
        <span class="rounded-xs px-1.5 py-0.5 text-[11px] leading-4 text-ink-2 shadow-ring">{{ $rated ? __('Rated') : __('Casual') }}</span>
    </span>

    @if (count($ladderModes) > 1)
        <div role="group" aria-label="{{ __('Ladder') }}" class="grid gap-1 rounded-md bg-ground p-1 shadow-ring" style="grid-template-columns: repeat({{ count($ladderModes) }}, minmax(0, 1fr))" data-test="lobby-ladder-modes">
            @foreach ($ladderModes as $mode)
                <button type="button" wire:click="showLadder('{{ $mode }}')" aria-pressed="{{ $mode === $ladderMode ? 'true' : 'false' }}" data-test="lobby-ladder-mode-{{ $mode }}"
                        @class(['min-h-11 cursor-pointer rounded-sm px-1 text-[13px] font-bold', 'bg-raised text-btc-hi shadow-[inset_0_-2px_0_var(--color-btc)]' => $mode === $ladderMode, 'text-ink' => $mode !== $ladderMode])>{{ \App\Support\Chess\ChessModes::short($mode) }}</button>
            @endforeach
        </div>
    @endif

    @if ($top['rows']->isEmpty())
        <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="lobby-ladder-empty">{{ $ladderName === null ? __('No results yet. Every finished blitz game counts.') : __('No results yet. Every finished :mode game counts.', ['mode' => $ladderName]) }}</p>
    @else
        <ol class="m-0 flex list-none flex-col p-0">
            @foreach ($top['rows'] as $row)
                <li wire:key="ladder-{{ $row->id }}" class="flex min-h-10 items-center gap-3 border-b border-hairline text-[13px] last:border-0" data-test="lobby-ladder-row">
                    <b @class(['w-5 shrink-0 text-right font-display text-sm', 'text-btc' => $loop->first, 'text-ink-2' => ! $loop->first])>{{ $loop->iteration }}</b>
                    <x-player-link :user="$row->user" class="flex min-w-0 grow items-center gap-2 text-ink hover:text-ink">
                        <x-avatar :user="$row->user" :size="20" class="rounded-sm" />
                        <span class="truncate">{{ $row->user?->displayName() }}</span>
                    </x-player-link>
                    <b class="shrink-0">{{ $row->rating }}</b>
                </li>
            @endforeach
        </ol>
    @endif

    <x-button variant="quiet" :href="route('ladder.show', [$ladderGame, $ladderMode])" class="self-start" data-test="lobby-ladder-link">{{ $ladderName === null ? __('Open the blitz ladder') : __('Open the :mode ladder', ['mode' => $ladderName]) }}</x-button>
</section>
