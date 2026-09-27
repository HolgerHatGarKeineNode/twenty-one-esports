{{--
    The blitz ladder's top five in the chess lobby, from the view the ladder
    page opens on (rated once it has a result, casual before), and the way
    to the whole ladder.
--}}
@php
    $top = $this->ladderTop;
    $rated = $top['pool'] === 'rated';
@endphp

<section aria-labelledby="ladder-card-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:col-span-3 lg:px-5" data-test="lobby-ladder" data-pool="{{ $top['pool'] }}">
    <span class="flex items-baseline justify-between gap-3">
        <h2 id="ladder-card-h" class="m-0 text-[15px] font-bold">{{ __('Blitz ladder') }}</h2>
        <span class="rounded-xs px-1.5 py-0.5 text-[11px] leading-4 text-ink-2 shadow-ring">{{ $rated ? __('Rated') : __('Casual') }}</span>
    </span>

    @if ($top['rows']->isEmpty())
        <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="lobby-ladder-empty">{{ __('No results yet. Every finished blitz game counts.') }}</p>
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

    <x-button variant="quiet" :href="route('ladder.show', ['chess', 'blitz'])" class="self-start" data-test="lobby-ladder-link">{{ __('Open the blitz ladder') }}</x-button>
</section>
