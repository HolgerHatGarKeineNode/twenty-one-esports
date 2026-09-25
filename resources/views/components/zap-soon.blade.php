{{-- "Zap" next to a Lightning address: shown, not payable, in this phase. --}}
<span title="{{ __('Zapping from TWENTY ONE comes in a later phase') }}"
      {{ $attributes->class('inline-flex h-6 shrink-0 items-center gap-1 rounded-l-xl rounded-r-sm border border-dashed border-edge px-2 text-[11px] font-bold text-ink-2') }}><span class="flex text-bolt"><x-icon name="bolt-toast" :size="14" /></span>{{ __('Zap') }}</span>
