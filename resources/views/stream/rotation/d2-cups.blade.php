{{--
    D2 · Broadcast desk · casual cups. Channel frame over the brand backdrop; the title with the cups' days and the
    regions' clocks beside it (RotationKit::cupSlots: a slot per game, user 2026-09-30), the pitch, then the weekend
    as a board: one column per day a cup starts on (Friday to Sunday as the slots are set, RotationKit::cupDays), and
    in it every game with an open casual cup by its start time: the game's cover with the start time stamped on it,
    its name, then one line per region's cup (EU and US, user 2026-09-28): the region and the places taken. Every
    game gets its row, the newest included: with seven cup games open the old four tiles left three of them out
    (Age of Empires II, the last slot of the weekend, never showed). Without an open cup the board gives way to one line.

    Data contract:
      $upcoming  list<array>: TournamentSlides::frames() of every upcoming tournament; read are cup (bool), region
                 ("EU", "US" or null), game, cupDay ("sunday"), cupTime ("20:00"), coverTile and cover (data URI or
                 null; the tile wins), taken, places
      $stats     array: the ticker counts (b-chrome)
      $backdrop  ?string, optional: the brand backdrop, as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $days = K::cupDays(K::cups($upcoming ?? [], 12), 3, 4);
    $columns = [];
    $shown = 0;
    foreach ($days as $d => $day) {
        $rows = [];
        foreach ($day['cups'] as $r => $cup) {
            $y = 300 + $r * 100;
            $tx = $day['x'] + 144;
            $tw = $day['x'] + $day['w'] - $tx;
            $regions = [];
            foreach (array_slice($cup['regions'], 0, 2) as $g => $row) {
                $taken = ($row['region'] !== '' ? $row['region'].' · ' : '').($row['places'] > 0 ? $row['taken'].' / '.$row['places'].' signed up' : $row['taken'].' signed up');
                $regions[] = ['y' => $y + 48 + $g * 24, 'text' => K::fit($taken, K::MONO, 16, $tw)];
            }
            $rows[] = [
                'y' => $y, 'tx' => $tx, 'tw' => $tw, 'i' => $shown++,
                'cover' => $cup['cover'],
                'time' => $cup['time'],
                'game' => K::fit($cup['game'], K::DISPLAY, 20, $tw),
                'regions' => $regions,
            ];
        }
        $columns[] = [...$day, 'label' => K::fit($day['label'], K::DISPLAY, 26, $day['w']), 'rows' => $rows,
            'moreText' => $day['more'] > 0 ? K::fit('and '.$day['more'].' more on the site', K::MONO, 16, $day['w']) : ''];
    }
    $pitch = $shown === 1 ? 'Open now, one per region. Sign up on the site.' : 'One per region in every game. Sign up on the site.';
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.78])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => 'casual cups'])

<text x="40" y="158" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">Casual cups</text>
{{-- The cups' days and the regions' clocks: every cup starts at its game's slot on its region's clock (user, 2026-09-30). --}}
@foreach (array_slice(K::cupSlots(), 0, 2) as $si => $slot)
<text data-unit="cup-slot-{{ $si }}" data-box="700 {{ 114 + $si * 30 }} 1241 {{ 138 + $si * 30 }}" x="1240" y="{{ 132 + $si * 30 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A" text-anchor="end">{{ K::fit($slot, K::MONO, 20, 520) }}</text>
@endforeach
@if ($columns !== [])
<text data-unit="pitch" data-box="39 182 1240 210" x="40" y="204" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">{{ $pitch }}</text>
@foreach ($columns as $d => $col)
<text data-unit="cup-day-{{ $d }}" data-box="{{ $col['x'] - 1 }} 240 {{ $col['x'] + $col['w'] + 1 }} 272" x="{{ $col['x'] }}" y="266" font-family="Unbounded" font-weight="800" font-size="26" fill="#FFFFFF">{{ $col['label'] }}</text>
<rect x="{{ $col['x'] }}" y="280" width="{{ $col['w'] }}" height="3" fill="#F7931A"/>
@foreach ($col['rows'] as $row)
@if ($row['cover'])
<svg x="{{ $col['x'] }}" y="{{ $row['y'] }}" width="128" height="72" viewBox="0 0 288 162"><image x="0" y="0" width="288" height="162" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $row['cover'] }}"/></svg>
@else
<rect x="{{ $col['x'] }}" y="{{ $row['y'] }}" width="128" height="72" fill="#1C1C21"/>
@endif
@if ($row['time'] !== '')
<rect x="{{ $col['x'] }}" y="{{ $row['y'] + 50 }}" width="62" height="22" fill="#F7931A"/>
<text data-unit="cup-time-{{ $row['i'] }}" data-box="{{ $col['x'] }} {{ $row['y'] + 50 }} {{ $col['x'] + 62 }} {{ $row['y'] + 72 }}" x="{{ $col['x'] + 31 }}" y="{{ $row['y'] + 67 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#17120A" text-anchor="middle">{{ $row['time'] }}</text>
@endif
<text data-unit="cup-game-{{ $row['i'] }}" data-box="{{ $row['tx'] - 1 }} {{ $row['y'] }} {{ $row['tx'] + $row['tw'] + 1 }} {{ $row['y'] + 26 }}" x="{{ $row['tx'] }}" y="{{ $row['y'] + 20 }}" font-family="Unbounded" font-weight="800" font-size="20" fill="#FFFFFF">{{ $row['game'] }}</text>
@foreach ($row['regions'] as $g => $region)
<text data-unit="cup-taken-{{ $row['i'] }}-{{ $g }}" data-box="{{ $row['tx'] - 1 }} {{ $region['y'] - 14 }} {{ $row['tx'] + $row['tw'] + 1 }} {{ $region['y'] + 5 }}" x="{{ $row['tx'] }}" y="{{ $region['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">{{ $region['text'] }}</text>
@endforeach
@endforeach
@if ($col['moreText'] !== '')
<text data-unit="cup-more-{{ $d }}" data-box="{{ $col['x'] - 1 }} 588 {{ $col['x'] + $col['w'] + 1 }} 610" x="{{ $col['x'] }}" y="604" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">{{ $col['moreText'] }}</text>
@endif
@endforeach
@else
<text x="40" y="290" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#FFFFFF">No cup is open right now.</text>
<text x="40" y="334" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Tournaments and cups: esports.einundzwanzig.space/tournaments</text>
@endif
</svg>
