@props(['count'])

{{-- The number of things waiting behind an admin tab (open cases, payouts to send). --}}
<span class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-xs bg-btc-tint px-1 text-[11px] font-bold text-btc">{{ $count }}<span class="sr-only"> {{ __('waiting') }}</span></span>
