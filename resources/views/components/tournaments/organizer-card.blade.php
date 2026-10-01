@props(['card', 'loading' => 'lazy'])

{{--
    One tournament an organizer set up, as a large card on the tournaments
    page (user, 2026-09-28: "mit Bildern oben groß"): the game's cover across
    the top (never text on the art), where it stands as an icon and a word,
    the prize pot chip, the name, game, mode and format, the start on the
    viewer's clock (day, clock, city; the cup board's rule) and the places as
    one tile per seat. The whole card is one link, one tab stop.

    $card: an OrganizerBoard entry. `loading`: "eager" for the covers in the
    first screen, lazy below.
--}}
@php
    use App\Support\Tournaments\Lobbies;

    $tournament = $card['tournament'];
    [$stateIcon, $stateLabel, $stateTone] = match ($card['state']) {
        'open' => ['clock', __('Open for sign-up'), 'bg-btc-chip text-btc-hi'],
        'closed' => ['lock', __('Sign-up closed'), 'bg-raised text-ink-2'],
        'drawing' => ['clock', __('Draw pending'), 'bg-raised text-ink-2'],
        'running' => ['play', __('Running'), 'bg-win-tint text-win'],
        'paused' => ['warn', __('Paused'), 'bg-raised text-ink-2'],
        'cancelled' => ['close', __('Called off'), 'bg-loss-tint text-loss'],
        default => ['check', __('Finished'), 'bg-raised text-ink-2'],
    };
    $seatTiles = $card['places'] > 0 && $card['places'] <= 32;
    // The browser rewrites the start to its own zone; a start in another year keeps the server's words, which name the year.
    $fixed = $card['fixedZone'] || $tournament->starts_at->year !== now()->year;
@endphp

<article {{ $attributes->class('relative flex min-w-0 flex-col overflow-hidden rounded-card bg-card shadow-ring-hairline has-[[data-card-link]:focus-visible]:outline-2 has-[[data-card-link]:focus-visible]:outline-offset-2 has-[[data-card-link]:focus-visible]:outline-btc-hi') }}
         data-test="organizer-card" data-tournament="{{ $tournament->id }}" data-state="{{ $card['state'] }}">
    <x-game-cover :game="$tournament->game" size="header" :loading="$loading" class="w-full" data-test="organizer-card-cover" />

    <div class="flex grow flex-col gap-3 p-4 lg:p-5">
        <p class="m-0 flex flex-wrap items-center gap-2 text-xs font-bold">
            <span class="inline-flex h-6 items-center gap-1.5 rounded-tag px-2 {{ $stateTone }}" data-test="organizer-card-state"><x-icon :name="$stateIcon" :size="14" />{{ $stateLabel }}</span>
            <x-prize-chip :tournament="$tournament" />
        </p>

        <h3 class="m-0 font-display text-lg leading-[1.25] font-bold break-words lg:text-xl">
            <a href="{{ route('tournaments.show', $tournament) }}" class="text-ink after:absolute after:inset-0 hover:text-btc-hi focus-visible:outline-none" data-card-link data-test="organizer-card-name">{{ $tournament->name }}</a>
        </h3>

        <p class="m-0 text-[13px] leading-normal text-ink-2">{{ Lobbies::gameLine($tournament) }}, {{ Lobbies::formatLabel($tournament) }}</p>

        <time datetime="{{ $tournament->starts_at->copy()->utc()->format('Y-m-d\TH:i:s\Z') }}" class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-[13px] text-ink-2" data-test="organizer-card-start"
              @unless ($fixed) x-data="cupStart({ at: {{ (int) $tournament->starts_at->getTimestampMs() }}, zone: @js($card['zone']) })" @endunless>
            <b class="font-display text-base text-ink tabular-nums" @unless ($fixed) x-text="clock || @js($card['clock'])" @endunless>{{ $card['clock'] }}</b>
            <span><span @unless ($fixed) x-text="day || @js($card['day'])" @endunless>{{ $card['day'] }}</span>, <span class="text-ink-3" @unless ($fixed) x-text="city || @js($card['city'])" @endunless>{{ $card['city'] }}</span></span>
        </time>

        <div class="mt-auto flex flex-col gap-1.5 pt-1" data-test="organizer-card-places">
            @if ($seatTiles)
                <span class="grid h-2 auto-cols-fr grid-flow-col gap-0.5" aria-hidden="true">
                    @for ($seat = 0; $seat < $card['places']; $seat++)
                        <span @class(['rounded-[1px]', 'bg-btc' => $seat < $card['taken'], 'border border-edge' => $seat >= $card['taken']])></span>
                    @endfor
                </span>
            @else
                <span class="h-2 overflow-hidden rounded-[1px] border border-edge" aria-hidden="true"><span class="block h-full bg-btc" style="width: {{ $card['places'] > 0 ? min(100, (int) round(100 * $card['taken'] / $card['places'])) : 0 }}%"></span></span>
            @endif
            <span class="text-xs text-ink-2">{{ __(':taken of :places places taken', ['taken' => $card['taken'], 'places' => $card['places']]) }}</span>
        </div>
    </div>
</article>
