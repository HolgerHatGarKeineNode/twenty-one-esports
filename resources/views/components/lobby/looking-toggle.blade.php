@props(['on' => false])

{{--
    A lobby's "Looking to play" switch (components/lobby/online-now; Proof of Pong's lobby shows it in its card's head,
    P9). The state is the lobby's Alpine `looking` and `toggleLooking()` (chessLobby, boardLobby); `on` is the stored
    state for the first paint.
--}}
{{-- The page's own state (the lobby's `looking`): a Livewire render never touches it. --}}
<button type="button" wire:ignore x-on:click="toggleLooking()" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" x-bind:aria-checked="looking ? 'true' : 'false'" data-test="looking-toggle"
        class="flex h-11 cursor-pointer items-center gap-2.5 rounded-md border border-line bg-well px-3 text-[13px] text-ink">
    <span class="relative h-5 w-9 shrink-0 rounded-full transition-colors motion-reduce:transition-none" x-bind:class="looking ? 'bg-btc' : 'bg-raised shadow-ring'"><span class="absolute top-0.5 size-4 rounded-full bg-ink transition-all motion-reduce:transition-none" x-bind:class="looking ? 'left-[18px]' : 'left-0.5'"></span></span>
    {{ __('Looking to play') }}
    <b class="min-w-7 text-left" x-bind:class="looking ? 'text-win' : 'text-ink-2'" x-text="looking ? @js(__('On')) : @js(__('Off'))" data-test="looking-state">{{ $on ? __('On') : __('Off') }}</b>
</button>
