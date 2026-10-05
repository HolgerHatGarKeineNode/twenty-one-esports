{{--
    Search results (P16, SearchController): players, clans and a match number.
    The field is repeated here, so a phone can refine without the header's search
    toggle. Groups without a hit are left out; no hit at all is an empty state
    that says what can be searched. noindex (PageMeta default).
--}}
@php
    $hint = __('Search for a player by name, NIP-05 or npub, a clan by name or tag, or a match by its number, like #123.');
    $missedNumber = $number !== null;
    $found = $players->isNotEmpty() || $clans->isNotEmpty();
@endphp
<x-layouts::app :title="$term === '' ? __('Search') : __('Search: :term', ['term' => $term])">
    <div class="flex flex-col gap-5 px-4 pb-8 lg:gap-6 lg:px-12 lg:pb-12" data-test="search-page">
        <h1 class="m-0 font-display text-[28px] leading-[1.1] font-bold lg:text-4xl">{{ __('Search') }}</h1>

        <form method="GET" action="{{ route('search') }}" role="search" class="flex max-w-[640px] gap-2">
            <label for="search-page-q" class="sr-only">{{ __('Search players, clans or match #') }}</label>
            <input id="search-page-q" name="q" type="search" value="{{ $term }}" maxlength="200" placeholder="{{ __('Search players, clans or match #') }}"
                   class="h-11 min-w-0 grow rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3" data-test="search-page-input">
            <button type="submit" class="btn-p h-11 shrink-0 cursor-pointer rounded-lg border-0 bg-btc px-5 text-sm font-bold text-on-btc">{{ __('Search') }}</button>
        </form>

        @if ($term === '')
            <x-empty-state class="rounded-lg bg-card px-5 py-8 lg:px-10" :heading="__('Find a player, a clan or a match')" :text="$hint" data-test="search-start" />
        @elseif (! $found && ! $missedNumber)
            <x-empty-state class="rounded-lg bg-card px-5 py-8 lg:px-10" :heading="__('Nothing found for “:term”', ['term' => $term])" :text="$hint" data-test="search-empty">
                <x-button :href="route('clans.index')" variant="secondary">{{ __('Browse the clans') }}</x-button>
                <x-button :href="route('ladder.show', ['chess', \App\Support\Chess\ChessModes::DEFAULT])" variant="secondary">{{ __('See the ladder') }}</x-button>
            </x-empty-state>
        @else
            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2 lg:gap-6">
                @if ($missedNumber)
                    <section aria-labelledby="search-matches-h" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-4 lg:col-span-2 lg:px-6" data-test="search-matches">
                        <h2 id="search-matches-h" class="m-0 text-[15px] font-bold">{{ __('Matches') }}</h2>
                        <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('There is no match #:number yet.', ['number' => $number]) }}
                            <a href="{{ route('matches.index') }}" class="inline-flex min-h-11 items-center">{{ __('See all matches') }}</a></p>
                    </section>
                @endif

                @if ($players->isNotEmpty())
                    <section aria-labelledby="search-players-h" class="flex flex-col rounded-lg bg-card px-2 py-3 lg:px-4" data-test="search-players">
                        <h2 id="search-players-h" class="m-0 flex items-baseline gap-2 px-2 pb-2 text-[15px] font-bold">{{ __('Players') }} <span class="text-xs font-normal text-ink-3">{{ $players->count() }}</span></h2>
                        @foreach ($players as $player)
                            <a href="{{ route('players.show', $player->npub) }}" class="tr grid min-h-14 grid-cols-[32px_minmax(0,1fr)] items-center gap-3 rounded-sm px-2 py-1.5 text-[13px] text-ink hover:text-ink" data-test="search-player">
                                <x-avatar :user="$player" :size="32" />
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <b class="truncate">{{ $player->displayName() }}</b>
                                    <span class="truncate text-[11px] text-ink-3">{{ $player->shortNpub() }}</span>
                                </span>
                            </a>
                        @endforeach
                    </section>
                @endif

                @if ($clans->isNotEmpty())
                    <section aria-labelledby="search-clans-h" class="flex flex-col rounded-lg bg-card px-2 py-3 lg:px-4" data-test="search-clans">
                        <h2 id="search-clans-h" class="m-0 flex items-baseline gap-2 px-2 pb-2 text-[15px] font-bold">{{ __('Clans') }} <span class="text-xs font-normal text-ink-3">{{ $clans->count() }}</span></h2>
                        @foreach ($clans as $clan)
                            <a href="{{ route('clans.show', $clan) }}" class="tr grid min-h-14 grid-cols-[auto_minmax(0,1fr)] items-center gap-3 rounded-sm px-2 py-1.5 text-[13px] text-ink hover:text-ink" data-test="search-clan">
                                <x-clan-tag :clan="$clan" />
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <b class="truncate">{{ $clan->name }}</b>
                                    <span class="text-[11px] text-ink-3">{{ trans_choice(':count player|:count players', $clan->members_count) }}</span>
                                </span>
                            </a>
                        @endforeach
                    </section>
                @endif
            </div>
        @endif
    </div>
</x-layouts::app>
