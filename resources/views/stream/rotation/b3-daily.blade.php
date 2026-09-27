{{--
    B3 · Broadcast desk · daily chess teaser. Channel frame; the pitch on the left, on the right a live daily game
    (when there is one) and the top three of the daily ladder.

    Data contract:
      $stats      array{ladders?: array{daily?: list<Row>}, …} (Row as in a3-ladders) + the ticker counts
      $dailyGame  Game|null, optional: a live daily (correspondence) game to show; null = card left out
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $g = $dailyGame ?? null;
    $card = null;
    if (is_array($g)) {
        $whiteMoves = (bool) ($g['white']['toMove'] ?? false);
        [$mover, $other] = $whiteMoves ? [$g['white'] ?? [], $g['black'] ?? []] : [$g['black'] ?? [], $g['white'] ?? []];
        $moveNo = max(1, (int) (explode(' ', trim((string) ($g['fen'] ?? '')))[5] ?? 1));
        $clock = K::clock((int) ($mover['clockMs'] ?? 0), 868, 36, 356);
        $card = [
            'b' => K::board($g['fen'] ?? '', $g['lastMove'] ?? null, 684, 140, 20),
            'head' => 'Live, move '.$moveNo,
            'mover' => K::name($mover['name'] ?? '', $whiteMoves ? 'White player' : 'Black player', 20, 356),
            'clock' => $clock,
            'other' => ($o = K::fit($other['name'] ?? '', K::MONO, 16, 356 - 17 * 9.6)) !== '' ? 'to move, against '.$o : 'to move',
        ];
        $wp = K::fit($g['white']['name'] ?? '', K::MONO, 22, 560) ?: 'White player';
        $bp = K::fit($g['black']['name'] ?? '', K::MONO, 22, 560 - 8 * 13.2) ?: 'Black player';
    }
    $rows = K::ladder($stats ?? [], 'daily', 3);
    $ly = $card ? 348 : 140;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? []])

<text x="40" y="190" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">One move a day.</text>
{{-- COPY-CHECK: "up to 24 hours for each move" confirmed in copy-check.md (a): app/Games/Chess.php:34 '1/86400', deadline reset per move. --}}
<text x="40" y="244" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Daily chess gives you up to 24 hours</text>
<text x="40" y="277" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">for each move. Play it from your phone,</text>
<text x="40" y="310" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">between two meetings.</text>
@if ($card)
<text x="40" y="370" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">Right now:</text>
<text data-unit="now-white" data-box="39 382 600 408" x="40" y="403" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $wp }}</text>
<text data-unit="now-black" data-box="39 415 600 441" x="40" y="436" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">against {{ $bp }}</text>
@endif
<text x="40" y="600" font-family="Unbounded" font-weight="800" font-size="24" fill="#F7931A">Start a daily game</text>
{{-- COPY-CHECK: per copy-check.md (b): a daily game starts with a challenge that the other player accepts. --}}
<text x="40" y="630" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">Log in and challenge any player.</text>

@if ($card)
<rect x="664" y="120" width="576" height="200" fill="#121215"/>
@include('stream.rotation.partials.board', ['b' => $card['b'], 'frame' => 4])
<text x="868" y="162" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">{{ $card['head'] }}</text>
<text data-unit="mover" data-box="867 174 1224 204" x="868" y="198" font-family="{{ $card['mover']['font'] }}" font-weight="800" font-size="20" fill="#FFFFFF">{{ $card['mover']['text'] }}</text>
@include('stream.rotation.partials.clock', ['c' => $card['clock'], 'y' => 250, 'fill' => '#F7931A'])
<text data-unit="other" data-box="867 270 1224 294" x="868" y="288" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">{{ $card['other'] }}</text>
@endif

<text x="664" y="{{ $ly }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">Daily ladder, casual Elo</text>
@forelse ($rows as $i => $r)
@php($ry = $ly + 16 + $i * 58)
<rect x="664" y="{{ $ry }}" width="576" height="54" fill="{{ $i === 0 ? '#F7931A' : '#121215' }}"/>
<text x="680" y="{{ $ry + 37 }}" font-family="Unbounded" font-weight="800" font-size="24" fill="{{ $i === 0 ? '#17120A' : '#FFFFFF' }}">{{ $i + 1 }}</text>
@php($nm = K::name($r['name'], 'Player', 20, 344))
<text data-unit="lad-name-{{ $i }}" data-box="727 {{ $ry + 8 }} 1080 {{ $ry + 46 }}" x="728" y="{{ $ry + 35 }}" font-family="{{ $nm['font'] }}" font-weight="800" font-size="20" fill="{{ $i === 0 ? '#17120A' : '#FFFFFF' }}">{{ $nm['text'] }}</text>
<text data-unit="lad-elo-{{ $i }}" data-box="1088 {{ $ry + 8 }} 1225 {{ $ry + 46 }}" x="1224" y="{{ $ry + 35 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $i === 0 ? '#17120A' : '#FFFFFF' }}" text-anchor="end">{{ $r['elo'] }} Elo</text>
@empty
<text x="664" y="{{ $ly + 44 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">No daily games yet. Start the first one.</text>
@endforelse
</svg>
