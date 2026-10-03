{{--
    TV4 · the prize pool, as the tournament TV shows its "Prize pool" scene (pages::tournaments.tv), in the TV frame
    (partials/tv-frame): left "In the pot" over the sum in large orange figures and "sats"; right the places still
    open with what each wins, then how many can still win it and who (at most eight, avatar and name, two to a row).

    Data contract: $tournament as TournamentLiveSlides builds it (running: tv.prize, standing, teamSize, results) and
    $viewers. Without a pot (or with no place open) the rotation leaves this slide out; drawn anyway it says so.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@use('App\Support\TwentyOne\Stream\TvSlides', 'Tv')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $tvData = is_array($t['tv'] ?? null) ? $t['tv'] : [];
    $prize = is_array($tvData['prize'] ?? null) ? $tvData['prize'] : null;
    $teams = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    $standing = is_array($t['standing'] ?? null) ? $t['standing'] : [];
    $faces = array_values(array_filter(array_slice((array) ($standing['faces'] ?? []), 0, 8), is_array(...)));
    $count = is_int($standing['count'] ?? null) ? $standing['count'] : count($faces);
    // The TV's grid: 5 parts the sum, 6 parts the places, 4u between.
    $leftW = round((1204 - 51) * 5 / 11);
    $rx = 38 + $leftW + 51;
    $rw = 1242 - $rx;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
@include('stream.rotation.partials.tv-frame', ['t' => $t, 'part' => 4])

@if ($prize !== null)
@php
    $sum = K::sats((int) ($prize['sats'] ?? 0));
    $sumSize = 90;
    while ($sumSize > 40 && K::width($sum, K::DISPLAY, $sumSize) * 1.04 > $leftW) {
        $sumSize -= 2;
    }
    $mid = (Tv::Y0 + Tv::Y1) / 2;
    $places = array_values(array_filter((array) ($prize['places'] ?? []), is_array(...)));
    $places = array_slice($places, 0, 5);
    $placeH = 51;
    $faceRows = (int) ceil(count($faces) / 2);
    $rightH = count($places) * ($placeH + 6) - 6 + ($faces !== [] ? 19 + 26 + 13 + $faceRows * 51 - 13 : 0);
    $ry0 = round($mid - $rightH / 2);
@endphp
<text data-unit="pot-lead" x="38" y="{{ round($mid - $sumSize * 0.55 - 14) }}" font-family="JetBrains Mono" font-size="19" fill="{{ Tv::INK2 }}">In the pot</text>
<text data-unit="pot-sats" x="38" y="{{ round($mid + $sumSize * 0.36) }}" font-family="Unbounded" font-weight="800" font-size="{{ $sumSize }}" fill="{{ Tv::BTC }}">{{ $sum }}</text>
<text data-unit="pot-unit" x="38" y="{{ round($mid + $sumSize * 0.36 + 44) }}" font-family="Unbounded" font-weight="700" font-size="31" fill="{{ Tv::INK }}">sats</text>
@foreach ($places as $pi => $share)
@php $py = $ry0 + $pi * ($placeH + 6); @endphp
<rect x="{{ $rx }}" y="{{ $py }}" width="{{ $rw }}" height="{{ $placeH }}" rx="5" fill="{{ Tv::PANEL }}"/>
<text data-unit="place-{{ $pi }}" x="{{ $rx + 13 }}" y="{{ $py + 33 }}" font-family="JetBrains Mono" font-size="22" fill="{{ Tv::INK }}">{{ K::ordinal((int) ($share['place'] ?? 0)) }} place</text>
<text data-unit="place-sats-{{ $pi }}" x="{{ $rx + $rw - 13 }}" y="{{ $py + 34 }}" font-family="Unbounded" font-weight="700" font-size="22" fill="{{ Tv::INK }}" text-anchor="end">{{ K::sats((int) ($share['sats'] ?? 0)) }} sats</text>
@endforeach
@if ($faces !== [])
@php $ky = $ry0 + count($places) * ($placeH + 6) - 6 + 19 + 19; @endphp
<text data-unit="contenders" x="{{ $rx }}" y="{{ $ky }}" font-family="JetBrains Mono" font-size="19" fill="{{ Tv::INK2 }}">{{ $count }} {{ $count === 1 ? ($teams ? 'team' : 'player') : ($teams ? 'teams' : 'players') }} can still win it</text>
@foreach ($faces as $fi => $entry)
@php
    $fx = $rx + ($fi % 2) * ($rw / 2 + 10);
    $fy = $ky + 19 + intdiv($fi, 2) * 51;
    $nm = K::name($entry['name'] ?? '', 'Player', 19, $rw / 2 - 38 - 10 - 20);
@endphp
@include('stream.rotation.partials.face', ['face' => K::face($entry, $teams), 'x' => round($fx, 1), 'y' => round($fy, 1), 'd' => 38, 'id' => 'tv4-'.$fi, 'fShape' => 'square', 'fGround' => Tv::RAISED, 'fGlyph' => '#4A4A52', 'fUnit' => 'pot-face-'.$fi])
<text data-unit="pot-name-{{ $fi }}" x="{{ round($fx + 48, 1) }}" y="{{ round($fy + 26, 1) }}" font-family="{{ $nm['font'] }}" font-weight="500" font-size="19" fill="{{ Tv::INK }}">{{ $nm['text'] }}</text>
@endforeach
@endif
@else
<text data-unit="empty" x="640" y="410" font-family="Unbounded" font-weight="700" font-size="31" fill="{{ Tv::INK }}" text-anchor="middle">This tournament plays without a pot.</text>
@endif
</svg>
