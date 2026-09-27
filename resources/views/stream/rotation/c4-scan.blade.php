{{--
    C4 · Terminal ticker · scan to play. The site's QR code on the left, three numbered steps on the right, the stats bar
    at the bottom, the brand backdrop behind. Without a QR code the steps move left and step 1 names only the address.

    Data contract:
      $siteQrSvg  string: QR of https://esports.einundzwanzig.space as SVG (trusted file content, as a5-zap's $qrSvg);
                  empty = no code shown
      $stats      array: players?, gamesToday? for the closing sentence + the stats bar counts
      $backdrop   ?string, optional: the brand backdrop, as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $hasQr = trim((string) ($siteQrSvg ?? '')) !== '';
    $tx = $hasQr ? 504 : 40;
    $players = K::count($stats ?? [], 'players');
    $today = K::count($stats ?? [], 'gamesToday');
    $facts = array_filter([
        $players === null ? null : ($players === 1 ? '1 player is in' : $players.' players are in'),
        K::plural($today, 'game today', 'games today'),
    ]);
    $factLine = $facts === [] ? null : ucfirst(implode(', ', $facts)).'.';
    $steps = [
        $hasQr ? 'Scan the code, or open esports.einundzwanzig.space' : 'Open esports.einundzwanzig.space',
        'Log in with Google or Nostr',
        'Find opponent for blitz, or challenge a player',
    ];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.76])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'scan to play'])
@if ($hasQr)
@include('stream.rotation.partials.qr', ['qr' => $siteQrSvg, 'modules' => $siteQrModules ?? null, 'x' => 40, 'y' => 148, 'size' => 400])
@endif
<text x="{{ $tx }}" y="236" font-family="JetBrains Mono" font-weight="700" font-size="38" fill="#FFFFFF">The next game starts</text>
<text x="{{ $tx }}" y="284" font-family="JetBrains Mono" font-weight="700" font-size="38" fill="#FFFFFF">when you do.</text>
<rect x="{{ $tx }}" y="312" width="{{ 1240 - $tx }}" height="1" fill="#2A2A30"/>
{{-- COPY-CHECK: step 2 per copy-check.md (c): the login page offers Google and Nostr. --}}
{{-- COPY-CHECK: step 3 "Find opponent" is the lobby's blitz button (⚡lobby.blade.php:434/:520); daily = challenge (copy-check.md b). --}}
@foreach ($steps as $i => $step)
<text x="{{ $tx }}" y="{{ 350 + $i * 58 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">{{ $i + 1 }}</text>
<text data-unit="step-{{ $i }}" data-box="{{ $tx + 63 }} {{ 328 + $i * 58 }} 1240 {{ 356 + $i * 58 }}" x="{{ $tx + 64 }}" y="{{ 350 + $i * 58 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $step }}</text>
<rect x="{{ $tx }}" y="{{ 370 + $i * 58 }}" width="{{ 1240 - $tx }}" height="1" fill="#2A2A30"/>
@endforeach
@if ($factLine)<text data-unit="facts" data-box="{{ $tx - 1 }} 550 1240 576" x="{{ $tx }}" y="570" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $factLine }}</text>@endif
</svg>
