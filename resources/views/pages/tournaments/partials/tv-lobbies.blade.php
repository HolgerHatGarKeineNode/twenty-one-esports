{{--
    The lobbies of a lobby tournament on the TV (plan "AoE2 und Trackmania",
    P10): one panel per lobby, side by side (up to three a row), each a grid
    of its players with face and name, and their places once decided (place
    1 shared by the allies left standing). Never a duel: a lobby is up to
    eight players in one game. Sized in stage units like every scene
    (resources/css/tv.css, .tv-rooms): the panels and the player grid
    shrink with the number of lobbies, so 8 players or 5 + 4 fit 1920×1080
    and 1280×720 alike.

    $boxes: TournamentTv::stages() heat boxes of the lobbies to show
--}}
@php
    $count = max(1, count($boxes));
    $columns = min($count, 3);
    $rows = (int) ceil($count / 3);
    // One row of panels: two player columns; two rows: four, so eight players stay two rows high.
    $playerColumns = $rows === 1 ? 2 : 4;
@endphp
<div class="tv-rooms" style="--lobby-cols: {{ $columns }}; --lobby-rows: {{ $rows }}; --player-cols: {{ $playerColumns }}; --face: {{ $rows === 1 ? ($count === 1 ? 5 : 4) : 2.5 }}" data-test="tv-lobbies">
    @foreach ($boxes as $box)
        @php
            $done = $box['status'] === 'done';
            // Decided: by place (an unplaced entry, "–", last); else in slot order.
            $sides = collect($box['sides'])->values()->map(fn (array $side, int $index): array => [...$side, 'index' => $index,
                'place' => $done && str_starts_with((string) ($side['score'] ?? ''), '#') ? (int) substr((string) $side['score'], 1) : 99])
                ->sortBy(fn (array $side): array => [$side['place'], $side['index']])->values();
            $winners = $sides->filter(fn (array $side): bool => $side['won'])->count();
        @endphp
        <article @class(['tv-room', 'is-live' => $box['live'], 'is-done' => $done]) wire:key="tv-lobby-{{ $box['key'] }}" data-key="{{ $box['key'] }}" data-test="tv-lobby" data-players="{{ count($box['sides']) }}">
            <p class="tv-room-head">
                @if ($box['live'])
                    <span class="tv-status is-live"><span class="tv-dot"></span>{{ __('Live') }}</span>
                @endif
                <span>{{ $box['round'] }}</span>
                <span class="tv-room-count">{{ trans_choice(':count player|:count players', count($box['sides'])) }}</span>
                @if ($done && $winners > 1)
                    <span class="tv-room-count">{{ __('shared place 1') }}</span>
                @endif
            </p>
            <ul class="tv-room-players">
                @foreach ($sides as $side)
                    <li @class(['tv-room-player', 'is-won' => $side['won']]) data-test="tv-lobby-player">
                        @include('pages.tournaments.partials.tv-face', ['entry' => $side['entry']])
                        <span class="tv-fit" style="--chars: {{ max(4, mb_strlen($side['name'])) }}"><span class="tv-room-name">{{ $side['name'] }}</span></span>
                        @if ($done && $side['score'] !== null)
                            <span class="tv-room-place">{{ $side['score'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </article>
    @endforeach
</div>
