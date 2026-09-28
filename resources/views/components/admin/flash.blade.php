@props(['tone' => 'success', 'message' => null])

{{--
    One outcome line of the admin area: success (green) or error (red), each
    with its own icon, so the colour is never the only sign. The text comes
    as `message` (escaped) or as the slot (markup of the caller).
--}}
@if (($message !== null && $message !== '') || $slot->isNotEmpty())
    <p {{ $attributes->class([
        'm-0 flex max-w-[80ch] items-start gap-3 rounded-md px-4 py-3 text-[13px] leading-normal',
        'bg-win-tint text-win shadow-[inset_0_0_0_1px_#1F5A34]' => $tone === 'success',
        'bg-loss-tint text-loss shadow-[inset_0_0_0_1px_#5A2A2E]' => $tone === 'error',
    ]) }} role="{{ $tone === 'error' ? 'alert' : 'status' }}">
        <x-icon :name="$tone === 'error' ? 'warn' : 'check'" :size="16" class="mt-0.5 shrink-0" />
        <span class="min-w-0">@if ($message !== null && $message !== ''){{ $message }}@else{{ $slot }}@endif</span>
    </p>
@endif
