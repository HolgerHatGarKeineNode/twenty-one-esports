{{--
    Live notifications from Overlays.dc.html: a 4 px tone bar, icon, message,
    time, one action and a close button. Raised through the `toast` window event
    (see resources/js/toasts.js). Tones: challenge (orange), confirmed (shield),
    success (check). Every tone carries its own icon, never colour alone.
    A toast with a countdown (P5c, "Opponent found") shows it under the text,
    with "Stay here" to cancel the automatic jump into the game.
--}}
<div x-data="toastStack" x-on:toast.window="add($event.detail)"
     class="pointer-events-none fixed top-[68px] right-4 z-50 flex w-[min(560px,calc(100vw-32px))] flex-col gap-2 lg:top-[76px] lg:right-8">
    <div role="status" aria-live="polite" class="flex flex-col gap-2">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="pointer-events-auto grid min-h-13 animate-drop-in grid-cols-[4px_24px_minmax(0,1fr)_auto_44px] items-center gap-3.5 overflow-hidden rounded-md pr-1 text-[13px] sm:grid-cols-[4px_24px_minmax(0,1fr)_auto_auto_44px]"
                 x-bind:class="{
                     'bg-toast-challenge shadow-ring-btc': toast.tone === 'challenge',
                     'bg-toast-neutral shadow-ring': toast.tone === 'confirmed',
                     'bg-win-tint shadow-ring-win': toast.tone === 'success',
                 }"
                 x-on:mouseenter="hold(toast)" x-on:mouseleave="arm(toast)" x-on:focusin="hold(toast)" x-on:focusout="arm(toast)">
                <span class="h-full" x-bind:class="toast.tone === 'challenge' ? 'bg-btc' : 'bg-win'"></span>
                <span class="flex" x-bind:class="toast.tone === 'challenge' ? 'text-btc' : 'text-win'">
                    <x-icon name="bolt-toast" :size="16" x-show="toast.tone === 'challenge'" />
                    <x-icon name="shield-check" :size="18" x-show="toast.tone === 'confirmed'" />
                    <x-icon name="check" :size="16" x-show="toast.tone === 'success'" />
                </span>
                <span class="min-w-0 py-2"><b x-text="toast.title"></b> <span class="text-ink-2" x-text="toast.text"></span>
                    <template x-if="toast.countdown !== null">
                        <span class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                            <span role="timer" class="text-btc-hi" x-text="opening(toast)" data-test="toast-countdown"></span>
                            <button type="button" class="min-h-8 cursor-pointer text-ink underline decoration-ink-3 underline-offset-2 hover:text-ink" x-on:click="stay(toast)" x-text="toast.labels.stay" data-test="toast-stay"></button>
                        </span>
                    </template>
                </span>
                <span class="hidden text-xs text-ink-3 sm:inline" x-text="toast.time"></span>
                <template x-if="toast.action">
                    <a class="text-[13px] whitespace-nowrap" x-bind:href="toast.action.href" x-text="toast.action.label"></a>
                </template>
                <template x-if="! toast.action"><span></span></template>
                <button type="button" class="flex size-11 items-center justify-center rounded-md text-ink-2 hover:text-ink" x-on:click="remove(toast.id)" aria-label="{{ __('Close notification') }}">
                    <x-icon name="close" :size="16" />
                </button>
            </div>
        </template>
    </div>
</div>
