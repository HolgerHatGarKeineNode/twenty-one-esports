{{--
    TB3 · Broadcast desk · how a tournament runs (drawing, running). Channel frame over the game's blurred cover. Left
    desk panel: the sharp cover beside the name and the format in one line, then "How it runs" and the stages as a
    numbered sequence. Right desk panel: the facts (matches, showing up, start, end) as label and value rows. Across the
    bottom, above the ticker, an orange lower third with what happens now: the round in play, or the Bitcoin block that
    draws the bracket and the time to the start.

    Data contract: $tournament as TournamentLiveSlides builds it (catalog-tournaments.md: name, status, phase, cover,
    game, howItRuns, now, drawBlock, countdown, url) and the backdrop ($backdrop); $stats for the ticker. Every key
    may be missing (a sign-up frame renders too).
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $how = is_array($t['howItRuns'] ?? null) ? $t['howItRuns'] : [];
    $cover = K::coverUri($t['cover'] ?? null);
    $title = K::headline(K::text($t, 'name', 'Tournament'), [26, 22], 380, 2);
    $titleStep = round($title['size'] * 1.12);
    $short = K::fit(K::text($how, 'short', K::text($t, 'format')), K::MONO, 16, 380);
    $shortY = 124 + (count($title['lines']) - 1) * $titleStep + 28;
    $game = K::wrap(K::text($t, 'game', 'Tournament'), K::MONO, 14, 136, 2);
    $stepRows = [];
    $sy = 290;
    foreach (K::howSteps($how, 3) as $step) {
        $line = K::wrap($step['line'], K::MONO, 18, 480, 2);
        $stepRows[] = ['n' => $step['n'], 'title' => K::fit($step['title'], K::DISPLAY, 22, 480), 'lines' => $line, 'y' => $sy];
        $sy += 34 + count($line) * 24 + 14;
    }
    $facts = [];
    $fy = 164;
    foreach (K::howFacts($how) as [$label, $value]) {
        $lines = K::wrap($value, K::MONO, 20, 504, 2);
        $facts[] = ['label' => $label, 'lines' => $lines, 'y' => $fy];
        $fy += 28 + count($lines) * 28 + 24;
    }
    $drawing = ($t['phase'] ?? null) === 'drawing';
    $block = is_int($t['drawBlock'] ?? null) ? $t['drawBlock'] : null;
    $nowLine = $drawing ? ($block !== null ? 'Bitcoin block '.number_format($block).' draws the bracket' : 'The draw is on') : K::text($t, 'now');
    $cd = $drawing ? K::countdownParts($t['countdown'] ?? null) : null;
    $cdText = $cd ? 'Starts in '.($cd['days'] > 0 ? $cd['days'].'d ' : '').$cd['hms'] : '';
    $nowText = K::fit($nowLine === '' ? K::tournamentUrl($t) : $nowLine, K::DISPLAY, 26, 1152 - ($cdText === '' ? 0 : K::width($cdText, K::MONO, 20) + 32));
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null)])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => K::fit(K::text($t, 'status', 'Live now'), K::MONO, 18, 400)])

<rect x="40" y="96" width="576" height="468" fill="#121215" fill-opacity="0.92"/>
<g data-unit="cover" data-box="56 112 212 202">
<rect x="61" y="117" width="144" height="81" fill="#F7931A"/>
<rect x="56" y="112" width="144" height="81" fill="#1A1A1D"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg). --}}
<image x="56" y="112" width="144" height="81" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>
@else
@foreach ($game as $i => $line)
<text x="128" y="{{ 156 - (count($game) - 1) * 9 + $i * 18 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#ADADB0" text-anchor="middle">{{ $line }}</text>
@endforeach
@endif
</g>
@foreach ($title['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" data-box="219 {{ 124 + $i * $titleStep - $title['size'] }} 601 {{ 124 + $i * $titleStep + $title['size'] * 0.25 }}" x="220" y="{{ 124 + $i * $titleStep }}" font-family="{{ $title['font'] }}" font-weight="800" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
<text data-unit="short" data-box="219 {{ $shortY - 16 }} 601 {{ $shortY + 5 }}" x="220" y="{{ $shortY }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">{{ $short }}</text>
<text data-unit="how" x="56" y="254" font-family="Unbounded" font-weight="800" font-size="32" fill="#FFFFFF">How it runs</text>
@foreach ($stepRows as $i => $st)
<rect x="56" y="{{ $st['y'] - 26 }}" width="36" height="36" fill="#F7931A"/>
<text data-unit="step-n-{{ $i }}" x="74" y="{{ $st['y'] }}" font-family="Unbounded" font-weight="800" font-size="22" fill="#17120A" text-anchor="middle">{{ $st['n'] }}</text>
<text data-unit="step-title-{{ $i }}" data-box="107 {{ $st['y'] - 22 }} 601 {{ $st['y'] + 6 }}" x="108" y="{{ $st['y'] }}" font-family="Unbounded" font-weight="800" font-size="22" fill="#FFFFFF">{{ $st['title'] }}</text>
@foreach ($st['lines'] as $li => $line)
<text data-unit="step-line-{{ $i }}-{{ $li }}" data-box="107 {{ $st['y'] + 10 + $li * 24 }} 601 {{ $st['y'] + 34 + $li * 24 }}" x="108" y="{{ $st['y'] + 28 + $li * 24 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $line }}</text>
@endforeach
@endforeach
@if ($stepRows === [])<text data-unit="no-steps" x="56" y="300" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">The format is on the tournament page.</text>@endif

<rect x="664" y="96" width="576" height="468" fill="#121215" fill-opacity="0.92"/>
<text data-unit="facts-title" x="688" y="136" font-family="Unbounded" font-weight="800" font-size="24" fill="#FFFFFF">What to know</text>
@foreach ($facts as $i => $f)
<text data-unit="fact-label-{{ $i }}" x="688" y="{{ $f['y'] + 18 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">{{ $f['label'] }}</text>
@foreach ($f['lines'] as $li => $line)
<text data-unit="fact-{{ $i }}-{{ $li }}" data-box="687 {{ $f['y'] + 26 + $li * 28 }} 1217 {{ $f['y'] + 52 + $li * 28 }}" x="688" y="{{ $f['y'] + 46 + $li * 28 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@endforeach

<rect x="40" y="584" width="1200" height="64" fill="#F7931A"/>
<text data-unit="now-label" x="64" y="606" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#17120A">{{ $drawing ? 'Draw pending' : ($nowLine === '' ? 'Everything about it' : 'Now') }}</text>
<text data-unit="now" data-box="63 606 {{ 1217 - ($cdText === '' ? 0 : K::width($cdText, K::MONO, 20) + 31) }} 642" x="64" y="636" font-family="Unbounded" font-weight="800" font-size="26" fill="#17120A">{{ $nowText }}</text>
@if ($cdText !== '')<g data-countdown="{{ $t['countdown'] }}"><text data-unit="cd" x="1216" y="626" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A" text-anchor="end">{{ $cdText }}</text></g>@endif
</svg>
