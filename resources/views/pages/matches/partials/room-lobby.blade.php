{{--
    The lobby card the league knows (MatchRoom-2c-LobbyGeteilt.dc.html, TournamentMatchRoom.dc.html): pinned
    under the steps from the lobby step on. Name and password in plain mono, 22 px, each with its copy button,
    never behind a "show" toggle (user 2026-10-10: "Das PW muss nicht hidden sein!"). Two sources: the league's
    lobby of a tournament match (LeagueLobby, made at the draw, the higher seed hosts), or the lobby a captain
    set in a lineup series (SeriesService::setLobby()). A casual 1v1's lobby travels in the chat instead
    (partials/casual-lobby-pin). Only the two sides reach this room; the directors see it on the match page.
    Needs $m, $mySide and $viewer; optional $leagueActions (a casual cup: who is in, and this player's action).
--}}
@php
    $leagueMade = \App\Support\Tournaments\LeagueLobby::isLeagueMade($m);
    $hostSide = $m->host_side === 'challenged' ? 'challenged' : 'challenger';
    $hostName = $m->sideName($hostSide);
    $iHostLobby = $mySide !== null && $mySide === $hostSide;
    $gameName = \App\Support\GameNames::game($m->game);
    $leagueActions ??= false;
    $copies = ['name' => [__('Name'), $m->lobby_name, __('Copy lobby name'), __('Copy name')], 'password' => [__('Password'), $m->lobby_password, __('Copy password'), __('Copy password')]];
    // Once both are in the game (MatchRoom-3/4 boards: "collapsed"), the card shrinks to one line; name and password stay in plain text.
    $compact = $m->isCasualPairing() ? $m->joined_at !== null : ($m->currentGames() !== [] || $m->status !== \App\Enums\SeriesStatus::Accepted);
@endphp
@if ($compact)
<section aria-labelledby="room-lobby-h" class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[4px] border border-btc-ring bg-feature px-4 py-2.5" x-data="{ copied: '' }" data-test="room-lobby-pin" data-league="{{ $leagueMade ? '1' : '0' }}" data-compact="1">
    <h2 id="room-lobby-h" class="m-0 flex items-center gap-1.5 text-[13px] font-normal text-ink-3"><x-icon name="lock" :size="14" class="shrink-0 text-btc-hi" /><span class="sm:sr-only">{{ $leagueMade ? __('Lobby, league access') : __('Lobby of :name', ['name' => $hostName]) }}</span></h2>
    @foreach ($copies as $field => [$label, $value, $aria])
        <span class="flex min-w-0 items-center gap-2 max-sm:w-full">
            <span class="rv-m w-16 shrink-0 text-[13px] sm:w-auto">{{ $label }}</span>
            <b class="min-w-0 font-mono text-[15px] [overflow-wrap:anywhere] max-sm:grow" data-test="room-lobby-{{ $field }}">{{ $value ?? '–' }}</b>
            <button type="button" x-on:click="navigator.clipboard?.writeText({{ \Illuminate\Support\Js::from((string) $value) }}); copied = '{{ $field }}'" aria-label="{{ $aria }}" data-test="room-lobby-copy-{{ $field }}" class="rv-b2 size-11 shrink-0 px-0">
                <span x-show="copied !== '{{ $field }}'"><x-icon name="copy" :size="14" /></span><span x-show="copied === '{{ $field }}'" x-cloak class="text-win"><x-icon name="check" :size="14" /></span>
            </button>
        </span>
    @endforeach
    <span class="grow max-sm:hidden"></span>
    @if ($m->isCasualPairing() && $m->joined_at !== null)
        <span class="rv-tag is-ok"><x-icon name="check" :size="12" />{{ __('both joined') }}</span>
    @endif
</section>
@else
<section aria-labelledby="room-lobby-h" class="rv-lobby" x-data="{ copied: '' }" data-test="room-lobby-pin" data-league="{{ $leagueMade ? '1' : '0' }}">
    <div class="flex items-start gap-3">
        <x-game-cover :game="$m->game" size="thumb" class="h-10 w-[72px] shrink-0 rounded-[2px] object-cover max-sm:hidden" />
        <span class="flex min-w-0 grow flex-col gap-0.5">
            <h2 id="room-lobby-h" class="m-0 flex min-w-0 flex-wrap items-center gap-x-1.5 text-[15px] font-bold [overflow-wrap:anywhere]">
                <x-icon name="lock" :size="14" class="shrink-0 text-btc-hi" />
                @if ($leagueMade)
                    {{ __('Lobby, league access') }}
                @else
                    <span class="min-w-0 max-sm:hidden">{{ __('Lobby of :name', ['name' => $hostName]) }} <span class="font-normal text-ink-2">({{ __('host') }})</span></span><span class="sm:hidden">{{ __('Lobby') }}</span>
                @endif
            </h2>
            <span class="rv-m text-[13px] [overflow-wrap:anywhere] {{ $leagueMade ? '' : 'max-sm:hidden' }}">{{ $leagueMade ? __('The host creates the lobby exactly like this: :name (higher seed).', ['name' => $hostName]) : __('host :clan', ['clan' => $hostName]).($m->lobby_region ? ', '.$m->lobby_region : '') }}</span>
        </span>
    </div>
    <b class="text-[15px] max-sm:hidden">{{ $iHostLobby ? __('Create this lobby in :game', ['game' => $gameName]) : __('Join this lobby in :game', ['game' => $gameName]) }}</b>
    <div class="flex flex-col gap-3">
        @foreach ($copies as $field => [$label, $value, $aria, $buttonLabel])
            <div class="flex min-w-0 flex-col gap-1">
                <span class="rv-m text-[13px]" id="room-lobby-{{ $field }}-l">{{ $label }}</span>
                <div class="flex min-w-0 gap-2">
                    <b class="rv-cred" aria-labelledby="room-lobby-{{ $field }}-l" data-test="room-lobby-{{ $field }}">{{ $value ?? '–' }}</b>
                    <button type="button" x-on:click="navigator.clipboard?.writeText({{ \Illuminate\Support\Js::from((string) $value) }}); copied = '{{ $field }}'" aria-label="{{ $aria }}" data-test="room-lobby-copy-{{ $field }}"
                            class="rv-b2 rv-cred-copy max-sm:w-11 max-sm:px-0">
                        <span x-show="copied !== '{{ $field }}'" class="inline-flex items-center gap-2"><x-icon name="copy" :size="16" /><span class="max-sm:sr-only">{{ $buttonLabel }}</span></span>
                        <span x-show="copied === '{{ $field }}'" x-cloak class="inline-flex items-center gap-2 text-win"><x-icon name="check" :size="16" /><span class="max-sm:sr-only">{{ __('Copied') }}</span></span>
                    </button>
                </div>
            </div>
        @endforeach
    </div>
    {{-- Mode and region repeat the head chips: on a phone the card keeps the first screen without them. --}}
    <span class="flex flex-wrap gap-2 max-sm:hidden">
        <span class="rv-tag h-7"><span class="text-ink-3">{{ __('Mode') }}</span> {{ $gameName }} {{ $m->mode }}</span>
        @if ($m->lobby_region)
            <span class="rv-tag h-7"><span class="text-ink-3">{{ __('Region') }}</span> {{ $m->lobby_region }}</span>
        @endif
        @if ($leagueMade)
            <span class="rv-tag h-7"><span class="text-ink-3">{{ __('Spectators') }}</span> {{ __('Tournament directors') }}</span>
        @endif
    </span>
    @if ($leagueActions)
        {{-- A casual cup with the league's lobby: the host says the lobby is open, the guest says they are in. --}}
        @php($hostIn = $m->lobby_shared_at !== null)
        @php($guestIn = $m->joined_at !== null)
        @php($running = $m->casualUnderWay() && $m->noshow_reported_at === null)
        <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
            @foreach ([[$hostSide, $hostIn], [\App\Models\SeriesMatch::otherSide($hostSide), $guestIn]] as [$side, $in])
                <span class="flex min-w-0 items-center gap-2" data-test="room-lobby-in-{{ $side }}" data-in="{{ $in ? '1' : '0' }}">
                    <span class="rv-av size-8 text-xs" aria-hidden="true">{{ mb_strtoupper(mb_substr($m->sideName($side), 0, 1)) }}</span>
                    <span class="flex min-w-0 flex-col">
                        <b class="text-[13px] [overflow-wrap:anywhere]">{{ $m->sideName($side) }}</b>
                        <span @class(['inline-flex items-center gap-1 text-[13px]', 'text-win' => $in, 'text-ink-3' => ! $in])>@if ($in)<x-icon name="check" :size="12" />{{ __('in the lobby') }}@else{{ __('not in the lobby yet') }}@endif</span>
                    </span>
                </span>
            @endforeach
            <span class="grow"></span>
            @if ($running && $iHostLobby && ! $hostIn)
                <button type="button" wire:click="casualLeagueLobbyOpen" class="rv-b h-[52px] px-6 max-lg:hidden" data-room-primary data-test="league-lobby-open">{{ __('I am in the lobby') }}</button>
            @elseif ($running && $mySide !== null && ! $iHostLobby && $hostIn && ! $guestIn)
                <button type="button" wire:click="casualJoined" class="rv-b h-[52px] px-6 max-lg:hidden" data-room-primary data-test="casual-joined">{{ __('I am in the lobby') }}</button>
            @elseif ($mySide !== null && (($iHostLobby && $hostIn) || (! $iHostLobby && $guestIn)))
                <span class="rv-tag is-ok h-11 px-3"><x-icon name="check" :size="14" />{{ __('Joined') }}</span>
            @endif
        </div>
    @endif
    <p class="rv-m m-0 flex items-center gap-2 text-[13px]"><x-icon name="lock" :size="14" class="shrink-0 text-win" />{{ $leagueMade ? __('Only the players of this lobby and the tournament directors see this. It is never published.') : __('Only the two lineups see this. It is never published.') }}</p>
</section>
@endif
