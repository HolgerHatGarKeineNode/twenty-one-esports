{{--
    A5 · Arena · zap this stream. The LNURL as a QR code on the left, one headline and one sentence on the right,
    and where the sats go: the league pool (pool@<host>, the profile's lud16; user, 2026-10-03). No Lightning
    address as text, no zap counts. Without a QR code the text moves left and names only the bolt.

    Data contract:
      $qrSvg     string: the LNURL QR as SVG (trusted file content, resources/stream/qr/lnurl.svg as `qrencode -t SVG
                 -m 0` writes it, or inner markup + $qrModules); empty = no code shown
      $backdrop  ?string, optional: the brand backdrop, as in a1-match
--}}
@php($hasQr = trim((string) ($qrSvg ?? '')) !== '')
@php($tx = $hasQr ? 464 : 40)
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null])
@if ($hasQr)
@include('stream.rotation.partials.qr', ['qr' => $qrSvg, 'modules' => $qrModules ?? null, 'x' => 40, 'y' => 180, 'size' => 360])
@else
<g transform="translate(900 180) scale(15)" fill="none" stroke="#F7931A" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"><path d="M13 2 L4 14 H11 L10 22 L20 9 H13 Z"/></g>
@endif
<text x="{{ $tx }}" y="300" font-family="Unbounded" font-weight="800" font-size="64" fill="#FFFFFF">Zap this stream</text>
@if ($hasQr)
<text x="{{ $tx }}" y="364" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#FFFFFF">Scan the code with your Lightning wallet,</text>
<text x="{{ $tx }}" y="400" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#FFFFFF">or tap the bolt in your Nostr client.</text>
@else
<text x="{{ $tx }}" y="364" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#FFFFFF">Tap the bolt in your Nostr client.</text>
@endif
<text x="{{ $tx }}" y="{{ $hasQr ? 464 : 428 }}" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#F7931A" data-zap-pool="1">Zaps go to the league pool for prizes.</text>
<text x="{{ $tx }}" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">esports.einundzwanzig.space</text>
@php($vb = \App\Support\TwentyOne\Stream\RotationKit::viewerBadge($viewers ?? null, 1024, 64, \App\Support\TwentyOne\Stream\RotationKit::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#F7931A', 'countInk' => '#FFFFFF', 'wordInk' => '#ADADB0'])@endif
</svg>
