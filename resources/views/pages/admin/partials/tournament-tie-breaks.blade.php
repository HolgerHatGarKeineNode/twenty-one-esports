{{--
    Up to three tie-breaks in order (Challonge's list; median Buchholz for
    Swiss only). `key` is the options key, `current` the chosen list, `labels`
    value => label of the tie-breaks this format offers.
--}}
<div class="grid gap-2 border-t border-hairline py-3 lg:grid-cols-[minmax(0,1fr)_200px] lg:gap-4">
    <span class="flex flex-col items-start gap-2">
        <span id="tb-{{ $key }}" class="text-[13px] font-bold">{{ __('Tie-breaks, in this order') }}</span>
        <ol class="m-0 flex w-full flex-col gap-1.5 pl-5 text-xs text-ink-2">
            @foreach ([0, 1, 2] as $index)
                <li wire:key="tb-{{ $key }}-{{ $index }}">
                    <select wire:model.live="options.{{ $key }}.{{ $index }}" aria-label="{{ __('Tie-break :number', ['number' => $index + 1]) }}"
                            class="h-11 w-full max-w-64 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink">
                        @foreach ($labels as $value => $label)
                            <option value="{{ $value }}" @selected(($current[$index] ?? null) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </li>
            @endforeach
        </ol>
    </span>
    <span class="text-xs leading-normal text-ink-2">{{ __('Used when two have the same points, in this order. Buchholz: the stronger your opponents were, the higher you rank.') }}</span>
</div>
