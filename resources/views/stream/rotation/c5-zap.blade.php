{{--
    C5 · Terminal ticker · zap this stream. Bolt and headline, a three-row table (how, with what, to whom: the
    league pool, user 2026-10-03), the LNURL as a QR
    code on the right, the stats bar at the bottom. No Lightning address as text, no zap counts, and no
    NIP-05 either: name@domain reads like a Lightning address and pays nowhere.

    Data contract:
      $qrSvg   string: the LNURL QR as SVG (as in a5-zap); empty = no code, the "how" row names only the bolt
      $stats     array for the stats bar (see c1-match)
      $backdrop  ?string, optional: the brand backdrop, as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $hasQr = trim((string) ($qrSvg ?? '')) !== '';
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.76])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'zaps'])
<g transform="translate(40 144) scale(3)" fill="none" stroke="#F7931A" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"><path d="M13 2 L4 14 H11 L10 22 L20 9 H13 Z"/></g>
<text x="132" y="200" font-family="JetBrains Mono" font-weight="700" font-size="56" fill="#FFFFFF">Zap this stream</text>
<rect x="40" y="250" width="{{ $hasQr ? 880 : 1200 }}" height="1" fill="#2A2A30"/>
<text x="40" y="292" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">how</text>
<text x="320" y="292" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#F7931A">{{ $hasQr ? 'scan the code, or tap the bolt' : 'tap the bolt in your Nostr client' }}</text>
<rect x="40" y="312" width="{{ $hasQr ? 880 : 1200 }}" height="1" fill="#2A2A30"/>
<text x="40" y="350" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">with</text>
<text x="320" y="350" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF">{{ $hasQr ? 'a Lightning wallet or a Nostr client' : 'a Nostr client with zaps' }}</text>
<rect x="40" y="370" width="{{ $hasQr ? 880 : 1200 }}" height="1" fill="#2A2A30"/>
<text x="40" y="408" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">to</text>
<text x="320" y="408" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#F7931A" data-zap-pool="1">the league pool, for prizes</text>
<rect x="40" y="428" width="{{ $hasQr ? 880 : 1200 }}" height="1" fill="#2A2A30"/>
@if ($hasQr)
@include('stream.rotation.partials.qr', ['qr' => $qrSvg, 'modules' => $qrModules ?? null, 'x' => 960, 'y' => 250, 'size' => 280])
@endif
</svg>
