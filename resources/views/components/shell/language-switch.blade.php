@props(['locales', 'current'])

<nav aria-label="{{ __('Language') }}" class="flex items-center gap-3">
    @foreach ($locales as $code => $name)
        @if ($code === $current)
            <span lang="{{ $code }}" aria-current="true" class="inline-flex min-h-11 items-center font-bold text-ink">{{ $name }}</span>
        @else
            <a href="{{ route('locale.switch', $code) }}" lang="{{ $code }}" hreflang="{{ $code }}" class="inline-flex min-h-11 items-center">{{ $name }}</a>
        @endif
    @endforeach
</nav>
