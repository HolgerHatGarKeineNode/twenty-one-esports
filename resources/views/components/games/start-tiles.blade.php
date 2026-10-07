@props(['game', 'paidSats' => 0, 'champion' => null, 'next' => null, 'searching' => 0, 'playHref', 'ladder' => null, 'latest' => null])

{{--
    The first row of a game page under its head (plan "RL-Startseite", P3;
    artboard "Gewählt · Mischung"): four tiles, one action each. Tournaments
    & prizes comes first and is the only orange one (user, 2026-10-05:
    "Turniere und später Season sind die wichtigsten Dinge in Zukunft"): the
    prizes paid out in this game, the last champion and the next date, and
    the way to this game's tournaments (`/tournaments?game=`). Then the
    casual 1v1, the ladder's top three and the latest match. A season tile
    will take the second place later.

    The row sizes itself by its own width, not the window's (a container
    query): beside the game chat on a desktop the page's column is narrow,
    so the tiles stand 2 × 2 until the row is wide enough for four in one.
    Text wraps instead of being cut.

    `paidSats`: GameLanding::paidSats. `champion`: the last prize
    tournament's winner(s) by name. `next`: the next tournament open for
    sign-up. `ladder`: the page's ladder (mode, pool, rows). `latest`: the
    latest finished series.
--}}
@php
    $gameName = \App\Support\GameNames::game((string) $game);
    $sats = fn (int $value): string => \App\Support\Cards\ShareCard::sats($value);
    $tile = 'flex min-h-36 min-w-0 flex-col gap-1.5 rounded-lg p-4 text-[13px] leading-snug break-words';
    $plain = $tile.' bg-card text-ink shadow-ring hover:bg-row-hover hover:text-ink';
    $label = 'text-xs font-bold tracking-wide uppercase';
@endphp

<nav aria-label="{{ __(':game: where to start', ['game' => $gameName]) }}" {{ $attributes->class('@container') }} data-test="start-tiles">
    <ul class="m-0 grid list-none grid-cols-2 gap-3 p-0 @4xl:grid-cols-4">
        <li class="flex min-w-0">
            <a href="{{ route('tournaments.index', ['game' => $game]) }}" class="{{ $tile }} w-full bg-btc text-on-btc hover:text-on-btc" data-test="start-tile" data-tile="tournaments">
                <span class="{{ $label }} inline-flex items-center gap-1.5"><x-icon name="trophy" :size="14" />{{ __('Tournaments & prizes') }}</span>
                @if ($paidSats > 0)
                    <b class="font-display text-xl leading-tight font-bold tabular-nums" data-test="start-tile-paid">{{ __(':sats sats won', ['sats' => $sats($paidSats)]) }}</b>
                @else
                    <b class="font-display text-xl leading-tight font-bold">{{ __('Play for sats') }}</b>
                @endif
                @if ($champion !== null)
                    <span data-test="start-tile-champion">{{ __('Champion: :name', ['name' => $champion]) }}</span>
                @endif
                <span class="mt-auto font-bold" data-test="start-tile-next">
                    {{ $next !== null ? __('Next: :date', ['date' => \App\Support\Series\SeriesPresenter::time($next->starts_at, auth()->user(), 'D j M')]) : __('All :game tournaments', ['game' => $gameName]) }}
                </span>
            </a>
        </li>
        <li class="flex min-w-0">
            <a href="{{ $playHref }}" class="{{ $plain }} w-full" data-test="start-tile" data-tile="play">
                <span class="{{ $label }} text-ink-2">{{ __('Play') }}</span>
                <b class="font-display text-xl leading-tight font-bold">{{ __('Casual 1v1') }}</b>
                <span class="text-ink-2">{{ __('No clan needed, moves your casual Elo.') }}</span>
                <span class="mt-auto font-bold text-btc">{{ trans_choice(':count searching now|:count searching now', $searching) }}</span>
            </a>
        </li>
        <li class="flex min-w-0">
            <a href="{{ route('ladder.show', [$game, $ladder['mode'] ?? array_key_first(app(\App\Games\GameRegistry::class)->get((string) $game)->modes())]) }}" class="{{ $plain }} w-full" data-test="start-tile" data-tile="ladder">
                <span class="{{ $label }} text-ink-2">{{ $ladder ? __(':mode ladder', ['mode' => $ladder['mode']]) : __('Ladder') }}</span>
                @if ($ladder === null)
                    <span class="text-ink-2">{{ __('No results yet: the ladder fills with the first finished series.') }}</span>
                @else
                    <ol class="m-0 flex list-none flex-col gap-1 p-0">
                        @foreach ($ladder['rows']->take(3) as $row)
                            @php
                                $isPlayer = $row->user_id !== null;
                                $name = $isPlayer ? ($row->user?->displayName() ?? __('Deleted account')) : ($row->lineup?->clan?->name ?? __('Deleted lineup'));
                            @endphp
                            <li class="flex min-w-0 items-baseline gap-2">
                                <b @class(['w-3 shrink-0 font-display', 'text-btc' => $loop->first, 'text-ink-2' => ! $loop->first])>{{ $loop->iteration }}</b>
                                <span class="min-w-0 grow">{{ $name }}</span>
                                <span class="shrink-0 text-ink-2 tabular-nums">{{ $row->rating }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </a>
        </li>
        <li class="flex min-w-0">
            @if ($latest !== null)
                @php
                    $wins = \App\Models\SeriesMatch::seriesScore($latest->currentGames());
                @endphp
                <a href="{{ route('matches.show', $latest) }}" class="{{ $plain }} w-full" data-test="start-tile" data-tile="latest">
                    <span class="{{ $label }} text-ink-2">{{ __('Latest match') }}</span>
                    <span class="font-bold">{{ $latest->sideName('challenger') }}</span>
                    <b class="font-display text-xl leading-tight font-bold tabular-nums">{{ $wins['challenger'] }} : {{ $wins['challenged'] }}</b>
                    <span class="font-bold">{{ $latest->sideName('challenged') }}</span>
                    <span class="mt-auto text-ink-2">{{ $latest->finished_at?->diffForHumans() }}</span>
                </a>
            @else
                <a href="#game-matches" class="{{ $plain }} w-full" data-test="start-tile" data-tile="latest">
                    <span class="{{ $label }} text-ink-2">{{ __('Latest match') }}</span>
                    <span class="text-ink-2">{{ __('No :game series yet.', ['game' => $gameName]) }}</span>
                    <span class="mt-auto font-bold text-btc">{{ __('All matches') }}</span>
                </a>
            @endif
        </li>
    </ul>
</nav>
