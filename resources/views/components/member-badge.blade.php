@props(['long' => false, 'solid' => false])

{{--
    EINUNDZWANZIG member badge: orange outline tag, text carries the meaning.
    `solid`: the filled chip of the player card and player page header.
--}}
@if ($solid)
    <span title="{{ __('EINUNDZWANZIG Member') }}"
          {{ $attributes->class('inline-flex h-5 shrink-0 items-center whitespace-nowrap rounded-sm bg-btc px-1.5 text-[10px] font-bold text-on-btc') }}>{{ __('EINUNDZWANZIG Member') }}</span>
@else
    <span title="{{ __('EINUNDZWANZIG Member') }}"
          {{ $attributes->class([
              'inline-flex shrink-0 items-center rounded-sm border border-btc-deep font-bold text-btc',
              'h-[18px] px-1.5 text-[10px]' => ! $long,
              'h-6 px-2 text-xs' => $long,
          ]) }}>{{ $long ? __('EINUNDZWANZIG Member') : __('Member') }}</span>
@endif
