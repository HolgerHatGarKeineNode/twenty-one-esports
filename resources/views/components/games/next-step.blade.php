@props(['game', 'steps', 'standing' => null, 'playHref'])

{{--
    "Your next step" on a series game's landing (P56). A viewer with a
    result here sees where they stand: their place and rating on the ladder
    of their best entry, and the way to the full ladder. Everyone else sees
    the three first steps of this game as a real sequence (hence the
    numbers), each ticked once done: log in, a side to play for (a clan, or
    a 1v1 without one), a first finished series.

    $steps and $standing: App\Support\Games\GameLanding. `playHref`: where
    the third step starts a series on this page.
--}}
@php
    $gameName = \App\Support\GameNames::game($game);
@endphp

@if ($standing !== null)
    <section aria-labelledby="gs-h" {{ $attributes->class('flex flex-col gap-3 rounded-lg bg-card px-4 py-4 shadow-ring-btc lg:px-5') }} data-test="game-standing">
        <h2 id="gs-h" class="m-0 text-[15px] font-bold">{{ __('Your standing') }}</h2>
        <a href="{{ route('ladder.show', [$game, $standing['mode']]) }}" class="flex min-h-14 items-center gap-4 text-ink hover:text-ink" data-test="game-standing-link">
            <b class="font-display text-4xl leading-none text-btc tabular-nums" data-test="game-standing-rank">#{{ $standing['rank'] }}</b>
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="text-[13px] font-bold">{{ __(':mode ladder', ['mode' => $standing['mode']]) }}</span>
                <span class="text-xs text-ink-2">{{ __(':rating Elo after :results', ['rating' => $standing['rating'], 'results' => trans_choice(':count series|:count series', $standing['results'])]) }}</span>
            </span>
        </a>
    </section>
@else
    @php
        $rows = [
            ['login', __('Log in with Nostr or Google'), route('login'), $steps['login']],
            ['side', __('Join a clan, or play 1v1 without one'), route('clans.index'), $steps['side']],
            ['played', __('Finish your first :game series', ['game' => $gameName]), $playHref, $steps['played']],
        ];
        $next = collect($rows)->search(fn (array $row): bool => ! $row[3]);
    @endphp
    <section aria-labelledby="gst-h" {{ $attributes->class('flex flex-col gap-2 rounded-lg bg-card px-4 py-4 lg:px-5') }} data-test="game-steps">
        <h2 id="gst-h" class="m-0 text-[15px] font-bold">{{ __('Start in three steps') }}</h2>
        <ol class="m-0 flex list-none flex-col p-0">
            @foreach ($rows as $index => [$key, $label, $href, $done])
                <li class="flex min-h-12 items-center gap-3 border-b border-hairline last:border-0" data-test="game-step" data-step="{{ $key }}" data-done="{{ $done ? 'true' : 'false' }}">
                    @if ($done)
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-md bg-win-tint text-win"><x-icon name="check" :size="16" /><span class="sr-only">{{ __('Done') }}:</span></span>
                        <span class="text-[13px] text-ink-3">{{ $label }}</span>
                    @else
                        <span @class(['flex size-7 shrink-0 items-center justify-center rounded-md font-display text-[13px] font-bold', 'bg-btc text-on-btc' => $index === $next, 'bg-raised text-ink-2' => $index !== $next]) aria-hidden="true">{{ $index + 1 }}</span>
                        <a href="{{ $href }}" @class(['inline-flex min-h-11 min-w-0 items-center text-[13px]', 'font-bold text-ink hover:text-btc-hi' => $index === $next, 'text-ink-2 hover:text-ink' => $index !== $next])>{{ $label }}</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
