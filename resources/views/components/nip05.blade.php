@props(['profile', 'size' => 'sm'])

{{--
    A player's NIP-05 address with the league's check (Nip05Verifier):
    verified (green shield), not verified (struck through), or not checked
    yet (plain). Nothing at all without an address.
--}}
@if ($profile->nip05 !== null)
    @php($text = $size === 'md' ? 'text-[13px]' : 'text-xs')
    @if ($profile->nip05State === 'verified')
        <span data-test="nip05" data-state="verified" {{ $attributes->class(['relative flex min-w-0 items-center gap-1.5 text-ink-2', $text]) }}>
            <span class="flex text-win" title="{{ __('verified') }}"><x-icon name="shield-check" :size="14" /></span>
            <span class="truncate">{{ $profile->nip05 }}</span><span class="sr-only">{{ __(', verified') }}</span>
        </span>
    @elseif ($profile->nip05State === 'unverified')
        <span data-test="nip05" data-state="unverified" {{ $attributes->class(['flex min-w-0 items-center gap-1.5 text-ink-3', $text]) }}>
            <span class="flex"><x-icon name="alert" :size="14" /></span>
            <span class="truncate line-through decoration-edge">{{ $profile->nip05 }}</span><span class="shrink-0 whitespace-nowrap">{{ __('not verified') }}</span>
        </span>
    @else
        <span data-test="nip05" data-state="unchecked" {{ $attributes->class(['flex min-w-0 items-center gap-1.5 text-ink-2', $text]) }}>
            <span class="truncate">{{ $profile->nip05 }}</span>
        </span>
    @endif
@endif
