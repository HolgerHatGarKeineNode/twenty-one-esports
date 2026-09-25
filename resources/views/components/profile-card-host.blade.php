{{--
    One per page (layouts/app): the player card as a popover on desktop and a
    bottom sheet on touch screens, filled from GET /players/{npub}/card by
    resources/js/profiles.js (`profileCardHost`). Names that open it carry
    `data-player-card="<npub>"` (see <x-player-link>).
--}}
<div x-data="profileCardHost" x-on:keydown.escape.window="close(true)">
    <div x-ref="pop" x-show="open && ! sheet" x-cloak role="dialog" :aria-label="label" data-test="profile-popover"
         x-on:mouseenter="keep()" x-on:mouseleave="leaveSoon()" x-on:focusout="focusOut($event)"
         :style="`left: ${x}px; top: ${y}px`"
         class="fixed z-50 w-[360px] max-w-[calc(100vw-32px)] overflow-hidden rounded-lg bg-card shadow-[0_16px_40px_rgba(0,0,0,.55),0_0_0_1px_#2A2A30]">
        <div x-html="open && ! sheet ? html : ''"></div>
    </div>

    <div x-show="open && sheet" x-cloak class="fixed inset-0 z-50" data-test="profile-sheet">
        <div aria-hidden="true" class="absolute inset-0 bg-[rgba(10,10,11,.72)]" x-on:click="close()"></div>
        <div x-ref="sheet" role="dialog" aria-modal="true" :aria-label="label" data-sheet
             x-on:touchstart.passive="dragStart($event)" x-on:touchmove.passive="dragMove($event)" x-on:touchend="dragEnd()"
             :style="`transform: translateY(${dragY}px)`"
             class="group/sheet absolute inset-x-0 bottom-0 max-h-[85svh] overflow-y-auto overscroll-contain rounded-t-2xl bg-card pb-[env(safe-area-inset-bottom)] shadow-[0_-12px_40px_rgba(0,0,0,.6),inset_0_1px_0_#2A2A30]">
            <span aria-hidden="true" class="absolute top-2 left-1/2 z-10 -ml-5 h-1 w-10 rounded-sm bg-edge"></span>
            <button type="button" x-on:click="close()" aria-label="{{ __('Close profile') }}"
                    class="absolute top-2 right-2 z-10 flex size-11 cursor-pointer items-center justify-center rounded-full bg-[rgba(10,10,11,.72)] text-ink"><x-icon name="close" :size="18" /></button>
            <div x-html="open && sheet ? html : ''"></div>
        </div>
    </div>
</div>
