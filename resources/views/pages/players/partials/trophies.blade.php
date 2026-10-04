{{--
    The player's trophies (App\Support\Players\PlayerTrophies), right under the
    header: the newest win (else the newest podium place) as the big plate, the
    other places 1 to 3 beside it (under it on a phone), newest first. Every
    trophy stands on its podium step, gold, silver or bronze, with its place as
    a number and in words, and links to its tournament. The newest win lands
    once on load while it is recent (`pt-arrive`, resources/css/app.css); with
    reduced motion it stands still. A player without a place 1 to 3 has no
    section at all: the tournaments they played stay in the record below.

    $trophies: PlayerTrophies::of()['trophies'], at least one.
--}}
@php
    use App\Support\GameNames;
    use App\Support\PreSeason;

    $tones = [1 => 'var(--color-rank-gold)', 2 => 'var(--color-rank-silver)', 3 => 'var(--color-rank-bronze)'];
    $placeWords = fn (array $trophy): string => match ($trophy['place']) {
        1 => $trophy['shared'] ? __('Shared tournament win') : __('Tournament win'),
        2 => $trophy['shared'] ? __('Shared 2nd place') : __('2nd place'),
        default => $trophy['shared'] ? __('Shared 3rd place') : __('3rd place'),
    };
    $featuredIndex = collect($trophies)->search(fn (array $trophy): bool => $trophy['place'] === 1);
    $featuredIndex = $featuredIndex === false ? 0 : $featuredIndex;
    $featured = $trophies[$featuredIndex];
    $rest = array_values(array_filter($trophies, fn (array $trophy, int $index): bool => $index !== $featuredIndex, ARRAY_FILTER_USE_BOTH));
    $cover = app(\App\Games\GameRegistry::class)->cover((string) $featured['tournament']->game);
    $meta = fn (array $trophy): string => GameNames::full($trophy['tournament']->game, $trophy['tournament']->mode).', '.trans_choice(':count entry|:count entries', (int) $trophy['tournament']->getAttribute('participants_count'));
@endphp

<section aria-labelledby="pt-h" class="mx-4 flex flex-col gap-3 lg:mx-0 lg:gap-4" data-test="player-trophies">
    <h2 id="pt-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Trophies') }}</h2>

    <div @class(['grid grid-cols-1 gap-3 lg:gap-4', 'lg:grid-cols-12' => $rest !== []])>
        {{-- The newest win: the big plate --}}
        <a href="{{ route('tournaments.show', $featured['tournament']) }}" style="--pt-tone: {{ $tones[$featured['place']] }}"
           @class(['pt-feature relative isolate flex min-w-0 items-end gap-4 overflow-hidden rounded-card px-4 pt-5 pb-4 text-ink hover:text-ink sm:gap-6 lg:px-6 lg:pt-6', 'lg:col-span-7' => $rest !== [], 'pt-arrive' => $featured['recent']])
           data-test="player-trophy-featured" data-place="{{ $featured['place'] }}" data-recent="{{ $featured['recent'] ? '1' : '0' }}">
            @if ($cover)
                {{-- The game, faint at the far side, as on the tournament's champion moment --}}
                <img src="{{ asset($cover->path($cover->widths[0], 'webp')) }}" alt="" width="480" height="270" loading="lazy" decoding="async" aria-hidden="true"
                     class="pointer-events-none absolute inset-y-0 right-0 -z-10 h-full w-2/3 object-cover opacity-15 [mask-image:linear-gradient(to_right,transparent,black_70%)]">
            @endif
            <span @class(['pt-step w-16 shrink-0 pt-3 text-[30px] lg:w-20 lg:text-4xl', 'h-24 lg:h-32' => $featured['place'] === 1, 'h-20 lg:h-24' => $featured['place'] === 2, 'h-16 lg:h-20' => $featured['place'] === 3]) aria-hidden="true">{{ $featured['place'] }}</span>
            <span class="pt-words flex min-w-0 grow flex-col gap-1.5 pb-1">
                <span class="text-[13px] font-bold" style="color: var(--pt-tone)" data-test="player-trophy-place">{{ $placeWords($featured) }}@if ($featured['at']), {{ $featured['at']->diffForHumans() }}@endif</span>
                <b class="font-display text-xl leading-tight font-bold [overflow-wrap:anywhere] lg:text-2xl" data-test="player-trophy-name">{{ $featured['tournament']->name }}</b>
                <span class="text-xs text-ink-2 [overflow-wrap:anywhere]">{{ $meta($featured) }}</span>
                @if ($featured['prize'] !== null)
                    <span class="mt-1 inline-flex h-7 items-center gap-1.5 self-start rounded-tag bg-btc-chip px-2.5 text-xs font-bold whitespace-nowrap text-btc-hi" data-test="player-trophy-prize">
                        <x-icon name="bolt" :size="12" />{{ __(':sats sats paid', ['sats' => PreSeason::formatSats($featured['prize'])]) }}
                    </span>
                @endif
            </span>
        </a>

        {{-- Every other place 1 to 3, newest first --}}
        @if ($rest !== [])
            <ol role="list" class="m-0 flex list-none flex-col rounded-card bg-card p-0 px-4 lg:col-span-5 lg:px-5">
                @foreach ($rest as $trophy)
                    <li wire:key="trophy-{{ $trophy['tournament']->id }}" class="border-b border-hairline last:border-0">
                        <a href="{{ route('tournaments.show', $trophy['tournament']) }}" style="--pt-tone: {{ $tones[$trophy['place']] }}"
                           class="grid min-h-16 grid-cols-[28px_minmax(0,1fr)_auto] items-end gap-3 py-3 text-ink hover:text-ink" data-test="player-trophy" data-place="{{ $trophy['place'] }}">
                            <span @class(['pt-step w-7 pt-1 text-xs', 'h-10' => $trophy['place'] === 1, 'h-8' => $trophy['place'] === 2, 'h-6' => $trophy['place'] === 3]) aria-hidden="true">{{ $trophy['place'] }}</span>
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-xs font-bold" style="color: var(--pt-tone)" data-test="player-trophy-place">{{ $placeWords($trophy) }}@if ($trophy['at']), {{ $trophy['at']->translatedFormat('M Y') }}@endif</span>
                                <b class="truncate text-[13px]" data-test="player-trophy-name">{{ $trophy['tournament']->name }}</b>
                                <span class="truncate text-xs text-ink-2">{{ $meta($trophy) }}</span>
                            </span>
                            @if ($trophy['prize'] !== null)
                                <b class="pb-0.5 text-xs whitespace-nowrap text-btc tabular-nums" data-test="player-trophy-prize">{{ PreSeason::formatSats($trophy['prize']) }} sats</b>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</section>
