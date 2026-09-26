{{--
    The settings tabs, one list for every settings page, so a new tab is on all
    of them. $current: gaming | chess | opponents | badges. Scrolls sideways
    instead of overflowing the page on a narrow screen.
--}}
@php
    $tabs = [
        'gaming' => [route('gaming.edit'), __('General'), 'settings-general-tab'],
        'chess' => [route('settings.chess'), __('Chess'), 'settings-chess-tab'],
        'opponents' => [route('settings.opponents'), __('Opponents'), 'settings-opponents-tab'],
        'badges' => [route('settings.badges'), __('Badges'), 'settings-badges-tab'],
    ];
@endphp
<nav aria-label="{{ __('Settings sections') }}" class="flex max-w-full overflow-x-auto border-b border-hairline">
    @foreach ($tabs as $key => [$href, $label, $test])
        <a href="{{ $href }}" data-test="{{ $test }}" @if ($key === $current) aria-current="page" @endif
           @class([
               'flex h-11 shrink-0 items-center px-3.5 text-[13px] whitespace-nowrap hover:text-ink',
               'font-bold text-ink shadow-[inset_0_-2px_0_#F7931A]' => $key === $current,
               'text-ink-2' => $key !== $current,
           ])>{{ $label }}</a>
    @endforeach
</nav>
