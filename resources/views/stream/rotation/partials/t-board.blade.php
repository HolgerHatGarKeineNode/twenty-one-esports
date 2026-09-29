{{--
    The current stage of a tournament past sign-up (TournamentLiveSlides `board`) inside ($bx, $by, $bw, $bh):
    - kind 'bracket': partials/t-bracket (RotationKit::bracketLayout), and under it the crop note of a big round;
    - kind 'groups': one box per group (RotationKit::groupsLayout, $groupCols across): rank, face, name, points, the
      places that go through with an $accent rank;
    - kind 'table': the table on the left (rank, face, name, record W-D-L, points) and the current round's pairings on
      the right;
    - anything else (heats, no stage yet): the line that the bracket comes with the first match.
    Style per look as partials/t-bracket ($panel, $rule, $accent, $nameFill, $muted, $chipInk, $bShape, $bId), plus
    $clanSeats (a team tournament's faces are clans).
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $kind = is_array($board ?? null) ? ($board['kind'] ?? null) : null;
    $clan = $clanSeats ?? false;
    $L = $kind === 'bracket' ? K::bracketLayout($board, $bx, $by, $bw, $bh - 26, 30, 40, 28, 32, $clan) : null;
    $G = $kind === 'groups' ? K::groupsLayout($board, $bx, $by, $bw, $bh - 26, $groupCols ?? 4, 16, 30, 36, 24, $clan) : null;
    $tableW = round($bw * 0.58);
    $rowPitch = 38;
    $rows = $kind === 'table' ? K::tableRows($board, $by + 34, $rowPitch, 28, 18, max(1, min(8, (int) floor(($bh - 60) / $rowPitch))), $clan) : [];
    $pairs = $kind === 'table' ? K::pairings($board['pairings'] ?? [], 6, $clan) : [];
    $note = '';
    if ($L) {
        foreach ($L['columns'] as $c) {
            if ($c['note'] !== '') {
                $note = $c['label'].': '.$c['note'];
                break;
            }
        }
    }
@endphp
@if ($L && $L['columns'] !== [])
@include('stream.rotation.partials.t-bracket', ['L' => $L])
@if ($note !== '')<text data-unit="board-note" data-box="{{ $bx - 1 }} {{ $by + $bh - 22 }} {{ $bx + $bw + 1 }} {{ $by + $bh + 2 }}" x="{{ $bx }}" y="{{ $by + $bh - 4 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $muted }}">{{ K::fit($note, K::MONO, 16, $bw) }}</text>@endif
@elseif ($G && $G['boxes'] !== [])
@foreach ($G['boxes'] as $gi => $g)
<rect x="{{ $g['x'] }}" y="{{ $g['y'] }}" width="{{ $g['w'] }}" height="{{ $g['h'] }}" fill="{{ $panel }}"/>
<text data-unit="g-{{ $gi }}-title" data-box="{{ $g['x'] }} {{ $g['y'] }} {{ $g['x'] + $g['w'] }} {{ $g['y'] + 40 }}" x="{{ $g['x'] + 10 }}" y="{{ $g['titleY'] }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $accent }}">{{ K::fit($g['title'], K::MONO, 16, $g['w'] - 20) }}</text>
@foreach ($g['rows'] as $ri => $r)
@php($nameX = $g['x'] + 34 + $G['fd'] + 8)
<text data-unit="g-{{ $gi }}-rank-{{ $ri }}" x="{{ $g['x'] + 26 }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $G['size'] }}" fill="{{ $r['through'] ? $accent : $muted }}" text-anchor="end">{{ $r['rank'] }}</text>
@include('stream.rotation.partials.face', ['face' => $r['face'], 'x' => $g['x'] + 34, 'y' => $r['top'], 'd' => $G['fd'], 'id' => $bId.'-g'.$gi.'-'.$ri, 'fUnit' => 'g-'.$gi.'-face-'.$ri, 'fShape' => $bShape ?? 'round', 'fRing' => $r['through'] ? $accent : null])
<text data-unit="g-{{ $gi }}-name-{{ $ri }}" data-box="{{ $nameX - 1 }} {{ $g['y'] }} {{ $g['x'] + $g['w'] - 36 }} {{ $g['y'] + $g['h'] }}" x="{{ $nameX }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $G['size'] }}" fill="{{ $r['through'] ? $nameFill : $muted }}">{{ K::fit($r['name'] === '' ? 'Player' : $r['name'], K::MONO, $G['size'], $g['x'] + $g['w'] - 40 - $nameX) }}</text>
<text data-unit="g-{{ $gi }}-pts-{{ $ri }}" x="{{ $g['x'] + $g['w'] - 10 }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $G['size'] }}" fill="{{ $nameFill }}" text-anchor="end">{{ $r['points'] }}</text>
@endforeach
@endforeach
@if ($G['hidden'] > 0)<text data-unit="board-note" x="{{ $bx }}" y="{{ $by + $bh - 4 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $muted }}">+{{ $G['hidden'] }} more {{ $G['hidden'] === 1 ? 'group' : 'groups' }}</text>@endif
@elseif ($rows !== [])
<text data-unit="t-head" x="{{ $bx }}" y="{{ $by + 18 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $muted }}">Table</text>
<text data-unit="t-head-rec" data-box="{{ $bx + $tableW - 134 }} {{ $by }} {{ $bx + $tableW - 72 }} {{ $by + 24 }}" x="{{ $bx + $tableW - 76 }}" y="{{ $by + 18 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $muted }}" text-anchor="end">W-D-L</text>
<text data-unit="t-head-pts" data-box="{{ $bx + $tableW - 70 }} {{ $by }} {{ $bx + $tableW + 1 }} {{ $by + 24 }}" x="{{ $bx + $tableW }}" y="{{ $by + 18 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $muted }}" text-anchor="end">Points</text>
@foreach ($rows as $ri => $r)
@php($nameX = $bx + 36 + 28 + 10)
<rect x="{{ $bx }}" y="{{ $by + 34 + $ri * $rowPitch }}" width="{{ $tableW }}" height="{{ $rowPitch - 4 }}" fill="{{ $panel }}"/>
<text data-unit="t-rank-{{ $ri }}" x="{{ $bx + 26 }}" y="{{ $r['y'] - 2 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $ri === 0 ? $accent : $muted }}" text-anchor="end">{{ $r['rank'] }}</text>
@include('stream.rotation.partials.face', ['face' => $r['face'], 'x' => $bx + 36, 'y' => $r['top'] - 2, 'd' => 28, 'id' => $bId.'-t'.$ri, 'fUnit' => 't-face-'.$ri, 'fShape' => $bShape ?? 'round', 'fRing' => $ri === 0 ? $accent : null])
<text data-unit="t-name-{{ $ri }}" data-box="{{ $nameX - 1 }} {{ $by + 34 + $ri * $rowPitch }} {{ $bx + $tableW - 140 }} {{ $by + 30 + ($ri + 1) * $rowPitch }}" x="{{ $nameX }}" y="{{ $r['y'] - 2 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $nameFill }}">{{ K::fit($r['name'] === '' ? 'Player' : $r['name'], K::MONO, 18, $tableW - 140 - ($nameX - $bx)) }}</text>
<text data-unit="t-rec-{{ $ri }}" data-box="{{ $bx + $tableW - 134 }} {{ $by + 34 + $ri * $rowPitch }} {{ $bx + $tableW - 72 }} {{ $by + 30 + ($ri + 1) * $rowPitch }}" x="{{ $bx + $tableW - 76 }}" y="{{ $r['y'] - 2 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $muted }}" text-anchor="end">{{ $r['record'] }}</text>
<text data-unit="t-pts-{{ $ri }}" data-box="{{ $bx + $tableW - 70 }} {{ $by + 34 + $ri * $rowPitch }} {{ $bx + $tableW + 1 }} {{ $by + 30 + ($ri + 1) * $rowPitch }}" x="{{ $bx + $tableW - 10 }}" y="{{ $r['y'] - 2 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $nameFill }}" text-anchor="end">{{ $r['points'] }}</text>
@endforeach
@php($px = $bx + $tableW + 32)
@php($pw = $bw - $tableW - 32)
<text data-unit="p-head" x="{{ $px }}" y="{{ $by + 18 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $accent }}">{{ is_int($board['round'] ?? null) ? 'Round '.$board['round'] : 'Pairings' }}</text>
@foreach ($pairs as $pi => $pr)
@php($py = $by + 34 + $pi * 60)
<rect x="{{ $px }}" y="{{ $py }}" width="{{ $pw }}" height="52" fill="{{ $panel }}"@if ($pr['live']) stroke="{{ $accent }}" stroke-width="2"@endif/>
<text data-unit="p-{{ $pi }}-a" data-box="{{ $px + 9 }} {{ $py }} {{ $px + $pw - 55 }} {{ $py + 26 }}" x="{{ $px + 10 }}" y="{{ $py + 21 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $nameFill }}">{{ K::fit($pr['a'], K::MONO, 16, $pw - 66) }}</text>
<text data-unit="p-{{ $pi }}-b" data-box="{{ $px + 9 }} {{ $py + 26 }} {{ $px + $pw - 55 }} {{ $py + 52 }}" x="{{ $px + 10 }}" y="{{ $py + 43 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $nameFill }}">{{ K::fit($pr['b'], K::MONO, 16, $pw - 66) }}</text>
<text data-unit="p-{{ $pi }}-s" x="{{ $px + $pw - 10 }}" y="{{ $py + 32 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $pr['live'] ? $accent : $muted }}" text-anchor="end">{{ $pr['score'] }}</text>
@endforeach
@else
<text data-unit="no-board" x="{{ $bx }}" y="{{ $by + 40 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $nameFill }}">The bracket appears with the first match.</text>
@endif
