{{--
    The sign-up's ownership tick "I own <game> on one of these platforms",
    next to the confirm button (the box with the platforms is
    pages.tournaments.partials.own-copy). Bound to the component's `ownsGame`;
    the server refuses the entry without it. Nothing for a game played on the site.
    $tournament.
--}}
@if (app(\App\Games\GameRegistry::class)->find($tournament->game) instanceof \App\Games\Contracts\PlayedOnOwnCopy)
    <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-md bg-card px-3 text-[13px] font-bold shadow-ring has-[:checked]:bg-btc-chip has-[:checked]:shadow-[inset_0_0_0_1px_var(--color-btc)]">
        <input type="checkbox" class="size-5 shrink-0 accent-[#F7931A]" wire:model="ownsGame" data-test="owns-game">
        <span>{{ __('I own :game on one of these platforms', ['game' => \App\Support\GameNames::game($tournament->game)]) }}</span>
    </label>
@endif
