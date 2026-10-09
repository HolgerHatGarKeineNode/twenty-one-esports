{{--
    Proof of Pong's Elo ladder (plan "Proof of Pong", P4; PongController::ladder()), in the look of the league's ladder
    pages (pages/hyper/ladder): one primary action, the viewer's own standing, the rows and how it counts as chips.
    Permanent Elo, no season: every rated live match between two players moves it.

    $rows: PongLadder::standings(); $mine: PongLadder::card() of the viewer or null; $start: the start Elo.
--}}
@php
    $grid = 'grid grid-cols-[28px_minmax(0,1fr)_56px] gap-3 lg:grid-cols-[40px_minmax(0,1fr)_96px_96px_64px]';
    // Indexed, with its own link preview: the first three by Elo.
    app(\App\Support\PageMeta::class)->describe(__('Proof of Pong ladder'), __('The Proof of Pong Elo ladder of the TWENTY ONE esports league: live 1v1 Pong to 21, every rated match between two players counts.'))
        ->card(fn () => \App\Support\Cards\PageCard::page('pong-ladder'));
@endphp
<x-layouts::app :title="__('Proof of Pong ladder')">
    <div class="flex grow flex-col gap-4 px-4 pb-8 lg:gap-6 lg:px-12" data-test="pong-ladder">
        <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
            <div class="flex min-w-0 flex-col gap-2">
                <h1 class="m-0 flex flex-wrap items-baseline gap-x-3 font-display text-[26px] font-bold lg:text-[34px]">
                    Proof of Pong <span class="font-sans text-xs font-normal text-ink-2">{{ __('Ladder') }}</span>
                </h1>
                @if ($mine !== null)
                    <ul class="m-0 flex list-none flex-wrap items-center gap-2 p-0 text-xs" aria-label="{{ __('You') }}">
                        <li class="inline-flex h-8 items-center gap-2 rounded-md bg-ground px-3 shadow-ring" data-test="pong-ladder-mine">
                            <span class="text-ink-2">{{ __('You') }}</span>
                            <b class="tabular-nums">{{ $mine['rated'] ? $mine['rating'] : '–' }}</b>
                            @if ($mine['rank'] !== null)<span class="text-ink-3">·</span><span class="text-ink-2">#{{ $mine['rank'] }}</span>@endif
                            <span class="text-ink-3">·</span><span class="tabular-nums text-ink-2">{{ $mine['wins'] }} / {{ $mine['losses'] }}</span>
                        </li>
                    </ul>
                @endif
            </div>
            <a href="{{ route('pong.index') }}" class="btn-p inline-flex min-h-12 items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-[15px] font-bold text-on-btc hover:text-on-btc" data-test="pong-ladder-play">
                <x-icon name="play" :size="18" />{{ __('Play a live match') }}
            </a>
        </div>

        <section aria-labelledby="pong-ladder-h" class="flex flex-col rounded-lg bg-card px-2 py-4 lg:px-6 lg:py-5">
            <h2 id="pong-ladder-h" class="m-0 px-2 pb-3 text-[15px] font-bold lg:px-0">{{ __('Elo') }}</h2>
            <div class="{{ $grid }} h-8 items-center border-b border-hairline px-2 text-xs font-bold text-ink-2">
                <span>#</span>
                <span>{{ __('Player') }}</span>
                <span class="text-right">{{ __('Elo') }}</span>
                <span class="hidden text-right lg:block">{{ __('W / L') }}</span>
                <span class="hidden text-right lg:block">{{ __('Results') }}</span>
            </div>
            @forelse ($rows as $row)
                <a href="{{ route('players.show', $row['user']->npub) }}" class="tr {{ $grid }} min-h-[52px] items-center rounded-sm px-2 py-1.5 text-[13px] text-ink hover:text-ink" data-test="pong-ladder-row">
                    <span class="text-ink-3 tabular-nums">{{ $row['rank'] }}</span>
                    <span class="flex min-w-0 items-center gap-2">
                        <x-avatar :user="$row['user']" :size="24" />
                        <span class="truncate font-bold">{{ $row['user']->displayName() }}</span>
                        @if ($row['provisional'])<span class="shrink-0 text-xs text-ink-3">{{ __('provisional') }}</span>@endif
                    </span>
                    <b class="text-right text-[15px] tabular-nums">{{ $row['rating'] }}</b>
                    <span class="hidden text-right text-ink-2 tabular-nums lg:block">{{ $row['wins'] }} / {{ $row['losses'] }}</span>
                    <span class="hidden text-right text-ink-2 tabular-nums lg:block">{{ $row['results'] }}</span>
                </a>
            @empty
                <p class="m-0 px-2 py-6 text-[13px] text-ink-2" data-test="pong-ladder-empty">{{ __('No rated match yet. The first live match between two players opens the ladder.') }}</p>
            @endforelse
        </section>

        <section aria-labelledby="pong-ladder-how" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6">
            <h2 id="pong-ladder-how" class="m-0 text-[15px] font-bold">{{ __('How it counts') }}</h2>
            <ul class="m-0 flex list-none flex-wrap gap-2 p-0 text-xs" data-test="pong-ladder-rules">
                <li class="inline-flex h-8 items-center gap-2 rounded-md bg-ground px-3 shadow-ring"><span class="text-ink-2">{{ __('Start rating') }}</span><b class="tabular-nums">{{ $start }}</b></li>
                <li class="inline-flex h-8 items-center rounded-md bg-ground px-3 shadow-ring">{{ __('Live 1v1 between two players') }}</li>
                <li class="inline-flex h-8 items-center rounded-md bg-ground px-3 shadow-ring">{{ __('Games against a bot do not count') }}</li>
                <li class="inline-flex h-8 items-center rounded-md bg-ground px-3 shadow-ring">{{ __('Gone for :seconds s = loss', ['seconds' => (int) config('esports.pong.forfeit_seconds', 30)]) }}</li>
            </ul>
        </section>
    </div>
</x-layouts::app>
