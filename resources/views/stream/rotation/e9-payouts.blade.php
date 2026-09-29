{{--
    E9 · Terminal ticker · pride: the season paid its miners. Header line and stats bar as c3; the latest season with
    paid payouts (SeasonPayout, status paid): the sats it paid as the big number, to how many players, then its three
    biggest payouts as a table (rank, face, player, blocks, sats), the first in orange and crowned. Without a paid
    season: how a block turns into sats, as an invitation.

    Data contract:
      $pride     array{payouts: array{season: string, total: int, players: int, rows: list<array{name: string,
                 avatar: ?string, sats: int, blocks: int}>}|null, …} (PrideSlides::all())
      $stats     array: the stats bar counts (c-chrome)
      $backdrop  ?string, optional: the brand backdrop
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $paid = is_array($pride['payouts'] ?? null) && is_int($pride['payouts']['total'] ?? null) && $pride['payouts']['total'] > 0 ? $pride['payouts'] : null;
    if ($paid) {
        $total = K::sats($paid['total']);
        $totalW = K::width($total, K::DISPLAY, 96) * 1.04;
        $players = is_int($paid['players'] ?? null) ? $paid['players'] : 0;
        $season = K::clean($paid['season'] ?? '');
        $rows = [];
        foreach (K::prideRows($paid['rows'] ?? [], 3) as $i => $row) {
            $rows[] = [
                // The payout's own rank: a name the fonts cannot draw drops its row, it never renumbers the others.
                'rank' => $rank = is_int($row['rank'] ?? null) ? $row['rank'] : $i + 1,
                'y' => 424 + $i * 58, 'lead' => $rank === 1, 'face' => K::prideFace($row),
                'name' => K::fit($row['name'], K::MONO, 22, 560),
                'blocks' => is_int($row['blocks'] ?? null) ? (string) $row['blocks'] : '',
                'sats' => is_int($row['sats'] ?? null) ? K::sats($row['sats']) : '',
            ];
        }
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.8])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'season payouts'])
@if ($paid)
<text data-unit="paid-season" data-box="39 110 1240 138" x="40" y="132" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#A1A1A7">{{ K::fit($season !== '' ? ucfirst($season).' paid its miners' : 'The season paid its miners', K::MONO, 24, 1200) }}</text>
<text data-unit="paid-total" data-box="38 150 {{ 44 + $totalW }} 266" x="40" y="244" font-family="Unbounded" font-weight="800" font-size="96" fill="#F7931A">{{ $total }}</text>
<text x="{{ 64 + $totalW }}" y="244" font-family="JetBrains Mono" font-weight="700" font-size="32" fill="#F7931A">sats</text>
<text data-unit="paid-players" data-box="39 276 1240 304" x="40" y="298" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF">to {{ K::plural($players, 'player', 'players') }}, one block at a time.</text>
<text x="40" y="376" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">#</text>
<text x="112" y="376" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">player</text>
<text x="820" y="376" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">blocks</text>
<text x="1240" y="376" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7" text-anchor="end">sats</text>
<rect x="40" y="384" width="1200" height="1" fill="#2A2A30"/>
@foreach ($rows as $ri => $r)
@php($ink = $r['lead'] ? '#F7931A' : '#FFFFFF')
<text x="40" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $ink }}">{{ $r['rank'] }}</text>
@include('stream.rotation.partials.face', ['face' => $r['face'], 'x' => 68, 'y' => $r['y'] - 30, 'd' => 38, 'id' => 'paid-'.$ri, 'fUnit' => 'paid-face-'.$ri, 'fShape' => 'square', 'fRing' => $r['lead'] ? '#F7931A' : '#6B6B72', 'fCrown' => $r['lead'] ? '#F7931A' : null])
<text data-unit="paid-name-{{ $ri }}" data-box="111 {{ $r['y'] - 22 }} 700 {{ $r['y'] + 6 }}" x="112" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $ink }}">{{ $r['name'] }}</text>
@if ($r['blocks'] !== '')<text data-unit="paid-blocks-{{ $ri }}" data-box="819 {{ $r['y'] - 22 }} 960 {{ $r['y'] + 6 }}" x="820" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $ink }}">{{ $r['blocks'] }}</text>@endif
@if ($r['sats'] !== '')<text data-unit="paid-sats-{{ $ri }}" data-box="980 {{ $r['y'] - 22 }} 1241 {{ $r['y'] + 6 }}" x="1240" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $ink }}" text-anchor="end">{{ $r['sats'] }}</text>@endif
<rect x="40" y="{{ $r['y'] + 18 }}" width="1200" height="1" fill="#2A2A30"/>
@endforeach
@else
<text x="40" y="200" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">No season paid out yet.</text>
<text x="40" y="264" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF">Every block you mine pays you sats after the season ends.</text>
<text x="40" y="304" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#A1A1A7">Win rated games, mine blocks, get paid.</text>
@endif
</svg>
