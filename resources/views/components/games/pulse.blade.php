@props(['items', 'label'])

{{--
    What is going on in a game right now (P56), as one row of counts under a
    landing's call to action: each count is a link to the part of the page
    (or the page) that shows it, so the numbers are a way in, not a boast.
    Counts come from App\Support\Games\GameLanding; a zero stays visible and
    quiet, never hidden, so the row does not change shape between visits.

    $items: list of [key, count, label, href, live]. `live` gives a count
    above zero the pulsing dot of a thing happening now.
--}}
<ul role="list" aria-label="{{ $label }}" {{ $attributes->class('m-0 grid list-none gap-1 p-0') }} style="grid-template-columns: repeat({{ count($items) }}, minmax(0, 1fr))" data-test="game-pulse">
    @foreach ($items as [$key, $count, $text, $href, $live])
        <li class="min-w-0">
            <a href="{{ $href }}" data-test="game-pulse-{{ $key }}" data-count="{{ $count }}"
               class="flex h-full min-h-14 flex-col justify-start gap-0.5 rounded-md px-2 py-1.5 text-ink hover:bg-row-hover hover:text-ink lg:px-3">
                <span class="flex items-center gap-1.5">
                    @if ($live && $count > 0)<span class="size-2 shrink-0 animate-live rounded-full bg-btc" aria-hidden="true"></span>@endif
                    <b @class(['font-display text-lg leading-none tabular-nums lg:text-2xl', 'text-ink-3' => $count === 0])>{{ $count }}</b>
                </span>
                <span class="text-[11px] leading-tight text-ink-2 lg:text-xs">{{ $text }}</span>
            </a>
        </li>
    @endforeach
</ul>
