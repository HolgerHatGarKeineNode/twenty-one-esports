{{--
    The fixed right column of every stream slide (plan "Stream: feste Jetzt-läuft-Spalte rechts"; user 2026-10-15:
    "den NOW Playing Bereich einpassen immer an der gleichen Stelle", choice "Feste Spalte rechts"): x 1040..1280,
    opaque, appended by SceneRenderer::svg() after the slide, so nothing of the slide shows through. The slide keeps
    its content in x 0..1016 (RotationKit::CONTENT_RIGHT).

    The column is the artist's stage (user 2026-10-16: "Die Artists sollen schön Platz für Stolz und Werbung der
    Artists bekommen!"): one centred stack, the artist first. Above y 112 only the calm ground: the players put their
    LIVE badge into that corner (x >= 1040, y < 112). From y 112: the label, the artist's avatar (136 px, orange
    ring; their initials when no picture is cached), the name as the column's largest text (Unbounded, up to two
    lines, sized to the width), the track title when the credit knows it, what the code does (a drawn bolt for a
    lightning address: the stream's fontconfig knows only the brand fonts, no emoji) and the code, as large as the
    height left allows (at most 208 px, quiet zone included). Only real data: name, picture, title, target.
    Without a card (a podcast replay, a track without a credit) the league stands there instead: its mark and the
    code to the site. At the bottom, small, the live viewer count (it moved here out of every slide's top line;
    user 2026-10-15: "Und dann das watching anzahl wo anders platziert?") and the site's address.

    $np: NowPlaying::card() or null — array{artist: string, title?: ?string, avatar: ?string (data URI), qr: string (trusted SVG), kind: 'lud16'|'njump'|'youtube'|'wavlake'}.
    $viewers: ?int, the scene's live viewer count (no count, no row).
    $siteQr: string, the site's code (resources/stream/qr/site.svg) for the column without a card.
    $voice: ?bool, the hosts' on-air light (ProgrammeSlides::voiceOnline(); null while the commentary is off, nothing
            drawn): y 36..100 (user 2026-10-18, "da oben wäre doch noch Platz"; the band above y 32 stays calm for a
            player's LIVE badge), a green "ON AIR" while the workstation voices, a red "OFF AIR" while it or VoiceStudio is gone.
    One <g data-unit> so the pixel sonde measures the column as one piece.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    // Column geometry: the orange edge at x 1040 (4 px); the stack centred on what is left, x 1044..1280 (user
    // 2026-10-16: "Insgesamt ist die Artist Slide rechts nicht mittig vom Inhalt her"), so x 1162. The column keeps a
    // 16 px margin to the frame (TwentyOneProgrammeSlidesTest: right <= 1264 here, 1248 on the slide; baselines
    // <= 700): text within 200 px (1162 ± 100; "JustAnotherNode", one word, needs 194 px at 16), the code at most
    // 196 px.
    $colX = K::CONTENT_WIDTH;
    $cx = ($colX + 4 + 1280) / 2;
    $textW = 200;
    $qrMax = 196;
    $qrFloor = 628;
    $avatarD = 136;
    $avatarY = 148;
    $acy = $avatarY + $avatarD / 2;
    $y = $avatarY + $avatarD;
    $npAvatar = $np === null ? null : K::avatarUri($np['avatar'] ?? null);
    $npName = null;
    $npTitle = '';
    $npAction = null;
    $actionY = 0;
    if ($np !== null) {
        // The name on one line when it fits there at 20 px or more, else in two lines; the largest size at which it fits whole.
        $npName = K::headline((string) $np['artist'], [30, 26, 23, 20], $textW, 1);
        if (implode(' ', $npName['lines']) !== K::clean((string) $np['artist'])) {
            $npName = K::headline((string) $np['artist'], [26, 23, 20, 18, 17, 16], $textW, 2);
        }
        $nameYs = [];
        foreach ($npName['lines'] as $i => $line) {
            $nameYs[] = round($y + 20 + $npName['size'] * 0.8 + $i * $npName['size'] * 1.18, 1);
        }
        $y = end($nameYs);
        // One line, cut after the last whole word that fits.
        $npTitle = K::wrap(is_string($np['title'] ?? null) ? $np['title'] : '', K::MONO, 14, $textW, 1)[0] ?? '';
        $titleY = $y + 26;
        if ($npTitle !== '') {
            $y = $titleY;
        }
        $npAction = match ($np['kind']) {
            'lud16' => 'Zap den Artist',
            'youtube' => 'Artist auf YouTube',
            'wavlake' => 'Zap auf Wavlake',
            default => 'Artist auf Nostr',
        };
        $actionY = $y + 30;
        $bolt = $np['kind'] === 'lud16';
        // Bolt (12 px) and text centred together.
        $actionW = K::width($npAction, K::MONO, 15) + ($bolt ? 18 : 0);
        $actionX = $cx - $actionW / 2;
        $y = $actionY + 6;
        // The first letters of the first two words (no closure: a view closure leaks on every render, TwentyOneSceneTest).
        $initials = '';
        foreach (array_slice(preg_split('/\s+/u', K::clean((string) $np['artist'])) ?: [], 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }
    }
    // The code takes what is left down to $qrFloor, centred in that band.
    $qrTop = $np === null ? 356 : $y + 14;
    $qrSize = (int) max(144, min($qrMax, $qrFloor - $qrTop));
    $qrY = round($qrTop + ($qrFloor - $qrTop - $qrSize) / 2);
    $qrX = round($cx - $qrSize / 2);
    // The viewer row, centred: viewerBadge() sets it right-aligned, so it moves by half its width.
    $vb = K::viewerBadge($viewers ?? null, 1248, 670, K::DISPLAY, 14, 12);
    $vbShift = $vb === null ? 0 : round($cx - ($vb['x0'] + 1248) / 2, 1);
    $voice = is_bool($voice ?? null) ? $voice : null;
    if ($voice !== null) {
        $voiceInk = $voice ? '#4ADE80' : '#F87171';
        $voiceLabel = $voice ? 'ON AIR' : 'OFF AIR';
        $voiceLines = $voice ? ['Markus und Sirius', 'sind live on air'] : ['Markus und Sirius suchen', 'das Kabel für den Neustart'];
        // Dot (10 px and a 8 px gap) and label centred together; letter-spacing 1 adds a pixel per character.
        $voiceW = 18 + K::width($voiceLabel, K::MONO, 13) + mb_strlen($voiceLabel);
        $voiceX = round($cx - $voiceW / 2, 1);
    }
@endphp
<g data-unit="now-playing-column" data-box="{{ $colX }} 0 1280 720">
<rect x="{{ $colX }}" y="0" width="{{ 1280 - $colX }}" height="720" fill="#121215"/>
<rect x="{{ $colX }}" y="0" width="4" height="720" fill="#F7931A"/>
@if ($voice !== null)
<g data-unit="voice-status" data-box="{{ $cx - $textW / 2 }} 36 {{ $cx + $textW / 2 }} 100">
    <circle cx="{{ $voiceX + 5 }}" cy="48" r="5" fill="{{ $voiceInk }}"/>
    <text x="{{ $voiceX + 18 }}" y="53" font-family="JetBrains Mono" font-weight="700" font-size="13" fill="{{ $voiceInk }}" letter-spacing="1">{{ $voiceLabel }}</text>
    @foreach ($voiceLines as $i => $line)
    <text x="{{ $cx }}" y="{{ 74 + $i * 18 }}" font-family="JetBrains Mono" font-weight="700" font-size="12" fill="#ADADB0" text-anchor="middle">{{ $line }}</text>
    @endforeach
</g>
@endif
@if ($np !== null)
<g data-unit="now-playing">
    <text x="{{ $cx }}" y="130" font-family="JetBrains Mono" font-weight="700" font-size="13" fill="#F9B25F" letter-spacing="1" text-anchor="middle">JETZT LÄUFT</text>
    <defs><clipPath id="np-face"><circle cx="{{ $cx }}" cy="{{ $acy }}" r="{{ $avatarD / 2 }}"/></clipPath></defs>
    @if ($npAvatar)
        <image href="{{ $npAvatar }}" x="{{ $cx - $avatarD / 2 }}" y="{{ $avatarY }}" width="{{ $avatarD }}" height="{{ $avatarD }}" preserveAspectRatio="xMidYMid slice" clip-path="url(#np-face)"/>
    @else
        <circle cx="{{ $cx }}" cy="{{ $acy }}" r="{{ $avatarD / 2 }}" fill="#1E1E24"/>
        <text data-unit="now-playing-initials" x="{{ $cx }}" y="{{ $acy + 16 }}" font-family="{{ K::nameFont($initials) }}" font-weight="800" font-size="44" fill="#F7931A" text-anchor="middle">{{ $initials }}</text>
    @endif
    <circle cx="{{ $cx }}" cy="{{ $acy }}" r="{{ $avatarD / 2 - 2 }}" fill="none" stroke="#F7931A" stroke-width="4"/>
    @foreach ($npName['lines'] as $i => $line)
        <text data-unit="now-playing-artist-{{ $i }}" data-box="{{ $cx - $textW / 2 - 1 }} {{ round($nameYs[$i] - $npName['size']) }} {{ $cx + $textW / 2 + 1 }} {{ round($nameYs[$i] + $npName['size'] * 0.3) }}" x="{{ $cx }}" y="{{ $nameYs[$i] }}" font-family="{{ $npName['font'] }}" font-weight="800" font-size="{{ $npName['size'] }}" fill="#FFFFFF" text-anchor="middle">{{ $line }}</text>
    @endforeach
    @if ($npTitle !== '')
        <text data-unit="now-playing-title" x="{{ $cx }}" y="{{ $titleY }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#ADADB0" text-anchor="middle">{{ $npTitle }}</text>
    @endif
    {{-- A drawn bolt, not the emoji: the stream's private fontconfig knows only the brand fonts. --}}
    @if ($bolt)
        <path d="M{{ round($actionX + 8, 1) }} {{ $actionY - 14 }} l-6 10 h4.5 l-2 8 l8 -11 h-4.5 l2.5 -7 z" fill="#F7931A"/>
    @endif
    <text data-unit="now-playing-action" x="{{ round($actionX + ($bolt ? 18 : 0), 1) }}" y="{{ $actionY }}" font-family="JetBrains Mono" font-weight="700" font-size="15" fill="#F7931A">{{ $npAction }}</text>
    @include('stream.rotation.partials.qr', ['x' => $qrX, 'y' => $qrY, 'size' => $qrSize, 'qr' => $np['qr']])
</g>
@else
<g data-unit="now-playing-league">
    <use href="#mark" xlink:href="#mark" x="{{ $cx - 40 }}" y="136" width="80" height="80"/>
    <text x="{{ $cx }}" y="258" font-family="Unbounded" font-weight="800" font-size="20" fill="#FFFFFF" text-anchor="middle">TWENTY ONE</text>
    <text x="{{ $cx }}" y="284" font-family="Unbounded" font-weight="800" font-size="20" fill="#FFFFFF" text-anchor="middle">ESPORTS</text>
    <text x="{{ $cx }}" y="330" font-family="JetBrains Mono" font-weight="700" font-size="15" fill="#F7931A" text-anchor="middle">Scan and play</text>
    @include('stream.rotation.partials.qr', ['x' => $qrX, 'y' => $qrY, 'size' => $qrSize, 'qr' => $siteQr ?? ''])
</g>
@endif
<rect x="{{ $cx - $textW / 2 }}" y="644" width="{{ $textW }}" height="1" fill="#2A2A30"/>
@if ($vb)<g transform="translate({{ $vbShift }} 0)">@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#F7931A', 'countInk' => '#FFFFFF', 'wordInk' => '#ADADB0'])</g>@endif
<text data-unit="now-playing-url" x="{{ $cx }}" y="{{ $vb ? 694 : 680 }}"
 font-family="JetBrains Mono" font-weight="700" font-size="11" fill="#8B8B90" text-anchor="middle">esports.einundzwanzig.space</text>
</g>
