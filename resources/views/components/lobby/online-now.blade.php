@props(['user' => null, 'lookingKey' => 'chess/blitz', 'lookingTag' => null, 'canInvite' => true, 'showElo' => true, 'inviteMode' => null, 'challengeUrl' => null, 'emptyText' => null, 'listClass' => 'max-h-80', 'toggle' => true])

{{--
    "Online now" of a game lobby: everyone on the presence channel `online`
    (resources/js/echo.js, joined on every logged-in page), the players who
    look for this lobby's game first with a tag and an Invite, and the
    lobby's own "Looking to play" switch. The chess lobby (partials/lobby-live)
    and a board game's lobby (pages/board/⚡lobby, P5 of plan mempool-streifen:
    "ich sehe auch keine Online Leute") show the same block.

    The list and the switch are Alpine's: the lobby's x-data (chessLobby in
    resources/js/chess.js, boardLobby in resources/js/boardLobby.js) gives
    `connection`, `others`, `looking`, `lookingFailed`, `toggleLooking()` and
    `invited(member)`; the component's $wire gives invite(id) and
    withdrawInvite(). A Livewire render never touches the switch.

    `lookingKey`: the `users.looking_to_play` value of this lobby
    (`chess/blitz`, `<board game>/blitz`). `lookingTag`: the tag on a row of
    a player who looks for it. `canInvite`: false while the viewer is in a
    live game. `showElo`: the member's Elo, which the channel carries for
    chess rapid only, labelled (routes/channels.php), so a board game lobby hides it.
    `inviteMode`: an Alpine expression passed as invite(id, mode) (the chess
    lobby's `liveMode`: rapid or blitz); null = invite(id).
    `emptyText` (or the slot `empty`, which may follow the switch): what the list says when nobody else is online (null:
    the general sentence); `listClass`: the list's
    height cap; `toggle`: false where the lobby shows the switch (components/lobby/looking-toggle) itself, as Proof
    of Pong's lobby does in its card's head (P9).
    `challengeUrl`: a board game's correspondence page. Invite shows only next to
    a player who looks for this lobby's game; every other row had nothing but the
    profile link (plan "Blockli-Optimierung", P3: "only opens profiles"), so it
    gets "Challenge", that page with the player picked (`?to=<id>`).

    Who is looking right now (plan "Proof of Pong", P4): a member's data on the presence channel is what they had when
    they joined it, so a player who switched before the viewer joined showed as they were at their join. The block
    hands the presence (window.esportsPresence.seed(), resources/js/echo.js) the list of everyone looking as this page
    renders it, once (wire:ignore: a later render of the lobby never sends an older list); pushes after it keep it
    current. Every source carries the server time of its read (the list, a member's join, a push), and the newest one
    wins whatever order the page hears them in (P6). The newest switches first, at most 200. Only in a page's first render: a Livewire roundtrip (a lazy lobby
    among them) sends no list, so it can neither send an older one nor clear the known ones.
--}}
@php
    $lookingTag ??= __('looking: Blitz 5+3');
    $on = $user?->looking_to_play === $lookingKey;
    // Stamped before the read, as the channel's join data (routes/channels.php): a switch saved after it is newer.
    $lookingSeedAt = round(microtime(true) * 1000, 3);
    $lookingNow = $user === null || \Livewire\Livewire::isLivewireRequest() ? null : \App\Models\User::query()->whereNotNull('looking_to_play')->latest('updated_at')->limit(200)->pluck('looking_to_play', 'id')->all();
@endphp

<div id="online-now" {{ $attributes->class('flex scroll-mt-4 flex-col gap-2') }} data-test="online-now">
    @if ($lookingNow !== null)
        <span hidden wire:ignore x-init="window.esportsPresence ? window.esportsPresence.seed({{ \Illuminate\Support\Js::from((object) $lookingNow) }}, {{ \Illuminate\Support\Js::from($lookingSeedAt) }}) : (window.esportsPresenceSeed = { looking: {{ \Illuminate\Support\Js::from((object) $lookingNow) }}, at: {{ \Illuminate\Support\Js::from($lookingSeedAt) }} })" data-test="online-looking-now"></span>
    @endif
    <span class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
        <h3 id="online-h" tabindex="-1" class="m-0 flex items-center gap-2 text-[15px] font-bold"><span class="size-2 rounded-full bg-win" aria-hidden="true"></span>{{ __('Online now') }} <b class="text-ink-2" x-show="connection === 'connected'" x-text="others.length" data-test="online-count"></b></h3>
        @if ($user && $toggle)
            <x-lobby.looking-toggle :on="$on" />
        @endif
    </span>
    @if ($user)
        <p x-show="lookingFailed" x-cloak role="alert" class="m-0 text-[13px] text-loss" data-test="looking-failed">{{ __('That did not save. The switch is back where it was, please try again.') }}</p>
    @endif
    @if (! $user)
        <p class="m-0 text-[13px] text-ink-2">{{ __('Log in to see who is online and to invite a friend.') }}</p>
    @else
        <p class="m-0 text-[13px] text-ink-2" x-show="connection !== 'connected'">{{ __('The online list needs the live connection. Connecting …') }}</p>
        <p class="m-0 text-[13px] text-ink-2" x-show="connection === 'connected' && others.length === 0" data-test="online-empty">{{ $empty ?? $emptyText ?? __('Nobody else is online right now.') }}</p>
        <ul class="m-0 flex {{ $listClass }} list-none flex-col overflow-y-auto p-0" x-show="others.length > 0">
            <template x-for="m in others" :key="m.id">
                {{--
                    The name always keeps its room (review of P5, 2026-09-30: next to the tag and Invite it shrank to
                    0 px on a 375 px phone): picture and name on the first line, Elo and the "looking" tag on a
                    second line under the name, which wraps; only the action on the right keeps its width.
                --}}
                <li class="flex min-h-12 items-center gap-3 border-b border-hairline py-1 text-[13px] last:border-0" data-test="online-player">
                    <span class="flex min-w-0 grow flex-col">
                        {{-- Picture or Blockpile (P10a); the name opens the player card (resources/js/profiles.js). --}}
                        <a :href="@js(url('players')) + '/' + m.npub" :data-player-card="m.npub" :data-pubkey="m.pubkey" :data-player-name="m.name" aria-haspopup="dialog"
                           class="flex min-h-11 min-w-0 items-center gap-3 text-ink hover:text-ink" data-test="online-player-link">
                            <img :src="m.avatar || m.generated" :data-fallback="m.generated" width="24" height="24" loading="lazy" referrerpolicy="no-referrer"
                                 :alt="(m.avatar ? @js(__(':name avatar')) : @js(__(':name avatar, generated'))).replace(':name', m.name)"
                                 x-on:error.once="$el.src = m.generated; $el.alt = @js(__(':name avatar, generated')).replace(':name', m.name)" class="block size-6 shrink-0 rounded-sm bg-raised object-cover">
                            <b class="min-w-0 truncate" x-text="m.name" data-test="online-name"></b>
                        </a>
                        <span class="-mt-1.5 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 pb-1.5 pl-9 empty:hidden" x-show="m.looking === '{{ $lookingKey }}'{{ $showElo ? ' || m.elo' : '' }}">
                            @if ($showElo)
                                <span class="text-xs text-ink-2" x-show="m.elo" data-test="online-elo"><span class="text-ink-3">{{ \App\Support\Chess\ChessModes::short(\App\Support\Chess\ChessModes::DEFAULT) }} </span><span x-text="m.elo"></span><span class="text-ink-3" x-show="m.provisional"> · {{ __('provisional') }}</span></span>
                            @endif
                            <span x-show="m.looking === '{{ $lookingKey }}'" class="max-w-full rounded-xs bg-win-tint px-1.5 py-0.5 text-[11px] font-bold wrap-break-word text-win shadow-ring-win" data-test="online-looking">{{ $lookingTag }}</span>
                        </span>
                    </span>
                    @if ($canInvite)
                        <template x-if="invited(m)">
                            <span class="flex shrink-0 flex-col items-end text-[13px] sm:flex-row sm:items-center sm:gap-1" data-test="invited">
                                <span class="text-ink-2">{{ __('Invited') }} ·</span>
                                <button type="button" x-on:click="$wire.withdrawInvite()" class="inline-flex h-11 cursor-pointer items-center rounded-md px-2 text-[13px] text-loss" data-test="withdraw-invite">{{ __('Withdraw') }}</button>
                            </span>
                        </template>
                        {{-- Only a player who is looking can be invited (ChessInvites::invite, BoardInvites::invite refuse the rest). --}}
                        <template x-if="! invited(m) && m.looking === '{{ $lookingKey }}'">
                            <button type="button" x-on:click="$wire.invite(m.id{{ $inviteMode !== null ? ', '.$inviteMode : '' }})" class="btn-w inline-flex h-11 shrink-0 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="invite">{{ __('Invite') }}</button>
                        </template>
                    @endif
                    @if ($challengeUrl !== null)
                        {{-- Everyone else (or anyone, while the viewer is in a live game): challenge to a correspondence game. --}}
                        <template x-if="{{ $canInvite ? "! invited(m) && m.looking !== '".$lookingKey."'" : 'true' }}">
                            <a :href="@js($challengeUrl) + '?to=' + m.id" class="btn-w inline-flex h-11 shrink-0 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="challenge">
                                {{ __('Challenge') }}<span class="sr-only" x-text="' ' + m.name"></span>
                            </a>
                        </template>
                    @endif
                </li>
            </template>
        </ul>
    @endif
</div>
