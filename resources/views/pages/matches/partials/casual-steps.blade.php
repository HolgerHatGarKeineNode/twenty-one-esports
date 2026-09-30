{{--
    The steps of a casual 1v1 (P23, CasualMatches) in the match room, as one
    timeline: ready (a scheduled match, P23 S4: checked in), lobby (EA ID for
    EA FC; Age of Empires II: a lobby a player hosts in the game, with a
    password and spectators allowed), joined, report, confirm. Under
    it the step that runs now: its deadline as a big countdown
    (SeriesMatch::casualNextDeadline()), one primary action for this player,
    and the no-show claim, its answer and the host swap exactly when they
    apply. The lobby itself is never here: the host shares it as a card in
    the chat next to this (NIP "Lobby and account cards"); "Share lobby"
    opens that composer. Needs $m, $mySide, $viewer, $report and $editable
    from pages/matches/⚡room.
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
    $canSwap = $running && $iHost && $m->lobby_shared_at === null && $m->host_swapped_at === null && $m->noshow_reported_at === null && ($lobbyDue?->isFuture() ?? false);
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
    $toCheck = $m->status === SeriesStatus::Reported && $report?->status === ReportStatus::Open && $mySide !== null && $mySide !== $report->side;
    $big = 'min-h-14 w-full px-6 font-display text-base font-bold sm:w-auto';
@endphp

<section aria-labelledby="casual-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="casual-steps">
    <span class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="casual-h" class="m-0 text-[15px] font-bold">{{ __('Match steps') }}</h2>
        <span class="text-xs text-ink-2" data-test="casual-role">{{ $mySide === null ? '' : ($iHost ? __('you host') : __('you join')) }}</span>
    </span>

    {{-- The timeline: a rail with one stop per step, the running one in orange. --}}
    <ol class="m-0 grid list-none grid-cols-5 p-0" data-test="casual-timeline">
        @foreach ($casualSteps as $index => [$key, $label, $done, $detail])
            @php($current = $key === $currentKey)
            @php($nextDone = $casualSteps[$index + 1][2] ?? false)
            <li @class(['relative flex min-w-0 flex-col items-center gap-1.5 px-0.5 text-center', 'after:absolute after:top-[7px] after:left-1/2 after:h-0.5 after:w-full' => $index < 4, 'after:bg-win' => $index < 4 && $nextDone, 'after:bg-line' => $index < 4 && ! $nextDone])
                data-test="casual-step-{{ $key }}" data-done="{{ $done ? '1' : '0' }}" @if ($current) aria-current="step" @endif>
                <span @class(['relative z-10 flex size-4 items-center justify-center rounded-full border-2', 'border-win bg-win text-on-btc' => $done, 'border-btc bg-btc ring-4 ring-btc-press' => $current, 'border-edge bg-card' => ! $done && ! $current])>
                    @if ($done)<x-icon name="check" :size="10" />@endif
                </span>
                {{-- Below sm five labels do not fit (German "Beigetreten"): the dots stay, the running step is named under the rail. --}}
                <b @class(['max-w-full text-xs leading-tight break-words max-sm:sr-only', 'text-win' => $done, 'text-btc-hi' => $current, 'text-ink-2' => ! $done && ! $current])>{{ $label }}</b>
                <span class="max-w-full text-[11px] leading-snug break-words text-ink-3 max-sm:sr-only">{{ $detail }}</span>
            </li>
        @endforeach
    </ol>

    @if ($currentKey !== null)
        @php($currentIndex = array_search($currentKey, array_column($casualSteps, 0), true))
        <p class="m-0 -mt-2 text-xs font-bold text-btc-hi sm:hidden" data-test="casual-current-step">{{ __('Step :n of :total: :label', ['n' => $currentIndex + 1, 'total' => count($casualSteps), 'label' => $casualSteps[$currentIndex][1]]) }}</p>
    @endif

    @if ($deadlineText !== null)
        {{-- The clock of the running step. --}}
        <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-1 rounded-md bg-well px-4 py-3" data-test="casual-deadline" data-kind="{{ $deadline['kind'] }}">
            <span class="flex min-w-0 items-center gap-2 text-[13px] leading-normal"><x-icon name="clock" :size="16" class="shrink-0 text-btc-hi" /><span>{{ $deadlineText }}</span></span>
            <b class="font-display text-[32px] leading-none font-bold text-btc-hi tabular-nums" role="timer" x-data="casualClock({{ ($deadline['kind'] === 'checkin' && $checkInOpens?->isFuture() ? $checkInOpens : $deadline['at'])->getTimestamp() }}, {{ now()->getTimestampMs() }})" x-text="left" data-test="casual-clock"></b>
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
                    <x-button icon="check" x-on:click="ready()" ::disabled="busy" class="{{ $big }}" data-test="casual-ready">{{ __('Ready') }}</x-button>
                    <p x-show="error" x-text="error" class="m-0 text-loss" role="alert"></p>
                </div>
            @endif
        @elseif ($m->awaitsCheckIn())
            @include('pages.matches.partials.casual-checkin')
        @elseif ($running)
            @if ($claimAgainstMe)
                <p class="m-0 text-loss">{{ __(':name says you did not show up. Answer before :time, or the match is scored as a forfeit.', ['name' => $otherName, 'time' => $at($m->casualContestDueAt())]) }}</p>
                <div><x-button icon="user" wire:click="casualContestNoShow" class="{{ $big }}" data-test="casual-contest">{{ __('I am here') }}</x-button></div>
            @elseif ($m->noshow_reported_at !== null)
                <p class="m-0">{{ __('You claimed a no-show. :name can answer until :time.', ['name' => $otherName, 'time' => $at($m->casualContestDueAt())]) }}</p>
            @elseif ($iHost && $m->lobby_shared_at === null)
                <p class="m-0">{{ match (true) {
                    $isRl => __('Create a private match in Rocket League and share its name and password in the chat.'),
                    $isAoe => __('Host a lobby in Age of Empires II with a password and spectators allowed, then share its name and password in the chat.'),
                    default => __('Share your EA ID in the chat. :name sends you a friend request; accept it and send the Play a Friend invite.', ['name' => $otherName]),
                } }}</p>
                <div>
                    {{-- Opens the card composer in the chat (roomChat listens for casual-compose). A component attribute compiles no @js: the kind rides on data-kind. --}}
                    <x-button :icon="$sharesLobby ? 'key' : 'user'" class="{{ $big }}" data-test="casual-share" data-kind="{{ $sharesLobby ? 'lobby' : 'account' }}"
                              x-on:click="window.dispatchEvent(new CustomEvent('casual-compose', { detail: $el.dataset.kind })); document.querySelector('[data-test=room-chat]')?.scrollIntoView({ block: 'nearest', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })">
                        {{ $sharesLobby ? __('Share lobby') : __('Share EA ID') }}
                    </x-button>
                </div>
            @elseif ($iHost && $m->joined_at === null)
                <p class="m-0">{{ match (true) {
                    $isRl => __('Waiting for :name to join your private match.', ['name' => $otherName]),
                    $isAoe => __('Waiting for :name to join your lobby.', ['name' => $otherName]),
                    default => __('Accept the friend request from :name and send the Play a Friend invite.', ['name' => $otherName]),
                } }}</p>
            @elseif (! $iHost && $m->lobby_shared_at === null)
                <p class="m-0">{{ $sharesLobby ? __('Waiting for :name to share the lobby in the chat.', ['name' => $otherName]) : __('Waiting for :name to share their EA ID in the chat.', ['name' => $otherName]) }}</p>
            @elseif (! $iHost && $m->joined_at === null)
                <p class="m-0">{{ match (true) {
                    $isRl => __('Join the private match with the name and password from the card in the chat, then confirm here.'),
                    $isAoe => __('Find the lobby by its name in the lobby browser, join with the password from the card in the chat, then confirm here.'),
                    default => __('Send :name a friend request with the EA ID from the chat and accept the invite, then confirm here.', ['name' => $otherName]),
                } }}</p>
                <div><x-button icon="check" wire:click="casualJoined" class="{{ $big }}" data-test="casual-joined">{{ __('I am in the lobby') }}</x-button></div>
            @else
                <p class="m-0">{{ __('Both are in. Play, then enter the score.') }}</p>
                @if ($editable)
                    <div><x-button icon="shield-check" x-on:click="submit = true" class="{{ $big }}" data-test="casual-report">{{ __('Submit final score') }}</x-button></div>
                @endif
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
        @elseif ($toCheck)
            <p class="m-0">{{ __(':name sent the score. Check it: accept it, or report a problem.', ['name' => $otherName]) }}</p>
            <div><x-button icon="shield-check" href="#check" class="{{ $big }}" data-test="casual-check">{{ __('Check the result') }}</x-button></div>
        @elseif ($m->status === SeriesStatus::Reported)
            <p class="m-0">{{ __('Your score is sent. :name accepts it or reports a problem; without an answer the league confirms it.', ['name' => $otherName]) }}</p>
        @endif
    </div>
</section>
