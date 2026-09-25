{{--
    Styleguide: every token and shell component in one place. Local and testing
    environments only (routes/web.php answers 404 elsewhere).
--}}
@php
    use App\Support\SampleData;

    $palette = [
        __('Surfaces') => [
            ['ground', '#0A0A0B', __('Page ground, inner fields')],
            ['bar', '#111114', __('Header bar')],
            ['card', '#121215', __('Cards')],
            ['row-hover', '#17171B', __('Row hover')],
            ['raised', '#1E1E24', __('Active nav, bar tracks')],
            ['well', '#1A1A1E', __('Quiet button fill')],
        ],
        __('Lines') => [
            ['hairline', '#1E1E22', __('Section rules')],
            ['line', '#2A2A30', __('Hairline borders')],
            ['dash', '#3A3A42', __('Empty slots')],
            ['edge', '#63636A', __('Inputs and outlined buttons (3:1)')],
        ],
        __('Text') => [
            ['ink', '#FFFFFF', __('Primary text')],
            ['ink-2', '#ADADB0', __('Secondary text')],
            ['ink-3', '#8B8B90', __('Muted text')],
        ],
        __('Brand and signals') => [
            ['btc', '#F7931A', __('Brand orange, primary action')],
            ['btc-hi', '#F9B25F', __('Hover, focus ring')],
            ['btc-deep', '#B9640A', __('Member badge edge')],
            ['on-btc', '#17120A', __('Text and icons on orange')],
            ['proof', '#A78BFA', __('Proof blocks only')],
            ['bolt', '#FACC15', __('Lightning icon only')],
            ['win', '#4ADE80', __('Win, positive (with text)')],
            ['loss', '#F87171', __('Loss, danger (with text)')],
        ],
        __('Ranks') => [
            ['rank-bronze', '#E5A06B', __('Bronze, brown')],
            ['rank-silver', '#ADADB0', __('Silver, grey')],
            ['rank-gold', '#FACC15', __('Gold')],
            ['rank-platinum', '#5EEAD4', __('Platinum, teal')],
            ['rank-diamond', '#60A5FA', __('Diamond, blue')],
            ['rank-champion', '#E879F9', __('Champion, fuchsia')],
            ['rank-grand-champion', '#F43F5E', __('Grand Champion, crimson with glow')],
            ['rank-provisional', '#ADADB0', __('Provisional, dashed outline')],
        ],
    ];

    $toasts = [
        ['challenge', __('New challenge from Block 21'), __('to your 3v3, answer by 18:40'), __('just now'), __('View')],
        ['confirmed', __('Mempool Maniacs accepted your result'), __('match #103, now 2 of 3 checks'), __(':n min ago', ['n' => 1]), __('Open match')],
        ['success', __('Elo +12'), __('Laser Eyes 3v3 now 1296'), __(':n min ago', ['n' => 1]), __('Open ladder')],
    ];
@endphp

<x-layouts::app :title="__('Styleguide')">
    <div class="flex flex-col gap-10 px-4 py-6 lg:px-12 lg:py-8">
        <div class="flex flex-col gap-2">
            <h1 class="m-0 font-display text-[28px] font-bold">{{ __('Styleguide') }}</h1>
            <p class="m-0 max-w-[70ch] text-[13px] leading-normal text-ink-2">{{ __('Tokens and shell components of TWENTY ONE, taken from the approved screens. Only visible in local and testing environments.') }}</p>
        </div>

        <section aria-labelledby="sg-colour" class="flex flex-col gap-4">
            <h2 id="sg-colour" class="m-0 font-display text-xl font-bold">{{ __('Colour') }}</h2>
            @foreach ($palette as $group => $swatches)
                <div class="flex flex-col gap-2">
                    <h3 class="m-0 text-[13px] font-bold">{{ $group }}</h3>
                    <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($swatches as [$token, $hex, $role])
                            <li class="flex items-center gap-3 rounded-lg bg-card p-3">
                                <span class="size-10 shrink-0 rounded-md shadow-ring" style="background: {{ $hex }}" aria-hidden="true"></span>
                                <span class="flex min-w-0 flex-col gap-0.5 text-xs">
                                    <b class="text-[13px]">{{ $token }}</b>
                                    <span class="text-ink-2">{{ $hex }}</span>
                                    <span class="truncate text-ink-3">{{ $role }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </section>

        <section aria-labelledby="sg-type" class="flex flex-col gap-4">
            <h2 id="sg-type" class="m-0 font-display text-xl font-bold">{{ __('Type') }}</h2>
            <div class="flex flex-col gap-4 rounded-lg bg-card p-5">
                <span class="font-display text-[30px] leading-[1.1] font-extrabold lg:text-5xl">{{ __('Mine your first block.') }}</span>
                <span class="text-xs text-ink-3">Unbounded 800, 48 / 30 px, {{ __('hero') }}</span>
                <span class="font-display text-2xl leading-[1.2] font-bold">{{ __('Your first block in 2 minutes') }}</span>
                <span class="text-xs text-ink-3">Unbounded 700, 24 / 20 px, {{ __('card headline') }}</span>
                <span class="font-display text-2xl font-bold">#212</span>
                <span class="text-xs text-ink-3">Unbounded 700, {{ __('numbers as display') }}</span>
                <span class="text-[15px] font-bold">{{ __('Next tournament') }}</span>
                <span class="text-xs text-ink-3">JetBrains Mono 700, 15 px, {{ __('card title') }}</span>
                <p class="m-0 max-w-[60ch] text-[13px] leading-[1.6] text-ink-2">{{ __('Clans play 3v3, 2v2 and 1v1 series in your own Rocket League client, then enter the goals here. Both captains confirm the result.') }}</p>
                <span class="text-xs text-ink-3">JetBrains Mono 400, 13 px / 1.6, {{ __('body') }}</span>
            </div>
        </section>

        <section aria-labelledby="sg-shape" class="flex flex-col gap-4">
            <h2 id="sg-shape" class="m-0 font-display text-xl font-bold">{{ __('Radius, depth, motion') }}</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="flex flex-col gap-2 rounded-lg bg-card p-4 text-xs"><b class="text-[13px]">8 px</b><span class="text-ink-2">{{ __('Cards, buttons in the shell') }}</span></div>
                <div class="flex flex-col gap-2 rounded-md bg-card p-4 text-xs"><b class="text-[13px]">6 px</b><span class="text-ink-2">{{ __('Controls, toasts') }}</span></div>
                <div class="flex flex-col gap-2 rounded-lg bg-card p-4 text-xs shadow-ring-btc"><b class="text-[13px]">ring-btc</b><span class="text-ink-2">{{ __('Highlighted card') }}</span></div>
                <div class="flex flex-col gap-2 rounded-lg bg-card p-4 text-xs animate-breathe"><b class="text-[13px]">glow-btc</b><span class="text-ink-2">{{ __('Yours, breathing (off with reduced motion)') }}</span></div>
            </div>
            <p class="m-0 text-xs text-ink-3">{{ __('Motion: ease-snap cubic-bezier(.2,.8,.2,1), ease-pop cubic-bezier(.2,.9,.3,1.1); 150 to 300 ms for answers to a click. Everything stops with prefers-reduced-motion.') }}</p>
        </section>

        <section aria-labelledby="sg-controls" class="flex flex-col gap-4">
            <h2 id="sg-controls" class="m-0 font-display text-xl font-bold">{{ __('Buttons and badges') }}</h2>
            <div class="flex flex-wrap items-center gap-3">
                <x-button>{{ __('Send the first challenge') }}</x-button>
                <x-button variant="secondary">{{ __('Challenge a friend') }}</x-button>
                <x-button variant="quiet">{{ __('Latest matches') }}</x-button>
                <x-button icon="shield-check">{{ __('Confirm final score') }}</x-button>
                <x-member-badge />
                <x-member-badge long />
                <span class="bs-tag">{{ __('casual') }}</span>
                <span class="inline-flex h-5 items-center rounded-sm bg-btc-tint px-1 text-[10px] font-bold text-btc">LSR</span>
            </div>
        </section>

        <section aria-labelledby="sg-ranks" class="flex flex-col gap-4">
            <h2 id="sg-ranks" class="m-0 font-display text-xl font-bold">{{ __('Ranks') }}</h2>
            <p class="m-0 max-w-[70ch] text-[13px] leading-normal text-ink-2">{{ __('Seven tiers with three levels each, Grand Champion on top. The label always names the rank. Provisional until 5 rated results.') }}</p>
            <div class="flex flex-col gap-3 rounded-lg bg-card p-5">
                @foreach (['bronze', 'silver', 'gold', 'platinum', 'diamond', 'champion', 'grand-champion'] as $tier)
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2" data-rank-row="{{ $tier }}">
                        @foreach ([1, 2, 3] as $level)
                            <x-rank-badge :tier="$tier" :level="$level" class="min-w-44" />
                        @endforeach
                    </div>
                @endforeach
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-hairline pt-3">
                    <x-rank-badge tier="provisional" />
                    <x-rank-badge tier="diamond" :level="2" size="sm" />
                    <span class="text-xs text-ink-3">{{ __('small size, for tables') }}</span>
                </div>
            </div>
        </section>

        <section aria-labelledby="sg-strip" class="flex flex-col gap-4">
            <h2 id="sg-strip" class="m-0 font-display text-xl font-bold">{{ __('Block strip') }}</h2>
            <div class="overflow-hidden rounded-lg shadow-ring-hairline">
                <x-block-strip size="md" :finished="SampleData::finishedBlocks()" :running="SampleData::runningBlocks()" class="pb-6" />
            </div>
            <div class="max-w-[440px] overflow-hidden rounded-lg shadow-ring-hairline">
                <x-block-strip size="sm" focus="chess" :finished="SampleData::finishedBlocks()" :running="SampleData::runningBlocks()" :legend="false" />
            </div>
        </section>

        <section aria-labelledby="sg-states" class="flex flex-col gap-4">
            <h2 id="sg-states" class="m-0 font-display text-xl font-bold">{{ __('States') }}</h2>
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:gap-8">
                <div class="rounded-lg p-6 shadow-ring-hairline lg:px-10">
                    <x-empty-state :heading="__('No rated series in 1v1 yet')" :text="__('The first confirmed match opens the table. Two clans already have a 1v1 lineup; any series between them gets it going.')">
                        <x-button>{{ __('Send the first challenge') }}</x-button>
                        <x-button variant="quiet">{{ __('Set up a 1v1 lineup') }}</x-button>
                    </x-empty-state>
                </div>
                <div class="overflow-hidden rounded-lg shadow-ring-hairline">
                    <x-skeleton-table />
                </div>
                <div class="flex h-[276px] rounded-lg shadow-ring-hairline">
                    <x-loading-screen :fullscreen="false" class="grow" />
                </div>
                <div class="flex flex-col justify-center gap-3 rounded-lg p-6 shadow-ring-hairline">
                    <h3 class="m-0 text-[13px] font-bold">{{ __('Toasts') }}</h3>
                    <p class="m-0 text-xs text-ink-2">{{ __('Raised with a toast window event; they stack top right under the header.') }}</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach ($toasts as [$tone, $title, $text, $time, $action])
                            <x-button variant="secondary" data-toast="{{ $tone }}"
                                      x-data x-on:click="$dispatch('toast', {{ \Illuminate\Support\Js::from(['tone' => $tone, 'title' => $title, 'text' => $text, 'time' => $time, 'action' => ['label' => $action, 'href' => route('matches.index')]]) }})">
                                {{ __('Show :tone toast', ['tone' => $tone]) }}
                            </x-button>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
    </div>
</x-layouts::app>
