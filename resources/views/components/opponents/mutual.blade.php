@props(['game' => null, 'viewer' => null])

{{--
    "Mutual opponents" (plan "Season vs. Casual", P5): the players the signed-in player lists each other with, in the
    chess lobby, a board lobby and /me. One row each (at most LIMIT): the name (their player page), whether they are on
    the site now (OnSite, the bell's ping), whether the pair can play Season 1 rated now (RatedTrustGate, the same gate
    as the queue and the challenge), and where the game has one, the direct rated challenge: a board game's
    correspondence challenge to that player (`?to=`, rated preselected when the gate lets the pair). Chess has no direct
    rated challenge (its daily mode is casual, live invites are casual): the rated search pairs mutual players.

    `game`: the lobby's game (null: /me, which offers the board game the player plays most, SeasonPath::gameToMine()).
    `viewer`: another viewer than the signed-in one (tests only). Nothing for a guest, without opponent lists, or
    without a mutual opponent (SeasonPath's opponents step says how to get one).
--}}
@php
    $viewer ??= auth()->user();
    $limit = 6;
    $pubkeys = $viewer instanceof \App\Models\User ? app(\App\Support\SeasonChain\Opponents::class)->mutual($viewer) : [];
    $rows = [];

    if ($pubkeys !== []) {
        $registry = app(\App\Games\GameRegistry::class);
        $game ??= \App\Support\SeasonChain\SeasonPath::gameToMine($viewer)['slug'] ?? null;
        // The direct rated challenge: a board game with the correspondence mode, while rated board play is offered.
        $challenge = $game !== null && $registry->isBoard($game) && $registry->mode($game, \App\Models\BoardGame::CORRESPONDENCE) !== null && \App\Support\Board\RatedBoard::offered();
        $gate = app(\App\Support\SeasonChain\RatedTrustGate::class);
        $onSite = app(\App\Support\Notifications\OnSite::class);
        $users = \App\Models\User::query()->whereIn('pubkey', $pubkeys)->get()->sortBy(fn (\App\Models\User $user): int => array_search($user->pubkey, $pubkeys, true))->values();

        foreach ($users as $user) {
            $pair = [$viewer->pubkey, $user->pubkey];
            $rows[] = [
                'user' => $user,
                'online' => $onSite->isOnSite($user),
                'ready' => $gate->refusal($pair, $pair) === null,
            ];
        }

        // Who can play now first: online and ready, then ready, then the rest, in list order within each.
        $rows = collect($rows)->sortBy(fn (array $row): int => ($row['online'] ? 0 : 1) + ($row['ready'] ? 0 : 2))->take($limit)->values()->all();
        $season = \App\Support\Badges\BadgeCopy::season(\App\Support\Series\Ladders::season() ?? 'pre-season');
    }
@endphp

@if ($rows !== [])
    <section aria-labelledby="mutual-opponents-h" {{ $attributes->class('flex min-w-0 flex-col gap-1 rounded-[4px] bg-card p-3 shadow-ring lg:p-4') }} data-test="mutual-opponents">
        <span class="flex min-w-0 flex-wrap items-baseline justify-between gap-x-3">
            <h2 id="mutual-opponents-h" class="m-0 text-[13px] font-bold">{{ __('Mutual opponents') }} <span class="font-normal text-ink-2">{{ count($pubkeys) }}</span></h2>
            <a href="{{ route('settings.opponents') }}" class="inline-flex min-h-11 items-center text-[13px] text-ink-2 hover:text-ink" data-test="mutual-opponents-all">{{ __('Your opponents') }}</a>
        </span>
        <ul class="m-0 flex list-none flex-col p-0">
            @foreach ($rows as $row)
                <li class="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 border-t border-hairline py-1" data-test="mutual-opponent" data-online="{{ $row['online'] ? 'true' : 'false' }}" data-ready="{{ $row['ready'] ? 'true' : 'false' }}">
                    <a href="{{ route('players.show', $row['user']->npub) }}" class="flex min-h-11 min-w-0 grow basis-40 items-center gap-2 text-[13px] text-ink hover:text-ink">
                        <x-avatar :user="$row['user']" :size="24" class="shrink-0 rounded-full" />
                        <b class="min-w-0 truncate">{{ $row['user']->displayName() }}</b>
                        @if ($row['online'])
                            <span class="inline-flex shrink-0 items-center gap-1 text-xs font-normal text-win" data-test="mutual-opponent-online"><span class="size-2 rounded-full bg-win" aria-hidden="true"></span>{{ __('Online') }}</span>
                        @endif
                    </a>
                    @if ($row['ready'])
                        @if ($challenge)
                            <a href="{{ route('board.correspondence', [$game, 'to' => $row['user']->npub]) }}" class="rv-b2 is-sm shrink-0 whitespace-nowrap" data-test="mutual-opponent-challenge">{{ __('Challenge to :season', ['season' => $season]) }}</a>
                        @else
                            <span class="inline-flex min-h-8 shrink-0 items-center gap-1.5 rounded-[4px] border border-win-ring bg-win-tint px-2.5 text-xs" data-test="mutual-opponent-ready"><x-icon name="check" :size="14" class="text-win" />{{ __(':season ready', ['season' => $season]) }}</span>
                        @endif
                    @else
                        <span class="shrink-0 text-xs text-ink-2" data-test="mutual-opponent-not-ready">{{ __('Casual only for now') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
