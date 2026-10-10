@php
    // Three counts on every page (App\Support\Engagement\HomeBoard::stats(), cached; the login page shows the same).
    $stats = \App\Support\Engagement\HomeBoard::stats();
    $locales = ['en' => 'English', 'de' => 'Deutsch'];
    $current = app()->getLocale();
@endphp

{{-- Footer, 1:1 from the design canvas (Header.dc.html "Fußzeile", Main.dc.html, HomePhone.dc.html): the league's three counts, the links with Impressum, the language. --}}
<footer class="shrink-0 border-t border-hairline bg-ground text-[13px]" data-test="footer">
    <div class="hidden items-center gap-6 px-12 py-6 lg:flex">
        <span class="text-ink-2">{{ __('Players') }} <b class="text-ink">{{ $stats['players'] }}</b></span>
        <span class="text-ink-2">{{ __('Clans') }} <b class="text-ink">{{ $stats['clans'] }}</b></span>
        <span class="text-ink-2">{{ __('Games played') }} <b class="text-ink">{{ $stats['games'] }}</b></span>
        <span class="grow"></span>
        <a href="{{ route('rules') }}" @navigate(route('rules')) class="inline-flex min-h-6 items-center text-btc">{{ __('Rules') }}</a>
        <a href="{{ route('protocol') }}" @navigate(route('protocol')) class="inline-flex min-h-6 items-center text-btc">{{ __('Open protocol') }}</a>
        <a href="https://github.com/HolgerHatGarKeineNode/twenty-one-esports" target="_blank" rel="noopener" class="inline-flex min-h-6 items-center text-btc" data-test="source-code">{{ __('Open source') }}</a>
        <a href="https://einundzwanzig.space/kontakt/" target="_blank" rel="noopener" class="inline-flex min-h-6 items-center text-btc" data-test="imprint">{{ __('Legal notice') }}</a>
        <nav aria-label="{{ __('Language') }}" class="flex items-center gap-2">
            @foreach ($locales as $code => $name)
                @if ($code === $current)
                    <b lang="{{ $code }}" aria-current="true" class="text-ink">{{ $name }}</b>
                @else
                    <a href="{{ route('locale.switch', $code) }}" lang="{{ $code }}" hreflang="{{ $code }}" class="inline-flex min-h-6 items-center text-ink-2 hover:text-ink">{{ $name }}</a>
                @endif
            @endforeach
        </nav>
    </div>

    <div class="flex flex-col gap-2 px-4 pt-4 pb-6 text-xs text-ink-3 lg:hidden">
        <span>{{ __('Players') }} {{ $stats['players'] }}, {{ __('Clans') }} {{ $stats['clans'] }}, {{ __('Games') }} {{ $stats['games'] }}</span>
        <span class="flex flex-wrap gap-x-4">
            <a href="{{ route('rules') }}" @navigate(route('rules')) class="inline-flex min-h-11 items-center text-btc">{{ __('Rules') }}</a>
            <a href="{{ route('protocol') }}" @navigate(route('protocol')) class="inline-flex min-h-11 items-center text-btc">{{ __('Open protocol') }}</a>
            <a href="https://github.com/HolgerHatGarKeineNode/twenty-one-esports" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center text-btc">{{ __('Open source') }}</a>
            <a href="https://einundzwanzig.space/kontakt/" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center text-btc">{{ __('Legal notice') }}</a>
            @foreach ($locales as $code => $name)
                @if ($code !== $current)
                    <a href="{{ route('locale.switch', $code) }}" lang="{{ $code }}" hreflang="{{ $code }}" class="inline-flex min-h-11 items-center text-btc">{{ $name }}</a>
                @endif
            @endforeach
        </span>
    </div>
</footer>
