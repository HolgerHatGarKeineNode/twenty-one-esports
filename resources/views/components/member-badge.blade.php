@props(['long' => false])

{{-- EINUNDZWANZIG member badge: orange outline tag, text carries the meaning. --}}
<span title="{{ __('EINUNDZWANZIG Member') }}"
      {{ $attributes->class([
          'inline-flex shrink-0 items-center rounded-sm border border-btc-deep font-bold text-btc',
          'h-[18px] px-1.5 text-[10px]' => ! $long,
          'h-6 px-2 text-xs' => $long,
      ]) }}>{{ $long ? __('EINUNDZWANZIG Member') : __('Member') }}</span>
