<?php

use App\Enums\NotificationKind;
use App\Jobs\SendNostrDm;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Chess\ChessSettings;
use App\Support\Notifications\Notice;
use App\Support\Notifications\NotificationDm;
use App\Support\Notifications\NotificationDmOptOut;
use App\Support\Notifications\WebPush;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Notifications (P51, split off the chess settings): the channels (bell,
 * browser push, Nostr DM, the daily-move reminder) and a switch per
 * NotificationKind. Off means nothing at all for that event (no bell entry,
 * no toast, no push, no DM). Every change is saved at once; the values live
 * in ChessSettings, as before the split.
 *
 * Nostr DM (ChessSettings::dmFor): shown on unless switched off. On, it
 * covers only the kinds that need a player while they are away
 * (NotificationKind::dmAllowed()); push and DM wait while the player is on
 * the site (OnSite). The switches come in four groups
 * (NotificationKind::group()), each row saying where it can reach the
 * player; the page-only kinds of a live game have no switch.
 */
new #[Title('Notifications')] #[Layout('layouts::app', ['scripts' => ['resources/js/push.js']])] class extends Component {
    public bool $saved = false;

    public function toggle(string $key): void
    {
        abort_unless($key === 'dm', 422);

        // `dm` unset means on (by default for some kinds): the first tap turns it off.
        $this->store([...$this->settings()->toArray(), 'dm' => ! $this->settings()->dmOn()]);
    }

    public function toggleTrigger(string $trigger): void
    {
        abort_unless(in_array($trigger, ChessSettings::triggers(), true) && ! NotificationKind::from($trigger)->pageOnly(), 422);

        $settings = $this->settings()->toArray();
        $settings['triggers'][$trigger] = ! $settings['triggers'][$trigger];
        $this->store($settings);
    }

    /**
     * P45: a kind's Nostr DM at once, or in the daily digest.
     */
    public function setDigest(string $trigger, string $timing): void
    {
        abort_unless(in_array($trigger, ChessSettings::triggers(), true) && in_array($timing, ['instant', 'daily'], true), 422);

        $settings = $this->settings()->toArray();

        if ($timing === 'daily') {
            $settings['digest'][$trigger] = true;
        } else {
            unset($settings['digest'][$trigger]);
        }

        $this->store($settings);
    }

    public function setRemindHours(int $hours): void
    {
        abort_unless(in_array($hours, ChessSettings::REMIND_HOURS, true), 422);

        $this->store([...$this->settings()->toArray(), 'remindHours' => $hours]);
    }

    /**
     * "Send a test DM" (P45): one DM from the notification key to the player,
     * whatever the DM switch says (it is asked for), at most one a minute. The
     * job looks up the player's DM relays afresh and leaves its outcome for
     * testDm(); the page polls it while it is pending.
     */
    public function sendTestDm(): void
    {
        $user = $this->user();
        abort_unless(NotificationDm::fromConfig()->isConfigured(), 422);

        $key = 'dm-test-send:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->addError('testDm', __('One test DM a minute. Wait :seconds s and try again.', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }

        RateLimiter::hit($key, 60);
        Cache::put(SendNostrDm::testResultKey($user), ['state' => 'pending', 'format' => null, 'accepted' => 0, 'asked' => 0, 'at' => now()->getTimestamp()], now()->addMinutes(SendNostrDm::TEST_RESULT_MINUTES));

        $locale = $user->locale ?? (string) config('app.locale');
        $notice = new Notice(
            __('Test DM from TWENTY ONE esports', [], $locale),
            __('If you can read this, league notifications reach your Nostr inbox.', [], $locale),
            route('settings.notifications'),
        );

        SendNostrDm::dispatch($user, $notice->toDmText(NotificationDmOptOut::line($user)), null, true);
    }

    /**
     * The outcome of the last test DM, while it is kept.
     *
     * @return array{state: string, format: string|null, accepted: int, asked: int, at: int}|null
     */
    public function testDm(): ?array
    {
        $result = Cache::get(SendNostrDm::testResultKey($this->user()));

        return is_array($result) ? $result : null;
    }

    public function setPush(bool $on): void
    {
        $this->store([...$this->settings()->toArray(), 'push' => $on]);
    }

    /**
     * This browser's push subscription (PushSubscription.toJSON()). Null when
     * it is saved, else why not: an endpoint that is not https on a public
     * host is refused here already ({@see WebPush::endpointTarget()}).
     */
    public function savePushSubscription(string $json): ?string
    {
        $data = json_decode($json, true);
        $endpoint = is_array($data) ? ($data['endpoint'] ?? null) : null;
        $key = is_array($data) ? ($data['keys']['p256dh'] ?? null) : null;
        $auth = is_array($data) ? ($data['keys']['auth'] ?? null) : null;

        if (! is_string($endpoint) || ! str_starts_with($endpoint, 'https://') || strlen($endpoint) > 500
            || ! is_string($key) || strlen(WebPush::base64UrlDecode($key)) !== 65
            || ! is_string($auth) || strlen(WebPush::base64UrlDecode($auth)) !== 16) {
            return __('That did not work. Please try again.');
        }

        if (WebPush::fromConfig()->endpointTarget($endpoint) === null) {
            return __('This browser\'s push service cannot be reached from here: its address must be https on a public host.');
        }

        PushSubscription::query()->updateOrCreate(['endpoint' => $endpoint], ['user_id' => $this->user()->id, 'public_key' => $key, 'auth_token' => $auth]);
        $this->setPush(true);

        return null;
    }

    public function removePushSubscription(string $endpoint): void
    {
        PushSubscription::query()->where('user_id', $this->user()->id)->where('endpoint', $endpoint)->delete();
    }

    public function settings(): ChessSettings
    {
        return $this->user()->chessSettings();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function store(array $settings): void
    {
        $this->user()->forceFill(['chess_settings' => ChessSettings::fromArray($settings)->toArray()])->save();
        $this->saved = true;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $settings = $this->settings();
    $user = auth()->user();
    $webPush = WebPush::fromConfig();
    $dmReady = NotificationDm::fromConfig()->isConfigured();
@endphp

<div class="flex grow flex-col gap-5 px-4 pb-8 lg:px-12 lg:pb-10" data-test="notification-settings">
    <x-settings.header current="notifications">
        <span role="status" class="flex items-center gap-1.5 text-[13px] text-win" x-data x-show="$wire.saved" x-cloak data-test="settings-saved"><x-icon name="check" :size="16" />{{ __('Saved.') }}</span>
    </x-settings.header>

    <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,480px)_minmax(0,1fr)]">
        <div class="flex min-w-0 flex-col gap-5">
        <section id="notifications" aria-labelledby="ch-h" class="flex scroll-mt-20 flex-col rounded-lg bg-card px-4 py-5 lg:px-6" data-test="notification-channels">
            <h2 id="ch-h" class="m-0 mb-1 text-[15px] font-bold">{{ __('Channels') }}</h2>
            <div class="flex min-h-[61px] items-center gap-4 border-b border-hairline py-2" data-test="channel-bell">
                <span class="flex min-w-0 grow flex-col gap-0.5"><span class="text-sm">{{ __('Bell and the page you are on') }}</span><span class="text-xs text-ink-2">{{ __('always, for every kind switched on') }}</span></span>
                <x-icon name="bell" :size="18" class="shrink-0 text-ink-2" />
            </div>
                {{-- Browser push: this browser subscribes in resources/js/push.js --}}
                <div class="flex min-h-[61px] items-center gap-4 border-b border-hairline py-2" x-data="pushToggle(@js(['on' => $settings->push && $user->pushSubscriptions()->exists(), 'vapidKey' => $webPush->isConfigured() ? $webPush->publicKey() : null, 'labels' => [
                    'allowed' => __('allowed in this browser'), 'notHere' => __('not set up in this browser yet'), 'denied' => __('blocked in this browser\'s settings'),
                    'unsupported' => __('this browser has no push'), 'notConfigured' => __('not set up on this server yet'), 'failed' => __('That did not work. Please try again.'),
                ]]))">
                    <span class="flex min-w-0 grow flex-col gap-0.5"><span class="text-sm">{{ __('Reminders by browser push') }}</span><span class="text-xs text-ink-2" x-text="error || hint"></span></span>
                    <span class="text-xs text-ink-2" x-text="on ? @js(__('on')) : @js(__('off'))"></span>
                    <button type="button" role="switch" :aria-checked="on ? 'true' : 'false'" aria-label="{{ __('Reminders by browser push') }}" x-on:click="toggle()" :disabled="busy || ! supported" data-test="push-toggle"
                            class="relative h-8 w-[50px] shrink-0 cursor-pointer rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50" :class="on ? 'bg-btc' : 'bg-ground shadow-[inset_0_0_0_1px_#63636A]'">
                        <span class="absolute top-1 size-6 rounded-full transition-all" :class="on ? 'left-[22px] bg-ground' : 'left-1 bg-edge'"></span>
                    </button>
                </div>

                @php($dmHint = match (true) {
                    ! $dmReady => __('to your Nostr inbox · not set up on this server yet'),
                    $settings->dmOn() => __('only for what needs you while you are away: challenges, requests, deadline reminders, tournament news'),
                    default => __('off · the league sends you no DM'),
                })
                @include('pages.settings.partials.switch', ['label' => __('Notifications by Nostr DM'), 'hint' => $dmHint, 'on' => $settings->dmOn(), 'action' => "toggle('dm')", 'test' => 'dm'])

                @if ($dmReady)
                    {{-- P45: one DM now, to see whether and how league DMs reach this player. --}}
                    @php($testDm = $this->testDm())
                    <div class="flex min-h-[61px] flex-wrap items-center gap-x-4 gap-y-2 border-b border-hairline py-2" data-test="test-dm"
                         @if (($testDm['state'] ?? null) === 'pending') wire:poll.2s @endif>
                        <span class="flex min-w-0 grow basis-48 flex-col gap-0.5">
                            <span class="text-sm">{{ __('Test DM') }}</span>
                            <span class="text-xs text-ink-2" role="status" data-test="test-dm-status" data-state="{{ $testDm['state'] ?? 'none' }}" data-format="{{ $testDm['format'] ?? '' }}">
                                @if ($testDm === null)
                                    {{ __('sends one DM to your Nostr inbox now, to check that they reach you') }}
                                @elseif ($testDm['state'] === 'pending')
                                    {{ __('Sending…') }}
                                @elseif ($testDm['state'] === 'failed')
                                    {{ __('That did not work. Please try again.') }}
                                @elseif ($testDm['accepted'] === 0)
                                    {{ __('No relay took it. Please try again later.') }}
                                @elseif ($testDm['format'] === 'nip04')
                                    {{ __('Sent as an older NIP-04 DM, because you have no DM relay list (kind 10050): :accepted of :asked relays took it. Apps that only read NIP-17 will not show it.', ['accepted' => $testDm['accepted'], 'asked' => $testDm['asked']]) }}
                                @else
                                    {{ __('Sent as a NIP-17 DM to your DM relays: :accepted of :asked relays took it. Look in your Nostr app.', ['accepted' => $testDm['accepted'], 'asked' => $testDm['asked']]) }}
                                @endif
                            </span>
                            @error('testDm')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                        </span>
                        <button type="button" wire:click="sendTestDm" wire:loading.attr="disabled" wire:target="sendTestDm" data-test="send-test-dm"
                                @disabled(($testDm['state'] ?? null) === 'pending')
                                class="btn-w inline-flex h-11 shrink-0 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-3 text-[13px] text-ink disabled:cursor-wait disabled:opacity-70">
                            <x-icon name="send" :size="16" />{{ __('Send a test DM') }}
                        </button>
                    </div>
                @endif

                <div class="flex min-h-[61px] items-center gap-4 py-2">
                    <span class="flex min-w-0 grow flex-col gap-0.5"><label for="remind-hours" class="text-sm">{{ __('Remind me when') }}</label><span class="text-xs text-ink-2">{{ __('are left before your move is due') }}</span></span>
                    <select id="remind-hours" wire:change="setRemindHours($event.target.value)" data-test="remind-hours"
                            class="h-11 rounded-lg border border-edge bg-ground px-3 text-[13px] text-ink">
                        @foreach (ChessSettings::REMIND_HOURS as $hours)
                            <option value="{{ $hours }}" @selected($settings->remindHours === $hours)>{{ $hours }} h</option>
                        @endforeach
                    </select>
                </div>
                <span class="border-t border-hairline pt-3 text-xs leading-normal text-ink-3" data-test="channels-explained">{{ __('Each shows in the bell and on the page you are on. Push and DM reach you only while you are not on the site, and only where the list says so. A live game sends neither: you are at the board.') }}</span>
                <span class="pt-2 text-xs leading-normal text-ink-3" data-test="dm-explained">{{ __('Nostr DMs come from the league\'s own notification key, never from another player. They are on by default for what needs you while you are away. Turn them off with the switch above, or with the link at the end of every DM, no login needed.') }}</span>
        </section>

            {{-- P45: per kind that goes out by DM, at once or in one DM a day (DmDigest, 18:00 Berlin time). --}}
            @php($dmKinds = $dmReady ? array_values(array_filter(NotificationKind::cases(), fn (NotificationKind $kind): bool => $settings->wants($kind->value) && $settings->dmFor($kind->value))) : [])
            @if ($dmKinds !== [])
                <section aria-labelledby="dt-h" class="flex flex-col rounded-lg bg-card px-4 py-5 lg:px-6" data-test="dm-timing">
                    <h2 id="dt-h" class="m-0 mb-1 text-[15px] font-bold">{{ __('Nostr DM: at once or daily') }}</h2>
                    <p class="m-0 mb-2 text-xs leading-normal text-ink-2">{{ __('Daily collects these into one DM at 18:00 (Berlin time). The bell still shows each at once.') }}</p>
                    @foreach ($dmKinds as $kind)
                        @php([$label] = $kind->setting())
                        <div class="flex min-h-[61px] items-center gap-4 border-b border-hairline py-2 last:border-0">
                            <label for="dm-timing-{{ $kind->value }}" class="min-w-0 grow text-sm">{{ __($label) }}</label>
                            <select id="dm-timing-{{ $kind->value }}" wire:change="setDigest('{{ $kind->value }}', $event.target.value)" data-test="dm-timing-{{ $kind->value }}"
                                    class="h-11 shrink-0 rounded-lg border border-edge bg-ground px-3 text-[13px] text-ink">
                                <option value="instant" @selected(! $settings->digestFor($kind->value))>{{ __('At once') }}</option>
                                <option value="daily" @selected($settings->digestFor($kind->value))>{{ __('Daily digest') }}</option>
                            </select>
                        </div>
                    @endforeach
                </section>
            @endif
        </div>

            <section aria-labelledby="nf-h" class="flex flex-col self-start rounded-lg bg-card px-4 py-5 lg:px-6" data-test="notify-about">
                <h2 id="nf-h" class="m-0 mb-1 text-[15px] font-bold">{{ __('Notify me about') }}</h2>
                @foreach (['correspondence' => __('Correspondence games'), 'play' => __('Live games and 1v1'), 'community' => __('Clans and tournaments'), 'league' => __('League')] as $group => $heading)
                    <h3 class="m-0 mt-4 text-xs font-bold tracking-wide text-ink-2 uppercase" data-test="notify-group-{{ $group }}">{{ $heading }}</h3>
                    @foreach (NotificationKind::cases() as $kind)
                        @continue($kind->group() !== $group)
                        @php([$label, $hint] = $kind->setting())
                        @include('pages.settings.partials.switch', [
                            'label' => __($label),
                            'hint' => __($hint, ['hours' => $settings->remindHours]),
                            'reach' => $kind->dmAllowed() ? __('bell, push and DM') : __('bell and push'),
                            'on' => $settings->wants($kind->value),
                            'action' => "toggleTrigger('{$kind->value}')",
                            'test' => 'trigger-'.$kind->value,
                        ])
                    @endforeach
                @endforeach
                <p class="m-0 mt-4 text-xs leading-normal text-ink-3" data-test="page-only-kinds">{{ __('Always on this page only, never sent: opponent found, invites to a live game or 1v1, your opponent joined your lobby. You are at the board for these.') }}</p>
            </section>
    </div>
</div>
