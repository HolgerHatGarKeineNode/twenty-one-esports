{{--
    Placeholder for a planned route (screens-v1.md). `page` is a translation key,
    `section` the active main-navigation item. The real page replaces this in its phase.
--}}
<x-layouts::app :title="__($page)" :section="$section">
    <div class="flex flex-col gap-5 px-4 pb-6 lg:px-12 lg:pb-8">
        <h1 class="m-0 font-display text-2xl font-bold lg:text-[28px]">{{ __($page) }}</h1>

        <div class="rounded-lg shadow-ring-hairline">
            <x-empty-state class="px-5 py-8 lg:px-10 lg:py-10"
                           :heading="__('Coming soon')"
                           :text="__('This page is still being built. Until it opens, Home shows every game across the league as it happens.')">
                <x-button :href="route('home')">{{ __('Go to Home') }}</x-button>
            </x-empty-state>
        </div>
    </div>
</x-layouts::app>
