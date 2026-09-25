<?php

use App\Enums\NotificationKind;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Chess\ChessSettings;
use App\Support\Notifications\NotificationDm;
use App\Support\Notifications\WebPush;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Chess settings (ChessSettings.dc.html): board, during the game, daily
 * chess, and which notifications go out on which channel. Every change is
 * saved at once ("Saved, applies from your next move").
 *
 * Premoves and "Elo during the game" are shown switched off until premoves
 * and Elo exist. "Notify me about" is not in the design; every
 * NotificationKind gets a switch there, and off means nothing at all for that
 * event (no bell entry, no toast, no push, no DM).
 *
 * Sounds (P5c, the design's "Sounds" row in "During the game"): on/off and a
 * volume, with a button to hear the set. The page's sound player takes the
 * new values at once (`sound-settings` browser event).
 */
new #[Title('Chess settings')] #[Layout('layouts::app', ['scripts' => ['resources/js/chess.js', 'resources/js/push.js']])] class extends Component {
    public bool $saved = false;

    public function toggle(string $key): void
    {
        abort_unless(in_array($key, ['coordinates', 'alwaysQueen', 'doubleCheck', 'dm', 'sound'], true), 422);

        $settings = $this->settings()->toArray();
        $settings[$key] = ! $settings[$key];
        $this->store($settings);
    }

    public function setVolume(int $volume): void
    {
        abort_unless($volume >= 0 && $volume <= 100, 422);

        $this->store([...$this->settings()->toArray(), 'volume' => $volume]);
    }

    public function toggleTrigger(string $trigger): void
    {
        abort_unless(in_array($trigger, ChessSettings::triggers(), true), 422);

        $settings = $this->settings()->toArray();
        $settings['triggers'][$trigger] = ! $settings['triggers'][$trigger];
        $this->store($settings);
    }

    public function setBoard(string $board): void
    {
        abort_unless(in_array($board, ChessSettings::BOARDS, true), 422);

        // The Orange Pill board is a member perk (ChessSettings "Member").
        if ($board === 'orange' && ! $this->user()->is_member) {
            return;
        }

        $this->store([...$this->settings()->toArray(), 'board' => $board]);
    }

    public function setRemindHours(int $hours): void
    {
        abort_unless(in_array($hours, ChessSettings::REMIND_HOURS, true), 422);

        $this->store([...$this->settings()->toArray(), 'remindHours' => $hours]);
    }

    public function setPush(bool $on): void
    {
        $this->store([...$this->settings()->toArray(), 'push' => $on]);
    }

    /**
     * This browser's push subscription (PushSubscription.toJSON()).
     */
    public function savePushSubscription(string $json): bool
    {
        $data = json_decode($json, true);
        $endpoint = is_array($data) ? ($data['endpoint'] ?? null) : null;
        $key = is_array($data) ? ($data['keys']['p256dh'] ?? null) : null;
        $auth = is_array($data) ? ($data['keys']['auth'] ?? null) : null;

        if (! is_string($endpoint) || ! str_starts_with($endpoint, 'https://') || strlen($endpoint) > 500
            || ! is_string($key) || strlen(WebPush::base64UrlDecode($key)) !== 65
            || ! is_string($auth) || strlen(WebPush::base64UrlDecode($auth)) !== 16) {
            return false;
        }

        PushSubscription::query()->updateOrCreate(['endpoint' => $endpoint], ['user_id' => $this->user()->id, 'public_key' => $key, 'auth_token' => $auth]);
        $this->setPush(true);

        return true;
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
        $stored = ChessSettings::fromArray($settings);
        $this->user()->forceFill(['chess_settings' => $stored->toArray()])->save();
        $this->saved = true;
        $this->dispatch('sound-settings', enabled: $stored->sound, volume: $stored->volume);
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
    $themes = [
        'house' => [__('House'), __('league default'), false, '#CFCFD4', '#62626C'],
        'wood' => [__('Wood'), __('warm, like a real board'), false, '#E4CCA2', '#8E5F3B'],
        'slate' => [__('Slate'), __('cool and calm'), false, '#DCE3EA', '#5E7891'],
        'orange' => [__('Orange Pill'), __('bitcoin orange'), true, '#F4D9B0', '#B9640A'],
    ];
    $theme = $themes[$settings->board];
    $webPush = WebPush::fromConfig();
    $dmReady = NotificationDm::fromConfig()->isConfigured();
    $switch = fn (bool $on) => $on;
@endphp

<div class="flex grow flex-col gap-5 px-4 pb-8 lg:px-12 lg:pb-10" data-test="chess-settings">
    <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
        <h1 class="m-0 font-display text-[28px] font-bold lg:text-[32px]">{{ __('Settings') }}</h1>
        <span role="status" class="flex items-center gap-1.5 text-[13px] text-win" x-data x-show="$wire.saved" x-cloak data-test="settings-saved"><x-icon name="check" :size="16" />{{ __('Saved, applies from your next move') }}</span>
        <span class="grow"></span>
        <nav aria-label="{{ __('Settings sections') }}" class="flex border-b border-hairline">
            <a href="{{ route('gaming.edit') }}" class="flex h-11 items-center px-4 text-[13px] text-ink-2 hover:text-ink">{{ __('General') }}</a>
            <a href="{{ route('settings.chess') }}" aria-current="page" class="flex h-11 items-center px-4 text-[13px] font-bold text-ink shadow-[inset_0_-2px_0_#F7931A] hover:text-ink">{{ __('Chess') }}</a>
        </nav>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,400px)_minmax(0,1fr)_minmax(0,1fr)] xl:grid-cols-[400px_452px_minmax(0,1fr)]">
        {{-- Preview + keyboard --}}
        <div class="flex flex-col gap-5">
            <section aria-labelledby="pv-h" class="flex flex-col gap-3.5 rounded-lg bg-card px-6 py-5">
                <span class="flex items-baseline justify-between"><h2 id="pv-h" class="m-0 text-[15px] font-bold">{{ __('Preview') }}</h2><span class="text-xs text-ink-2">{{ $theme[0] }}</span></span>
                <div class="pt-4 pr-4" wire:key="preview-{{ $settings->board }}-{{ $settings->coordinates ? 1 : 0 }}" x-data="{ cells: window.chessBoardCells?.('r1bqkbnr/pppp1ppp/2n5/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R w KQkq - 2 3', { last: ['b8', 'c6'], theme: @js($settings->board), noCoords: @js(! $settings->coordinates), coords: true }) ?? [], boardLabel: @js(__('Preview of the :name board', ['name' => $theme[0]])) }">
                    <x-chess.board class="max-w-[304px]" />
                </div>
                <span class="text-[13px] leading-normal text-ink-2">{{ __('Last move in orange, also marked in the move list.') }}</span>
                <span class="border-t border-hairline pt-3 text-xs leading-normal text-ink-3">{{ __('Saved to your account, on every device you log in with.') }}</span>
            </section>

            <section aria-labelledby="kb-h" class="flex flex-col gap-2 rounded-lg bg-card px-6 py-5">
                <h2 id="kb-h" class="m-0 text-[15px] font-bold">{{ __('Keyboard') }}</h2>
                @foreach ([['Enter', __('play the typed move, e.g. Rh4')], ['Esc', __('clear selection or the promotion picker')], ['F', __('flip board')], ['← →', __('step through moves')], ['Q R B N', __('piece to promote to')]] as [$key, $text])
                    <div class="grid min-h-10 grid-cols-[100px_minmax(0,1fr)] items-center gap-3 border-b border-hairline text-[13px]"><kbd class="justify-self-start rounded-sm border border-edge px-2 py-0.5 font-mono text-xs">{{ $key }}</kbd><span class="text-ink-2">{{ $text }}</span></div>
                @endforeach
            </section>
        </div>

        {{-- Board --}}
        <section aria-labelledby="bd-h" class="flex flex-col gap-4 self-start rounded-lg bg-card px-6 py-5">
            <h2 id="bd-h" class="m-0 text-[15px] font-bold">{{ __('Board') }}</h2>
            <span class="text-[13px]" id="colors-h">{{ __('Colors') }}</span>
            <div role="radiogroup" aria-labelledby="colors-h" class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                @foreach ($themes as $key => [$name, $note, $memberOnly, $light, $dark])
                    @php($locked = $memberOnly && ! $user->is_member)
                    <button type="button" role="radio" aria-checked="{{ $settings->board === $key ? 'true' : 'false' }}" wire:click="setBoard('{{ $key }}')" @disabled($locked) data-test="board-{{ $key }}"
                            @class(['flex min-h-[190px] cursor-pointer flex-col gap-2 rounded-lg bg-ground p-2.5 text-left text-ink disabled:cursor-not-allowed disabled:opacity-60',
                                'shadow-[inset_0_0_0_2px_#F7931A]' => $settings->board === $key, 'shadow-ring' => $settings->board !== $key])>
                        <span aria-hidden="true" class="grid aspect-square w-full grid-cols-4 grid-rows-4 overflow-hidden rounded-xs">
                            @for ($i = 0; $i < 16; $i++)
                                <span style="background: {{ (intdiv($i, 4) + $i) % 2 === 0 ? $light : $dark }}"></span>
                            @endfor
                        </span>
                        <span class="flex items-start justify-between gap-1 text-[13px] font-bold">{{ $name }}@if ($settings->board === $key)<x-icon name="check" :size="16" class="shrink-0 text-btc" />@endif</span>
                        @if ($memberOnly)<x-member-badge class="self-start" />@endif
                        <span class="text-[11px] leading-snug text-ink-2">{{ $note }}</span>
                    </button>
                @endforeach
            </div>

            <div class="flex flex-col gap-2.5 border-t border-hairline pt-4">
                <span class="flex flex-wrap items-baseline justify-between gap-2"><span class="text-[13px]">{{ __('Pieces') }}</span><span class="flex items-center gap-2 text-xs text-ink-2">{{ __('Classic · more sets for members later') }} <x-member-badge /></span></span>
                <div aria-hidden="true" class="grid grid-cols-6 overflow-hidden rounded-md">
                    @foreach (['♔', '♕', '♖', '♗', '♘', '♙', '♚', '♛', '♜', '♝', '♞', '♟'] as $i => $glyph)
                        <span class="flex aspect-square items-center justify-center text-[34px] leading-none text-ground" style="background: {{ ($i + intdiv($i, 6)) % 2 === 0 ? $theme[3] : $theme[4] }}">{{ $glyph }}&#xFE0E;</span>
                    @endforeach
                </div>
                <span class="text-xs leading-normal text-ink-2">{{ __('White pieces always have a dark outline so they stay readable on light squares.') }}</span>
            </div>

            @include('pages.settings.partials.switch', ['label' => __('Coordinates'), 'hint' => __('a–h and 1–8 along the edge'), 'on' => $settings->coordinates, 'action' => "toggle('coordinates')", 'test' => 'coordinates'])
        </section>

        {{-- During the game, daily chess, notifications --}}
        <div class="flex flex-col gap-5">
            <section aria-labelledby="dg-h" class="flex flex-col rounded-lg bg-card px-6 py-5">
                <h2 id="dg-h" class="m-0 mb-1 text-[15px] font-bold">{{ __('During the game') }}</h2>
                @include('pages.settings.partials.switch', ['label' => __('Premoves'), 'hint' => __('queue a move while your opponent thinks · coming later'), 'on' => false, 'action' => null, 'test' => 'premoves'])
                @include('pages.settings.partials.switch', ['label' => __('Always queen'), 'hint' => __('promote without asking'), 'on' => $settings->alwaysQueen, 'action' => "toggle('alwaysQueen')", 'test' => 'always-queen'])
                @include('pages.settings.partials.switch', ['label' => __('Elo during the game'), 'hint' => __('no Elo before Block 0: every game is casual'), 'on' => false, 'action' => null, 'test' => 'elo'])
                @include('pages.settings.partials.switch', ['label' => __('Sounds'), 'hint' => __('moves, check, 10 s left, game end, notifications'), 'on' => $settings->sound, 'action' => "toggle('sound')", 'test' => 'sound'])

                {{-- Volume, with a button to hear the set (resources/js/sounds.js). --}}
                <div class="flex min-h-[61px] flex-wrap items-center gap-x-4 gap-y-2 py-2" x-data="{ volume: @js($settings->volume) }"
                     x-on:sound-settings.window="window.esportsSounds?.configure($event.detail)" data-test="sound-volume">
                    <span class="flex min-w-0 grow flex-col gap-0.5"><label for="sound-volume" class="text-sm">{{ __('Volume') }}</label><span class="text-xs text-ink-2" x-text="volume + ' %'">{{ $settings->volume }} %</span></span>
                    <input id="sound-volume" type="range" min="0" max="100" step="5" x-model.number="volume" @disabled(! $settings->sound)
                           x-on:input="window.esportsSounds?.configure({ volume })" x-on:change="$wire.setVolume(volume)"
                           class="h-11 w-36 cursor-pointer accent-btc disabled:cursor-not-allowed disabled:opacity-50" data-test="volume-input">
                    <button type="button" @disabled(! $settings->sound) data-test="sound-test" x-on:click="window.esportsSounds?.sample()"
                            class="btn-w inline-flex h-11 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-50">{{ __('Play a sound') }}</button>
                </div>
            </section>

            <section aria-labelledby="dc-h" class="flex flex-col rounded-lg bg-card px-6 py-5">
                <h2 id="dc-h" class="m-0 mb-1 text-[15px] font-bold">{{ __('Daily chess') }}</h2>
                @include('pages.settings.partials.switch', ['label' => __('Double-check daily moves'), 'hint' => __('one extra tap before a move is final'), 'on' => $settings->doubleCheck, 'action' => "toggle('doubleCheck')", 'test' => 'double-check'])

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

                @include('pages.settings.partials.switch', ['label' => __('Reminders by Nostr DM'), 'hint' => $dmReady ? __('to your Nostr inbox, for Nostr logins') : __('to your Nostr inbox · not set up on this server yet'), 'on' => $settings->dm, 'action' => "toggle('dm')", 'test' => 'dm'])

                <div class="flex min-h-[61px] items-center gap-4 py-2">
                    <span class="flex min-w-0 grow flex-col gap-0.5"><label for="remind-hours" class="text-sm">{{ __('Remind me when') }}</label><span class="text-xs text-ink-2">{{ __('are left before your move is due') }}</span></span>
                    <select id="remind-hours" wire:change="setRemindHours($event.target.value)" data-test="remind-hours"
                            class="h-11 rounded-lg border border-edge bg-ground px-3 text-[13px] text-ink">
                        @foreach (ChessSettings::REMIND_HOURS as $hours)
                            <option value="{{ $hours }}" @selected($settings->remindHours === $hours)>{{ $hours }} h</option>
                        @endforeach
                    </select>
                </div>
            </section>

            <section aria-labelledby="nf-h" class="flex flex-col rounded-lg bg-card px-6 py-5" data-test="notify-about">
                <h2 id="nf-h" class="m-0 mb-1 text-[15px] font-bold">{{ __('Notify me about') }}</h2>
                @foreach (NotificationKind::cases() as $kind)
                    @php([$label, $hint] = $kind->setting())
                    @include('pages.settings.partials.switch', ['label' => __($label), 'hint' => __($hint, ['hours' => $settings->remindHours]), 'on' => $settings->wants($kind->value), 'action' => "toggleTrigger('{$kind->value}')", 'test' => 'trigger-'.$kind->value])
                @endforeach
                <span class="pt-3 text-xs leading-normal text-ink-3">{{ __('Each shows in the bell and on the page you are on. Daily-chess and clan notifications also go out by browser push and Nostr DM, as switched on above.') }}</span>
            </section>
        </div>
    </div>
</div>
