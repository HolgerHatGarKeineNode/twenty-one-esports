{{--
    B3 · Broadcast desk · one move a day. Channel frame over the backdrop; the pitch on the left, naming every game
    with a daily mode (chess, and nine men's morris and checkers while they are on); on the right a live daily chess
    game (when there is one: the player to move with face, name and clock, the opponent below) and the daily leaders:
    with two or more correspondence ladders the leader of each (face, game, name, Elo), else the top three of daily
    chess, each with a face, the leader crowned.

    Data contract:
      $stats       array{ladders?: array{daily?: list<Row>}, boards?: list<Board>, …} (Row and Board as in a3-ladders)
                   + the ticker counts
      $dailyGames  list<string>, optional: the games with a daily mode, in order; missing = chess only
      $dailyGame   Game|null, optional: a live daily (correspondence) game to show; null = card left out
      $backdrop   ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $g = $dailyGame ?? null;
    $card = null;
    if (is_array($g)) {
        $whiteMoves = (bool) ($g['white']['toMove'] ?? false);
        [$mover, $other] = $whiteMoves ? [$g['white'] ?? [], $g['black'] ?? []] : [$g['black'] ?? [], $g['white'] ?? []];
        $moveNo = max(1, (int) (explode(' ', trim((string) ($g['fen'] ?? '')))[5] ?? 1));
        $clock = K::clock((int) ($mover['clockMs'] ?? 0), 868, 40, 356);
        $card = [
            'b' => K::board($g['fen'] ?? '', $g['lastMove'] ?? null, 684, 140, 20),
            'head' => 'Move '.$moveNo,
            'mover' => K::name($mover['name'] ?? '', $whiteMoves ? 'White player' : 'Black player', 20, 300),
            'moverFace' => $mover['avatar'] ?? null, 'otherFace' => $other['avatar'] ?? null,
            'clock' => $clock,
            'other' => K::fit($other['name'] ?? '', K::MONO, 16, 320) ?: ($whiteMoves ? 'Black player' : 'White player'),
        ];
    }
    $leaders = K::dailyLeaders($stats ?? [], 3);
    $byGame = count($leaders) >= 2;
    $rows = $byGame ? array_column($leaders, 'row') : K::ladder($stats ?? [], 'daily', 3);
    $faces = $byGame ? array_column(array_column($leaders, 'row'), 'avatar') : K::ladderAvatars($stats ?? [], 'daily', 3);
    $labels = $byGame ? array_column($leaders, 'game') : [];
    $ly = $card ? 348 : 140;
    $games = K::listing($dailyGames ?? ['Chess'], 3);
    $pitch = $games === 'Chess' || $games === '' ? 'Chess, up to 24 hours for each move.' : $games.'.';
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? []])

<text x="40" y="330" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">One move a day.</text>
{{-- COPY-CHECK: "up to 24 hours for each move" confirmed in copy-check.md (a): app/Games/Chess.php:34 '1/86400', deadline reset per move. --}}
<text data-unit="daily-games" data-box="39 358 640 386" x="40" y="380" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">{{ K::fit($pitch, K::MONO, 22, 600) }}</text>
@if ($pitch !== 'Chess, up to 24 hours for each move.')<text x="40" y="414" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Up to 24 hours for each move.</text>@endif
<text x="40" y="600" font-family="Unbounded" font-weight="800" font-size="24" fill="#F7931A">Start a daily game</text>
{{-- COPY-CHECK: per copy-check.md (b): a daily game starts with a challenge that the other player accepts. --}}
<text x="40" y="630" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">Log in and challenge any player.</text>

@if ($card)
<rect x="664" y="120" width="576" height="200" fill="#121215" fill-opacity="0.92"/>
@include('stream.rotation.partials.board', ['b' => $card['b'], 'frame' => 4])
<text x="868" y="152" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">{{ $card['head'] }}</text>
@include('stream.rotation.partials.face', ['face' => ['uri' => $card['moverFace'], 'tag' => null], 'x' => 868, 'y' => 164, 'd' => 44, 'id' => 'mover', 'fUnit' => 'face-mover', 'fRing' => '#F7931A'])
<text data-unit="mover" data-box="923 170 1224 204" x="924" y="193" font-family="{{ $card['mover']['font'] }}" font-weight="800" font-size="20" fill="#FFFFFF">{{ $card['mover']['text'] }}</text>
@include('stream.rotation.partials.clock', ['c' => $card['clock'], 'y' => 256, 'fill' => '#F7931A'])
@include('stream.rotation.partials.face', ['face' => ['uri' => $card['otherFace'], 'tag' => null], 'x' => 868, 'y' => 274, 'd' => 28, 'id' => 'other', 'fUnit' => 'face-other', 'fRing' => '#6B6B72'])
<text data-unit="other" data-box="905 278 1224 302" x="906" y="294" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">{{ $card['other'] }}</text>
@endif

<text x="664" y="{{ $ly }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">{{ $byGame ? 'Daily leaders' : 'Daily ladder' }}</text>
@if ($byGame)
@foreach ($rows as $i => $r)
@php($ry = $ly + 16 + $i * 58)
<rect x="664" y="{{ $ry }}" width="576" height="54" fill="#121215"/>
<rect x="664" y="{{ $ry }}" width="6" height="54" fill="#F7931A"/>
@include('stream.rotation.partials.face', ['face' => ['uri' => $faces[$i] ?? null, 'tag' => null], 'x' => 686, 'y' => $ry + 7, 'd' => 40, 'id' => 'lead-'.$i,
    'fUnit' => 'lead-face-'.$i, 'fRing' => '#F7931A'])
@php($nm = K::name($r['name'], 'Player', 20, 330))
<text data-unit="lead-name-{{ $i }}" data-box="737 {{ $ry + 4 }} 1080 {{ $ry + 30 }}" x="738" y="{{ $ry + 25 }}" font-family="{{ $nm['font'] }}" font-weight="800" font-size="20" fill="#FFFFFF">{{ $nm['text'] }}</text>
<text data-unit="lead-game-{{ $i }}" data-box="737 {{ $ry + 34 }} 1080 {{ $ry + 50 }}" x="738" y="{{ $ry + 46 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#ADADB0">{{ K::fit($labels[$i] ?? '', K::MONO, 14, 330) }}</text>
<text data-unit="lead-elo-{{ $i }}" data-box="1088 {{ $ry + 8 }} 1225 {{ $ry + 46 }}" x="1224" y="{{ $ry + 35 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A" text-anchor="end">{{ $r['elo'] }} Elo</text>
@endforeach
@else
@forelse ($rows as $i => $r)
@php($ry = $ly + 16 + $i * 58)
<rect x="664" y="{{ $ry }}" width="576" height="54" fill="{{ $i === 0 ? '#F7931A' : '#121215' }}"/>
<text x="680" y="{{ $ry + 37 }}" font-family="Unbounded" font-weight="800" font-size="24" fill="{{ $i === 0 ? '#17120A' : '#FFFFFF' }}">{{ $i + 1 }}</text>
@include('stream.rotation.partials.face', ['face' => ['uri' => $faces[$i] ?? null, 'tag' => null], 'x' => 716, 'y' => $ry + 7, 'd' => 40, 'id' => 'lad-'.$i,
    'fUnit' => 'lad-face-'.$i, 'fRing' => $i === 0 ? '#17120A' : '#F7931A', 'fCrown' => $i === 0 ? '#17120A' : null])
@php($nm = K::name($r['name'], 'Player', 20, 304))
<text data-unit="lad-name-{{ $i }}" data-box="767 {{ $ry + 8 }} 1080 {{ $ry + 46 }}" x="768" y="{{ $ry + 35 }}" font-family="{{ $nm['font'] }}" font-weight="800" font-size="20" fill="{{ $i === 0 ? '#17120A' : '#FFFFFF' }}">{{ $nm['text'] }}</text>
<text data-unit="lad-elo-{{ $i }}" data-box="1088 {{ $ry + 8 }} 1225 {{ $ry + 46 }}" x="1224" y="{{ $ry + 35 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $i === 0 ? '#17120A' : '#FFFFFF' }}" text-anchor="end">{{ $r['elo'] }} Elo</text>
@empty
<text x="664" y="{{ $ly + 44 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">No daily games yet. Start the first one.</text>
@endforelse
@endif
</svg>
