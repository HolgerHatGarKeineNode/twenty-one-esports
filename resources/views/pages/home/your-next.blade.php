{{--
    A logged-in player's open matches, first on home: live ones, then those
    on them, then those waiting for the other side (HomeHub::yourNext(), the
    match dock's own list). Nothing for a guest or a player with none.
    From lg only: below it the match dock's bar already shows the first of
    them in the first viewport, fixed above the tab bar, and a second copy
    would push the tournament's call to action under that bar.

    $items: Collection of App\Support\Dock\DockItem.
--}}
@if ($items->isNotEmpty())
    <section aria-labelledby="your-next-h" class="flex flex-col gap-2 px-12 pt-6 max-lg:hidden" data-test="your-next">
        <h2 id="your-next-h" class="sr-only">{{ __('Your matches') }}</h2>
        <ul class="m-0 grid list-none grid-cols-1 gap-2 p-0 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($items as $item)
                <li>
                    <a href="{{ $item->href }}" aria-label="{{ $item->sentence }}"
                       @class(['flex min-h-14 items-center gap-3 rounded-card px-3 py-2 text-ink hover:text-ink', 'bg-btc-chip shadow-ring-btc' => $item->needsYou || $item->isLive(), 'bg-card shadow-ring' => ! $item->needsYou && ! $item->isLive()])
                       data-test="your-next-item" data-kind="{{ $item->kind }}">
                        @if ($item->face)
                            <x-avatar :user="$item->face" :size="36" class="shrink-0 rounded-tag" />
                        @elseif ($item->clan)
                            <x-clan-tag :clan="$item->clan" :tile="36" class="size-9 shrink-0 rounded-tag" />
                        @endif
                        <span class="flex min-w-0 grow flex-col">
                            <b class="truncate text-[13px]">{{ $item->name }}</b>
                            <span @class(['truncate text-xs', 'text-btc-hi' => $item->needsYou, 'text-ink-2' => ! $item->needsYou])>{{ $item->title }}, {{ mb_strtolower($item->state) }}</span>
                        </span>
                        @if ($item->action)
                            <span class="inline-flex h-8 shrink-0 items-center rounded-control bg-btc px-3 text-xs font-bold text-on-btc">{{ $item->action }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
