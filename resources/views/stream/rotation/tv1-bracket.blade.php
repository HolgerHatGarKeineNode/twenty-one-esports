{{--
    TV1 · a running tournament's bracket, as its tournament TV shows its "Bracket" scene (pages::tournaments.tv), in
    the TV frame (partials/tv-frame). The stage's title when there is more than one stage; then either the whole
    bracket as the TV draws it (`tv.tree`, TvSlides::tree(): upper over lower, the grand final on the right, every
    box lined to the box its winner goes to, lit once decided, live ones on the warm panel), or for a bracket too big
    for one slide the board (the section in play, up to three rounds from the current one), or a table stage's current round per group with its
    pairings two to a row (`tv.rounds`, "Group A", "Round 2"), or, for lobbies and heats, the board as the other
    live slides draw it (partials/t-board) in the TV's colours.

    Data contract: $tournament as TournamentLiveSlides builds it (running: board, tv, live, results, progress, cover,
    url) and $viewers. Every key may be missing (the scene tests render it with other phases too). $stats unused.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@use('App\Support\TwentyOne\Stream\TvSlides', 'Tv')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $board = is_array($t['board'] ?? null) ? $t['board'] : null;
    $tvData = is_array($t['tv'] ?? null) ? $t['tv'] : [];
    $rounds = array_values(array_filter((array) ($tvData['rounds'] ?? []), is_array(...)));
    $stage = is_string($tvData['stage'] ?? null) ? K::fit($tvData['stage'], K::DISPLAY, 19, 1204) : '';
    $top = Tv::Y0 + ($stage !== '' ? 34 : 0);
    $kind = $board['kind'] ?? null;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
@include('stream.rotation.partials.tv-frame', ['t' => $t, 'part' => 1])

@if ($stage !== '')<text data-unit="stage" x="38" y="{{ Tv::Y0 + 19 }}" font-family="Unbounded" font-weight="700" font-size="19" fill="{{ Tv::INK }}">{{ $stage }}</text>@endif

@if (is_array($tvData['tree'] ?? null))
@php $tr = Tv::tree($tvData['tree'], $top); @endphp
@foreach ($tr['lines'] as $line)
<path d="{{ $line['d'] }}" fill="none" stroke="{{ $line['lit'] ? Tv::BTC : Tv::LINE }}" stroke-width="1.6"/>
@endforeach
@foreach ($tr['titles'] as $ti => $title)
<text data-unit="section-{{ $ti }}" x="{{ $title['x'] }}" y="{{ $title['y'] }}" font-family="JetBrains Mono" font-size="16" fill="{{ Tv::INK2 }}">{{ K::fit($title['text'], K::MONO, 16, 400) }}</text>
@endforeach
@foreach ($tr['labels'] as $li => $label)
<text data-unit="col-{{ $li }}" x="{{ $label['x'] }}" y="{{ $label['y'] }}" font-family="JetBrains Mono" font-size="14" fill="{{ Tv::INK3 }}">{{ K::fit($label['text'], K::MONO, 14, $label['w']) }}</text>
@endforeach
@foreach ($tr['boxes'] as $bi => $b)
@include('stream.rotation.partials.tv-box', ['bx' => $b, 'box' => $b['box'], 'k' => $tr['k'], 'id' => 't'.$bi])
@endforeach
@elseif ($kind === 'bracket')
@php $br = Tv::bracket($board, $top + 22); @endphp
<text data-unit="section" x="38" y="{{ $top + 14 }}" font-family="JetBrains Mono" font-size="16" fill="{{ Tv::INK2 }}">{{ K::fit($board['title'] ?? '', K::MONO, 16, 1204) }}</text>
@foreach ($br['lines'] as $line)
<path d="{{ $line['d'] }}" fill="none" stroke="{{ $line['lit'] ? Tv::BTC : Tv::LINE }}" stroke-width="1.6"/>
@endforeach
@foreach ($br['columns'] as $ci => $column)
<text data-unit="col-{{ $ci }}" x="{{ $column['x'] }}" y="{{ $top + 40 }}" font-family="JetBrains Mono" font-size="16" fill="{{ Tv::INK2 }}">{{ K::fit($column['label'], K::MONO, 16, $column['w']) }}</text>
@foreach ($column['boxes'] as $bi => $b)
@include('stream.rotation.partials.tv-box', ['bx' => $b, 'box' => $b['box'], 'k' => $br['k'], 'id' => 'c'.$ci.'b'.$bi])
@endforeach
@endforeach
@elseif ($rounds !== [])
@php
    $shown = array_slice($rounds, 0, 4);
    $split = count($shown) > 1;
    $partCols = $split ? 2 : 1;
    $partRows = (int) ceil(count($shown) / $partCols);
    $partGap = 26;
    $partW = (1204 - ($partCols - 1) * $partGap) / $partCols;
    $partH = (Tv::Y1 - $top - ($partRows - 1) * 19) / $partRows;
@endphp
@foreach ($shown as $pi => $round)
@php
    $px = 38 + ($pi % $partCols) * ($partW + $partGap);
    $py = $top + intdiv($pi, $partCols) * ($partH + 19);
    $boxes = array_values(array_filter((array) ($round['boxes'] ?? []), is_array(...)));
    $byes = array_values(array_filter((array) ($round['byes'] ?? []), is_string(...)));
    $rows = max(1, (int) ceil(count($boxes) / 2));
    $k = Tv::k($rows);
    $bh = Tv::boxHeight($k);
    $gapY = 12.8 * $k;
    $fit = (int) max(1, floor(($partH - 64 + $gapY) / ($bh + $gapY)));
    $boxes = array_slice($boxes, 0, $fit * 2);
    $bw = ($partW - 26) / 2;
@endphp
<text data-unit="part-{{ $pi }}" x="{{ round($px, 1) }}" y="{{ $py + 19 }}" font-family="Unbounded" font-weight="700" font-size="19" fill="{{ Tv::INK }}">{{ K::fit($round['title'] ?? '', K::DISPLAY, 19, $partW) }}</text>
<text data-unit="round-{{ $pi }}" x="{{ round($px, 1) }}" y="{{ $py + 46 }}" font-family="JetBrains Mono" font-size="16" fill="{{ Tv::INK2 }}">Round {{ (int) ($round['round'] ?? 1) }}</text>
@foreach ($boxes as $bi => $box)
@include('stream.rotation.partials.tv-box', ['bx' => ['x' => round($px + ($bi % 2) * ($bw + 26), 1), 'y' => round($py + 64 + intdiv($bi, 2) * ($bh + $gapY), 1), 'w' => round($bw, 1), 'h' => $bh], 'box' => $box, 'k' => $k, 'id' => 'p'.$pi.'b'.$bi])
@endforeach
@foreach (array_slice($byes, 0, 1) as $bye)
@php $byeY = $py + 64 + (int) ceil(count($boxes) / 2) * ($bh + $gapY) + 16; @endphp
@if ($byeY < Tv::Y1)<text data-unit="bye-{{ $pi }}" x="{{ round($px, 1) }}" y="{{ round($byeY, 1) }}" font-family="JetBrains Mono" font-size="16" fill="{{ Tv::INK2 }}">{{ K::fit(K::clean($bye).' has a bye this round.', K::MONO, 16, $partW) }}</text>@endif
@endforeach
@endforeach
@elseif ($board !== null)
@include('stream.rotation.partials.t-board', ['board' => $board, 'bx' => 38, 'by' => $top, 'bw' => 1204, 'bh' => Tv::Y1 - $top, 'groupCols' => 2, 'clanSeats' => is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1,
    'panel' => Tv::PANEL, 'rule' => Tv::LINE, 'accent' => Tv::BTC, 'nameFill' => Tv::INK, 'muted' => Tv::INK3, 'chipInk' => Tv::ON_BTC, 'bShape' => 'round', 'bId' => 'tv1'])
@else
<text data-unit="empty" x="640" y="410" font-family="Unbounded" font-weight="700" font-size="31" fill="{{ Tv::INK }}" text-anchor="middle">The bracket appears here once it is drawn.</text>
@endif
</svg>
