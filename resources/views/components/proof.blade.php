@props(['rows' => [], 'label' => null, 'toggle' => 'chevron'])

{{--
    Collapsible "Proof" block from the clan screens: Nostr ids only live in
    here, in violet (screens-v1.md: Nostr IDs only inside "Proof" details).

    rows:   list of [key, value] or [key, value, href]: with an href the value
            links out (njump.me, P40) in a new tab, underlined so the link
            does not rest on the colour alone
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
        @foreach ($rows as $proofRow)
            @php [$key, $value] = $proofRow; $href = $proofRow[2] ?? null; @endphp
            <div class="grid min-h-[30px] grid-cols-[110px_minmax(0,1fr)] items-center gap-2.5 border-t border-hairline lg:grid-cols-[140px_minmax(0,1fr)]">
                <span class="text-ink-3">{{ $key }}</span>
                @if ($href)
                    <a href="{{ $href }}" rel="noopener noreferrer" target="_blank" title="{{ $href }}" data-test="proof-link"
                       class="flex min-h-6 min-w-0 items-center text-proof underline decoration-proof/50 underline-offset-2 hover:text-ink hover:decoration-ink"><span class="truncate">{{ $value }}</span><span class="sr-only"> {{ __('(opens njump.me in a new tab)') }}</span></a>
                @else
                    <span class="truncate text-proof" title="{{ $value }}">{{ $value }}</span>
                @endif
            </div>
        @endforeach
        @if ($slot->isNotEmpty())
            <p class="mt-2 mb-0 text-xs leading-[1.6] text-ink-3">{{ $slot }}</p>
        @endif
    </div>
</details>
