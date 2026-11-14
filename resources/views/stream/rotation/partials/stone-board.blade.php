{{--
    A Go board on the board scene (d5): the slab, its lower face, the grain, the light on its upper edge, the lines,
    the star points, the stones with their shadows and the ring on the stone set last. `$go` is
    StoneBoardDrawing::layout(); the gradients are StoneBoardArt::gradients() in the scene's <defs>. Plain SVG for
    rsvg-convert, no filter: a shadow is a radial gradient under its stone.
--}}
@use('App\Support\Cards\StoneBoardArt', 'SBA')
<rect x="{{ $go['slab']['x'] }}" y="{{ $go['slab']['y'] + $go['slab']['face'] }}" width="{{ $go['slab']['w'] }}" height="{{ $go['slab']['h'] }}" fill="{{ SBA::SIDE }}"/>
<rect data-unit="wood" x="{{ $go['slab']['x'] }}" y="{{ $go['slab']['y'] }}" width="{{ $go['slab']['w'] }}" height="{{ $go['slab']['h'] }}" fill="url(#go-wood)"/>
@foreach ($go['grain'] as [$gx, $gw, $go_opacity])<rect x="{{ $gx }}" y="{{ $go['slab']['y'] }}" width="{{ $gw }}" height="{{ $go['slab']['h'] }}" fill="{{ SBA::GRAIN }}" fill-opacity="{{ $go_opacity }}"/>@endforeach

<rect x="{{ $go['slab']['x'] }}" y="{{ $go['slab']['y'] }}" width="{{ $go['slab']['w'] }}" height="1" fill="{{ SBA::SHEEN }}" fill-opacity="0.4"/>
@foreach ($go['lines'] as [$x1, $y1, $x2, $y2, $lw])<line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}" stroke="{{ SBA::LINE }}" stroke-width="{{ $lw }}" stroke-linecap="square"/>@endforeach

@foreach ($go['stars'] as [$sx, $sy])<circle cx="{{ $sx }}" cy="{{ $sy }}" r="{{ $go['star'] }}" fill="{{ SBA::LINE }}"/>@endforeach

@foreach ($go['stones'] as $st)<circle cx="{{ $st['x'] + $go['shadow']['dx'] }}" cy="{{ $st['y'] + $go['shadow']['dy'] }}" r="{{ $go['shadow']['radius'] }}" fill="url(#go-stone-shadow)"/><circle data-piece="{{ $st['side'] }}" data-stone="{{ $st['kind'] }}" cx="{{ $st['x'] }}" cy="{{ $st['y'] }}" r="{{ $go['radius'] }}" fill="url(#go-stone-{{ $st['kind'] }})" stroke="{{ SBA::EDGE[$st['kind']] }}" stroke-width="1"/>@endforeach

@if ($go['ring'] !== null)
<circle data-last="{{ $go['ring']['kind'] }}" cx="{{ $go['ring']['x'] }}" cy="{{ $go['ring']['y'] }}" r="{{ $go['ring']['radius'] }}" fill="none" stroke="{{ SBA::RING[$go['ring']['kind']] }}" stroke-width="{{ $go['ring']['width'] }}"/>
@endif
