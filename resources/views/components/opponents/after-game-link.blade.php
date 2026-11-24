@props(['opponent', 'viewer' => null, 'href' => null, 'textClass' => null])

{{--
    The opponent list at the end of a game where the signed add cannot run in place (plan "Season vs. Casual", P5):
    the live chess board's game-over card (a link to the finished game's page, where x-opponents.after-game holds the
    add) and the Hyperbitcoinization end dialog (its own document without Livewire: the opponent's player page holds
    the add, the opponents page the requests). One line per opponent, by where the two stand (Opponents::relation()):
    "Add :name as opponent" and "Accept :name's request" link to the place of the add, "Rated next time once :name adds
    you back" and "Mutual opponents, rated next time" are text.

    `href`: where the add happens (null: the opponent's player page, and the opponents page for a request).
    `textClass`: the classes of the two text states; the link takes the component's attributes. Renders nothing for a
    guest, a bot seat (no opponent), the viewer, or before opponent lists open.

    "Rated next time" only for a viewer who meets every other step of the path (design-lead review, finding 2): with
    a profile or trust step open, the two text states give way to what is missing ("You still need: Trust rank 0/50",
    a link to the opponents page's #trust; SeasonPath::missing()).
--}}
@php
    $viewer ??= auth()->user();
    $show = $viewer instanceof \App\Models\User && $opponent instanceof \App\Models\User && $opponent->exists && $opponent->id !== $viewer->id
        && \App\Support\SeasonChain\OpponentLists::forLeague() !== null;
    $relation = $show ? app(\App\Support\SeasonChain\Opponents::class)->relation($viewer, $opponent) : null;
    $name = $show ? $opponent->displayName() : '';
    $missing = in_array($relation, ['mutual', 'listed'], true) ? app(\App\Support\SeasonChain\SeasonPath::class)->missing($viewer) : null;
@endphp

@if ($show)
    @if ($missing !== null && $missing['href'] !== null)
        <a href="{{ $missing['href'] }}" {{ $attributes }} data-test="after-game-link" data-state="missing">{{ $missing['text'] }}</a>
    @elseif ($missing !== null)
        <span class="{{ $textClass }}" data-test="after-game-link" data-state="missing">{{ $missing['text'] }}</span>
    @elseif ($relation === 'mutual')
        <span class="{{ $textClass }}" data-test="after-game-link" data-state="mutual">{{ __('Mutual opponents, rated next time') }}</span>
    @elseif ($relation === 'listed')
        <span class="{{ $textClass }}" data-test="after-game-link" data-state="listed">{{ __('Rated next time once :name adds you back', ['name' => $name]) }}</span>
    @elseif ($relation === 'lists-you')
        <a href="{{ $href ?? route('settings.opponents').'#requests' }}" {{ $attributes }} data-test="after-game-link" data-state="lists-you">{{ __('Accept :name\'s request', ['name' => $name]) }}</a>
    @else
        <a href="{{ $href ?? route('players.show', $opponent->npub) }}" {{ $attributes }} data-test="after-game-link" data-state="none">{{ __('Add :name as opponent', ['name' => $name]) }}</a>
    @endif
@endif
