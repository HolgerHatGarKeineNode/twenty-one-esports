{{--
    G4 · Terminal ticker · TMNF's time to beat (TmnfSlides::LEADER), over two cars side by side (an official screenshot,
    the scrim baked into the still). Top left the game's mark, beside it the week and the title: "New #1 on the board"
    while the week's best time is younger than an hour, "The time to beat" while the week runs, the winner once it is
    over. The #1 big and crowned, the name, the time in fixed digit cells as the hero, how far it is off (or under) the
    author time and when it was set; at the foot when the week closes and where to join. Without anybody on the board
    the open first place and the call to set it.

    Data contract (SceneSource, TmnfSlides::scene()):
      $tmnf      array{state: string, week: string, track: string, server: string, closes: string, url: string,
                   leader: array{name: string, time: string, avatar: ?string, versus: ?string, new: bool, when: ?string}|null, …}|null
                 null while TMNF is switched off: the slide invites to every game instead
      $stats     array: the stats bar counts (c-chrome)
      $backdrop  ?string: the slide's still (TMNF on), else the brand's blurred cover
      $cover     ?string: TMNF's cover as a data URI, the game's mark
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tmnf ?? null) ? $tmnf : null;
    $state = K::text($t ?? [], 'state', 'empty');
    $leader = is_array($t['leader'] ?? null) ? $t['leader'] : null;
    $title = match (true) {
        $leader === null => 'Be the first on the board',
        $state === 'finished' => 'Winner of the week',
        $state === 'checking' => 'Fastest of the week',
        (bool) ($leader['new'] ?? false) => 'New #1 on the board',
        default => 'The time to beat',
    };
    $week = K::fit(K::text($t ?? [], 'week', 'TMNF this week'), K::MONO, 22, 560);
    $name = $leader ? K::name(K::text($leader, 'name'), 'Player', 48, 560) : null;
    $time = $leader ? K::text($leader, 'time') : '';
    $cells = $time !== '' ? K::digitCells($time, 300, 96) : null;
    $versus = $leader ? K::fit(K::text($leader, 'versus'), K::MONO, 24, 640) : '';
    $when = $leader ? K::fit(K::text($leader, 'when'), K::MONO, 20, 640) : '';
    $server = K::clean(K::text($t ?? [], 'server', 'TWENTY ONE')) ?: 'TWENTY ONE';
    $track = K::clean(K::text($t ?? [], 'track', 'the track of the week'));
    $closes = K::text($t ?? [], 'closes', 'Monday 00:00 Berlin');
    $foot = K::fit(match (true) {
        in_array($state, ['finished', 'checking'], true) => 'A new week starts every Monday on '.$server.'.',
        $leader === null => 'Drive it on '.$server.'. Your best time counts until '.$closes.'.',
        default => 'Beat it on '.$server.' by '.$closes.'.',
    }, K::MONO, 22, 1200);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => $t ? 0 : 0.86])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'trackmania'])

@if ($t)
@include('stream.rotation.partials.game-mark', ['gmCover' => $cover ?? null, 'gmX' => 40, 'gmY' => 104, 'gmW' => 192, 'gmEdge' => '#5EEAD4'])
<text data-unit="week" data-box="255 112 817 138" x="256" y="132" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#A1A1A7">{{ $week }}</text>
<text data-unit="title" data-box="254 150 1000 198" x="256" y="188" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">{{ $title }}</text>
<text data-unit="track" data-box="255 202 900 224" x="256" y="220" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#C9C9CE">{{ K::fit($track, K::MONO, 18, 640) }}</text>

@if ($leader)
@include('stream.rotation.partials.face', ['face' => K::prideFace($leader), 'x' => 40, 'y' => 312, 'd' => 216, 'id' => 'tml', 'fUnit' => 'leader-face', 'fRing' => '#5EEAD4', 'fCrown' => '#F7931A'])
<text data-unit="name" data-box="299 272 900 324" x="300" y="314" font-family="{{ $name['font'] }}" font-weight="800" font-size="48" fill="#FFFFFF">{{ $name['text'] }}</text>
@if ($cells)@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 432, 'fill' => '#5EEAD4'])@endif
@if ($versus !== '')<text data-unit="versus" data-box="299 458 960 486" x="300" y="480" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF">{{ $versus }}</text>@endif
@if ($when !== '')<text data-unit="when" data-box="299 494 960 518" x="300" y="514" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#C9C9CE">{{ $when }}</text>@endif
@else
@include('stream.rotation.partials.face', ['face' => null, 'x' => 40, 'y' => 312, 'd' => 216, 'id' => 'tml-open', 'fOpen' => 'open', 'fOpenInk' => '#5EEAD4'])
<text data-unit="name" data-box="299 300 1000 352" x="300" y="342" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">#1 is free</text>
<text data-unit="versus" data-box="299 380 1000 408" x="300" y="402" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF">{{ K::fit('Set the first time on '.$track.'.', K::MONO, 24, 700) }}</text>
@endif

<text data-unit="foot" data-box="39 572 1241 598" x="40" y="592" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $foot }}</text>
@else
<text data-unit="title" data-box="38 150 1241 210" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">Every game</text>
<text data-unit="claim" data-box="39 240 1241 270" x="40" y="262" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Every game of the league is on the site.</text>
@endif
</svg>
