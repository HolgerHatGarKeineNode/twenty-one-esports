{{--
    D4 · Terminal ticker · the league on Nostr. Header line and stats bar as c4-scan; the site's QR code on the left,
    the claim and three facts on the right (calendar event, the stream bot's note, the .ics download), then the login
    line. Without a QR code the text moves left.

    Data contract:
      $siteQrSvg  string: QR of https://esports.einundzwanzig.space as SVG (as c4-scan); empty = no code shown
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: the brand backdrop, as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $hasQr = trim((string) ($siteQrSvg ?? '')) !== '';
    $tx = $hasQr ? 504 : 40;
    // COPY-CHECK: every published tournament is a NIP-52 calendar event (31923) and the stream bot posts it as a note
    // (c28adbb, ESPORTS_STREAM_BOT_ENABLED on in prod, 6 notes on 2026-09-28); the tournament page offers the .ics
    // download (pages/tournaments/partials/when.blade.php, data-test="when-calendar"); login with Nostr (a4-join).
    $facts = [
        ['Calendar', 'Every tournament is a Nostr calendar event.'],
        ['Notes', 'The stream bot posts each one as a note.'],
        ['.ics', 'One tap puts it in your own calendar.'],
    ];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.78])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'on nostr'])
@if ($hasQr)
@include('stream.rotation.partials.qr', ['qr' => $siteQrSvg, 'modules' => $siteQrModules ?? null, 'x' => 40, 'y' => 148, 'size' => 400])
@endif
<text x="{{ $tx }}" y="206" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">Never miss</text>
<text x="{{ $tx }}" y="258" font-family="Unbounded" font-weight="800" font-size="40" fill="#F7931A">a tournament.</text>
<rect x="{{ $tx }}" y="296" width="{{ 1240 - $tx }}" height="1" fill="#2A2A30"/>
@foreach ($facts as $i => [$label, $fact])
<text x="{{ $tx }}" y="{{ 340 + $i * 62 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $label }}</text>
<text data-unit="fact-{{ $i }}" data-box="{{ $tx + 139 }} {{ 318 + $i * 62 }} 1240 {{ 346 + $i * 62 }}" x="{{ $tx + 140 }}" y="{{ 340 + $i * 62 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ K::fit($fact, K::MONO, 20, 1100 - $tx) }}</text>
<rect x="{{ $tx }}" y="{{ 362 + $i * 62 }}" width="{{ 1240 - $tx }}" height="1" fill="#2A2A30"/>
@endforeach
<text data-unit="login" data-box="{{ $tx - 1 }} 538 1240 566" x="{{ $tx }}" y="560" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Log in with Nostr, follow the league.</text>
</svg>
