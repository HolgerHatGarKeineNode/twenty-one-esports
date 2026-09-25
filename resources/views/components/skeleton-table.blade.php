@props(['columns' => [], 'widths' => ['70%', '55%', '82%', '48%', '64%'], 'label' => null])

{{--
    Table loading skeleton from States.dc.html: real column heads, shimmering
    bars in the cells. `columns` = list of [heading, grid track, bar width];
    the widest column uses `widths` row by row so the rows do not look cloned.
--}}
@php
    $columns = $columns ?: [
        [__('Match'), '80px', '48px'],
        [__('Winner'), 'minmax(0,1fr)', null],
        [__('Time'), '90px', '56px'],
        [__('Series'), '70px', '32px'],
        [__('Mode'), '70px', '32px'],
    ];
    $tracks = implode(' ', array_column($columns, 1));
@endphp

<div role="status" aria-label="{{ $label ?? __('Table loading') }}" {{ $attributes->class('overflow-x-auto px-5 py-4') }}>
    {{-- Narrow screens scroll the table sideways instead of clipping its last columns. --}}
    <div class="flex min-w-[420px] flex-col">
    <div class="grid h-[34px] items-center gap-4 border-b border-hairline text-xs text-ink-3" style="grid-template-columns: {{ $tracks }}">
        @foreach ($columns as [$heading])
            <span>{{ $heading }}</span>
        @endforeach
    </div>
    @foreach ($widths as $width)
        <div class="grid h-10 items-center gap-4 border-b border-[#16161A]" style="grid-template-columns: {{ $tracks }}" aria-hidden="true">
            @foreach ($columns as [$heading, $track, $bar])
                <span class="sk h-3" style="width: {{ $bar ?? $width }}"></span>
            @endforeach
        </div>
    @endforeach
    </div>
</div>
