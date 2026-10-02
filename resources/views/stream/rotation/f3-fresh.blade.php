{{--
    F3 · Terminal ticker · Blockfill's fresh blocks (BlockfillSlides::FRESH): the week's latest runs as blocks, newest
    left, the way the mempool strip shows them. Top left the game's mark (its cover, partials/game-mark), beside it
    "Blockfill Week N, YYYY" and the headline with the news: the newest run that holds first place ("New #1: Ada
    0:48.120"), else the newest personal best, else "Fresh blocks". The run that holds first place is a mined block
    (orange, lit top) and the only one that says "New #1"; a #1 beaten since says "Was #1" (graphite, orange top); a
    personal best is graphite with an orange edge; any other verified run graphite; a run the league has not replayed
    yet a dashed block that says "unconfirmed" and never its time. Under each block the player's avatar, name and how
    long ago.

    Fewer than four runs leave no empty seats: the runs and one more block share the row, and that block is the call
    to play, an unmined grey block with the game page's QR code and the time to beat (or the call to set the first
    one). With no run at all, the call fills the row.

    Data contract (SceneSource, BlockfillSlides::scene()):
      $blockfill  array{title: string, goal: int (the week's blocks), fresh: list<array{name: string, time: ?string, waiting: bool, badge: 'top'|'was'|'best'|null,
                    when: string, avatar: ?string}>, leader: array{name: string, time: string}|null, url: string, …}|null
                  null while Blockfill is switched off: the slide invites to every game instead
      $siteQrSvg  string: the QR code of the game page ('' leaves it out)
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: Blockfill's blurred cover, else the brand's
      $cover      ?string, optional: Blockfill's cover as a data URI, the game's mark
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = is_array($blockfill ?? null) ? $blockfill : null;
    $runs = [];
    foreach (array_slice(is_array($b['fresh'] ?? null) ? $b['fresh'] : [], 0, 4) as $run) {
        if (is_array($run)) {
            $runs[] = $run;
        }
    }
    // No closures in a stream view (they leak per render without the CLI opcache): plain loops.
    $news = null;
    foreach (['top', 'best'] as $wanted) {
        foreach ($runs as $run) {
            if ($news === null && ($run['badge'] ?? null) === $wanted && is_string($run['time'] ?? null)) {
                $news = $run;
            }
        }
    }
    // The news keeps its time whole: only the name gives way ("New #1: Mühlenmeister Groß… 0:47.833").
    $headW = 984;
    $newsLead = ($news['badge'] ?? null) === 'top' ? 'New #1: ' : 'Personal best: ';
    $newsTime = $news === null ? '' : ' '.K::clean(K::text($news, 'time'));
    $newsName = $news === null ? '' : K::clean(K::text($news, 'name'));
    $newsFont = K::nameFont($newsLead.$newsName.$newsTime);
    // A size down (44 to 36) before the name gives way.
    $newsFit = '';
    $newsSize = 44;
    foreach ([44, 36] as $newsSize) {
        $newsFit = K::fit($newsName, $newsFont, $newsSize, $headW - K::width($newsLead.$newsTime, $newsFont, $newsSize) * 1.04);
        if ($newsFit === $newsName) {
            break;
        }
    }
    $headline = match (true) {
        $runs === [] => 'No blocks this week yet.',
        $news === null => 'Fresh blocks',
        default => $newsLead.($newsFit ?: 'Player').$newsTime,
    };
    $head = ['text' => $headline, 'font' => $news === null ? K::DISPLAY : $newsFont, 'size' => $news === null ? 44 : $newsSize];
    $week = K::fit('Blockfill '.(preg_replace('/^Blockfill\s*/', '', K::text($b ?? [], 'title')) ?: 'this week'), K::MONO, 22, 560);
    $lead = $runs === [] ? '' : 'A run counts once the league has replayed it.';

    // The row: four runs, or the runs and the call, sharing 1200 px (each block 36 px apart, the last one's side 16 px).
    $cy = 268;
    $h = 180;
    $dd = 16;
    $gap = 36;
    $slots = min(4, count($runs) + 1);
    $w = floor((1200 - $dd - ($slots - 1) * $gap) / $slots);
    $callAt = count($runs) < 4 ? count($runs) : null;

    // The call: the time to beat, the game page.
    $leader = is_array($b['leader'] ?? null) ? $b['leader'] : null;
    $best = $leader ? K::clean(K::text($leader, 'time')) : '';
    $callTime = $best !== '' ? 'Beat '.$best : 'Set the first time.';
    $url = K::text($b ?? [], 'url', 'esports.einundzwanzig.space/blockfill');
    $qrSize = $h - 32;
    // Wide (one run or none): the whole call inside the block, right of the QR code.
    $callWide = $w >= 560;
    $callTextX = $qrSize + 40;
    $callTextW = $w - $callTextX - 24;
    $callUrlSize = K::width($url, K::MONO, 18) <= $callTextW ? 18 : 16;
    // A whole sentence or the short one, never a cut one.
    $mine = 'Mine '.max(1, (int) ($b['goal'] ?? 40)).' blocks.';
    $callLine = K::width($mine.' Your best run counts.', K::MONO, 20) <= $callTextW ? $mine.' Your best run counts.' : $mine;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.86])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'blockfill'])

@if ($b)
@include('stream.rotation.partials.game-mark', ['gmCover' => $cover ?? null, 'gmX' => 40, 'gmY' => 104, 'gmW' => 192, 'gmEdge' => '#F7931A'])
<text data-unit="week" data-box="255 112 817 138" x="256" y="132" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#A1A1A7">{{ $week }}</text>
<text data-unit="headline" data-box="254 146 1241 200" x="256" y="188" font-family="{{ $head['font'] }}" font-weight="800" font-size="{{ $head['size'] }}" fill="#FFFFFF">{{ $head['text'] }}</text>
@if ($lead !== '')
<text data-unit="lead" data-box="255 202 1241 226" x="256" y="220" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $lead }}</text>
@endif

@for ($i = 0; $i < $slots; $i++)
@php
    $run = $runs[$i] ?? null;
    $x = 40 + $i * ($w + $gap);
    $top = "M{$x} {$cy}L".($x + $dd).' '.($cy - $dd).'H'.($x + $w + $dd).'L'.($x + $w)." {$cy}Z";
    $side = 'M'.($x + $w)." {$cy}L".($x + $w + $dd).' '.($cy - $dd).'V'.($cy + $h - $dd).'L'.($x + $w).' '.($cy + $h).'Z';
    $waiting = $run !== null && (($run['waiting'] ?? false) === true || ! is_string($run['time'] ?? null));
    $badge = $run === null || $waiting ? null : ($run['badge'] ?? null);
    $ink = $badge === 'top' ? '#17120A' : '#FFFFFF';
    $label = match (true) {
        $run === null => null,
        $waiting => 'not replayed yet',
        $badge === 'top' => 'New #1',
        $badge === 'was' => 'Was #1',
        $badge === 'best' => 'Personal best',
        default => 'Verified',
    };
    $labelInk = match ($badge) { 'top' => '#17120A', 'was', 'best' => '#F7931A', default => '#A1A1A7' };
@endphp
@if ($i === $callAt)
{{-- The call: an unmined block, the next one to mine, with the game page's QR code. --}}
<g data-unit="call">
<path d="{{ $side }}" fill="#1F1F24"/>
<path d="{{ $top }}" fill="#4A4A52"/>
<rect x="{{ $x }}" y="{{ $cy }}" width="{{ $w }}" height="{{ $h }}" fill="#2E2E35"/>
</g>
@include('stream.rotation.partials.qr', ['qr' => $siteQrSvg ?? '', 'x' => $x + 16, 'y' => $cy + 16, 'size' => $qrSize])
@if ($callWide)
<text data-unit="call-time" data-box="{{ $x + $callTextX - 1 }} {{ $cy + 34 }} {{ $x + $w - 15 }} {{ $cy + 76 }}" x="{{ $x + $callTextX }}" y="{{ $cy + 68 }}" font-family="Unbounded" font-weight="800" font-size="32" fill="#FFFFFF">{{ K::fit($callTime, K::DISPLAY, 32, $callTextW) }}</text>
<text data-unit="call-line" data-box="{{ $x + $callTextX - 1 }} {{ $cy + 90 }} {{ $x + $w - 15 }} {{ $cy + 114 }}" x="{{ $x + $callTextX }}" y="{{ $cy + 108 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $callLine }}</text>
<text data-unit="call-url" data-box="{{ $x + $callTextX - 1 }} {{ $cy + 132 }} {{ $x + $w - 15 }} {{ $cy + 156 }}" x="{{ $x + $callTextX }}" y="{{ $cy + 150 }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $callUrlSize }}" fill="#FFFFFF">{{ K::fit($url, K::MONO, $callUrlSize, $callTextW) }}</text>
@else
@if ($w - $callTextX - 16 >= K::width('Scan to play', K::MONO, 18))
<text data-unit="call-line" data-box="{{ $x + $callTextX - 1 }} {{ $cy + 16 }} {{ $x + $w - 15 }} {{ $cy + 40 }}" x="{{ $x + $callTextX }}" y="{{ $cy + 36 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">Scan to play</text>
@endif
<text data-unit="call-time" data-box="{{ $x - 1 }} {{ $cy + $h + 34 }} {{ $x + $w + $dd }} {{ $cy + $h + 62 }}" x="{{ $x }}" y="{{ $cy + $h + 56 }}" font-family="Unbounded" font-weight="800" font-size="22" fill="#FFFFFF">{{ K::fit($callTime, K::DISPLAY, 22, $w + $dd) }}</text>
@endif
@elseif ($run !== null)
<g data-unit="block-{{ $i }}">
@if ($waiting)
<path d="{{ $side }}" fill="none" stroke="#63636A" stroke-width="2" stroke-dasharray="6 4"/>
<path d="{{ $top }}" fill="none" stroke="#63636A" stroke-width="2" stroke-dasharray="6 4"/>
<rect x="{{ $x + 1 }}" y="{{ $cy + 1 }}" width="{{ $w - 2 }}" height="{{ $h - 2 }}" fill="#141417" fill-opacity="0.85" stroke="#63636A" stroke-width="2" stroke-dasharray="6 4"/>
@elseif ($badge === 'top')
<path d="{{ $side }}" fill="#B9640A"/>
<path d="{{ $top }}" fill="#FFC27A"/>
<rect x="{{ $x }}" y="{{ $cy }}" width="{{ $w }}" height="{{ $h }}" fill="#F7931A"/>
@else
<path d="{{ $side }}" fill="#141417"/>
<path d="{{ $top }}" fill="{{ $badge === 'was' ? '#B9640A' : '#34343C' }}"/>
<rect x="{{ $x }}" y="{{ $cy }}" width="{{ $w }}" height="{{ $h }}" fill="#1C1C21"/>
@if ($badge === 'best')<rect x="{{ $x + 1 }}" y="{{ $cy + 1 }}" width="{{ $w - 2 }}" height="{{ $h - 2 }}" fill="none" stroke="#F7931A" stroke-width="2"/>@endif
@endif
</g>
<text data-unit="block-label-{{ $i }}" data-box="{{ $x + 15 }} {{ $cy + 16 }} {{ $x + $w - 15 }} {{ $cy + 40 }}" x="{{ $x + 16 }}" y="{{ $cy + 36 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $waiting ? '#A1A1A7' : $labelInk }}">{{ $label }}</text>
@if ($waiting)
<text data-unit="block-time-{{ $i }}" data-box="{{ $x + 15 }} {{ $cy + 86 }} {{ $x + $w - 15 }} {{ $cy + 116 }}" x="{{ $x + 16 }}" y="{{ $cy + 110 }}" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#ADADB0">unconfirmed</text>
@else
<text data-unit="block-time-{{ $i }}" data-box="{{ $x + 15 }} {{ $cy + 72 }} {{ $x + $w - 15 }} {{ $cy + 118 }}" x="{{ $x + 16 }}" y="{{ $cy + 114 }}" font-family="Unbounded" font-weight="800" font-size="36" fill="{{ $ink }}">{{ K::fit(K::text($run, 'time'), K::DISPLAY, 36, $w - 32) }}</text>
@endif
<text data-unit="block-when-{{ $i }}" data-box="{{ $x + 15 }} {{ $cy + 146 }} {{ $x + $w - 15 }} {{ $cy + 168 }}" x="{{ $x + 16 }}" y="{{ $cy + 164 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $badge === 'top' ? '#17120A' : '#A1A1A7' }}">{{ K::fit(K::text($run, 'when'), K::MONO, 16, $w - 32) }}</text>
@php($name = K::name(K::text($run, 'name'), 'Player', 22, $w + $dd - 64))
@include('stream.rotation.partials.face', ['face' => K::prideFace($run), 'x' => $x, 'y' => $cy + $h + 24, 'd' => 48, 'id' => 'bf3-'.$i, 'fUnit' => 'block-face-'.$i, 'fRing' => $badge === 'top' ? '#F7931A' : null])
<text data-unit="block-name-{{ $i }}" data-box="{{ $x + 63 }} {{ $cy + $h + 34 }} {{ $x + $w + $dd }} {{ $cy + $h + 62 }}" x="{{ $x + 64 }}" y="{{ $cy + $h + 56 }}" font-family="{{ $name['font'] }}" font-weight="800" font-size="22" fill="#FFFFFF">{{ $name['text'] }}</text>
@endif
@endfor
@else
<text data-unit="title" data-box="38 150 1241 210" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">Every game</text>
<text data-unit="claim" data-box="39 240 1241 270" x="40" y="262" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Every game of the league is on the site.</text>
@endif
</svg>
