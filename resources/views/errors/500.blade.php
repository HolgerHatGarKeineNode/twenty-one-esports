<x-layouts::minimal :title="__('Something broke on our side')">
    <x-error-panel code="500" :heading="__('Something broke on our side')" :text="__('This page hit an error. Your games and results are safe. Try again in a moment.')">
        <x-button href="{{ url()->current() }}">{{ __('Try again') }}</x-button>
        <x-button variant="quiet" href="/">{{ __('Go to Home') }}</x-button>
    </x-error-panel>
</x-layouts::minimal>
