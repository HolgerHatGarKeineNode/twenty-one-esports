{{--
    E1 · Broadcast desk · pride: the latest win. Channel frame over the brand backdrop; the winner's face big with a
    crown on the left, on the right the label, the winner's name, who they beat, blitz or daily and when, and the
    winner's casual Elo change as an orange chip. Without a decided game: an invitation to be the first winner.

    Data contract:
      $pride     array{win: array{winner: string, winnerAvatar: ?string, loser: string, loserAvatar: ?string,
                 mode: string, delta: ?int, ago: ?string}|null, …} (PrideSlides::all())
      $stats     array: the ticker counts (b-chrome)
      $backdrop  ?string, optional: the brand backdrop, as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $win = is_array($pride['win'] ?? null) ? $pride['win'] : null;
    if ($win) {
        $name = K::name($win['winner'] ?? '', 'Player', 64, 740);
        $beat = K::fit('beat '.K::clean($win['loser'] ?? ''), K::MONO, 30, 740);
        $when = K::fit(implode(' · ', array_filter([K::clean($win['mode'] ?? ''), K::clean($win['ago'] ?? '')])), K::MONO, 24, 740);
        $delta = is_int($win['delta'] ?? null) && $win['delta'] > 0 ? '+'.$win['delta'].' casual Elo' : null;
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.76])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => 'latest win'])
@if ($win)
<radialGradient id="win-glow"><stop offset="0.5" stop-color="#F7931A" stop-opacity="0.5"/><stop offset="1" stop-color="#F7931A" stop-opacity="0"/></radialGradient>
<circle cx="230" cy="380" r="230" fill="url(#win-glow)"/>
@include('stream.rotation.partials.face', ['face' => ['uri' => $win['winnerAvatar'] ?? null, 'tag' => null], 'x' => 80, 'y' => 230, 'd' => 300, 'id' => 'winner', 'fUnit' => 'winner-face', 'fRing' => '#F7931A', 'fCrown' => '#F7931A'])
<text x="460" y="220" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#F7931A">LATEST WIN · GG</text>
<text data-unit="winner-name" data-box="459 250 1240 318" x="460" y="304" font-family="{{ $name['font'] }}" font-weight="800" font-size="64" fill="#FFFFFF">{{ $name['text'] }}</text>
<text data-unit="winner-beat" data-box="459 336 1240 372" x="460" y="364" font-family="JetBrains Mono" font-weight="700" font-size="30" fill="#FFFFFF">{{ $beat }}</text>
@if ($when !== '')<text data-unit="winner-when" data-box="459 390 1240 420" x="460" y="414" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#ADADB0">{{ $when }}</text>@endif
@if ($delta)
<rect x="460" y="456" width="{{ K::width($delta, K::DISPLAY, 40) * 1.04 + 48 }}" height="76" fill="#F7931A"/>
<text data-unit="winner-delta" x="484" y="508" font-family="Unbounded" font-weight="800" font-size="40" fill="#17120A">{{ $delta }}</text>
@endif
<text x="460" y="600" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Your name here next? Play on esports.einundzwanzig.space</text>
@else
<text x="40" y="300" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">No winner yet.</text>
<text x="40" y="370" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#ADADB0">Win the first game and your name is on this screen.</text>
@endif
</svg>
