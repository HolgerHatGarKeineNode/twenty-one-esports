{{--
    "Show area", Go's switch of its own (R11): it marks each side's area on the board. On while a pass waits for its
    answer (the board shows what would be counted), so it cannot be switched then.
--}}
<button type="button" class="inline-flex h-11 shrink-0 cursor-pointer items-center gap-2 rounded-md border px-3 text-[13px] whitespace-nowrap text-ink disabled:cursor-default"
        :class="areaShown ? 'border-ink-2 bg-raised' : 'btn-w border-line bg-well'"
        x-on:click="toggleArea()" :aria-pressed="areaShown.toString()" :disabled="confirmPass && canMove" data-test="toggle-area">
    <span class="grid size-4 shrink-0 place-items-center rounded-[2px] border" :class="areaShown ? 'border-ink bg-ink' : 'border-edge'" aria-hidden="true">
        <svg viewBox="0 0 12 12" class="size-3" x-show="areaShown"><path d="M2.5 6.2 5 8.6l4.5-5" fill="none" stroke="#0A0A0B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </span>{{ __('Show area') }}
</button>
