@props(['players' => [], 'heading', 'note' => null])

{{--
    P57: rated play was refused, or will be, because two players do not
    list each other as opponents. Says so, and puts the fix next to it: per
    player the signed add ("Add :name as opponent"), or "Accept :name's
    request" when they list you already (livewire:opponent-button, inline).
    The other player gets the request notification from the add itself. The
    slot carries the page's casual alternative. `note` replaces the general
    explanation (the rated queue names nobody, P57 review).
--}}
<div {{ $attributes->class('flex flex-col gap-3 rounded-md bg-toast-challenge p-4 shadow-ring-btc') }} data-test="needs-mutual">
    <p class="m-0 flex max-w-[68ch] items-start gap-2 text-[13px] leading-normal font-bold text-ink" data-test="needs-mutual-heading">
        <x-icon name="shield-check" :size="16" class="mt-0.5 shrink-0 text-btc" /><span class="min-w-0 [overflow-wrap:anywhere]">{{ $heading }}</span>
    </p>
    <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2">{{ $note ?? __('A rated game needs both of you to add the other as an opponent. The other player gets a request and accepts it on their Opponents page.') }}</p>
    @foreach ($players as $player)
        <livewire:opponent-button :player="$player" :inline="true" :key="'needs-mutual-'.$player->id" />
    @endforeach
    @if (! $slot->isEmpty())
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</div>
