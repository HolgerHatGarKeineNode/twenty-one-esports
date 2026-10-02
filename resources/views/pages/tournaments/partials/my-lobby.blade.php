{{--
    The viewer's own lobby of a running lobby tournament (P10, pages/tournaments/⚡show myLobby()), pinned
    above the hero (2026-10-02: the lobby list sits far down the page): the league's name and password with
    copy buttons, the report deadline, and the way to the lobby's card, where the places are reported. Only
    for a player of this lobby (LobbyResults::plays()), the same rule as the card's access block.
    `$match`: the lobby (App\Models\TournamentMatch), `$tournament`.
--}}
@php
    use App\Support\Tournaments\LobbyResults;

    $lobby = (array) $match->lobby;
    $reportUntil = LobbyResults::reportUntil($match);
    $zone = (string) (auth()->user()?->timezone ?? config('esports.preseason.display_timezone'));
@endphp
<section aria-labelledby="my-lobby-h" class="mx-4 flex flex-col gap-2 rounded-card bg-card px-4 py-4 shadow-[inset_0_0_0_1px_#F7931A] lg:mx-12 lg:px-6" x-data="{ show: false, copied: '' }" data-test="my-lobby">
    <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
        <h2 id="my-lobby-h" class="m-0 flex items-center gap-1.5 text-[15px] font-bold"><x-icon name="key" :size="16" class="shrink-0 text-btc-hi" />{{ __('Join this lobby in :game', ['game' => \App\Support\GameNames::game($tournament->game)]) }}</h2>
        <span class="text-xs text-ink-2">{{ __('Lobby :number', ['number' => $match->position]) }} · {{ trans_choice(':count player|:count players', $match->slots->count()) }}</span>
    </span>
    <div class="grid min-h-11 grid-cols-[72px_minmax(0,1fr)_auto] items-center gap-2 border-t border-hairline pt-2 text-[13px]">
        <span class="text-xs text-ink-2">{{ __('Name') }}</span><b class="min-w-0 font-mono break-all" data-test="my-lobby-name">{{ $lobby['name'] ?? '' }}</b>
        <button type="button" x-on:click="navigator.clipboard?.writeText(@js((string) ($lobby['name'] ?? ''))); copied = 'name'" aria-label="{{ __('Copy lobby name') }}" data-test="my-lobby-copy-name" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><span x-show="copied !== 'name'"><x-icon name="copy" :size="16" /></span><span x-show="copied === 'name'" x-cloak class="text-win"><x-icon name="check" :size="16" /></span></button>
    </div>
    <div class="grid min-h-11 grid-cols-[72px_minmax(0,1fr)_auto_auto] items-center gap-2 border-t border-hairline pt-2 text-[13px]">
        <span class="text-xs text-ink-2">{{ __('Password') }}</span>
        <span class="min-w-0"><span x-show="! show">••••••</span><b x-show="show" x-cloak class="font-mono break-all" data-test="my-lobby-password">{{ $match->lobby_password ?? '–' }}</b></span>
        <button type="button" x-on:click="show = ! show" :aria-pressed="show ? 'true' : 'false'" aria-label="{{ __('Show password') }}" data-test="my-lobby-show" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="eye" :size="16" /></button>
        <button type="button" x-on:click="navigator.clipboard?.writeText(@js((string) $match->lobby_password)); copied = 'password'" aria-label="{{ __('Copy password') }}" data-test="my-lobby-copy-password" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><span x-show="copied !== 'password'"><x-icon name="copy" :size="16" /></span><span x-show="copied === 'password'" x-cloak class="text-win"><x-icon name="check" :size="16" /></span></button>
    </div>
    <span class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-hairline pt-2">
        @if ($reportUntil !== null && LobbyResults::reportOpen($match))
            <span class="text-xs text-ink-2" data-test="my-lobby-deadline">{{ __('Players report by :time; after that a director decides.', ['time' => $reportUntil->copy()->setTimezone($zone)->translatedFormat('D j M, H:i')]) }}</span>
        @endif
        <a href="#lobby-{{ $match->position }}" class="inline-flex min-h-11 items-center text-[13px] font-bold" data-test="my-lobby-report">{{ __('Report the places') }}</a>
    </span>
</section>
