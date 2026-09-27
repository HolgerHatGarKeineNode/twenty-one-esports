{{--
    TB2 · Broadcast desk · upcoming tournament, bracket preview. Channel frame over the game's blurred cover. Left: the
    sharp cover beside the name and the stage note, the projected groups (or first-round pairings) four across as
    desk panels, every seat a face and a name, an open seat an empty one, and the line that this is a preview. Right:
    a "Who plays" panel as on the site (seed, face, name, Elo; "Your spot?", open seats as empty faces, "+N more open
    spots"). Across the bottom, above the ticker, an orange lower third: the call to sign up, the address and a small
    countdown in fixed digit cells; a full tournament shows "Watch it live" and the start instead.

    Data contract: $tournament as in docs/plans/2026-09-27T1456-stream-tournament-slides.md (name, status, cover, game,
    preview, roster, openSpots, taken, places, spotsLeft, countdown, countdownLabel, startsAt, url) plus 'avatar' per
    roster row and preview seat ('logo' for a clan) and the backdrop ($backdrop, else $tournament['backdrop']) per
    docs/plans/2026-09-27T1811-stream-avatars-imagery.md; $stats for the ticker.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $countdown = is_string($t['countdown'] ?? null) ? $t['countdown'] : '';
    $spots = K::spots($t);
    $cover = K::coverUri($t['cover'] ?? null);
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    $title = K::name(K::text($t, 'name'), 'Tournament', 28, 584);
    $pv = is_array($t['preview'] ?? null) ? $t['preview'] : null;
    $p = K::previewBoxes($pv, 40, 212, 776, 312, 4, 16, 30, 40, 26, 10);
    $faces = K::previewFaces($pv, $clanSeats);
    $sub = K::fit(K::previewHeading($p), K::MONO, 18, 584);
    $game = K::wrap(K::text($t, 'game', 'Tournament'), K::MONO, 14, 156, 2);
    $roster = K::rosterFaces($t);
    $foot = K::fit(K::previewFoot($p), K::MONO, 16, 776);
    $rows = [];
    foreach (K::whoPlays($t, 9) as $i => $r) {
        $rows[] = $r + ['y' => 200 + $i * 40, 'face' => $r['kind'] === 'player' ? ($roster[$i] ?? null) : null,
            'label' => $r['kind'] === 'player' ? K::name($r['name'], 'Player', 20, $r['rating'] === null ? 268 : 178, false) : null];
    }
    $cd = K::countdownParts($t['countdown'] ?? null);
    $cells = $cd ? K::digitCells($cd['hms'], 1216, 28, true) : null;
    $days = $cd && $cd['days'] > 0 ? $cd['days'].'d' : null;
    $starts = K::text($t, 'startsAt');
    $cta = $spots['full'] ? 'Watch it live' : 'Sign up';
    $ctaW = K::width($cta, K::DISPLAY, 30) * 1.04;
    $cdLabel = K::fit(K::text($t, 'countdownLabel', 'Sign-up closes in'), K::MONO, 16, 200);
    $startsText = $starts === '' ? '' : K::fit('Starts '.$starts, K::MONO, 20, 316);
    // Where the right-hand part of the lower third begins; the address gets the room up to 24 px before it.
    $rightX = match (true) {
        $spots['full'] => 1216 - K::width($startsText, K::MONO, 20),
        $cells !== null => $cells['x0'] - ($days ? K::width($days, K::DISPLAY, 28) * 1.04 + 26 : 16) - K::width($cdLabel, K::MONO, 16),
        default => 1216,
    };
    $url = K::tournamentUrl($t);
    $urlSize = K::monoSize($url, 20, $rightX - 24 - (64 + $ctaW + 24));
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null)])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => K::fit(K::text($t, 'status', 'Sign-up open'), K::MONO, 18, 400)])

<g data-unit="cover" data-box="40 96 206 190">
<rect x="45" y="101" width="160" height="90" fill="#F7931A"/>
<rect x="40" y="96" width="160" height="90" fill="#121215"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg): a second href would double the 43 kB data URI. --}}
<image x="40" y="96" width="160" height="90" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>
@else
@foreach ($game as $i => $line)
<text x="120" y="{{ 146 - (count($game) - 1) * 9 + $i * 18 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#ADADB0" text-anchor="middle">{{ $line }}</text>
@endforeach
@endif
</g>
<text data-unit="title" data-box="223 106 817 144" x="224" y="136" font-family="{{ $title['font'] }}" font-weight="800" font-size="28" fill="#FFFFFF">{{ $title['text'] }}</text>
<text data-unit="sub" x="224" y="170" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $sub }}</text>
@if ($p['boxes'] === [])
<text data-unit="no-preview" x="40" y="250" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">The bracket takes shape as players sign up.</text>
@else
@include('stream.rotation.partials.t-preview', ['p' => $p, 'faces' => $faces, 'panel' => '#121215', 'rule' => '#2A2A30', 'titleFill' => '#F7931A', 'nameFill' => '#FFFFFF', 'openFill' => '#8B8B90', 'ring' => '#F7931A', 'pvId' => 'g'])
@endif
@if ($foot !== '')<text data-unit="foot" data-box="39 526 817 548" x="40" y="542" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">{{ $foot }}</text>@endif
<text data-unit="preview-1" x="40" y="566" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">A preview, not the draw.</text>

<rect x="848" y="112" width="392" height="456" fill="#121215" fill-opacity="0.92"/>
<text data-unit="who" x="872" y="156" font-family="Unbounded" font-weight="800" font-size="28" fill="#FFFFFF">Who plays</text>
@foreach ($rows as $i => $r)
@if ($r['kind'] === 'invite')<rect x="865" y="{{ $r['y'] - 27 }}" width="358" height="38" fill="none" stroke="#F7931A" stroke-width="2"/>@endif
@if ($r['seed'] !== null)<text data-unit="seed-{{ $i }}" x="900" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $r['kind'] === 'open' ? '#8B8B90' : '#F7931A' }}" text-anchor="end">{{ $r['seed'] }}</text>@endif
@if ($r['kind'] !== 'more')
@include('stream.rotation.partials.face', ['face' => $r['face'], 'x' => 910, 'y' => $r['y'] - 23, 'd' => 30, 'id' => 'who-'.$i, 'fUnit' => 'who-face-'.$i,
    'fOpen' => $r['kind'] === 'player' ? null : ($r['kind'] === 'invite' ? 'invite' : 'open'), 'fOpenInk' => $r['kind'] === 'invite' ? '#F7931A' : '#6B6B72', 'fRing' => '#F7931A'])
@endif
@if ($r['kind'] === 'player')
<text data-unit="player-{{ $i }}" data-box="947 {{ $r['y'] - 22 }} {{ $r['rating'] === null ? 1217 : 1127 }} {{ $r['y'] + 6 }}" x="948" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ $r['label']['text'] }}</text>
@if ($r['rating'] !== null)<text data-unit="elo-{{ $i }}" x="1216" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0" text-anchor="end">{{ $r['rating'] }} Elo</text>@endif
@elseif ($r['kind'] === 'invite')
<text data-unit="invite" x="948" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $r['more'] > 0 ? 18 : 20 }}" fill="#F7931A">Your spot?</text>
@if ($r['more'] > 0)<text data-unit="invite-more" x="1214" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="12" fill="#ADADB0" text-anchor="end">{{ K::moreOpen($r['more']) }}</text>@endif
@elseif ($r['kind'] === 'more')
<text data-unit="more" x="948" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#8B8B90">{{ K::moreOpen($r['more']) }}</text>
@endif
@endforeach

<rect x="40" y="584" width="1200" height="64" fill="#F7931A"/>
<text data-unit="cta" x="64" y="628" font-family="Unbounded" font-weight="800" font-size="30" fill="#17120A">{{ $cta }}</text>
<text data-unit="url" data-box="{{ 64 + $ctaW + 23 }} 606 {{ $rightX - 23 }} 634" x="{{ 64 + $ctaW + 24 }}" y="626" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#17120A">{{ $url }}</text>
<g data-countdown="{{ $countdown }}">
@if ($spots['full'])
@if ($starts !== '')<text data-unit="starts" data-box="900 594 1217 640" x="1216" y="626" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A" text-anchor="end">{{ $startsText }}</text>@endif
@elseif ($cells)
<text data-unit="cd-label" x="{{ $rightX }}" y="626" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#17120A">{{ $cdLabel }}</text>
@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 628, 'fill' => '#17120A'])
@if ($days)<text data-unit="cd-days" x="{{ $cells['x0'] - 10 }}" y="628" font-family="Unbounded" font-weight="800" font-size="28" fill="#17120A" text-anchor="end">{{ $days }}</text>@endif
@endif
</g>
</svg>
