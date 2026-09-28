@props(['title' => null, 'meta' => null, 'id' => null, 'flush' => false])

{{--
    One section of an admin page: a card with its h2, an optional meta line
    beside it and actions on the right (slot `actions`), then the content.
    `flush` drops the inner gap for row lists whose rows carry their own
    hairlines (tables, <x-admin.row>).
--}}
@php($headingId = $id ?? ($title ? 'panel-'.\Illuminate\Support\Str::slug($title) : null))

<section @if ($headingId) aria-labelledby="{{ $headingId }}" @endif {{ $attributes->class(['flex flex-col rounded-lg bg-card px-4 py-5 lg:px-6', 'gap-4' => ! $flush, 'gap-2' => $flush]) }}>
    @if ($title || isset($actions))
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <span class="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1">
                @if ($title)<h2 id="{{ $headingId }}" class="m-0 text-[15px] font-bold">{{ $title }}</h2>@endif
                @if ($meta)<span class="text-xs text-ink-3">{{ $meta }}</span>@endif
            </span>
            @isset($actions)<span class="flex flex-wrap items-center gap-3">{{ $actions }}</span>@endisset
        </div>
    @endif
    {{ $slot }}
</section>
