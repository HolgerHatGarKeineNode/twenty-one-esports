{{--
    "Live now" in the chess lobby: the running blitz boards with both
    players' ratings, then who is online (presence channel `online`) with
    "Looking to play" and an invite per player. The list and the switch are
    Alpine's (chessLobby in resources/js/chess.js); a Livewire render never
    touches the switch.
--}}
@php
    $liveGames = $this->liveGames;
    $liveRatings = $this->liveRatings;
@endphp

<section aria-labelledby="live-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-4 lg:col-span-5 lg:px-5">
    <div class="flex flex-col gap-2" data-test="now-playing">
        <span class="flex items-baseline justify-between gap-3">
            <h2 id="live-h" class="m-0 flex items-center gap-2 text-[15px] font-bold"><span @class(['size-2 rounded-full', 'animate-live bg-btc' => $liveGames->isNotEmpty(), 'bg-edge' => $liveGames->isEmpty()]) aria-hidden="true"></span>{{ __('Live now') }} <b class="text-ink-2" data-test="live-count">{{ $this->liveCount }}</b></h2>
            <a href="{{ route('games.index') }}" class="inline-flex min-h-11 items-center text-xs text-ink lg:min-h-6" data-test="all-live-games">{{ __('All live games') }}</a>
        </span>
        @if ($liveGames->isEmpty())
            <p class="m-0 text-[13px] text-ink-2">{{ __('No live game right now.') }}</p>
        @else
            <ul role="list" class="m-0 flex list-none flex-col p-0">
                @foreach ($liveGames as $live)
                    @php
                        $ratings = $liveRatings[$live->id];
                    @endphp
                    <li wire:key="live-{{ $live->id }}">
                        <a href="{{ route('games.show', $live) }}" class="grid min-h-14 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 border-b border-hairline py-2 text-ink hover:text-ink" data-test="live-game">
                            <span class="flex min-w-0 flex-col gap-1 text-[13px]">
                                @foreach (['w' => $live->white, 'b' => $live->black] as $side => $player)
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span @class(['size-2.5 shrink-0 rounded-[2px] shadow-ring', 'bg-ink' => $side === 'w', 'bg-ground' => $side === 'b']) aria-hidden="true"></span>
                                        <b class="truncate">{{ $player?->displayName() }}</b>
                                        <span class="shrink-0 text-xs text-ink-2">{{ $ratings[$side]['rating'] }}</span>
                                    </span>
                                @endforeach
                            </span>
                            <span class="flex flex-col items-end gap-1 text-xs text-ink-2">
                                <span class="inline-flex items-center gap-1.5 text-ink"><x-icon name="eye" :size="14" />{{ __('Watch') }}</span>
                                <span>{{ __('move :n', ['n' => intdiv($live->ply, 2) + 1]) }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Online now: presence channel `online`. --}}
    <div id="online-now" class="flex scroll-mt-4 flex-col gap-2" data-test="online-now">
        <span class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <h3 id="online-h" tabindex="-1" class="m-0 flex items-center gap-2 text-[15px] font-bold"><span class="size-2 rounded-full bg-win" aria-hidden="true"></span>{{ __('Online now') }} <b class="text-ink-2" x-show="connection === 'connected'" x-text="others.length" data-test="online-count"></b></h3>
            @auth
                {{-- The page's own state (chessLobby.looking): a Livewire render never touches it. --}}
                <button type="button" wire:ignore x-on:click="toggleLooking()" role="switch" aria-checked="{{ $user->looking_to_play ? 'true' : 'false' }}" x-bind:aria-checked="looking ? 'true' : 'false'" data-test="looking-toggle"
                        class="flex h-11 cursor-pointer items-center gap-2.5 rounded-md border border-line bg-well px-3 text-[13px] text-ink">
                    <span class="relative h-5 w-9 shrink-0 rounded-full transition-colors motion-reduce:transition-none" x-bind:class="looking ? 'bg-btc' : 'bg-raised shadow-ring'"><span class="absolute top-0.5 size-4 rounded-full bg-ink transition-all motion-reduce:transition-none" x-bind:class="looking ? 'left-[18px]' : 'left-0.5'"></span></span>
                    {{ __('Looking to play') }}
                    <b class="min-w-7 text-left" x-bind:class="looking ? 'text-win' : 'text-ink-2'" x-text="looking ? @js(__('On')) : @js(__('Off'))" data-test="looking-state">{{ $user->looking_to_play ? __('On') : __('Off') }}</b>
                </button>
            @endauth
        </span>
        @auth
            <p x-show="lookingFailed" x-cloak role="alert" class="m-0 text-[13px] text-loss" data-test="looking-failed">{{ __('That did not save. The switch is back where it was, please try again.') }}</p>
        @endauth
        @guest
            <p class="m-0 text-[13px] text-ink-2">{{ __('Log in to see who is online and to invite a friend.') }}</p>
        @else
            <p class="m-0 text-[13px] text-ink-2" x-show="connection !== 'connected'">{{ __('The online list needs the live connection. Connecting …') }}</p>
            <p class="m-0 text-[13px] text-ink-2" x-show="connection === 'connected' && others.length === 0">{{ __('Nobody else is online right now.') }}</p>
            <ul class="m-0 flex max-h-80 list-none flex-col overflow-y-auto p-0" x-show="others.length > 0">
                <template x-for="m in others" :key="m.id">
                    <li class="flex min-h-12 items-center gap-3 border-b border-hairline text-[13px] last:border-0" data-test="online-player">
                        {{-- Picture or Blockpile (P10a); the name opens the player card (resources/js/profiles.js). --}}
                        <a :href="@js(url('players')) + '/' + m.npub" :data-player-card="m.npub" :data-pubkey="m.pubkey" :data-player-name="m.name" aria-haspopup="dialog"
                           class="flex min-h-11 min-w-0 grow items-center gap-3 text-ink hover:text-ink" data-test="online-player-link">
                            <img :src="m.avatar || m.generated" :data-fallback="m.generated" width="24" height="24" loading="lazy" referrerpolicy="no-referrer"
                                 :alt="(m.avatar ? @js(__(':name avatar')) : @js(__(':name avatar, generated'))).replace(':name', m.name)"
                                 x-on:error.once="$el.src = m.generated; $el.alt = @js(__(':name avatar, generated')).replace(':name', m.name)" class="block size-6 shrink-0 rounded-sm bg-raised object-cover">
                            <b class="min-w-0 truncate" x-text="m.name"></b>
                        </a>
                        <span class="shrink-0 text-xs text-ink-2 max-sm:hidden" x-show="m.elo" data-test="online-elo"><span x-text="m.elo"></span><span class="text-ink-3" x-show="m.provisional"> · {{ __('provisional') }}</span></span>
                        <span x-show="m.looking" class="shrink-0 rounded-xs bg-win-tint px-1.5 py-0.5 text-[11px] font-bold text-win shadow-ring-win">{{ __('looking: Blitz 5+3') }}</span>
                        @if (! $active)
                            <template x-if="invited(m)">
                                <span class="flex shrink-0 items-center gap-1 text-[13px]" data-test="invited">
                                    <span class="text-ink-2">{{ __('Invited') }} ·</span>
                                    <button type="button" x-on:click="$wire.withdrawInvite()" class="inline-flex h-11 cursor-pointer items-center rounded-md px-2 text-[13px] text-loss" data-test="withdraw-invite">{{ __('Withdraw') }}</button>
                                </span>
                            </template>
                            {{-- Only a player who is looking can be invited (ChessInvites::invite refuses the rest). --}}
                            <template x-if="! invited(m) && m.looking">
                                <button type="button" x-on:click="$wire.invite(m.id)" class="btn-w inline-flex h-11 shrink-0 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="invite">{{ __('Invite') }}</button>
                            </template>
                        @endif
                    </li>
                </template>
            </ul>
        @endguest
    </div>
</section>
