@props(['title' => null])

{{--
    Fallback shell for 500 and 503: no session, database or auth lookups, so it
    still renders when the application itself is the thing that failed.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $title, 'realtime' => false])
    </head>
    <body class="flex min-h-svh flex-col bg-ground font-mono text-ink antialiased">
        <header class="flex h-14 shrink-0 items-center gap-2.5 border-b border-hairline bg-bar px-4 lg:h-16 lg:px-8">
            <a href="/" class="flex min-h-11 items-center gap-2.5 text-ink hover:text-ink" aria-label="{{ __('TWENTY ONE esports, home') }}">
                <x-logo :size="36" />
                <span class="flex items-baseline gap-1.5 whitespace-nowrap">
                    <span class="font-display text-base font-extrabold tracking-[0.02em]">TWENTY ONE</span>
                    <span class="text-xs text-ink-2">esports</span>
                </span>
            </a>
        </header>
        <main id="content" class="flex grow flex-col pt-page-top lg:pt-page-top-lg">
            {{ $slot }}
        </main>
    </body>
</html>
