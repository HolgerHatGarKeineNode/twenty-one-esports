@props(['tier' => 'provisional', 'level' => 1, 'size' => 'md'])

{{--
    Rank badge per FIXPASS.md section 5: the cube glyph (verbatim) in the tier
    colour plus the name with a roman numeral, e.g. "Diamond I". No pips. The
    name is always shown, colour never carries the rank alone. Provisional uses
    the same cube as a dashed outline; Grand Champion adds its glow.

    tier:  bronze|silver|gold|platinum|diamond|champion|grand-champion|provisional
    level: 1..3 (ignored for provisional)
    size:  sm (11 px label) | md (12 px label)
--}}
@php
    $tiers = [
        'bronze' => __('Bronze'),
        'silver' => __('Silver'),
        'gold' => __('Gold'),
        'platinum' => __('Platinum'),
        'diamond' => __('Diamond'),
        'champion' => __('Champion'),
        'grand-champion' => __('Grand Champion'),
    ];
    $provisional = ! array_key_exists($tier, $tiers);
    $colour = 'var(--color-rank-'.($provisional ? 'provisional' : $tier).')';
    $numeral = ['I', 'II', 'III'][max(1, min(3, (int) $level)) - 1];
    $label = $provisional ? __('Provisional') : $tiers[$tier].' '.$numeral;
    $fill = $provisional ? 'transparent' : $colour;
    $stroke = $provisional ? '#ADADB0' : 'none';
    $glow = $tier === 'grand-champion' ? 'drop-shadow(0 0 4px #F43F5E)' : 'none';
@endphp

<span {{ $attributes->class([
    'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap font-bold',
    'text-[11px]' => $size === 'sm',
    'text-xs' => $size !== 'sm',
]) }} style="color: {{ $colour }}">
    {{-- FINAL glyph from FIXPASS.md section 5, verbatim; only FILL, STROKE and GLOW vary. --}}
    <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" style="flex-shrink: 0; filter: {{ $glow }}"><path d="M7 1.5 12.5 4.7v4.6L7 12.5 1.5 9.3V4.7z" fill="{{ $fill }}" stroke="{{ $stroke }}" stroke-width="1.2" stroke-dasharray="2 1.5"></path><path d="M7 1 13 4.5 7 8 1 4.5z" fill="#FFFFFF" fill-opacity=".35"></path></svg>
    <span>{{ $label }}</span>
</span>
