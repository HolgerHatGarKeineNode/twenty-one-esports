{{--
    The other side of a match on /me: the player's picture, else the clan's
    logo or tag in a square. $face: User|null, $clan: Clan|null, $tag:
    string|null, $size: px.
--}}
@if ($face)
    <x-avatar :user="$face" :size="$size" class="shrink-0 rounded-md" />
@else
    <x-clan-tag :clan="$clan" :tag="$tag" :tile="$size" class="flex shrink-0 items-center justify-center rounded-md bg-btc-tint text-[11px] font-bold text-btc" style="width: {{ $size }}px; height: {{ $size }}px" />
@endif
