{{--
    F2 · Terminal ticker · Blockfill's week board (BlockfillSlides::BOARD): the C frame of f1. Top left the game's mark
    (its cover, partials/game-mark), beside it "Blockfill Week N, YYYY", the headline and how many are on the board;
    right the countdown to the week's end (Monday 00:00 Berlin), ticking in
    fixed digit cells so it never jitters. Below the top ten in two columns of five (places 1-5 left, 6-10 right),
    each with avatar, name, best time and the gap to first; first carries the orange ring and "time to beat" in the
    gap's place. Places nobody holds yet stay open seats. Nothing at x >= 1040 above y 112 (the client's LIVE badge).

    Data contract (SceneSource, BlockfillSlides::scene()):
      $blockfill  array{week: string, title: string, countdown: string, closes: string, players: int,
                    board: list<array{place: int, name: string, time: string, gap: ?string, avatar: ?string}>, …}|null
                  null while Blockfill is switched off: the slide invites to every game instead
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: Blockfill's blurred cover, else the brand's
      $cover      ?string, optional: Blockfill's cover as a data URI, the game's mark
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = is_array($blockfill ?? null) ? $blockfill : null;
    // Always "Blockfill …", whatever the week's own title says.
    $title = K::fit('Blockfill '.(preg_replace('/^Blockfill\s*/', '', K::text($b ?? [], 'title')) ?: 'this week'), K::MONO, 22, 560);
    $players = is_int($b['players'] ?? null) ? $b['players'] : 0;
    $count = $players === 1 ? '1 player on the board' : $players.' players on the board';
    $closes = K::fit('Closes '.K::text($b ?? [], 'closes', 'Monday 00:00 Berlin'), K::MONO, 18, 400);
    $cd = K::countdownParts($b['countdown'] ?? null);
    $cells = $cd === null ? null : K::digitCells(($cd['days'] > 0 ? $cd['days'].'d ' : '').$cd['hms'], 1240, 44, true);
    $rows = [];
    foreach (array_slice(is_array($b['board'] ?? null) ? $b['board'] : [], 0, 10) as $i => $row) {
        // A name with nothing printable left still holds its place, as "Player".
        if (! is_array($row)) {
            continue;
        }
        $n = count($rows);
        $x0 = $n < 5 ? 40 : 660;
        $top = 236 + ($n % 5) * 76;
        $place = is_int($row['place'] ?? null) ? $row['place'] : $n + 1;
        $rows[] = ['x' => $x0, 'top' => $top, 'place' => $place, 'face' => K::prideFace($row),
            'name' => K::name(K::text($row, 'name'), 'Player', 24, 296),
            'time' => K::fit(K::text($row, 'time'), K::MONO, 24, 150),
            'gap' => K::fit(K::text($row, 'gap'), K::MONO, 18, 150)];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.86])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'blockfill'])

@if ($b)
@include('stream.rotation.partials.game-mark', ['gmCover' => $cover ?? null, 'gmX' => 40, 'gmY' => 104, 'gmW' => 192, 'gmEdge' => '#F7931A'])
<text data-unit="week" data-box="255 112 817 138" x="256" y="132" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#A1A1A7">{{ $title }}</text>
<text data-unit="title" data-box="254 146 830 198" x="256" y="188" font-family="Unbounded" font-weight="800" font-size="44" fill="#FFFFFF">The week's fastest</text>
<text data-unit="count" data-box="255 200 817 222" x="256" y="218" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">{{ $count }}</text>
<text data-unit="closes" data-box="839 116 1241 138" x="1240" y="132" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7" text-anchor="end">{{ $closes }}</text>
@if ($cells)@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 188, 'fill' => '#F7931A'])@endif

@foreach ($rows as $r)
@php($lead = $r['place'] === 1)
<text data-unit="place-{{ $r['place'] }}" data-box="{{ $r['x'] - 1 }} {{ $r['top'] + 16 }} {{ $r['x'] + 40 }} {{ $r['top'] + 50 }}" x="{{ $r['x'] }}" y="{{ $r['top'] + 46 }}" font-family="Unbounded" font-weight="800" font-size="28" fill="#F7931A">{{ $r['place'] }}</text>
@include('stream.rotation.partials.face', ['face' => $r['face'], 'x' => $r['x'] + 56, 'y' => $r['top'] + 8, 'd' => 52, 'id' => 'bf2-'.$r['place'], 'fUnit' => 'place-face-'.$r['place'], 'fRing' => $lead ? '#F7931A' : null])
<text data-unit="place-name-{{ $r['place'] }}" data-box="{{ $r['x'] + 123 }} {{ $r['top'] + 18 }} {{ $r['x'] + 422 }} {{ $r['top'] + 48 }}" x="{{ $r['x'] + 124 }}" y="{{ $r['top'] + 44 }}" font-family="{{ $r['name']['font'] }}" font-weight="800" font-size="24" fill="#FFFFFF">{{ $r['name']['text'] }}</text>
<text data-unit="place-time-{{ $r['place'] }}" data-box="{{ $r['x'] + 429 }} {{ $r['top'] + 14 }} {{ $r['x'] + 581 }} {{ $r['top'] + 38 }}" x="{{ $r['x'] + 580 }}" y="{{ $r['top'] + 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF" text-anchor="end">{{ $r['time'] }}</text>
<text data-unit="place-gap-{{ $r['place'] }}" data-box="{{ $r['x'] + 429 }} {{ $r['top'] + 42 }} {{ $r['x'] + 581 }} {{ $r['top'] + 62 }}" x="{{ $r['x'] + 580 }}" y="{{ $r['top'] + 58 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $lead ? '#F7931A' : '#A1A1A7' }}" text-anchor="end">{{ $lead ? 'time to beat' : $r['gap'] }}</text>
<rect x="{{ $r['x'] }}" y="{{ $r['top'] + 72 }}" width="580" height="1" fill="#2A2A30"/>
@endforeach
{{-- The places nobody holds yet: open seats in their rows. --}}
@for ($i = count($rows); $i < 10; $i++)
@php($ox = $i < 5 ? 40 : 660)
@php($oy = 236 + ($i % 5) * 76)
<text x="{{ $ox }}" y="{{ $oy + 46 }}" font-family="Unbounded" font-weight="800" font-size="28" fill="#3A3A42">{{ $i + 1 }}</text>
@include('stream.rotation.partials.face', ['face' => null, 'x' => $ox + 56, 'y' => $oy + 8, 'd' => 52, 'id' => 'bf2-open-'.$i, 'fOpen' => 'open', 'fOpenInk' => '#63636A'])
<text x="{{ $ox + 124 }}" y="{{ $oy + 42 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#7A7A82">open</text>
<rect x="{{ $ox }}" y="{{ $oy + 72 }}" width="580" height="1" fill="#2A2A30"/>
@endfor
@else
<text data-unit="title" data-box="38 150 1241 210" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">Every game</text>
<text data-unit="claim" data-box="39 240 1241 270" x="40" y="262" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Every game of the league is on the site.</text>
@endif
</svg>
