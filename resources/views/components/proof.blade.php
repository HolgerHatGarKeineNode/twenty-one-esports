@props(['rows' => [], 'label' => null, 'toggle' => 'chevron'])

{{--
    Collapsible "Proof" block from the clan screens: Nostr ids only live in
    here, in violet (screens-v1.md: Nostr IDs only inside "Proof" details).

    rows:   list of [key, value]
    toggle: `show` = the violet "show" text of ClanShow.dc.html ("hide" while
            open); `chevron` = the chevron of ClanCreate, ClanManage and
            InviteAccept. The designs differ here, each page follows its own.
    slot:   optional closing note under the rows (ClanShow).
--}}
<details {{ $attributes->class('group rounded-md bg-ground shadow-ring') }}>
    <summary class="flex min-h-11 cursor-pointer items-center gap-2.5 px-3.5 text-[13px] text-ink-2">
        <x-icon name="shield-check" :size="16" class="text-proof" />
        <b class="text-ink">{{ __('Proof') }}</b>
        <span>{{ $label ?? __('Verifiable record') }}</span>
        <span class="grow"></span>
        @if ($toggle === 'show')
            <span class="text-xs text-proof group-open:hidden">{{ __('show') }}</span>
            <span class="hidden text-xs text-proof group-open:inline">{{ __('hide') }}</span>
        @else
            <x-icon name="chevron-down" :size="16" class="transition-transform duration-200 group-open:rotate-180" />
        @endif
    </summary>
    <div class="flex flex-col px-3.5 pb-3 text-xs">
        @foreach ($rows as [$key, $value])
            <div class="grid min-h-[30px] grid-cols-[110px_minmax(0,1fr)] items-center gap-2.5 border-t border-hairline lg:grid-cols-[140px_minmax(0,1fr)]">
                <span class="text-ink-3">{{ $key }}</span>
                <span class="truncate text-proof" title="{{ $value }}">{{ $value }}</span>
            </div>
        @endforeach
        @if ($slot->isNotEmpty())
            <p class="mt-2 mb-0 text-xs leading-[1.6] text-ink-3">{{ $slot }}</p>
        @endif
    </div>
</details>
