{{--
    S1 · Terminal ticker · season: the countdown to Block 0 (SeasonSlides::COUNTDOWN). Header line and stats bar as c3.
    The clock in fixed digit cells as the hero (it ticks every frame without jitter), then what starts: the season's
    name and supply, and one tile per share with what a win pays there. Without a planned date the clock gives way to
    "Block 0 soon." and never shows a made-up time; once the date has passed and the board has not pressed the button
    yet, "Any moment now." (never a frozen or negative clock).

    Data contract:
      $season    array{clock: 'countdown'|'due'|'soon'|null, countdown: ?string, name: string, supply: int, eras: int,
                 shares: list<array{label: string, share: int, low: int, high: int}>, …} (SeasonSlides::frameSeason())
      $stats     array: the stats bar counts (c-chrome)
      $backdrop  ?string, optional: the brand backdrop
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $s = is_array($season ?? null) ? $season : [];
    $clock = $s['clock'] ?? 'soon';
    $name = K::text($s, 'name', 'The season');
    $cd = $clock === 'countdown' ? K::countdownParts($s['countdown'] ?? null) : null;
    $cells = null;
    if ($cd) {
        $cdText = ($cd['days'] > 0 ? $cd['days'].'d ' : '').$cd['hms'];
        // The clock as big as it fits x 40..1016.
        foreach ([120, 108, 96, 84] as $cdSize) {
            $cells = K::digitCells($cdText, 40, $cdSize);
            if ($cells['x1'] <= K::CONTENT_RIGHT) {
                break;
            }
        }
    }
    $label = K::fit(match (true) {
        $cd !== null => 'Block 0 of '.$name.' in',
        $clock === 'due' => 'Block 0 of '.$name.' is due',
        default => 'Block 0 of '.$name,
    }, K::MONO, 24, 976);
    $supply = (int) ($s['supply'] ?? 0);
    $shares = array_slice(array_values(array_filter((array) ($s['shares'] ?? []), 'is_array')), 0, 4);
    $tileGap = 16;
    $tileW = $shares === [] ? 0 : (K::CONTENT_RIGHT - 40 - $tileGap * (count($shares) - 1)) / count($shares);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.8])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'season · block 0'])
<text data-unit="label" data-box="39 110 1016 138" x="40" y="132" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#A1A1A7">{{ $label }}</text>
@if ($cells)
<g data-countdown="{{ $s['countdown'] }}">@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 262, 'fill' => '#F7931A'])</g>
@elseif ($clock === 'due')
<text data-unit="due" data-box="38 170 1016 268" x="40" y="250" font-family="Unbounded" font-weight="800" font-size="84" fill="#F7931A">Any moment now.</text>
@else
<text data-unit="soon" data-box="38 170 1016 268" x="40" y="250" font-family="Unbounded" font-weight="800" font-size="84" fill="#FFFFFF">Block 0 soon.</text>
@endif
@if ($supply > 0)
<text data-unit="supply" data-box="39 300 1016 348" x="40" y="340" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">{{ K::sats($supply) }} sats</text>
<text data-unit="supply-line" data-box="39 356 1016 384" x="40" y="378" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">{{ K::fit('to mine in '.$name.'. Each group halves by what it mined.', K::MONO, 22, 976) }}</text>
@endif
@foreach ($shares as $i => $share)
@php($tx = 40 + $i * ($tileW + $tileGap))
@php($pays = (int) ($share['low'] ?? 0) === 0 ? 'opens with its rated play' : ((int) $share['low'] === (int) $share['high'] ? K::sats((int) $share['low']).' sats a win' : K::sats((int) $share['low']).'–'.K::sats((int) $share['high']).' sats a win'))
<rect x="{{ $tx }}" y="412" width="{{ $tileW }}" height="148" fill="#17171B" stroke="#2A2A30" stroke-width="2"/>
<rect x="{{ $tx }}" y="412" width="{{ $tileW }}" height="6" fill="#F7931A"/>
<text data-unit="share-label-{{ $i }}" data-box="{{ $tx + 19 }} 432 {{ $tx + $tileW - 16 }} 460" x="{{ $tx + 20 }}" y="454" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ K::fit(K::text($share, 'label'), K::MONO, 22, $tileW - 40) }}</text>
<text data-unit="share-{{ $i }}" data-box="{{ $tx + 18 }} 470 {{ $tx + $tileW - 16 }} 514" x="{{ $tx + 20 }}" y="510" font-family="Unbounded" font-weight="800" font-size="40" fill="#F7931A">{{ (int) ($share['share'] ?? 0) }} %</text>
<text data-unit="share-pays-{{ $i }}" data-box="{{ $tx + 19 }} 528 {{ $tx + $tileW - 16 }} 548" x="{{ $tx + 20 }}" y="544" font-family="JetBrains Mono" font-weight="700" font-size="{{ K::monoSize($pays, 18, $tileW - 40) }}" fill="#ADADB0">{{ K::fit($pays, K::MONO, K::monoSize($pays, 18, $tileW - 40), $tileW - 40) }}</text>
@endforeach
<text data-unit="foot" data-box="39 580 1016 604" x="40" y="598" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ K::fit('Rated wins between Trusted players mine. Paid once, after the season review.', K::MONO, 20, 976) }}</text>
</svg>
