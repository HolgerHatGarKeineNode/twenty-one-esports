{{--
    One thing that needs the player now (/me, "Needs you now"): a match dock
    tab (App\Support\Dock\DockItem) with its one number and its action, and,
    on a tournament match, P18's countdown to the league's own decision
    (App\Support\Tournaments\MatchWait). A tournament wait without a dock tab
    stands alone. The whole card is one link.

    $item: DockItem|null, $wait: MatchWait|null (one of them is set).
--}}
@php
    /** @var \App\Support\Dock\DockItem|null $item */
    /** @var \App\Support\Tournaments\MatchWait|null $wait */
    $href = $item?->href ?? $wait->url;
    $name = $item?->name ?? $wait->sides[0].' '.__('vs').' '.$wait->sides[1];
    $title = $item !== null ? $item->title : $wait->label;
    $state = $item !== null ? $item->state : $wait->stateLabel();
    $action = $item?->action ?? ($wait !== null ? $wait->actionText() : null);
    $label = $item?->sentence ?? __('Your match :label: :a vs :b', ['label' => $wait->label, 'a' => $wait->sides[0], 'b' => $wait->sides[1]]);
    $nowMs = (int) now()->getTimestampMs();
@endphp

<a href="{{ $href }}" aria-label="{{ $label }}" class="flex h-full min-w-0 flex-col gap-3 rounded-card bg-btc-chip p-3 text-ink shadow-ring-btc hover:bg-btc-press hover:text-ink lg:p-4"
   data-test="me-need" data-kind="{{ $item?->kind ?? 'tournament_wait' }}" data-phase="{{ $item?->phase ?? $wait->state }}">
    <span class="flex min-w-0 items-center gap-3">
        @if ($item)
            @include('pages.me.partials.face', ['face' => $item->face, 'clan' => $item->clan, 'tag' => $item->tag, 'size' => 44])
        @else
            <span class="flex size-11 shrink-0 items-center justify-center rounded-md bg-btc-tint text-btc"><x-icon name="trophy" :size="22" /></span>
        @endif
        <span class="flex min-w-0 grow flex-col gap-0.5">
            <b class="truncate text-[13px]">{{ $name }}</b>
            <span class="truncate text-xs text-ink-2">{{ $title }}</span>
        </span>
        @if ($item?->tick !== null)
            <b class="shrink-0 font-display text-lg leading-none font-bold text-btc-hi tabular-nums" role="timer" data-test="me-need-clock"
               x-data="autoDecision({ at: {{ $item->tick['endsAt'] }}, now: {{ $nowMs }}, soon: @js(__('any moment')) })" x-text="text">{{ \App\Support\Engagement\PlayerHub::clock($item->tick['endsAt'] - $nowMs) }}</b>
        @elseif ($item !== null && $item->trailing !== '')
            <span class="shrink-0 text-xs text-ink-2 tabular-nums">{{ $item->trailing }}</span>
        @endif
    </span>
    @if ($wait !== null)
        <x-tournaments.auto-decision :wait="$wait" class="text-xs text-ink" />
    @endif
    <span class="mt-auto flex items-center justify-between gap-3">
        <span class="min-w-0 text-xs font-bold text-btc-hi [overflow-wrap:anywhere]">{{ $state }}</span>
        @if ($action)
            <span class="inline-flex h-9 shrink-0 items-center rounded-control bg-btc px-3 text-xs font-bold text-on-btc" aria-hidden="true">{{ $action }}</span>
        @endif
    </span>
</a>
