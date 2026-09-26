@props(['active' => null])

{{--
    Admin sub-navigation from AdminDisputes.dc.html / AdminDispute.dc.html.
    Built pages link; the ones of later phases (tournaments P8, payouts P9,
    settings) are shown muted and not clickable yet.
--}}
@php
    $open = \App\Models\SeriesMatch::query()
        ->where(fn ($query) => $query->where('status', \App\Enums\SeriesStatus::Disputed)
            ->orWhere(fn ($query) => $query->where('status', \App\Enums\SeriesStatus::Accepted)->whereNotNull('noshow_reported_at')))
        ->count();
    $items = [
        ['status', __('Status'), route('admin.status'), null],
        ['disputes', __('Disputes'), route('admin.disputes'), $open],
        ['tournaments', __('Tournaments'), null, null],
        ['payouts', __('Payouts'), null, null],
        ['seasons', __('Seasons'), route('admin.season'), null],
        ['trust', __('Trust'), route('admin.trust'), null],
        ['settings', __('Settings'), null, null],
        ['admins', __('Admins'), route('admin.admins'), null],
    ];
@endphp

<nav aria-label="{{ __('Admin') }}" {{ $attributes->class('overflow-x-auto border-b border-hairline') }}>
    <ul class="m-0 flex list-none gap-1 px-4 lg:px-12">
        @foreach ($items as [$key, $label, $href, $count])
            <li>
                @if ($href)
                    <a href="{{ $href }}" @if ($active === $key) aria-current="page" @endif
                       @class(['inline-flex h-12 items-center gap-2 border-b-2 px-3 text-[13px] whitespace-nowrap', 'border-btc font-bold text-ink hover:text-ink' => $active === $key, 'border-transparent text-ink-2 hover:text-ink' => $active !== $key])>
                        {{ $label }}@if ($count)<span class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-xs bg-btc-tint px-1 text-[11px] font-bold text-btc">{{ $count }}</span>@endif
                    </a>
                @else
                    <span aria-disabled="true" class="inline-flex h-12 items-center border-b-2 border-transparent px-3 text-[13px] whitespace-nowrap text-ink-3">{{ $label }}</span>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
