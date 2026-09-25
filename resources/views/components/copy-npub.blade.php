@props(['npub', 'name' => null, 'variant' => 'icon', 'label' => null])

{{--
    Copies a player's npub so they can be found in any Nostr client (e.g. for
    a private DM).
    `icon` (default): a small button, its hit area is 44 px.
    `button`: the 44 px ghost button of the player card; with `label` it also
    shows text (the player page header shows the short npub).
--}}
<span x-data="{ copied: false }" {{ $attributes->class('relative inline-flex shrink-0') }}>
    <button type="button" data-test="copy-npub" data-npub="{{ $npub }}"
            aria-label="{{ $name ? __('Copy :name\'s npub', ['name' => $name]) : __('Copy npub') }}"
            title="{{ $variant === 'icon' ? $npub : __('Copy npub') }}"
            x-on:click.prevent="navigator.clipboard?.writeText(@js($npub)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
            @class([
                'relative inline-flex size-6 cursor-pointer items-center justify-center rounded-sm text-ink-3 after:absolute after:-inset-2.5 hover:bg-well hover:text-ink focus-visible:text-ink' => $variant === 'icon',
                'inline-flex h-11 min-w-11 cursor-pointer items-center justify-center gap-2 rounded-md border border-line bg-well px-3 text-xs text-ink-2 hover:bg-[#222228] hover:text-ink' => $variant === 'button',
            ])>
        <x-icon name="copy" :size="$variant === 'icon' ? 14 : 16" x-show="! copied" />
        <x-icon name="check" :size="$variant === 'icon' ? 14 : 16" x-show="copied" x-cloak class="text-btc" />
        @if ($label)<span class="whitespace-nowrap">{{ $label }}</span>@endif
    </button>
    <span role="status" class="sr-only" x-text="copied ? @js(__('npub copied')) : ''"></span>
</span>
