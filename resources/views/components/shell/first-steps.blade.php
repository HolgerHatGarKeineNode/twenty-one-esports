{{--
    "New here?" (guests only): the three steps into the league, under the
    header on every page until the visitor dismisses it. The dismissal is kept
    per browser in localStorage, with a cookie as fallback; the inline script
    hides the strip before the first paint. Without any storage the strip
    simply shows again on the next page: nothing here can throw.
--}}
<script>
    (() => {
        const key = 'twentyone.firstSteps';
        let dismissed = false;
        try { dismissed = window.localStorage.getItem(key) === 'dismissed'; } catch (e) {}
        try { dismissed = dismissed || document.cookie.split('; ').includes(key + '=dismissed'); } catch (e) {}
        if (dismissed) document.documentElement.dataset.stepsDismissed = '1';
    })();
</script>
<section class="first-steps shrink-0 border-b border-btc-ring bg-pl-bar" aria-labelledby="first-steps-h" x-data="firstSteps" data-test="first-steps">
    {{--
        One row at every width. On a phone the steps scroll sideways inside it: stacked, the strip was
        193 px tall and pushed the first thing of a game page (its invite) under the tab bar.
    --}}
    <div class="flex items-center gap-3 py-1.5 pr-1 pl-4 lg:gap-6 lg:py-3 lg:pr-4 lg:pl-8">
        <h2 id="first-steps-h" class="m-0 shrink-0 text-[13px] font-bold text-btc-hi">{{ __('New here?') }}</h2>
        <ol class="m-0 flex min-w-0 flex-1 list-none gap-5 overflow-x-auto p-0 whitespace-nowrap [scrollbar-width:none] max-lg:[mask-image:linear-gradient(90deg,#000_calc(100%-24px),transparent)] lg:gap-6">
            @foreach ([[route('login', ['then' => 'play']), __('Log in with Nostr or Google')], [route('chess.lobby'), __('Play a casual chess game')], [route('clans.index'), __('Join a clan')]] as $index => [$href, $label])
                <li class="flex shrink-0 items-center gap-2 text-[13px]">
                    <span aria-hidden="true" class="flex size-5 shrink-0 items-center justify-center rounded-xs bg-btc text-xs font-bold text-on-btc">{{ $index + 1 }}</span>
                    <a href="{{ $href }}" @navigate($href) class="inline-flex min-h-11 items-center text-ink underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc lg:min-h-8">{{ $label }}</a>
                </li>
            @endforeach
        </ol>
        <button type="button" x-on:click="dismiss()" class="flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md text-ink-2 hover:text-ink" data-test="first-steps-dismiss">
            <x-icon name="close" :size="18" />
            <span class="sr-only">{{ __('Dismiss the first steps') }}</span>
        </button>
    </div>
</section>
