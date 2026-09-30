{{--
    D6 · Terminal ticker · spotlight: a series game on a slide of its own (GameSpotlight, Age of Empires II since
    813bf8f2). The C frame of d4: header line and stats bar. Left the game's cover art sharp and whole, edged in the
    game's colour, and under it who holds the top of its ladder (avatar crowned in the game's colour, name, ladder,
    Elo), else the open top spot. Right the game's name as the headline, its series lengths, a rule in the game's
    colour, then how it is played here as label and line (casual 1v1, the host's lobby, the cups' weekend slot, the
    ladders), and the call to play with the game's page. The league's orange stays on the action; the game's colour
    marks only the game.

    Data contract (SceneSource, GameSpotlight::data()):
      $spotlight  array{name: string, claim: string, cover: ?string, colour: string, colourDeep: string,
                    facts: list<array{label: string, line: string}>,
                    leader: array{name: string, elo: int, avatar: ?string, ladder: string}|null, url: string}|null
                  null without a series game: the slide invites to every game instead
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: the game's blurred cover, else the brand's
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $s = is_array($spotlight ?? null) ? $spotlight : null;
    $accent = K::colour($s['colour'] ?? null, '#F7931A');
    $cover = K::coverUri($s['cover'] ?? null);
    $title = K::headline(K::text($s ?? [], 'name', 'Every game'), [52, 44, 36], 592, 2);
    $titleStep = round($title['size'] * 1.1);
    $claimY = 150 + (count($title['lines']) - 1) * $titleStep + 42;
    $claim = K::fit(K::text($s ?? [], 'claim'), K::MONO, 20, 592);
    $ruleY = $claimY + 28;
    $facts = [];
    $y = $ruleY + 34;
    foreach (array_slice(is_array($s['facts'] ?? null) ? $s['facts'] : [], 0, 4) as $i => $fact) {
        $lines = K::wrap(K::text($fact, 'line'), K::MONO, 18, 440, 2);
        if ($lines === []) {
            continue;
        }
        $facts[] = ['i' => $i, 'y' => $y, 'label' => K::fit(K::text($fact, 'label'), K::MONO, 18, 140), 'lines' => $lines, 'rule' => $y + 16 + (count($lines) - 1) * 24];
        $y += 42 + (count($lines) - 1) * 24;
    }
    $leader = is_array($s['leader'] ?? null) ? $s['leader'] : null;
    $leaderName = $leader ? K::nameFont(K::text($leader, 'name')) : K::DISPLAY;
    $leaderLine = $leader ? K::fit('#1 on the '.K::text($leader, 'ladder').', '.(int) ($leader['elo'] ?? 0).' Elo', K::MONO, 18, 440) : '';
    $url = K::fit(K::text($s ?? [], 'url', 'esports.einundzwanzig.space'), K::MONO, 18, 592);
    // The call right under the facts, never into the stats bar (rule at y 625).
    $ctaY = min($y - 4, 510);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.82])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'spotlight'])

<g data-unit="cover" data-box="40 104 601 425">
<rect x="40" y="104" width="560" height="320" fill="{{ $accent }}"/>
<rect x="46" y="110" width="548" height="308" fill="#1C1C21"/>
@if ($cover)
<image x="46" y="110" width="548" height="308" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>
@endif
</g>
@if ($leader)
@include('stream.rotation.partials.face', ['face' => K::prideFace($leader), 'x' => 40, 'y' => 470, 'd' => 96, 'id' => 'spot', 'fUnit' => 'leader-face', 'fRing' => $accent, 'fCrown' => $accent])
<text data-unit="leader-name" data-box="155 482 601 526" x="156" y="518" font-family="{{ $leaderName }}" font-weight="800" font-size="32" fill="#FFFFFF">{{ K::fit(K::text($leader, 'name'), $leaderName, 32, 440) }}</text>
<text data-unit="leader-line" data-box="155 536 601 562" x="156" y="556" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $leaderLine }}</text>
@elseif ($s)
@include('stream.rotation.partials.face', ['face' => null, 'x' => 40, 'y' => 470, 'd' => 96, 'id' => 'spot', 'fUnit' => 'leader-face', 'fOpen' => 'open', 'fOpenInk' => $accent])
<text data-unit="leader-name" data-box="155 482 601 526" x="156" y="518" font-family="Unbounded" font-weight="800" font-size="32" fill="#FFFFFF">The top spot is open.</text>
<text data-unit="leader-line" data-box="155 536 601 562" x="156" y="556" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">Win a game and it is yours.</text>
@endif

@foreach ($title['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" data-box="647 {{ 150 + $i * $titleStep - $title['size'] }} 1241 {{ 150 + $i * $titleStep + $title['size'] * 0.3 }}" x="648" y="{{ 150 + $i * $titleStep }}" font-family="{{ $title['font'] }}" font-weight="800" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($claim !== '')
<text data-unit="claim" data-box="647 {{ $claimY - 18 }} 1241 {{ $claimY + 6 }}" x="648" y="{{ $claimY }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $claim }}</text>
@endif
<rect x="648" y="{{ $ruleY }}" width="592" height="4" fill="{{ $accent }}"/>
@foreach ($facts as $f)
<text data-unit="fact-label-{{ $f['i'] }}" data-box="647 {{ $f['y'] - 16 }} 790 {{ $f['y'] + 6 }}" x="648" y="{{ $f['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $f['label'] }}</text>
@foreach ($f['lines'] as $li => $line)
<text data-unit="fact-{{ $f['i'] }}-{{ $li }}" data-box="799 {{ $f['y'] + $li * 24 - 16 }} 1241 {{ $f['y'] + $li * 24 + 6 }}" x="800" y="{{ $f['y'] + $li * 24 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $line }}</text>
@endforeach
<rect x="648" y="{{ $f['rule'] }}" width="592" height="1" fill="#2A2A30"/>
@endforeach
@if ($s)
<rect x="648" y="{{ $ctaY }}" width="120" height="44" fill="#F7931A"/>
<text data-unit="cta" data-box="648 {{ $ctaY }} 768 {{ $ctaY + 44 }}" x="708" y="{{ $ctaY + 29 }}" font-family="Unbounded" font-weight="800" font-size="20" fill="#17120A" text-anchor="middle">Play</text>
<text data-unit="cta-url" data-box="647 {{ $ctaY + 62 }} 1241 {{ $ctaY + 86 }}" x="648" y="{{ $ctaY + 80 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $url }}</text>
@else
<text data-unit="claim" data-box="647 300 1241 330" x="648" y="322" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Every game of the league is on the site.</text>
@endif
</svg>
