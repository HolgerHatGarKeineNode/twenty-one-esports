{{--
    F3 · Terminal ticker · Blockfill's fresh blocks (BlockfillSlides::FRESH): the week's latest runs as blocks, newest
    left, the way the mempool strip shows them. The headline carries the news: the newest run that took first place
    ("New #1: Ada 0:48.120"), else the newest personal best, else "Fresh blocks". A run that took first place is a
    mined block (orange, lit top); a personal best a graphite block with an orange edge; any other verified run a
    graphite block; a run the league has not replayed yet a dashed block that says "unconfirmed" and never its time.
    Under each block the player's avatar, name and how long ago. No runs yet: four open blocks that invite to play.

    Data contract (SceneSource, BlockfillSlides::scene()):
      $blockfill  array{title: string, fresh: list<array{name: string, time: ?string, waiting: bool, badge: 'top'|'best'|null,
                    when: string, avatar: ?string}>, …}|null
                  null while Blockfill is switched off: the slide invites to every game instead
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: Blockfill's blurred cover, else the brand's
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = is_array($blockfill ?? null) ? $blockfill : null;
    $runs = [];
    foreach (array_slice(is_array($b['fresh'] ?? null) ? $b['fresh'] : [], 0, 4) as $run) {
        if (is_array($run)) {
            $runs[] = $run;
        }
    }
    // No closures in a stream view (they leak per render without the CLI opcache): plain loops.
    $news = null;
    foreach (['top', 'best'] as $wanted) {
        foreach ($runs as $run) {
            if ($news === null && ($run['badge'] ?? null) === $wanted && is_string($run['time'] ?? null)) {
                $news = $run;
            }
        }
    }
    // The news keeps its time whole: only the name gives way ("New #1: Mühlenmeister Groß… 0:47.833").
    $newsLead = ($news['badge'] ?? null) === 'top' ? 'New #1: ' : 'Personal best: ';
    $newsTime = $news === null ? '' : ' '.K::clean(K::text($news, 'time'));
    $newsName = $news === null ? '' : K::clean(K::text($news, 'name'));
    $newsFont = K::nameFont($newsLead.$newsName.$newsTime);
    $headline = match (true) {
        $runs === [] => 'No blocks this week yet.',
        $news === null => 'Fresh blocks',
        default => $newsLead.(K::fit($newsName, $newsFont, 44, 1200 - K::width($newsLead.$newsTime, $newsFont, 44) * 1.04) ?: 'Player').$newsTime,
    };
    $head = ['text' => $headline, 'font' => $news === null ? K::DISPLAY : $newsFont];
    $week = preg_replace('/^Blockfill /', '', K::text($b ?? [], 'title')) ?: 'this week';
    $lead = K::fit($runs === [] ? 'Mine 40 blocks and your run lands here first.' : 'The latest runs of '.$week.'. A run counts once the league has replayed it.', K::MONO, 22, 1200);
    $cy = 268;
    $w = 264;
    $h = 180;
    $dd = 16;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.86])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'blockfill'])

@if ($b)
<text data-unit="headline" data-box="38 112 1241 166" x="40" y="156" font-family="{{ $head['font'] }}" font-weight="800" font-size="44" fill="#FFFFFF">{{ $head['text'] }}</text>
<text data-unit="lead" data-box="39 176 1241 202" x="40" y="196" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">{{ $lead }}</text>

@for ($i = 0; $i < 4; $i++)
@php
    $run = $runs[$i] ?? null;
    $x = 40 + $i * 300;
    $top = "M{$x} {$cy}L".($x + $dd).' '.($cy - $dd).'H'.($x + $w + $dd).'L'.($x + $w)." {$cy}Z";
    $side = 'M'.($x + $w)." {$cy}L".($x + $w + $dd).' '.($cy - $dd).'V'.($cy + $h - $dd).'L'.($x + $w).' '.($cy + $h).'Z';
    $waiting = $run !== null && (($run['waiting'] ?? false) === true || ! is_string($run['time'] ?? null));
    $badge = $run === null || $waiting ? null : ($run['badge'] ?? null);
    $ink = $badge === 'top' ? '#17120A' : '#FFFFFF';
    $label = match (true) {
        $run === null => null,
        $waiting => 'not replayed yet',
        $badge === 'top' => 'New #1',
        $badge === 'best' => 'Personal best',
        default => 'Verified',
    };
    $labelInk = match ($badge) { 'top' => '#17120A', 'best' => '#F7931A', default => '#A1A1A7' };
@endphp
<g data-unit="block-{{ $i }}">
@if ($run === null || $waiting)
<path d="{{ $side }}" fill="none" stroke="#63636A" stroke-width="2" stroke-dasharray="6 4"/>
<path d="{{ $top }}" fill="none" stroke="#63636A" stroke-width="2" stroke-dasharray="6 4"/>
<rect x="{{ $x + 1 }}" y="{{ $cy + 1 }}" width="{{ $w - 2 }}" height="{{ $h - 2 }}" fill="#141417" fill-opacity="0.85" stroke="#63636A" stroke-width="2" stroke-dasharray="6 4"/>
@if ($run === null && $i === 0 && $runs === [])
<path d="M{{ $x + $w / 2 - 22 }} {{ $cy + $h / 2 }}H{{ $x + $w / 2 + 22 }}M{{ $x + $w / 2 }} {{ $cy + $h / 2 - 22 }}V{{ $cy + $h / 2 + 22 }}" stroke="#7A7A82" stroke-width="6" stroke-linecap="round"/>
@endif
@elseif ($badge === 'top')
<path d="{{ $side }}" fill="#B9640A"/>
<path d="{{ $top }}" fill="#FFC27A"/>
<rect x="{{ $x }}" y="{{ $cy }}" width="{{ $w }}" height="{{ $h }}" fill="#F7931A"/>
@else
<path d="{{ $side }}" fill="#141417"/>
<path d="{{ $top }}" fill="#34343C"/>
<rect x="{{ $x }}" y="{{ $cy }}" width="{{ $w }}" height="{{ $h }}" fill="#1C1C21"/>
@if ($badge === 'best')<rect x="{{ $x + 1 }}" y="{{ $cy + 1 }}" width="{{ $w - 2 }}" height="{{ $h - 2 }}" fill="none" stroke="#F7931A" stroke-width="2"/>@endif
@endif
</g>
@if ($run !== null)
<text data-unit="block-label-{{ $i }}" data-box="{{ $x + 15 }} {{ $cy + 16 }} {{ $x + $w - 15 }} {{ $cy + 40 }}" x="{{ $x + 16 }}" y="{{ $cy + 36 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $waiting ? '#A1A1A7' : $labelInk }}">{{ $label }}</text>
@if ($waiting)
<text data-unit="block-time-{{ $i }}" data-box="{{ $x + 15 }} {{ $cy + 86 }} {{ $x + $w - 15 }} {{ $cy + 116 }}" x="{{ $x + 16 }}" y="{{ $cy + 110 }}" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#ADADB0">unconfirmed</text>
@else
<text data-unit="block-time-{{ $i }}" data-box="{{ $x + 15 }} {{ $cy + 72 }} {{ $x + $w - 15 }} {{ $cy + 118 }}" x="{{ $x + 16 }}" y="{{ $cy + 114 }}" font-family="Unbounded" font-weight="800" font-size="36" fill="{{ $ink }}">{{ K::fit(K::text($run, 'time'), K::DISPLAY, 36, $w - 32) }}</text>
@endif
<text data-unit="block-when-{{ $i }}" data-box="{{ $x + 15 }} {{ $cy + 146 }} {{ $x + $w - 15 }} {{ $cy + 168 }}" x="{{ $x + 16 }}" y="{{ $cy + 164 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $badge === 'top' ? '#17120A' : '#A1A1A7' }}">{{ K::fit(K::text($run, 'when'), K::MONO, 16, $w - 32) }}</text>
@php($name = K::name(K::text($run, 'name'), 'Player', 22, $w + $dd - 64))
@include('stream.rotation.partials.face', ['face' => K::prideFace($run), 'x' => $x, 'y' => $cy + $h + 24, 'd' => 48, 'id' => 'bf3-'.$i, 'fUnit' => 'block-face-'.$i, 'fRing' => $badge === 'top' ? '#F7931A' : null])
<text data-unit="block-name-{{ $i }}" data-box="{{ $x + 63 }} {{ $cy + $h + 34 }} {{ $x + $w + $dd }} {{ $cy + $h + 62 }}" x="{{ $x + 64 }}" y="{{ $cy + $h + 56 }}" font-family="{{ $name['font'] }}" font-weight="800" font-size="22" fill="#FFFFFF">{{ $name['text'] }}</text>
@elseif ($i === 0 && $runs === [])
<text data-unit="block-name-0" data-box="{{ $x - 1 }} {{ $cy + $h + 34 }} {{ $x + $w + $dd }} {{ $cy + $h + 62 }}" x="{{ $x }}" y="{{ $cy + $h + 56 }}" font-family="Unbounded" font-weight="800" font-size="22" fill="#FFFFFF">Your run</text>
@endif
@endfor
@else
<text data-unit="title" data-box="38 150 1241 210" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">Every game</text>
<text data-unit="claim" data-box="39 240 1241 270" x="40" y="262" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Every game of the league is on the site.</text>
@endif
</svg>
