/**
 * An answer for a Livewire component that is no longer in the document is
 * not morphed.
 *
 * A poll or refresh of a component on the page being left (the match dock,
 * the notification bell) can come back after wire:navigate has swapped the
 * page. Livewire still morphs it into the detached old element, whose Alpine
 * scope is already torn down: the morph's Alpine.cloneNode() then evaluates
 * the old element's expressions without their x-data, so `open` resolves to
 * window.open ("TypeError: Illegal invocation") and `announcement`,
 * `pageBar`, `panelLeft` to nothing ("ReferenceError"). Reproduced every time
 * with the CPU throttled 6x (tests/Browser/BunkerSessionTest.php, step 3) and
 * with the answer held back 1.5 s (tests/Browser/NavigateRaceTest.php).
 *
 * The message is cancelled before the morph. Its callers still get what the
 * server returned: the action promises are settled with the real returns
 * first, because a bare cancel() rejects them with a plain object, which
 * surfaced as "unhandledrejection: [object Object]" from code that awaits a
 * refresh.
 */
export function dropAnswersForDetachedComponents(Livewire) {
    Livewire.interceptMessage(({ message, onSuccess }) => {
        onSuccess(() => {
            if (!message.component?.el || message.component.el.isConnected) {
                return;
            }

            const effects = message.responsePayload?.effects ?? {};
            message.resolveActionPromises(effects.returns ?? [], effects.returnsMeta ?? {});
            message.cancel();
        });
    });
}
