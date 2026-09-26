@props(['value', 'decrement', 'increment', 'less', 'more', 'model' => null, 'id' => null])

{{--
    − value + stepper of the format chooser. With `model` the middle is a
    number input bound live to that property, else a read-only value.
--}}
<span {{ $attributes->class('inline-flex shrink-0 items-stretch overflow-hidden rounded-md border border-edge bg-ground') }}>
    <button type="button" aria-label="{{ $less }}" wire:click="{{ $decrement }}" class="btn-w flex size-11 cursor-pointer items-center justify-center border-0 bg-transparent text-lg text-ink">−</button>
    @if ($model)
        <input id="{{ $id }}" type="text" inputmode="numeric" wire:model.live.debounce.400ms="{{ $model }}"
               class="h-11 w-16 border-x border-y-0 border-line bg-ground text-center text-[15px] font-bold text-ink" data-test="{{ $id }}">
    @else
        <span aria-live="polite" class="flex h-11 w-12 items-center justify-center border-x border-line text-[15px] font-bold">{{ $value }}</span>
    @endif
    <button type="button" aria-label="{{ $more }}" wire:click="{{ $increment }}" class="btn-w flex size-11 cursor-pointer items-center justify-center border-0 bg-transparent text-lg text-ink">+</button>
</span>
