{{--
    E5 · Terminal ticker · pride: the newest block of the season chain and the player whose win mined it. Header line
    and stats bar as c3; the height as the big number, the miner's face and name, whom they beat on which ladder and
    when, the sats the block pays each miner, and their count of blocks this season; on the right the chain's last
    five heights, the new one lit, and the season's total. A voided block never shows (PrideSlides::block). Without a
    block: how blocks are mined, as an invitation.

    Data contract:
      $pride     array{block: array{height: int, season: string, reward: int, label: string, ladder: string,
                 miners: list<array{name: string, avatar: ?string}>, beat: list<string>, seasonBlocks: int,
                 minerBlocks: ?int, ago: string, live: bool}|null, …} (PrideSlides::all())
      $stats     array: the stats bar counts (c-chrome)
      $backdrop  ?string, optional: the brand backdrop
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $block = is_array($pride['block'] ?? null) && is_int($pride['block']['height'] ?? null) ? $pride['block'] : null;
    if ($block) {
        $miners = K::prideRows($block['miners'] ?? [], 3);
        $miner = $miners[0] ?? null;
        $name = K::name($miner['name'] ?? '', 'A player', 44, 560);
        $others = count($miners) > 1 ? 'with '.K::listing(array_column(array_slice($miners, 1), 'name'), 2) : null;
        $beat = K::listing($block['beat'] ?? [], 2);
        $ladder = K::clean($block['ladder'] ?? '');
        $line = K::fit(($beat !== '' ? 'beat '.$beat : 'won').($ladder !== '' ? ' in '.$ladder : ''), K::MONO, 22, 600);
        $sub = K::fit(implode(', ', array_filter([$others, K::clean($block['ago'] ?? '')])), K::MONO, 20, 600);
        $reward = is_int($block['reward'] ?? null) && $block['reward'] > 0 ? '+'.K::sats($block['reward']).' sats' : null;
        $mine = is_int($block['minerBlocks'] ?? null) && $block['minerBlocks'] > 0 ? K::ordinal($block['minerBlocks']).' block this season' : null;
        $heights = K::chainHeights($block['height'], 5);
        $season = K::clean($block['season'] ?? '');
        // After the season ended this block closed it: no next one to mine in it.
        $live = ($block['live'] ?? true) !== false;
        $label = $live ? 'New block' : K::fit($season !== '' ? 'The last block of '.$season : 'The last block of the season', K::MONO, 24, 860);
        $total = is_int($block['seasonBlocks'] ?? null) ? K::plural($block['seasonBlocks'], 'block', 'blocks').' mined'.($season !== '' ? ' in '.$season : '') : null;
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.8])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'season chain'])
@if ($block)
<text data-unit="block-label" data-box="39 110 900 138" x="40" y="132" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#A1A1A7">{{ $label }}</text>
<text data-unit="height" data-box="38 140 700 252" x="40" y="240" font-family="Unbounded" font-weight="800" font-size="112" fill="#F7931A">{{ $block['height'] }}</text>
@include('stream.rotation.partials.face', ['face' => K::prideFace($miner), 'x' => 40, 'y' => 288, 'd' => 120, 'id' => 'miner', 'fUnit' => 'miner-face', 'fRing' => '#F7931A'])
<text x="184" y="312" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">mined by</text>
<text data-unit="miner-name" data-box="183 318 760 368" x="184" y="358" font-family="{{ $name['font'] }}" font-weight="800" font-size="44" fill="#FFFFFF">{{ $name['text'] }}</text>
<text data-unit="miner-line" data-box="183 380 790 406" x="184" y="400" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $line }}</text>
@if ($sub !== '')<text data-unit="miner-sub" data-box="183 412 790 436" x="184" y="430" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#A1A1A7">{{ $sub }}</text>@endif
@if ($reward)
@php($rw = K::width($reward, K::DISPLAY, 36) * 1.04 + 40)
<rect x="40" y="468" width="{{ $rw }}" height="72" fill="#F7931A"/>
<text data-unit="reward" data-box="48 476 {{ 36 + $rw }} 532" x="60" y="517" font-family="Unbounded" font-weight="800" font-size="36" fill="#17120A">{{ $reward }}</text>
@if ($mine)<text data-unit="miner-count" data-box="{{ 55 + $rw }} 490 820 520" x="{{ 56 + $rw }}" y="512" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $mine }}</text>@endif
@elseif ($mine)
<text data-unit="miner-count" data-box="39 490 820 520" x="40" y="512" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $mine }}</text>
@endif
@if ($live)<text x="40" y="596" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#A1A1A7">Every rated win can mine the next one.</text>@endif
{{-- The chain: the last five heights top down, links between them, the new block lit. --}}
@foreach ($heights as $hi => $h)
@php($by = 112 + $hi * 96)
@php($new = $h === $block['height'])
@if ($hi > 0)<rect x="1054" y="{{ $by - 24 }}" width="4" height="24" fill="#2A2A30"/>@endif
<rect x="{{ $new ? 952 : 976 }}" y="{{ $by }}" width="{{ $new ? 208 : 160 }}" height="72" fill="{{ $new ? '#F7931A' : '#17171B' }}" stroke="{{ $new ? '#F7931A' : '#2A2A30' }}" stroke-width="2"/>
<text data-unit="chain-{{ $hi }}" data-box="{{ $new ? 952 : 976 }} {{ $by + 14 }} {{ $new ? 1160 : 1136 }} {{ $by + 58 }}" x="1056" y="{{ $by + 47 }}" font-family="Unbounded" font-weight="800" font-size="{{ $new ? 30 : 22 }}" fill="{{ $new ? '#17120A' : '#A1A1A7' }}" text-anchor="middle">{{ $h }}</text>
@endforeach
@if ($total)<text data-unit="chain-total" data-box="820 580 1241 604" x="1240" y="598" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7" text-anchor="end">{{ K::fit($total, K::MONO, 18, 420) }}</text>@endif
@else
<text x="40" y="200" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">No block mined yet.</text>
<text x="40" y="264" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF">Every rated win can mine a block of the season chain.</text>
<text x="40" y="304" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#A1A1A7">Mine the first one and your name is on it.</text>
{{-- The chain's open end: the next block, waiting for its miner. --}}
<rect x="953" y="401" width="206" height="70" fill="none" stroke="#F7931A" stroke-width="2" stroke-dasharray="8 6"/>
<text x="1056" y="446" font-family="Unbounded" font-weight="800" font-size="24" fill="#F7931A" text-anchor="middle">next</text>
<text x="1056" y="506" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7" text-anchor="middle">waiting for its miner</text>
@endif
</svg>
