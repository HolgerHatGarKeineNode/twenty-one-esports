{{--
    TX6 · the champion moment (RotationPlanner::CHAMPION_SCENE, 2026-10-03): right after a tournament is decided this
    slide holds the stream alone for `champion_moment_seconds`. The same look as the tournament page's champion hero
    (pages/tournaments/partials/champion): the champion under a gold stage light, the avatar large in a gold ring
    with the trophy on it, "Champion · <tournament>", the name in display type, the prize when one is paid, and the
    podium of places 1 to 3 as steps in gold, silver and bronze. A shared place 1 (a lobby tournament) names everybody
    on it; without a place 1 that can be read the slide says "The results are in". Top right (x > 1040, y < 112)
    stays plain ground for the client's LIVE badge; the viewer count sits left of it.

    Data contract: $tournament as TournamentLiveSlides builds it (name, champion, sharedFirst, podium, podiumMore, pot,
    teamSize, url) and the backdrop ($backdrop). Every key may be missing. $stats unused.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $gold = '#FACC15';
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    $champ = K::entryFaces(is_array($t['champion'] ?? null) ? [$t['champion']] : [], 1, $clanSeats)[0] ?? null;
    $firsts = $champ ? [] : K::sharedFirst([...$t, 'phase' => 'finished']);
    $shared = $firsts !== [];
    $label = K::fit(($shared ? 'Shared 1st place · ' : 'Champion · ').K::text($t, 'name', 'Tournament'), K::MONO, 22, 640);
    $headline = $champ ? ($champ['name'] === '' ? 'Champion' : $champ['name']) : ($shared ? implode(', ', array_map(K::clean(...), $firsts)) : 'The results are in');
    $name = K::headline($headline, $champ ? [88, 76, 64, 52, 44] : [56, 48, 40, 34], 650, $champ ? 2 : 3);
    $nameStep = round($name['size'] * 1.08);
    $nameTop = 220 + $name['size'];
    $nameBottom = $nameTop + (count($name['lines']) - 1) * $nameStep;
    $pot = is_int($t['pot'] ?? null) && $t['pot'] > 0 ? K::sats($t['pot']) : '';
    $faces = [];
    foreach (K::entryFaces($t['podium'] ?? [], 6, $clanSeats) as $f) {
        if (is_int($f['place']) && $f['place'] >= 1 && $f['place'] <= 3 && ! isset($faces[$f['place']])) {
            $faces[$f['place']] = $f;
        }
    }
    // The steps, shown second, first, third: x of the step, its height and colour.
    $steps = [2 => [560, 64, '#ADADB0'], 1 => [780, 96, $gold], 3 => [1000, 44, '#E5A06B']];
    $avatar = $champ ? $champ['face'] : null;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.82])
{{-- The stage light: flat gold discs, cheaper to draw than a radial gradient. --}}
@foreach ([360, 320, 280, 245, 212, 182] as $r)
<circle cx="290" cy="330" r="{{ $r }}" fill="{{ $gold }}" fill-opacity="0.028"/>
@endforeach
<rect x="0" y="716" width="1280" height="4" fill="{{ $gold }}"/>

<use href="#mark" xlink:href="#mark" x="40" y="36" width="32" height="32"/>
<text data-unit="status" data-box="83 40 600 68" x="84" y="60" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $gold }}">Just decided</text>

@if ($champ)
@include('stream.rotation.partials.face', ['face' => $avatar, 'x' => 140, 'y' => 180, 'd' => 300, 'id' => 'tx6-champ', 'fUnit' => 'champ-face', 'fRing' => $gold])
<g data-unit="trophy"><circle cx="410" cy="452" r="42" fill="{{ $gold }}" stroke="#0A0A0B" stroke-width="6"/>
<path transform="translate(386 428) scale(2)" d="M6 2h12v3h3a1 1 0 0 1 1 1v2a5 5 0 0 1-5 5h-.4A6 6 0 0 1 13 16.9V19h3v3H8v-3h3v-2.1A6 6 0 0 1 7.4 13H7a5 5 0 0 1-5-5V6a1 1 0 0 1 1-1h3V2Zm12 5v4a3 3 0 0 0 2-2.8V7h-2ZM4 7v1.2A3 3 0 0 0 6 11V7H4Z" fill="#17120A"/></g>
@elseif ($shared)
@foreach (array_slice($faces[1] ?? null ? [$faces[1]] : [], 0, 1) as $f)
@include('stream.rotation.partials.face', ['face' => $f['face'], 'x' => 140, 'y' => 180, 'd' => 300, 'id' => 'tx6-first', 'fUnit' => 'first-face', 'fRing' => $gold])
@endforeach
@endif

<text data-unit="label" data-box="519 {{ 186 }} 1240 {{ 214 }}" x="520" y="208" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $gold }}">{{ $label }}</text>
@foreach ($name['lines'] as $i => $line)
<text data-unit="name-{{ $i }}" data-box="519 {{ $nameTop + $i * $nameStep - $name['size'] }} 1240 {{ $nameTop + $i * $nameStep + round($name['size'] * 0.25) }}" x="520" y="{{ $nameTop + $i * $nameStep }}" font-family="{{ $name['font'] }}" font-weight="800" font-size="{{ $name['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($pot !== '' && $champ)
<text data-unit="pot" data-box="519 {{ $nameBottom + 22 }} 1240 {{ $nameBottom + 58 }}" x="520" y="{{ $nameBottom + 52 }}" font-family="Unbounded" font-weight="800" font-size="32" fill="{{ $gold }}">{{ K::fit($pot.' sats in the pot', K::DISPLAY, 32, 700) }}</text>
@endif

{{-- The podium: three steps along the bottom right, the champion on the highest. --}}
@foreach ($steps as $place => [$sx, $sh, $ink])
@php($f = $faces[$place] ?? null)
@if ($f)
@php($stepTop = 700 - $sh)
@include('stream.rotation.partials.face', ['face' => $f['face'], 'x' => $sx + 74, 'y' => $stepTop - 112, 'd' => 56, 'id' => 'tx6-p'.$place, 'fUnit' => 'podium-face-'.$place, 'fRing' => $ink])
<text data-unit="podium-name-{{ $place }}" data-box="{{ $sx }} {{ $stepTop - 42 }} {{ $sx + 204 }} {{ $stepTop - 16 }}" x="{{ $sx + 102 }}" y="{{ $stepTop - 22 }}" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ K::fit($f['name'] === '' ? 'Player' : $f['name'], K::MONO, 18, 200) }}</text>
<rect x="{{ $sx }}" y="{{ $stepTop }}" width="204" height="{{ $sh }}" fill="{{ $ink }}"/>
<text data-unit="podium-place-{{ $place }}" x="{{ $sx + 102 }}" y="{{ $stepTop + 30 }}" text-anchor="middle" font-family="Unbounded" font-weight="800" font-size="24" fill="#17120A">{{ $place }}</text>
@endif
@endforeach
@if (is_int($t['podiumMore'] ?? null) && $t['podiumMore'] > 0)<text data-unit="podium-more" x="1228" y="560" text-anchor="end" font-family="Unbounded" font-weight="800" font-size="22" fill="{{ $gold }}">+{{ $t['podiumMore'] }} more</text>@endif

@php($vb = K::viewerBadge($viewers ?? null, 1024, 64, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#FFFFFF', 'countInk' => '#FFFFFF', 'wordInk' => '#ADADB0'])@endif
</svg>
