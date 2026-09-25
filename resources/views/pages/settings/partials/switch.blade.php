{{--
    One settings row with a switch (ChessSettings.dc.html): label, hint, the
    "on"/"off" word and the pill switch. `action` is the Livewire call; null
    renders a switch that cannot be changed yet.
--}}
<div class="flex min-h-[61px] items-center gap-4 border-b border-hairline py-2 last:border-0">
    <span class="flex min-w-0 grow flex-col gap-0.5"><span class="text-sm">{{ $label }}</span><span class="text-xs text-ink-2">{{ $hint }}</span></span>
    <span class="text-xs {{ $on ? 'text-ink' : 'text-ink-3' }}">{{ $on ? __('on') : __('off') }}</span>
    <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ $label }}" data-test="switch-{{ $test }}"
            @if ($action) wire:click="{{ $action }}" @else disabled @endif
            @class(['relative h-8 w-[50px] shrink-0 cursor-pointer rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50', 'bg-btc' => $on, 'bg-ground shadow-[inset_0_0_0_1px_#63636A]' => ! $on])>
        <span @class(['absolute top-1 size-6 rounded-full transition-all', 'left-[22px] bg-ground' => $on, 'left-1 bg-edge' => ! $on])></span>
    </button>
</div>
