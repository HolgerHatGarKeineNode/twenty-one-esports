{{--
    The steps of a casual 1v1 (P23, CasualMatches) in the match room: ready,
    lobby, joined, each with the deadline SeriesMatch::casualNextDeadline()
    names. The lobby itself is never here: the host shares it as a card in
    the chat next to this (NIP "Lobby and account cards"). Needs $m, $mySide
    and $viewer from pages/matches/⚡room.
--}}
@php
    $hostSide = $m->host_side === 'challenged' ? 'challenged' : 'challenger';
    $guestSide = \App\Models\SeriesMatch::otherSide($hostSide);
    $iHost = $mySide === $hostSide;
    $otherName = $m->sideName($mySide === null ? 'challenged' : \App\Models\SeriesMatch::otherSide($mySide));
    $deadline = $m->casualNextDeadline();
    $running = $m->status === \App\Enums\SeriesStatus::Accepted && $m->start_at !== null;
    $isRl = $m->game === 'rocket-league';
    $lobbyDue = $m->casualLobbyDueAt();
    $joinDue = $m->casualJoinDueAt();
    $readyCount = ($m->ready_at_challenger ? 1 : 0) + ($m->ready_at_challenged ? 1 : 0);
    $myReady = $mySide !== null && $m->readyAt($mySide) !== null;
    $claimOpen = $m->noshow_reported_at === null && $m->noshow_contested_at === null;
    $canClaim = $running && $claimOpen && (
        ($iHost && $m->lobby_shared_at !== null && $m->joined_at === null && $joinDue?->isPast())
        || (! $iHost && $m->lobby_shared_at === null && $lobbyDue?->isPast())
    );
    $claimAgainstMe = $running && $m->noshow_reported_at !== null && $m->noshow_side !== null && $m->noshow_side !== $mySide && ($m->casualContestDueAt()?->isFuture() ?? false);
    $canSwap = $running && $iHost && $m->lobby_shared_at === null && $m->host_swapped_at === null && $m->noshow_reported_at === null && ($lobbyDue?->isFuture() ?? false);
    $at = fn ($time) => $time === null ? '' : \App\Support\Series\SeriesPresenter::time($time, $viewer, 'H:i');
    $casualSteps = [
        ['ready', __('Ready'), $m->start_at !== null, $m->start_at !== null ? $at($m->start_at) : __(':n of 2 ready', ['n' => $readyCount])],
        ['lobby', $isRl ? __('Lobby shared') : __('EA ID shared'), $m->lobby_shared_at !== null, $m->lobby_shared_at !== null ? $at($m->lobby_shared_at).($m->lobby_seen_at !== null ? ', '.__('opened') : '') : __('host :name', ['name' => $m->sideName($hostSide)])],
        ['joined', __('Joined'), $m->joined_at !== null, $m->joined_at !== null ? $at($m->joined_at) : __('guest :name', ['name' => $m->sideName($guestSide)])],
    ];
    $deadlineText = $deadline === null ? null : match ($deadline['kind']) {
        'ready' => __('Both press Ready by :time', ['time' => $at($deadline['at'])]),
        'lobby' => __(':name shares the lobby by :time', ['name' => $m->sideName($hostSide), 'time' => $at($deadline['at'])]),
        'join' => __(':name joins by :time', ['name' => $m->sideName($guestSide), 'time' => $at($deadline['at'])]),
        'contest' => __(':name answers the no-show claim by :time', ['name' => $m->sideName((string) $deadline['side']), 'time' => $at($deadline['at'])]),
        'report' => __('Report the result by :time', ['time' => $at($deadline['at'])]),
        'confirm' => __(':name answers the report by :time', ['name' => $m->sideName((string) $deadline['side']), 'time' => $at($deadline['at'])]),
    };
@endphp

<section aria-labelledby="casual-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="casual-steps">
    <span class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="casual-h" class="m-0 text-[15px] font-bold">{{ __('Match steps') }}</h2>
        <span class="text-xs text-ink-2" data-test="casual-role">{{ $mySide === null ? '' : ($iHost ? __('you host') : __('you join')) }}</span>
    </span>

    <ol class="m-0 grid list-none grid-cols-3 gap-2 p-0">
        @foreach ($casualSteps as $index => [$key, $label, $done, $detail])
            @php($current = ! $done && ($index === 0 || $casualSteps[$index - 1][2]))
            <li @class(['flex min-w-0 flex-col gap-1 rounded-md px-3 py-2.5', 'bg-win-tint' => $done, 'bg-btc-press' => $current, 'bg-well' => ! $done && ! $current]) data-test="casual-step-{{ $key }}" data-done="{{ $done ? '1' : '0' }}">
                <span @class(['inline-flex items-center gap-1.5 text-[13px] font-bold', 'text-win' => $done, 'text-btc-hi' => $current])>
                    @if ($done)<x-icon name="check" :size="14" class="shrink-0" />@endif{{ $label }}
                </span>
                <span class="text-[11px] leading-snug break-words text-ink-2">{{ $detail }}</span>
            </li>
        @endforeach
    </ol>

    @if ($deadlineText !== null)
        <p class="m-0 flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px]" data-test="casual-deadline" data-kind="{{ $deadline['kind'] }}">
            <x-icon name="clock" :size="16" class="shrink-0 text-btc-hi" />
            <span>{{ $deadlineText }}</span>
            <span class="font-mono text-btc-hi" x-data="casualClock({{ $deadline['at']->getTimestamp() }})" x-text="left" aria-hidden="true"></span>
        </p>
    @endif

    {{-- What this player does now --}}
    <div class="flex flex-col gap-3 border-t border-hairline pt-3 text-[13px] leading-normal text-ink-2">
        @if ($m->awaitsReady())
            @if ($myReady)
                <p class="m-0">{{ __('You are ready. Waiting for :name.', ['name' => $otherName]) }}</p>
            @elseif ($mySide !== null)
                <p class="m-0">{{ __('Press Ready to start. The chat needs a signer that can encrypt (NIP-44): the lobby travels through it, end-to-end encrypted.') }}</p>
                <div x-data="casualReady(@js(\App\Support\Nostr\SignerMessages::labels() + ['noNip44' => __('Your signer cannot encrypt messages (NIP-44), so you cannot get the lobby. Use a Nostr extension or signer app with NIP-44 to play casual 1v1.')]))" class="flex flex-col items-start gap-2">
                    <x-button icon="check" x-on:click="ready()" ::disabled="busy" data-test="casual-ready">{{ __('Ready') }}</x-button>
                    <p x-show="error" x-text="error" class="m-0 text-loss" role="alert"></p>
                </div>
            @endif
        @elseif ($running)
            @if ($claimAgainstMe)
                <p class="m-0 text-loss">{{ __(':name says you did not show up. Answer before :time, or the match is scored as a forfeit.', ['name' => $otherName, 'time' => $at($m->casualContestDueAt())]) }}</p>
                <div><x-button icon="user" wire:click="casualContestNoShow" data-test="casual-contest">{{ __('I am here') }}</x-button></div>
            @elseif ($m->noshow_reported_at !== null)
                <p class="m-0">{{ __('You claimed a no-show. :name can answer until :time.', ['name' => $otherName, 'time' => $at($m->casualContestDueAt())]) }}</p>
            @elseif ($iHost && $m->lobby_shared_at === null)
                <p class="m-0">{{ $isRl ? __('Create a private match in Rocket League and share its name and password in the chat.') : __('Share your EA ID in the chat. :name sends you a friend request; accept it and send the Play a Friend invite.', ['name' => $otherName]) }}</p>
            @elseif ($iHost && $m->joined_at === null)
                <p class="m-0">{{ $isRl ? __('Waiting for :name to join your private match.', ['name' => $otherName]) : __('Accept the friend request from :name and send the Play a Friend invite.', ['name' => $otherName]) }}</p>
            @elseif (! $iHost && $m->lobby_shared_at === null)
                <p class="m-0">{{ $isRl ? __('Waiting for :name to share the lobby in the chat.', ['name' => $otherName]) : __('Waiting for :name to share their EA ID in the chat.', ['name' => $otherName]) }}</p>
            @elseif (! $iHost && $m->joined_at === null)
                <p class="m-0">{{ $isRl ? __('Join the private match with the name and password from the card in the chat, then confirm here.') : __('Send :name a friend request with the EA ID from the chat and accept the invite, then confirm here.', ['name' => $otherName]) }}</p>
                <div><x-button icon="check" wire:click="casualJoined" data-test="casual-joined">{{ __('I am in the lobby') }}</x-button></div>
            @else
                <p class="m-0">{{ __('Both are in. Play, then enter the score below.') }}</p>
            @endif

            @if ($canClaim)
                <div class="flex flex-wrap items-center gap-3">
                    <x-button variant="secondary" icon="user" wire:click="casualClaimNoShow" data-test="casual-claim">{{ __('Opponent didn\'t show') }}</x-button>
                    <span class="text-xs text-ink-3">{{ __(':name can answer within :minutes minutes.', ['name' => $otherName, 'minutes' => $m->casualSetting('contest_minutes')]) }}</span>
                </div>
            @endif

            @if ($canSwap)
                <div class="flex flex-col items-start gap-1 border-t border-hairline pt-3">
                    <x-button variant="quiet" icon="retry" wire:click="casualSwapHost" wire:confirm="{{ __(':name becomes the host and gets the full time to share the lobby. This works once per match.', ['name' => $otherName]) }}" data-test="casual-swap">{{ __('Can\'t share, swap host') }}</x-button>
                    <span class="text-xs text-ink-3">{{ __('If your signer fails, hand the host seat to :name instead of missing the deadline.', ['name' => $otherName]) }}</span>
                </div>
            @endif
        @endif
    </div>
</section>
