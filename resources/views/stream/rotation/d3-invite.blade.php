{{--
    D3 · Arena · invite by link. Full orange frame as a4-join: the pitch, three columns (what a link does in chess, for
    a clan, for a tournament), the site's QR code on the right, and the proof that it works: the player who brought
    the most new players in the last 30 days, with face and count (PrideSlides `inviters`), else where to find the
    button. Without a QR code the columns keep their places and the corner stays empty.

    Data contract:
      $siteQrSvg  string: QR of https://esports.einundzwanzig.space as SVG (as c4-scan); empty = no code shown
      $pride      array{inviters?: list<array{name: string, avatar: ?string, brought: int}>, …}, optional (PrideSlides::all())
      $backdrop   ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $hasQr = trim((string) ($siteQrSvg ?? '')) !== '';
    // COPY-CHECK: 07f825b ("Put 'Invite a friend by link' at the top of /chess and the game pages"): chess
    // makes a daily link with uses, expiry and colour; a series game page gives a clan captain the clan's join link.
    // A tournament link (P47, InviteLinks::forTournament) is one per player and tournament, and a friend's sign-up
    // through it gives both of them the "Brought a friend" frame (InviteLinks::creditTournamentSignup, Cosmetics).
    $cols = [
        ['Chess', ['A daily game by link.', 'Pick colour, expiry, uses.']],
        ['Clans', ['Captains share the', 'clan\'s join link.']],
        ['Tournaments', ['Your own sign-up link.', 'Both of you get a frame.']],
    ];
    $top = K::prideRows($pride['inviters'] ?? [], 1)[0] ?? null;
    if ($top) {
        $brought = max(1, (int) ($top['brought'] ?? 1));
        $topName = K::name($top['name'], 'Player', 30, 560);
        $topLine = 'brought '.$brought.' new '.($brought === 1 ? 'player' : 'players').' in 30 days';
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.2])
<rect width="1280" height="720" fill="#F7931A" fill-opacity="{{ K::backdropUri($backdrop ?? null) ? 0.94 : 1 }}"/>
<use href="#mark-dark" xlink:href="#mark-dark" x="40" y="48" width="64" height="64"/>
<text x="124" y="92" font-family="Unbounded" font-weight="800" font-size="32" fill="#17120A">Bring a friend</text>
<text x="40" y="226" font-family="Unbounded" font-weight="800" font-size="60" fill="#17120A">Send a link.</text>
<text x="40" y="300" font-family="Unbounded" font-weight="800" font-size="60" fill="#17120A">Play together.</text>
@foreach ($cols as $i => [$title, $lines])
@php($cx = 40 + $i * 300)
<rect x="{{ $cx }}" y="352" width="48" height="6" fill="#17120A"/>
<text x="{{ $cx }}" y="402" font-family="Unbounded" font-weight="800" font-size="28" fill="#17120A">{{ $title }}</text>
@foreach ($lines as $li => $line)<text data-unit="col-{{ $i }}-{{ $li }}" data-box="{{ $cx - 1 }} {{ 424 + $li * 28 }} {{ $cx + 284 }} {{ 448 + $li * 28 }}" x="{{ $cx }}" y="{{ 442 + $li * 28 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">{{ $line }}</text>@endforeach
@endforeach
@if ($hasQr)
<rect x="944" y="144" width="296" height="296" fill="#17120A"/>
@include('stream.rotation.partials.qr', ['qr' => $siteQrSvg, 'modules' => $siteQrModules ?? null, 'x' => 952, 'y' => 152, 'size' => 280])
@endif
@if ($top)
<rect x="40" y="520" width="880" height="104" fill="#17120A"/>
@include('stream.rotation.partials.face', ['face' => K::prideFace($top), 'x' => 60, 'y' => 536, 'd' => 72, 'id' => 'inviter', 'fUnit' => 'inviter-face', 'fRing' => '#F7931A', 'fCrown' => '#F7931A'])
<text data-unit="inviter-name" data-box="151 540 900 580" x="152" y="570" font-family="{{ $topName['font'] }}" font-weight="800" font-size="30" fill="#FFFFFF">{{ $topName['text'] }}</text>
<text data-unit="inviter-line" data-box="151 586 900 612" x="152" y="606" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $topLine }}</text>
@else
<text data-unit="where" data-box="39 560 1000 588" x="40" y="580" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">"Invite a friend by link": top of /chess and every game page.</text>
@endif
<text x="1240" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A" text-anchor="end">esports.einundzwanzig.space</text>
@php($vb = K::viewerBadge($viewers ?? null, 1024, 92, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#17120A', 'countInk' => '#17120A', 'wordInk' => '#17120A'])@endif
</svg>
