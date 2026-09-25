@props(['grey' => false, 'size' => 16])

{{-- The dock's mempool cube: orange while something needs the player, grey while everything waits. --}}
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 14 14" aria-hidden="true" focusable="false" {{ $attributes->class('block shrink-0') }}>
    <path d="M7 1.5 12.5 4.7v4.6L7 12.5 1.5 9.3V4.7z" fill="{{ $grey ? '#63636A' : '#F7931A' }}"></path>
    <path d="M7 1 13 4.5 7 8 1 4.5z" fill="#FFFFFF" fill-opacity=".35"></path>
</svg>
