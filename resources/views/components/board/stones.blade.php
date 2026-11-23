@props(['view', 'last' => [], 'label' => '', 'gid' => 'go'])

{{--
    A small static Go board, rendered on the server like the chess tile's (x-match-dock.board): the board of the game's
    page and of the stream's board scene (StoneBoardDrawing, StoneBoardArt, stream/rotation/partials/stone-board), so a
    team match tile shows the stones without the board script. `view` is the rules' view (GoRules::view()), `last` the
    point of the last move (ringed), `gid` a prefix for the gradients' ids, unique on the page (several tiles).
    Square at any width: the drawing fills a 400 x 400 viewBox, its lower face inside it.
--}}
@php($go = \App\Support\Board\StoneBoardDrawing::layout($view, $last, 0, 0, 400))
<svg viewBox="0 0 400 400" role="img" aria-label="{{ $label }}" {{ $attributes->class('block aspect-square h-auto shrink-0') }} data-lines="{{ (int) round(sqrt(count($view['points']))) }}" data-stones="{{ count($go['stones']) }}">
    <defs>{!! \App\Support\Cards\StoneBoardArt::gradients($gid) !!}</defs>
    @include('stream.rotation.partials.stone-board', ['go' => $go, 'gid' => $gid])
</svg>
