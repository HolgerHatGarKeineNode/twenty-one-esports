<?php

use App\Models\ChatMute;
use App\Models\User;
use App\Support\GameChat\GameChannels;
use App\Support\GameNames;
use App\Support\Nostr\NostrKeys;
use Livewire\Attributes\Json;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The global chat of a game (P21, NIP "Game channels"): a NIP-28 public
 * channel with NIP-88 polls on the chat relays, on every game's page
 * (GameNames::page(): /chess, /games/{slug}, /scores/tmnf, /blockfill). Messages, polls and votes go from the browser to
 * the relays and back (resources/js/gameChannel.js); the server hands out
 * the channel, keeps a viewer's own mutes and answers which pubkeys are
 * league players (their name and avatar, and whose poll votes count).
 * wire:ignore: a page refresh never morphs the list.
 */
new class extends Component
{
    #[Locked]
    public string $game = 'chess';

    /**
     * @return array<string, mixed>|null
     */
    public function config(): ?array
    {
        $viewer = auth()->user();

        return GameChannels::config($this->game, $viewer instanceof User ? $viewer : null);
    }

    /**
     * Name, avatar and vote weight (`counts`) of the league accounts among these pubkeys (at most 100).
     *
     * @param  array<mixed>  $pubkeys
     * @return array<string, array{name: string, avatar: string, counts: bool}>
     */
    #[Json]
    public function players(array $pubkeys): array
    {
        return GameChannels::players($pubkeys);
    }

    /**
     * Mute or unmute a pubkey in the chats, for this viewer only (the browser keeps its own copy).
     */
    #[Json]
    public function setMuted(string $pubkey, bool $muted): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! NostrKeys::isHexPubkey($pubkey) || $pubkey === $user->pubkey) {
            return false;
        }

        if ($muted) {
            ChatMute::query()->firstOrCreate(['user_id' => $user->id, 'muted_pubkey' => $pubkey]);
        } else {
            ChatMute::query()->where('user_id', $user->id)->where('muted_pubkey', $pubkey)->delete();
        }

        return true;
    }
}; ?>

@php
    $chat = $this->config();
    $guest = ($chat['me'] ?? null) === null;
    $gameName = GameNames::game($game);
    $pickerOptions = $chat === null ? [] : ['locale' => $chat['locale'], 'me' => $chat['me'], 'relays' => $chat['emojiRelays'], 'labels' => ['yourEmoji' => $chat['labels']['yourEmoji'], 'insert' => $chat['labels']['insert']]];
    $durations = [3600 => __('1 hour'), 86400 => __('1 day'), 259200 => __('3 days'), 604800 => __('1 week')];
@endphp

{{--
    Placement (2026-10-03, user: "Kann der Chat bitte weiter oben hin? Ganz da unten geht er verloren."): below xl
    the page puts the chat right under its head, collapsed to one bar with the latest message and the unread count,
    opened in place; from xl the page's `.chat-rail` holds it as a sticky side column (resources/css/app.css), always
    open, between the header and the dock. Behaviour (history, polls, mutes) is the same in both.
--}}
<section aria-labelledby="game-chat-h" wire:ignore data-test="game-chat" data-game="{{ $game }}" data-channel="{{ $chat['channel'] ?? '' }}"
         class="@container flex flex-col overflow-hidden rounded-lg border-t-2 border-(--game,var(--color-btc)) bg-card shadow-ring"
         @if ($chat) x-data="gameChannel(@js($chat))" :data-open="open.toString()" @endif>
    <header @class(['relative flex items-center gap-3 border-b border-hairline px-4 py-3 lg:px-5', 'max-xl:border-b-0' => $chat !== null])
            @if ($chat) :class="{ 'max-xl:border-b-0': ! open }" @endif>
        <x-icon name="chat" :size="18" class="shrink-0 self-start text-btc max-xl:mt-0.5" />
        <div class="flex min-w-0 grow flex-col gap-1">
            <span class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id="game-chat-h" class="m-0 text-[15px] font-bold">{{ __(':game chat', ['game' => $gameName]) }}</h2>
                <span @class(['text-xs text-ink-2', 'max-xl:hidden' => $chat !== null]) @if ($chat) :class="{ 'max-xl:hidden': ! open }" @endif>{{ __('Talk and vote with everyone who plays :game.', ['game' => $gameName]) }}</span>
            </span>
            @if ($chat)
                {{-- Collapsed: the newest message (or poll), so a closed chat still shows that people talk here. --}}
                <p x-show="! open" id="game-chat-preview-{{ $game }}" class="m-0 flex min-w-0 items-center gap-2 text-[13px] leading-5 xl:hidden" data-test="game-chat-preview">
                    <template x-if="latest">
                        <span class="flex min-w-0 items-center gap-2">
                            <img :src="avatarOf(latest.pubkey)" alt="" width="20" height="20" referrerpolicy="no-referrer" loading="lazy" x-on:error="$el.src = generatedAvatar(latest.pubkey)" class="size-5 shrink-0 rounded-full bg-raised object-cover">
                            <b class="max-w-[40%] shrink-0 truncate font-bold text-ink-2" x-text="nameOf(latest.pubkey)"></b>
                            <span class="min-w-0 truncate text-ink" x-text="previewText(latest)"></span>
                        </span>
                    </template>
                    <span x-show="! latest" class="truncate text-ink-3" x-text="previewIdle"></span>
                </p>
            @endif
        </div>
        @if ($chat)
            <span x-show="! open && unread > 0" x-cloak class="inline-flex h-6 min-w-6 shrink-0 items-center justify-center rounded-tag bg-btc px-1.5 text-xs font-bold text-on-btc tabular-nums xl:hidden" data-test="game-chat-unread" x-text="unreadBadge"></span>
        @endif
        {{-- In the side column the composer's line says it ("Public on Nostr, visible in every client."); the title keeps the width. --}}
        <span @class(['relative z-10 inline-flex h-6 shrink-0 items-center gap-1.5 self-start rounded-tag px-2 text-[11px] text-ink-2 shadow-ring', 'max-xl:hidden max-sm:hidden xl:hidden' => $chat !== null]) @if ($chat) :class="{ 'max-xl:hidden': ! open }" @endif title="{{ __('NIP-28 channel and NIP-88 polls on the chat relays') }}"><x-icon name="link" :size="12" />{{ __('Public on Nostr') }}</span>
        @if ($chat)
            <x-icon name="chevron-down" :size="18" class="shrink-0 text-ink-2 transition-transform duration-200 ease-out motion-reduce:transition-none xl:hidden" x-bind:class="open ? 'rotate-180' : ''" />
            {{-- The whole bar opens and closes the chat below xl; from xl the chat is always open and this is gone. --}}
            <button type="button" x-on:click="toggle()" :aria-expanded="open.toString()" aria-controls="game-chat-body-{{ $game }}" aria-describedby="game-chat-preview-{{ $game }}" :aria-label="toggleLabel" data-test="game-chat-toggle"
                    class="absolute inset-0 cursor-pointer rounded-t-lg focus-visible:outline-offset-[-2px] xl:hidden"></button>
        @endif
    </header>

    @if ($chat === null)
        <p class="m-0 px-4 py-4 text-[13px] leading-5 text-ink-2 lg:px-5" data-test="game-chat-off">{{ __('The chat of this game is not set up yet.') }}</p>
    @else
        <div id="game-chat-body-{{ $game }}" class="grid grid-cols-1 transition-opacity duration-200 ease-out starting:opacity-0 motion-reduce:transition-none max-xl:hidden @3xl:grid-cols-[minmax(0,1fr)_300px] xl:flex xl:min-h-0 xl:grow xl:flex-col"
             :class="{ 'max-xl:hidden': ! open }" data-test="game-chat-body">
            {{-- The conversation --}}
            <div class="flex min-w-0 flex-col @3xl:border-r @3xl:border-hairline xl:min-h-0 xl:grow">
                {{-- As tall as a conversation needs, up to a fixed height; an empty channel stays short. In the side column: the column's height. --}}
                <div class="relative flex min-h-0 flex-col xl:h-auto xl:grow" :class="hasItems ? 'h-[360px] lg:h-[440px]' : 'h-44'">
                    <ol x-ref="list" x-on:scroll.passive="onScroll()" role="log" aria-label="{{ __('Chat messages') }}" data-test="game-chat-list"
                        class="m-0 flex min-h-0 grow list-none flex-col overflow-y-auto overscroll-contain px-2 py-2">
                        <li aria-hidden="true" class="grow"></li>
                        <template x-for="row in rows" :key="row.key">
                            <li class="flex flex-col" :data-type="row.type" :class="row.cont ? 'pt-0.5' : 'pt-2'">
                                <template x-if="row.type === 'muted'">
                                    <span class="flex min-h-8 items-center gap-2 px-2 text-xs text-ink-3" data-test="game-chat-muted">
                                        <x-icon name="mute" :size="14" />
                                        <span class="min-w-0 grow" x-text="mutedLabel(row)"></span>
                                        <button type="button" x-on:click="toggleReveal(row)" :aria-expanded="isRevealed(row).toString()"
                                                class="inline-flex min-h-8 cursor-pointer items-center rounded-control px-2 text-ink-2 underline decoration-edge underline-offset-2 hover:text-ink"
                                                x-text="isRevealed(row) ? t.hide : t.show"></button>
                                    </span>
                                </template>

                                <template x-if="row.type === 'message'">
                                    <div class="group flex gap-2.5 rounded-control px-2 py-0.5 hover:bg-row-hover" :class="row.revealed ? 'shadow-[inset_2px_0_0_var(--color-edge)]' : ''" data-test="game-chat-message" :data-pubkey="row.item.pubkey">
                                        <img :src="avatarOf(row.item.pubkey)" alt="" width="24" height="24" referrerpolicy="no-referrer" loading="lazy"
                                             x-on:error="$el.src = generatedAvatar(row.item.pubkey)" :class="row.cont ? 'invisible h-0' : ''"
                                             class="mt-0.5 size-6 shrink-0 rounded-full bg-raised object-cover">
                                        <div class="flex min-w-0 grow flex-col">
                                            <span class="flex min-w-0 items-baseline gap-2" x-show="! row.cont">
                                                <button type="button" x-on:click="toggleMenu(row.item.id)" :aria-expanded="(menuFor === row.item.id).toString()" :disabled="row.item.pubkey === me || ! me"
                                                        class="min-h-6 min-w-0 cursor-pointer truncate text-left text-xs font-bold disabled:cursor-default"
                                                        :class="row.item.pubkey === me ? 'text-btc-hi' : 'text-ink-2 hover:text-ink'" x-text="nameOf(row.item.pubkey)"></button>
                                                <span x-show="markOf(row.item.pubkey) === 'league' || markOf(row.item.pubkey) === 'bot'" class="shrink-0 rounded-tag px-1 text-[11px] leading-4 text-proof shadow-[inset_0_0_0_1px_var(--color-proof-ring)]" data-test="game-chat-badge" x-text="markOf(row.item.pubkey) === 'league' ? t.league : t.bot"></span>
                                                <span x-show="markOf(row.item.pubkey) === 'outside'" class="min-w-0 shrink truncate text-[11px] text-ink-3" data-test="game-chat-outside" x-text="t.notPlayer"></span>
                                                <time class="ml-auto shrink-0 text-[11px] text-ink-3 tabular-nums" :datetime="new Date(row.item.created_at * 1000).toISOString()" x-text="time(row.item.created_at)"></time>
                                            </span>
                                            <p class="m-0 text-sm leading-normal break-words text-ink" data-test="game-chat-text">
                                                <template x-for="(token, i) in row.item.tokens" :key="i">@include('pages.live.chat-token')</template>
                                            </p>
                                            <span x-show="menuFor === row.item.id" x-cloak class="flex flex-wrap gap-2 pt-1.5 pb-1">
                                                <button type="button" x-on:click="setMuted(row.item.pubkey, ! isMuted(row.item.pubkey))" data-test="game-chat-mute"
                                                        class="btn-w inline-flex h-8 cursor-pointer items-center gap-2 rounded-control border border-line bg-well px-2.5 text-xs text-ink">
                                                    <x-icon name="mute" :size="14" /><span x-text="muteLabel(row.item.pubkey)"></span>
                                                </button>
                                            </span>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="row.type === 'poll'">
                                    <div class="mx-1 flex gap-2.5 px-1 py-0.5" data-test="game-chat-poll-row">
                                        <img :src="avatarOf(row.item.pubkey)" alt="" width="24" height="24" referrerpolicy="no-referrer" loading="lazy" x-on:error="$el.src = generatedAvatar(row.item.pubkey)" class="mt-0.5 size-6 shrink-0 rounded-full bg-raised object-cover">
                                        <div class="flex min-w-0 grow flex-col gap-1">
                                            <span class="flex min-w-0 items-baseline gap-2 text-xs">
                                                <b class="truncate" :class="row.item.pubkey === me ? 'text-btc-hi' : 'text-ink-2'" x-text="nameOf(row.item.pubkey)"></b>
                                                <span class="text-ink-3">{{ __('asks') }}</span>
                                                <time class="ml-auto shrink-0 text-[11px] text-ink-3 tabular-nums" x-text="time(row.item.created_at)"></time>
                                            </span>
                                            <template x-for="poll in [row.item.poll]" :key="poll.id">@include('components.game-channel-poll')</template>
                                        </div>
                                    </div>
                                </template>
                            </li>
                        </template>
                        <li x-show="status === 'connecting'" class="px-2 py-2 text-[13px] text-ink-3">{{ __('Connecting to the chat …') }}</li>
                        <li x-show="status === 'live' && ! hasItems" x-cloak class="flex items-center gap-3 px-2 py-3 text-[13px] leading-5 text-ink-2" data-test="game-chat-empty">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-btc-chip text-btc" aria-hidden="true"><x-icon name="chat" :size="18" /></span>
                            <span>{{ __('No messages yet. Start the conversation or ask a question.') }}</span>
                        </li>
                        <li x-show="status === 'off'" x-cloak class="px-2 py-2 text-[13px] leading-5 text-ink-2">{{ __('The chat has no relay here, so it is off.') }}</li>
                    </ol>

                    <button type="button" x-show="unseen > 0 && ! atBottom" x-cloak x-transition.opacity.duration.150ms x-on:click="scrollToBottom(true)" data-test="game-chat-new"
                            class="absolute bottom-3 left-1/2 inline-flex h-9 -translate-x-1/2 cursor-pointer items-center gap-1.5 rounded-control bg-btc px-3 text-xs font-bold whitespace-nowrap text-on-btc shadow-[0_8px_24px_rgba(10,10,11,.7)] hover:bg-btc-hi">
                        <x-icon name="chevron-down" :size="16" /><span x-text="unseenLabel"></span>
                    </button>
                </div>

                @if ($guest)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-hairline px-4 py-3" data-test="game-chat-guest">
                        <x-button variant="quiet" icon="chat" :href="route('login')">{{ __('Log in to chat') }}</x-button>
                        <span class="text-xs text-ink-3">{{ __('Reading is open to everyone.') }}</span>
                    </div>
                @else
                    {{-- A new poll: question, 2 to 4 answers, how long it stays open. --}}
                    <form x-show="composing" x-cloak x-on:submit.prevent="sendPoll()" class="flex flex-col gap-2.5 border-t border-hairline bg-well/40 px-4 py-3" data-test="game-chat-poll-form">
                        <span class="flex items-center justify-between gap-2">
                            <b class="text-[13px]">{{ __('New poll') }}</b>
                            <button type="button" x-on:click="composing = false" class="inline-flex size-8 cursor-pointer items-center justify-center rounded-control text-ink-2 hover:bg-row-hover hover:text-ink" aria-label="{{ __('Close') }}"><x-icon name="close" :size="16" /></button>
                        </span>
                        <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Question') }}
                            <input x-ref="question" x-model="question" maxlength="{{ $chat['poll']['questionMax'] }}" autocomplete="off" data-test="game-chat-poll-question" class="h-11 rounded-md border border-edge bg-ground px-3 text-base text-ink lg:text-sm">
                        </label>
                        <fieldset class="m-0 flex flex-col gap-1.5 border-0 p-0">
                            <legend class="mb-1 text-xs text-ink-2">{{ __('Answers') }}</legend>
                            <template x-for="(answer, i) in answers" :key="i">
                                <span class="flex gap-2">
                                    <input x-model="answers[i]" maxlength="{{ $chat['poll']['optionMax'] }}" autocomplete="off" :aria-label="@js(__('Answer')) + ' ' + (i + 1)" data-test="game-chat-poll-answer"
                                           class="h-10 min-w-0 grow rounded-md border border-edge bg-ground px-3 text-base text-ink lg:text-sm">
                                    <button type="button" x-show="answers.length > 2" x-on:click="removeAnswer(i)" :aria-label="@js(__('Remove answer')) + ' ' + (i + 1)" class="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="close" :size="14" /></button>
                                </span>
                            </template>
                            <button type="button" x-show="answers.length < {{ $chat['poll']['maxOptions'] }}" x-on:click="addAnswer()" class="inline-flex min-h-9 cursor-pointer items-center gap-1.5 self-start rounded-control px-1 text-xs text-btc hover:text-btc-hi" data-test="game-chat-poll-add">+ {{ __('Add an answer') }}</button>
                        </fieldset>
                        <span class="flex flex-wrap items-end gap-2">
                            <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Open for') }}
                                <select x-model.number="duration" data-test="game-chat-poll-duration" class="h-10 rounded-md border border-edge bg-ground px-2 text-sm text-ink">
                                    @foreach ($chat['poll']['durations'] as $seconds)
                                        <option value="{{ $seconds }}">{{ $durations[$seconds] ?? trans_choice(':count hour|:count hours', intdiv($seconds, 3600)) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <span class="grow"></span>
                            <x-button type="submit" icon="vote" ::disabled="sending || pollProblem !== null" class="disabled:cursor-not-allowed disabled:opacity-50" data-test="game-chat-poll-send">{{ __('Start poll') }}</x-button>
                        </span>
                        <span class="text-[11px] leading-4 text-ink-3">{{ __('Public on Nostr. Votes count from members and players with a result in the league, once each: the latest.') }}</span>
                    </form>

                    <form x-show="status !== 'off'" x-on:submit.prevent="send()" class="flex flex-col gap-1.5 border-t border-hairline px-3 pt-3 pb-3" data-test="game-chat-form">
                        <div class="flex items-center gap-2">
                            <button type="button" x-show="! composing && mayPoll" x-on:click="openPollForm()" :disabled="status !== 'live'" aria-label="{{ __('New poll') }}" title="{{ __('New poll') }}" data-test="game-chat-poll-open"
                                    class="btn-w inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2 hover:text-ink disabled:opacity-50"><x-icon name="vote" :size="18" /></button>
                            <label for="game-chat-input" class="sr-only">{{ __('Message to the :game chat', ['game' => $gameName]) }}</label>
                            <div class="relative flex min-w-0 grow items-center">
                                <input id="game-chat-input" x-ref="composer" x-model="input" placeholder="{{ __('Say something') }}" autocomplete="off" enterkeyhint="send"
                                       maxlength="{{ $chat['maxLength'] * 2 }}" :aria-invalid="(remaining < 0).toString()" aria-describedby="game-chat-status" data-test="game-chat-input"
                                       class="h-11 w-full min-w-0 rounded-lg border border-edge bg-ground px-3.5 text-base text-ink placeholder:text-ink-3 lg:text-sm" :class="pointerFine ? 'pr-11' : ''">
                                <template x-if="pointerFine">
                                    <div class="absolute top-1/2 right-1 -translate-y-1/2" x-data="emojiPopover()" x-on:keydown.escape.window="close(true)">
                                        <button type="button" x-ref="trigger" x-on:click="toggle()" :aria-expanded="open.toString()" aria-label="{{ __('Insert emoji') }}" title="{{ __('Insert emoji') }}" data-test="game-chat-emoji"
                                                class="inline-flex size-9 cursor-pointer items-center justify-center rounded-control text-ink-2 hover:bg-row-hover hover:text-ink" :class="open ? 'text-btc' : ''">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0M9 9.5h.01M15 9.5h.01"></path></svg>
                                        </button>
                                        <template x-if="open">
                                            <div>
                                                <template x-teleport="body">
                                                    <div data-emoji-panel role="dialog" aria-label="{{ __('Insert emoji') }}" :style="panelStyle" x-on:click.outside="closeUnless($event)"
                                                         x-init="$nextTick(() => $el.querySelector('input[type=search]')?.focus()); new ResizeObserver(() => reposition()).observe($el)"
                                                         class="fixed z-50 rounded-card bg-bar p-2 shadow-[0_0_0_1px_var(--color-line),0_16px_32px_rgba(10,10,11,.8)]">
                                                        <x-emoji-picker :options="$pickerOptions" />
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                            <button type="submit" aria-label="{{ __('Send message') }}" :disabled="sending" data-test="game-chat-send"
                                    class="btn-w inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink disabled:opacity-50"><x-icon name="send" :size="16" /></button>
                        </div>
                        <p id="game-chat-status" class="m-0 flex items-start justify-between gap-3 px-1 text-xs leading-4">
                            <span class="text-loss" role="alert" x-show="error" x-text="error" data-test="game-chat-error"></span>
                            <span class="text-ink-3" x-show="! error">{{ __('Public on Nostr, visible in every client.') }}</span>
                            <span x-show="remaining < 40" x-cloak class="shrink-0 tabular-nums" :class="remaining < 0 ? 'text-loss' : 'text-ink-3'" x-text="remaining"></span>
                        </p>
                    </form>
                @endif
            </div>

            {{--
                Where the chat is 48rem wide: the open polls beside the conversation, to vote without scrolling for
                them (narrower, below xl, they are in the conversation only). In the xl side column above it instead,
                at most 40 % of the column, and only while a poll is open.
            --}}
            <aside aria-labelledby="game-polls-h" data-polls="0" :data-polls="openPolls.length" class="hidden flex-col gap-3 px-4 py-4 @3xl:flex xl:order-first xl:flex xl:max-h-[40%] xl:shrink-0 xl:overflow-y-auto xl:overscroll-contain xl:border-b xl:border-hairline xl:py-3 xl:data-[polls=0]:hidden" data-test="game-chat-polls">
                <span class="flex items-center justify-between gap-2">
                    <h3 id="game-polls-h" class="m-0 text-[13px] font-bold">{{ __('Open polls') }}</h3>
                    @unless ($guest)
                        <button type="button" x-show="mayPoll" x-on:click="openPollForm()" :disabled="status !== 'live'" class="inline-flex min-h-8 cursor-pointer items-center gap-1 rounded-control px-1 text-xs text-btc hover:text-btc-hi disabled:opacity-50" data-test="game-chat-poll-open-side">+ {{ __('New poll') }}</button>
                    @endunless
                </span>
                <template x-for="poll in openPolls" :key="poll.id">@include('components.game-channel-poll')</template>
                <p x-show="openPolls.length === 0" class="m-0 text-xs leading-5 text-ink-3" data-test="game-chat-polls-empty">{{ __('No open poll. Ask the community what to play next, which format, which time.') }}</p>
                @unless ($guest)
                    <p x-show="! mayPoll" x-cloak class="m-0 text-xs leading-5 text-ink-3" data-test="game-chat-polls-locked">{{ __('Polls and counted votes are for members and players with a result in the league.') }}</p>
                @endunless
            </aside>
        </div>
    @endif
</section>
