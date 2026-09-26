@props(['events', 'headingId' => 'weekly-h'])

{{--
    The next weekly events (P10, App\Support\Engagement\WeeklySlots): title,
    game, day and time in the viewer's time zone, "live now" while one runs.
    Renders nothing without events. Used on home and in the chess lobby.
--}}
@php
    use App\Support\PreSeason;

    $zone = PreSeason::timezoneFor(auth()->user());
    $games = app(App\Games\GameRegistry::class);
@endphp

@if ($events->isNotEmpty())
    <section aria-labelledby="{{ $headingId }}" {{ $attributes->class('flex flex-col gap-3') }} data-test="weekly-events">
        <span class="flex items-baseline justify-between gap-3">
            <h2 id="{{ $headingId }}" class="m-0 text-[15px] font-bold">{{ __('Weekly events') }}</h2>
            <span class="text-xs text-ink-2">{{ __('Play together, every week') }}</span>
        </span>
        <ul class="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(min(100%,300px),1fr))] gap-2 p-0">
            @foreach ($events as $event)
                @php
                    $slot = $event->slot;
                    $mode = $games->mode($slot->game, $slot->mode);
                    $label = __($games->find($slot->game)?->name() ?? $slot->game).' · '.__($mode?->name ?? $slot->mode);
                    $start = $event->starts_at->toImmutable()->setTimezone($zone)->locale(app()->getLocale());
                    $live = $event->isLive();
                    $href = $slot->game === 'chess' ? route('chess.lobby') : route('games.rocket-league');
                @endphp
                <li wire:key="slot-event-{{ $event->id }}">
                    <a href="{{ $href }}" @class(['flex min-h-14 items-center gap-3 rounded-md border px-3 py-2 text-ink hover:text-ink', 'border-btc-ring bg-btc-chip' => $live, 'border-line bg-well' => ! $live]) data-test="weekly-event">
                        <span @class(['flex size-10 shrink-0 flex-col items-center justify-center rounded-sm text-[10px] leading-tight font-bold uppercase', 'bg-btc text-on-btc' => $live, 'bg-raised text-ink-2 shadow-ring' => ! $live]) aria-hidden="true">
                            <span>{{ $start->isoFormat('ddd') }}</span>
                            <span class="text-[13px]">{{ $start->isoFormat('D') }}</span>
                        </span>
                        <span class="flex min-w-0 grow flex-col gap-0.5">
                            <b class="truncate text-[13px]">{{ $slot->title }}</b>
                            <span class="truncate text-xs text-ink-2">{{ $label }}</span>
                        </span>
                        @if ($live)
                            <span class="flex shrink-0 items-center gap-1.5 text-xs font-bold text-btc-hi"><span class="size-2 animate-live rounded-full bg-btc-hi" aria-hidden="true"></span>{{ __('running now') }}</span>
                        @else
                            <span class="shrink-0 text-xs whitespace-nowrap text-ink-2">{{ $start->isoFormat('ddd HH:mm') }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
