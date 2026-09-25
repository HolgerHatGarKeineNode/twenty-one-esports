<x-layouts::minimal :title="__('Back in a few minutes')">
    <x-error-panel code="503" :heading="__('Back in a few minutes')" :text="__('TWENTY ONE is being updated. Your games and results are safe, and the league picks up where it stopped.')">
        <x-button href="{{ url()->current() }}">{{ __('Try again') }}</x-button>
    </x-error-panel>
</x-layouts::minimal>
