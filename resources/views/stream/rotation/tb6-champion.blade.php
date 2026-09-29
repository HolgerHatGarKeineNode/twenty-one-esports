{{--
    TB6 · Broadcast desk · a finished tournament's champion. Channel frame over the game's blurred cover. Left: the
    champion's face big with a crown. Right: "Champion", the name huge, which tournament they won, the pot (only with
    one), and a desk panel with their path to the title. Across the bottom, above the ticker, an orange lower third
    with the rest of the podium. Without a champion that can be read the slide says "The results are in" and shows the
    podium only; no winner is made up.

    Data contract: $tournament as TournamentLiveSlides builds it (catalog-tournaments.md: name, status, champion, podium,
    path, pot, teamSize) and the backdrop ($backdrop); $stats for the ticker. Every key may be missing.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    $champ = K::entryFaces(is_array($t['champion'] ?? null) ? [$t['champion']] : [], 1, $clanSeats)[0] ?? null;
    $name = K::headline($champ ? ($champ['name'] === '' ? 'Champion' : $champ['name']) : 'The results are in', [52, 44, 36, 30], 800, 2);
    $nameStep = round($name['size'] * 1.1);
    $of = K::fit(($champ ? 'Champion of ' : '').K::text($t, 'name', 'Tournament'), K::MONO, 20, 800);
    $pot = is_int($t['pot'] ?? null) && $t['pot'] > 0 ? K::sats($t['pot']).' sats in the pot' : '';
    $afterName = 200 + (count($name['lines']) - 1) * $nameStep;
    $path = [];
    foreach (array_slice(is_array($t['path'] ?? null) ? $t['path'] : [], -4) as $step) {
        if (is_array($step) && is_string($step['opponent'] ?? null)) {
            $verb = ($step['draw'] ?? false) ? 'drew with ' : (($step['won'] ?? false) ? 'beat ' : 'lost to ');
            $score = is_string($step['label'] ?? null) ? ' '.str_replace('–', '-', K::clean($step['label'])) : '';
            $path[] = ['round' => K::fit(is_string($step['round'] ?? null) ? $step['round'] : '', K::MONO, 16, 170), 'line' => K::fit($verb.K::clean($step['opponent']).$score, K::MONO, 18, 560)];
        }
    }
    $pathY = $afterName + ($pot !== '' ? 96 : 64);
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
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null)])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => K::fit(K::text($t, 'status', 'Finished'), K::MONO, 18, 400)])

@if ($champ)
@include('stream.rotation.partials.face', ['face' => $champ['face'], 'x' => 56, 'y' => 196, 'd' => 300, 'id' => 'tb6-champ', 'fUnit' => 'champ-face', 'fRing' => '#F7931A', 'fCrown' => '#F7931A'])
@endif
<text data-unit="label" x="400" y="132" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $champ ? 'Champion' : 'Finished' }}</text>
@foreach ($name['lines'] as $i => $line)
<text data-unit="name-{{ $i }}" data-box="399 {{ 200 + $i * $nameStep - $name['size'] }} 1241 {{ 200 + $i * $nameStep + $name['size'] * 0.25 }}" x="400" y="{{ 200 + $i * $nameStep }}" font-family="{{ $name['font'] }}" font-weight="800" font-size="{{ $name['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
<text data-unit="of" data-box="399 {{ $afterName + 20 }} 1241 {{ $afterName + 46 }}" x="400" y="{{ $afterName + 40 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $of }}</text>
@if ($pot !== '')<text data-unit="pot" data-box="399 {{ $afterName + 52 }} 1241 {{ $afterName + 80 }}" x="400" y="{{ $afterName + 74 }}" font-family="Unbounded" font-weight="800" font-size="22" fill="#F7931A">{{ K::fit($pot, K::DISPLAY, 22, 800) }}</text>@endif
@if ($path !== [])
<rect x="400" y="{{ $pathY }}" width="800" height="{{ 44 + count($path) * 34 }}" fill="#121215" fill-opacity="0.92"/>
<text data-unit="path-label" x="424" y="{{ $pathY + 30 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">Path to the title</text>
@foreach ($path as $i => $p)
<text data-unit="path-round-{{ $i }}" data-box="423 {{ $pathY + 44 + $i * 34 }} 600 {{ $pathY + 68 + $i * 34 }}" x="424" y="{{ $pathY + 64 + $i * 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#8B8B90">{{ $p['round'] }}</text>
<text data-unit="path-{{ $i }}" data-box="615 {{ $pathY + 44 + $i * 34 }} 1177 {{ $pathY + 68 + $i * 34 }}" x="616" y="{{ $pathY + 64 + $i * 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $p['line'] }}</text>
@endforeach
@endif
@if ($rest !== [])
<rect x="40" y="584" width="1200" height="64" fill="#F7931A"/>
<text data-unit="podium-label" x="64" y="624" font-family="Unbounded" font-weight="800" font-size="20" fill="#17120A">Podium</text>
@foreach ($rest as $i => $f)
@php($px = 216 + $i * 340)
@include('stream.rotation.partials.face', ['face' => $f['face'], 'x' => $px, 'y' => 596, 'd' => 40, 'id' => 'tb6-p'.$i, 'fUnit' => 'podium-face-'.$i, 'fRing' => '#17120A', 'fGround' => '#17120A', 'fGlyph' => '#6B4A1A'])
<text data-unit="podium-{{ $i }}" data-box="{{ $px + 51 }} 604 {{ $px + 321 }} 632" x="{{ $px + 52 }}" y="624" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">{{ K::fit(($f['place'] === null ? '' : K::ordinal($f['place']).' ').($f['name'] === '' ? 'Player' : $f['name']), K::MONO, 18, 268) }}</text>
@endforeach
@endif
</svg>
