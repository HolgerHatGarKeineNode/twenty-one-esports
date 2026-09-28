{{--
    D3 · Arena · invite by link. Full orange frame as a4-join: the pitch, three columns (what a link does in chess,
    for a clan, in a messenger), where to find the button, the site's QR code on the right. Without a QR code the
    columns keep their places and the corner stays empty.

    Data contract:
      $siteQrSvg  string: QR of https://esports.einundzwanzig.space as SVG (as c4-scan); empty = no code shown
      $backdrop   ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $hasQr = trim((string) ($siteQrSvg ?? '')) !== '';
    // COPY-CHECK: 07f825b ("Put 'Invite a friend by link' at the top of /chess and the game pages"): chess
    // makes a daily link with uses, expiry and colour; a series game page gives a clan captain the clan's join link;
    // the landing /i/{code} has a preview for Signal, Telegram and Nostr (pages/invites/⚡link.blade.php docblock).
    $cols = [
        ['Chess', ['A daily game by link.', 'Pick colour, expiry, uses.']],
        ['Clans', ['Captains share the', 'clan\'s join link.']],
        ['Anywhere', ['Previews in Signal,', 'Telegram and Nostr.']],
    ];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.2])
<rect width="1280" height="720" fill="#F7931A" fill-opacity="{{ K::backdropUri($backdrop ?? null) ? 0.94 : 1 }}"/>
<use href="#mark-dark" xlink:href="#mark-dark" x="40" y="48" width="64" height="64"/>
<text x="124" y="92" font-family="Unbounded" font-weight="800" font-size="32" fill="#17120A">Bring a friend</text>
<text x="40" y="236" font-family="Unbounded" font-weight="800" font-size="60" fill="#17120A">Send a link.</text>
<text x="40" y="310" font-family="Unbounded" font-weight="800" font-size="60" fill="#17120A">Play together.</text>
@foreach ($cols as $i => [$title, $lines])
@php($cx = 40 + $i * 300)
<rect x="{{ $cx }}" y="372" width="48" height="6" fill="#17120A"/>
<text x="{{ $cx }}" y="424" font-family="Unbounded" font-weight="800" font-size="28" fill="#17120A">{{ $title }}</text>
@foreach ($lines as $li => $line)<text data-unit="col-{{ $i }}-{{ $li }}" data-box="{{ $cx - 1 }} {{ 446 + $li * 28 }} {{ $cx + 284 }} {{ 470 + $li * 28 }}" x="{{ $cx }}" y="{{ 464 + $li * 28 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">{{ $line }}</text>@endforeach
@endforeach
@if ($hasQr)
<rect x="944" y="144" width="296" height="296" fill="#17120A"/>
@include('stream.rotation.partials.qr', ['qr' => $siteQrSvg, 'modules' => $siteQrModules ?? null, 'x' => 952, 'y' => 152, 'size' => 280])
@endif
<text data-unit="where" data-box="39 560 1000 588" x="40" y="580" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">"Invite a friend by link": top of /chess and every game page.</text>
<text x="1240" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A" text-anchor="end">esports.einundzwanzig.space</text>
@php($vb = K::viewerBadge($viewers ?? null, 1024, 92, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#17120A', 'countInk' => '#17120A', 'wordInk' => '#17120A'])@endif
</svg>
