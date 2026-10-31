@props(['banner' => null])

{{--
    A tournament game's whole banner on a phone, opened from its one-line forms (DerCaddy, 2026-10-15: "Wäre es möglich,
    dass sich das Banner mit Klick darauf vergrößert? Und sobald man wieder woanders klickt, wird es wieder klein"): the
    title row's line on a board game's page (game-banner-row) and the shell header's chip on every tournament game's page
    dispatch `tournament-banner`, and this panel drops down under the header with what the line cuts off: the whole name,
    the round and the game of a duel or the standing of the legs, what is at stake, the desk and the tournament page.
    Over the page, not in it, so the board keeps its size; a tap anywhere else (the shade catches it, so it never reaches
    the board as a move), the close button or Escape folds it again, and the focus goes back to the line or chip.
    `tournament-banner-state` tells the openers, for their aria-expanded. Rendered once per page by game-banner. The focus
    goes back to the opener, or to the other one when it stepped aside meanwhile (the header's chip gives way once the
    line is in view again, on a page too short to scroll it away).
--}}
@if ($banner)
    @php($desk = isset($banner['tournamentId']) && ($deskTournament = \App\Models\Tournament::query()->find($banner['tournamentId'])) !== null ? \App\Support\Tournaments\TournamentDesk::for($deskTournament, auth()->user()) : null)
    <div x-data="{ open: false, opener: null, close(back = true) { this.open = false; if (! back) return; const to = this.opener?.checkVisibility() ? this.opener : [...document.querySelectorAll('[aria-controls=tournament-banner-sheet]')].find((el) => el.checkVisibility()); to?.focus({ preventScroll: true }); } }" class="lg:hidden"
         x-on:tournament-banner.window="open = ! open; opener = $event.detail?.opener ?? null; open && $nextTick(() => $refs.close.focus({ preventScroll: true }))"
         x-on:keydown.escape.window="open && close()"
         x-effect="window.dispatchEvent(new CustomEvent('tournament-banner-state', { detail: open }))" data-test="banner-sheet-root">
        <div x-show="open" x-cloak class="fixed inset-0 z-[60] bg-[rgba(10,10,11,.6)]" x-on:click="close(false)" aria-hidden="true" data-test="banner-sheet-shade"></div>
        <section id="tournament-banner-sheet" x-show="open" x-cloak role="dialog" aria-modal="false" aria-labelledby="banner-sheet-name"
                 class="fixed inset-x-4 top-[calc(var(--shell-h)+8px)] z-[61] flex max-h-[calc(100svh-var(--shell-h)-24px)] flex-col gap-3 overflow-y-auto rounded-lg bg-btc-tint p-4 shadow-[inset_0_0_0_2px_var(--color-btc),0_16px_32px_rgba(10,10,11,.8)]"
                 data-test="banner-sheet">
            <div class="flex items-start gap-3">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-btc text-on-btc" aria-hidden="true"><x-icon name="trophy" :size="22" /></span>
                <span class="flex min-w-0 grow flex-col gap-1">
                    <span class="text-xs font-bold text-ink-2">{{ $banner['cup'] ? __('Cup game') : __('Tournament game') }}</span>
                    <span id="banner-sheet-name" class="font-display text-lg leading-tight font-extrabold [overflow-wrap:anywhere]" data-test="banner-sheet-name">{{ $banner['tournament'] }}</span>
                    {{-- One run of text: a long standing wraps between words, never leaving the dot alone at a line's end. --}}
                    <span class="text-sm font-bold text-btc-hi"><span data-test="banner-sheet-round">{{ $banner['round'] }}</span>@if ($banner['step']) · <span data-test="banner-sheet-step">{{ $banner['step'] }}</span>@endif</span>
                    <span class="text-[13px] font-bold text-ink" data-test="banner-sheet-stake">{{ $banner['stake'] }}</span>
                </span>
                <button type="button" x-ref="close" x-on:click="close()" class="-mt-2 -mr-2 flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md text-ink-2 hover:text-ink"
                        aria-label="{{ __('Close') }}" data-test="banner-sheet-close"><x-icon name="close" :size="18" /></button>
            </div>
            <x-tournaments.game-switch :games="$banner['games'] ?? []" size="lg" />
            <div class="flex flex-wrap gap-2">
                @if ($desk)
                    <x-tournaments.desk-button :desk="$desk" class="grow" />
                @endif
                <x-button :href="$banner['url']" icon="trophy" class="grow" data-test="banner-sheet-link">{{ __('Tournament page') }}</x-button>
            </div>
        </section>
    </div>
@endif
