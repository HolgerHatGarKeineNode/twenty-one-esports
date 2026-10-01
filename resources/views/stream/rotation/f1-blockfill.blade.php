{{--
    F1 · Terminal ticker · Blockfill (plan "Blockfill", P6): the league's own stacking game and its weekly hunt. The
    C frame of d4: header line and stats bar. Left the week, its state (when it ends, its final standings, or that
    nobody is on the board yet), the chain of 40 blocks its leader mined as 40 cubes in the game's orange, the leader
    with face and time, and the call to play with the game's page. Right the week's top 5 with face, name and time;
    an empty week shows five open places. The league's orange stays on the action and the mined blocks.

    Data contract (SceneSource, BlockfillSlide::cached()):
      $blockfill  array{state: 'empty'|'running'|'finished', title: string, line: string,
                    top: list<array{place: int, name: string, time: string, avatar: ?string}>,
                    leader: array{name: string, time: string, avatar: ?string}|null, url: string}|null
                  null while Blockfill is switched off: the slide invites to every game instead
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: Blockfill's blurred cover, else the brand's
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = is_array($blockfill ?? null) ? $blockfill : null;
    $state = in_array($b['state'] ?? null, ['empty', 'running', 'finished'], true) ? $b['state'] : 'empty';
    $title = K::fit(K::text($b ?? [], 'title', 'Blockfill'), K::MONO, 22, 560);
    $line = K::fit(K::text($b ?? [], 'line'), K::MONO, 22, 560);
    $leader = is_array($b['leader'] ?? null) ? $b['leader'] : null;
    $leaderName = $leader ? K::name(K::text($leader, 'name'), 'Player', 32, 470) : null;
    $leaderTime = $leader ? K::fit(K::text($leader, 'time'), K::MONO, 24, 470) : '';
    $chainLabel = match (true) {
        $leader === null => '40 blocks to mine',
        $state === 'finished' => 'The winner\'s chain: 40 blocks',
        default => 'The leader\'s chain: 40 blocks',
    };
    $rows = [];
    foreach (array_slice(is_array($b['top'] ?? null) ? $b['top'] : [], 0, 5) as $i => $row) {
        if (! is_array($row) || K::clean(K::text($row, 'name')) === '') {
            continue;
        }
        $place = is_int($row['place'] ?? null) ? $row['place'] : $i + 1;
        $rows[] = ['y' => 160 + count($rows) * 88, 'place' => $place, 'face' => K::prideFace($row), 'name' => K::name(K::text($row, 'name'), 'Player', 26, 290),
            'time' => K::fit(K::text($row, 'time'), K::MONO, 24, 150)];
    }
    $url = K::fit(K::text($b ?? [], 'url', 'esports.einundzwanzig.space'), K::MONO, 18, 424);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.84])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'blockfill'])

@if ($b)
<text data-unit="week" data-box="39 112 601 138" x="40" y="132" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#A1A1A7">{{ $title }}</text>
<text data-unit="title" data-box="38 150 601 210" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">Blockfill</text>
<text data-unit="line" data-box="39 226 601 252" x="40" y="246" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $line }}</text>

{{-- The chain: 40 cubes in two rows of 20, mined (orange, a lit top edge) or still open (dashed). --}}
<text data-unit="chain-label" data-box="39 286 601 310" x="40" y="304" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">{{ $chainLabel }}</text>
<g data-unit="chain" data-box="40 322 596 374">
@foreach (range(0, 39) as $i)
@php($cx = 40 + ($i % 20) * 28)
@php($cy = 322 + intdiv($i, 20) * 28)
@if ($leader)
<rect x="{{ $cx }}" y="{{ $cy }}" width="24" height="24" fill="#F7931A"/>
<rect x="{{ $cx }}" y="{{ $cy }}" width="24" height="5" fill="#FFC27A"/>
@else
<rect x="{{ $cx + 0.5 }}" y="{{ $cy + 0.5 }}" width="23" height="23" fill="none" stroke="#63636A" stroke-width="1" stroke-dasharray="3 3"/>
@endif
@endforeach
</g>

@if ($leader)
@include('stream.rotation.partials.face', ['face' => K::prideFace($leader), 'x' => 40, 'y' => 400, 'd' => 80, 'id' => 'bf-leader', 'fUnit' => 'leader-face', 'fRing' => '#F7931A', 'fCrown' => '#F7931A'])
<text data-unit="leader-name" data-box="135 410 601 450" x="136" y="442" font-family="{{ $leaderName['font'] }}" font-weight="800" font-size="32" fill="#FFFFFF">{{ $leaderName['text'] }}</text>
<text data-unit="leader-time" data-box="135 456 601 482" x="136" y="476" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#F7931A">{{ $leaderTime }}</text>
@else
<text data-unit="leader-name" data-box="39 410 601 450" x="40" y="442" font-family="Unbounded" font-weight="800" font-size="30" fill="#FFFFFF">Be the first on the board.</text>
<text data-unit="leader-time" data-box="39 456 601 482" x="40" y="476" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">Your best ranked run of the week counts.</text>
@endif

<rect x="40" y="516" width="120" height="44" fill="#F7931A"/>
<text data-unit="cta" data-box="40 516 160 560" x="100" y="545" font-family="Unbounded" font-weight="800" font-size="20" fill="#17120A" text-anchor="middle">Play</text>
<text data-unit="cta-url" data-box="175 526 601 552" x="176" y="545" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $url }}</text>

{{-- The week's top 5. --}}
<text data-unit="top-label" data-box="679 112 1241 138" x="680" y="132" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#A1A1A7">{{ $state === 'finished' ? 'Final top 5' : 'Top 5 this week' }}</text>
@foreach ($rows as $r)
<text data-unit="place-{{ $r['place'] }}" data-box="679 {{ $r['y'] + 14 }} 720 {{ $r['y'] + 50 }}" x="680" y="{{ $r['y'] + 44 }}" font-family="Unbounded" font-weight="800" font-size="28" fill="#F7931A">{{ $r['place'] }}</text>
@include('stream.rotation.partials.face', ['face' => $r['face'], 'x' => 728, 'y' => $r['y'] + 4, 'd' => 56, 'id' => 'bf-place-'.$r['place'], 'fUnit' => 'place-face-'.$r['place']])
<text data-unit="place-name-{{ $r['place'] }}" data-box="799 {{ $r['y'] + 16 }} 1090 {{ $r['y'] + 48 }}" x="800" y="{{ $r['y'] + 42 }}" font-family="{{ $r['name']['font'] }}" font-weight="800" font-size="26" fill="#FFFFFF">{{ $r['name']['text'] }}</text>
<text data-unit="place-time-{{ $r['place'] }}" data-box="1090 {{ $r['y'] + 20 }} 1241 {{ $r['y'] + 46 }}" x="1240" y="{{ $r['y'] + 42 }}" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF" text-anchor="end">{{ $r['time'] }}</text>
<rect x="680" y="{{ $r['y'] + 72 }}" width="560" height="1" fill="#2A2A30"/>
@endforeach
{{-- The places nobody holds yet, open seats in their rows. --}}
@for ($i = count($rows); $i < 5; $i++)
@php($oy = 160 + $i * 88)
<text x="680" y="{{ $oy + 44 }}" font-family="Unbounded" font-weight="800" font-size="28" fill="#3A3A42">{{ $i + 1 }}</text>
@include('stream.rotation.partials.face', ['face' => null, 'x' => 728, 'y' => $oy + 4, 'd' => 56, 'id' => 'bf-open-'.$i, 'fOpen' => 'open', 'fOpenInk' => '#63636A'])
<text x="800" y="{{ $oy + 42 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#63636A">open</text>
<rect x="680" y="{{ $oy + 72 }}" width="560" height="1" fill="#2A2A30"/>
@endfor
@else
<text data-unit="title" data-box="38 150 1241 210" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">Every game</text>
<text data-unit="claim" data-box="39 240 1241 270" x="40" y="262" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Every game of the league is on the site.</text>
@endif
</svg>
