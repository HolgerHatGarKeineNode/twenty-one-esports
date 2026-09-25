@props(['npub', 'name' => null])

{{--
    Copies a player's npub so they can be found in any Nostr client (e.g. for
    a private DM). The visible button is small; its hit area is 44 px.
--}}
<span x-data="{ copied: false }" {{ $attributes->class('relative inline-flex shrink-0') }}>
    <button type="button" data-test="copy-npub" data-npub="{{ $npub }}"
            aria-label="{{ $name ? __('Copy :name\'s npub', ['name' => $name]) : __('Copy npub') }}"
            title="{{ $npub }}"
            x-on:click.prevent="navigator.clipboard?.writeText(@js($npub)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
            class="relative inline-flex size-6 cursor-pointer items-center justify-center rounded-sm text-ink-3 after:absolute after:-inset-2.5 hover:bg-well hover:text-ink focus-visible:text-ink">
        <x-icon name="copy" :size="14" x-show="! copied" />
        <x-icon name="check" :size="14" x-show="copied" x-cloak class="text-btc" />
    </button>
    <span role="status" class="sr-only" x-text="copied ? @js(__('npub copied')) : ''"></span>
</span>
