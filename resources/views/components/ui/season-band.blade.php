{{--
    The live season as a band (user 2026-11-20: Block 0 is out and the season chain comes first, on the start page
    and on /matches): its block height, when Block 0 was mined, what a win mines per game now and the way to play.
    The figures are HomeBoard::season(), the same as the season card and /mining. Render it only while a season
    runs ($season['live']); it renders nothing otherwise.

    $season: HomeBoard::season(); $viewer: the viewer or null (for the time zone); $test: the data-test prefix;
    $how: where "How to play" leads (plan "Season vs. Casual", P6: the page's x-season.how); $keys: false leaves out
    what a win mines per game, for a page that lists it in its own season card (home; design-lead review, finding 6).
    The heading names the live season ("Season 1 · Block 4"), never a bare "Season".
--}}
@props(['season', 'viewer' => null, 'test' => 'season-band', 'how' => '#season-how', 'keys' => true])

@php
    use App\Support\Engagement\HomeBoard;
    use App\Support\Formats;
    use App\Support\PreSeason;
    use App\Support\SeasonChain\ChainOverview;
    use App\Support\SeasonChain\Seasons;

    $genesis = $season['live'] ? Seasons::live()?->genesis_at : null;
    $open = $keys ? array_values(array_filter($season['keys'], fn (array $key): bool => ! $key['closed'])) : [];
    $seasonName = ($liveSlug = \App\Support\Series\Ladders::season()) === null ? __('Season') : \App\Support\Badges\BadgeCopy::season($liveSlug);
@endphp

@if ($season['live'])
    <section aria-labelledby="{{ $test }}-h" {{ $attributes->class('rv-card flex flex-col gap-3 border-l-2 border-l-btc p-4 lg:flex-row lg:items-center lg:gap-6') }} data-test="{{ $test }}">
        <div class="flex min-w-0 flex-col gap-1">
            <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="rv-live h-5"><i></i>{{ __('Live now') }}</span>
                @if ($genesis !== null)
                    <span class="rv-m" data-test="{{ $test }}-genesis">{{ __('Block 0 mined on :when', ['when' => Formats::date($genesis, true, PreSeason::timezoneFor($viewer))]) }}</span>
                @endif
            </span>
            <h2 id="{{ $test }}-h" class="rv-h2 max-lg:text-[20px]" data-test="{{ $test }}-heading">{{ $seasonName }} · <span class="text-btc tabular-nums" data-test="{{ $test }}-height">{{ __('Block :height', ['height' => HomeBoard::sats((int) $season['height'])]) }}</span></h2>
            <span class="text-[13px] text-ink-2">{{ __('Every fair rated win mines the next block of the season chain.') }}</span>
            {{-- Plan P6: what rated needs, in one line, and the way to the explainer. --}}
            <span class="text-[13px] text-ink-2" data-test="{{ $test }}-needs">{{ __('Rated: two Trusted players who list each other.') }} <a href="{{ $how }}" class="rv-lk whitespace-nowrap" data-test="{{ $test }}-how">{{ __('How it works') }}</a></span>
        </div>
        @if ($open !== [])
            <ul class="m-0 flex list-none flex-wrap gap-2 p-0 lg:max-w-[46%]" data-test="{{ $test }}-keys">
                @foreach ($open as $key)
                    {{-- A phone may break a long name ("EA Sports FC 26 1v1") inside its chip instead of pushing the page sideways. --}}
                    <li class="rv-tag is-btc max-w-full max-lg:h-auto max-lg:py-1 max-lg:leading-snug max-lg:whitespace-normal" data-key="{{ $key['key'] }}"><x-icon name="mining" :size="12" class="shrink-0" />{{ __(':key: :sats sats per win', ['key' => ChainOverview::keyLabel($key['mode']), 'sats' => HomeBoard::sats($key['sats'])]) }}</li>
                @endforeach
            </ul>
        @endif
        <span class="grow max-lg:hidden"></span>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <x-button :href="route('play')" data-test="{{ $test }}-play">{{ __('Play and mine') }}</x-button>
            <a href="{{ route('mining') }}" @navigate(route('mining')) class="rv-lk text-[13px] whitespace-nowrap" data-test="{{ $test }}-chain">{{ __('Season chain') }}</a>
        </div>
    </section>
@endif
