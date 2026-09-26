@props([
    'id',
    'label',
    'exclude' => [],
    'placeholder' => null,
    'submitOnPick' => false,
    'allowNpub' => false,
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
    `allow-npub`: bind a nullable string instead; it holds the hex pubkey, and
    a fully valid npub or hex key without an account here can be picked too
    (for roles and invites granted to a key). Typed names still never count.
--}}
<div x-data="playerPicker({ url: @js(route('players.search')), submitOnPick: @js((bool) $submitOnPick), allowNpub: @js((bool) $allowNpub), labels: @js([
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
    <div x-show="showChip" x-cloak data-test="picker-chip"
         class="flex h-11 min-w-0 items-center gap-2 rounded-md bg-ground pr-1 pl-2.5 text-[13px] shadow-ring">
        <template x-if="chosen">
            <img x-bind:src="chosen.avatar" alt="" width="24" height="24" referrerpolicy="no-referrer"
                 x-on:error="if ($el.src !== chosen.fallback) $el.src = chosen.fallback"
                 class="block size-6 shrink-0 rounded-full bg-raised object-cover">
        </template>
        <span class="max-w-[60%] shrink-0 truncate text-ink" x-text="chosen?.name"></span>
        <span x-show="chosen?.npub" class="shrink-0 font-mono text-xs text-ink-3" x-text="chosen?.npub"></span>
        <span x-show="chosen?.unregistered" class="min-w-0 truncate text-xs text-btc" data-test="picker-unregistered">{{ __('not registered yet') }}</span>
        <span class="grow"></span>
        <button type="button" x-on:click="clear()" aria-label="{{ __('Clear') }}" data-test="picker-clear"
                class="flex size-9 shrink-0 cursor-pointer items-center justify-center text-ink-2 hover:text-ink"><x-icon name="close" :size="14" /></button>
    </div>
    <input id="{{ $id }}" x-ref="input" x-show="! showChip" type="text" role="combobox" autocomplete="off" spellcheck="false"
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
        <template x-for="(player, index) in results" x-bind:key="player.id ?? player.key">
            <li role="option" x-bind:id="optionId(index)" x-bind:aria-selected="index === active ? 'true' : 'false'"
                x-on:mousedown.prevent="choose(player)" x-on:mousemove="active = index"
                x-bind:class="index === active ? 'bg-raised shadow-[inset_2px_0_0_#F7931A]' : ''" data-test="picker-option"
                class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-sm px-2.5 text-[13px]">
                <img x-bind:src="player.avatar" alt="" width="24" height="24" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                     x-on:error="if ($el.src !== player.fallback) $el.src = player.fallback"
                     class="block size-6 shrink-0 rounded-full bg-raised object-cover">
                <span class="min-w-0 grow truncate text-ink" x-text="player.name"></span>
                <span class="shrink-0 font-mono text-xs text-ink-3" x-text="player.npub"></span>
                <span x-show="player.unregistered" class="shrink-0 text-xs text-btc">{{ __('not registered yet') }}</span>
            </li>
        </template>
    </ul>
    <p x-show="open && message !== ''" x-cloak x-text="message" x-on:click="close()" data-test="picker-message"
       class="absolute top-full right-0 left-0 z-30 m-0 mt-1 rounded-md bg-well px-3 py-2.5 text-xs text-ink-2 shadow-ring"></p>
    <span class="sr-only" role="status" x-text="status"></span>
</div>
