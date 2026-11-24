@props(['game' => null, 'mode' => null, 'opponent' => null, 'user' => null, 'compact' => false, 'names' => true, 'path' => null, 'except' => null])

{{--
    "Your path to Season 1" (plan "Season vs. Casual", P4): what stands between the signed-in player and rated play,
    from the gate itself (App\Support\SeasonChain\SeasonPath). Eligible: one line and the mutual opponents. Steps: one
    row per step, done or not, one sentence and at most one button (R3: steps, not paragraphs). Blocked (no season, a
    game not in the season yet, a fair-play bar, an account check): that one message, no button. Nothing for a guest.

    Props: `game` and `mode` the lobby's (null: any game, as /mining and the hub), `opponent` a concrete player in
    context (the add button for exactly that player), `user` another viewer than the signed-in one (tests only),
    `compact` the steps as one row of chips beside the heading (a done step green, an open one red with its fix as
    the link; on a phone the heading is for screen readers only), for a page with a height budget (/mining's hero,
    where these chips stood before). `names` false: the eligible line leaves the mutual opponents to
    x-opponents.mutual right under it (plan P5: the lobbies and /me). `path`: SeasonPath::for() already read by the
    page (a lobby that drops its own reason line while the steps show), instead of reading it again.
--}}
@php
    $viewer = $user ?? auth()->user();
    $path ??= $viewer instanceof \App\Models\User ? app(\App\Support\SeasonChain\SeasonPath::class)->for($viewer, $game, $mode, $opponent) : null;
@endphp

@if ($path !== null)
    <section {{ $attributes->class(['flex min-w-0 gap-2', 'flex-col rounded-[4px] bg-card p-3 shadow-ring lg:p-4' => ! $compact, 'flex-wrap items-center' => $compact]) }}
             aria-label="{{ __('Your path to :season', ['season' => $path['season']]) }}" data-test="season-path" data-state="{{ $path['state'] }}" data-kind="{{ $path['kind'] ?? '' }}">
        @if ($path['state'] === 'eligible')
            <p class="m-0 flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] leading-normal" data-test="season-path-eligible">
                <x-icon name="check" :size="16" class="shrink-0 text-win" />
                <b>{{ __('You can play :season', ['season' => $path['season']]) }}</b>
                @if ($names && $path['mutual'] !== [])
                    <span class="text-ink-2">{{ __('Mutual opponents:') }}</span>
                    @foreach ($path['mutual'] as $mutual)
                        <a href="{{ $mutual['href'] }}" class="rv-tag max-w-[16ch] truncate text-ink hover:text-ink" data-test="season-path-mutual">{{ $mutual['name'] }}</a>
                    @endforeach
                @endif
            </p>
        @else
            <h2 @class(['m-0 text-[13px] font-bold', 'max-sm:sr-only' => $compact])>{{ __('Your path to :season', ['season' => $path['season']]) }}</h2>
            @if ($path['state'] === 'blocked')
                <p class="m-0 flex max-w-[68ch] items-start gap-2 text-[13px] leading-normal text-ink-2" data-test="season-path-blocked">
                    <x-icon :name="in_array($path['kind'], ['barred'], true) ? 'alert' : 'clock'" :size="16" class="mt-0.5 shrink-0" />
                    <span class="min-w-0">{{ $path['message'] }}</span>
                </p>
            @elseif ($compact)
                <ol class="contents" data-test="season-path-steps">
                    {{-- `except`: the step the page already offers as its primary button (/mining), not repeated as a chip. --}}
                    @foreach (array_filter($path['steps'], fn (array $step): bool => $step['key'] !== $except) as $step)
                        <li class="flex min-w-0" data-test="season-path-step" data-key="{{ $step['key'] }}" data-done="{{ $step['done'] ? 'true' : 'false' }}">
                            @if ($step['player'] !== null)
                                <livewire:opponent-button :player="$step['player']" :inline="true" :key="'season-path-'.$step['player']->id" />
                            @elseif ($step['href'] !== null)
                                <a href="{{ $step['href'] }}" class="inline-flex min-h-11 min-w-0 items-center gap-2 rounded-[4px] border border-live-ring bg-live-tint px-3 text-[13px]" title="{{ $step['text'] }}" data-test="season-path-fix">
                                    <x-icon name="alert" :size="14" class="shrink-0 text-loss" /><span class="min-w-0">{{ $step['short'] }}</span> <span class="whitespace-nowrap text-loss underline underline-offset-2 max-sm:sr-only">{{ $step['cta'] }}</span><x-icon name="next" :size="14" class="shrink-0 text-loss sm:hidden" />
                                </a>
                            @else
                                <span @class(['inline-flex min-h-8 min-w-0 items-center gap-2 rounded-[4px] border px-3 text-[13px]', 'border-win-ring bg-win-tint' => $step['done'], 'border-line bg-ground text-ink-2' => ! $step['done']]) title="{{ $step['text'] }}">
                                    <x-icon :name="$step['done'] ? 'check' : 'clock'" :size="14" @class(['shrink-0', 'text-win' => $step['done']]) /><span class="min-w-0">{{ $step['short'] }}</span>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @else
                <ol class="m-0 flex list-none flex-col p-0" data-test="season-path-steps">
                    @foreach ($path['steps'] as $step)
                        <li class="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 border-t border-hairline py-1.5 first:border-t-0" data-test="season-path-step" data-key="{{ $step['key'] }}" data-done="{{ $step['done'] ? 'true' : 'false' }}">
                            <span class="flex min-w-0 grow basis-60 items-start gap-2 text-[13px] leading-normal">
                                <x-icon :name="$step['done'] ? 'check' : 'alert'" :size="16" @class(['mt-0.5 shrink-0', 'text-win' => $step['done'], 'text-loss' => ! $step['done']]) />
                                <span @class(['min-w-0 [overflow-wrap:anywhere]', 'text-ink-2' => $step['done']])>{{ $step['text'] }}</span>
                            </span>
                            @if ($step['player'] !== null)
                                <livewire:opponent-button :player="$step['player']" :inline="true" :key="'season-path-'.$step['player']->id" />
                            @elseif ($step['href'] !== null)
                                <a href="{{ $step['href'] }}" class="rv-b2 is-sm shrink-0 whitespace-nowrap" data-test="season-path-fix">{{ $step['cta'] }}</a>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        @endif
    </section>
@endif
