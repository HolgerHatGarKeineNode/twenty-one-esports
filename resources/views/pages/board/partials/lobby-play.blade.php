{{--
    The ways to play in a board game's lobby (pages/board/⚡lobby), after
    the chess lobby's tile row (pages/chess/partials/lobby-play): Blitz opens
    its panel in place under the row, Correspondence (chess: Daily chess)
    and Tournaments lead into their flow, "Challenge a player" goes to the
    online list. A search or an invite waiting for its answer shows its card
    there whatever the tile. A board game without a blitz mode (all three
    since the user dropped blitz on 2026-10-07) has no Blitz tile and no
    panel: Correspondence is its orange tile, the invite plays one move a day. Alpine `stage` is the open panel ('blitz' |
    null), `rated` the Casual/Rated choice sent with "Find opponent".

    Rated is selectable only while the board games' rated queue is offered
    and open for this player (RatedBoard); otherwise it is disabled with a
    short badge on the option and the reason behind "?".
--}}
@php
    use App\Games\GameRegistry;
    use App\Models\BoardGame;
    use App\Support\Board\BoardQueue;
    use App\Support\PreSeason;
    use App\Support\Rating\Ratings;
    use App\Support\Series\Ladders;

    $range = config('esports.board_games.queue.range');
    $rating = Ratings::forUser($user?->id, $slug, 'blitz', \App\Models\Rating::CASUAL)['rating'];
    // The rated queue off: rated is closed for everyone, with the reason RatedBoard gives for it.
    $ratedRefusal = $this->ratedOffered ? $this->ratedRefusal : __('Rated :game is not open yet. Games are casual for now.', ['game' => $name]);
    $searching = $this->searching;
    $correspondence = $this->correspondence;
    $hasCorrespondence = app(GameRegistry::class)->mode($slug, BoardGame::CORRESPONDENCE) !== null;
    $hasBlitz = app(GameRegistry::class)->mode($slug, 'blitz') !== null;
    $tiles = (int) $hasBlitz + (int) $hasCorrespondence + 2;
    $waitingThere = $correspondence['challenges'] + $correspondence['yourMove'];
    // A soft hyphen: "Correspondence" is wider than a phone's tile, and Chrome on Linux has no hyphenation of its own
    // (measured at 375 px: it broke as "Correspondenc / e"). German "Fernpartie" fits.
    $correspondenceLabel = str_replace('Correspondence', "Correspon\u{00AD}dence", __('Correspondence'));
    $next = $this->nextTournament;
    $zone = PreSeason::timezoneFor($user);
    $busy = $entry !== null || $outgoing !== null;
    $tag = 'inline-block rounded-xs px-1.5 text-[11px] leading-4 font-bold';
@endphp

<section aria-labelledby="play-h" class="flex flex-col gap-2 lg:gap-3" data-test="play"
         x-data="{ stage: @js($busy ? 'blitz' : null), help: false, rated: false }"
         x-init="if (stage === null && location.hash === '#blitz') stage = 'blitz'">
    <h2 id="play-h" class="sr-only">{{ __('Ways to play') }}</h2>

    <ul role="list" @class(['m-0 grid list-none grid-cols-2 gap-2 p-0 lg:gap-3', 'lg:grid-cols-4' => $tiles === 4, 'lg:grid-cols-3' => $tiles === 3, 'lg:grid-cols-2' => $tiles === 2]) data-test="play-grid">
        @if ($hasBlitz)
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
        @endif
        @if ($hasCorrespondence)
            {{-- Without blitz the one orange tile, across both columns below lg so the row of three keeps no hole. --}}
            <li @class(['max-lg:col-span-2' => ! $hasBlitz])>
                <x-chess.lobby-tile :label="$correspondenceLabel" icon="calendar" :href="route('board.correspondence', $slug)" data-test="play-correspondence" :variant="$hasBlitz ? 'default' : 'primary'"
                                    :count="$waitingThere" :count-label="trans_choice(':count challenge to answer|:count challenges to answer', $correspondence['challenges']).', '.trans_choice('your move in :count game|your move in :count games', $correspondence['yourMove'])">
                    <x-slot:meta>
                        @if ($correspondence['yourMove'] > 0)
                            {{ __('Your move') }}
                        @elseif ($correspondence['challenges'] > 0)
                            {{ trans_choice(':count challenge to answer|:count challenges to answer', $correspondence['challenges']) }}
                        @elseif ($correspondence['running'] > 0)
                            {{ trans_choice(':count game running|:count games running', $correspondence['running']) }}
                        @else
                            {{ __('1 move a day') }}
                        @endif
                    </x-slot:meta>
                </x-chess.lobby-tile>
            </li>
        @endif
        <li>
            @auth
                {{-- A live game with someone online now: the invite buttons are in the online list. --}}
                <x-chess.lobby-tile :label="__('Challenge a player')" icon="send" data-test="play-challenge" aria-controls="online-now"
                                    x-on:click="document.getElementById('online-now').scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); (document.querySelector('#online-now [data-test=invite], #online-now [data-test=challenge]') ?? document.getElementById('online-h')).focus({ preventScroll: true })">
                    <x-slot:meta><b class="text-ink" x-text="connection === 'connected' ? others.length : '–'" data-test="play-online-count">–</b> {{ __('online') }}</x-slot:meta>
                </x-chess.lobby-tile>
            @else
                <x-chess.lobby-tile :label="__('Challenge a player')" icon="send" :href="route('login')" data-test="play-challenge">
                    <x-slot:meta>{{ __('Log in to play') }}</x-slot:meta>
                </x-chess.lobby-tile>
            @endauth
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
    </ul>

    @if ($entry)
        {{-- Finding an opponent: the chess lobby's card, with this board game's queue. --}}
        @php($queueRange = app(BoardQueue::class)->range($entry))
        <div id="lobby-searching" role="status" aria-live="polite" class="flex flex-col items-center gap-4 rounded-lg bg-card p-4 shadow-ring-btc" data-test="lobby-searching" data-rated="{{ $entry->rated ? 'true' : 'false' }}">
            <div class="cube mt-4 flex size-[124px] flex-col items-center justify-between bg-[linear-gradient(180deg,#2A1F0E,#17120A)] px-2 py-2.5 text-center" aria-hidden="true">
                <span class="text-[13px] font-bold">~{{ $entry->rating }} Elo</span>
                <span class="text-[11px] text-btc-hi">{{ $entry->rating - $queueRange }} – {{ $entry->rating + $queueRange }}</span>
                <span class="text-base font-bold">5+3</span>
                <span class="text-[11px] text-ink-2" data-test="searching-kind">{{ $entry->rated ? __('Blitz · rated') : __('Blitz · casual') }}</span>
            </div>
            <span class="text-center font-display text-lg font-bold">{{ $entry->rated ? __('Finding a rated opponent for :game …', ['game' => $name]) : __('Finding an opponent for :game …', ['game' => $name]) }}</span>
            <span class="block h-1 w-full max-w-[420px] overflow-hidden rounded-xs bg-raised"><span class="sweep block h-1 w-2/5 rounded-xs bg-btc"></span></span>
            <span class="text-[13px] leading-normal text-ink-2">{{ trans_choice(':count player searching right now.|:count players searching right now.', $searching) }}</span>
            {{-- P57: the rated queue skips players who do not list each other; say so in counts (nobody is named: presence), and offer the fix. --}}
            @php($ratedQueue = $this->ratedQueue)
            @if ($entry->rated && $ratedQueue['others'] > 0 && $ratedQueue['mutual'] === 0)
                <x-opponents.needs-mutual class="self-stretch text-left"
                    :heading="trans_choice(':count other player searches rated right now, but you do not list each other, so the queue cannot pair you.|:count other players search rated right now, but you list each other with none of them, so the queue cannot pair you.', $ratedQueue['others'])"
                    :note="$ratedQueue['asking'] > 0
                        ? trans_choice(':count player in the queue lists you. Accept the request on your Opponents page and the queue can pair you.|:count players in the queue list you. Accept their requests on your Opponents page and the queue can pair you.', $ratedQueue['asking'])
                        : __('A rated game needs both of you to add the other as an opponent. Casual pairs you with anyone.')">
                    @if ($ratedQueue['asking'] > 0)
                        <x-button :href="route('settings.opponents').'#requests'" data-test="needs-mutual-requests">{{ __('Open your requests') }}</x-button>
                    @endif
                    <x-button variant="quiet" wire:click="searchCasualInstead" data-test="needs-mutual-casual">{{ __('Search casual instead') }}</x-button>
                </x-opponents.needs-mutual>
            @endif
            <span class="flex flex-wrap items-center gap-3 self-stretch">
                <x-button variant="quiet" wire:click="cancelSearch" class="grow" data-test="cancel-search">{{ __('Cancel') }}</x-button>
                @if ($hasCorrespondence)
                    <a href="{{ route('board.correspondence', $slug) }}" class="inline-flex min-h-11 items-center text-[13px] text-ink">{{ __('Correspondence games') }}</a>
                @endif
            </span>
        </div>
    @elseif ($outgoing)
        {{-- Waiting for the invited player's answer. --}}
        <div id="lobby-waiting" class="flex flex-col gap-3.5 rounded-lg bg-card p-5 shadow-ring-btc" data-test="lobby-invited">
            <span class="flex items-center gap-3"><span aria-hidden="true" class="block size-5 shrink-0 animate-spin rounded-full border-2 border-line border-t-btc motion-reduce:animate-none"></span><b class="min-w-0 text-base wrap-anywhere" data-test="waiting-name">{{ __('Waiting for :name', ['name' => $outgoing->invitee->displayName()]) }}</b></span>
            <p class="m-0 text-[13px] text-ink-2">{{ $outgoing->mode === BoardGame::CORRESPONDENCE ? __('1 move a day · Casual · colours drawn at random') : __('Blitz 5+3 · Casual · colours drawn at random') }}</p>
            <span class="flex gap-2.5">
                <button type="button" wire:click="withdrawInvite" class="inline-flex h-11 cursor-pointer items-center justify-center rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss" data-test="withdraw-invite">{{ __('Withdraw') }}</button>
            </span>
        </div>
    @elseif ($hasBlitz)
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
                            <span class="rounded-xs bg-raised px-1.5 py-0.5 text-[11px] font-normal text-ink-2" data-test="kind-rated-badge">{{ Ladders::isOpen($slug, 'blitz') ? __('not open yet') : __('from Block 0') }}</span>
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
                    <button type="button" x-on:click="$wire.findOpponent(rated)" data-test="find-opponent-button"
                            class="btn-p inline-flex min-h-12 grow cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-5 font-display text-base font-bold text-on-btc lg:min-w-64 lg:grow-0">
                        <x-icon name="bolt" :size="18" />{{ __('Find opponent') }}
                    </button>
                @else
                    <x-button :href="route('login')" class="min-h-12 grow lg:min-w-64 lg:grow-0" data-test="lobby-login">{{ __('Log in to play') }}</x-button>
                @endauth
            </div>
            <div id="blitz-help" x-show="help" x-cloak class="flex flex-col gap-2 border-t border-hairline px-2 pt-3 pb-1 text-[13px] leading-normal text-ink-2 lg:order-last lg:basis-full" data-test="blitz-help">
                <p id="kind-why" class="m-0 max-w-[72ch]" data-test="kind-why">@if ($ratedRefusal !== null){{ $ratedRefusal }}@else<span x-show="! rated">{{ __('Casual pairs you with anyone online and moves only your casual Elo.') }}</span> <span x-show="rated" x-cloak>{{ trans_choice('Rated pairs you only with a Trusted player you list each other with (you have :count). A win can mine a season block.|Rated pairs you only with Trusted players you list each other with (you have :count). A win can mine a season block.', $this->mutualOpponents) }}</span>@endif</p>
                <p class="m-0 max-w-[72ch]">{{ __('Opponent strength') }}: {{ __('±:range around :rating, wider every :seconds s', ['range' => $range['initial'], 'rating' => $rating, 'seconds' => $range['every_seconds']]) }}</p>
                <p class="m-0 max-w-[72ch]">{{ __('You join the queue and can cancel any time. The game starts as soon as someone in your range is found.') }}</p>
            </div>
        </div>
    @endif
</section>
