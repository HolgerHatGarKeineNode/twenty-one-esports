@props([
    'id',
    'label',
    'exclude' => [],
    'placeholder' => null,
    'submitOnPick' => false,
    'labelClass' => 'text-xs text-ink-2',
    'inputClass' => 'h-11 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink',
])

{{--
    Picks one existing player by typing (resources/js/playerPicker.js): name,
    NIP-05 or npub, suggestions from route('players.search'). Bind it with
    wire:model to a nullable int property; it holds the player's id, never the
    typed text, so the host validates "picked or not" and nothing else.
    `exclude`: user ids never suggested (the organizer, those already added).
    `submit-on-pick`: choosing a player submits the surrounding form.
--}}
<div x-data="playerPicker({ url: @js(route('players.search')), submitOnPick: @js((bool) $submitOnPick), labels: @js([
        'none' => __('No player found.'),
        'found' => __('Suggestions: :count'),
        'failed' => __('The search did not work. Try again.'),
        'tooMany' => __('Too many searches. Wait a moment.'),
     ]) })"
     x-modelable="picked"
     x-on:click.outside="close()"
     data-picker-id="{{ $id }}"
     data-exclude="{{ json_encode(array_values(array_map('intval', $exclude))) }}"
     data-test="player-picker"
     {{ $attributes->class('relative flex min-w-0 flex-col gap-1.5') }}>
    <label for="{{ $id }}" class="{{ $labelClass }}">{{ $label }}</label>
    <input id="{{ $id }}" type="text" role="combobox" autocomplete="off" spellcheck="false"
           aria-autocomplete="list" aria-controls="{{ $id }}-list" aria-expanded="false"
           x-bind:aria-expanded="open && results.length > 0 ? 'true' : 'false'"
           x-bind:aria-activedescendant="active >= 0 ? optionId(active) : null"
           x-model="query" x-on:input="typed()" x-on:input.debounce.250ms="search()"
           x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)"
           x-on:keydown.enter="enter($event)" x-on:keydown.escape="close()"
           placeholder="{{ $placeholder ?? __('Name, NIP-05 or npub') }}"
           class="{{ $inputClass }}">
    <ul id="{{ $id }}-list" role="listbox" aria-label="{{ $label }}" x-show="open && results.length > 0" x-cloak data-test="picker-list"
        class="absolute top-full right-0 left-0 z-30 m-0 mt-1 flex max-h-80 list-none flex-col overflow-y-auto rounded-md bg-well p-1 shadow-ring">
        <template x-for="(player, index) in results" x-bind:key="player.id">
            <li role="option" x-bind:id="optionId(index)" x-bind:aria-selected="index === active ? 'true' : 'false'"
                x-on:mousedown.prevent="choose(player)" x-on:mousemove="active = index"
                x-bind:class="index === active ? 'bg-raised shadow-[inset_2px_0_0_#F7931A]' : ''" data-test="picker-option"
                class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-sm px-2.5 text-[13px]">
                <img x-bind:src="player.avatar" alt="" width="24" height="24" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                     x-on:error="if ($el.src !== player.fallback) $el.src = player.fallback"
                     class="block size-6 shrink-0 rounded-full bg-raised object-cover">
                <span class="min-w-0 grow truncate text-ink" x-text="player.name"></span>
                <span class="shrink-0 font-mono text-xs text-ink-3" x-text="player.npub"></span>
            </li>
        </template>
    </ul>
    <p x-show="open && message !== ''" x-cloak x-text="message" data-test="picker-message"
       class="absolute top-full right-0 left-0 z-30 m-0 mt-1 rounded-md bg-well px-3 py-2.5 text-xs text-ink-2 shadow-ring"></p>
    <span class="sr-only" role="status" x-text="status"></span>
</div>
