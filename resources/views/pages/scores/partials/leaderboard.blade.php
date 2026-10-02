{{--
    A score leaderboard (plan "AoE2 und Trackmania", P4): place (a medal for the top three), the player's league name
    (never a game account), the best value inside the window with its gap to the first, and when it was set. Entries
    without a value follow without a place. Each row ends in its actions (pages.scores.partials.row-actions).
    $standings: list<App\Support\Scores\ScoreStanding>; $metric: App\Games\ScoreMetric; $limit: rows shown (null: all);
    $skip: rows left out at the top (a page that shows them above already, as TMNF's podium; default 0);
    $viewerId: the logged-in player, whose row is marked "You"; $staff: directors and admins also see the source and the
    proof link (a player's link may name their game account, so it is never public); $beat: where "Beat this time" next
    to the first place leads (null: not shown).
    Blockfill (plan "Blockfill", P5): a row whose replay the viewer may watch ends in a 44 px play square to it
    (App\Support\Stacker\StackerReplays::forStandings(): every row whose verified run still keeps its replay).
    $shareMoment: on a Blockfill week, the viewer's own run that is a moment (BlockfillMoments::shareableOn()): their row
    gets the button that opens the share sheet (components/⚡blockfill-share, on the same page).
--}}
@php
    $limit ??= null;
    $staff ??= false;
    $beat ??= null;
    $shareMoment ??= null;
    $skip ??= 0;
    $rows = array_slice($standings, $skip, $limit);
    $replays = app(\App\Support\Stacker\StackerReplays::class)->forStandings($rows, auth()->user() instanceof \App\Models\User ? auth()->user() : null);
    $best = collect($standings)->first(fn ($row): bool => $row->place !== null && $row->value !== null)?->value;
    // The gap to the first: under a minute as seconds ("+1.667"), above it as the value is written ("+1:02.345").
    $gap = function (int $value) use ($best, $metric): string {
        $diff = abs($value - $best);

        return $metric->unit === 'ms' && $diff < 60_000 ? '+'.sprintf('%d.%03d', intdiv($diff, 1000), $diff % 1000) : ($metric->lowerIsBetter() ? '+' : '−').$metric->format($diff);
    };
    $medal = [1 => 'bg-rank-gold', 2 => 'bg-rank-silver', 3 => 'bg-rank-bronze'];
    $grid = 'grid grid-cols-[32px_minmax(0,1fr)_auto] items-center gap-x-3 lg:grid-cols-[40px_minmax(0,1fr)_minmax(120px,auto)_224px]';
@endphp
<div class="flex flex-col" data-test="score-leaderboard">
    <div class="{{ $grid }} h-8 border-b border-hairline px-2 text-xs font-bold text-ink-2">
        <span class="text-center">#</span>
        <span>{{ __('Player') }}</span>
        <span class="text-right">{{ $metric->unit === 'ms' ? __('Best time') : __('Best score') }}</span>
        <span class="text-right max-lg:hidden">{{ __('Set') }}</span>
    </div>
    <ol class="m-0 flex list-none flex-col p-0">
        @forelse ($rows as $row)
            @php
                $user = $row->participant->user;
                $mine = $viewerId !== null && $row->participant->user_id === $viewerId;
                $actions = trim(view('pages.scores.partials.row-actions', ['row' => $row, 'viewerId' => $viewerId])->render());
            @endphp
            <li wire:key="score-row-{{ $row->participant->id }}" data-test="score-row" data-place="{{ $row->place ?? '' }}" @if ($mine) data-mine @endif
                @class([$grid, 'min-h-14 border-b border-hairline px-2 py-2 text-[13px] last:border-b-0', 'bg-btc-chip shadow-[inset_3px_0_0_var(--color-btc)]' => $mine])>
                @if (isset($medal[$row->place]))
                    <span class="flex size-7 items-center justify-self-center justify-center rounded-full font-display text-[13px] font-bold text-on-btc tabular-nums {{ $medal[$row->place] }}" data-test="score-medal">{{ $row->place }}</span>
                @else
                    <span @class(['text-center font-display text-base font-bold tabular-nums', 'text-ink-2' => $row->place !== null, 'text-ink-3' => $row->place === null])>{{ $row->place ?? '–' }}</span>
                @endif
                <span class="flex min-w-0 items-center gap-2.5">
                    @if ($user)
                        <x-avatar :user="$user" :size="28" class="shrink-0 rounded-sm" />
                    @endif
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span class="flex min-w-0 items-center gap-2">
                            @if ($user)
                                <a href="{{ route('players.show', $user->npub) }}" class="truncate font-bold text-ink hover:text-btc-hi">{{ $row->participant->name }}</a>
                            @else
                                <span class="truncate font-bold text-ink-2">{{ $row->participant->name }}</span>
                            @endif
                            @if ($mine)
                                <span class="inline-flex h-5 shrink-0 items-center rounded-xs bg-btc px-1.5 text-[11px] font-bold text-on-btc">{{ __('You') }}</span>
                                @if ($shareMoment !== null)
                                    <button type="button" x-data x-on:click="window.dispatchEvent(new CustomEvent('blockfill-share', { detail: { moment: @js($shareMoment) } }))" data-test="score-row-share"
                                            class="-my-2 inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md text-ink-2 hover:text-btc-hi" aria-label="{{ __('Share this moment') }}" title="{{ __('Share this moment') }}">
                                        <x-icon name="send" :size="16" />
                                    </button>
                                @endif
                            @endif
                        </span>
                        @if ($beat !== null && $row->place === 1 && ! $mine)
                            <a href="{{ $beat }}" class="inline-flex min-h-6 items-center gap-1 self-start text-xs font-bold whitespace-nowrap text-btc-hi hover:text-btc" data-test="beat-this"><x-icon name="play" :size="12" />{{ __('Beat this time') }}</a>
                        @endif
                    </span>
                </span>
                <span class="flex items-center justify-end gap-2">
                    <span class="flex flex-col items-end text-right">
                        @if ($row->value !== null)
                            <b class="font-mono tabular-nums" data-test="score-value">{{ $metric->format($row->value) }}</b>
                            @if ($row->place !== null && $row->place > 1 && $best !== null)
                                <span class="text-xs text-ink-3 tabular-nums" data-test="score-gap">{{ $gap($row->value) }}</span>
                            @endif
                        @else
                            <span class="text-xs text-ink-3">{{ $row->participant->isDisqualified() ? __('disqualified') : __('no value yet') }}</span>
                        @endif
                        @if ($staff && $row->source !== null)
                            <span class="text-[11px] text-ink-3">{{ $row->source }}@if ($row->proofUrl) · <a href="{{ $row->proofUrl }}" rel="nofollow noopener" target="_blank" class="text-ink-2 underline">{{ __('proof') }}</a>@endif</span>
                        @endif
                    </span>
                    {{-- The replay the viewer may watch: a play square of its own, 44 px, not a word under the time --}}
                    @isset($replays[$row->participant->id])
                        <a href="{{ $replays[$row->participant->id] }}" aria-label="{{ __('Watch the replay of :name', ['name' => $row->participant->name]) }}" title="{{ __('Watch replay') }}"
                           class="inline-flex size-11 shrink-0 items-center justify-center rounded-md border border-line bg-well text-btc-hi hover:border-btc hover:bg-btc hover:text-on-btc" data-test="score-replay">
                            <x-icon name="play" :size="18" />
                        </a>
                    @elseif ($replays !== [])
                        {{-- The square's room, so the times stay in one column on a board with replays --}}
                        <span class="size-11 shrink-0" aria-hidden="true"></span>
                    @endisset
                    @if ($actions !== '')
                        <span class="flex shrink-0 items-center gap-1" data-test="score-row-actions">{!! $actions !!}</span>
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
