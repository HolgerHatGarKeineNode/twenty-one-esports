{{--
    TC3 · Terminal ticker · how a tournament runs (drawing, running). Header line ("how it runs") and stats bar over the
    game's blurred cover. Top left the cover stage: sharp cover, the name, the format in one line. Below it the stages
    as a numbered sequence. Right of the rule: the facts as key and value lines. Above the stats bar an orange line with
    what happens now: the round in play, or the Bitcoin block that draws the bracket and the time to the start.

    Data contract: $tournament as TournamentLiveSlides builds it (catalog-tournaments.md: name, status, phase, cover,
    game, howItRuns, now, drawBlock, countdown, url) and the backdrop ($backdrop); $stats for the stats bar. Every key
    may be missing (a sign-up frame renders too).
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $how = is_array($t['howItRuns'] ?? null) ? $t['howItRuns'] : [];
    $cover = K::coverUri($t['cover'] ?? null);
    $title = K::headline(K::text($t, 'name', 'Tournament'), [26, 22], 416, 2, K::MONO);
    $titleStep = round($title['size'] * 1.2);
    $short = K::wrap(K::text($how, 'short', K::text($t, 'format')), K::MONO, 16, 416, 2);
    $shortY = 112 + (count($title['lines']) - 1) * $titleStep + 34;
    $stepRows = [];
    $sy = 290;
    foreach (K::howSteps($how, 3) as $step) {
        $line = K::wrap($step['line'], K::MONO, 18, 640, 2);
        $stepRows[] = ['n' => $step['n'], 'title' => K::fit($step['title'], K::MONO, 22, 640), 'lines' => $line, 'y' => $sy];
        $sy += 34 + count($line) * 26 + 18;
    }
    $facts = [];
    $fy = 136;
    foreach (K::howFacts($how) as [$label, $value]) {
        $lines = K::wrap($value, K::MONO, 18, 408, 2);
        $facts[] = ['label' => $label, 'lines' => $lines, 'y' => $fy];
        $fy += 26 + count($lines) * 26 + 22;
    }
    $drawing = ($t['phase'] ?? null) === 'drawing';
    // A sign-up frame (TournamentSlides: no phase) shows where to sign up and the time to the close.
    $signup = ! isset($t['phase']);
    $block = is_int($t['drawBlock'] ?? null) ? $t['drawBlock'] : null;
    $nowLine = $drawing ? ($block !== null ? 'Bitcoin block '.number_format($block).' draws the bracket' : 'The draw is on') : K::text($t, 'now');
    $cd = $drawing || $signup ? K::countdownParts($t['countdown'] ?? null) : null;
    $cdWord = $signup ? 'Closes in ' : 'Starts in ';
    $cdText = $cd ? ($cd['days'] > 0 ? $cd['days'].'d ' : '').$cd['hms'] : '';
    $nowText = K::fit(($drawing ? '' : ($signup ? 'Sign up: ' : 'Now: ')).($nowLine === '' ? K::tournamentUrl($t) : $nowLine), K::MONO, 22, 1168 - ($cdText === '' ? 0 : K::width($cdWord.$cdText, K::MONO, 22) + 24));
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.78])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'how it runs'])

<g data-unit="cover" data-box="38 86 298 234">
<rect x="39" y="87" width="258" height="146" fill="#16161A" stroke="#F7931A" stroke-width="2"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg). --}}
<image x="40" y="88" width="256" height="144" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>
@endif
</g>
@foreach ($title['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" data-box="319 {{ 112 + $i * $titleStep - $title['size'] }} 745 {{ 112 + $i * $titleStep + 8 }}" x="320" y="{{ 112 + $i * $titleStep }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@foreach ($short as $i => $line)
<text data-unit="short-{{ $i }}" data-box="319 {{ $shortY + $i * 22 - 16 }} 745 {{ $shortY + $i * 22 + 5 }}" x="320" y="{{ $shortY + $i * 22 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">{{ $line }}</text>
@endforeach
@foreach ($stepRows as $i => $st)
<text data-unit="step-n-{{ $i }}" x="40" y="{{ $st['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">{{ $st['n'] }}.</text>
<text data-unit="step-title-{{ $i }}" data-box="79 {{ $st['y'] - 20 }} 745 {{ $st['y'] + 6 }}" x="80" y="{{ $st['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $st['title'] }}</text>
@foreach ($st['lines'] as $li => $line)
<text data-unit="step-line-{{ $i }}-{{ $li }}" data-box="79 {{ $st['y'] + 10 + $li * 26 }} 745 {{ $st['y'] + 34 + $li * 26 }}" x="80" y="{{ $st['y'] + 28 + $li * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">{{ $line }}</text>
@endforeach
@endforeach
@if ($stepRows === [])<text data-unit="no-steps" x="40" y="300" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">The format is on the tournament page.</text>@endif

<rect x="800" y="88" width="1" height="456" fill="#2A2A30"/>
@foreach ($facts as $i => $f)
<text data-unit="fact-label-{{ $i }}" x="832" y="{{ $f['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1A7">{{ mb_strtolower($f['label']) }}</text>
@foreach ($f['lines'] as $li => $line)
<text data-unit="fact-{{ $i }}-{{ $li }}" data-box="831 {{ $f['y'] + 8 + $li * 26 }} 1241 {{ $f['y'] + 32 + $li * 26 }}" x="832" y="{{ $f['y'] + 28 + $li * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@endforeach

<rect x="40" y="560" width="1200" height="48" fill="#F7931A"/>
<text data-unit="now" data-box="55 568 {{ 1225 - ($cdText === '' ? 0 : K::width($cdWord.$cdText, K::MONO, 22) + 23) }} 600" x="56" y="592" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">{{ $nowText }}</text>
@if ($cdText !== '')<g data-countdown="{{ $t['countdown'] }}"><text data-unit="cd" x="1224" y="592" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A" text-anchor="end">{{ $cdWord.$cdText }}</text></g>@endif
</svg>
