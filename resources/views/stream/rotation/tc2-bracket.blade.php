{{--
    TC2 · Terminal ticker · upcoming tournament, bracket preview. Header line and stats bar as every C scene. Left: the
    name, the projected groups (or first-round pairings) as terminal tables with seeds and "Open spot", the line that
    this is a preview. Right: "Who plays" as on the site (seeds with Elo, "Your spot?", open seats, "+N more open
    spots"). Above the stats bar an orange line: the call to sign up, the address and the countdown; a full
    tournament shows "Watch it live" and the start instead.

    Data contract: $tournament as in docs/plans/2026-09-27T1456-stream-tournament-slides.md (name, preview, roster,
    openSpots, taken, places, spotsLeft, countdown, countdownLabel, startsAt, url); $stats for the stats bar.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $countdown = is_string($t['countdown'] ?? null) ? $t['countdown'] : '';
    $spots = K::spots($t);
    $title = K::fit(K::text($t, 'name', 'Tournament'), K::MONO, 28, 720);
    $p = K::previewBoxes(is_array($t['preview'] ?? null) ? $t['preview'] : null, 40, 164, 720, 330, 2, 24, 28, 32, 26, 6);
    $sub = K::fit(K::previewHeading($p), K::MONO, 18, 720);
    $foot = K::fit(K::previewFoot($p), K::MONO, 16, 720);
    $rows = [];
    foreach (K::whoPlays($t, 9) as $i => $r) {
        $rows[] = $r + ['y' => 184 + $i * 32, 'label' => $r['kind'] === 'player' ? K::name($r['name'], 'Player', 18, $r['rating'] === null ? 328 : 248, false) : null];
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
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'bracket preview'])

<text data-unit="title" data-box="39 90 761 124" x="40" y="116" font-family="JetBrains Mono" font-weight="700" font-size="28" fill="#FFFFFF">{{ $title }}</text>
<text data-unit="sub" x="40" y="146" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#8B8B90">{{ $sub }}</text>
@if ($p['boxes'] === [])
<text data-unit="no-preview" x="40" y="200" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">The bracket takes shape as players sign up.</text>
@else
@include('stream.rotation.partials.t-preview', ['p' => $p, 'panel' => null, 'rule' => '#2A2A30', 'titleFill' => '#F7931A', 'seedFill' => '#8B8B90', 'nameFill' => '#FFFFFF', 'openFill' => '#8B8B90'])
@endif
@if ($foot !== '')<text data-unit="foot" data-box="39 494 761 516" x="40" y="510" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#8B8B90">{{ $foot }}</text>@endif
<text data-unit="preview-1" x="40" y="536" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">A preview, not the draw.</text>
<text data-unit="preview-2" x="282" y="536" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#8B8B90">Seeds are fixed when sign-up closes.</text>

<rect x="800" y="96" width="1" height="448" fill="#2A2A30"/>
<text data-unit="who" x="832" y="136" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF">Who plays</text>
<rect x="832" y="156" width="408" height="1" fill="#2A2A30"/>
@foreach ($rows as $i => $r)
@if ($r['seed'] !== null)<text data-unit="seed-{{ $i }}" x="864" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $r['kind'] === 'open' ? '#8B8B90' : '#F7931A' }}" text-anchor="end">{{ $r['seed'] }}</text>@endif
@if ($r['kind'] === 'player')
<text data-unit="player-{{ $i }}" data-box="875 {{ $r['y'] - 20 }} {{ $r['rating'] === null ? 1241 : 1161 }} {{ $r['y'] + 6 }}" x="876" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $r['label']['text'] }}</text>
@if ($r['rating'] !== null)<text data-unit="elo-{{ $i }}" x="1240" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0" text-anchor="end">{{ $r['rating'] }} Elo</text>@endif
@elseif ($r['kind'] === 'invite')
<text data-unit="invite" x="876" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">Your spot?</text>
@if ($r['more'] > 0)<text data-unit="invite-more" x="1240" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#ADADB0" text-anchor="end">{{ K::moreOpen($r['more']) }}</text>@endif
@elseif ($r['kind'] === 'open')
<text data-unit="open-{{ $i }}" x="876" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#8B8B90">Open spot</text>
@else
<text data-unit="more" x="876" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#8B8B90">{{ K::moreOpen($r['more']) }}</text>
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
