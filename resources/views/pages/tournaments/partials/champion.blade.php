{{--
    The champion moment (App\Support\Tournaments\TournamentChampionMoment; user, 2026-10-03: "Das mini Ding als
    Stolz-Moment für den Sieger??? NEINN!!!!! Das muss krasser werden!!!!"): the first thing on a finished tournament
    page, full width. The champion under a gold stage light: the avatar large in a gold ring with the trophy on it,
    "Champion · <tournament>", the name in display type, the viewer's own line (You won! / their place), the prize
    once paid, "Share the win" and "Results". Beside it (below on a phone) the podium of places 1 to 3; under both the
    champion's record and path. A shared place 1 shows everybody on it.

    Once per browser and tournament it celebrates (championMoment.js: falling blocks, a gold sheen over the name);
    with reduced motion it stands still. The winner shares on Nostr with the champion card (share-button); everybody
    else shares the page, whose link preview is the podium card.

    `$moment` (TournamentChampionMoment::of()), `$tournament`, `$results` (the anchor of the results: #bracket or
    #leaderboard), `$desk` (TournamentDesk::for() or null). The closing comment marks the section's end for the feature test.
--}}
@php
    $champions = $moment['champions'];
    $shared = $moment['shared'];
    $viewer = $moment['viewer'];
    $won = ($viewer['state'] ?? null) === 'won';
    $lead = $champions[0];
    $leadUser = $lead['users'][0] ?? null;
    $names = implode(', ', array_column($champions, 'name'));
    $cover = app(\App\Games\GameRegistry::class)->cover((string) $tournament->game);
    $record = $moment['record'];
    $recordLine = $record === null ? null : implode(', ', array_filter([
        trans_choice(':count win|:count wins', $record['wins']),
        $record['draws'] > 0 ? trans_choice(':count draw|:count draws', $record['draws']) : null,
        $record['losses'] > 0 ? trans_choice(':count loss|:count losses', $record['losses']) : __('no loss'),
    ]));
    // The steps of the podium: shown second, first, third from the left; the champion stands highest.
    $steps = [
        1 => ['step' => 'h-16 bg-rank-gold lg:h-24', 'avatar' => 56, 'order' => 'order-2'],
        2 => ['step' => 'h-12 bg-rank-silver lg:h-16', 'avatar' => 44, 'order' => 'order-1'],
        3 => ['step' => 'h-8 bg-rank-bronze lg:h-12', 'avatar' => 44, 'order' => 'order-3'],
    ];
    $byPlace = collect($moment['podium'])->keyBy('place');
    $shareText = $shared
        ? __(':names share 1st place in :tournament', ['names' => $names, 'tournament' => $tournament->title()])
        : __(':name won :tournament', ['name' => $lead['name'], 'tournament' => $tournament->title()]);
    // Falling blocks: position, size, delay, drift and colour per block, fixed so a render is stable.
    $rain = [];
    foreach (range(0, 27) as $i) {
        $rain[] = sprintf('--x:%d%%;--s:%dpx;--d:%dms;--t:%dms;--drift:%dpx;--r:%ddeg;--c:var(--color-%s)',
            ($i * 37 + 7) % 100, [8, 10, 12, 6][$i % 4], ($i * 53) % 700, 1500 + ($i * 71) % 700, (($i * 29) % 120) - 60, 90 + ($i * 47) % 270,
            ['rank-gold', 'btc', 'btc-hi', 'rank-gold', 'ink'][$i % 5]);
    }
@endphp
<section aria-labelledby="champion-h" class="cm-stage relative isolate overflow-hidden border-b border-[color-mix(in_oklab,var(--color-rank-gold)_35%,transparent)]"
         x-data="championMoment({ id: {{ $tournament->id }} })" :class="celebrating && 'is-celebrating'"
         data-test="champion-hero" data-viewer="{{ $viewer['state'] ?? 'guest' }}" data-shared="{{ $shared ? '1' : '0' }}">
    @if ($cover)
        {{-- The game, faint at the far side: whose tournament this was, without competing with the champion. --}}
        <img src="{{ asset($cover->path($cover->widths[0], 'webp')) }}" alt="" width="480" height="270" loading="eager" decoding="async" aria-hidden="true"
             class="pointer-events-none absolute inset-y-0 right-0 -z-10 h-full w-full object-cover opacity-15 [mask-image:linear-gradient(to_bottom,black,transparent_70%)] lg:w-1/2 lg:opacity-15 lg:[mask-image:linear-gradient(to_right,transparent,black_60%)]">
    @endif
    <div class="cm-rain" aria-hidden="true">
        @foreach ($rain as $style)
            <span style="{{ $style }}"></span>
        @endforeach
    </div>

    <div class="grid gap-8 px-4 pt-6 pb-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,460px)] lg:gap-x-16 lg:px-12 lg:pt-12 lg:pb-12">
        {{-- The champion --}}
        <div class="flex min-w-0 flex-col gap-6" @if ($shared) data-test="tournament-shared-first" @else data-test="tournament-winner" @endif>
            <div class="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-center sm:gap-8">
                <div class="relative w-fit shrink-0">
                    @if ($shared)
                        <span class="flex -space-x-4">
                            @foreach (array_slice($champions, 0, 4) as $entry)
                                @if ($entry['users'][0] ?? null)
                                    <x-avatar :user="$entry['users'][0]" :size="96" class="size-20 rounded-full shadow-[0_0_0_4px_var(--color-rank-gold)] lg:size-24" />
                                @else
                                    <span class="grid size-20 place-items-center rounded-full bg-raised text-ink-2 shadow-[0_0_0_4px_var(--color-rank-gold)] lg:size-24"><x-icon name="clans" :size="28" /></span>
                                @endif
                            @endforeach
                        </span>
                    @elseif ($leadUser)
                        <x-avatar :user="$leadUser" :size="176" class="size-28 rounded-full shadow-[0_0_0_4px_var(--color-rank-gold),0_0_48px_color-mix(in_oklab,var(--color-rank-gold)_45%,transparent)] sm:size-36 lg:size-44" />
                    @elseif ($lead['clan'])
                        <x-clan-tag :clan="$lead['clan']" :tile="176" class="flex size-28 items-center justify-center rounded-full bg-btc-tint text-2xl font-bold text-btc shadow-[0_0_0_4px_var(--color-rank-gold)] sm:size-36 lg:size-44" />
                    @else
                        <span class="grid size-28 place-items-center rounded-full bg-raised text-ink-2 shadow-[0_0_0_4px_var(--color-rank-gold)] sm:size-36 lg:size-44"><x-icon name="clans" :size="40" /></span>
                    @endif
                    <span class="absolute -right-1 -bottom-1 grid size-12 place-items-center rounded-full bg-rank-gold text-on-btc shadow-[0_0_0_4px_var(--color-ground)] lg:size-14" data-test="champion-trophy">
                        <x-icon name="trophy" :size="26" />
                    </span>
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <p class="m-0 text-[15px] leading-snug font-bold text-rank-gold [overflow-wrap:anywhere]">
                        {{ $shared ? __('Shared 1st place · :tournament', ['tournament' => $tournament->title()]) : __('Champion · :tournament', ['tournament' => $tournament->title()]) }}
                    </p>
                    <h2 id="champion-h" class="cm-foil m-0 font-display font-bold text-balance text-ink [overflow-wrap:anywhere] {{ $shared ? 'text-[28px] leading-[1.15] sm:text-[36px] lg:text-[44px]' : 'text-[40px] leading-[1.05] sm:text-[56px] lg:text-[72px]' }}" data-test="champion-name">
                        @if ($shared)
                            {{ $names }}
                        @elseif ($lead['href'])
                            <a href="{{ $lead['href'] }}" class="text-ink hover:text-ink hover:underline hover:decoration-rank-gold hover:underline-offset-8">{{ $lead['name'] }}</a>
                        @else
                            {{ $lead['name'] }}
                        @endif
                    </h2>
                    @if ($won)
                        <p class="m-0 flex flex-wrap items-center gap-3" data-test="champion-you">
                            <span class="inline-flex min-h-9 items-center gap-2 rounded-md bg-rank-gold px-3 font-display text-lg font-bold text-on-btc">{{ __('You won!') }}</span>
                            <span class="text-[15px] text-ink-2">{{ __('Congratulations, champion!') }}</span>
                        </p>
                    @elseif (($viewer['state'] ?? null) === 'placed')
                        <p class="m-0 text-[15px] text-ink-2" data-test="champion-you">{{ __('You finished in place :place', ['place' => $viewer['place']]) }}. {{ __('Thanks for playing!') }}</p>
                    @elseif (($viewer['state'] ?? null) === 'played')
                        <p class="m-0 text-[15px] text-ink-2" data-test="champion-you">{{ __('Thanks for playing!') }}</p>
                    @endif
                </div>
            </div>

            @if ($moment['prize'] !== null || $recordLine !== null)
                <p class="m-0 flex flex-wrap items-baseline gap-x-6 gap-y-2 text-[15px] text-ink-2">
                    @if ($moment['prize'] !== null)
                        <span data-test="champion-prize"><b class="font-display text-2xl font-bold text-rank-gold tabular-nums lg:text-3xl">{{ number_format($moment['prize']) }}</b> {{ __('sats prize') }}</span>
                    @endif
                    @if ($recordLine !== null)
                        <span data-test="champion-record"><b class="font-bold text-ink">{{ $recordLine }}</b>@if ($record['points'] !== null), {{ trans_choice(':count point|:count points', (float) $record['points'], ['count' => $record['points']]) }}@endif</span>
                    @endif
                </p>
            @endif

            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start">
                @if ($won && ! $shared)
                    <livewire:share-button type="tournament" :moment="(string) $tournament->id" :label="__('Share the win')" />
                @else
                    <div class="flex flex-col gap-1.5" data-test="champion-share">
                        <div class="flex flex-wrap gap-2">
                            <button type="button" x-on:click="share(@js($shareText), @js(route('tournaments.show', $tournament)), @js(__('Link copied. Paste it into a note in your Nostr app.')))"
                                    class="btn-p inline-flex h-12 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-6 text-[15px] font-bold text-on-btc" data-test="champion-share-button">
                                <x-icon name="send" :size="18" />{{ __('Share the win') }}
                            </button>
                            @unless ($shared)
                                <a href="{{ \App\Support\Cards\ShareCard::tournament($tournament, \App\Models\TournamentParticipant::query()->findOrFail($lead['id']))->path('wide') }}" download="twentyone-champion.png"
                                   class="btn-w inline-flex h-12 items-center gap-2 rounded-md border border-line bg-well px-4 text-[13px] text-ink hover:text-ink" data-test="champion-card"><x-icon name="download" :size="16" />{{ __('Image') }}</a>
                            @endunless
                        </div>
                        <span class="text-xs text-win" role="status" x-show="hint" x-text="hint" x-cloak></span>
                    </div>
                @endif
                <a href="{{ $results }}" class="btn-w inline-flex h-12 items-center justify-center gap-2 rounded-md border border-line bg-well px-6 text-[15px] font-bold text-ink hover:text-ink" data-test="champion-results">
                    <x-icon name="tournaments" :size="18" />{{ __('Results') }}
                </a>
                {{-- The tournament desk stays open a day after the end (TournamentDesk): a question about the result or the prize. --}}
                <x-tournaments.desk-button :desk="$desk ?? null" on-page class="h-12" />
            </div>
        </div>

        {{-- The podium --}}
        <ol class="m-0 grid list-none grid-cols-3 items-end gap-2 self-end p-0 lg:gap-3" aria-label="{{ __('Podium') }}" data-test="champion-podium">
            @foreach ($steps as $place => ['step' => $step, 'avatar' => $size, 'order' => $order])
                @php($row = $byPlace->get($place))
                <li class="flex min-w-0 flex-col items-center gap-1.5 text-center {{ $order }}" data-test="podium-{{ $place }}">
                    @if ($row !== null && $row['entries'] !== [])
                        <span class="flex -space-x-3">
                            @foreach (array_slice($row['entries'], 0, 3) as $entry)
                                @if ($entry['users'][0] ?? null)
                                    <x-avatar :user="$entry['users'][0]" :size="$size" class="shrink-0 rounded-full shadow-[0_0_0_2px_var(--color-ground)]" />
                                @else
                                    <span class="grid shrink-0 place-items-center rounded-full bg-raised text-ink-2 shadow-[0_0_0_2px_var(--color-ground)]" style="width: {{ $size }}px; height: {{ $size }}px"><x-icon name="clans" :size="18" /></span>
                                @endif
                            @endforeach
                        </span>
                        {{-- A shared place names everybody on it in one line: "Lia & Blockrunner", never two names that read as one. --}}
                        @php($placeNames = array_column($row['entries'], 'name'))
                        <span class="line-clamp-3 w-full text-[13px] leading-tight font-bold text-ink [overflow-wrap:anywhere] lg:text-sm" title="{{ implode(', ', $placeNames) }}">{{ count($placeNames) === 2 ? implode(' & ', $placeNames) : implode(', ', $placeNames) }}</span>
                    @else
                        <span class="grid size-11 place-items-center rounded-full border-2 border-dashed border-edge text-ink-3" aria-hidden="true"><x-icon name="user" :size="16" /></span>
                        <span class="text-[13px] text-ink-2">–</span>
                    @endif
                    <span class="mt-1 flex w-full items-start justify-center rounded-t-md pt-1 font-display text-xl font-bold text-on-btc tabular-nums {{ $step }}" aria-label="{{ __('Place :place', ['place' => $place]) }}">{{ $place }}</span>
                </li>
            @endforeach
        </ol>

        {{-- The path: the champion's played matches, in order, the last one the final --}}
        @if ($moment['path'] !== [])
            <div class="flex min-w-0 flex-col gap-3 lg:col-span-2" data-test="champion-path">
                <h3 class="m-0 text-[15px] font-bold text-ink">{{ __('The path to the title') }}</h3>
                <ol class="m-0 flex list-none flex-col p-0 lg:flex-row">
                    @foreach ($moment['path'] as $step)
                        <li class="relative flex min-w-0 flex-1 flex-col gap-1 border-l-2 border-[color-mix(in_oklab,var(--color-rank-gold)_45%,transparent)] py-2 pl-4 lg:border-t-2 lg:border-l-0 lg:pt-3 lg:pr-4 lg:pl-0">
                            <span class="text-xs leading-5 text-ink-3">{{ $step['round'] }}</span>
                            <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-[13px] leading-5">
                                <span @class([
                                    'inline-flex h-6 shrink-0 items-center rounded-xs px-1.5 text-[11px] font-bold',
                                    'bg-rank-gold text-on-btc' => $step['result'] === 'won',
                                    'bg-raised text-ink' => $step['result'] === 'drew',
                                    'bg-loss-tint text-loss' => $step['result'] === 'lost',
                                ])>{{ match ($step['result']) { 'won' => __('Beat'), 'drew' => __('Drew with'), default => __('Lost to') } }}</span>
                                <span class="min-w-0 font-bold text-ink [overflow-wrap:anywhere]">{{ $step['opponent'] }}</span>
                                @if ($step['score'])
                                    <span class="shrink-0 text-ink-2 tabular-nums">{{ $step['score'] }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </div>
</section>
<!-- /champion-hero -->
