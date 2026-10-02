{{--
    G1 · Terminal ticker · TrackMania Nations Forever (plan "Trackmania und Restposten", P2): the weekly time attack on
    the league's own server, as Blockfill's f1. The C frame of d4: header line and stats bar. Left the week, its state
    (when it ends, its final standings, or that nobody is on the board yet), the track of the week under a chequered
    band in the game's teal, the leader with face and time, and how to join (the server's name, the game's page).
    Right the week's top 5 with face, name and time; an empty week shows five open places. Players only by their league
    name, never a TMNF login.

    Data contract (SceneSource, TmnfSlide::cached()):
      $tmnf       array{state: 'empty'|'running'|'finished', title: string, line: string, track: string, server: string,
                    top: list<array{place: int, name: string, time: string, avatar: ?string}>,
                    leader: array{name: string, time: string, avatar: ?string}|null, url: string}|null
                  null while TMNF is switched off: the slide invites to every game instead
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: TMNF's blurred cover, else the brand's
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tmnf ?? null) ? $tmnf : null;
    $state = in_array($t['state'] ?? null, ['empty', 'running', 'finished'], true) ? $t['state'] : 'empty';
    $title = K::fit(K::text($t ?? [], 'title', 'TMNF'), K::MONO, 22, 560);
    $line = K::fit(K::text($t ?? [], 'line'), K::MONO, 22, 560);
    $track = K::fit(K::text($t ?? [], 'track', 'A01-Race'), K::DISPLAY, 36, 560);
    $server = K::fit('Join '.K::text($t ?? [], 'server', 'our server').' in TMNF', K::MONO, 18, 424);
    $leader = is_array($t['leader'] ?? null) ? $t['leader'] : null;
    $leaderName = $leader ? K::name(K::text($leader, 'name'), 'Player', 32, 470) : null;
    $leaderTime = $leader ? K::fit(K::text($leader, 'time'), K::MONO, 24, 470) : '';
    $rows = [];
    foreach (array_slice(is_array($t['top'] ?? null) ? $t['top'] : [], 0, 5) as $i => $row) {
        if (! is_array($row) || K::clean(K::text($row, 'name')) === '') {
            continue;
        }
        $place = is_int($row['place'] ?? null) ? $row['place'] : $i + 1;
        $rows[] = ['y' => 160 + count($rows) * 88, 'place' => $place, 'face' => K::prideFace($row), 'name' => K::name(K::text($row, 'name'), 'Player', 26, 290),
            'time' => K::fit(K::text($row, 'time'), K::MONO, 24, 150)];
    }
    $url = K::fit(K::text($t ?? [], 'url', 'esports.einundzwanzig.space'), K::MONO, 18, 424);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.84])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'trackmania'])

@if ($t)
<text data-unit="week" data-box="39 112 601 138" x="40" y="132" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#A1A1A7">{{ $title }}</text>
<text data-unit="title" data-box="38 150 601 210" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">TrackMania</text>
<text data-unit="line" data-box="39 226 601 252" x="40" y="246" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $line }}</text>

{{-- The track of the week under a chequered band in the game's teal: two rows of 8 px squares. --}}
<g data-unit="flag" data-box="40 268 600 284">
@foreach (range(0, 69) as $i)
<rect x="{{ 40 + $i * 8 }}" y="{{ $i % 2 === 0 ? 268 : 276 }}" width="8" height="8" fill="#5EEAD4"/>
@endforeach
</g>
<text data-unit="track-label" data-box="39 294 601 314" x="40" y="310" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">Track of the week</text>
<text data-unit="track" data-box="38 320 601 360" x="40" y="354" font-family="Unbounded" font-weight="800" font-size="36" fill="#5EEAD4">{{ $track }}</text>

@if ($leader)
@include('stream.rotation.partials.face', ['face' => K::prideFace($leader), 'x' => 40, 'y' => 400, 'd' => 80, 'id' => 'tm-leader', 'fUnit' => 'leader-face', 'fRing' => '#5EEAD4', 'fCrown' => '#F7931A'])
<text data-unit="leader-name" data-box="135 410 601 450" x="136" y="442" font-family="{{ $leaderName['font'] }}" font-weight="800" font-size="32" fill="#FFFFFF">{{ $leaderName['text'] }}</text>
<text data-unit="leader-time" data-box="135 456 601 482" x="136" y="476" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#5EEAD4">{{ $leaderTime }}</text>
@else
<text data-unit="leader-name" data-box="39 410 601 450" x="40" y="442" font-family="Unbounded" font-weight="800" font-size="30" fill="#FFFFFF">Be the first on the board.</text>
<text data-unit="leader-time" data-box="39 456 601 482" x="40" y="476" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">Your best finish of the week counts.</text>
@endif

<rect x="40" y="516" width="120" height="44" fill="#F7931A"/>
<text data-unit="cta" data-box="40 516 160 560" x="100" y="545" font-family="Unbounded" font-weight="800" font-size="20" fill="#17120A" text-anchor="middle">Join</text>
<text data-unit="cta-url" data-box="175 516 601 538" x="176" y="533" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $url }}</text>
<text data-unit="cta-server" data-box="175 540 601 562" x="176" y="557" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $server }}</text>

{{-- The week's top 5. --}}
<text data-unit="top-label" data-box="679 112 1241 138" x="680" y="132" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#A1A1A7">{{ $state === 'finished' ? 'Final top 5' : 'Top 5 this week' }}</text>
@foreach ($rows as $r)
<text data-unit="place-{{ $r['place'] }}" data-box="679 {{ $r['y'] + 14 }} 720 {{ $r['y'] + 50 }}" x="680" y="{{ $r['y'] + 44 }}" font-family="Unbounded" font-weight="800" font-size="28" fill="#5EEAD4">{{ $r['place'] }}</text>
@include('stream.rotation.partials.face', ['face' => $r['face'], 'x' => 728, 'y' => $r['y'] + 4, 'd' => 56, 'id' => 'tm-place-'.$r['place'], 'fUnit' => 'place-face-'.$r['place']])
<text data-unit="place-name-{{ $r['place'] }}" data-box="799 {{ $r['y'] + 16 }} 1090 {{ $r['y'] + 48 }}" x="800" y="{{ $r['y'] + 42 }}" font-family="{{ $r['name']['font'] }}" font-weight="800" font-size="26" fill="#FFFFFF">{{ $r['name']['text'] }}</text>
<text data-unit="place-time-{{ $r['place'] }}" data-box="1090 {{ $r['y'] + 20 }} 1241 {{ $r['y'] + 46 }}" x="1240" y="{{ $r['y'] + 42 }}" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF" text-anchor="end">{{ $r['time'] }}</text>
<rect x="680" y="{{ $r['y'] + 72 }}" width="560" height="1" fill="#2A2A30"/>
@endforeach
@for ($i = count($rows); $i < 5; $i++)
@php($oy = 160 + $i * 88)
<text x="680" y="{{ $oy + 44 }}" font-family="Unbounded" font-weight="800" font-size="28" fill="#3A3A42">{{ $i + 1 }}</text>
@include('stream.rotation.partials.face', ['face' => null, 'x' => 728, 'y' => $oy + 4, 'd' => 56, 'id' => 'tm-open-'.$i, 'fOpen' => 'open', 'fOpenInk' => '#63636A'])
<text x="800" y="{{ $oy + 42 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#63636A">open</text>
<rect x="680" y="{{ $oy + 72 }}" width="560" height="1" fill="#2A2A30"/>
@endfor
@else
<text data-unit="title" data-box="38 150 1241 210" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">Every game</text>
<text data-unit="claim" data-box="39 240 1241 270" x="40" y="262" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Every game of the league is on the site.</text>
@endif
</svg>
