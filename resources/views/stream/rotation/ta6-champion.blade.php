{{--
    TA6 · Arena · a finished tournament's champion. Dark left half over the game's blurred cover: "Champion", their face
    as the hero with a crown, the name huge under it. Orange right half: which tournament they won, the pot (only with
    one), their path to the title (round, whom they beat, the score from their side), and the rest of the podium.
    Without a champion that can be read (a table tie, heats) the slide says "The results are in" and shows the podium
    only; no winner is made up. Top right (x > 1040, y < 112) stays plain orange for the client's LIVE badge.

    Data contract: $tournament as TournamentLiveSlides builds it (catalog-tournaments.md: name, status, champion, podium,
    path, pot, teamSize, finishedAt) and the backdrop ($backdrop). Every key may be missing. $stats unused.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    $champ = K::entryFaces(is_array($t['champion'] ?? null) ? [$t['champion']] : [], 1, $clanSeats)[0] ?? null;
    $name = $champ ? K::headline($champ['name'] === '' ? 'Champion' : $champ['name'], [48, 40, 32, 26], 560, 2) : null;
    $nameStep = $name ? round($name['size'] * 1.1) : 0;
    $of = K::headline(K::text($t, 'name', 'Tournament'), [30, 26, 22], 552, 2);
    $ofStep = round($of['size'] * 1.12);
    $pot = is_int($t['pot'] ?? null) && $t['pot'] > 0 ? K::sats($t['pot']).' sats in the pot' : '';
    $y = 180 + (count($of['lines']) - 1) * $ofStep + ($pot !== '' ? 48 : 0);
    $path = [];
    foreach (array_slice(is_array($t['path'] ?? null) ? $t['path'] : [], -5) as $step) {
        if (is_array($step) && is_string($step['opponent'] ?? null)) {
            $verb = ($step['draw'] ?? false) ? 'drew with ' : (($step['won'] ?? false) ? 'beat ' : 'lost to ');
            $score = is_string($step['label'] ?? null) ? ' '.str_replace('–', '-', K::clean($step['label'])) : '';
            $round = K::fit(is_string($step['round'] ?? null) ? $step['round'] : '', K::MONO, 16, 150);
            $path[] = ['round' => $round, 'line' => K::fit($verb.K::clean($step['opponent']).$score, K::MONO, 18, 390)];
        }
    }
    $pathY = $y + 64;
    $rest = [];
    foreach (K::entryFaces($t['podium'] ?? [], 4, $clanSeats) as $f) {
        if (! $champ || $f['place'] !== 1) {
            $rest[] = $f;
        }
    }
    // More on the podium than three cards (a shared place 1): two cards and "+N more" in the third place.
    $podiumTotal = count($rest) + (is_int($t['podiumMore'] ?? null) ? max(0, $t['podiumMore']) : 0);
    $rest = array_slice($rest, 0, $podiumTotal > 3 ? 2 : 3);
    $podiumMore = $podiumTotal - count($rest);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.6])
<rect x="640" y="0" width="640" height="720" fill="#F7931A"/>

<use href="#mark" xlink:href="#mark" x="40" y="36" width="32" height="32"/>
<text data-unit="status" data-box="83 40 600 68" x="84" y="60" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ K::fit(K::text($t, 'status', 'Finished'), K::MONO, 20, 516) }}</text>
@if ($champ)
<text data-unit="label" x="40" y="128" font-family="Unbounded" font-weight="800" font-size="30" fill="#F7931A">Champion</text>
@include('stream.rotation.partials.face', ['face' => $champ['face'], 'x' => 40, 'y' => 232, 'd' => 260, 'id' => 'ta6-champ', 'fUnit' => 'champ-face', 'fRing' => '#F7931A', 'fCrown' => '#F7931A'])
@foreach ($name['lines'] as $i => $line)
<text data-unit="name-{{ $i }}" data-box="39 {{ 552 + $i * $nameStep - $name['size'] }} 601 {{ 552 + $i * $nameStep + $name['size'] * 0.25 }}" x="40" y="{{ 552 + $i * $nameStep }}" font-family="{{ $name['font'] }}" font-weight="800" font-size="{{ $name['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@else
<text data-unit="label" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="44" fill="#FFFFFF">The results are in</text>
<text data-unit="label-sub" x="40" y="244" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">Every place is on the tournament page.</text>
@endif

<text data-unit="of-label" x="680" y="146" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#4A3A20">{{ $champ ? 'Champion of' : 'Finished' }}</text>
@foreach ($of['lines'] as $i => $line)
<text data-unit="of-{{ $i }}" data-box="679 {{ 180 + $i * $ofStep - $of['size'] }} 1233 {{ 180 + $i * $ofStep + $of['size'] * 0.25 }}" x="680" y="{{ 180 + $i * $ofStep }}" font-family="{{ $of['font'] }}" font-weight="800" font-size="{{ $of['size'] }}" fill="#17120A">{{ $line }}</text>
@endforeach
@if ($pot !== '')<text data-unit="pot" data-box="679 {{ $y - 22 }} 1233 {{ $y + 6 }}" x="680" y="{{ $y }}" font-family="Unbounded" font-weight="800" font-size="22" fill="#17120A">{{ K::fit($pot, K::DISPLAY, 22, 552) }}</text>@endif
@if ($path !== [])
<text data-unit="path-label" x="680" y="{{ $pathY }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#4A3A20">Path to the title</text>
@foreach ($path as $i => $p)
<text data-unit="path-round-{{ $i }}" data-box="679 {{ $pathY + 16 + $i * 36 }} 830 {{ $pathY + 42 + $i * 36 }}" x="680" y="{{ $pathY + 36 + $i * 36 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#4A3A20">{{ $p['round'] }}</text>
<text data-unit="path-{{ $i }}" data-box="839 {{ $pathY + 16 + $i * 36 }} 1233 {{ $pathY + 42 + $i * 36 }}" x="840" y="{{ $pathY + 36 + $i * 36 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">{{ $p['line'] }}</text>
@endforeach
@endif
@if ($rest !== [])
<rect x="680" y="560" width="552" height="120" fill="#17120A"/>
@foreach ($rest as $i => $f)
@php($px = 700 + $i * 180)
@include('stream.rotation.partials.face', ['face' => $f['face'], 'x' => $px, 'y' => 576, 'd' => 56, 'id' => 'ta6-p'.$i, 'fUnit' => 'podium-face-'.$i, 'fRing' => '#F7931A'])
<text data-unit="podium-name-{{ $i }}" data-box="{{ $px - 1 }} 640 {{ $px + 168 }} 664" x="{{ $px }}" y="658" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">{{ K::fit($f['name'] === '' ? 'Player' : $f['name'], K::MONO, 16, 168) }}</text>
<text data-unit="podium-place-{{ $i }}" x="{{ $px + 68 }}" y="610" font-family="Unbounded" font-weight="800" font-size="22" fill="#F7931A">{{ $f['place'] === null ? '' : K::ordinal($f['place']) }}</text>
@endforeach
@if ($podiumMore > 0)<text data-unit="podium-more" x="1060" y="626" font-family="Unbounded" font-weight="800" font-size="24" fill="#F7931A">+{{ $podiumMore }} more</text>@endif
@endif
@php($vb = K::viewerBadge($viewers ?? null, 1024, 64, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#17120A', 'countInk' => '#17120A', 'wordInk' => '#17120A'])@endif
</svg>
