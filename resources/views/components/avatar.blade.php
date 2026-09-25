@props(['name', 'size' => 24, 'src' => null, 'background' => 'linear-gradient(135deg, #F9B25F, #B9640A)'])

{{--
    Initial avatar from the designs: gradient disc, Unbounded 800 initial, dark ink.
    With `src` the picture lies on top of the disc; while it loads, or when the
    URL is dead (common for kind-0 pictures), the initial stays visible.
--}}
<span aria-hidden="true"
      {{ $attributes->class('relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full font-display font-extrabold text-on-btc') }}
      style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ $size >= 28 ? 11 : 10 }}px; background: {{ $background }}">{{ mb_strtoupper(mb_substr((string) $name, 0, 1)) }}@if ($src)<img src="{{ $src }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="absolute inset-0 size-full object-cover" onerror="this.remove()">@endif</span>
