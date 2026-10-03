{{--
    TV2 · the matches being played now, as the tournament TV shows its "Up now" scene (pages::tournaments.tv), in the
    TV frame (partials/tv-frame): the game's cover faint behind (10 %), then each live match (at most two) with the
    Live chip and its round over both players: avatar in an orange ring, the name as large as its half allows, the seed
    under it, "vs" in orange between them. One match alone takes larger avatars.

    Data contract: $tournament as TournamentLiveSlides builds it (running: live, cover, results, tv) and $viewers.
    Without a live match (paused, a lobby tournament) the rotation leaves this slide out; drawn anyway it says so.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@use('App\Support\TwentyOne\Stream\TvSlides', 'Tv')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $duels = array_values(array_filter(array_slice((array) ($t['live'] ?? []), 0, 2), fn (mixed $m): bool => is_array($m) && count((array) ($m['sides'] ?? [])) === 2));
    $solo = count($duels) === 1;
    $faceD = $solo ? 192 : 115;
    $roundH = 26;
    $duelH = $roundH + 22 + $faceD;
    $gap = 66;
    $blockH = count($duels) * $duelH + max(0, count($duels) - 1) * $gap;
    $top0 = round((Tv::Y0 + Tv::Y1) / 2 - $blockH / 2);
    $coverUri = K::coverUri($t['cover'] ?? null);
    $nameMax = round(12.8 * (($solo ? 15 : 9) * 0.32 + 0.4));
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
@include('stream.rotation.partials.tv-frame', ['t' => $t, 'part' => 2])

@if ($coverUri)
<clipPath id="tv2-cover"><rect x="236" y="{{ Tv::Y0 }}" width="808" height="{{ Tv::Y1 - Tv::Y0 }}" rx="8"/></clipPath>
<image x="236" y="{{ Tv::Y0 }}" width="808" height="{{ Tv::Y1 - Tv::Y0 }}" preserveAspectRatio="xMidYMid slice" clip-path="url(#tv2-cover)" opacity="0.1" xlink:href="{{ $coverUri }}"/>
@endif

@foreach ($duels as $di => $duel)
@php
    $y = $top0 + $di * ($duelH + $gap);
    $round = K::fit((string) ($duel['round'] ?? ''), K::DISPLAY, 19, 400);
    $chipW = 82;
    $roundW = K::width($round, K::DISPLAY, 19) * 1.04;
    $rx0 = round(640 - ($chipW + 13 + $roundW) / 2, 1);
    $faceY = $y + $roundH + 22;
    $cy = $faceY + $faceD / 2;
@endphp
<g data-unit="duel-round-{{ $di }}"><rect x="{{ $rx0 }}" y="{{ $y }}" width="{{ $chipW }}" height="{{ $roundH }}" rx="2.5" fill="{{ Tv::BTC }}"/><circle cx="{{ $rx0 + 13 }}" cy="{{ $y + 13 }}" r="4.2" fill="{{ Tv::ON_BTC }}"/><text x="{{ $rx0 + 23 }}" y="{{ $y + 20 }}" font-family="Unbounded" font-weight="700" font-size="19" fill="{{ Tv::ON_BTC }}">Live</text><text x="{{ round($rx0 + $chipW + 13, 1) }}" y="{{ $y + 20 }}" font-family="Unbounded" font-weight="700" font-size="19" fill="{{ Tv::INK }}">{{ $round }}</text></g>
@foreach (array_values($duel['sides']) as $si => $side)
@php
    $right = $si === 1;
    $fx = $right ? 1204 - $faceD : 76;
    $room = 640 - 40 - 38 - ($faceD + 19) - 38;
    $nameSize = $nameMax;
    $clean = K::clean((string) ($side['name'] ?? ''));
    $font = $clean === '' ? K::DISPLAY : K::nameFont($clean);
    while ($nameSize > 19 && K::width($clean, $font, $nameSize) * 1.04 > $room) {
        $nameSize -= 1;
    }
    $nm = K::name($side['name'] ?? '', 'Player', $nameSize, $room);
    $tx = $right ? $fx - 19 : $fx + $faceD + 19;
    $anchor = $right ? 'end' : 'start';
    $seed = is_int($side['seed'] ?? null) ? 'Seed '.$side['seed'] : '';
@endphp
<rect x="{{ $fx - 5 }}" y="{{ $faceY - 5 }}" width="{{ $faceD + 10 }}" height="{{ $faceD + 10 }}" rx="{{ round(($faceD + 10) * 0.18, 1) }}" fill="none" stroke="{{ Tv::BTC }}" stroke-width="3"/>
@include('stream.rotation.partials.face', ['face' => K::face($side), 'x' => $fx, 'y' => $faceY, 'd' => $faceD, 'id' => 'tv2-'.$di.'-'.$si, 'fShape' => 'square', 'fGround' => Tv::RAISED, 'fGlyph' => '#4A4A52', 'fUnit' => 'duel-face-'.$di.'-'.$si])
<text data-unit="duel-name-{{ $di }}-{{ $si }}" x="{{ $tx }}" y="{{ round($cy - ($seed !== '' ? 4 : -$nameSize * 0.36), 1) }}" font-family="{{ $nm['font'] }}" font-weight="800" font-size="{{ $nameSize }}" fill="{{ Tv::INK }}" text-anchor="{{ $anchor }}">{{ $nm['text'] }}</text>
@if ($seed !== '')<text data-unit="duel-seed-{{ $di }}-{{ $si }}" x="{{ $tx }}" y="{{ round($cy + 28, 1) }}" font-family="JetBrains Mono" font-size="19" fill="{{ Tv::INK2 }}" text-anchor="{{ $anchor }}">{{ $seed }}</text>@endif
@endforeach
<text data-unit="duel-vs-{{ $di }}" x="640" y="{{ round($cy + 11, 1) }}" font-family="Unbounded" font-weight="800" font-size="31" fill="{{ Tv::BTC }}" text-anchor="middle">vs</text>
@endforeach
@if ($duels === [])
<text data-unit="empty" x="640" y="410" font-family="Unbounded" font-weight="700" font-size="31" fill="{{ Tv::INK }}" text-anchor="middle">No match is being played right now.</text>
@endif
</svg>
