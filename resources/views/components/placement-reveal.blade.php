{{--
    Placement reveal (P10): after a player's fifth rated result in a ladder
    the rank they placed into is revealed once, on the next page they open.
    App\Support\Engagement\Placements::claim() marks it shown while this
    renders, so a reload or a second tab does not show it again.

    The cube starts as the dashed "provisional" outline of the rank badge,
    fills with the tier colour and pops (house motion: --ease-pop, the block
    glow); the name and the rating follow. prefers-reduced-motion switches
    all of it off (resources/css/app.css), the dialog stays.
--}}
@php
    $reveal = auth()->check() ? app(App\Support\Engagement\Placements::class)->claim(auth()->user()) : null;
@endphp

@if ($reveal)
    @php
        [$tier, $level] = $reveal->badge();
        $games = app(App\Games\GameRegistry::class);
        $ladder = __($games->find($reveal->game)?->name() ?? $reveal->game).' · '.__($games->mode($reveal->game, $reveal->mode)?->name ?? $reveal->mode);
        $names = ['bronze' => __('Bronze'), 'silver' => __('Silver'), 'gold' => __('Gold'), 'platinum' => __('Platinum'), 'diamond' => __('Diamond'), 'champion' => __('Champion'), 'grand-champion' => __('Grand Champion')];
        $rank = ($names[$tier] ?? $reveal->tier).' '.['I', 'II', 'III'][max(1, min(3, $level)) - 1];
    @endphp
    <div x-data="{ open: true }" x-show="open" x-on:keydown.escape.window="open = false"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
         role="dialog" aria-modal="true" aria-labelledby="placement-h" data-test="placement-reveal">
        <div class="pr-card flex w-full max-w-sm flex-col items-center gap-4 rounded-lg bg-card px-6 py-8 text-center shadow-ring" style="--tier: var(--color-rank-{{ $tier }})" x-on:click.outside="open = false">
            <span class="flex flex-col gap-1">
                <span class="text-xs tracking-[0.12em] text-ink-2 uppercase">{{ __('Placement complete') }}</span>
                <span class="text-[13px] font-bold text-ink" data-test="placement-ladder">{{ $ladder }}</span>
            </span>
            <span class="pr-cube" aria-hidden="true">
                <svg viewBox="0 0 14 14" width="96" height="96"><path class="pr-shell" d="M7 1.5 12.5 4.7v4.6L7 12.5 1.5 9.3V4.7z"></path><path d="M7 1 13 4.5 7 8 1 4.5z" fill="#FFFFFF" fill-opacity=".35"></path></svg>
            </span>
            <h2 id="placement-h" class="pr-name m-0 font-display text-2xl font-extrabold" style="color: var(--tier)" data-test="placement-rank">{{ $rank }}</h2>
            <p class="pr-rating m-0 text-[13px] text-ink-2">{{ __('Five rated results played. You placed with a rating of :rating.', ['rating' => $reveal->rating]) }}</p>
            <x-button class="pr-rating" x-on:click="open = false" data-test="placement-close">{{ __('Nice') }}</x-button>
        </div>
    </div>
@endif
