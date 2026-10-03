{{--
    One match as the tournament TV draws it (pages.tournaments.partials.tv-box, resources/css/tv.css): a panel with
    both sides, each its face, name and score. The winner in bold with an orange check and score, the loser dimmed
    (weight, mark and brightness, never colour alone); an open side an empty face and where it comes from; a live
    match on the warm panel with an orange edge and the "Live" chip on its top edge; a voided one dimmed and marked.

    Params: $bx (array{x, y, w, h}), $box (a TournamentLiveSlides box: state, sides, how), $k (the TV's size factor),
    $id (unique in the scene).
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@use('App\Support\TwentyOne\Stream\TvSlides', 'Tv')
@php
    $fs = round(24 * $k, 1);
    $state = (string) ($box['state'] ?? 'waiting');
    $live = $state === 'live';
    $sides = array_values(array_filter((array) ($box['sides'] ?? []), is_array(...)));
    $winnerKnown = $state === 'done' && collect($sides)->contains(fn (array $s): bool => ($s['won'] ?? false) === true);
    $padX = round($fs * 0.5, 1);
    $padY = round($fs * 0.35, 1);
    $pitch = round($fs * 1.25 + $fs * 0.2, 1);
    $faceD = round($fs * 1.15, 1);
@endphp
<g data-unit="box-{{ $id }}" @if ($state === 'void' || $state === 'bye') opacity="0.45" @endif>
<rect x="{{ $bx['x'] }}" y="{{ $bx['y'] }}" width="{{ $bx['w'] }}" height="{{ $bx['h'] }}" rx="5" fill="{{ $live ? Tv::LIVE_PANEL : Tv::PANEL }}" stroke="{{ $live ? Tv::BTC : Tv::LINE }}" stroke-width="{{ $live ? 1.6 : 1 }}"/>
@foreach (array_slice($sides, 0, 2) as $si => $side)
@php
    $cy = $bx['y'] + $padY + $si * $pitch + $fs * 0.625;
    $won = ($side['won'] ?? false) === true;
    $lost = $winnerKnown && ! $won;
    $known = ($side['known'] ?? true) === true;
    $score = is_string($side['score'] ?? null) ? $side['score'] : null;
    $scoreW = $score === null ? 0 : K::width($score, K::DISPLAY, $fs) * 1.04;
    $markW = $won ? $fs * 0.8 + $fs * 0.4 : 0;
    $nameX = $bx['x'] + $padX + $faceD + $fs * 0.4;
    $nameRoom = $bx['x'] + $bx['w'] - $padX - $scoreW - $markW - ($score !== null ? $fs * 0.4 : 0) - $nameX;
    $nameFs = $known ? $fs : round($fs * 0.8, 1);
    $nm = $known ? K::name($side['name'] ?? '', 'Open', $nameFs, $nameRoom) : ['text' => K::fit($side['name'] ?? '', K::MONO, $nameFs, $nameRoom), 'font' => K::MONO];
@endphp
<g @if ($lost) opacity="0.45" @endif>
@if ($known)
@include('stream.rotation.partials.face', ['face' => K::face($side), 'x' => $bx['x'] + $padX, 'y' => round($cy - $faceD / 2, 1), 'd' => $faceD, 'id' => $id.'-'.$si, 'fShape' => 'square', 'fGround' => Tv::RAISED, 'fGlyph' => '#4A4A52', 'fUnit' => 'box-face-'.$id.'-'.$si])
@else
<rect x="{{ $bx['x'] + $padX + 1 }}" y="{{ round($cy - $faceD / 2 + 1, 1) }}" width="{{ $faceD - 2 }}" height="{{ $faceD - 2 }}" rx="{{ round($faceD * 0.18, 1) }}" fill="none" stroke="{{ Tv::LINE }}" stroke-width="1.5"/>
@endif
<text data-unit="box-name-{{ $id }}-{{ $si }}" x="{{ round($nameX, 1) }}" y="{{ round($cy + $nameFs * 0.36, 1) }}" font-family="{{ $nm['font'] }}" font-weight="{{ $known ? ($won ? 700 : 500) : 400 }}" font-size="{{ $nameFs }}" fill="{{ $known ? Tv::INK : Tv::INK3 }}">{{ $nm['text'] }}</text>
@if ($won)
@php $mx = $bx['x'] + $bx['w'] - $padX - $scoreW - ($score !== null ? $fs * 0.4 : 0) - $fs * 0.8; @endphp
<path d="M{{ round($mx + $fs * 0.1, 1) }} {{ round($cy, 1) }}l{{ round($fs * 0.22, 1) }} {{ round($fs * 0.22, 1) }}l{{ round($fs * 0.42, 1) }} -{{ round($fs * 0.44, 1) }}" fill="none" stroke="{{ Tv::BTC }}" stroke-width="{{ round(max(1.6, $fs * 0.09), 1) }}" stroke-linecap="round" stroke-linejoin="round"/>
@endif
@if ($score !== null)
<text data-unit="box-score-{{ $id }}-{{ $si }}" x="{{ round($bx['x'] + $bx['w'] - $padX, 1) }}" y="{{ round($cy + $fs * 0.36, 1) }}" font-family="Unbounded" font-weight="800" font-size="{{ $fs }}" fill="{{ $won ? Tv::BTC : Tv::INK }}" text-anchor="end">{{ $score }}</text>
@endif
</g>
@endforeach
@if ($live && $k >= 0.6)
@php
    // The chip scales down with a dense tree, never below 12 px type.
    $cs = max(0.75, min(1, $k * 1.25));
    $cw = round(62 * $cs, 1);
    $chx = round($bx['x'] + $bx['w'] - 9.6 * $cs - $cw, 1);
@endphp
<g data-unit="box-live-{{ $id }}"><rect x="{{ $chx }}" y="{{ round($bx['y'] - 13 * $cs, 1) }}" width="{{ $cw }}" height="{{ round(24 * $cs, 1) }}" rx="2.5" fill="{{ Tv::BTC }}"/><circle cx="{{ round($chx + 12 * $cs, 1) }}" cy="{{ round($bx['y'] - 1 * $cs, 1) }}" r="{{ round(3.8 * $cs, 1) }}" fill="{{ Tv::ON_BTC }}"/><text x="{{ round($chx + 21 * $cs, 1) }}" y="{{ round($bx['y'] + 4.6 * $cs, 1) }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ round(16 * $cs, 1) }}" fill="{{ Tv::ON_BTC }}">Live</text></g>
@elseif ($state === 'void')
<text data-unit="box-void-{{ $id }}" x="{{ round($bx['x'] + $bx['w'] - $padX, 1) }}" y="{{ round($bx['y'] + $bx['h'] / 2 + 5, 1) }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="{{ Tv::INK2 }}" text-anchor="end">voided</text>
@endif
</g>
