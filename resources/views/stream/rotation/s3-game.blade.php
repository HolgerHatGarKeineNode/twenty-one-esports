{{--
    S3 · Terminal ticker · season: one game that mines (SeasonSlides::GAME), the frame of d6. Left the game's cover
    edged in its colour, under it its latest block and who mined it (never a voided one) and its blocks this season;
    before Block 0 the countdown instead. Right its share, name and era, what a win pays each winner per mode now, the
    distance to its group's next halving in sats and wins, and the game's own path (the site's QR stands in the
    now-playing column). The rules line
    at the bottom says who mines and when it is paid.

    Data contract:
      $game       array{name: string, group: string, grouped: bool, share: int, era: ?int, eras: int, closed: bool,
                  last: bool, to_halving: int, wins: int, winsLabel: string, modes: list<array{label: string, pays: int}>,
                  colour: string, url: string, cover: ?string, blocks: int, latest: array{height: int, perPlayer: int,
                  ago: string, miners: list<array{name: string, avatar: ?string}>}|null}|null (SeasonSlides::scene())
      $season     SeasonSlides::frameSeason(): `phase` (pre: the countdown under the cover), `clock`, `countdown`
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: the game's blurred cover, else the brand's
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    // The cover and the block x 40..488, the text x 528..1016, the QR x 896..1016 (the now-playing column covers x 1040..1280).
    $g = is_array($game ?? null) ? $game : null;
    $s = is_array($season ?? null) ? $season : [];
    $pre = ($s['phase'] ?? '') === 'pre';
    $accent = K::colour($g['colour'] ?? null, '#F7931A');
    $cover = K::coverUri($g['cover'] ?? null);
    $gameName = K::text($g ?? [], 'name', 'This game');
    $title = K::headline($gameName, [48, 44, 40, 34], 488, 1);
    $shareLine = K::fit(($g['grouped'] ?? false ? K::text($g, 'group').' · ' : '').(int) ($g['share'] ?? 0).' % of the supply', K::MONO, 20, 488);
    $era = is_int($g['era'] ?? null) ? $g['era'] : null;
    $eraLine = $era === null ? 'Mined out' : 'Era '.$era.' of '.(int) ($g['eras'] ?? 0).(($g['last'] ?? false) ? ', the last' : '');
    $modes = array_slice(array_values(array_filter((array) ($g['modes'] ?? []), 'is_array')), 0, 3);
    $modesY = 300;
    $halvingY = $modesY + count($modes) * 44 + 8;
    $sats = K::sats((int) ($g['to_halving'] ?? 0));
    $wins = (int) ($g['wins'] ?? 0);
    $winsText = $wins > 0 ? ', about '.K::plural($wins, K::text($g, 'winsLabel').' win', K::text($g, 'winsLabel').' wins').' at this reward' : '';
    $halvingText = match (true) {
        $era === null => 'Every sat of this group is mined. It pays no more blocks this season.',
        (bool) ($g['last'] ?? false) => 'Mined out after '.$sats.' more sats'.$winsText.'.',
        default => 'Next halving in '.$sats.' sats'.$winsText.'.',
    };
    $halving = K::wrap($halvingText, K::MONO, 18, 488, 3);
    $url = K::text($g ?? [], 'url', 'esports.einundzwanzig.space');
    // The site's QR stands in the now-playing column already; here the game's own path takes its room.
    $urlMax = 488;
    $urlSize = K::monoSize($url, 22, $urlMax);
    $latest = is_array($g['latest'] ?? null) ? $g['latest'] : null;
    if ($latest) {
        $miners = K::prideRows($latest['miners'] ?? [], 3);
        $miner = $miners[0] ?? null;
        foreach ([28, 24, 20] as $nameSize) {
            $minerName = K::name($miner['name'] ?? '', 'A player', $nameSize, 360);
            if ($minerName['text'] === K::clean($miner['name'] ?? '')) {
                break;
            }
        }
        $minerSub = K::fit(implode(' · ', array_filter([
            count($miners) > 1 ? 'with '.K::listing(array_column(array_slice($miners, 1), 'name'), 2) : null,
            (int) ($latest['perPlayer'] ?? 0) > 0 ? '+'.K::sats((int) $latest['perPlayer']).' sats' : null,
            K::clean($latest['ago'] ?? ''),
        ])), K::MONO, 18, 360);
    }
    $blocks = (int) ($g['blocks'] ?? 0);
    $blocksLine = K::fit(K::plural($blocks, 'block', 'blocks').' mined in '.$gameName.' this season', K::MONO, 18, 448);
    $cd = $pre && ($s['clock'] ?? null) === 'countdown' ? K::countdownParts($s['countdown'] ?? null) : null;
    $cells = $cd ? K::digitCells(($cd['days'] > 0 ? $cd['days'].'d ' : '').$cd['hms'], 40, 44) : null;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.82])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => $pre ? 'season · mines from block 0' : 'season · mining now'])
@if ($g)
<g data-unit="cover" data-box="40 112 489 365">
<rect x="40" y="112" width="448" height="252" fill="{{ $accent }}"/>
<rect x="46" y="118" width="436" height="240" fill="#1C1C21"/>
@if ($cover)<image x="46" y="118" width="436" height="240" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>@endif
</g>
@if ($pre)
<text data-unit="block0-label" data-box="39 388 489 410" x="40" y="406" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">{{ $cells ? 'Block 0 in' : 'Block 0' }}</text>
@if ($cells)
<g data-countdown="{{ $s['countdown'] }}">@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 460, 'fill' => '#F7931A'])</g>
@else
<text data-unit="block0" data-box="38 420 489 464" x="40" y="458" font-family="Unbounded" font-weight="800" font-size="36" fill="{{ ($s['clock'] ?? null) === 'due' ? '#F7931A' : '#FFFFFF' }}">{{ ($s['clock'] ?? null) === 'due' ? 'Any moment now.' : 'Soon.' }}</text>
@endif
<text data-unit="block0-line" data-box="39 486 489 510" x="40" y="504" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">Every block is still up for grabs.</text>
@elseif ($latest)
<text data-unit="latest-label" data-box="39 388 489 410" x="40" y="406" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">{{ K::fit('Latest block · height '.(int) $latest['height'], K::MONO, 18, 448) }}</text>
@include('stream.rotation.partials.face', ['face' => K::prideFace($miner), 'x' => 40, 'y' => 424, 'd' => 72, 'id' => 'season-miner', 'fUnit' => 'miner-face', 'fRing' => $accent])
<text data-unit="miner-name" data-box="127 430 489 470" x="128" y="462" font-family="{{ $minerName['font'] }}" font-weight="800" font-size="{{ $nameSize }}" fill="#FFFFFF">{{ $minerName['text'] }}</text>
@if ($minerSub !== '')<text data-unit="miner-sub" data-box="127 474 489 498" x="128" y="492" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $minerSub }}</text>@endif
<text data-unit="blocks" data-box="39 524 489 548" x="40" y="542" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $blocksLine }}</text>
@else
<text data-unit="none" data-box="38 392 489 434" x="40" y="426" font-family="Unbounded" font-weight="800" font-size="{{ K::headline('No block yet.', [30], 448, 1)['size'] }}" fill="#FFFFFF">No block yet.</text>
<text data-unit="none-line" data-box="39 452 489 476" x="40" y="470" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ K::fit('The first '.$gameName.' block is up for grabs.', K::MONO, 20, 448) }}</text>
@endif

<text data-unit="share" data-box="527 114 1016 140" x="528" y="134" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#A1A1A7">{{ $shareLine }}</text>
<text data-unit="title" data-box="526 {{ 190 - $title['size'] }} 1016 {{ 190 + $title['size'] * 0.3 }}" x="528" y="190" font-family="{{ $title['font'] }}" font-weight="800" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $title['lines'][0] ?? '' }}</text>
<text data-unit="era" data-box="527 212 1016 240" x="528" y="234" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="{{ $accent }}">{{ $eraLine }}</text>
<rect x="528" y="254" width="488" height="1" fill="#2A2A30"/>
<text data-unit="modes-label" data-box="527 264 1016 284" x="528" y="280" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1A7">{{ $pre ? 'from Block 0, a win pays each winner' : 'a win pays each winner now' }}</text>
@foreach ($modes as $mi => $mode)
@php($my = $modesY + $mi * 44 + 28)
<text data-unit="mode-{{ $mi }}" data-box="527 {{ $my - 20 }} 760 {{ $my + 4 }}" x="528" y="{{ $my }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ K::fit(K::text($mode, 'label'), K::MONO, 22, 232) }}</text>
<text data-unit="mode-pays-{{ $mi }}" data-box="760 {{ $my - 26 }} 1016 {{ $my + 4 }}" x="1016" y="{{ $my }}" font-family="Unbounded" font-weight="800" font-size="26" fill="#F7931A" text-anchor="end">{{ K::sats((int) ($mode['pays'] ?? 0)) }} sats</text>
@if ($mi < count($modes) - 1)<rect x="528" y="{{ $my + 16 }}" width="488" height="1" fill="#2A2A30"/>@endif
@endforeach
@foreach ($halving as $li => $line)
<text data-unit="halving-{{ $li }}" data-box="527 {{ $halvingY + 16 + $li * 24 }} 1016 {{ $halvingY + 38 + $li * 24 }}" x="528" y="{{ $halvingY + 34 + $li * 24 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $line }}</text>
@endforeach
<text x="528" y="540" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1A7">play</text>
<text data-unit="url" data-box="527 550 {{ 528 + $urlMax }} 576" x="528" y="570" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#FFFFFF">{{ K::fit($url, K::MONO, $urlSize, $urlMax) }}</text>
<text data-unit="rules" data-box="39 584 1016 606" x="40" y="602" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">Rated wins between Trusted players mine. Paid once, after the season review.</text>
@else
<text x="40" y="200" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">Season mining</text>
<text x="40" y="264" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF">Rated wins between Trusted players mine blocks.</text>
@endif
</svg>
