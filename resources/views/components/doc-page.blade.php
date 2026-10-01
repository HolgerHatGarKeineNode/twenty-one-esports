@props(['sections', 'navLabel'])

{{--
    A long page of anchored sections (P28 rules, P29 open protocol): from lg
    a sticky list of the sections on the left that marks the one in view;
    below lg every section is an accordion under its heading, closed until
    tapped or reached through its anchor. Every section is in the markup at
    every width, so a link to #its-id always lands.

    `sections`: list of [id, title]. The slot holds one <x-doc-section> per
    entry, in the same order.
--}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-[224px_minmax(0,1fr)] lg:gap-10"
     x-data="{ current: @js($sections[0][0] ?? '') }"
     x-init="const seen = new IntersectionObserver((entries) => entries.filter((e) => e.isIntersecting).forEach((e) => current = e.target.id), { rootMargin: '-30% 0px -60% 0px' });
             $root.querySelectorAll('[data-doc-section]').forEach((el) => seen.observe(el))">
    <nav aria-label="{{ $navLabel }}" class="max-lg:hidden" data-test="doc-nav">
        <ol class="sticky top-below-shell m-0 flex list-none flex-col gap-0.5 p-0">
            @foreach ($sections as $index => [$id, $title])
                <li>
                    <a href="#{{ $id }}" x-bind:aria-current="current === @js($id) ? 'location' : null"
                       class="flex min-h-11 items-center rounded-md border-l-2 border-transparent px-3 text-[13px] text-ink-2 hover:bg-row-hover hover:text-ink aria-[current=location]:border-btc aria-[current=location]:bg-btc-chip aria-[current=location]:text-btc-hi">
                        {{ $title }}
                    </a>
                </li>
            @endforeach
        </ol>
    </nav>
    <div class="flex min-w-0 flex-col gap-3 lg:gap-5">
        {{ $slot }}
    </div>
</div>
