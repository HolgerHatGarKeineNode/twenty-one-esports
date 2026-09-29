{{--
    TC5 · Terminal ticker · tournament pride (RotationKit::prideFaces: who is still standing, the top of a table, who
    goes through, or the podium once finished). Header line ("still standing" / "podium") and stats bar over the game's
    blurred cover. Left: the name, the headline for the field and the cut they made, then their square faces with
    names. Right of the rule: the biggest upset and the latest results as log lines.

    Data contract: $tournament as TournamentLiveSlides builds it (catalog-tournaments.md: name, status, phase, board,
    standing, podium, upset, results, teamSize) and the backdrop ($backdrop); $stats for the stats bar. Every key may
    be missing.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    $pride = K::prideFaces($t, 16);
    $big = count($pride['faces']) <= 8;
    $d = $big ? 88 : 60;
    $cols = $big ? 4 : 8;
    $cell = $big ? 180 : 90;
    $title = K::fit(K::text($t, 'name', 'Tournament'), K::MONO, 20, 720);
    $headline = K::fit($pride['headline'] === '' ? 'The field' : $pride['headline'], K::MONO, 34, 720);
    $sub = K::fit($pride['sub'], K::MONO, 18, 720);
    $upset = is_array($t['upset'] ?? null) ? $t['upset'] : null;
    $upsetFaces = $upset ? K::entryFaces([$upset['winner'] ?? null, $upset['loser'] ?? null], 2, $clanSeats) : [];
    $upsetLine = $upset ? K::upsetLine($upset) : '';
    $upsetResult = $upset ? K::resultLine($upset, K::MONO, 16, 400) : '';
    $results = [];
    foreach (array_slice(is_array($t['results'] ?? null) ? $t['results'] : [], 0, $upset ? 5 : 9) as $r) {
        $line = K::resultLine($r, K::MONO, 16, 380);
        if ($line !== '') {
            $results[] = $line;
        }
    }
    $resultsY = $upsetLine !== '' ? 300 : 130;
    $finished = ($t['phase'] ?? null) === 'finished';
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.78])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => $finished ? 'podium' : 'still standing'])

<text data-unit="title" data-box="39 {{ 108 - 20 }} 761 {{ 108 + 6 }}" x="40" y="108" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $title }}</text>
<text data-unit="headline" data-box="39 {{ 154 - 34 }} 761 {{ 154 + 9 }}" x="40" y="154" font-family="JetBrains Mono" font-weight="700" font-size="34" fill="#FFFFFF">{{ $headline }}</text>
@if ($sub !== '')<text data-unit="sub" data-box="39 168 761 192" x="40" y="186" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $sub }}</text>@endif
@foreach ($pride['faces'] as $i => $f)
@php($fx = 40 + ($i % $cols) * $cell)
@php($fy = 216 + intdiv($i, $cols) * ($big ? 160 : 80))
@include('stream.rotation.partials.face', ['face' => $f['face'], 'x' => $fx, 'y' => $fy, 'd' => $d, 'id' => 'tc5-'.$i, 'fUnit' => 'p-face-'.$i, 'fShape' => 'square', 'fRing' => '#F7931A',
    'fRank' => $f['place'] ?? null, 'fCrown' => ($f['place'] ?? null) === 1 ? '#F7931A' : null])
@if ($big)
<text data-unit="p-name-{{ $i }}" data-box="{{ $fx - 1 }} {{ $fy + $d + 6 }} {{ $fx + $cell - 15 }} {{ $fy + $d + 30 }}" x="{{ $fx }}" y="{{ $fy + $d + 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ K::fit($f['name'] === '' ? 'Player' : $f['name'], K::MONO, 18, $cell - 16) }}</text>
@if ($f['note'] !== '')<text data-unit="p-note-{{ $i }}" data-box="{{ $fx - 1 }} {{ $fy + $d + 32 }} {{ $fx + $cell - 15 }} {{ $fy + $d + 52 }}" x="{{ $fx }}" y="{{ $fy + $d + 48 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">{{ K::fit($f['note'], K::MONO, 14, $cell - 16) }}</text>@endif
@endif
@endforeach
@if ($pride['faces'] === [])<text data-unit="no-faces" x="40" y="250" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">The field takes shape with the first results.</text>@endif
@if ($pride['more'] > 0)<text data-unit="more" x="40" y="{{ $big ? 590 : 400 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">+{{ $pride['more'] }} more still in it</text>@endif

<rect x="800" y="88" width="1" height="520" fill="#2A2A30"/>
@if ($upsetLine !== '')
<text data-unit="upset-label" x="832" y="118" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1A7">biggest upset</text>
@if (isset($upsetFaces[0]))@include('stream.rotation.partials.face', ['face' => $upsetFaces[0]['face'], 'x' => 832, 'y' => 134, 'd' => 64, 'id' => 'tc5-uw', 'fUnit' => 'upset-winner', 'fShape' => 'square', 'fRing' => '#F7931A'])@endif
@if (isset($upsetFaces[1]))@include('stream.rotation.partials.face', ['face' => $upsetFaces[1]['face'], 'x' => 908, 'y' => 158, 'd' => 40, 'id' => 'tc5-ul', 'fUnit' => 'upset-loser', 'fShape' => 'square'])@endif
<text data-unit="upset" data-box="831 {{ 234 - 22 }} 1241 {{ 234 + 6 }}" x="832" y="234" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">{{ K::fit($upsetLine, K::MONO, 22, 408) }}</text>
@if ($upsetResult !== '')<text data-unit="upset-result" data-box="831 244 1241 268" x="832" y="262" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">{{ $upsetResult }}</text>@endif
@endif
<text data-unit="results-label" x="832" y="{{ $resultsY }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1A7">latest results</text>
@foreach ($results as $i => $line)
<text data-unit="result-mark-{{ $i }}" x="832" y="{{ $resultsY + 34 + $i * 32 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">&gt;</text>
<text data-unit="result-{{ $i }}" data-box="851 {{ $resultsY + 16 + $i * 32 }} 1241 {{ $resultsY + 40 + $i * 32 }}" x="852" y="{{ $resultsY + 34 + $i * 32 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($results === [])<text data-unit="no-results" x="832" y="{{ $resultsY + 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">First results land here.</text>@endif
</svg>
