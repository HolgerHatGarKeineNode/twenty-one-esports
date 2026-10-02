{{--
    The track of a TMNF week on its board page: a picture of the game's Stadium (an official screenshot, the
    environment the track is driven in, not the track itself), the track's name, and its facts as chips:
    environment, author, author time. $track: TmnfWeeks::track() of the week's course (null: unknown).
--}}
@php
    $authorTime = ($track['author_ms'] ?? 0) > 0 ? \App\Games\ScoreMetric::time()->format((int) $track['author_ms']) : null;
    $chip = 'inline-flex h-8 items-center gap-2 rounded-md bg-raised px-3 text-xs text-ink';
@endphp
<section aria-labelledby="tmnf-track-h" class="flex flex-col overflow-hidden rounded-lg bg-card" data-test="tmnf-track">
    <picture class="block aspect-video bg-well">
        <source type="image/webp" srcset="{{ asset('images/tmnf/stadium-480.webp') }} 480w, {{ asset('images/tmnf/stadium-960.webp') }} 960w" sizes="(min-width: 1024px) 400px, 100vw">
        <img src="{{ asset('images/tmnf/stadium-480.jpg') }}" srcset="{{ asset('images/tmnf/stadium-480.jpg') }} 480w, {{ asset('images/tmnf/stadium-960.jpg') }} 960w" sizes="(min-width: 1024px) 400px, 100vw"
             width="480" height="270" alt="{{ __('The Stadium of TrackMania Nations Forever, seen from above') }}" loading="lazy" decoding="async" class="block size-full object-cover">
    </picture>
    <div class="flex flex-col gap-3 p-4 lg:p-5">
        <h2 id="tmnf-track-h" class="m-0 flex flex-col gap-1">
            <span class="text-xs font-normal text-ink-2">{{ __('Track of the week') }}</span>
            <span class="font-display text-2xl leading-tight font-bold [overflow-wrap:anywhere]">{{ $track['name'] ?? __('the track of the week') }}</span>
        </h2>
        @if ($authorTime !== null)
            <span class="flex flex-col gap-0.5" data-test="track-author-time">
                <span class="text-xs text-ink-2">{{ __('Author time') }}</span>
                <b class="font-mono text-xl font-bold text-tmnf tabular-nums">{{ $authorTime }}</b>
            </span>
        @endif
        <ul class="m-0 flex list-none flex-wrap gap-2 p-0" data-test="track-facts">
            @if (($track['environment'] ?? '') !== '')
                <li class="{{ $chip }}"><x-icon name="flag" :size="14" class="shrink-0 text-tmnf" />{{ $track['environment'] }}</li>
            @endif
            @if (($track['author'] ?? '') !== '')
                <li class="{{ $chip }}">{{ __('Built by :author', ['author' => $track['author']]) }}</li>
            @endif
            <li class="{{ $chip }}"><x-icon name="shield-check" :size="14" class="shrink-0 text-tmnf" />{{ __('Timed by our server') }}</li>
        </ul>
    </div>
</section>
