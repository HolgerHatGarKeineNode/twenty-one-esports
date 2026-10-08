{{-- One seat of the viewer's lobby table (the hyper-lobby component): free, a bot, or a player with their faction. --}}
@php
    $labels = ['bitcoiner' => 'Bitcoiner', 'fed' => 'Fed', 'ezb' => __('ECB'), 'goldbug' => 'Goldbug', 'shitcoiner' => 'Shitcoiner', 'nocoiner' => 'Nocoiner'];
    $portrait = \App\Support\Hyper\HyperGame::FACTIONS;
@endphp
<li wire:key="mine-seat-{{ $index }}" @class(['flex min-h-14 min-w-0 items-center gap-2 rounded-md p-2', 'bg-well shadow-ring' => $seat !== null, 'border border-dashed border-line text-ink-3' => $seat === null]) data-test="hyper-lobby-seat" data-seat="{{ $index }}" data-faction="{{ $seat?->faction }}">
    @if ($seat === null)
        <span class="text-xs">{{ __('Free seat') }}</span>
    @else
        @if ($seat->faction)
            <img src="/hyper/art/por-{{ $portrait[$seat->faction] }}.jpg?v=1" alt="" width="32" height="32" class="size-8 shrink-0 rounded-full object-cover shadow-ring" loading="lazy">
        @elseif ($seat->user)
            <x-avatar :user="$seat->user" :size="32" class="shrink-0" />
        @else
            <span class="grid size-8 shrink-0 place-items-center rounded-full bg-ground text-base" aria-hidden="true">🤖</span>
        @endif
        <span class="flex min-w-0 flex-col">
            <b class="truncate text-xs">{{ $seat->user?->displayName() ?? __('Bot') }}</b>
            <span class="truncate text-[11px] text-ink-2">{{ $seat->faction ? $labels[$seat->faction] : __('Random') }}</span>
        </span>
    @endif
</li>
