{{--
    TB4 · Broadcast desk · a tournament past sign-up, its bracket as it stands, full width like a TV graphic. Channel
    frame (bug with the status and the round in play, ticker) over the game's blurred cover: the name and the matches
    played, then the current stage across the desk (partials/t-board: the bracket cropped around the live matches, the
    groups, or the table with its pairings).

    Data contract: $tournament as TournamentLiveSlides builds it (catalog-tournaments.md: name, phase, status, now,
    board, progress, standing, teamSize) and the backdrop ($backdrop); $stats for the ticker. Every key may be missing.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $finished = ($t['phase'] ?? null) === 'finished';
    $kind = is_array($t['board'] ?? null) ? ($t['board']['kind'] ?? null) : null;
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    $played = is_array($t['progress'] ?? null) && is_int($t['progress']['played'] ?? null) ? $t['progress']['played'] : null;
    $total = is_array($t['progress'] ?? null) && is_int($t['progress']['total'] ?? null) ? $t['progress']['total'] : null;
    $progress = $played !== null && $total !== null ? $played.' of '.$total.' decided' : '';
    $standing = $kind === 'bracket' && ! $finished && is_array($t['standing'] ?? null) && is_int($t['standing']['count'] ?? null) ? $t['standing']['count'].' still standing' : '';
    $right = implode(', ', array_filter([$standing, $progress]));
    $rightW = K::width($right, K::MONO, 18);
    $title = K::name(K::text($t, 'name'), 'Tournament', 30, 1160 - $rightW - 32);
    $firsts = K::sharedFirst($t);
    // A running lobby board (P8) names its time limit here; the lobbies decided are counted on the right.
    $note = K::text($t, 'status', 'Live now').', '.($finished ? match ($kind) { 'bracket' => 'final bracket', 'lobbies' => 'final places', default => 'final standings' }
        : ($kind === 'lobbies' && is_string($t['board']['limit'] ?? null) ? 'one lobby match, '.K::clean($t['board']['limit']) : mb_strtolower(K::text($t, 'now', K::text($t, 'format')))));
    // A finished lobby tournament (P10): its place 1 across all lobbies, under the board.
    // As many names as the line holds whole, the rest counted (P8: 40 players can share place 1 across five lobbies).
    $firstLabel = count($firsts) > 1 ? 'Shared 1st place: ' : '1st place: ';
    $firstLine = $firsts === [] ? '' : $firstLabel.(K::namesInLines($firsts, K::MONO, 18, 1200 - K::width($firstLabel, K::MONO, 18), 1)[0] ?? '');
    $groupCols = 4;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.74])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => K::fit($note, K::MONO, 18, 520)])

<text data-unit="title" data-box="39 104 {{ 1200 - $rightW - 31 }} 146" x="40" y="136" font-family="{{ $title['font'] }}" font-weight="800" font-size="30" fill="#FFFFFF">{{ $title['text'] }}</text>
@if ($right !== '')<text data-unit="progress" x="1240" y="134" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A" text-anchor="end">{{ $right }}</text>@endif
<rect x="40" y="152" width="1200" height="2" fill="#F7931A"/>
@if ($firstLine !== '')<text data-unit="first-line" data-box="39 628 1241 654" x="40" y="648" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $firstLine }}</text>@endif

@include('stream.rotation.partials.t-board', ['board' => $t['board'] ?? null, 'bx' => 40, 'by' => 172, 'bw' => 1200, 'bh' => 478, 'groupCols' => $groupCols, 'clanSeats' => $clanSeats,
    'panel' => '#121215', 'rule' => '#2A2A30', 'accent' => '#F7931A', 'nameFill' => '#FFFFFF', 'muted' => '#8B8B90', 'chipInk' => '#17120A', 'bShape' => 'round', 'bId' => 'tb4'])
</svg>
