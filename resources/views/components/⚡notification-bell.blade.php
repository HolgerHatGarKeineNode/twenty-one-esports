<?php

use App\Enums\NotificationKind;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * The notification bell (P5c): unread count, the last 20 notifications, mark
 * one or all as read. No artboard draws it; it is built from the Overlays
 * toast vocabulary (tone bar, icon, bold title, grey text, orange action) and
 * the header's 44 px targets. The header renders it once; responsive classes
 * give it the mobile look below `lg` and the desktop look from `lg` on.
 *
 * The list is rendered only while the panel is open (P5g): a page load and a
 * reload of a closed bell carry the count, not 20 notifications. Opening the
 * panel asks for the list; closing it clears the flag without a request, so
 * the next reload of a closed bell leaves the list out again.
 *
 * A new notification reaches the page as a window event (resources/js/alerts.js,
 * `esports-notification`), and the bell reloads itself.
 */
new class extends Component {
    public const LIMIT = 20;

    /** True while the panel is open: only then does a render carry the list. */
    public bool $showList = false;

    public function loadList(): void
    {
        $this->showList = true;
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): Collection
    {
        return $this->user()->notifications()->latest()->limit(self::LIMIT)->get();
    }

    #[Computed]
    public function unread(): int
    {
        return $this->user()->unreadNotifications()->count();
    }

    /**
     * Mark one as read and follow its link.
     */
    public function open(string $id): void
    {
        $notification = $this->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;

        if (is_string($url) && $url !== '') {
            $this->redirect($url);
        }

        unset($this->notifications, $this->unread);
    }

    public function markRead(string $id): void
    {
        $this->user()->notifications()->findOrFail($id)->markAsRead();
        unset($this->notifications, $this->unread);
    }

    public function markAllRead(): void
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);
        unset($this->notifications, $this->unread);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $unread = $this->unread;
@endphp

<div class="relative" x-data="{ open: false }"
     x-init="$watch('open', (value) => { $dispatch('bell-toggle', value); value ? $wire.loadList() : ($wire.showList = false) })"
     x-on:esports-notification.window="$wire.$refresh()" x-on:dock-toggle.window="$event.detail && (open = false)"
     x-on:keydown.escape.window="open = false" x-on:click.outside="open = false" data-test="bell">
    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-controls="bell-panel"
            class="relative flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-lg text-ink-2 hover:text-ink lg:border lg:border-line lg:bg-well"
            aria-label="{{ $unread > 0 ? trans_choice('Notifications, :count unread|Notifications, :count unread', $unread) : __('Notifications') }}" data-test="bell-button">
        <x-icon name="bell" />
        @if ($unread > 0)
            <span class="absolute top-1 right-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-btc px-1 text-[11px] leading-none font-bold text-on-btc" aria-hidden="true" data-test="bell-count">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </button>

    {{-- Mobile: fixed under the 56 px bar; desktop: anchored to the bell. The two class sets never overlap, so no utility has to win the cascade. --}}
    <div id="bell-panel" x-show="open" x-cloak role="region" aria-label="{{ __('Notifications') }}"
         x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="-translate-y-1 opacity-0"
         x-transition:leave="transition duration-100 ease-in" x-transition:leave-end="opacity-0"
         class="z-40 flex flex-col overflow-hidden rounded-lg bg-card shadow-ring max-lg:fixed max-lg:inset-x-4 max-lg:top-[60px] max-lg:max-h-[calc(100svh-76px)] lg:absolute lg:top-full lg:right-0 lg:mt-2 lg:max-h-[min(640px,calc(100svh-96px))] lg:w-[420px]" data-test="bell-panel">
        <div class="flex min-h-12 shrink-0 items-center justify-between gap-3 border-b border-hairline pr-2 pl-4">
            <b class="text-[13px]">{{ __('Notifications') }}</b>
            @if ($unread > 0)
                <button type="button" wire:click="markAllRead" class="flex h-11 cursor-pointer items-center rounded-md px-2 text-[13px] text-btc hover:text-btc-hi" data-test="bell-read-all">{{ __('Mark all read') }}</button>
            @endif
        </div>

        @if (! $showList)
            <div role="status" aria-label="{{ __('Loading…') }}" class="flex flex-col" data-test="bell-loading">
                @foreach (['70%', '55%', '82%'] as $width)
                    <div class="flex h-[72px] flex-col justify-center gap-2 border-b border-hairline px-4 last:border-0" aria-hidden="true">
                        <span class="sk h-3" style="width: {{ $width }}"></span>
                        <span class="sk h-2.5 w-2/5"></span>
                    </div>
                @endforeach
            </div>
        @elseif ($this->notifications->isEmpty())
            <div class="flex flex-col gap-1.5 px-4 py-6">
                <b class="text-[13px]">{{ __('No notifications yet') }}</b>
                <span class="text-xs leading-normal text-ink-2">{{ __('Opponents found, invites, daily moves and results show up here.') }}</span>
            </div>
        @else
            <ul class="m-0 flex list-none flex-col overflow-y-auto p-0">
                @foreach ($this->notifications as $notification)
                    @php
                        $data = $notification->data;
                        $kind = NotificationKind::tryFrom((string) ($data['kind'] ?? ''));
                        $tone = $kind?->tone() ?? 'confirmed';
                        $isUnread = $notification->read_at === null;
                    @endphp
                    <li wire:key="n-{{ $notification->id }}" @class(['grid grid-cols-[4px_24px_minmax(0,1fr)_auto] items-start gap-3 border-b border-hairline pr-2 last:border-0', 'bg-toast-challenge' => $isUnread && $tone === 'challenge', 'bg-toast-neutral' => $isUnread && $tone !== 'challenge']) data-test="bell-item" data-unread="{{ $isUnread ? '1' : '0' }}">
                        <span @class(['h-full', 'bg-btc' => $isUnread && $tone === 'challenge', 'bg-win' => $isUnread && $tone !== 'challenge'])></span>
                        <span @class(['flex pt-3.5', 'text-btc' => $tone === 'challenge', 'text-win' => $tone !== 'challenge', 'opacity-60' => ! $isUnread])>
                            @if ($tone === 'challenge')<x-icon name="bolt-toast" :size="16" />@elseif ($tone === 'success')<x-icon name="check" :size="16" />@else<x-icon name="shield-check" :size="18" />@endif
                        </span>
                        <button type="button" wire:click="open('{{ $notification->id }}')" class="flex min-w-0 cursor-pointer flex-col gap-0.5 py-3 text-left text-[13px] text-ink" data-test="bell-open">
                            <b @class(['break-words', 'text-ink-2' => ! $isUnread])>{{ $data['title'] ?? '' }}</b>
                            <span class="break-words text-ink-2">{{ $data['body'] ?? '' }}</span>
                            <span class="text-xs text-ink-3">{{ $notification->created_at?->locale(app()->getLocale())->diffForHumans(['options' => Carbon\CarbonInterface::JUST_NOW]) }}</span>
                        </button>
                        @if ($isUnread)
                            <button type="button" wire:click="markRead('{{ $notification->id }}')" class="mt-1 flex size-11 cursor-pointer items-center justify-center rounded-md text-ink-2 hover:text-ink" aria-label="{{ __('Mark as read') }}" data-test="bell-mark-read">
                                <x-icon name="check" :size="16" />
                            </button>
                        @else
                            <span class="size-11"></span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
