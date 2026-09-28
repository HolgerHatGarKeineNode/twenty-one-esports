{{--
    D2 · Broadcast desk · casual cups. Channel frame over the brand backdrop; the pitch on top, then one tile per game
    with an open casual cup (at most four, soonest close first): the game's cover, its name, then one row per region's
    cup (EU and US, user 2026-09-28): the region, the places taken with a fill bar, and when the sign-up closes.
    Without an open cup the tiles give way to one line.

    Data contract:
      $upcoming  list<array>: TournamentSlides::frames() of every upcoming tournament; read are cup (bool), region
                 ("EU", "US" or null), game, cover (data URI or null), taken, places, signupClosesAt
      $stats     array: the ticker counts (b-chrome)
      $backdrop  ?string, optional: the brand backdrop, as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $cups = K::cups($upcoming ?? [], 4);
    $tiles = [];
    foreach ($cups as $i => $cup) {
        $rows = [];
        foreach ($cup['regions'] as $r => $row) {
            $taken = ($row['region'] !== '' ? $row['region'].' · ' : '').($row['places'] > 0 ? $row['taken'].' / '.$row['places'].' signed up' : $row['taken'].' signed up');
            $rows[] = [
                'y' => 488 + $r * 66,
                'taken' => K::fit($taken, K::MONO, 17, 256),
                'fill' => 256 * ($row['places'] > 0 ? min(1, $row['taken'] / $row['places']) : 0),
                'closes' => $row['closes'] !== '' ? K::fit('closes '.$row['closes'], K::MONO, 14, 256) : '',
            ];
        }
        $tiles[] = [
            'x' => 40 + $i * 304,
            'cover' => $cup['cover'],
            'game' => $cup['game'],
            'gameSize' => min(24, 240 / max(1, K::width($cup['game'], K::DISPLAY, 1))),
            'rows' => $rows,
        ];
    }
    $pitch = count($tiles) === 1 ? 'Open now, one per region. Sign up on the site.' : 'One per region in every game. Sign up on the site.';
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.78])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => 'casual cups'])

<text x="40" y="158" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">Casual cups</text>
@if ($tiles !== [])
<text data-unit="pitch" data-box="39 182 1240 210" x="40" y="204" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">{{ $pitch }}</text>
@foreach ($tiles as $i => $t)
<rect x="{{ $t['x'] }}" y="240" width="288" height="376" fill="#121215" fill-opacity="0.94"/>
@if ($t['cover'])
<svg x="{{ $t['x'] }}" y="240" width="288" height="162" viewBox="0 0 288 162"><image x="0" y="0" width="288" height="162" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $t['cover'] }}"/></svg>
@else
<rect x="{{ $t['x'] }}" y="240" width="288" height="162" fill="#1C1C21"/>
@endif
<rect x="{{ $t['x'] }}" y="402" width="288" height="4" fill="#F7931A"/>
<text data-unit="cup-game-{{ $i }}" data-box="{{ $t['x'] + 15 }} 424 {{ $t['x'] + 274 }} 458" x="{{ $t['x'] + 16 }}" y="450" font-family="Unbounded" font-weight="800" font-size="{{ $t['gameSize'] }}" fill="#FFFFFF">{{ K::fit($t['game'], K::DISPLAY, $t['gameSize'], 256) }}</text>
@foreach ($t['rows'] as $r => $row)
<text data-unit="cup-taken-{{ $i }}-{{ $r }}" data-box="{{ $t['x'] + 15 }} {{ $row['y'] - 16 }} {{ $t['x'] + 274 }} {{ $row['y'] + 5 }}" x="{{ $t['x'] + 16 }}" y="{{ $row['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="17" fill="#F7931A">{{ $row['taken'] }}</text>
<rect x="{{ $t['x'] + 16 }}" y="{{ $row['y'] + 10 }}" width="256" height="6" fill="#2A2A30"/>
@if ($row['fill'] > 0)<rect x="{{ $t['x'] + 16 }}" y="{{ $row['y'] + 10 }}" width="{{ $row['fill'] }}" height="6" fill="#F7931A"/>@endif
@if ($row['closes'] !== '')
<text data-unit="cup-closes-{{ $i }}-{{ $r }}" data-box="{{ $t['x'] + 15 }} {{ $row['y'] + 22 }} {{ $t['x'] + 274 }} {{ $row['y'] + 42 }}" x="{{ $t['x'] + 16 }}" y="{{ $row['y'] + 36 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#FFFFFF">{{ $row['closes'] }}</text>
@endif
@endforeach
@endforeach
@else
<text x="40" y="290" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#FFFFFF">No cup is open right now.</text>
<text x="40" y="334" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Tournaments and cups: esports.einundzwanzig.space/tournaments</text>
@endif
</svg>
