{{--
    TV3 · the standings, as the tournament TV shows its "Standings" scene (pages::tournaments.tv), in the TV frame
    (partials/tv-frame): up to four tables two to a row, each its title and its rows on panels (rank, orange when
    through; avatar; name; "Through"; wins–draws–losses; points), the legend under them. A tournament without a table
    (a bracket throughout, which the TV shows no standings for) gets who is still standing instead, in the same
    language: the count and the players with their avatars.

    Data contract: $tournament as TournamentLiveSlides builds it (running: tv.tables, standing, teamSize, results) and
    $viewers. Every key may be missing.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@use('App\Support\TwentyOne\Stream\TvSlides', 'Tv')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $tvData = is_array($t['tv'] ?? null) ? $t['tv'] : [];
    $tables = \App\Support\TwentyOne\Stream\TvSlides::tables((array) ($tvData['tables'] ?? []));
    $standing = is_array($t['standing'] ?? null) ? $t['standing'] : null;
    $teams = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
@include('stream.rotation.partials.tv-frame', ['t' => $t, 'part' => 3])

@if ($tables !== [])
@php
    $cols = min(2, count($tables));
    $tRows = (int) ceil(count($tables) / $cols);
    $colW = (1204 - ($cols - 1) * 38) / $cols;
    $blockH = (Tv::Y1 - Tv::Y0 - ($tRows - 1) * 19) / $tRows;
@endphp
@foreach ($tables as $ti => $table)
@php
    $tx = 38 + ($ti % $cols) * ($colW + 38);
    $ty = Tv::Y0 + intdiv($ti, $cols) * ($blockH + 19);
    $rows = array_values(array_filter((array) $table['rows'], is_array(...)));
    $k = Tv::k(count($rows), 6);
    $fs = round(24 * $k, 1);
    $rowH = round(max($fs * 1.2 * 1.2, $fs * 1.4) + $fs * 0.6, 1);
    $gapY = round(6.4 * $k, 1);
    $room = $blockH - 32 - 30;
    $fit = (int) max(1, floor(($room + $gapY) / ($rowH + $gapY)));
    $rows = array_slice($rows, 0, $fit);
    $faceD = round($fs * 1.2, 1);
@endphp
<text data-unit="table-{{ $ti }}" x="{{ round($tx, 1) }}" y="{{ $ty + 19 }}" font-family="Unbounded" font-weight="700" font-size="19" fill="{{ Tv::INK }}">{{ K::fit($table['title'] ?? '', K::DISPLAY, 19, $colW) }}</text>
@foreach ($rows as $ri => $row)
@php
    $ry = $ty + 32 + $ri * ($rowH + $gapY);
    $cy = $ry + $rowH / 2;
    $through = ($row['through'] ?? false) === true;
    $right = $tx + $colW - $fs * 0.5;
    $pointsX = $right;
    $record = str_replace('-', '–', (string) ($row['record'] ?? ''));
    $recordX = $pointsX - $fs * 2.4 - $fs * 0.5;
    $recordW = K::width($record, K::MONO, $fs);
    $throughW = $through ? K::width('Through', K::MONO, 16) + 20 : 0;
    $throughX = $recordX - $recordW - $fs * 0.5 - $throughW;
    $rankX = $tx + $fs * 0.5 + $fs * 1.4;
    $faceX = $rankX + $fs * 0.5;
    $nameX = $faceX + $faceD + $fs * 0.5;
    $nameRoom = ($through ? $throughX : $recordX - $recordW) - $fs * 0.5 - $nameX;
    $nm = K::name($row['name'] ?? '', 'Player', $fs, $nameRoom);
@endphp
<rect x="{{ round($tx, 1) }}" y="{{ round($ry, 1) }}" width="{{ round($colW, 1) }}" height="{{ $rowH }}" rx="5" fill="{{ Tv::PANEL }}"/>
<text data-unit="rank-{{ $ti }}-{{ $ri }}" x="{{ round($rankX, 1) }}" y="{{ round($cy + $fs * 0.36, 1) }}" font-family="Unbounded" font-weight="700" font-size="{{ $fs }}" fill="{{ $through ? Tv::BTC : Tv::INK2 }}" text-anchor="end">{{ (int) ($row['rank'] ?? 0) }}</text>
@include('stream.rotation.partials.face', ['face' => K::face($row, $teams), 'x' => round($faceX, 1), 'y' => round($cy - $faceD / 2, 1), 'd' => $faceD, 'id' => 'tv3-'.$ti.'-'.$ri, 'fShape' => 'square', 'fGround' => Tv::RAISED, 'fGlyph' => '#4A4A52', 'fUnit' => 'row-face-'.$ti.'-'.$ri])
<text data-unit="row-name-{{ $ti }}-{{ $ri }}" x="{{ round($nameX, 1) }}" y="{{ round($cy + $fs * 0.36, 1) }}" font-family="{{ $nm['font'] }}" font-weight="500" font-size="{{ $fs }}" fill="{{ Tv::INK }}">{{ $nm['text'] }}</text>
@if ($through)
<path d="M{{ round($throughX + 2, 1) }} {{ round($cy + 3, 1) }}l5 -5l5 5" fill="none" stroke="{{ Tv::BTC_HI }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
<text data-unit="through-{{ $ti }}-{{ $ri }}" x="{{ round($throughX + 18, 1) }}" y="{{ round($cy + 5.5, 1) }}" font-family="JetBrains Mono" font-size="16" fill="{{ Tv::BTC_HI }}">Through</text>
@endif
<text data-unit="record-{{ $ti }}-{{ $ri }}" x="{{ round($recordX, 1) }}" y="{{ round($cy + $fs * 0.36, 1) }}" font-family="JetBrains Mono" font-size="{{ $fs }}" fill="{{ Tv::INK2 }}" text-anchor="end">{{ $record }}</text>
<text data-unit="points-{{ $ti }}-{{ $ri }}" x="{{ round($pointsX, 1) }}" y="{{ round($cy + $fs * 0.36, 1) }}" font-family="Unbounded" font-weight="800" font-size="{{ $fs }}" fill="{{ Tv::INK }}" text-anchor="end">{{ K::clean((string) ($row['points'] ?? '')) }}</text>
@endforeach
<text data-unit="legend-{{ $ti }}" x="{{ round($tx, 1) }}" y="{{ round($ty + 32 + count($rows) * ($rowH + $gapY) + 16, 1) }}" font-family="JetBrains Mono" font-size="16" fill="{{ Tv::INK3 }}">Wins, draws, losses and points</text>
@endforeach
@elseif ($standing !== null)
@php
    $faces = array_values(array_filter((array) ($standing['faces'] ?? []), is_array(...)));
    $count = is_int($standing['count'] ?? null) ? $standing['count'] : count($faces);
    $of = is_int($standing['of'] ?? null) ? $standing['of'] : null;
    $word = $teams ? ($of === 1 ? 'team' : 'teams') : ($of === 1 ? 'player' : 'players');
    $line = $of !== null ? $count.' of '.$of.' '.$word.' still in it' : $count.' still in it';
    $perRow = 4;
    $gridTop = Tv::Y0 + 80;
    $rowsN = max(1, (int) ceil(count($faces) / $perRow));
    $pitch = min(96, (Tv::Y1 - $gridTop) / $rowsN);
    $faceD = round(min(64, $pitch - 16));
    $cellW = 1204 / $perRow;
@endphp
<text data-unit="standing-title" x="38" y="{{ Tv::Y0 + 19 }}" font-family="Unbounded" font-weight="700" font-size="19" fill="{{ Tv::INK }}">Still standing</text>
<text data-unit="standing-count" x="38" y="{{ Tv::Y0 + 54 }}" font-family="JetBrains Mono" font-size="19" fill="{{ Tv::INK2 }}">{{ $line }}</text>
@foreach ($faces as $fi => $entry)
@php
    $fx = 38 + ($fi % $perRow) * $cellW;
    $fy = $gridTop + intdiv($fi, $perRow) * $pitch;
    $nm = K::name($entry['name'] ?? '', 'Player', 19, $cellW - $faceD - 10 - 19);
@endphp
@include('stream.rotation.partials.face', ['face' => K::face($entry, $teams), 'x' => round($fx, 1), 'y' => round($fy, 1), 'd' => $faceD, 'id' => 'tv3-s'.$fi, 'fShape' => 'square', 'fGround' => Tv::RAISED, 'fGlyph' => '#4A4A52', 'fUnit' => 'standing-face-'.$fi])
<text data-unit="standing-name-{{ $fi }}" x="{{ round($fx + $faceD + 10, 1) }}" y="{{ round($fy + $faceD / 2 + 7, 1) }}" font-family="{{ $nm['font'] }}" font-weight="500" font-size="19" fill="{{ Tv::INK }}">{{ $nm['text'] }}</text>
@endforeach
@endif
</svg>
