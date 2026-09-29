{{--
    TC6 · Terminal ticker · a finished tournament's champion. Header line ("champion") and stats bar over the game's
    blurred cover. Left: the champion's square face big with a crown. Right: the name huge in mono, which tournament
    they won, the pot (only with one), their path to the title as log lines, and the rest of the podium on an orange
    line above the stats bar. Without a champion that can be read the slide says "The results are in" and shows the
    podium only; no winner is made up.

    Data contract: $tournament as TournamentLiveSlides builds it (catalog-tournaments.md: name, status, champion, podium,
    path, pot, teamSize) and the backdrop ($backdrop); $stats for the stats bar. Every key may be missing.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    $champ = K::entryFaces(is_array($t['champion'] ?? null) ? [$t['champion']] : [], 1, $clanSeats)[0] ?? null;
    $name = K::headline($champ ? ($champ['name'] === '' ? 'Champion' : $champ['name']) : 'The results are in', [44, 36, 30], 840, 2, K::MONO);
    $nameStep = round($name['size'] * 1.15);
    $afterName = 170 + (count($name['lines']) - 1) * $nameStep;
    $of = K::fit(($champ ? 'champion of ' : 'finished: ').K::text($t, 'name', 'Tournament'), K::MONO, 18, 840);
    $pot = is_int($t['pot'] ?? null) && $t['pot'] > 0 ? K::sats($t['pot']).' sats in the pot' : '';
    $path = [];
    foreach (array_slice(is_array($t['path'] ?? null) ? $t['path'] : [], -5) as $step) {
        if (is_array($step) && is_string($step['opponent'] ?? null)) {
            $verb = ($step['draw'] ?? false) ? 'drew with ' : (($step['won'] ?? false) ? 'beat ' : 'lost to ');
            $score = is_string($step['label'] ?? null) ? ' '.str_replace('–', '-', K::clean($step['label'])) : '';
            $path[] = K::fit(mb_strtolower(is_string($step['round'] ?? null) ? $step['round'] : '').': '.$verb.K::clean($step['opponent']).$score, K::MONO, 18, 820);
        }
    }
    $pathY = $afterName + ($pot !== '' ? 104 : 72);
    $rest = [];
    foreach (K::entryFaces($t['podium'] ?? [], 4, $clanSeats) as $f) {
        if (! $champ || $f['place'] !== 1) {
            $rest[] = $f;
        }
    }
    $rest = array_slice($rest, 0, 3);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.76])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => $champ ? 'champion' : 'finished'])

@if ($champ)
@include('stream.rotation.partials.face', ['face' => $champ['face'], 'x' => 40, 'y' => 176, 'd' => 300, 'id' => 'tc6-champ', 'fUnit' => 'champ-face', 'fShape' => 'square', 'fRing' => '#F7931A', 'fCrown' => '#F7931A'])
@endif
@foreach ($name['lines'] as $i => $line)
<text data-unit="name-{{ $i }}" data-box="{{ $champ ? 367 : 39 }} {{ 170 + $i * $nameStep - $name['size'] }} 1241 {{ 170 + $i * $nameStep + 10 }}" x="{{ $champ ? 368 : 40 }}" y="{{ 170 + $i * $nameStep }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $name['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
<text data-unit="of" data-box="{{ $champ ? 367 : 39 }} {{ $afterName + 22 }} 1241 {{ $afterName + 46 }}" x="{{ $champ ? 368 : 40 }}" y="{{ $afterName + 40 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $of }}</text>
@if ($pot !== '')<text data-unit="pot" data-box="{{ $champ ? 367 : 39 }} {{ $afterName + 54 }} 1241 {{ $afterName + 80 }}" x="{{ $champ ? 368 : 40 }}" y="{{ $afterName + 74 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ K::fit($pot, K::MONO, 22, 840) }}</text>@endif
@foreach ($path as $i => $line)
<text data-unit="path-mark-{{ $i }}" x="{{ $champ ? 368 : 40 }}" y="{{ $pathY + $i * 32 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">&gt;</text>
<text data-unit="path-{{ $i }}" data-box="{{ $champ ? 389 : 61 }} {{ $pathY + $i * 32 - 18 }} 1241 {{ $pathY + $i * 32 + 6 }}" x="{{ $champ ? 390 : 62 }}" y="{{ $pathY + $i * 32 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($rest !== [])
<rect x="40" y="560" width="1200" height="48" fill="#F7931A"/>
<text data-unit="podium-label" x="56" y="592" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A">podium</text>
@foreach ($rest as $i => $f)
@php($px = 168 + $i * 356)
@include('stream.rotation.partials.face', ['face' => $f['face'], 'x' => $px, 'y' => 568, 'd' => 32, 'id' => 'tc6-p'.$i, 'fUnit' => 'podium-face-'.$i, 'fShape' => 'square', 'fGround' => '#17120A', 'fGlyph' => '#6B4A1A'])
<text data-unit="podium-{{ $i }}" data-box="{{ $px + 41 }} 572 {{ $px + 341 }} 600" x="{{ $px + 42 }}" y="592" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A">{{ K::fit(($f['place'] === null ? '' : K::ordinal($f['place']).' ').($f['name'] === '' ? 'Player' : $f['name']), K::MONO, 20, 298) }}</text>
@endforeach
@endif
</svg>
