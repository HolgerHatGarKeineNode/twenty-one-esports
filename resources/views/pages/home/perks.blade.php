@php
    $perks = [
        ['award', __('Member badge on your profile, in rankings and on your clan')],
        ['brush', __('Board themes, piece sets, profile frames, animated clan logos')],
        ['bolt', __("Members' prize pools: everyone plays, sats go to members")],
        ['vote', __('Vote on the next game and try new features first')],
    ];
@endphp

<section aria-labelledby="perk-h" class="col-span-full flex flex-col gap-3 rounded-lg border border-btc-tint p-4 lg:grid lg:grid-cols-[260px_minmax(0,1fr)_auto] lg:items-center lg:gap-7 lg:px-6 lg:py-[18px]">
    <h2 id="perk-h" class="m-0 text-sm leading-normal font-bold">{{ __('EINUNDZWANZIG members get a little extra') }}</h2>
    <ul class="m-0 flex list-none flex-col gap-2.5 p-0 text-xs leading-normal text-ink-2 lg:grid lg:grid-cols-4 lg:gap-5">
        @foreach ($perks as [$icon, $text])
            <li class="grid grid-cols-[16px_minmax(0,1fr)] items-start gap-2.5 lg:flex">
                <span aria-hidden="true" class="mt-[7px] ml-1 size-1.5 rounded-[1px] bg-btc lg:hidden"></span>
                <span class="hidden pt-px text-btc lg:inline-flex"><x-icon :name="$icon" :size="16" /></span>
                <span>{{ $text }}</span>
            </li>
        @endforeach
    </ul>
    <span class="flex items-center gap-2 text-xs text-ink-3 lg:hidden">
        <x-member-badge />
        <span>{{ __('adds perks, never rating or gameplay.') }}</span>
    </span>
    <a href="https://einundzwanzig.space" class="inline-flex min-h-11 items-center self-start whitespace-nowrap text-[13px] lg:self-auto lg:text-xs">{{ __('About membership') }}</a>
</section>
