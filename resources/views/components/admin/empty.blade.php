@props(['text'])

{{--
    An empty list inside an admin panel: the dashed block slot of the site's
    empty states (<x-empty-state>), one line that says what fills it, and the
    action that does, when there is one (slot).
--}}
<div {{ $attributes->class('flex flex-wrap items-center gap-x-3 gap-y-3 py-2') }}>
    <span class="size-4 shrink-0 rounded-[3px] border-[1.5px] border-dashed border-dash" aria-hidden="true"></span>
    <p class="m-0 min-w-0 grow basis-[24ch] text-[13px] leading-normal text-ink-2">{{ $text }}</p>
    @if ($slot->isNotEmpty())
        <span class="flex flex-wrap gap-3">{{ $slot }}</span>
    @endif
</div>
