{{--
    S2 · Terminal ticker · season: the launch, for `season_launch_hours` after Block 0 (SeasonSlides::LAUNCH). Header
    line and stats bar as c3. The supply over the season is live as the headline, its genesis message as signed (two
    lines at most), one tile per share with what a win pays there (as s1), the next block as the call (orange plate)
    and the games that mine it. The site's QR stands in the
    now-playing column; a second one here would compete with an artist's tip code there.

    Data contract:
      $season     array{name: string, supply: int, message: ?string, shares: list<array{label: string, share: int,
                  low: int, high: int}>, games: list<array{name: string, url: string}>, …}
                  (SeasonSlides::frameSeason())
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: the brand backdrop
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    // The text x 40..1016 (the now-playing column covers x 1040..1280).
    $s = is_array($season ?? null) ? $season : [];
    $name = K::text($s, 'name', 'The season');
    $supply = (int) ($s['supply'] ?? 0);
    $label = K::fit('The genesis block is mined'.($supply > 0 ? ' · '.K::sats($supply).' sats to mine' : ''), K::MONO, 24, 976);
    $title = K::headline($name.' is live.', [64, 56, 48], 976, 1, K::DISPLAY);
    $message = K::wrap(K::text($s, 'message'), K::MONO, 22, 940, 2);
    $messageY = 228;
    $shares = array_slice(array_values(array_filter((array) ($s['shares'] ?? []), 'is_array')), 0, 4);
    $tileGap = 16;
    $tileW = $shares === [] ? 0 : (K::CONTENT_RIGHT - 40 - $tileGap * (count($shares) - 1)) / count($shares);
    $tileY = 340;
    $next = 'The next block is up for grabs';
    $nextSize = 36;
    $nextW = K::width($next, K::DISPLAY, $nextSize) * 1.04 + 40;
    $games = K::listing(array_column(array_filter((array) ($s['games'] ?? []), 'is_array'), 'name'), 4);
    $playText = 'Mine it with a rated win in '.$games.'. Tick tock, next block.';
    $play = $games !== '' ? K::fit($playText, K::MONO, K::monoSize($playText, 20, 976), 976) : '';
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.8])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'season · block 0 is out'])
<text data-unit="label" data-box="39 110 1016 138" x="40" y="132" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#A1A1A7">{{ $label }}</text>
<text data-unit="title" data-box="38 {{ 196 - $title['size'] }} 1016 {{ 196 + $title['size'] * 0.3 }}" x="40" y="196" font-family="Unbounded" font-weight="800" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $title['lines'][0] ?? '' }}</text>
@if ($message !== [])
<rect x="40" y="{{ $messageY }}" width="4" height="{{ 42 + count($message) * 30 }}" fill="#F7931A"/>
<text data-unit="message-label" data-box="59 {{ $messageY + 4 }} 1016 {{ $messageY + 24 }}" x="60" y="{{ $messageY + 20 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1A7">genesis message, as signed</text>
@foreach ($message as $li => $line)
<text data-unit="message-{{ $li }}" data-box="59 {{ $messageY + 34 + $li * 30 }} 1016 {{ $messageY + 58 + $li * 30 }}" x="60" y="{{ $messageY + 54 + $li * 30 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@endif
@foreach ($shares as $i => $share)
@php($tx = 40 + $i * ($tileW + $tileGap))
@php($pays = (int) ($share['low'] ?? 0) === 0 ? 'opens with its rated play' : ((int) $share['low'] === (int) $share['high'] ? K::sats((int) $share['low']).' sats a win' : K::sats((int) $share['low']).'–'.K::sats((int) $share['high']).' sats a win'))
@php($paysSize = K::monoSize($pays, 18, $tileW - 40))
<rect x="{{ $tx }}" y="{{ $tileY }}" width="{{ $tileW }}" height="116" fill="#17171B" stroke="#2A2A30" stroke-width="2"/>
<rect x="{{ $tx }}" y="{{ $tileY }}" width="{{ $tileW }}" height="6" fill="#F7931A"/>
<text data-unit="share-label-{{ $i }}" data-box="{{ $tx + 19 }} {{ $tileY + 14 }} {{ $tx + $tileW - 16 }} {{ $tileY + 38 }}" x="{{ $tx + 20 }}" y="{{ $tileY + 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ K::fit(K::text($share, 'label'), K::MONO, 20, $tileW - 40) }}</text>
<text data-unit="share-{{ $i }}" data-box="{{ $tx + 18 }} {{ $tileY + 44 }} {{ $tx + $tileW - 16 }} {{ $tileY + 80 }}" x="{{ $tx + 20 }}" y="{{ $tileY + 76 }}" font-family="Unbounded" font-weight="800" font-size="32" fill="#F7931A">{{ (int) ($share['share'] ?? 0) }} %</text>
<text data-unit="share-pays-{{ $i }}" data-box="{{ $tx + 19 }} {{ $tileY + 88 }} {{ $tx + $tileW - 16 }} {{ $tileY + 108 }}" x="{{ $tx + 20 }}" y="{{ $tileY + 104 }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $paysSize }}" fill="#ADADB0">{{ K::fit($pays, K::MONO, $paysSize, $tileW - 40) }}</text>
@endforeach
<rect x="40" y="476" width="{{ $nextW }}" height="72" fill="#F7931A"/>
<text data-unit="next" data-box="48 484 {{ 36 + $nextW }} 540" x="60" y="525" font-family="Unbounded" font-weight="800" font-size="{{ $nextSize }}" fill="#17120A">{{ $next }}</text>
@if ($play !== '')<text data-unit="play" data-box="39 576 1016 602" x="40" y="596" font-family="JetBrains Mono" font-weight="700" font-size="{{ K::monoSize($playText, 20, 976) }}" fill="#FFFFFF">{{ $play }}</text>@endif
</svg>
