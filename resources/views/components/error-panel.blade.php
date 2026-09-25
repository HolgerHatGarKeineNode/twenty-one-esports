@props(['code', 'heading', 'text'])

{{-- Error page body in the "Match not found" style of States.dc.html. --}}
<div class="flex grow flex-col px-4 py-6 lg:px-12 lg:py-8">
    <div class="flex grow flex-col items-center justify-center gap-3.5 rounded-lg px-5 py-12 text-center shadow-ring-hairline">
        <span class="text-xs text-ink-3">{{ __('Error :code', ['code' => $code]) }}</span>
        <h1 class="m-0 font-display text-[22px] leading-[1.25] font-bold">{{ $heading }}</h1>
        <p class="m-0 max-w-[52ch] text-[13px] leading-normal text-ink-2">{{ $text }}</p>
        <div class="mt-1 flex flex-wrap justify-center gap-3">{{ $slot }}</div>
    </div>
</div>
