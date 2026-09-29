{{--
    Invite friends to a tournament: copy the page link, hand it to a Nostr
    app (the system share sheet, else the clipboard with a hint), Telegram,
    and the tournament's card as an image (ShareCard `tournament-invite`).
    The invite-link share pattern of pages::invites.link.

    $tournament; $label: the line above the buttons; $text: the message.
    P47: for a signed-in player the link is their personal tournament link
    (InviteLinks::forTournament()), which credits them when a friend signs up.
--}}
@php($pageUrl = app(\App\Support\Invites\InviteLinks::class)->tournamentUrlFor(auth()->user(), $tournament) ?? route('tournaments.show', $tournament))
<div class="flex flex-col gap-2" data-test="tournament-share"
     x-data="{ copied: false, hint: '', canShare: typeof navigator.share === 'function', url: @js($pageUrl), text: @js($text),
               async copy(hint = '') { try { await navigator.clipboard.writeText(this.url); this.copied = true; this.hint = hint; setTimeout(() => { this.copied = false; this.hint = ''; }, 2500); } catch (e) { this.hint = @js(__('Copy did not work here. Select the link and copy it.')); } },
               async share(hint) { if (this.canShare) { try { await navigator.share({ title: document.title, text: this.text, url: this.url }); return; } catch (e) { if (e?.name === 'AbortError') return; } } await this.copy(hint); } }">
    <span class="text-xs text-ink-2">{{ $label }}</span>
    <div class="flex flex-wrap gap-2">
        <button type="button" x-on:click="copy()" data-test="copy-link"
                class="btn-w inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-3.5 text-[13px] text-ink">
            <x-icon name="copy" :size="16" /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy link'))">{{ __('Copy link') }}</span>
        </button>
        <button type="button" x-on:click="share(@js(__('Link copied. Paste it into a note in your Nostr app.')))" data-test="share-nostr"
                class="btn-w inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-3.5 text-[13px] text-ink"><x-icon name="chat" :size="16" />Nostr</button>
        <a href="https://t.me/share/url?url={{ urlencode($pageUrl) }}&amp;text={{ urlencode($text) }}" target="_blank" rel="noopener noreferrer"
           class="btn-w inline-flex h-11 items-center gap-2 rounded-md border border-line bg-well px-3.5 text-[13px] text-ink hover:text-ink"><x-icon name="send" :size="16" />Telegram</a>
        <a href="{{ \App\Support\Cards\ShareCard::tournamentInvite($tournament)->path('wide') }}" download="twentyone-tournament.png" data-test="share-card"
           class="btn-w inline-flex h-11 items-center gap-2 rounded-md border border-line bg-well px-3.5 text-[13px] text-ink hover:text-ink"><x-icon name="download" :size="16" />{{ __('Image') }}</a>
    </div>
    <span class="text-xs text-win" role="status" x-show="hint" x-text="hint" x-cloak></span>
</div>
