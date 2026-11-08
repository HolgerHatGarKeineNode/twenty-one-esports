{{--
    The steps of a casual 1v1 (P23, CasualMatches) in the match room, as one
    timeline: ready (a scheduled match, P23 S4: checked in), lobby (EA ID for
    EA FC; Age of Empires II: a lobby a player hosts in the game, with a
    password and spectators allowed), joined, report, confirm. Under
    it the step that runs now: its deadline as a big countdown
    (SeriesMatch::casualNextDeadline()), one primary action for this player,
    and the no-show claim, its answer and the host swap exactly when they
    apply. The lobby travels as a card in the chat next to this (NIP "Lobby
    and account cards"); "Share lobby" and "Replace" open that composer. The
    host's open card is pinned here by the browser from its decrypted chat,
    never by the league, which only knows that a card went out. Needs $m,
    $mySide, $viewer, $report, $editable, $view and $roomClock from
    pages/matches/⚡room. One view per step (P3): in the result step only the
    folded lobby stays; the step's primary action carries
    `data-room-primary` and hides below lg, where the room's phone bar
    mirrors it.
--}}
@php
    use App\Enums\ReportStatus;
    use App\Enums\SeriesStatus;
    use App\Models\SeriesMatch;

    $hostSide = $m->host_side === 'challenged' ? 'challenged' : 'challenger';
    $guestSide = SeriesMatch::otherSide($hostSide);
    $iHost = $mySide === $hostSide;
    $otherName = $m->sideName($mySide === null ? 'challenged' : SeriesMatch::otherSide($mySide));
    $deadline = $m->casualNextDeadline();
    // Past the ready check or the check-in: a scheduled match has its agreed `start_at` long before (P23 S4).
    $running = $m->casualUnderWay();
    $scheduled = $m->isScheduledPairing();
    $checkInOpens = $m->awaitsCheckIn() ? $m->checkInOpensAt() : null;
    $isRl = $m->game === 'rocket-league';
    $isAoe = $m->game === 'age-of-empires-2';
    // Rocket League and Age of Empires II share a lobby (name and password), EA FC an EA ID.
    $sharesLobby = $isRl || $isAoe;
    // A tournament match carries the league's lobby (LeagueLobby, P5): no card travels through the chat.
    $leagueLobby = \App\Support\Tournaments\LeagueLobby::isLeagueMade($m);
    $lobbyDue = $m->casualLobbyDueAt();
    $joinDue = $m->casualJoinDueAt();
    $readyCount = ($m->ready_at_challenger ? 1 : 0) + ($m->ready_at_challenged ? 1 : 0);
    $myReady = $mySide !== null && $m->readyAt($mySide) !== null;
    $claimOpen = $m->noshow_reported_at === null && $m->noshow_contested_at === null;
    $canClaim = $running && $claimOpen && (
        ($iHost && $m->lobby_shared_at !== null && $m->joined_at === null && $joinDue?->isPast())
        || (! $iHost && $m->lobby_shared_at === null && $lobbyDue?->isPast())
    );
    // When this player may claim a no-show, if the opponent keeps them waiting.
    $claimFrom = ! $running || ! $claimOpen ? null : match (true) {
        $iHost && $m->lobby_shared_at !== null && $m->joined_at === null => $joinDue,
        ! $iHost && $m->lobby_shared_at === null => $lobbyDue,
        default => null,
    };
    $claimAgainstMe = $running && $m->noshow_reported_at !== null && $m->noshow_side !== null && $m->noshow_side !== $mySide && ($m->casualContestDueAt()?->isFuture() ?? false);
    $canSwap = $running && $iHost && ! \App\Support\Tournaments\LeagueLobby::isLeagueMade($m) && $m->lobby_shared_at === null && $m->host_swapped_at === null && $m->noshow_reported_at === null && ($lobbyDue?->isFuture() ?? false);
    $at = fn ($time) => $time === null ? '' : \App\Support\Series\SeriesPresenter::time($time, $viewer, 'H:i');
    $reported = $report !== null && $report->status !== ReportStatus::Superseded;
    $casualSteps = [
        $scheduled
            ? ['ready', __('Checked in'), $m->bothCheckedIn(), $m->bothCheckedIn() ? $at($m->start_at) : __(':n of 2 checked in', ['n' => $readyCount])]
            : ['ready', __('Ready'), $m->start_at !== null, $m->start_at !== null ? $at($m->start_at) : __(':n of 2 ready', ['n' => $readyCount])],
        ['lobby', $sharesLobby ? __('Lobby shared') : __('EA ID shared'), $m->lobby_shared_at !== null, $m->lobby_shared_at !== null ? $at($m->lobby_shared_at).($m->lobby_seen_at !== null ? ', '.__('opened') : '') : __('host :name', ['name' => $m->sideName($hostSide)])],
        ['joined', __('Joined'), $m->joined_at !== null, $m->joined_at !== null ? $at($m->joined_at) : __('guest :name', ['name' => $m->sideName($guestSide)])],
        ['report', __('Result'), $reported || $m->status->hasResult(), $reported ? __('by :name', ['name' => $m->sideName($report->side)]) : __('either player')],
        ['confirm', __('Confirmed'), $m->status->hasResult(), $m->status->hasResult() ? $at($m->finished_at) : __('the other player')],
    ];
    // The step that runs: the first one not done (a claim or a report can come before `joined`).
    $currentKey = $m->status->hasResult() ? null : match (true) {
        $m->status === SeriesStatus::Reported => 'confirm',
        $m->awaitsReady(), $m->awaitsCheckIn() => 'ready',
        $m->lobby_shared_at === null => 'lobby',
        $m->joined_at === null => 'joined',
        default => 'report',
    };
    $deadlineText = $deadline === null ? null : match ($deadline['kind']) {
        'ready' => __('Both press Ready by :time', ['time' => $at($deadline['at'])]),
        // Before its window the clock counts to the opening, not to the close.
        'checkin' => $checkInOpens?->isFuture() ? __('The check-in opens at :open, the match starts :time', ['open' => $at($checkInOpens), 'time' => $at($m->scheduledAt())]) : __('Both check in by :time', ['time' => $at($deadline['at'])]),
        'lobby' => __(':name shares the lobby by :time', ['name' => $m->sideName($hostSide), 'time' => $at($deadline['at'])]),
        'join' => __(':name joins by :time', ['name' => $m->sideName($guestSide), 'time' => $at($deadline['at'])]),
        'contest' => __(':name answers the no-show claim by :time', ['name' => $m->sideName((string) $deadline['side']), 'time' => $at($deadline['at'])]),
        'report' => __('Report the result by :time', ['time' => $at($deadline['at'])]),
        'confirm' => __(':name answers the report by :time', ['name' => $m->sideName((string) $deadline['side']), 'time' => $at($deadline['at'])]),
    };
    $big = 'min-h-14 w-full px-6 font-display text-base font-bold sm:w-auto';
@endphp

{{-- [overflow-wrap:anywhere]: a 40-character name without a space in the clock line ran 49 px out of the page at 390 (2026-10-04). --}}
{{--
    The four steps sit above as the room's stepper (partials/room-stepper); this is the step that runs: the lobby
    card pinned first (boards MatchRoom-2c, TournamentMatchRoom), then one card with the clock line and the one
    action of this player.
--}}
<section aria-labelledby="casual-h" class="flex flex-col gap-4 [overflow-wrap:anywhere]" data-test="casual-steps" data-current="{{ $currentKey ?? 'done' }}">
    <h2 id="casual-h" class="sr-only">{{ __('Match steps') }}</h2>

    @php($lobbyPin = $sharesLobby && $mySide !== null && $m->status === SeriesStatus::Accepted && $m->start_at !== null)
    @if ($leagueLobby && $m->status->isRunning())
        {{-- A tournament match's league lobby (LeagueLobby): there from the start, the host is the higher seed. --}}
        @include('pages.matches.partials.room-lobby', ['leagueActions' => true])
    @elseif ($lobbyPin && $running)
        {{-- The pinned lobby card from the chat, first: it and its action stay above the fold at 375. --}}
        @include('pages.matches.partials.casual-lobby-pin')
    @endif

    @if (in_array($view, ['ready', 'checkin', 'lobby'], true))
    <div class="rv-card flex flex-col gap-3 px-4 py-4 lg:px-6 lg:py-5" data-test="casual-step-card">
    <span class="flex flex-wrap items-baseline justify-between gap-2">
        <b class="rv-h3">{{ match ($currentKey) {
            'ready' => $scheduled ? __('Check in') : __('Ready'),
            'lobby', 'joined' => __('Lobby'),
            'report' => __('Result'),
            'confirm' => __('Check the result'),
            default => __('Match steps'),
        } }}</b>
        <span class="rv-m text-[13px]" data-test="casual-role">{{ $mySide === null ? '' : ($iHost ? __('you host') : __('you join')) }}</span>
    </span>

    @if ($m->awaitsCheckIn() || $m->awaitsReady())
        {{-- Who is in (board 1b: both sides with their state, "1 of 2 checked in"). --}}
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2" data-test="casual-presence">
            @foreach (\App\Models\SeriesMatch::SIDES as $presenceSide)
                @php($presenceIn = $m->readyAt($presenceSide) !== null)
                <span class="flex min-w-0 items-center gap-2">
                    <span class="rv-av size-8 text-xs" aria-hidden="true">{{ mb_strtoupper(mb_substr($m->sideName($presenceSide), 0, 1)) }}</span>
                    <span class="flex min-w-0 flex-col">
                        <b class="text-[13px]">{{ $m->sideName($presenceSide) }}</b>
                        <span @class(['inline-flex items-center gap-1 text-[13px]', 'text-win' => $presenceIn, 'text-ink-3' => ! $presenceIn])>@if ($presenceIn)<x-icon name="check" :size="12" />{{ $scheduled ? __('checked in') : __('ready') }}@else{{ $scheduled ? __('not checked in yet') : __('not ready yet') }}@endif</span>
                    </span>
                </span>
            @endforeach
            <span class="rv-m text-[13px]">{{ $scheduled ? __(':n of 2 checked in', ['n' => $readyCount]) : __(':n of 2 ready', ['n' => $readyCount]) }}</span>
        </div>
    @endif

    @if ($deadlineText !== null)
        {{-- The clock line of the running step: who acts by when; the big clock sits in the head. --}}
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-[4px] bg-well px-4 py-3" data-test="casual-deadline" data-kind="{{ $deadline['kind'] }}">
            <span class="flex min-w-0 items-center gap-2 text-[13px] leading-normal"><x-icon name="clock" :size="16" class="shrink-0 text-btc-hi" /><span class="min-w-0">{{ $deadlineText }}</span></span>
            {{-- One clock per step: the head's from sm, this one on a phone, both counting to the same moment ($roomClock). --}}
            @if ($roomClock !== null)
                <b class="font-display text-xl leading-none font-bold text-btc-hi tabular-nums sm:hidden" role="timer" x-data="casualClock({{ $roomClock['at']->getTimestamp() }}, {{ now()->getTimestampMs() }})" x-text="left" data-test="casual-clock"></b>
            @endif
        </div>
    @endif
    {{-- What this player does now: one primary action. --}}
    <div class="flex flex-col gap-3 text-[13px] leading-normal text-ink-2" data-test="casual-now">
        @if ($m->awaitsReady())
            @if ($myReady)
                <p class="m-0">{{ __('You are ready. Waiting for :name.', ['name' => $otherName]) }}</p>
            @elseif ($mySide !== null)
                <p class="m-0">{{ __('Press Ready to start. The chat needs a signer that can encrypt (NIP-44): the lobby travels through it, end-to-end encrypted.') }}</p>
                <div x-data="casualReady(@js(\App\Support\Nostr\SignerMessages::labels() + ['noNip44' => __('Your signer cannot encrypt messages (NIP-44), so you cannot get the lobby. Use a Nostr extension or signer app with NIP-44 to play casual 1v1.')]))" class="flex flex-col items-stretch gap-2 sm:items-start">
                    <x-button icon="check" x-on:click="ready()" ::disabled="busy" class="{{ $big }} max-lg:hidden" data-room-primary data-test="casual-ready">{{ __('Ready') }}</x-button>
                    <p x-show="error" x-text="error" class="m-0 text-loss" role="alert"></p>
                </div>
            @endif
        @elseif ($m->awaitsCheckIn())
            @include('pages.matches.partials.casual-checkin')
        @elseif ($running)
            @if ($claimAgainstMe)
                <p class="m-0 text-loss">{{ __(':name says you did not show up. Answer before :time, or the match is scored as a forfeit.', ['name' => $otherName, 'time' => $at($m->casualContestDueAt())]) }}</p>
                <div><x-button icon="user" wire:click="casualContestNoShow" class="{{ $big }} max-lg:hidden" data-room-primary data-test="casual-contest">{{ __('I am here') }}</x-button></div>
            @elseif ($m->noshow_reported_at !== null)
                <p class="m-0">{{ __('You claimed a no-show. :name can answer until :time.', ['name' => $otherName, 'time' => $at($m->casualContestDueAt())]) }}</p>
            @elseif ($iHost && $m->lobby_shared_at === null)
                {{-- Rocket League and Age of Empires II: the lobby pin above carries the instruction and Share lobby. --}}
                @unless ($sharesLobby)
                    <p class="m-0">{{ __('Share your EA ID in the chat. :name sends you a friend request; accept it and send the Play a Friend invite.', ['name' => $otherName]) }}</p>
                    <div>
                        {{-- Opens the card composer in the chat (roomChat listens for casual-compose). A component attribute compiles no @js: the kind rides on data-kind. --}}
                        <x-button icon="user" class="{{ $big }} max-lg:hidden" data-room-primary data-test="casual-share" data-kind="account"
                                  x-on:click="window.dispatchEvent(new CustomEvent('casual-compose', { detail: $el.dataset.kind })); document.querySelector('[data-test=room-chat]')?.scrollIntoView({ block: 'nearest', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })">
                            {{ __('Share EA ID') }}
                        </x-button>
                    </div>
                @endunless
            @elseif ($iHost && $m->joined_at === null)
                <p class="m-0">{{ match (true) {
                    $isRl => __('Waiting for :name to join your private match.', ['name' => $otherName]),
                    $isAoe => __('Waiting for :name to join your lobby.', ['name' => $otherName]),
                    default => __('Accept the friend request from :name and send the Play a Friend invite.', ['name' => $otherName]),
                } }}</p>
                @if ($m->lobby_shared_at !== null)
                    {{-- The guest may be in and simply forgot to confirm: the host confirms for them (user, 2026-10-05). --}}
                    <div><x-button variant="quiet" icon="check" wire:click="casualJoined" data-test="casual-host-joined">{{ __(':name is in my lobby', ['name' => $otherName]) }}</x-button></div>
                @endif
            @elseif (! $iHost && $m->lobby_shared_at === null)
                @unless ($sharesLobby)
                    <p class="m-0">{{ __('Waiting for :name to share their EA ID in the chat.', ['name' => $otherName]) }}</p>
                @endunless
            @elseif (! $iHost && $m->joined_at === null)
                {{-- Rocket League and Age of Empires II: "I am in the lobby" sits in the lobby pin above, under the card. --}}
                @unless ($sharesLobby)
                    <p class="m-0">{{ __('Send :name a friend request with the EA ID from the chat and accept the invite, then confirm here.', ['name' => $otherName]) }}</p>
                    <div><x-button icon="check" wire:click="casualJoined" class="{{ $big }} max-lg:hidden" data-room-primary data-test="casual-joined">{{ __('I am in the lobby') }}</x-button></div>
                @endunless
            @endif

            @if ($canClaim)
                <div class="flex flex-wrap items-center gap-3">
                    <x-button variant="secondary" icon="user" wire:click="casualClaimNoShow" data-test="casual-claim">{{ __('Opponent didn\'t show') }}</x-button>
                    <span class="text-xs text-ink-3">{{ __(':name can answer within :minutes minutes.', ['name' => $otherName, 'minutes' => $m->casualSetting('contest_minutes')]) }}</span>
                </div>
            @elseif ($claimFrom !== null && $claimFrom->isFuture())
                <p class="m-0 text-xs text-ink-3" data-test="casual-claim-from">{{ __('If :name does not show up, you can claim a no-show from :time.', ['name' => $otherName, 'time' => $at($claimFrom)]) }}</p>
            @endif

            @if ($canSwap)
                <div class="flex flex-col items-start gap-1 border-t border-hairline pt-3">
                    <x-button variant="quiet" icon="retry" wire:click="casualSwapHost" wire:confirm="{{ __(':name becomes the host and gets the full time to share the lobby. This works once per match.', ['name' => $otherName]) }}" data-test="casual-swap">{{ __('Can\'t share, swap host') }}</x-button>
                    <span class="text-xs text-ink-3">{{ __('If your signer fails, hand the host seat to :name instead of missing the deadline.', ['name' => $otherName]) }}</span>
                </div>
            @endif
        @endif
    </div>

    {{-- The sheet belongs to the result step; a match played without both confirming the lobby still reports (user, 2026-10-05). --}}
    @if ($editable && $running && $view === 'lobby')
        <div x-show="! early" class="border-t border-hairline pt-3"><x-button variant="quiet" icon="chevron-down" x-on:click="early = true" data-test="casual-early-sheet">{{ __('Enter the result now') }}</x-button></div>
    @endif

    {{-- Before the check-in the check-in is the action; a card sent that early waits under it. --}}
    @if ($lobbyPin && ! $running && ! $leagueLobby)
        @include('pages.matches.partials.casual-lobby-pin')
    @endif

    </div>
    @endif
</section>
