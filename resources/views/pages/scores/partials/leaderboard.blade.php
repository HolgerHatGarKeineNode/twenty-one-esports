{{--
    A score leaderboard (plan "AoE2 und Trackmania", P4): place, the player's league name (never a game account),
    the best value inside the window and when it was set. Entries without a value follow without a place.
    $standings: list<App\Support\Scores\ScoreStanding>; $metric: App\Games\ScoreMetric; $limit: rows shown (null: all);
    $viewerId: the logged-in player, marked "You"; $staff: directors and admins also see the source and the proof link
    (a player's link may name their game account, so it is never public).
    $shareMoment: on a Blockfill week, the viewer's own run that is a moment (BlockfillMoments::shareableOn()): their row
    gets the button that opens the share sheet (components/⚡blockfill-share, on the same page).
--}}
@php
    $limit ??= null;
    $staff ??= false;
    $shareMoment ??= null;
    $rows = $limit === null ? $standings : array_slice($standings, 0, $limit);
    $grid = 'grid grid-cols-[32px_minmax(0,1fr)_minmax(88px,auto)] items-center gap-3 lg:grid-cols-[40px_minmax(0,1fr)_140px_180px]';
@endphp
<div class="flex flex-col" data-test="score-leaderboard">
    <div class="{{ $grid }} h-8 border-b border-hairline px-2 text-xs font-bold text-ink-2">
        <span>#</span>
        <span>{{ __('Player') }}</span>
        <span class="text-right">{{ $metric->unit === 'ms' ? __('Best time') : __('Best score') }}</span>
        <span class="text-right max-lg:hidden">{{ __('Set') }}</span>
    </div>
    <ol class="m-0 flex list-none flex-col p-0">
        @forelse ($rows as $row)
            @php($user = $row->participant->user)
            <li wire:key="score-row-{{ $row->participant->id }}" data-test="score-row" data-place="{{ $row->place ?? '' }}"
                @class([$grid, 'min-h-12 border-b border-hairline px-2 py-1.5 text-[13px] last:border-b-0', 'bg-btc-chip' => $viewerId !== null && $row->participant->user_id === $viewerId])>
                <span @class(['font-display text-base font-bold tabular-nums', 'text-btc' => $row->place === 1, 'text-ink-3' => $row->place === null])>{{ $row->place ?? '–' }}</span>
                <span class="flex min-w-0 items-center gap-2">
                    @if ($user)
                        <x-avatar :user="$user" :size="24" class="shrink-0 rounded-sm" />
                        <a href="{{ route('players.show', $user->npub) }}" class="truncate font-bold text-ink hover:text-btc-hi">{{ $row->participant->name }}</a>
                    @else
                        <span class="truncate font-bold text-ink-2">{{ $row->participant->name }}</span>
                    @endif
                    @if ($viewerId !== null && $row->participant->user_id === $viewerId)
                        <span class="inline-flex h-5 shrink-0 items-center rounded-xs bg-btc px-1.5 text-[11px] font-bold text-on-btc">{{ __('You') }}</span>
                        @if ($shareMoment !== null)
                            <button type="button" x-data x-on:click="window.dispatchEvent(new CustomEvent('blockfill-share', { detail: { moment: @js($shareMoment) } }))" data-test="score-row-share"
                                    class="-my-2 inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md text-ink-2 hover:text-btc-hi" aria-label="{{ __('Share this moment') }}" title="{{ __('Share this moment') }}">
                                <x-icon name="send" :size="16" />
                            </button>
                        @endif
                    @endif
                </span>
                <span class="flex flex-col items-end text-right">
                    @if ($row->value !== null)
                        <b class="font-mono tabular-nums" data-test="score-value">{{ $metric->format($row->value) }}</b>
                    @else
                        <span class="text-xs text-ink-3">{{ $row->participant->isDisqualified() ? __('disqualified') : __('no value yet') }}</span>
                    @endif
                    @if ($staff && $row->source !== null)
                        <span class="text-[11px] text-ink-3">{{ $row->source }}@if ($row->proofUrl) · <a href="{{ $row->proofUrl }}" rel="nofollow noopener" target="_blank" class="text-ink-2 underline">{{ __('proof') }}</a>@endif</span>
                    @endif
                </span>
                <span class="text-right text-xs text-ink-2 tabular-nums max-lg:hidden">{{ $row->achievedAt ? \App\Support\LeagueTime::stamp($row->achievedAt) : '' }}</span>
            </li>
        @empty
            <li class="px-2 py-6 text-[13px] text-ink-2">{{ __('Nobody is in yet.') }}</li>
        @endforelse
    </ol>
    @if ($limit !== null && count($standings) > $limit)
        <p class="m-0 px-2 pt-2 text-xs text-ink-3">{{ __('Showing :shown of :total.', ['shown' => $limit, 'total' => count($standings)]) }}</p>
    @endif
</div>
