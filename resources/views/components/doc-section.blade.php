@props(['id', 'title'])

{{--
    One section of <x-doc-page>: its heading, and below lg an accordion
    (the heading is the button). From lg the body always shows and the
    heading is plain. A link to #id opens it on a phone.
--}}
<section id="{{ $id }}" aria-labelledby="{{ $id }}-h" data-doc-section data-test="doc-section-{{ $id }}"
         x-data="{ open: location.hash === @js('#'.$id) }"
         x-on:hashchange.window="if (location.hash === @js('#'.$id)) open = true"
         class="rounded-lg bg-card">
    <h2 id="{{ $id }}-h" class="m-0 font-display text-lg leading-tight font-bold lg:px-6 lg:pt-6 lg:text-xl">
        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-expanded="false" aria-controls="{{ $id }}-body"
                class="flex min-h-14 w-full cursor-pointer items-center justify-between gap-3 px-4 text-left lg:hidden" data-test="doc-toggle">
            <span>{{ $title }}</span>
            <x-icon name="chevron-down" :size="18" class="shrink-0 text-ink-2 transition-transform duration-150 motion-reduce:transition-none" ::class="open ? 'rotate-180' : ''" />
        </button>
        <span class="max-lg:hidden">{{ $title }}</span>
    </h2>
    <div id="{{ $id }}-body" x-cloak x-bind:class="open ? '' : 'max-lg:hidden'" class="flex flex-col gap-4 px-4 pt-1 pb-5 lg:px-6 lg:pt-3 lg:pb-6" data-test="doc-body">
        {{ $slot }}
    </div>
</section>
