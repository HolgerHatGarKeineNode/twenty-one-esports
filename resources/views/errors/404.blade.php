<x-layouts::app :title="__('Page not found')">
    <x-error-panel code="404" :heading="__('Page not found')" :text="__('Nothing lives at this address. Check the link, or start again from Home.')">
        <x-button :href="route('home')">{{ __('Go to Home') }}</x-button>
        <x-button variant="quiet" :href="route('matches.index')">{{ __('Latest matches') }}</x-button>
    </x-error-panel>
</x-layouts::app>
