{{--
    The viewer's own lobby of a running lobby tournament (P10, pages/tournaments/⚡show myLobby()), pinned
    right under the hero (2026-10-02: the lobby list sits far down the page): the league's name and password (shown, user 2026-10-10) with
    copy buttons, the report deadline, and the way to the lobby's card, where the places are reported. Only
    for a player of this lobby (LobbyResults::plays()), the same rule as the card's access block.
    `$match`: the lobby (App\Models\TournamentMatch), `$tournament`; `$embedded`: inside the "What to do now"
    hero (partials/now), which carries the card and the heading.
--}}
@php
    use App\Support\Tournaments\LobbyResults;

    $lobby = (array) $match->lobby;
    $reportUntil = LobbyResults::reportUntil($match);
    $zone = (string) (auth()->user()?->timezone ?? config('esports.preseason.display_timezone'));
    $embedded ??= false;
@endphp
<section aria-labelledby="my-lobby-h" @class(['flex flex-col gap-4', 'rv-feat p-4 lg:p-6' => ! $embedded, 'max-w-[640px]' => $embedded]) data-test="my-lobby">
    {{-- TournamentLobby.dc.html: the game's thumb, "Lobby n, Liga-Zugang", the way in, and "Jetzt dran". --}}
    <span class="flex items-start gap-3">
        <x-game-cover :game="$tournament->game" :games="$tournament->coverGames()" size="thumb" class="h-10 w-[72px] flex-none rounded-[2px] [&_img]:object-cover max-sm:hidden" />
        <span class="flex min-w-0 grow flex-col">
            <h2 id="my-lobby-h" class="m-0 flex items-center gap-1.5 text-[15px] font-bold"><x-icon name="lock" :size="14" class="shrink-0 text-btc-hi" />{{ __('Lobby :number', ['number' => $match->position]) }}, {{ __('league access') }}</h2>
            <span class="rv-m">{{ __('Join this lobby in :game', ['game' => \App\Support\GameNames::game($tournament->game)]) }}, {{ trans_choice(':count player|:count players', $match->slots->count()) }}</span>
        </span>
        <span class="rv-tag is-btc flex-none">{{ __('Up now') }}</span>
    </span>
    <x-tournaments.lobby-creds :name="$lobby['name'] ?? ''" :password="$match->lobby_password" test="my-lobby" :big="! $embedded" />
    <span class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-hairline pt-2">
        @if ($reportUntil !== null && LobbyResults::reportOpen($match))
            <span class="text-xs text-ink-2" data-test="my-lobby-deadline">{{ __('Players report by :time; after that a director decides.', ['time' => \App\Support\Formats::date($reportUntil, true, $zone)]) }}</span>
        @endif
        <a href="#lobby-{{ $match->position }}" class="inline-flex min-h-11 items-center text-[13px] font-bold" data-test="my-lobby-report">{{ __('Report the places') }}</a>
    </span>
</section>
