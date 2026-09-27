{{--
    TB2 · Broadcast desk · upcoming tournament, bracket preview. Channel frame. Left: the name, the projected groups (or
    first-round pairings) as desk panels with seeds and "Open spot", the stage note and the line that this is a
    preview. Right: a "Who plays" panel as on the site (seeds with Elo, "Your spot?", open seats, "+N more open
    spots"). Across the bottom, above the ticker, an orange lower third: the call to sign up, the address and a small
    countdown in fixed digit cells; a full tournament shows "Watch it live" and the start instead.

    Data contract: $tournament as in docs/plans/2026-09-27T1456-stream-tournament-slides.md (name, status, preview,
    roster, openSpots, taken, places, spotsLeft, countdown, countdownLabel, startsAt, url); $stats for the ticker.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $countdown = is_string($t['countdown'] ?? null) ? $t['countdown'] : '';
    $spots = K::spots($t);
    $title = K::name(K::text($t, 'name'), 'Tournament', 28, 776);
    $p = K::previewBoxes(is_array($t['preview'] ?? null) ? $t['preview'] : null, 40, 168, 776, 348, 2, 16, 30, 34, 26, 10);
    $sub = K::fit(K::previewHeading($p), K::MONO, 18, 776);
    $foot = K::fit(K::previewFoot($p), K::MONO, 16, 776);
    $rows = [];
    foreach (K::whoPlays($t, 9) as $i => $r) {
        $rows[] = $r + ['y' => 200 + $i * 40, 'label' => $r['kind'] === 'player' ? K::name($r['name'], 'Player', 20, $r['rating'] === null ? 304 : 214, false) : null];
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
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => K::fit(K::text($t, 'status', 'Sign-up open'), K::MONO, 18, 400)])

<text data-unit="title" data-box="39 96 817 134" x="40" y="126" font-family="{{ $title['font'] }}" font-weight="800" font-size="28" fill="#FFFFFF">{{ $title['text'] }}</text>
<text data-unit="sub" x="40" y="154" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $sub }}</text>
@if ($p['boxes'] === [])
<text data-unit="no-preview" x="40" y="220" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">The bracket takes shape as players sign up.</text>
@else
@include('stream.rotation.partials.t-preview', ['p' => $p, 'panel' => '#121215', 'rule' => '#2A2A30', 'titleFill' => '#F7931A', 'seedFill' => '#8B8B90', 'nameFill' => '#FFFFFF', 'openFill' => '#8B8B90'])
@endif
@if ($foot !== '')<text data-unit="foot" data-box="39 526 817 548" x="40" y="542" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">{{ $foot }}</text>@endif
<text data-unit="preview-1" x="40" y="566" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">A preview, not the draw.</text>
<text data-unit="preview-2" x="282" y="566" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">Seeds are fixed when sign-up closes.</text>

<rect x="848" y="112" width="392" height="456" fill="#121215"/>
<text data-unit="who" x="872" y="156" font-family="Unbounded" font-weight="800" font-size="28" fill="#FFFFFF">Who plays</text>
@foreach ($rows as $i => $r)
@if ($r['kind'] === 'invite')<rect x="865" y="{{ $r['y'] - 27 }}" width="358" height="38" fill="none" stroke="#F7931A" stroke-width="2"/>@endif
@if ($r['seed'] !== null)<text data-unit="seed-{{ $i }}" x="900" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $r['kind'] === 'open' ? '#8B8B90' : '#F7931A' }}" text-anchor="end">{{ $r['seed'] }}</text>@endif
@if ($r['kind'] === 'player')
<text data-unit="player-{{ $i }}" data-box="911 {{ $r['y'] - 22 }} {{ $r['rating'] === null ? 1217 : 1127 }} {{ $r['y'] + 6 }}" x="912" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ $r['label']['text'] }}</text>
@if ($r['rating'] !== null)<text data-unit="elo-{{ $i }}" x="1216" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0" text-anchor="end">{{ $r['rating'] }} Elo</text>@endif
@elseif ($r['kind'] === 'invite')
<text data-unit="invite" x="912" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">Your spot?</text>
@if ($r['more'] > 0)<text data-unit="invite-more" x="1208" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#ADADB0" text-anchor="end">{{ K::moreOpen($r['more']) }}</text>@endif
@elseif ($r['kind'] === 'open')
<text data-unit="open-{{ $i }}" x="912" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#8B8B90">Open spot</text>
@else
<text data-unit="more" x="912" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#8B8B90">{{ K::moreOpen($r['more']) }}</text>
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
