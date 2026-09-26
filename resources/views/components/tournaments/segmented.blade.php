@props(['options', 'current', 'method', 'label' => null, 'labelledby' => null, 'grow' => true])

{{--
    Segmented radio group of the format chooser (AdminTournamentCreate.dc.html):
    one button per option, the chosen one raised in orange. `options` is a
    list of [value, label]; a click calls the Livewire `method` with the value.
--}}
<span role="radiogroup" @if ($labelledby) aria-labelledby="{{ $labelledby }}" @elseif ($label) aria-label="{{ $label }}" @endif
      {{ $attributes->class('flex flex-wrap gap-1 rounded-md border border-edge bg-ground p-[3px]') }}>
    @foreach ($options as [$value, $text])
        <button type="button" role="radio" aria-checked="{{ $current === $value ? 'true' : 'false' }}" wire:key="seg-{{ $method }}-{{ $value }}"
                wire:click="{{ $method }}({{ \Illuminate\Support\Js::from($value) }})"
                @class(['min-h-[38px] cursor-pointer rounded-sm border-0 px-3 text-[13px] font-bold whitespace-nowrap', 'grow' => $grow,
                    'bg-raised text-btc' => $current === $value, 'bg-transparent text-ink-2 hover:text-ink' => $current !== $value])>{{ $text }}</button>
    @endforeach
</span>
