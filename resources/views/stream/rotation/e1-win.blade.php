{{--
    E1 · Broadcast desk · pride: the latest win, in any game. Channel frame over the brand backdrop; the winner's face
    big with a crown on the left (a team: its clan's logo, else its tag, with the players' faces under it), on the
    right the label, the winner's name, who they beat (a series with its score), the game and when, then the proof as
    orange chips: the Elo it won (casual or season ladder) and the season block it mined. Without a decided result: an
    invitation to be the first winner.

    Data contract:
      $pride     array{win: array{winner: string, winnerAvatar: ?string, winnerLogo?: ?string, winnerTag?: ?string,
                 teamAvatars?: list<?string>, loser: string, mode: string, shownMode?: string (a series: the game's short title and mode, printed instead of mode), score?: ?string, delta: ?int, ratedDelta?: ?int,
                 block?: ?int, ago: ?string, tournament?: ?string}|null, …} (PrideSlides::all());
                 `tournament`: the tournament the win won its winner, in the label instead of "Latest win"
      $stats     array: the ticker counts (b-chrome)
      $backdrop  ?string, optional: the brand backdrop, as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $win = is_array($pride['win'] ?? null) ? $pride['win'] : null;
    if ($win) {
        // A long name steps down in size before it loses letters (a clan's name often is long).
        $head = K::headline(is_string($win['winner'] ?? null) ? $win['winner'] : '', [64, 52, 44], 740, 1);
        $name = ($head['lines'][0] ?? '') === '' ? ['text' => 'Player', 'font' => K::DISPLAY, 'size' => 64.0] : ['text' => $head['lines'][0], 'font' => $head['font'], 'size' => $head['size']];
        $score = K::clean(is_string($win['score'] ?? null) ? $win['score'] : '');
        $beat = K::fit('beat '.K::clean($win['loser'] ?? '').($score !== '' ? ' '.$score : ''), K::MONO, 30, 740);
        $when = K::fit(implode(', ', array_filter([K::clean($win['shownMode'] ?? $win['mode'] ?? ''), K::clean($win['ago'] ?? '')])), K::MONO, 24, 740);
        $chips = array_values(array_filter([
            is_int($win['ratedDelta'] ?? null) && $win['ratedDelta'] > 0 ? '+'.$win['ratedDelta'].' season Elo' : (is_int($win['delta'] ?? null) && $win['delta'] > 0 ? '+'.$win['delta'].' casual Elo' : null),
            is_int($win['block'] ?? null) && $win['block'] > 0 ? 'Mined block '.$win['block'] : null,
        ]));
        // The largest size at which every chip fits the 780 px column, 16 px apart.
        foreach ([40, 34, 28, 24] as $size) {
            $total = 0;
            foreach ($chips as $chip) {
                $total += K::width($chip, K::DISPLAY, $size) * 1.04 + 40 + 16;
            }
            if ($total - 16 <= 780) {
                break;
            }
        }
        $chipX = 460;
        $chipRows = [];
        foreach ($chips as $ci => $chip) {
            $w = K::width($chip, K::DISPLAY, $size) * 1.04 + 40;
            $chipRows[] = ['x' => $chipX, 'w' => $w, 'text' => $chip, 'size' => $size, 'dark' => $ci > 0];
            $chipX += $w + 16;
        }
        // The winner's picture: a player's face, else a team's clan logo (whole) or its tag tile.
        $logo = K::avatarUri($win['winnerLogo'] ?? null);
        $face = K::avatarUri($win['winnerAvatar'] ?? null) !== null || ($logo === null && ! is_string($win['winnerTag'] ?? null))
            ? ['uri' => $win['winnerAvatar'] ?? null, 'tag' => null, 'fit' => 'slice']
            : ['uri' => $logo, 'tag' => K::clanTag((string) ($win['winnerTag'] ?? ''), (string) ($win['winner'] ?? '')), 'fit' => 'meet'];
        $team = array_slice(array_values(array_filter(is_array($win['teamAvatars'] ?? null) ? $win['teamAvatars'] : [], 'is_string')), 0, 4);
        $tournament = K::clean($win['tournament'] ?? '');
        $label = $tournament !== '' ? K::fit('Tournament win: '.$tournament, K::MONO, 26, 780) : 'Latest win';
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.76])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => 'latest win'])
@if ($win)
<radialGradient id="win-glow"><stop offset="0.5" stop-color="#F7931A" stop-opacity="0.5"/><stop offset="1" stop-color="#F7931A" stop-opacity="0"/></radialGradient>
<circle cx="230" cy="{{ $team ? 350 : 380 }}" r="230" fill="url(#win-glow)"/>
@include('stream.rotation.partials.face', ['face' => $face, 'x' => 80, 'y' => $team ? 200 : 230, 'd' => 300, 'id' => 'winner', 'fUnit' => 'winner-face', 'fRing' => '#F7931A', 'fCrown' => '#F7931A', 'fGround' => '#17120A', 'fShape' => $face['fit'] === 'meet' ? 'square' : 'round'])
@foreach ($team as $ti => $mate)
@include('stream.rotation.partials.face', ['face' => ['uri' => $mate, 'tag' => null], 'x' => 230 - count($team) * 36 + $ti * 72 + 4, 'y' => 540, 'd' => 64, 'id' => 'mate-'.$ti, 'fUnit' => 'mate-'.$ti, 'fRing' => '#F7931A'])
@endforeach
<text data-unit="winner-label" data-box="459 196 1240 226" x="460" y="220" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#F7931A">{{ $label }}</text>
<text data-unit="winner-name" data-box="459 250 1240 318" x="460" y="304" font-family="{{ $name['font'] }}" font-weight="800" font-size="{{ $name['size'] }}" fill="#FFFFFF">{{ $name['text'] }}</text>
<text data-unit="winner-beat" data-box="459 336 1240 372" x="460" y="364" font-family="JetBrains Mono" font-weight="700" font-size="30" fill="#FFFFFF">{{ $beat }}</text>
@if ($when !== '')<text data-unit="winner-when" data-box="459 390 1240 420" x="460" y="414" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#ADADB0">{{ $when }}</text>@endif
@foreach ($chipRows as $ci => $chip)
<rect x="{{ $chip['x'] }}" y="456" width="{{ $chip['w'] }}" height="76" fill="{{ $chip['dark'] ? '#17120A' : '#F7931A' }}" @if ($chip['dark'])stroke="#F7931A" stroke-width="3"@endif/>
<text data-unit="winner-chip-{{ $ci }}" data-box="{{ $chip['x'] + 8 }} 466 {{ $chip['x'] + $chip['w'] - 4 }} 522" x="{{ $chip['x'] + 20 }}" y="{{ 494 + $chip['size'] * 0.36 }}" font-family="Unbounded" font-weight="800" font-size="{{ $chip['size'] }}" fill="{{ $chip['dark'] ? '#F7931A' : '#17120A' }}">{{ $chip['text'] }}</text>
@endforeach
<text x="460" y="600" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Win one and you are next on this screen.</text>
@else
<text x="40" y="300" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">No winner yet.</text>
<text x="40" y="370" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#ADADB0">Win the first game and your name is on this screen.</text>
@endif
</svg>
