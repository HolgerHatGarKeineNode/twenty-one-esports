{{--
    TC4 · Terminal ticker · a tournament past sign-up, its bracket as it stands. Header line ("live bracket") and stats
    bar as every C scene, over the game's blurred cover: the name, the status and round in play, the matches played,
    then the current stage (partials/t-board with square faces: the bracket cropped around the live matches, the
    groups, or the table with its pairings).

    Data contract: $tournament as TournamentLiveSlides builds it (catalog-tournaments.md: name, phase, status, now,
    board, progress, standing, teamSize) and the backdrop ($backdrop); $stats for the stats bar. Every key may be missing.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $finished = ($t['phase'] ?? null) === 'finished';
    $kind = is_array($t['board'] ?? null) ? ($t['board']['kind'] ?? null) : null;
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    $played = is_array($t['progress'] ?? null) && is_int($t['progress']['played'] ?? null) ? $t['progress']['played'] : null;
    $total = is_array($t['progress'] ?? null) && is_int($t['progress']['total'] ?? null) ? $t['progress']['total'] : null;
    $progress = $played !== null && $total !== null ? $played.'/'.$total.' decided' : '';
    $title = K::fit(K::text($t, 'name', 'Tournament'), K::MONO, 26, 1200 - K::width($progress, K::MONO, 18) - 32);
    $line = K::fit(K::text($t, 'status', 'Live now').': '.($finished ? ($kind === 'bracket' ? 'final bracket' : 'final standings') : K::text($t, 'now', K::text($t, 'format'))), K::MONO, 18, 1200);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.78])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => $finished ? 'final bracket' : 'live bracket'])

<text data-unit="title" data-box="39 {{ 110 - 26 }} {{ 1240 - K::width($progress, K::MONO, 18) - 31 }} {{ 110 + 8 }}" x="40" y="110" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#FFFFFF">{{ $title }}</text>
@if ($progress !== '')<text data-unit="progress" x="1240" y="108" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0" text-anchor="end">{{ $progress }}</text>@endif
<text data-unit="line" data-box="39 122 1241 148" x="40" y="140" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $line }}</text>
<rect x="40" y="156" width="1200" height="1" fill="#2A2A30"/>

@include('stream.rotation.partials.t-board', ['board' => $t['board'] ?? null, 'bx' => 40, 'by' => 168, 'bw' => 1200, 'bh' => 446, 'groupCols' => 4, 'clanSeats' => $clanSeats,
    'panel' => '#16161A', 'rule' => '#2A2A30', 'accent' => '#F7931A', 'nameFill' => '#FFFFFF', 'muted' => '#A1A1A7', 'chipInk' => '#17120A', 'bShape' => 'square', 'bId' => 'tc4'])
</svg>
