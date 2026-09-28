{{--
    One running match that does not wait on the player (/me, "Running and
    upcoming"): live, or waiting for the other side or an admin, with P18's
    countdown when the league decides a tournament match on its own.

    $item: App\Support\Dock\DockItem|null, $wait: App\Support\Tournaments\MatchWait|null.
--}}
@php
    $href = $item?->href ?? $wait->url;
    $name = $item?->name ?? $wait->sides[0].' '.__('vs').' '.$wait->sides[1];
    $title = $item !== null ? $item->title : $wait->label;
    $state = $item !== null ? $item->state : $wait->stateLabel();
@endphp

<a href="{{ $href }}" class="flex min-h-16 flex-col justify-center gap-1 px-3 py-2.5 text-ink hover:bg-row-hover hover:text-ink" data-test="me-open" data-live="{{ $item?->isLive() ? 'true' : 'false' }}"
   @if ($item) aria-label="{{ $item->sentence }}" @endif>
    <span class="flex min-w-0 items-center gap-3">
        @if ($item)
            @include('pages.me.partials.face', ['face' => $item->face, 'clan' => $item->clan, 'tag' => $item->tag, 'size' => 40])
        @else
            <span class="flex size-10 shrink-0 items-center justify-center rounded-md bg-raised text-ink-2"><x-icon name="trophy" :size="20" /></span>
        @endif
        <span class="flex min-w-0 grow flex-col gap-0.5">
            <b class="truncate text-[13px]">{{ $name }}</b>
            <span class="truncate text-xs text-ink-2">{{ $title }}</span>
        </span>
        <span class="flex shrink-0 flex-col items-end gap-0.5 text-right text-xs">
            @if ($item?->isLive())
                <span class="inline-flex items-center gap-1.5 font-bold text-btc"><span class="size-1.5 animate-live rounded-full bg-btc" aria-hidden="true"></span>{{ $state }}</span>
            @else
                <span class="text-ink-2">{{ $state }}</span>
            @endif
            @if ($item !== null && $item->trailing !== '')
                <span class="text-ink-3 tabular-nums">{{ $item->trailing }}</span>
            @endif
        </span>
    </span>
    @if ($wait !== null)
        <x-tournaments.auto-decision :wait="$wait" class="pl-[52px] text-xs text-ink-2" />
    @endif
</a>
