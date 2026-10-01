{{--
    The lobbies of a lobby tournament on the TV (plan "AoE2 und Trackmania",
    P10): one panel per lobby, side by side, each a grid of its players with
    face and name, and their places once decided (place 1 shared by the
    allies left standing). Never a duel: a lobby is up to eight players in
    one game. Sized in stage units like every scene (resources/css/tv.css,
    .tv-rooms), so it fits 1920×1080 and 1280×720 alike:
    - one or two lobbies: two player columns per panel, big faces;
    - three to five (a cup of 40 is five): one row of panels, one player a
      line, so a name keeps the panel's whole width;
    - six to ten (64 players are eight): two even rows of up to five.
    A tournament has at most 64 players (TournamentFormatChooser), so ten
    lobbies are never exceeded.

    $boxes: TournamentTv::stages() heat boxes of the lobbies to show
--}}
@php
    $count = max(1, count($boxes));
    $wide = $count <= 2;
    // Up to five a row; two rows share the lobbies evenly (8 lobbies: 4 + 4).
    $rows = $wide ? 1 : (int) ceil($count / 5);
    $columns = $wide ? $count : (int) ceil($count / $rows);
    $face = $wide ? ($count === 1 ? 5 : 4) : ($rows === 1 ? 2.4 : 1.6);
    // The largest a name grows to (it shrinks to its width first, never below 1.25 units, then ellipsises).
    $nameMax = $wide ? $face * 0.45 : ($rows === 1 ? 1.8 : 1.4);
@endphp
<div @class(['tv-rooms', 'is-compact' => ! $wide]) style="--lobby-cols: {{ $columns }}; --lobby-rows: {{ $rows }}; --player-cols: {{ $wide ? 2 : 1 }}; --face: {{ $face }}; --name-max: {{ $nameMax }}" data-test="tv-lobbies">
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
