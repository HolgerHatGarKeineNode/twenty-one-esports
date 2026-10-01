{{--
    The league's lobby defaults of the room's game (App\Support\Series\LobbyRules,
    P9), under the casual steps and in a series' lobby panel. Nothing for a game
    without any. Needs $m from pages/matches/⚡room.
--}}
@php($lobbyRules = \App\Support\Series\LobbyRules::items($m->game))
@if ($lobbyRules !== [])
    <div class="flex min-w-0 flex-col gap-1.5 border-t border-hairline pt-3" data-test="lobby-rules">
        <b class="text-xs text-ink">{{ __('League lobby rules') }}</b>
        <ul role="list" class="m-0 flex list-disc flex-col gap-1 pl-4 text-xs leading-normal break-words text-ink-2">
            @foreach ($lobbyRules as $rule)
                <li>{{ $rule }}</li>
            @endforeach
        </ul>
        <a href="{{ route('rules') }}#{{ $m->game }}" class="inline-flex min-h-11 items-center self-start text-xs">{{ __('All rules') }}</a>
    </div>
@endif
