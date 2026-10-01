{{--
    The inviter's share panel of an invite landing: the link to copy, the
    apps to share to, when it runs out, and cancelling it. Shared by the game
    and clan landing and the "beat my time" landing (partials/score).
    Needs $link, $shareText, $secondary, $note; $shareHeading is optional.
--}}
<div class="flex flex-col gap-4" data-test="invite-share"
     x-data="{ copied: false, hint: '', canShare: typeof navigator.share === 'function', url: @js($link->url()), text: @js($shareText),
               async copy(hint = '') { try { await navigator.clipboard.writeText(this.url); this.copied = true; this.hint = hint; setTimeout(() => this.copied = false, 2500); } catch (e) { this.hint = @js(__('Copy did not work here. Select the link and copy it.')); } },
               async share(hint) { if (this.canShare) { try { await navigator.share({ title: document.title, text: this.text, url: this.url }); return; } catch (e) { if (e?.name === 'AbortError') return; } } await this.copy(hint); } }">
    <h2 class="m-0 font-display text-2xl font-bold">{{ $shareHeading ?? __('Share your invite') }}</h2>
    <label for="invite-url" class="text-xs text-ink-2">{{ __('Invite link') }}</label>
    <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-2">
        <input id="invite-url" type="text" readonly value="{{ $link->url() }}" x-on:focus="$el.select()" data-test="invite-url"
               class="h-12 min-w-0 rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink">
        <button type="button" x-on:click="copy()" data-test="copy-link"
                class="btn-p inline-flex h-12 cursor-pointer items-center gap-2 rounded-lg px-4 text-sm font-bold whitespace-nowrap"
                x-bind:class="copied ? 'bg-win-tint text-win shadow-[inset_0_0_0_1px_#1F5A34]' : 'bg-btc text-on-btc'">
            <span x-show="! copied" class="inline-flex items-center gap-2"><x-icon name="copy" :size="16" /><span class="sm:hidden">{{ __('Copy') }}</span><span class="max-sm:hidden">{{ __('Copy link') }}</span></span>
            <span x-show="copied" x-cloak class="inline-flex items-center gap-2"><x-icon name="check" :size="16" />{{ __('Copied') }}</span>
        </button>
    </div>
    <span class="text-xs text-win" role="status" x-show="copied || hint" x-text="hint || @js(__('Link copied. Paste it in any chat.'))" x-cloak></span>

    <span class="text-xs text-ink-2">{{ __('Share to') }}</span>
    <div class="grid grid-cols-2 gap-2">
        <button type="button" x-on:click="share(@js(__('Link copied. Paste it into a note in your Nostr app.')))" class="{{ $secondary }}"><x-icon name="chat" :size="16" />Nostr</button>
        <button type="button" x-on:click="share(@js(__('Link copied. Paste it in Signal.')))" class="{{ $secondary }}"><x-icon name="send" :size="16" />Signal</button>
        <a href="https://t.me/share/url?url={{ urlencode($link->url()) }}&amp;text={{ urlencode($shareText) }}" target="_blank" rel="noopener noreferrer" class="{{ $secondary }}"><x-icon name="send" :size="16" />Telegram</a>
        <button type="button" x-show="canShare" x-on:click="share('')" class="{{ $secondary }}" data-test="native-share"><x-icon name="link" :size="16" />{{ __('More apps') }}</button>
    </div>

    <div class="flex flex-col gap-2 border-t border-hairline pt-4 text-xs leading-normal">
        <span class="flex items-start gap-2"><x-icon name="clock" :size="14" class="mt-0.5 shrink-0" />{{ $note }}</span>
        <span class="text-ink-2">{{ __('Invites never count toward ratings, Hashrate, blocks or rewards.') }}</span>
    </div>
    <button type="button" wire:click="revoke" wire:confirm="{{ __('Cancel this invite? The link stops working at once.') }}" data-test="revoke-link"
            class="inline-flex min-h-12 w-full cursor-pointer items-center justify-center rounded-lg border border-[#5A2A2E] bg-transparent text-[13px] text-loss">{{ __('Cancel this invite') }}</button>
</div>
