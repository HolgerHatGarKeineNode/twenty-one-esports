{{--
    Direction B (broadcast desk): the channel frame every B scene shares. Bug top left (mark + wordmark),
    orange ticker along the bottom (y 664..720) with the real counts from $stats (RotationKit::tickerItems: a missing
    count is left out, and counts too long for the line drop from the end; the URL always stays). Optional $bugNote: a short orange note after the wordmark (e.g. "3 games live").
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php($tick = K::tickerItems($stats ?? []))
@php($tickX = 40)
<use href="#mark" xlink:href="#mark" x="40" y="32" width="40" height="40"/>
<text x="94" y="59" font-family="Unbounded" font-weight="800" font-size="18" fill="#FFFFFF">TWENTY ONE ESPORTS</text>
@if (! empty($bugNote))<text data-unit="bug-note" data-box="380 36 900 66" x="380" y="59" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $bugNote }}</text>@endif
<rect x="0" y="664" width="1280" height="56" fill="#F7931A"/>
@foreach ($tick as $i => $item)
@if ($i > 0)<rect x="{{ $tickX }}" y="688" width="8" height="8" fill="#17120A"/>@php($tickX += 32)@endif
<text data-unit="ticker-{{ $i }}" data-box="{{ $tickX - 1 }} 670 1240 714" x="{{ $tickX }}" y="699" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A">{{ $item }}</text>
@php($tickX += mb_strlen($item) * 12 + 24)
@endforeach
