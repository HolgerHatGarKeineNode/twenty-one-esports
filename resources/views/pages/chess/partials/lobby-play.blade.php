{{--
    The lobby's ways to play (pages/chess/⚡lobby): one row of tiles and the
    panel a tile opens in place under the row. Alpine `stage` is the open
    panel ('quick' | 'invite' | null); a search or a waiting invite shows its
    card there whatever the stage.

    Rapid 10+5 and Blitz 5+3 (plan "Schach Rapid und Clan", P2): two tiles,
    Rapid first and twice as wide, both opening the quick-play panel in their
    mode (the page's Alpine `liveMode`); `/chess#rapid` and `/chess#blitz`
    open it. Each tile counts who searches its mode. "Either" (Alpine
    `either`) searches every live mode and pairs with the first fitting
    opponent. While searching, the card shows the switch hint once the player
    waited alone (ChessQueue::switchHint).

    Season 1 or Casual (plan "Season vs. Casual", P3) is on screen before any
    click, under the ways to play; each mode's row tags whether it plays
    Season 1, and "Your path to Season 1" (x-season.path) follows the panels.
    Casual/Rated (P7e) is the page's choice (Alpine `rated`) and travels with
    "Find opponent"; Rated is disabled while closed for this player in the
    open mode. The panel opens on Rated where it is open for the player and
    the mode (Alpine `chose` keeps a click on Casual), with one chip under the
    choice once a season runs: what a rated win mines now, or that casual
    mines nothing. While closed, the word on the option names the kind of
    reason and one short line under the choice says what to do, with a link
    where a page helps (the lobby's ratedReasons); the full sentence stays
    behind "?".
--}}
@php
    use App\Support\Chess\ChessModes;
    use App\Support\Chess\ChessQueue;
    use App\Support\Chess\RatedChess;
    use App\Support\Formats;
    use App\Support\PreSeason;
    use App\Support\SeasonChain\Seasons;

    $range = config('esports.chess.queue.range');
    $rating = (int) config('esports.chess.queue.start_rating');
    $ratedRefusals = $this->ratedRefusals;
    $searching = $this->searching;
    $byMode = $this->searchingByMode;
    $liveModes = ChessModes::live();
    $defaultRefusal = $ratedRefusals[ChessModes::DEFAULT] ?? null;
    // The reason is rendered only where some mode is closed, the casual/rated words only where some mode is open.
    $anyRefused = array_filter($ratedRefusals) !== [];
    $allRefused = ! in_array(null, $ratedRefusals, true);
    $reasons = $this->ratedReasons;
    $defaultReason = $reasons[ChessModes::DEFAULT] ?? null;
    // "Your path to Season 1" under the panels (plan P4), read once: while it lists the steps, the reason line under the
    // choice would say the same again (design-lead review, finding 3); the badge on the rated option stays.
    $seasonPath = $user instanceof \App\Models\User ? app(\App\Support\SeasonChain\SeasonPath::class)->for($user, 'chess', ChessModes::DEFAULT) : null;
    $pathShowsSteps = ($seasonPath['state'] ?? null) === 'steps';
    // One chip under the choice, only while a season runs: what a rated win mines now per mode, or that casual mines nothing.
    $seasonLive = Seasons::live() !== null;
    $chips = [];
    foreach (RatedChess::rewardsNow() as $rewardMode => $rewardSats) {
        $chips[$rewardMode] = __('A win mines :sats sats', ['sats' => Formats::number($rewardSats)]);
    }
    $seasonName = ($liveSeason = \App\Support\Series\Ladders::season()) === null ? __('Season') : \App\Support\Badges\BadgeCopy::season($liveSeason);
    $seasonModes = collect($liveModes)->mapWithKeys(fn (string $mode): array => [$mode => RatedChess::offered() && \App\Support\Series\Ladders::isOpen('chess', $mode)])->all();
    $labels = collect($liveModes)->mapWithKeys(fn (string $mode): array => [$mode => ChessModes::label($mode)])->all();
    $yourMove = $this->yourMove;
    $next = $this->nextTournament;
    $zone = PreSeason::timezoneFor($user);
    $busy = $entry !== null || $outgoing !== null;
    $tag = 'inline-block rounded-xs px-1.5 text-[11px] leading-4 font-bold';
    // Team match (plan "Schach Rapid und Clan", P6): a clan member goes to their clan page (its team matches and the
    // challenge), everyone else to the clans to pick one.
    $teamClan = $user?->clanMember?->clan;
    $teamHref = $teamClan !== null ? route('clans.show', $teamClan) : route('clans.index');
@endphp

<section aria-labelledby="play-h" class="@container flex flex-col gap-3" data-test="play"
         x-data="{ stage: @js($busy ? 'quick' : null), help: false, rated: @js($defaultRefusal === null), chose: null, either: false, refusals: @js($ratedRefusals), reasons: @js($reasons), chips: @js((object) $chips) }"
         {{-- The search's own mode comes in here, never in the page's x-data: a changed root x-data restarts chessLobby and drops its notification question. --}}
         x-init="if (@js($entry?->mode) !== null) liveMode = @js($entry?->mode); if (stage === null && @js($liveModes).includes(location.hash.slice(1))) { liveMode = location.hash.slice(1); stage = 'quick' }; $watch('stage', (open) => open && $nextTick(() => requestAnimationFrame(() => document.getElementById(open === 'quick' ? 'lobby-quick' : 'lobby-invite')?.scrollIntoView({ block: 'nearest', behavior: 'instant' }))))"
         x-effect="rated = refusals[liveMode] === null && chose !== false">
    {{-- "Spielarten" (template T1, GameLobby.dc.html): one row per way to play, rapid first (user, 2026-10-05). --}}
    <div class="gp-panel gp-modes">
        <h2 id="play-h" class="gp-h">{{ __('Ways to play') }}</h2>
        <ul role="list" class="m-0 flex list-none flex-col p-0" data-test="play-grid">
            @foreach ($liveModes as $liveMode)
                <li>
                    <x-chess.lobby-tile :label="ChessModes::label($liveMode)" :action="__('Seek')" :variant="$loop->first ? 'primary' : 'default'" data-test="play-{{ $liveMode }}" data-mode="{{ $liveMode }}"
                                        x-on:click="if (stage === 'quick' && liveMode === '{{ $liveMode }}') { stage = null } else { liveMode = '{{ $liveMode }}'; stage = 'quick' }"
                                        x-bind:aria-expanded="(stage === 'quick' && liveMode === '{{ $liveMode }}').toString()"
                                        aria-expanded="{{ in_array($liveMode, $entry?->takes() ?? [], true) || ($outgoing !== null && $loop->first) ? 'true' : 'false' }}" aria-controls="{{ $entry ? 'lobby-searching' : ($outgoing ? 'lobby-waiting' : 'lobby-quick') }}">
                        <x-slot:meta>
                            @if (in_array($liveMode, $entry?->takes() ?? [], true))
                                <span class="{{ $tag }} bg-btc text-on-btc" data-test="play-{{ $liveMode }}-state">{{ __('Searching') }}</span>
                            @else
                                <span class="rv-live h-5 font-normal"><i></i><b data-test="play-{{ $liveMode }}-searching">{{ $byMode[$liveMode] ?? 0 }}</b> {{ __('searching') }}</span>
                            @endif
                            {{-- Whether this mode plays Season 1 at all (plan P3; Casual always): the choice itself is under the list. --}}
                            <span @class(['rv-tag ms-1 whitespace-nowrap', 'is-btc' => $seasonModes[$liveMode] && ($reasons[$liveMode] ?? null) === null]) data-test="play-{{ $liveMode }}-kinds">{{ $seasonModes[$liveMode] ? $seasonName : __('Casual only') }}</span>
                        </x-slot:meta>
                        <x-slot:detail>{{ $loop->first ? __('Our default: more time to think') : __('as in the casual cups') }}</x-slot:detail>
                    </x-chess.lobby-tile>
                </li>
            @endforeach
            <li>
                @auth
                    {{-- A live game with someone online now: the invite buttons are in the online list. --}}
                    <x-chess.lobby-tile :label="__('Challenge a player')" :action="__('Challenge')" data-test="play-challenge" aria-controls="online-now"
                                        x-on:click="document.getElementById('online-now').scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); (document.querySelector('#online-now [data-test=invite]') ?? document.getElementById('online-h')).focus({ preventScroll: true })">
                        <x-slot:detail>{{ __('Daily chess, or a friend who is online') }}</x-slot:detail>
                        <x-slot:meta><b class="text-ink" x-text="connection === 'connected' ? others.length : '–'" data-test="play-online-count">–</b> {{ __('online') }}</x-slot:meta>
                    </x-chess.lobby-tile>
                @else
                    <x-chess.lobby-tile :label="__('Challenge a player')" :action="__('Log in')" :href="route('login')" data-test="play-challenge">
                        <x-slot:detail>{{ __('Log in to play') }}</x-slot:detail>
                    </x-chess.lobby-tile>
                @endauth
            </li>
            <li>
                <x-chess.lobby-tile :label="__('Invite a friend')" :action="__('Send a link')" data-test="play-invite"
                                    x-on:click="stage = stage === 'invite' ? null : 'invite'" x-bind:aria-expanded="(stage === 'invite').toString()"
                                    aria-expanded="false" aria-controls="lobby-invite">
                    <x-slot:detail>{{ __('Send a link') }}</x-slot:detail>
                </x-chess.lobby-tile>
            </li>
            <li>
                <x-chess.lobby-tile :label="__('Daily chess')" :action="__('Start a game')" :href="route('chess.challenge')" data-test="play-daily"
                                    :count="$yourMove" :count-label="trans_choice(':count game waits for your move|:count games wait for your move', $yourMove)">
                    <x-slot:detail>{{ __('1 move a day') }}</x-slot:detail>
                    <x-slot:meta>
                        @if ($yourMove > 0)
                            {{ __('Your move') }}
                        @elseif ($this->dailyGames->isNotEmpty())
                            {{ trans_choice(':count game running|:count games running', $this->dailyGames->count()) }}
                        @else
                            {{ __('Casual') }}
                        @endif
                    </x-slot:meta>
                </x-chess.lobby-tile>
            </li>
            {{-- Below lg the team match is one slim line under the list, so the ways to play stay above the tab bar at 375 x 667. --}}
            <li class="max-lg:hidden">
                <x-chess.lobby-tile :label="__('Team match')" :action="__('Challenge a clan')" :href="$teamHref" data-test="play-team">
                    <x-slot:detail>{{ __('Rapid · 2 or 3 boards') }}, {{ __('Clan against clan') }}</x-slot:detail>
                </x-chess.lobby-tile>
            </li>
        </ul>
    </div>

    @if (! $busy)
        {{--
            Season 1 or Casual, before any click (plan "Season vs. Casual", P3): it sat inside the panel a tile opens, so
            nobody saw it. The choice "Find opponent" sends, for the mode the panel plays (Alpine liveMode). Out of the way
            while the invite module is open: a link invite is casual, and the module opens in place below.
        --}}
        <div class="flex flex-col gap-2 rounded-[4px] bg-card p-2 shadow-ring lg:p-3" x-show="stage !== 'invite'" data-test="kind-choice" data-mode="{{ ChessModes::DEFAULT }}" x-bind:data-mode="liveMode">
            <div role="radiogroup" aria-label="{{ __('Game kind') }}" class="grid min-w-0 grid-cols-2 gap-1 rounded-md bg-ground p-1 shadow-ring" data-test="game-kind"
                 data-rated-open="{{ $defaultRefusal === null ? 'true' : 'false' }}" x-bind:data-rated-open="(refusals[liveMode] === null).toString()">
                <button type="button" role="radio" aria-checked="{{ $defaultRefusal === null ? 'false' : 'true' }}" x-bind:aria-checked="rated ? 'false' : 'true'" x-on:click="chose = false" aria-describedby="kind-reason kind-why" data-test="kind-casual"
                        class="flex min-h-14 cursor-pointer items-center justify-center rounded-sm px-2 py-1.5 text-[13px] font-bold text-btc-hi shadow-[inset_0_-2px_0_var(--color-btc)] bg-raised"
                        x-bind:class="rated ? 'bg-transparent! shadow-none! text-ink!' : ''"><span class="flex flex-col items-center"><b>{{ __('Casual') }}</b><span class="text-[11px] leading-4 font-normal text-ink-2">{{ __('For fun, own Elo') }}</span></span></button>
                <button type="button" role="radio" aria-checked="{{ $defaultRefusal === null ? 'true' : 'false' }}" x-bind:aria-checked="rated ? 'true' : 'false'" x-on:click="chose = true" aria-describedby="kind-reason kind-why" data-test="kind-rated"
                        @disabled($defaultRefusal !== null) x-bind:disabled="refusals[liveMode] !== null"
                        class="flex min-h-14 cursor-pointer flex-wrap items-center justify-center gap-x-2 rounded-sm px-2 py-1.5 text-[13px] font-bold text-ink disabled:cursor-not-allowed disabled:text-ink-3"
                        x-bind:class="rated ? 'bg-raised text-btc-hi! shadow-[inset_0_-2px_0_var(--color-btc)]' : ''">
                    <b class="basis-full text-center">{{ __(':season (rated)', ['season' => $seasonName]) }}</b>
                    <span @class(['text-[11px] leading-4 font-normal text-ink-2', 'hidden' => $defaultRefusal !== null]) x-bind:class="refusals[liveMode] !== null && 'hidden'" x-text="chips[liveMode] ?? @js(__('Ladder and a block'))" data-test="kind-rated-gain">{{ $chips[ChessModes::DEFAULT] ?? __('Ladder and a block') }}</span>
                    <span @class(['rounded-xs bg-raised px-1.5 py-0.5 text-[11px] font-normal text-ink-2', 'hidden' => $defaultRefusal === null]) x-bind:class="refusals[liveMode] === null && 'hidden'"
                          x-text="reasons[liveMode]?.badge" x-bind:data-kind="reasons[liveMode]?.kind" data-kind="{{ $defaultReason['kind'] ?? '' }}" data-test="kind-rated-badge">{{ $defaultReason['badge'] ?? '' }}</span>
                </button>
            </div>
        {{-- Under the choice, always in view (UX audit 2026-11-17, F1 and F2): the chip of the chosen kind, and why Rated is closed. --}}
        @if ($seasonLive || $anyRefused)
        <div class="flex flex-col gap-1.5 px-1" data-test="kind-note">
            @if ($seasonLive)
                <span class="flex flex-wrap items-center gap-x-2 gap-y-1" data-test="kind-chip" x-bind:data-kind="rated ? 'rated' : 'casual'" data-kind="{{ $defaultRefusal === null ? 'rated' : 'casual' }}">
                    <span class="rv-tag is-btc" x-show="rated && chips[liveMode]" @if ($defaultRefusal !== null || ! isset($chips[ChessModes::DEFAULT])) x-cloak @endif><x-icon name="mining" :size="12" /><span x-text="chips[liveMode]">{{ $chips[ChessModes::DEFAULT] ?? '' }}</span></span>
                    <span class="rv-m" x-show="rated && chips[liveMode]" @if ($defaultRefusal !== null || ! isset($chips[ChessModes::DEFAULT])) x-cloak @endif>{{ __('paid out after the season review') }}</span>
                    <span class="rv-tag" x-show="! rated" @if ($defaultRefusal === null) x-cloak @endif>{{ __('Casual mines no block') }}</span>
                </span>
            @endif
            @if ($anyRefused && ! $pathShowsSteps)
                <p id="kind-reason" class="m-0 flex max-w-[68ch] flex-wrap items-center gap-x-2 text-[13px] leading-normal text-ink-2" data-test="kind-reason"
                   x-show="reasons[liveMode] !== null" @if ($defaultReason === null) x-cloak @endif x-bind:data-kind="reasons[liveMode]?.kind" data-kind="{{ $defaultReason['kind'] ?? '' }}">
                    <span x-text="reasons[liveMode]?.line">{{ $defaultReason['line'] ?? '' }}</span>
                    <a class="rv-lk inline-flex min-h-6 items-center font-bold whitespace-nowrap" x-show="reasons[liveMode]?.href" x-bind:href="reasons[liveMode]?.href" x-text="reasons[liveMode]?.cta"
                       @if (($defaultReason['href'] ?? null) === null) x-cloak @else href="{{ $defaultReason['href'] }}" @endif data-test="kind-reason-link">{{ $defaultReason['cta'] ?? '' }}</a>
                </p>
            @endif
        </div>
        @endif
        </div>
    @endif

    @if ($entry)
        @php
            $takes = $entry->takes();
            $clocks = implode(' / ', array_map(fn (string $mode): string => (string) ChessModes::clock($mode), $takes));
            $modeNames = implode(' / ', array_map(fn (string $mode): string => ChessModes::short($mode), $takes));
            $hint = $this->switchHint;
            $ratedQueue = $this->ratedQueue;
        @endphp
        {{-- ChessStates "Finding opponent" --}}
        <div id="lobby-searching" data-check-at="{{ $this->checkAt }}" role="status" aria-live="polite" class="flex flex-col items-center gap-4 rounded-[4px] bg-card p-4 shadow-ring-btc" data-test="searching">
            {{-- Against two thin queues (user, 2026-10-05): after a while alone, who searches the other mode, and one click to switch with the place kept. First in the card: at 390 px it lay under the fold below the cube. --}}
            @if ($hint !== null)
                <div class="flex flex-col gap-2.5 self-stretch rounded-md bg-toast-challenge p-3 text-left shadow-ring-btc" data-test="switch-hint" data-mode="{{ $hint['mode'] }}">
                    <span class="flex items-start gap-2.5 text-[13px]"><x-icon name="bolt" :size="16" class="mt-0.5 shrink-0 text-btc" />
                        <span><b>{{ trans_choice(':count player searches :mode right now.|:count players search :mode right now.', $hint['count'], ['mode' => ChessModes::label($hint['mode'])]) }}</b>
                            <span class="text-ink-2">{{ __('Nobody fits you in :mode yet. Switch?', ['mode' => $modeNames]) }}</span></span>
                    </span>
                    <span class="grid grid-cols-1 gap-2 min-[420px]:grid-cols-2">
                        <x-button wire:click="switchSearch('{{ $hint['mode'] }}')" data-test="switch-hint-switch">{{ __('Switch to :mode', ['mode' => ChessModes::short($hint['mode'])]) }}</x-button>
                        <x-button variant="quiet" wire:click="switchSearch('{{ $entry->mode }}', true)" data-test="switch-hint-either">{{ __('Either is fine') }}</x-button>
                    </span>
                </div>
            @endif
            <div class="cube mt-4 flex size-[124px] flex-col items-center justify-between bg-[linear-gradient(180deg,#2A1F0E,#17120A)] px-2 py-2.5 text-center" aria-hidden="true">
                <span class="text-[13px] font-bold">~{{ $entry->rating }} Elo</span>
                <span class="text-[11px] text-btc-hi">{{ $entry->rating - app(ChessQueue::class)->range($entry) }} – {{ $entry->rating + app(ChessQueue::class)->range($entry) }}</span>
                <span class="text-base font-bold">{{ $clocks }}</span>
                <span class="text-[11px] text-ink-2" data-test="searching-kind">{{ $entry->rated ? __(':mode · rated', ['mode' => $modeNames]) : __(':mode · casual', ['mode' => $modeNames]) }}</span>
                <span class="text-[11px]" x-text="since({{ $entry->joined_at->getTimestampMs() }})"></span>
            </div>
            <span class="font-display text-lg font-bold" data-test="searching-title">{{ __('Finding opponent … :clock', ['clock' => $clocks]) }}</span>
            <span class="block h-1 w-full max-w-[420px] overflow-hidden rounded-xs bg-raised"><span class="sweep block h-1 w-2/5 rounded-xs bg-btc"></span></span>
            <span class="text-[13px] leading-normal text-ink-2">
                {{ trans_choice(':count player searching right now.|:count players searching right now.', $searching) }}
                {{ __('Your range: ±:range around :rating, it opens by :step every :seconds s. As soon as someone fits, the game starts, no extra click.', ['range' => app(ChessQueue::class)->range($entry), 'rating' => $entry->rating, 'step' => $range['step'], 'seconds' => $range['every_seconds']]) }}
            </span>
            {{-- P57: the rated queue skips players who do not list each other; say so in counts (nobody is named: presence), and offer the fix. --}}
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
        <div id="lobby-waiting" data-check-at="{{ $this->checkAt }}" class="flex flex-col gap-3.5 rounded-[4px] bg-card p-5 shadow-ring-btc" data-test="waiting-for-friend">
            <span class="flex items-center gap-3"><span aria-hidden="true" class="block size-5 shrink-0 animate-spin rounded-full border-2 border-line border-t-btc"></span><b class="min-w-0 text-base wrap-anywhere" data-test="waiting-name">{{ __('Waiting for :name', ['name' => $outgoing->invitee->displayName()]) }}</b></span>
            <div class="flex flex-col">
                <div class="grid h-9 grid-cols-[110px_minmax(0,1fr)] items-center border-b border-hairline text-[13px]"><span class="text-ink-2">{{ __('Time control') }}</span><span>{{ __(':mode, colours at random', ['mode' => ChessModes::label($outgoing->mode)]) }}</span></div>
                <div class="grid h-9 grid-cols-[110px_minmax(0,1fr)] items-center border-b border-hairline text-[13px]"><span class="text-ink-2">{{ __('Friend') }}</span><span>{{ __('online when invited') }}</span></div>
                <div class="grid h-9 grid-cols-[110px_minmax(0,1fr)] items-center border-b border-hairline text-[13px]"><span class="text-ink-2">{{ __('Open for') }}</span><span role="timer" x-text="since({{ $outgoing->created_at?->getTimestampMs() ?? 0 }})"></span></div>
            </div>
            <span class="flex gap-2.5">
                <button type="button" wire:click="withdrawInvite" class="inline-flex h-11 cursor-pointer items-center justify-center rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss">{{ __('Withdraw') }}</button>
            </span>
        </div>
    @else
        {{-- Quick play in the open live mode (Alpine `liveMode`): kind, either, range, one call to action; the explanations behind "?". --}}
        <div id="lobby-quick" role="region" aria-label="{{ $labels[ChessModes::DEFAULT] }}" x-bind:aria-label="@js($labels)[liveMode]" x-show="stage === 'quick'" x-cloak
             x-bind:data-mode="liveMode" data-mode="{{ ChessModes::DEFAULT }}"
             x-transition:enter="transition duration-200 ease-out motion-reduce:transition-none" x-transition:enter-start="opacity-0 -translate-y-1"
             class="flex flex-col gap-2 rounded-[4px] bg-card p-2 shadow-ring-btc lg:flex-row lg:flex-wrap lg:items-center lg:gap-3 lg:p-3" data-test="find-opponent">
            <div class="flex justify-end gap-2 lg:contents">
                <button type="button" x-on:click="help = ! help" x-bind:aria-expanded="help.toString()" aria-expanded="false" aria-controls="blitz-help" data-test="kind-help"
                        class="inline-flex size-11 shrink-0 cursor-pointer items-center justify-center self-center rounded-md border border-line bg-well font-display text-base font-bold text-ink-2 hover:text-ink aria-expanded:border-btc aria-expanded:text-btc-hi lg:order-last">
                    <span aria-hidden="true">?</span><span class="sr-only">{{ __('How live chess works') }}</span>
                </button>
            </div>
            {{--
                "Either" (user, 2026-10-05): every live mode, the first fitting opponent wins. Below lg it takes the range
                box's place beside "Find opponent" (the range is behind "?"): a row of its own pushed the button under
                the tab bar at 375 x 667 (tests/Browser/ChessLobbyTest.php).
            --}}
            <div class="flex gap-2 lg:contents">
                <label class="flex min-h-12 shrink-0 cursor-pointer items-center gap-2.5 rounded-md px-3 text-[13px] shadow-ring has-checked:shadow-ring-btc lg:min-h-11" data-test="either-option">
                    <input type="checkbox" x-model="either" class="size-4 shrink-0 accent-btc" data-test="either">
                    <span class="flex min-w-0 flex-col leading-4"><b>{{ __('Either') }}</b><span class="text-[11px] text-ink-2 lg:hidden">{{ implode(' / ', array_map(ChessModes::short(...), $liveModes)) }}</span><span class="text-[11px] text-ink-2 max-lg:hidden">{{ __(':modes, whichever pairs first', ['modes' => implode(__(' or '), array_map(ChessModes::short(...), $liveModes))]) }}</span></span>
                </label>
                <span class="flex min-h-12 shrink-0 flex-col justify-center rounded-md px-3 shadow-ring max-lg:hidden lg:min-h-11" data-test="blitz-range">
                    <span class="sr-only">{{ __('Opponent strength') }}:</span>
                    <b class="text-[15px] leading-5">±{{ $range['initial'] }}</b>
                    <span class="text-[11px] leading-4 text-ink-2">{{ __('around :rating', ['rating' => $rating]) }}</span>
                </span>
                @auth
                    <button type="button" x-on:click="joinQueue(rated, liveMode, either)" data-test="find-opponent-button"
                            class="btn-p inline-flex min-h-12 grow cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-5 font-display text-base font-bold text-on-btc lg:min-w-64 lg:grow-0">
                        <x-icon name="bolt" :size="18" />{{ __('Find opponent') }}
                    </button>
                @else
                    <x-button :href="route('login')" class="min-h-12 grow lg:min-w-64 lg:grow-0" data-test="find-opponent-login">{{ __('Log in to play') }}</x-button>
                @endauth
            </div>
            <div id="blitz-help" x-show="help" x-cloak class="flex flex-col gap-2 border-t border-hairline px-2 pt-3 pb-1 text-[13px] leading-normal text-ink-2 lg:order-last lg:basis-full" data-test="blitz-help">
                <p id="kind-why" class="m-0 max-w-[72ch]" data-test="kind-why">
@if ($anyRefused)<span x-show="refusals[liveMode] !== null" x-text="refusals[liveMode]" @if ($defaultRefusal === null) x-cloak @endif>{{ $defaultRefusal }}</span>@endif
                    @if (! $allRefused)<span x-show="refusals[liveMode] === null && ! rated" @if ($defaultRefusal !== null) x-cloak @endif>{{ __('Casual pairs you with anyone online and moves only your casual Elo.') }}</span> <span x-show="refusals[liveMode] === null && rated" x-cloak>{{ trans_choice('Rated pairs you only with a Trusted player you list each other with (you have :count).|Rated pairs you only with Trusted players you list each other with (you have :count).', $this->mutualOpponents) }} <a href="{{ route('settings.opponents') }}" class="text-ink underline underline-offset-2 hover:text-btc-hi" data-test="kind-why-opponents">{{ __('Your opponents') }}</a></span>@endif
                </p>
                <p class="m-0 max-w-[72ch]">{{ __('Opponent strength') }}: {{ __('±:range around :rating, wider every :seconds s', ['range' => $range['initial'], 'rating' => $rating, 'seconds' => $range['every_seconds']]) }}</p>
                <p class="m-0 max-w-[72ch]">{{ __('You join the queue and can cancel any time. The game starts as soon as someone in your range is found.') }}</p>
                <p class="m-0 max-w-[72ch]">{{ __('Rapid 10+5 gives each player ten minutes plus five seconds a move; blitz 5+3 five minutes plus three. Each has its own Elo.') }}</p>
            </div>
        </div>
    @endif

    {{-- Invite a friend by link (P6b): the lobby's module, opened by its tile. --}}
    <div id="lobby-invite" x-show="stage === 'invite'" x-cloak x-transition:enter="transition duration-200 ease-out motion-reduce:transition-none" x-transition:enter-start="opacity-0 -translate-y-1">
        <livewire:invite-link place="lobby" />
    </div>

    {{-- "Your path to Season 1" (plan P4): the steps still missing, each with its fix; one line once rated is open; under the invite module, which opens in place above it. --}}
    @auth
        <x-season.path game="chess" :mode="ChessModes::DEFAULT" :names="false" :path="$seasonPath" />
        {{-- Plan P5: who the player lists each other with, who is online and who can play Season 1 now. --}}
        <x-opponents.mutual game="chess" />
    @endauth

    <a href="{{ $teamHref }}" class="flex min-h-11 items-center gap-2 rounded-[4px] border border-line px-3 text-[13px] text-ink hover:text-ink lg:hidden" data-test="play-team-link">
        <x-icon name="clans" :size="16" class="shrink-0 text-ink-2" />
        <b class="shrink-0">{{ __('Team match') }}</b>
        <span class="min-w-0 truncate text-ink-2">{{ __('Clan against clan') }}</span>
    </a>
</section>
