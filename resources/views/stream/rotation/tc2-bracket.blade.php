{{--
    TC2 · Terminal ticker · upcoming tournament, bracket preview. Header line and stats bar as every C scene, over the
    game's blurred cover. Top left the cover stage: the sharp cover, the name, the game line and the stage note. Below
    it the projected groups (or first-round pairings) four across, every seat a face and a name, an open seat an empty
    one. Right: "Who plays" as on the site (seed, face, name, Elo; "Your spot?", open seats as empty faces, "+N more
    open spots").
    Above the stats bar an orange line: the call to sign up, the address and the countdown; a full tournament shows
    "Watch it live" and the start instead.

    Data contract: $tournament as in docs/plans/2026-09-27T1456-stream-tournament-slides.md (name, game, mode, format,
    cover, preview, roster, openSpots, taken, places, spotsLeft, countdown, countdownLabel, startsAt, url) plus, per
    docs/plans/2026-09-27T1811-stream-avatars-imagery.md, 'avatar' on every roster row and preview seat ('logo' for a
    clan), and the backdrop ($backdrop, else $tournament['backdrop']). $stats for the stats bar.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $countdown = is_string($t['countdown'] ?? null) ? $t['countdown'] : '';
    $spots = K::spots($t);
    $cover = K::coverUri($t['cover'] ?? null);
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    $title = K::headline(K::text($t, 'name', 'Tournament'), [28, 24], 440, 2, K::MONO);
    $titleStep = round($title['size'] * 1.2);
    $metaY = 112 + (count($title['lines']) - 1) * $titleStep + 40;
    $meta = K::fit(K::tournamentMeta($t), K::MONO, 16, 440);
    $pv = is_array($t['preview'] ?? null) ? $t['preview'] : null;
    $p = K::previewBoxes($pv, 40, 252, 720, 222, 4, 16, 28, 44, 26, 6);
    $faces = K::previewFaces($pv, $clanSeats);
    $sub = K::fit(K::previewHeading($p), K::MONO, 16, 440);
    $foot = K::fit(K::previewFoot($p), K::MONO, 16, 720);
    $roster = K::rosterFaces($t);
    $rows = [];
    foreach (K::whoPlays($t, 9) as $i => $r) {
        $rows[] = $r + ['y' => 186 + $i * 40, 'face' => $r['kind'] === 'player' ? ($roster[$i] ?? null) : null,
            'label' => $r['kind'] === 'player' ? K::name($r['name'], 'Player', 18, $r['rating'] === null ? 324 : 244, false) : null];
    }
    $cd = K::countdownParts($countdown);
    $cdText = $cd ? ($cd['days'] > 0 ? $cd['days'].'d ' : '').$cd['hms'] : '';
    $cdLabel = K::fit(K::text($t, 'countdownLabel', 'Sign-up closes in'), K::MONO, 16, 200);
    $starts = K::text($t, 'startsAt');
    $startsText = $starts === '' ? '' : K::fit('Starts '.$starts, K::MONO, 20, 400);
    $cta = $spots['full'] ? 'Watch it live' : 'Sign up';
    $urlX = 56 + mb_strlen($cta) * 14.4 + 24;
    // Where the right-hand part of the bar begins; the address gets the room up to 24 px before it.
    $rightX = match (true) {
        $spots['full'] => 1224 - mb_strlen($startsText) * 12,
        $cdText !== '' => 1224 - mb_strlen($cdText) * 16.8 - 16 - mb_strlen($cdLabel) * 9.6,
        default => 1224,
    };
    $url = K::tournamentUrl($t);
    $urlSize = K::monoSize($url, 20, $rightX - 24 - $urlX);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.76])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'bracket preview'])

<g data-unit="cover" data-box="38 86 298 234">
<rect x="39" y="87" width="258" height="146" fill="#16161A" stroke="#F7931A" stroke-width="2"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg): a second href would double the 43 kB data URI. --}}
<image x="40" y="88" width="256" height="144" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>
@else
@foreach (K::wrap(K::text($t, 'game', 'Tournament'), K::MONO, 20, 224, 2) as $i => $line)
<text x="168" y="{{ 166 + $i * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#A1A1A7" text-anchor="middle">{{ $line }}</text>
@endforeach
@endif
</g>
@foreach ($title['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" data-box="319 {{ 112 + $i * $titleStep - $title['size'] }} 761 {{ 112 + $i * $titleStep + 8 }}" x="320" y="{{ 112 + $i * $titleStep }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($meta !== '')<text data-unit="meta" data-box="319 {{ $metaY - 16 }} 761 {{ $metaY + 5 }}" x="320" y="{{ $metaY }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">{{ $meta }}</text>@endif
<text data-unit="sub" data-box="319 {{ $metaY + 12 }} 761 {{ $metaY + 33 }}" x="320" y="{{ $metaY + 28 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">{{ $sub }}</text>
@if ($p['boxes'] === [])
<text data-unit="no-preview" x="40" y="290" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">The bracket takes shape as players sign up.</text>
@else
@include('stream.rotation.partials.t-preview', ['p' => $p, 'faces' => $faces, 'panel' => '#0A0A0B', 'rule' => '#2A2A30', 'titleFill' => '#F7931A', 'nameFill' => '#FFFFFF', 'openFill' => '#A1A1A7', 'ring' => '#F7931A', 'pvShape' => 'square', 'pvId' => 'g'])
@endif
@if ($foot !== '')<text data-unit="foot" data-box="39 494 761 516" x="40" y="510" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1A7">{{ $foot }}</text>@endif
<text data-unit="preview-1" x="40" y="538" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">A preview, not the draw.</text>

<rect x="800" y="88" width="1" height="456" fill="#2A2A30"/>
<text data-unit="who" x="832" y="136" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF">Who plays</text>
<rect x="832" y="152" width="408" height="1" fill="#2A2A30"/>
@foreach ($rows as $i => $r)
@if ($r['seed'] !== null)<text data-unit="seed-{{ $i }}" x="860" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $r['kind'] === 'open' ? '#A1A1A7' : '#F7931A' }}" text-anchor="end">{{ $r['seed'] }}</text>@endif
@if ($r['kind'] !== 'more')
@include('stream.rotation.partials.face', ['face' => $r['face'], 'x' => 872, 'y' => $r['y'] - 23, 'd' => 30, 'id' => 'who-'.$i, 'fUnit' => 'who-face-'.$i, 'fShape' => 'square',
    'fOpen' => $r['kind'] === 'player' ? null : ($r['kind'] === 'invite' ? 'invite' : 'open'), 'fOpenInk' => $r['kind'] === 'invite' ? '#F7931A' : '#6B6B72', 'fRing' => '#F7931A'])
@endif
@if ($r['kind'] === 'player')
<text data-unit="player-{{ $i }}" data-box="911 {{ $r['y'] - 20 }} {{ $r['rating'] === null ? 1241 : 1161 }} {{ $r['y'] + 6 }}" x="912" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $r['label']['text'] }}</text>
@if ($r['rating'] !== null)<text data-unit="elo-{{ $i }}" x="1240" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0" text-anchor="end">{{ $r['rating'] }} Elo</text>@endif
@elseif ($r['kind'] === 'invite')
<text data-unit="invite" x="912" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">Your spot?</text>
@if ($r['more'] > 0)<text data-unit="invite-more" x="1240" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#ADADB0" text-anchor="end">{{ K::moreOpen($r['more']) }}</text>@endif
@elseif ($r['kind'] === 'open')
{{-- An open seat is its dashed face and seed; no label. --}}
@else
<text data-unit="more" x="912" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1A7">{{ K::moreOpen($r['more']) }}</text>
@endif
@endforeach

<rect x="40" y="560" width="1200" height="48" fill="#F7931A"/>
<text data-unit="cta" x="56" y="593" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#17120A">{{ $cta }}</text>
<text data-unit="url" data-box="{{ $urlX - 1 }} 572 {{ $rightX - 23 }} 598" x="{{ $urlX }}" y="592" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#17120A">{{ $url }}</text>
<g data-countdown="{{ $countdown }}">
@if ($spots['full'])
@if ($startsText !== '')<text data-unit="starts" x="1224" y="592" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A" text-anchor="end">{{ $startsText }}</text>@endif
@elseif ($cdText !== '')
<text data-unit="cd-label" x="{{ $rightX }}" y="591" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#17120A">{{ $cdLabel }}</text>
<text data-unit="countdown" x="1224" y="594" font-family="JetBrains Mono" font-weight="700" font-size="28" fill="#17120A" text-anchor="end">{{ $cdText }}</text>
@endif
</g>
</svg>
