{{--
    F5 · Terminal ticker · Blockfill's call to play (BlockfillSlides::PLAY): the C frame of c4. Left the QR code of the
    game page (resources/stream/qr/blockfill.svg), right the headline with the time to beat (or the call to set the
    first one), the three steps (a real sequence), that ranked runs need a keyboard, and the countdown to the end of
    the week in the mono face, so it does not jitter.

    Data contract (SceneSource, BlockfillSlides::scene()):
      $blockfill  array{leader: array{name: string, time: string}|null, countdown: string, url: string, …}|null
                  null while Blockfill is switched off: the slide invites to every game instead
      $siteQrSvg  string: the QR code of the game page ('' leaves it out)
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: Blockfill's blurred cover, else the brand's
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = is_array($blockfill ?? null) ? $blockfill : null;
    $leader = is_array($b['leader'] ?? null) ? $b['leader'] : null;
    $best = $leader ? K::clean(K::text($leader, 'time')) : '';
    // Two lines by meaning, never a time split across them.
    $head = ['lines' => ['Mine 40 blocks.', $best !== '' ? K::fit('Beat '.$best, K::DISPLAY, 44, 736) : 'Set the first time.'], 'size' => 44, 'font' => K::DISPLAY];
    $url = K::text($b ?? [], 'url', 'esports.einundzwanzig.space/blockfill');
    $steps = [
        K::fit('Open '.$url, K::MONO, 22, 692),
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
@include('stream.rotation.partials.qr', ['qr' => $siteQrSvg ?? '', 'x' => 40, 'y' => 148, 'size' => 400])
@foreach ($head['lines'] as $li => $hl)
<text data-unit="headline-{{ $li }}" data-box="503 {{ 160 + $li * 56 }} 1241 {{ 214 + $li * 56 }}" x="504" y="{{ 204 + $li * 56 }}" font-family="{{ $head['font'] }}" font-weight="800" font-size="{{ $head['size'] }}" fill="#FFFFFF">{{ $hl }}</text>
@endforeach
@foreach ($steps as $i => $step)
@php($sy = 300 + $i * 58)
<rect x="504" y="{{ $sy }}" width="736" height="1" fill="#2A2A30"/>
<text x="504" y="{{ $sy + 38 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">{{ $i + 1 }}</text>
<text data-unit="step-{{ $i }}" data-box="547 {{ $sy + 18 }} 1241 {{ $sy + 44 }}" x="548" y="{{ $sy + 38 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $step }}</text>
@endforeach
<rect x="504" y="474" width="736" height="1" fill="#2A2A30"/>
<text data-unit="keyboard" data-box="503 500 1241 524" x="504" y="518" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">Ranked runs need a keyboard. On a phone you can practise.</text>
@if ($cd !== '')
<text data-unit="countdown" data-box="503 546 1241 574" x="504" y="568" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">The week closes in <tspan fill="#F7931A">{{ $cd }}</tspan></text>
@endif
@else
<text data-unit="title" data-box="38 150 1241 210" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">Every game</text>
<text data-unit="claim" data-box="39 240 1241 270" x="40" y="262" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Every game of the league is on the site.</text>
@endif
</svg>
