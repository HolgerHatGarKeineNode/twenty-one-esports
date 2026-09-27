{{--
    B4 · Broadcast desk · clans teaser. Channel frame; the pitch and the clan count on the left, one clan as a card on
    the right (its initial on an orange tile: remote logos are not fetched while rendering).

    Data contract:
      $stats  array{clans?: int, clan?: array{name: string, members?: int, games?: int, founded?: string,
              tag?: string, logoUrl?: string}|null, …} (StreamStats::clanSpotlight) + the ticker counts. logoUrl is
              ignored; founded is shown as given (e.g. "Sep 12, 2026").
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $clans = K::count($stats ?? [], 'clans');
    $clan = is_array($stats['clan'] ?? null) && K::clean($stats['clan']['name'] ?? '') !== '' ? $stats['clan'] : null;
    $tail = match (true) {
        $clans === null => null,
        $clans === 0 => 'No clans yet. Yours could be the first.',
        $clans === 1 => 'clan so far. Yours could be the second.',
        default => 'clans so far. Start yours or join one.',
    };
    $numW = $clans ? K::width((string) $clans, K::DISPLAY, 64) : 0;
    if ($clan) {
        $cname = K::name($clan['name'], 'Clan', 30, 368);
        // The tile shows the clan tag (at most 4 characters), else the name's first letter.
        $tag = mb_strtoupper(K::clean($clan['tag'] ?? ''));
        $initial = $tag !== '' && mb_strlen($tag) <= 4 ? $tag : mb_strtoupper(mb_substr(K::clean($clan['name']), 0, 1));
        $initialFont = K::nameFont($initial);
        $initialSize = mb_strlen($initial) <= 1 ? 56 : (mb_strlen($initial) === 2 ? 44 : 30);
        $foundedText = K::clean($clan['founded'] ?? '');
        $founded = $foundedText !== '' ? K::fit('founded '.$foundedText, K::MONO, 18, 368) : null;
        $tiles = array_values(array_filter([
            is_int($clan['members'] ?? null) ? [$clan['members'], $clan['members'] === 1 ? 'member' : 'members'] : null,
            is_int($clan['games'] ?? null) ? [$clan['games'], $clan['games'] === 1 ? 'game played' : 'games played'] : null,
        ]));
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? []])

<text x="40" y="276" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">Play for your clan</text>
<text x="40" y="330" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Start a clan with your meetup or your</text>
<text x="40" y="363" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">friends, or join one that exists.</text>
@if ($tail !== null)
@if ($clans > 0)<text data-unit="clan-count" x="40" y="452" font-family="Unbounded" font-weight="800" font-size="64" fill="#F7931A">{{ $clans }}</text>@endif
@php($inline = $clans === 0 || $numW <= 60)
@php($tailX = $clans > 0 && $inline ? 40 + $numW + 16 : 40)
@php($tailY = $inline ? 452 : 496)
<text data-unit="clan-tail" data-box="{{ $tailX - 1 }} {{ $tailY - 24 }} 610 {{ $tailY + 6 }}" x="{{ $tailX }}" y="{{ $tailY }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ $tail }}</text>
@endif

<rect x="664" y="180" width="576" height="{{ $clan ? ($tiles ? 380 : 260) : 220 }}" fill="#121215"/>
@if ($clan)
<rect x="696" y="212" width="120" height="120" fill="#F7931A"/>
<text x="756" y="294" font-family="{{ $initialFont }}" font-weight="800" font-size="{{ $initialSize }}" fill="#17120A" text-anchor="middle">{{ $initial }}</text>
<text data-unit="clan-name" data-box="839 {{ ($founded ? 268 : 282) - 30 }} 1216 {{ ($founded ? 268 : 282) + 8 }}" x="840" y="{{ $founded ? 268 : 282 }}" font-family="{{ $cname['font'] }}" font-weight="800" font-size="30" fill="#FFFFFF">{{ $cname['text'] }}</text>
@if ($founded)<text x="840" y="302" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $founded }}</text>@endif
@foreach ($tiles as $ti => [$val, $label])
@php($tx = 696 + $ti * 264)
<rect x="{{ $tx }}" y="356" width="248" height="100" fill="#0A0A0B"/>
<text data-unit="tile-{{ $ti }}" data-box="{{ $tx + 15 }} 366 {{ $tx + 240 }} 408" x="{{ $tx + 16 }}" y="400" font-family="Unbounded" font-weight="800" font-size="{{ strlen((string) $val) > 5 ? 24 : 32 }}" fill="#F7931A">{{ $val }}</text>
<text x="{{ $tx + 16 }}" y="436" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $label }}</text>
@endforeach
<text x="696" y="{{ $tiles ? 520 : 400 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">Clans are on esports.einundzwanzig.space</text>
@else
<text x="696" y="256" font-family="Unbounded" font-weight="800" font-size="30" fill="#FFFFFF">No clan to show yet.</text>
<text x="696" y="304" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">Found the first one on</text>
<text x="696" y="336" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">esports.einundzwanzig.space</text>
@endif
</svg>
