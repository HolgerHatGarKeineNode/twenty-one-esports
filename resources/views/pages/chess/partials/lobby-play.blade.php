{{--
    The lobby's ways to play (pages/chess/⚡lobby): one row of tiles and the
    panel a tile opens in place under the row. Alpine `stage` is the open
    panel ('blitz' | 'invite' | null); a search or a waiting invite shows its
    card there whatever the stage, and `/chess#blitz` opens the blitz panel.

    Casual/Rated (P7e) is the page's choice (Alpine `rated`) and travels with
    "Find opponent"; Rated is disabled while closed for this player, with a
    short badge on the option and the reason behind "?".
--}}
@php
    use App\Support\Chess\ChessQueue;
    use App\Support\PreSeason;
    use App\Support\Series\Ladders;

    $range = config('esports.chess.queue.range');
    $rating = (int) config('esports.chess.queue.start_rating');
    $ratedRefusal = $this->ratedRefusal;
    $searching = $this->searching;
    $yourMove = $this->yourMove;
    $next = $this->nextTournament;
    $zone = PreSeason::timezoneFor($user);
    $busy = $entry !== null || $outgoing !== null;
    $tag = 'inline-block rounded-xs px-1.5 text-[11px] leading-4 font-bold';
@endphp

<section aria-labelledby="play-h" class="flex flex-col gap-2 lg:gap-3" data-test="play"
         x-data="{ stage: @js($busy ? 'blitz' : null), help: false, rated: false }"
         x-init="if (stage === null && location.hash === '#blitz') stage = 'blitz'">
    <h2 id="play-h" class="sr-only">{{ __('Ways to play') }}</h2>

    <ul role="list" class="m-0 grid list-none grid-cols-2 gap-2 p-0 lg:grid-cols-6 lg:gap-3" data-test="play-grid">
        <li>
            <x-chess.lobby-tile :label="__('Blitz')" variant="primary" data-test="play-blitz"
                                x-on:click="stage = stage === 'blitz' ? null : 'blitz'" x-bind:aria-expanded="(stage === 'blitz').toString()"
                                aria-expanded="{{ $busy ? 'true' : 'false' }}" aria-controls="{{ $entry ? 'lobby-searching' : ($outgoing ? 'lobby-waiting' : 'lobby-blitz') }}">
                <x-slot:glyph><span class="font-display text-lg leading-none font-bold tracking-tight lg:text-[1.75rem]">5+3</span></x-slot:glyph>
                <x-slot:meta>
                    @if ($entry)
                        <span class="{{ $tag }} bg-btc text-on-btc" data-test="play-blitz-state">{{ __('You are searching') }}</span>
                    @else
                        <b class="text-ink" data-test="play-blitz-searching">{{ $searching }}</b> {{ __('searching') }}
                    @endif
                </x-slot:meta>
            </x-chess.lobby-tile>
        </li>
        <li>
            <x-chess.lobby-tile :label="__('Daily chess')" icon="calendar" :href="route('chess.challenge')" data-test="play-daily"
                                :count="$yourMove" :count-label="trans_choice(':count game waits for your move|:count games wait for your move', $yourMove)">
                <x-slot:meta>
                    @if ($yourMove > 0)
                        {{ __('Your move') }}
                    @elseif ($this->dailyGames->isNotEmpty())
                        {{ trans_choice(':count game running|:count games running', $this->dailyGames->count()) }}
                    @else
                        {{ __('1 move a day') }}
                    @endif
                </x-slot:meta>
            </x-chess.lobby-tile>
        </li>
        <li>
            @auth
                {{-- A live blitz game with someone online now: the invite buttons are in the online list. --}}
                <x-chess.lobby-tile :label="__('Challenge a player')" icon="send" data-test="play-challenge" aria-controls="online-now"
                                    x-on:click="document.getElementById('online-now').scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); (document.querySelector('#online-now [data-test=invite]') ?? document.getElementById('online-h')).focus({ preventScroll: true })">
                    <x-slot:meta><b class="text-ink" x-text="connection === 'connected' ? others.length : '–'" data-test="play-online-count">–</b> {{ __('online') }}</x-slot:meta>
                </x-chess.lobby-tile>
            @else
                <x-chess.lobby-tile :label="__('Challenge a player')" icon="send" :href="route('login')" data-test="play-challenge">
                    <x-slot:meta>{{ __('Log in to play') }}</x-slot:meta>
                </x-chess.lobby-tile>
            @endauth
        </li>
        <li>
            <x-chess.lobby-tile :label="__('Invite a friend')" icon="link" data-test="play-invite"
                                x-on:click="stage = stage === 'invite' ? null : 'invite'" x-bind:aria-expanded="(stage === 'invite').toString()"
                                aria-expanded="false" aria-controls="lobby-invite">
                <x-slot:meta>{{ __('Send a link') }}</x-slot:meta>
            </x-chess.lobby-tile>
        </li>
        <li>
            <x-chess.lobby-tile :label="__('Tournaments')" icon="trophy" :href="$next ? route('tournaments.show', $next) : route('tournaments.index')" data-test="play-tournaments">
                <x-slot:meta>
                    @if ($next)
                        <span class="sr-only">{{ __('Sign-up') }} </span>{{ __('until :when', ['when' => $next->signup_closes_at->copy()->timezone($zone)->locale(app()->getLocale())->isoFormat('ddd HH:mm')]) }}
                    @else
                        {{ __('None open') }}
                    @endif
                </x-slot:meta>
                @if ($next)
                    <x-slot:detail>{{ $next->name }}</x-slot:detail>
                @endif
            </x-chess.lobby-tile>
        </li>
        <li>
            <x-chess.lobby-tile :label="__('Team match')" icon="clans" variant="muted" data-test="play-team">
                <x-slot:meta><span class="{{ $tag }} bg-raised text-ink-2" data-test="play-team-soon">{{ __('Soon') }}</span></x-slot:meta>
                <x-slot:detail>{{ __('Clan against clan') }}</x-slot:detail>
            </x-chess.lobby-tile>
        </li>
    </ul>

    @if ($entry)
        {{-- ChessStates "Finding opponent" --}}
        <div id="lobby-searching" data-check-at="{{ $this->checkAt }}" role="status" aria-live="polite" class="flex flex-col items-center gap-4 rounded-lg bg-card p-4 shadow-ring-btc" data-test="searching">
            <div class="cube mt-4 flex size-[124px] flex-col items-center justify-between bg-[linear-gradient(180deg,#2A1F0E,#17120A)] px-2 py-2.5 text-center" aria-hidden="true">
                <span class="text-[13px] font-bold">~{{ $entry->rating }} Elo</span>
                <span class="text-[11px] text-btc-hi">{{ $entry->rating - app(ChessQueue::class)->range($entry) }} – {{ $entry->rating + app(ChessQueue::class)->range($entry) }}</span>
                <span class="text-base font-bold">5+3</span>
                <span class="text-[11px] text-ink-2" data-test="searching-kind">{{ $entry->rated ? __('Blitz · rated') : __('Blitz · casual') }}</span>
                <span class="text-[11px]" x-text="since({{ $entry->joined_at->getTimestampMs() }})"></span>
            </div>
            <span class="font-display text-lg font-bold">{{ __('Finding opponent … 5+3') }}</span>
            <span class="block h-1 w-full max-w-[420px] overflow-hidden rounded-xs bg-raised"><span class="sweep block h-1 w-2/5 rounded-xs bg-btc"></span></span>
            <span class="text-[13px] leading-normal text-ink-2">
                {{ trans_choice(':count player searching right now.|:count players searching right now.', $searching) }}
                {{ __('Your range: ±:range around :rating, it opens by :step every :seconds s. As soon as someone fits, the game starts, no extra click.', ['range' => app(ChessQueue::class)->range($entry), 'rating' => $entry->rating, 'step' => $range['step'], 'seconds' => $range['every_seconds']]) }}
            </span>
            {{-- P5c: asked once per browser, when the player joins the queue; the browser's own prompt only after "Allow". --}}
            <div x-show="askNotify" x-cloak class="flex flex-col gap-2.5 self-stretch rounded-md bg-toast-challenge p-3 text-left shadow-ring-btc" data-test="notify-prompt">
                <span class="flex items-start gap-2.5 text-[13px]"><x-icon name="bell" :size="16" class="mt-0.5 shrink-0 text-btc" /><span><b>{{ __('Hear about it in another tab?') }}</b> <span class="text-ink-2">{{ __('A desktop notification when an opponent is found, while this tab is in the background.') }}</span></span></span>
                <span class="grid grid-cols-2 gap-2">
                    <x-button variant="quiet" x-on:click="answerNotify(false)" data-test="notify-prompt-no">{{ __('Not now') }}</x-button>
                    <x-button icon="bell" x-on:click="answerNotify(true)" data-test="notify-prompt-allow">{{ __('Allow') }}</x-button>
                </span>
            </div>
            <span class="flex flex-wrap items-center gap-3 self-stretch">
                <x-button variant="quiet" wire:click="cancelSearch" class="grow" data-test="cancel-search">{{ __('Cancel') }}</x-button>
                <a href="{{ route('chess.challenge') }}" class="inline-flex min-h-11 items-center text-[13px] text-ink">{{ __('Play daily chess instead') }}</a>
            </span>
        </div>
    @elseif ($outgoing)
        {{-- ChessStates "Waiting for a friend" --}}
        <div id="lobby-waiting" data-check-at="{{ $this->checkAt }}" class="flex flex-col gap-3.5 rounded-lg bg-card p-5 shadow-ring-btc" data-test="waiting-for-friend">
            <span class="flex items-center gap-3"><span aria-hidden="true" class="block size-5 shrink-0 animate-spin rounded-full border-2 border-line border-t-btc"></span><b class="text-base">{{ __('Waiting for :name', ['name' => $outgoing->invitee->displayName()]) }}</b></span>
            <div class="flex flex-col">
                <div class="grid h-9 grid-cols-[110px_minmax(0,1fr)] items-center border-b border-hairline text-[13px]"><span class="text-ink-2">{{ __('Time control') }}</span><span>{{ __('Blitz 5+3, colours at random') }}</span></div>
                <div class="grid h-9 grid-cols-[110px_minmax(0,1fr)] items-center border-b border-hairline text-[13px]"><span class="text-ink-2">{{ __('Friend') }}</span><span>{{ __('online when invited') }}</span></div>
                <div class="grid h-9 grid-cols-[110px_minmax(0,1fr)] items-center border-b border-hairline text-[13px]"><span class="text-ink-2">{{ __('Open for') }}</span><span role="timer" x-text="since({{ $outgoing->created_at?->getTimestampMs() ?? 0 }})"></span></div>
            </div>
            <span class="flex gap-2.5">
                <button type="button" wire:click="withdrawInvite" class="inline-flex h-11 cursor-pointer items-center justify-center rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss">{{ __('Withdraw') }}</button>
            </span>
        </div>
    @else
        {{-- Quick play: kind, range, one call to action; the explanations behind "?". --}}
        <div id="lobby-blitz" role="region" aria-label="{{ __('Blitz 5+3') }}" x-show="stage === 'blitz'" x-cloak
             x-transition:enter="transition duration-200 ease-out motion-reduce:transition-none" x-transition:enter-start="opacity-0 -translate-y-1"
             class="flex flex-col gap-2 rounded-lg bg-card p-2 shadow-ring-btc lg:flex-row lg:flex-wrap lg:items-center lg:gap-3 lg:p-3" data-test="find-opponent">
            <div class="flex gap-2 lg:contents">
                <div role="radiogroup" aria-label="{{ __('Game kind') }}" class="grid min-w-0 grow grid-cols-2 gap-1 rounded-md bg-ground p-1 shadow-ring lg:w-80 lg:grow-0" data-test="game-kind" data-rated-open="{{ $ratedRefusal === null ? 'true' : 'false' }}">
                    <button type="button" role="radio" aria-checked="true" x-bind:aria-checked="rated ? 'false' : 'true'" x-on:click="rated = false" aria-describedby="kind-why" data-test="kind-casual"
                            class="flex min-h-11 cursor-pointer items-center justify-center rounded-sm px-2 text-[13px] font-bold text-btc-hi shadow-[inset_0_-2px_0_var(--color-btc)] bg-raised"
                            x-bind:class="rated ? 'bg-transparent! shadow-none! text-ink!' : ''">{{ __('Casual') }}</button>
                    <button type="button" role="radio" aria-checked="false" x-bind:aria-checked="rated ? 'true' : 'false'" x-on:click="rated = true" aria-describedby="kind-why" data-test="kind-rated"
                            @disabled($ratedRefusal !== null)
                            class="flex min-h-11 cursor-pointer flex-wrap items-center justify-center gap-x-2 rounded-sm px-2 text-[13px] font-bold text-ink disabled:cursor-not-allowed disabled:text-ink-3"
                            x-bind:class="rated ? 'bg-raised text-btc-hi! shadow-[inset_0_-2px_0_var(--color-btc)]' : ''">
                        {{ __('Rated') }}
                        @if ($ratedRefusal !== null)
                            <span class="rounded-xs bg-raised px-1.5 py-0.5 text-[11px] font-normal text-ink-2" data-test="kind-rated-badge">{{ Ladders::isOpen('chess', 'blitz') ? __('not open yet') : __('from Block 0') }}</span>
                        @endif
                    </button>
                </div>
                <button type="button" x-on:click="help = ! help" x-bind:aria-expanded="help.toString()" aria-expanded="false" aria-controls="blitz-help" data-test="kind-help"
                        class="inline-flex size-11 shrink-0 cursor-pointer items-center justify-center self-center rounded-md border border-line bg-well font-display text-base font-bold text-ink-2 hover:text-ink aria-expanded:border-btc aria-expanded:text-btc-hi lg:order-last">
                    <span aria-hidden="true">?</span><span class="sr-only">{{ __('How blitz works') }}</span>
                </button>
            </div>
            <div class="flex gap-2 lg:contents">
                <span class="flex min-h-12 shrink-0 flex-col justify-center rounded-md px-3 shadow-ring lg:min-h-11" data-test="blitz-range">
                    <span class="sr-only">{{ __('Opponent strength') }}:</span>
                    <b class="text-[15px] leading-5">±{{ $range['initial'] }}</b>
                    <span class="text-[11px] leading-4 text-ink-2">{{ __('around :rating', ['rating' => $rating]) }}</span>
                </span>
                @auth
                    <button type="button" x-on:click="joinQueue(rated)" data-test="find-opponent-button"
                            class="btn-p inline-flex min-h-12 grow cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-5 font-display text-base font-bold text-on-btc lg:min-w-64 lg:grow-0">
                        <x-icon name="bolt" :size="18" />{{ __('Find opponent') }}
                    </button>
                @else
                    <x-button :href="route('login')" class="min-h-12 grow lg:min-w-64 lg:grow-0" data-test="find-opponent-login">{{ __('Log in to play') }}</x-button>
                @endauth
            </div>
            <div id="blitz-help" x-show="help" x-cloak class="flex flex-col gap-2 border-t border-hairline px-2 pt-3 pb-1 text-[13px] leading-normal text-ink-2 lg:order-last lg:basis-full" data-test="blitz-help">
                <p id="kind-why" class="m-0 max-w-[72ch]" data-test="kind-why">@if ($ratedRefusal !== null){{ $ratedRefusal }}@else<span x-show="! rated">{{ __('Casual pairs you with anyone online and moves only your casual Elo.') }}</span> <span x-show="rated" x-cloak>{{ trans_choice('Rated pairs you only with a Trusted player you list each other with (you have :count).|Rated pairs you only with Trusted players you list each other with (you have :count).', $this->mutualOpponents) }}</span>@endif</p>
                <p class="m-0 max-w-[72ch]">{{ __('Opponent strength') }}: {{ __('±:range around :rating, wider every :seconds s', ['range' => $range['initial'], 'rating' => $rating, 'seconds' => $range['every_seconds']]) }}</p>
                <p class="m-0 max-w-[72ch]">{{ __('You join the queue and can cancel any time. The game starts as soon as someone in your range is found.') }}</p>
            </div>
        </div>
    @endif

    {{-- Invite a friend by link (P6b): the lobby's module, opened by its tile. --}}
    <div id="lobby-invite" x-show="stage === 'invite'" x-cloak x-transition:enter="transition duration-200 ease-out motion-reduce:transition-none" x-transition:enter-start="opacity-0 -translate-y-1">
        <livewire:invite-link place="lobby" />
    </div>
</section>
