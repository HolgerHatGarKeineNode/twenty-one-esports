@props(['pubkey' => 'row.item.pubkey'])

{{--
    The admin's part of a chat message's menu (App\Support\Moderation\SiteModeration,
    resources/js/siteHidden.js): mute the author for everyone on the site, or
    ban them from the site, each with a reason and a confirm. Rendered for
    admins only; everyone else's menu keeps just their own "Mute for me".
    Worded apart from that personal mute on purpose (user, 2026-10-05).
    `pubkey`: the JS expression of the message's author in the chat around it.
--}}
{{-- The shell's admin check of this request (ShellNavigation, memoized): no second read of the admins table. --}}
@if (\App\Support\Navigation\ShellNavigation::current()->isAdmin)
    <span x-data="siteModeration" data-url="{{ route('admin.moderation.store') }}" data-failed="{{ __('That did not work. Please try again.') }}"
          class="flex w-full flex-col gap-2" data-test="chat-moderation">
        <span class="flex flex-wrap gap-2" x-show="! action">
            <button type="button" x-on:click="start('mute')" data-test="chat-mod-mute"
                    class="btn-w inline-flex h-8 cursor-pointer items-center gap-2 rounded-control border border-line bg-well px-2.5 text-xs text-ink">
                <x-icon name="mute" :size="14" /><span>{{ __('Mute for everyone') }}</span>
            </button>
            <button type="button" x-on:click="start('ban')" data-test="chat-mod-ban"
                    class="btn-w inline-flex h-8 cursor-pointer items-center gap-2 rounded-control border border-line bg-well px-2.5 text-xs text-loss">
                <x-icon name="lock" :size="14" /><span>{{ __('Ban from the site') }}</span>
            </button>
        </span>
        <form x-show="action" x-cloak x-on:submit.prevent="confirm({{ $pubkey }})" class="flex w-full min-w-0 flex-col gap-2 rounded-control bg-well p-2 shadow-ring-hairline" data-test="chat-mod-form">
            <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">
                <span x-text="action === 'ban' ? @js(__('Ban from the site: no login, no play, no chat. Reason (only admins read it)')) : @js(__('Mute for everyone: their messages are hidden on the whole site. Reason (only admins read it)'))"></span>
                <input type="text" x-ref="reason" x-model="reason" maxlength="500" required minlength="3" data-test="chat-mod-reason"
                       class="h-9 w-full min-w-0 rounded-control border border-edge bg-ground px-2.5 text-[13px] text-ink">
            </label>
            <span x-show="error" x-cloak role="alert" class="text-xs text-loss" x-text="error" data-test="chat-mod-error"></span>
            <span class="flex flex-wrap gap-2">
                <button type="submit" :disabled="busy || reason.trim().length < 3" data-test="chat-mod-confirm"
                        class="inline-flex h-8 cursor-pointer items-center rounded-control bg-btc px-3 text-xs font-bold text-on-btc disabled:cursor-default disabled:opacity-50"
                        x-text="action === 'ban' ? @js(__('Ban')) : @js(__('Mute for everyone'))"></button>
                <button type="button" x-on:click="cancel()" class="inline-flex h-8 cursor-pointer items-center rounded-control px-3 text-xs text-ink-2 hover:text-ink">{{ __('Cancel') }}</button>
            </span>
        </form>
    </span>
@endif
