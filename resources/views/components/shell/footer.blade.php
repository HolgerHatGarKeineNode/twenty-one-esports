@php
    $stats = \App\Support\SampleData::footerStats();
    $locales = ['en' => 'English', 'de' => 'Deutsch'];
    $current = app()->getLocale();
@endphp

{{-- Footer from Main.dc.html (desktop) and MobileHome.dc.html (mobile), plus the language switch. --}}
<footer class="shrink-0 border-t border-hairline text-xs text-ink-3">
    <div class="hidden h-14 items-center gap-9 px-12 lg:flex">
        <span>{{ __('Players') }} <b class="text-ink">{{ $stats['players'] }}</b></span>
        <span>{{ __('Clans') }} <b class="text-ink">{{ $stats['clans'] }}</b></span>
        <span>{{ __('Games played') }} <b class="text-ink">{{ $stats['games'] }}</b></span>
        <span class="grow"></span>
        <a href="{{ route('rules') }}" class="inline-flex min-h-11 items-center">{{ __('Rules') }}</a>
        <a href="{{ route('protocol') }}" class="inline-flex min-h-11 items-center">{{ __('Open protocol') }}</a>
        <x-shell.language-switch :locales="$locales" :current="$current" />
    </div>

    <div class="flex flex-wrap gap-x-5 px-4 pt-2 pb-4 lg:hidden">
        <a href="{{ route('rules') }}" class="inline-flex min-h-11 items-center text-ink-2">{{ __('Rules') }}</a>
        <a href="{{ route('protocol') }}" class="inline-flex min-h-11 items-center text-ink-2">{{ __('How results are verified') }}</a>
        <span class="inline-flex min-h-11 items-center">TWENTY ONE esports</span>
        <x-shell.language-switch :locales="$locales" :current="$current" />
    </div>
</footer>
