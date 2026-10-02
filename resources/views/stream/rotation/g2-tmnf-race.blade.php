{{--
    G2 · Terminal ticker · TMNF's race to the author time (TmnfSlides::RACE), over an official screenshot of the game
    (the checkpoint arch, the scrim baked into the still). Top left the game's mark (its cover, partials/game-mark),
    beside it the week, the headline and the track with its author time; top right when the week closes. Below, the
    week's top 5 as lanes: each player's marker stands as close to the author time's teal line as their best time is
    (the slowest shown at the lane's start, a time under the author time past the line), their name, time and gap to
    the author time left of it. Places nobody holds stay empty lanes. Players only by their league name.

    Data contract (SceneSource, TmnfSlides::scene()):
      $tmnf      array{state: string, week: string, track: string, author: string, authorTime: ?string, closes: string,
                   lanes: list<array{place: int, name: string, time: string, versus: string, under: bool, x: float,
                   avatar: ?string}>, …}|null
                 null while TMNF is switched off: the slide invites to every game instead
      $stats     array: the stats bar counts (c-chrome)
      $backdrop  ?string: the slide's still (TMNF on), else the brand's blurred cover
      $cover     ?string: TMNF's cover as a data URI, the game's mark
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tmnf ?? null) ? $tmnf : null;
    $state = K::text($t ?? [], 'state', 'empty');
    $week = K::fit(K::text($t ?? [], 'week', 'TMNF this week'), K::MONO, 22, 560);
    $authorTime = K::text($t ?? [], 'authorTime');
    $author = K::text($t ?? [], 'author');
    $sub = K::fit(K::text($t ?? [], 'track', 'A01-Race').($author !== '' ? ' by '.$author : '').($authorTime !== '' ? ': author time '.$authorTime : ''), K::MONO, 18, 640);
    $corner = K::fit(match ($state) {
        'finished' => 'Final standings',
        'checking' => 'Week over, the times are being checked',
        default => 'Closes '.K::text($t ?? [], 'closes', 'Monday 00:00 Berlin'),
    }, K::MONO, 18, 340);
    $lanes = [];
    foreach (array_slice(is_array($t['lanes'] ?? null) ? $t['lanes'] : [], 0, 5) as $i => $lane) {
        if (! is_array($lane)) {
            continue;
        }
        $n = count($lanes);
        $cy = 284 + $n * 72;
        $x = is_numeric($lane['x'] ?? null) ? max(400.0, min(1184.0, (float) $lane['x'])) : 400.0;
        $versus = K::fit(K::text($lane, 'versus'), K::MONO, 18, 120);
        $room = $x - 40 - 96;
        $time = K::fit(K::text($lane, 'time'), K::MONO, 18, 140);
        $lanes[] = ['cy' => $cy, 'x' => $x, 'place' => is_int($lane['place'] ?? null) ? $lane['place'] : $n + 1, 'face' => K::prideFace($lane),
            'name' => K::name(K::text($lane, 'name'), 'Player', 22, $room), 'time' => $time, 'versus' => $versus,
            'versusX' => $x - 40, 'timeX' => $x - 40 - K::width($versus, K::MONO, 18) - ($versus !== '' ? 12 : 0),
            'under' => (bool) ($lane['under'] ?? false)];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => $t ? 0 : 0.86])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'trackmania'])

@if ($t)
@include('stream.rotation.partials.game-mark', ['gmCover' => $cover ?? null, 'gmX' => 40, 'gmY' => 104, 'gmW' => 192, 'gmEdge' => '#5EEAD4'])
<text data-unit="week" data-box="255 112 817 138" x="256" y="132" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#A1A1A7">{{ $week }}</text>
<text data-unit="title" data-box="254 150 1000 198" x="256" y="188" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">The race to the author time</text>
<text data-unit="track" data-box="255 202 900 224" x="256" y="220" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#C9C9CE">{{ $sub }}</text>
<text data-unit="closes" data-box="899 116 1241 138" x="1240" y="132" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF" text-anchor="end">{{ $corner }}</text>

{{-- The author time's line: what every lane races towards. --}}
@if ($authorTime !== '')
<text data-unit="author-label" data-box="990 220 1186 240" x="1088" y="236" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#5EEAD4" text-anchor="middle">Author {{ $authorTime }}</text>
<rect x="1086" y="246" width="4" height="362" fill="#5EEAD4"/>
@endif

@for ($i = 0; $i < 5; $i++)
@php($ly = 284 + $i * 72)
<line x1="400" y1="{{ $ly }}" x2="1184" y2="{{ $ly }}" stroke="#55555C" stroke-width="2" stroke-dasharray="10 10"/>
@endfor
@foreach ($lanes as $l)
<text data-unit="place-{{ $l['place'] }}" data-box="39 {{ $l['cy'] - 22 }} 80 {{ $l['cy'] + 14 }}" x="40" y="{{ $l['cy'] + 10 }}" font-family="Unbounded" font-weight="800" font-size="28" fill="#5EEAD4">{{ $l['place'] }}</text>
<text data-unit="lane-name-{{ $l['place'] }}" data-box="96 {{ $l['cy'] - 26 }} {{ $l['x'] - 39 }} {{ $l['cy'] }}" x="{{ $l['x'] - 40 }}" y="{{ $l['cy'] - 6 }}" font-family="{{ $l['name']['font'] }}" font-weight="800" font-size="22" fill="#FFFFFF" text-anchor="end">{{ $l['name']['text'] }}</text>
<text data-unit="lane-time-{{ $l['place'] }}" x="{{ round($l['timeX'], 1) }}" y="{{ $l['cy'] + 20 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF" text-anchor="end">{{ $l['time'] }}</text>
<text data-unit="lane-gap-{{ $l['place'] }}" x="{{ $l['versusX'] }}" y="{{ $l['cy'] + 20 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $l['under'] ? '#5EEAD4' : '#FFFFFF' }}" text-anchor="end">{{ $l['versus'] }}</text>
<g data-unit="lane-marker-{{ $l['place'] }}" data-x="{{ $l['x'] }}">
@include('stream.rotation.partials.face', ['face' => $l['face'], 'x' => $l['x'] - 24, 'y' => $l['cy'] - 24, 'd' => 48, 'id' => 'tmr-'.$l['place'], 'fUnit' => 'lane-face-'.$l['place'], 'fRing' => $l['under'] ? '#5EEAD4' : '#0A0A0B', 'fCrown' => $l['place'] === 1 ? '#F7931A' : null])
</g>
@endforeach
@if ($lanes === [])
<text data-unit="empty" data-box="399 400 1080 440" x="400" y="434" font-family="Unbounded" font-weight="800" font-size="30" fill="#FFFFFF">Nobody on the board yet.</text>
<text data-unit="empty-line" data-box="399 452 1080 476" x="400" y="472" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#C9C9CE">Set the first time and lead the race.</text>
@endif
@else
<text data-unit="title" data-box="38 150 1241 210" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">Every game</text>
<text data-unit="claim" data-box="39 240 1241 270" x="40" y="262" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Every game of the league is on the site.</text>
@endif
</svg>
