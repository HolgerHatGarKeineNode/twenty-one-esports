{{--
    TA3 · Arena · how a tournament runs (drawing, running). Dark left half over the game's blurred cover: status, the
    sharp cover beside the name, the format in one line, then "How it runs" and its stages as a numbered sequence (a
    real one: stage 1 is played before stage 2). Orange right half: the facts (matches, showing up, start, end), and a
    dark block at the bottom with what happens now: the round in play, or the Bitcoin block that draws the bracket and
    the time to the start. Top right (x > 1040, y < 112) stays plain orange for the client's LIVE badge.

    Data contract: $tournament as TournamentLiveSlides builds it (catalog-tournaments.md: name, status, phase, cover,
    game, mode, format, howItRuns {short, steps, matches, showUp, starts, ends}, now, drawBlock, countdown, url) and the
    backdrop ($backdrop). Every key may be missing (a sign-up frame renders too). $stats unused.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $how = is_array($t['howItRuns'] ?? null) ? $t['howItRuns'] : [];
    $cover = K::coverUri($t['cover'] ?? null);
    $title = K::headline(K::text($t, 'name', 'Tournament'), [30, 26, 22], 344, 2);
    $titleStep = round($title['size'] * 1.1);
    $titleY = 40 + round($title['size'] * 0.8);
    $short = K::wrap(K::text($how, 'short', K::text($t, 'format')), K::MONO, 18, 344, 2);
    $shortY = $titleY + (count($title['lines']) - 1) * $titleStep + 32;
    $game = K::wrap(K::text($t, 'game', 'Tournament'), K::MONO, 16, 164, 2);
    $steps = K::howSteps($how, 3);
    $stepRows = [];
    $sy = 316;
    foreach ($steps as $step) {
        $line = K::wrap($step['line'], K::MONO, 20, 488, 2);
        $stepRows[] = ['n' => $step['n'], 'title' => K::fit($step['title'], K::DISPLAY, 26, 488), 'lines' => $line, 'y' => $sy];
        $sy += 48 + count($line) * 28 + 30;
    }
    $facts = [];
    $fy = 150;
    foreach (K::howFacts($how) as [$label, $value]) {
        $lines = K::wrap($value, K::DISPLAY, 24, 552, 2);
        $facts[] = ['label' => $label, 'lines' => $lines, 'y' => $fy];
        $fy += 30 + count($lines) * 32 + 22;
    }
    $drawing = ($t['phase'] ?? null) === 'drawing';
    // A sign-up frame (TournamentSlides: no phase) shows where to sign up and the time to the close.
    $signup = ! isset($t['phase']);
    $block = is_int($t['drawBlock'] ?? null) ? $t['drawBlock'] : null;
    $nowLine = $drawing ? ($block !== null ? 'Bitcoin block '.number_format($block).' draws the bracket' : 'The draw is on') : K::text($t, 'now');
    $nowText = K::wrap($nowLine, K::DISPLAY, 26, 504, 2);
    $cd = $drawing || $signup ? K::countdownParts($t['countdown'] ?? null) : null;
    $cdLabel = $signup ? K::fit(K::text($t, 'countdownLabel', 'Sign-up closes in'), K::MONO, 18, 300) : 'Starts in';
    $url = K::tournamentUrl($t);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.7])
<rect x="640" y="0" width="640" height="720" fill="#F7931A"/>

<g data-unit="cover" data-box="40 40 238 150">
<rect x="46" y="46" width="192" height="104" fill="#F7931A"/>
<rect x="40" y="40" width="192" height="104" fill="#1A1A1D"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg): a second href would double the data URI. --}}
<image x="40" y="40" width="192" height="104" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>
@else
@foreach ($game as $i => $line)
<text x="136" y="{{ 98 - (count($game) - 1) * 10 + $i * 20 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0" text-anchor="middle">{{ $line }}</text>
@endforeach
@endif
</g>
@foreach ($title['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" data-box="255 {{ $titleY + $i * $titleStep - $title['size'] }} 601 {{ $titleY + $i * $titleStep + $title['size'] * 0.25 }}" x="256" y="{{ $titleY + $i * $titleStep }}" font-family="{{ $title['font'] }}" font-weight="800" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@foreach ($short as $i => $line)
<text data-unit="short-{{ $i }}" data-box="255 {{ $shortY + $i * 24 - 18 }} 601 {{ $shortY + $i * 24 + 5 }}" x="256" y="{{ $shortY + $i * 24 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $line }}</text>
@endforeach

<text data-unit="how" x="40" y="248" font-family="Unbounded" font-weight="800" font-size="44" fill="#FFFFFF">How it runs</text>
@foreach ($stepRows as $i => $st)
<text data-unit="step-n-{{ $i }}" x="40" y="{{ $st['y'] + 8 }}" font-family="Unbounded" font-weight="800" font-size="40" fill="#F7931A">{{ $st['n'] }}</text>
<text data-unit="step-title-{{ $i }}" data-box="95 {{ $st['y'] - 24 }} 601 {{ $st['y'] + 8 }}" x="96" y="{{ $st['y'] }}" font-family="Unbounded" font-weight="800" font-size="26" fill="#FFFFFF">{{ $st['title'] }}</text>
@foreach ($st['lines'] as $li => $line)
<text data-unit="step-line-{{ $i }}-{{ $li }}" data-box="95 {{ $st['y'] + 14 + $li * 28 }} 601 {{ $st['y'] + 40 + $li * 28 }}" x="96" y="{{ $st['y'] + 34 + $li * 28 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $line }}</text>
@endforeach
@endforeach
@if ($stepRows === [])<text data-unit="no-steps" x="40" y="300" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">The format is on the tournament page.</text>@endif

@foreach ($facts as $i => $f)
<text data-unit="fact-label-{{ $i }}" x="680" y="{{ $f['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#4A3A20">{{ $f['label'] }}</text>
@foreach ($f['lines'] as $li => $line)
<text data-unit="fact-{{ $i }}-{{ $li }}" data-box="679 {{ $f['y'] + 8 + $li * 32 }} 1233 {{ $f['y'] + 40 + $li * 32 }}" x="680" y="{{ $f['y'] + 34 + $li * 32 }}" font-family="Unbounded" font-weight="800" font-size="24" fill="#17120A">{{ $line }}</text>
@endforeach
@endforeach

<rect x="680" y="{{ $cd ? 520 : 556 }}" width="552" height="{{ $cd ? 160 : 124 }}" fill="#17120A"/>
@if ($nowText !== [])
<text data-unit="now-label" x="704" y="{{ $cd ? 552 : 588 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $drawing ? 'Draw pending' : 'Now' }}</text>
@foreach ($nowText as $i => $line)
<text data-unit="now-{{ $i }}" data-box="703 {{ ($cd ? 560 : 596) + $i * 32 }} 1209 {{ ($cd ? 592 : 628) + $i * 32 }}" x="704" y="{{ ($cd ? 586 : 622) + $i * 32 }}" font-family="Unbounded" font-weight="800" font-size="26" fill="#F7931A">{{ $line }}</text>
@endforeach
@else
<text data-unit="now-label" x="704" y="{{ $cd ? 552 : 588 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $signup ? 'Sign up at' : 'Everything about it' }}</text>
<text data-unit="url" data-box="703 {{ $cd ? 568 : 604 }} 1209 {{ $cd ? 596 : 632 }}" x="704" y="{{ $cd ? 590 : 626 }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ K::monoSize($url, 20, 504) }}" fill="#FFFFFF">{{ $url }}</text>
@endif
@if ($cd)
<g data-countdown="{{ $t['countdown'] }}">
<text data-unit="cd-label" x="704" y="660" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $cdLabel }}</text>
<text data-unit="cd" x="1208" y="662" font-family="JetBrains Mono" font-weight="700" font-size="28" fill="#FFFFFF" text-anchor="end">{{ ($cd['days'] > 0 ? $cd['days'].'d ' : '').$cd['hms'] }}</text>
</g>
@endif
@php($vb = K::viewerBadge($viewers ?? null, 1024, 64, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#17120A', 'countInk' => '#17120A', 'wordInk' => '#17120A'])@endif
</svg>
