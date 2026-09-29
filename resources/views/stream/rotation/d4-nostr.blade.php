{{--
    D4 · Terminal ticker · the league on Nostr: your name, your rank and your wins travel with your key. Header line
    and stats bar as c4-scan; the site's QR code on the left, the claim and four facts on the right (a name on the
    league's domain, the rank badge, comments and likes, the calendar event), then the login line. Without a QR code
    the text moves left. No address is written out: name@domain reads like a Lightning address (c5-zap).

    Data contract:
      $siteQrSvg  string: QR of https://esports.einundzwanzig.space as SVG (as c4-scan); empty = no code shown
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: the brand backdrop, as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $hasQr = trim((string) ($siteQrSvg ?? '')) !== '';
    $tx = $hasQr ? 504 : 40;
    // COPY-CHECK: a player may claim a NIP-05 name on the league's domain (App\Support\Nostr\Nip05Names, P47, opt-in in
    // the settings); a rank badge is a NIP-58 badge awarded to the player's key (App\Support\Badges\RankBadges); players
    // comment on and like tournaments, rated games and rated series (NIP-22 / NIP-25, App\Support\Comments\NostrComments,
    // P48); every published tournament is a NIP-52 calendar event (31923) the stream bot posts as a note (c28adbb).
    $facts = [
        ['Name', 'Claim your name on the league\'s domain.'],
        ['Rank', 'Your rank badge lands on your Nostr key.'],
        ['Talk', 'Comment on and like games and tournaments.'],
        ['Dates', 'Every tournament is a calendar event.'],
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
<text x="{{ $tx }}" y="190" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">Your rank goes</text>
<text x="{{ $tx }}" y="242" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">wherever you go.</text>
<rect x="{{ $tx }}" y="276" width="{{ 1240 - $tx }}" height="4" fill="#F7931A"/>
@foreach ($facts as $i => [$label, $fact])
<text x="{{ $tx }}" y="{{ 322 + $i * 58 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $label }}</text>
<text data-unit="fact-{{ $i }}" data-box="{{ $tx + 99 }} {{ 300 + $i * 58 }} 1240 {{ 328 + $i * 58 }}" x="{{ $tx + 100 }}" y="{{ 322 + $i * 58 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ K::fit($fact, K::MONO, 20, 1140 - $tx) }}</text>
<rect x="{{ $tx }}" y="{{ 342 + $i * 58 }}" width="{{ 1240 - $tx }}" height="1" fill="#2A2A30"/>
@endforeach
<text data-unit="login" data-box="{{ $tx - 1 }} 552 1240 580" x="{{ $tx }}" y="574" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Log in with Nostr, follow the league.</text>
</svg>
