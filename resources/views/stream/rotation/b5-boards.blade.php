{{--
    B5 · Broadcast desk · board themes teaser. Channel frame; the four themes of the chess settings side by side on the
    same position (names as resources/views/pages/settings/⚡chess.blade.php labels them), Orange Pill
    tagged as the member perk. The last move keeps the site's orange on every theme. The brand backdrop behind.

    Data contract:
      $stats     array for the ticker (see b1-match)
      $backdrop  ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $fen = '6k1/5ppp/8/8/3r4/8/5PPP/4R1K1 w - - 0 38';
    $themes = [
        ['house', 'House', false],
        ['wood', 'Wood', false],
        ['slate', 'Slate', false],
        ['orange', 'Orange Pill', true],
    ];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? []])
<text x="40" y="166" font-family="Unbounded" font-weight="800" font-size="44" fill="#FFFFFF">Pick your board</text>
<text x="40" y="202" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">In your chess settings.</text>
@foreach ($themes as $i => [$key, $name, $member])
@php($x = 44 + $i * 308)
@include('stream.rotation.partials.board', ['b' => K::board($fen, ['from' => 'd8', 'to' => 'd4'], $x, 248, 32, $key), 'frame' => 4, 'frameFill' => $member ? '#F7931A' : '#3A2C14'])
<text x="{{ $x - 4 }}" y="548" font-family="Unbounded" font-weight="800" font-size="22" fill="{{ $member ? '#F7931A' : '#FFFFFF' }}">{{ $name }}</text>
@if ($member)
<rect x="{{ $x + 159 }}" y="529" width="78" height="24" rx="2" fill="none" stroke="#F7931A" stroke-width="2"/>
<text x="{{ $x + 198 }}" y="546" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#F7931A" text-anchor="middle">Members</text>
@endif
@endforeach
</svg>
