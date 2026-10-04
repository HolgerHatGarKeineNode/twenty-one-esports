@props(['options' => []])

{{--
    The emoji picker (P24), ported from einundzwanzig-group
    (resources/views/components/emoji-picker.blade.php): recently used, search,
    one tab per emoji group, and first a tab with the viewer's own NIP-30
    emoji. Data and logic: emojiPicker() in resources/js/emojiPicker.js.
    Picking calls insertEmoji(text, emojiTag, label) of the chat around it
    (Alpine scope chain; the panel is teleported, the scope goes with it).

    Custom emoji and Unicode emoji are two flat loops, never an x-if per tile
    (the original measured 148 ms for 171 tiles with two templates each).
--}}
@php
    $pick = "e.custom ? insertEmoji(':' + e.shortcode + ':', ['emoji', e.shortcode, e.url]) : insertEmoji(e.u, null, e.label); close()";
@endphp
<div x-data="emojiPicker(@js($options))" {{ $attributes->merge(['class' => 'flex w-[min(20.5rem,calc(100vw-2rem))] flex-col gap-2']) }} data-test="emoji-picker">
    <template x-if="recent.length">
        <div class="flex items-center gap-0.5 overflow-x-auto" role="group" aria-label="{{ __('Recently used') }}" data-test="emoji-recent">
            <template x-for="e in recent.filter((x) => x.custom)" :key="':' + e.shortcode">
                <button type="button" x-on:click="{{ $pick }}" :aria-label="pickLabel(e)" :title="':' + e.shortcode + ':'"
                        class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-control hover:bg-row-hover">
                    <img :src="e.url" :alt="':' + e.shortcode + ':'" width="24" height="24" referrerpolicy="no-referrer" loading="lazy" class="size-6 object-contain">
                </button>
            </template>
            <template x-for="e in recent.filter((x) => !x.custom)" :key="e.u">
                <button type="button" x-on:click="{{ $pick }}" :aria-label="pickLabel(e)" :title="e.label"
                        class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-control text-xl leading-none hover:bg-row-hover">
                    <span x-text="e.u"></span>
                </button>
            </template>
        </div>
    </template>

    <label class="relative block">
        <span class="sr-only">{{ __('Search emoji') }}</span>
        <x-icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-3" />
        <input x-model.debounce.150ms="search" type="search" placeholder="{{ __('Search emoji') }}" autocomplete="off" data-test="emoji-search"
               class="h-10 w-full rounded-control border border-edge bg-ground pr-3 pl-9 text-[13px] text-ink placeholder:text-ink-3">
    </label>

    <div x-show="ready && ! search.trim()" class="-mx-0.5 flex gap-0.5 overflow-x-auto px-0.5" role="tablist" aria-label="{{ __('Emoji groups') }}">
        <template x-for="tab in tabs" :key="tab.key">
            <button type="button" role="tab" x-on:click="activeTab = tab.key" :aria-selected="(activeTab === tab.key).toString()" :title="tab.name" :aria-label="tab.name"
                    :data-test="'emoji-tab-' + tab.key"
                    class="relative flex h-9 w-8 shrink-0 cursor-pointer items-center justify-center rounded-control text-lg leading-none"
                    :class="activeTab === tab.key ? 'bg-well' : 'opacity-70 hover:opacity-100'">
                <span x-text="tab.icon"></span>
                <span x-show="activeTab === tab.key" class="absolute inset-x-1.5 bottom-0 h-0.5 bg-btc"></span>
            </button>
        </template>
    </div>

    <div class="grid max-h-52 grid-cols-8 gap-0.5 overflow-y-auto overscroll-contain" data-test="emoji-grid">
        <template x-for="e in customResults" :key="':' + e.shortcode">
            <button type="button" x-on:click="{{ $pick }}" :aria-label="pickLabel(e)" :title="':' + e.shortcode + ':'" data-custom
                    class="flex aspect-square cursor-pointer items-center justify-center rounded-control hover:bg-row-hover">
                <img :src="e.url" :alt="':' + e.shortcode + ':'" width="24" height="24" referrerpolicy="no-referrer" loading="lazy" class="size-6 object-contain">
            </button>
        </template>
        <template x-for="e in standardResults" :key="e.u">
            <button type="button" x-on:click="{{ $pick }}" :aria-label="pickLabel(e)" :title="e.label"
                    class="flex aspect-square cursor-pointer items-center justify-center rounded-control text-xl leading-none hover:bg-row-hover">
                <span x-text="e.u"></span>
            </button>
        </template>
    </div>

    <p x-show="! ready" class="m-0 py-6 text-center text-xs text-ink-3">{{ __('Loading emoji…') }}</p>
    <p x-show="ready && results.length === 0" x-cloak class="m-0 py-6 text-center text-xs text-ink-3"
       x-text="search.trim() ? @js(__('No emoji for “:query”')).replace(':query', search.trim()) : (activeTab === 'custom' && customTotal > 0 ? @js(__('Loading emoji…')) : '')"></p>
</div>
