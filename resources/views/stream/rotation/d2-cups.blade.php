{{--
    D2 · Broadcast desk · casual cups. Channel frame over the brand backdrop; the pitch on top, then one tile per open
    casual cup (at most four, one per game, soonest close first): the game's cover, its name, the places taken with a
    fill bar, and when the sign-up closes. Without an open cup the tiles give way to one line.

    Data contract:
      $upcoming  list<array>: TournamentSlides::frames() of every upcoming tournament; read are cup (bool), game,
                 cover (data URI or null), taken, places, signupClosesAt
      $stats     array: the ticker counts (b-chrome)
      $backdrop  ?string, optional: the brand backdrop, as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $cups = K::cups($upcoming ?? [], 4);
    $tiles = [];
    foreach ($cups as $i => $cup) {
        $x = 40 + $i * 304;
        $fill = $cup['places'] > 0 ? min(1, $cup['taken'] / $cup['places']) : 0;
        $tiles[] = [
            'x' => $x,
            'cover' => $cup['cover'],
            'game' => $cup['game'],
            'gameSize' => min(24, 240 / max(1, K::width($cup['game'], K::DISPLAY, 1))),
            'taken' => $cup['places'] > 0 ? $cup['taken'].' / '.$cup['places'].' signed up' : $cup['taken'].' signed up',
            'fill' => 256 * $fill,
            'closes' => $cup['closes'] !== '' ? K::fit($cup['closes'], K::MONO, 16, 256) : '',
        ];
    }
    $pitch = count($tiles) === 1 ? 'One open now. Sign up on the site.' : 'One open in every game. Sign up on the site.';
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
<text data-unit="cup-taken-{{ $i }}" x="{{ $t['x'] + 16 }}" y="500" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $t['taken'] }}</text>
<rect x="{{ $t['x'] + 16 }}" y="516" width="256" height="8" fill="#2A2A30"/>
@if ($t['fill'] > 0)<rect x="{{ $t['x'] + 16 }}" y="516" width="{{ $t['fill'] }}" height="8" fill="#F7931A"/>@endif
@if ($t['closes'] !== '')
<text x="{{ $t['x'] + 16 }}" y="562" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#ADADB0">sign-up closes</text>
<text data-unit="cup-closes-{{ $i }}" data-box="{{ $t['x'] + 15 }} 572 {{ $t['x'] + 274 }} 594" x="{{ $t['x'] + 16 }}" y="588" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">{{ $t['closes'] }}</text>
@endif
@endforeach
@else
<text x="40" y="290" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#FFFFFF">No cup is open right now.</text>
<text x="40" y="334" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Tournaments and cups: esports.einundzwanzig.space/tournaments</text>
@endif
</svg>
