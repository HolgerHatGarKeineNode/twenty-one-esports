{{--
    A face next to a name: the person's avatar, a clan's logo (or, without one, its tag tile), a neutral placeholder,
    or an empty seat. $x, $y (top left) and $d (size) in px; $id makes the clip path unique in the scene.
    $face    array{uri: ?string, tag: ?string, fit?: string}|null (RotationKit::face()); uri is checked again here
             (avatarUri), so a raw value can never reach the <image>. fit 'meet' (a logo) draws the image whole on
             $fGround instead of filling the circle. Neither uri nor tag: a neutral circle with a head-and-shoulders mark.
    Optional (prefixed f, so a parent's variables never leak in):
             $fOpen ('open' = a dashed empty seat, 'invite' = the same with a "+"), $fOpenInk, $fShape ('round' |
             'square'), $fRing (accent of the look, null = none), $fGround / $fGlyph (placeholder colours),
             $fTagFill / $fTagInk, $fKing ('wk' | 'bk': a small side tile bottom right, rim $fKingRim), $fRank (int: a
             numbered badge bottom left, with $fRankFill / $fRankInk / $fRankRim), $fCrown (colour: a crown above the
             face), $fClan (a clan logo data URI: a round badge top right, only drawn from 64 px up), $fUnit (data-unit
             for the pixel sonde).
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $fd = (float) $d;
    $fr = $fd / 2;
    $fcx = $x + $fr;
    $fcy = $y + $fr;
    $fsq = ($fShape ?? 'round') === 'square';
    $frx = round($fd * 0.16, 1);
    $fring = $fRing ?? null;
    $frw = $fd >= 96 ? ($fd >= 180 ? 4 : 3) : 2;
    $fopen = $fOpen ?? null;
    $furi = $fopen ? null : K::avatarUri($face['uri'] ?? null);
    $fmeet = ($face['fit'] ?? 'slice') === 'meet';
    $ftag = $fopen || $furi ? '' : (string) ($face['tag'] ?? '');
    $ftagSize = round($fd * match (mb_strlen($ftag)) { 1 => 0.5, 2 => 0.38, 3 => 0.28, default => 0.22 }, 1);
    $fclan = $fd >= 64 ? K::avatarUri($fClan ?? null) : null;
@endphp
<g data-unit="{{ $fUnit ?? 'face-'.$id }}">
@if ($fopen)
@if ($fsq)<rect x="{{ $x + 1 }}" y="{{ $y + 1 }}" width="{{ $fd - 2 }}" height="{{ $fd - 2 }}" rx="{{ $frx }}" fill="none" stroke="{{ $fOpenInk ?? '#6B6B72' }}" stroke-width="2" stroke-dasharray="4 3"/>@else<circle cx="{{ $fcx }}" cy="{{ $fcy }}" r="{{ $fr - 1 }}" fill="none" stroke="{{ $fOpenInk ?? '#6B6B72' }}" stroke-width="2" stroke-dasharray="4 3"/>@endif
@if ($fopen === 'invite')<path d="M{{ $fcx - $fd * 0.2 }} {{ $fcy }}H{{ $fcx + $fd * 0.2 }}M{{ $fcx }} {{ $fcy - $fd * 0.2 }}V{{ $fcy + $fd * 0.2 }}" fill="none" stroke="{{ $fOpenInk ?? '#6B6B72' }}" stroke-width="{{ max(2, round($fd / 14)) }}" stroke-linecap="round"/>@endif
@else
<clipPath id="fc-{{ $id }}">@if ($fsq)<rect x="{{ $x }}" y="{{ $y }}" width="{{ $fd }}" height="{{ $fd }}" rx="{{ $frx }}"/>@else<circle cx="{{ $fcx }}" cy="{{ $fcy }}" r="{{ $fr }}"/>@endif</clipPath>
@if ($furi && $fmeet)
{{-- A logo whole, inset from the ring, on the ground: a crest with alpha keeps its shape. --}}
@php($fin = round($fd * ($fsq ? 0.1 : 0.17), 1))
<rect x="{{ $x }}" y="{{ $y }}" width="{{ $fd }}" height="{{ $fd }}" rx="{{ $fsq ? $frx : $fr }}" fill="{{ $fGround ?? '#17120A' }}"/>
<image x="{{ $x + $fin }}" y="{{ $y + $fin }}" width="{{ $fd - 2 * $fin }}" height="{{ $fd - 2 * $fin }}" preserveAspectRatio="xMidYMid meet" xlink:href="{{ $furi }}"/>
@elseif ($furi)
{{-- The data URI once, as xlink:href (SVG 1.1, read by every librsvg). --}}
<image x="{{ $x }}" y="{{ $y }}" width="{{ $fd }}" height="{{ $fd }}" preserveAspectRatio="xMidYMid slice" clip-path="url(#fc-{{ $id }})" xlink:href="{{ $furi }}"/>
@elseif ($ftag !== '')
<rect x="{{ $x }}" y="{{ $y }}" width="{{ $fd }}" height="{{ $fd }}" rx="{{ $fsq ? $frx : $fr }}" fill="{{ $fTagFill ?? '#F7931A' }}"/>
<text x="{{ $fcx }}" y="{{ round($fcy + $ftagSize * 0.36, 1) }}" font-family="{{ K::nameFont($ftag) }}" font-weight="800" font-size="{{ $ftagSize }}" fill="{{ $fTagInk ?? '#17120A' }}" text-anchor="middle">{{ $ftag }}</text>
@else
<g clip-path="url(#fc-{{ $id }})"><rect x="{{ $x }}" y="{{ $y }}" width="{{ $fd }}" height="{{ $fd }}" fill="{{ $fGround ?? '#2A2A30' }}"/><circle cx="{{ $fcx }}" cy="{{ round($y + $fd * 0.4, 1) }}" r="{{ round($fd * 0.17, 1) }}" fill="{{ $fGlyph ?? '#55555C' }}"/><ellipse cx="{{ $fcx }}" cy="{{ round($y + $fd * 0.93, 1) }}" rx="{{ round($fd * 0.32, 1) }}" ry="{{ round($fd * 0.26, 1) }}" fill="{{ $fGlyph ?? '#55555C' }}"/></g>
@endif
@if ($fring)@if ($fsq)<rect x="{{ $x + $frw / 2 }}" y="{{ $y + $frw / 2 }}" width="{{ $fd - $frw }}" height="{{ $fd - $frw }}" rx="{{ $frx }}" fill="none" stroke="{{ $fring }}" stroke-width="{{ $frw }}"/>@else<circle cx="{{ $fcx }}" cy="{{ $fcy }}" r="{{ $fr - $frw / 2 }}" fill="none" stroke="{{ $fring }}" stroke-width="{{ $frw }}"/>@endif @endif
@endif
@if ($fclan)
{{-- The player's clan: its logo whole on a dark disc, top right. --}}
@php($fcb = round($fd * 0.36))
<circle cx="{{ $x + $fd - $fcb * 0.35 }}" cy="{{ $y + $fcb * 0.35 }}" r="{{ $fcb / 2 }}" fill="#17120A" stroke="{{ $fring ?? '#F7931A' }}" stroke-width="2"/>
<image x="{{ $x + $fd - $fcb * 0.35 - $fcb * 0.36 }}" y="{{ $y + $fcb * 0.35 - $fcb * 0.36 }}" width="{{ round($fcb * 0.72, 1) }}" height="{{ round($fcb * 0.72, 1) }}" preserveAspectRatio="xMidYMid meet" xlink:href="{{ $fclan }}"/>
@endif
@if (! empty($fKing))
@php($fk = round($fd * 0.32))
<rect x="{{ $x + $fd - $fk * 0.8 }}" y="{{ $y + $fd - $fk * 0.8 }}" width="{{ $fk }}" height="{{ $fk }}" rx="3" fill="#CFCFD4" stroke="{{ $fKingRim ?? '#17120A' }}" stroke-width="2"/>
<use href="#p-{{ $fKing }}" xlink:href="#p-{{ $fKing }}" x="{{ $x + $fd - $fk * 0.8 }}" y="{{ $y + $fd - $fk * 0.8 }}" width="{{ $fk }}" height="{{ $fk }}"/>
@endif
@if (isset($fRank))
@php($fb = round($fd * 0.2, 1))
<circle cx="{{ $x + $fb * 0.9 }}" cy="{{ $y + $fd - $fb * 0.9 }}" r="{{ $fb }}" fill="{{ $fRankFill ?? '#F7931A' }}" stroke="{{ $fRankRim ?? '#0A0A0B' }}" stroke-width="2"/>
<text x="{{ $x + $fb * 0.9 }}" y="{{ round($y + $fd - $fb * 0.9 + $fb * 0.4, 1) }}" font-family="Unbounded" font-weight="800" font-size="{{ round($fb * 1.1, 1) }}" fill="{{ $fRankInk ?? '#17120A' }}" text-anchor="middle">{{ $fRank }}</text>
@endif
@if (! empty($fCrown))
@php($fw = $fd * 0.46)
<path transform="translate({{ round($fcx - $fw / 2, 1) }} {{ round($y - $fw * 0.62, 1) }}) scale({{ round($fw / 24, 3) }})" d="M0 16L1.5 3.5L7 9L12 0L17 9L22.5 3.5L24 16Z" fill="{{ $fCrown }}"/>
@endif
</g>
