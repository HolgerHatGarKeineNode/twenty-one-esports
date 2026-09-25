@props(['fen', 'color'])

{{--
    The pieces one side has captured, strongest first, and its material lead
    (resources/js/captured.js). `fen` and `color` are Alpine expressions of
    the enclosing board component, e.g. fen="state.fen" color="topColor".
--}}
@php($names = ['q' => __('queen'), 'r' => __('rook'), 'b' => __('bishop'), 'n' => __('knight'), 'p' => __('pawn')])
<span x-data="{ get cap() { return window.chessCaptured?.({{ $fen }}, {{ $color }}, @js($names)) ?? { glyphs: '', lead: '', label: '' } } }"
      {{ $attributes->merge(['data-test' => 'captured'])->class('flex min-h-5 min-w-0 items-center gap-1.5 text-ink-2') }}
      :aria-label="cap.label ? @js(__('Captured: :pieces')).replace(':pieces', cap.label) : @js(__('Nothing captured yet'))" role="img">
    <span class="truncate font-[DejaVu_Sans,Noto_Sans_Symbols_2,Segoe_UI_Symbol,sans-serif] text-[19px] leading-none tracking-[-0.08em] text-ink" aria-hidden="true" x-text="cap.glyphs"></span>
    <span class="shrink-0 text-xs" aria-hidden="true" x-text="cap.lead"></span>
</span>
