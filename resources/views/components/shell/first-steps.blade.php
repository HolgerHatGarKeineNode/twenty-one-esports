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
    <div class="relative flex flex-col gap-2 py-3 pr-14 pl-4 lg:flex-row lg:items-center lg:gap-6 lg:px-8">
        <h2 id="first-steps-h" class="m-0 shrink-0 text-[13px] font-bold text-btc-hi">{{ __('New here?') }}</h2>
        <ol class="m-0 flex list-none flex-col gap-1 p-0 lg:flex-row lg:gap-6">
            @foreach ([[route('login', ['then' => 'play']), __('Log in with Nostr or Google')], [route('chess.lobby'), __('Play a casual chess game')], [route('clans.index'), __('Join a clan')]] as $index => [$href, $label])
                <li class="flex items-center gap-2 text-[13px]">
                    <span aria-hidden="true" class="flex size-5 shrink-0 items-center justify-center rounded-xs bg-btc text-xs font-bold text-on-btc">{{ $index + 1 }}</span>
                    <a href="{{ $href }}" class="inline-flex min-h-11 items-center text-ink underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc lg:min-h-8">{{ $label }}</a>
                </li>
            @endforeach
        </ol>
        <button type="button" x-on:click="dismiss()" class="absolute top-1 right-1 flex size-11 cursor-pointer items-center justify-center rounded-md text-ink-2 hover:text-ink lg:top-1/2 lg:right-4 lg:-translate-y-1/2" data-test="first-steps-dismiss">
            <x-icon name="close" :size="18" />
            <span class="sr-only">{{ __('Dismiss the first steps') }}</span>
        </button>
    </div>
</section>
