{{--
    F5 · Terminal ticker · Blockfill's call to play (BlockfillSlides::PLAY): the C frame of c4. Top left the game's mark
    (its cover, partials/game-mark), beside it "Blockfill Week N, YYYY" and the headline with the time to beat (or the
    call to set the first one). Below, left the QR code of the game page (resources/stream/qr/blockfill.svg), right the
    three steps (a real sequence), that ranked runs need a keyboard, and the countdown to the end of the week in the
    mono face, so it does not jitter.

    Data contract (SceneSource, BlockfillSlides::scene()):
      $blockfill  array{leader: array{name: string, time: string}|null, countdown: string, url: string, title: string, goal: int (the week's blocks), …}|null
                  null while Blockfill is switched off: the slide invites to every game instead
      $siteQrSvg  string: the QR code of the game page ('' leaves it out)
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: Blockfill's blurred cover, else the brand's
      $cover      ?string, optional: Blockfill's cover as a data URI, the game's mark
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = is_array($blockfill ?? null) ? $blockfill : null;
    $leader = is_array($b['leader'] ?? null) ? $b['leader'] : null;
    $best = $leader ? K::clean(K::text($leader, 'time')) : '';
    // One line beside the mark, a size down before anything is cut; never a time split.
    $head = K::headline('Mine '.max(1, (int) ($b['goal'] ?? 40)).' blocks. '.($best !== '' ? 'Beat '.$best : 'Set the first time.'), [44, 36], 984, 1, K::DISPLAY);
    $week = K::fit('Blockfill '.(preg_replace('/^Blockfill\s*/', '', K::text($b ?? [], 'title')) ?: 'this week'), K::MONO, 22, 560);
    $url = K::text($b ?? [], 'url', 'esports.einundzwanzig.space/blockfill');
    $steps = [
        K::fit('Open '.$url, K::MONO, 22, 756),
        'Log in with Google or Nostr',
        'Your best time this week counts',
    ];
    $cd = K::text($b ?? [], 'countdown');
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.86])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'blockfill'])

@if ($b)
@include('stream.rotation.partials.game-mark', ['gmCover' => $cover ?? null, 'gmX' => 40, 'gmY' => 104, 'gmW' => 192, 'gmEdge' => '#F7931A'])
<text data-unit="week" data-box="255 112 817 138" x="256" y="132" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#A1A1A7">{{ $week }}</text>
@foreach ($head['lines'] as $li => $hl)
<text data-unit="headline-{{ $li }}" data-box="254 {{ 188 - $head['size'] }} 1241 {{ 188 + round($head['size'] * 0.3) }}" x="256" y="188" font-family="{{ $head['font'] }}" font-weight="800" font-size="{{ $head['size'] }}" fill="#FFFFFF">{{ $hl }}</text>
@endforeach
@include('stream.rotation.partials.qr', ['qr' => $siteQrSvg ?? '', 'x' => 40, 'y' => 236, 'size' => 360])
@foreach ($steps as $i => $step)
@php($sy = 236 + $i * 58)
<rect x="440" y="{{ $sy }}" width="800" height="1" fill="#2A2A30"/>
<text x="440" y="{{ $sy + 38 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">{{ $i + 1 }}</text>
<text data-unit="step-{{ $i }}" data-box="483 {{ $sy + 18 }} 1241 {{ $sy + 44 }}" x="484" y="{{ $sy + 38 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $step }}</text>
@endforeach
<rect x="440" y="410" width="800" height="1" fill="#2A2A30"/>
<text data-unit="keyboard" data-box="439 436 1241 460" x="440" y="454" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">Ranked runs need a keyboard. On a phone you can practise.</text>
@if ($cd !== '')
<text data-unit="countdown" data-box="439 482 1241 510" x="440" y="504" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">The week closes in <tspan fill="#F7931A">{{ $cd }}</tspan></text>
@endif
@else
<text data-unit="title" data-box="38 150 1241 210" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">Every game</text>
<text data-unit="claim" data-box="39 240 1241 270" x="40" y="262" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Every game of the league is on the site.</text>
@endif
</svg>
