<?php

use App\Enums\ChessGameStatus;
use App\Enums\TournamentStatus;
use App\Models\ChatMute;
use App\Models\ChessGame;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\PageMeta;
use App\Support\StreamChat\StreamChat;
use App\Support\TwentyOne\LiveStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Json;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * The live stream page (P20, /live): the league's self-hosted 24/7 stream
 * in a big player, and around it what is on it right now: the running chess
 * games (the queries of the live games page), the running tournaments with
 * their bracket and TV view, sharing, and the zap QR code of the stream
 * scenes (never a Lightning address as text). Off air, the stage says so and
 * names the next tournament if one is scheduled.
 *
 * The stage and the on-air line follow the page's live feed (P20b,
 * Alpine.store('live')): the count changes without a reload, and the stage
 * starts playing when the stream comes on air while the page is open. Both
 * are wire:ignore'd; the 30-second poll refreshes the lists (only their island,
 * `programme`: performance plan P3). The floating player
 * (<x-live-player>) is not rendered here.
 *
 * The stream chat (P24, pages/live/chat, resources/js/liveChat.js): the
 * NIP-53 chat of the stream's 30311 next to the stage from lg, a tab under it
 * on smaller screens. The server hands it its config and keeps a viewer's
 * mutes; messages never pass through it.
 */
new #[Layout('layouts::app', ['scripts' => ['resources/js/liveChat.js']])] class extends Component {
    public const GAMES = 8;

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Live stream'));
        app(PageMeta::class)->describe(__('Live stream'), __('Watch the TWENTY ONE esports stream: the league\'s live chess games and tournaments, with music, around the clock. No login needed.'));
        app(\App\Support\PageMeta::class)->card(fn () => \App\Support\Cards\PageCard::page('live'));
    }

    #[Computed]
    public function status(): LiveStatus
    {
        return LiveStatus::current();
    }

    /**
     * Blitz first (it moves), then daily games by their latest move.
     *
     * @return Collection<int, ChessGame>
     */
    #[Computed]
    public function games(): Collection
    {
        $blitz = ChessGame::query()->live()->where('status', ChessGameStatus::Active)->with(['white', 'black'])->latest('id')->limit(self::GAMES)->get();
        $daily = ChessGame::query()->daily()->where('status', ChessGameStatus::Active)->with(['white', 'black'])->latest('updated_at')->latest('id')->limit(self::GAMES)->get();

        return $blitz->concat($daily)->take(self::GAMES);
    }

    /**
     * @return Collection<int, Tournament>
     */
    #[Computed]
    public function tournaments(): Collection
    {
        return Tournament::query()->where('status', TournamentStatus::Running)->exceptLeagueWeeks()->orderBy('starts_at')->limit(4)->get();
    }

    /**
     * The next tournament that has a start time, for the off-air stage.
     */
    #[Computed]
    public function next(): ?Tournament
    {
        return Tournament::query()->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing])
            ->whereNotNull('starts_at')->where('starts_at', '>', now())->orderBy('starts_at')->first();
    }

    /**
     * Config for the stream chat, null when the stream has no Nostr address.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function chat(): ?array
    {
        $viewer = auth()->user();

        return StreamChat::current()?->config($viewer instanceof User ? $viewer : null);
    }

    /**
     * Mute or unmute a pubkey in the chat, for this viewer only (the browser keeps its own copy).
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

    /**
     * Which of these pubkeys (at most 100) are league accounts, for the
     * chat's mention chips (StreamChat::players(): the pubkeys only). Null
     * when this address asked more than 30 times in a minute; the chat then
     * keeps the njump.me link.
     *
     * @param  array<mixed>  $pubkeys
     * @return list<string>|null
     */
    #[Json]
    public function players(array $pubkeys): ?array
    {
        $key = 'live-chat-players:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 30)) {
            return null;
        }

        RateLimiter::hit($key, 60);

        return StreamChat::players($pubkeys);
    }

    /**
     * The zap QR code the stream scenes show (resources/stream/qr), as an image; null without one.
     */
    #[Computed]
    public function zapQr(): ?string
    {
        $path = resource_path('stream/qr/lnurl.svg');
        $svg = is_file($path) ? @file_get_contents($path) : false;

        return is_string($svg) && str_contains($svg, '<svg') ? 'data:image/svg+xml;base64,'.base64_encode($svg) : null;
    }
}; ?>

@php
    $status = $this->status;
    $games = $this->games;
    $pageUrl = route('live');
    $shareText = __('Live now on TWENTY ONE esports: chess, tournaments and music.');
    $watchingNow = [
        'one' => trans_choice('watching now|watching now', 1),
        'many' => trans_choice('watching now|watching now', 2),
    ];
    $stage = [
        'url' => LiveStatus::playlistUrl(),
        'labels' => [
            'loading' => __('Tuning in…'),
            'retrying' => __('Signal lost. Reconnecting…'),
            'ended' => __('The stream went off air.'),
            'unsupported' => __('This browser cannot play the stream.'),
            'blocked' => __('Your browser blocks autoplay. Press play, or allow autoplay for this site in the address bar.'),
        ],
    ];
@endphp

<div class="flex flex-col gap-5 px-4 pb-8 lg:gap-6 lg:px-12" data-test="live-page" data-live="{{ $status->live ? '1' : '0' }}">
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
        <div class="flex max-w-[76ch] flex-col gap-1.5">
            <h1 class="m-0 font-display text-2xl leading-tight font-bold lg:text-[28px]">{{ __('Live stream') }}</h1>
            <p class="m-0 text-[13px] leading-5 text-ink-2">{{ __('The league\'s live chess games and tournaments, with music, around the clock.') }}</p>
        </div>
        {{-- On air: the tally light, not over the picture (the stream's own scenes carry their marks there). --}}
        <p class="m-0 flex items-center gap-3 text-[13px] text-ink-2" wire:ignore data-test="live-on-air"
           x-data="{ words: @js($watchingNow) }" x-show="$store.live.live" @unless ($status->live) style="display: none" @endunless>
            <span class="flex h-7 items-center gap-1.5 rounded-control bg-live-tint px-2 shadow-[inset_0_0_0_1px_var(--color-live-ring)]">
                <span class="on-air" aria-hidden="true"></span>
                <span class="font-display text-[11px] leading-none font-extrabold tracking-[0.06em] text-ink">LIVE</span>
            </span>
            <span data-test="live-viewers" x-show="$store.live.viewers !== null" @if ($status->viewers === null) style="display: none" @endif>
                <b class="inline-block min-w-[3ch] font-display text-base text-ink tabular-nums" x-text="$store.live.viewers" x-effect="$store.live.tick($el)">{{ $status->viewers }}</b>
                <span x-text="words[$store.live.viewers === 1 ? 'one' : 'many']">{{ $status->viewers === null ? '' : trans_choice('watching now|watching now', $status->viewers) }}</span>
            </span>
        </p>
    </div>

    {{--
        From lg: the stage and under it the programme on the left, the chat as a
        column on the right, as tall as the window allows below the page title
        and sticky while the programme scrolls by. Below lg: stage, then the
        chat and the programme as two views behind a switch, then sharing; there
        the chat fits the band the root's scroll-padding leaves between the sticky
        header and the tab bar plus dock (app.css), so scrolled into view it sits
        wholly in it. It carries no scroll margin of its own: one on top of the
        root's padding reserved the bars twice and pushed its top under the header.
    --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start lg:gap-x-6 lg:gap-y-5" x-data="{ tab: 'chat' }">
        <div class="flex min-w-0 flex-col gap-4 lg:col-start-1 lg:row-start-1">
            {{-- The stage: edge to edge on a phone, a framed screen from sm. From lg its
                 width follows the window height, so the whole 16:9 picture fits below the
                 header without scrolling (user 2026-09-27: too big on desktop). --}}
            <div class="-mx-4 sm:mx-0 lg:mx-auto lg:w-full lg:max-w-[max(36rem,calc((100dvh-26rem)*16/9))]" wire:ignore x-data="liveStage(@js($stage))" data-live-stage>
                    <div class="flex flex-col gap-2" x-show="onScreen" @unless ($status->live) style="display: none" @endunless data-test="live-stage">
                        <div class="relative aspect-video overflow-hidden bg-black sm:rounded-lg sm:shadow-[0_0_0_1px_var(--color-line)]">
                            <video x-ref="video" class="size-full object-contain" controls playsinline muted preload="none" aria-label="{{ __('TWENTY ONE live stream') }}" data-test="live-stage-video"></video>
                            <div class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-[rgba(10,10,11,.72)] p-4 text-center text-[13px] text-ink-2"
                                 x-show="status !== 'playing' && status !== 'idle'" x-cloak role="status" data-test="live-stage-status">
                                {{-- Autoplay refused even muted: a click plays it (a gesture is always allowed). --}}
                                <button type="button" class="live-play size-16 sm:size-20" x-show="status === 'blocked'" x-on:click="resume()" aria-label="{{ __('Play the live stream') }}" data-test="live-stage-play">
                                    <x-icon name="play" :size="30" />
                                </button>
                                <span class="max-w-[48ch]" x-text="statusText"></span>
                                <x-button variant="quiet" x-show="status === 'ended'" x-on:click="retry()">{{ __('Try again') }}</x-button>
                            </div>
                        </div>
                        <p class="m-0 flex flex-wrap items-center gap-x-3 gap-y-1 px-4 text-xs text-ink-2 sm:px-0" x-show="miniClosed" x-cloak data-test="live-mini-off">
                            {{ __('The mini player is switched off on the other pages.') }}
                            <button type="button" class="inline-flex min-h-11 cursor-pointer items-center text-btc underline decoration-btc/40 underline-offset-2 hover:text-btc-hi sm:min-h-6" x-on:click="showMiniPlayer()">{{ __('Switch it back on') }}</button>
                        </p>
                    </div>
                    <div class="flex aspect-video flex-col items-center justify-center gap-3 bg-bar px-6 text-center sm:rounded-lg sm:shadow-[0_0_0_1px_var(--color-line)]" data-test="live-offline"
                         x-show="! onScreen" @if ($status->live) style="display: none" @endif>
                        <span class="flex h-7 items-center gap-2 rounded-control bg-well px-2.5 text-ink-2">
                            <span class="inline-block size-2 rounded-full bg-edge" aria-hidden="true"></span>
                            <span class="font-display text-[11px] leading-none font-extrabold tracking-[0.06em]">{{ __('Off air') }}</span>
                        </span>
                        <p class="m-0 max-w-[44ch] font-display text-base leading-snug font-bold sm:text-xl">{{ __('The stream is off air right now.') }}</p>
                        @if ($this->next)
                            {{-- The next tournament as a picture (P53): its game's cover, name, start and places. --}}
                            @php($nextPlaces = app(\App\Support\Tournaments\TournamentSignups::class)->places($this->next))
                            <a href="{{ route('tournaments.show', $this->next) }}" class="flex max-w-full items-center gap-3 rounded-md border border-line bg-well p-2 pr-4 text-left text-[13px] text-ink-2 hover:border-edge hover:text-ink-2" data-test="live-offline-next">
                                <x-game-cover :game="$this->next->game" size="thumb" loading="eager" class="w-24 rounded-xs" data-test="live-offline-next-cover" />
                                <span class="flex min-w-0 flex-col gap-1 leading-tight">
                                    <span class="text-xs">{{ __('Next up:') }}</span>
                                    <b class="truncate text-ink">{{ $this->next->name }}</b>
                                    <x-league-time :at="$this->next->starts_at" class="text-xs" />
                                    <span class="flex items-center gap-2 text-xs" data-test="live-offline-next-seats">
                                        <span class="flex h-1 w-20 overflow-hidden rounded-[2px] bg-raised" aria-hidden="true"><span class="block h-full bg-btc" style="width: {{ $nextPlaces['places'] > 0 ? min(100, round(100 * $nextPlaces['taken'] / $nextPlaces['places'], 2)) : 0 }}%"></span></span>
                                        {{ __(':taken of :places spots taken', ['taken' => $nextPlaces['taken'], 'places' => $nextPlaces['places']]) }}
                                    </span>
                                </span>
                            </a>
                        @else
                            <p class="m-0 max-w-[52ch] text-[13px] leading-5 text-ink-2">{{ __('The games go on meanwhile: every running chess game can be watched on the site.') }}</p>
                        @endif
                        <x-button variant="quiet" icon="eye" :href="route('games.index')">{{ __('Watch live games') }}</x-button>
                    </div>
            </div>

            @if ($status->live && $status->title !== null)
                <p class="m-0 text-[13px] leading-5 text-ink" data-test="live-title">{{ $status->title }}</p>
            @endif
        </div>

        {{-- Below lg: which of the two the space under the stage shows. --}}
        <div class="flex gap-1 rounded-lg bg-card p-1 lg:hidden" role="group" aria-label="{{ __('Show under the stream') }}" data-test="live-switch">
            <button type="button" x-on:click="tab = 'chat'; $dispatch('live-chat-shown')" :aria-pressed="(tab === 'chat').toString()" aria-controls="live-chat" data-test="live-switch-chat"
                    class="inline-flex h-11 grow cursor-pointer items-center justify-center gap-2 rounded-control text-[13px]" :class="tab === 'chat' ? 'bg-well font-bold text-ink shadow-ring' : 'text-ink-2'">
                <x-icon name="chat" :size="16" />{{ __('Chat') }}
            </button>
            <button type="button" x-on:click="tab = 'programme'" :aria-pressed="(tab === 'programme').toString()" aria-controls="live-programme" data-test="live-switch-programme"
                    class="inline-flex h-11 grow cursor-pointer items-center justify-center gap-2 rounded-control text-[13px]" :class="tab === 'programme' ? 'bg-well font-bold text-ink shadow-ring' : 'text-ink-2'">
                <x-icon name="list" :size="16" />{{ __('On the stream') }}
            </button>
        </div>

        @include('pages.live.chat', ['chat' => $this->chat, 'class' => 'max-lg:h-[min(34rem,calc(100svh-var(--spacing-below-shell)-var(--tabbar-h)-var(--dock-h)-1rem))] lg:sticky lg:top-below-shell lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:h-[max(26rem,calc(100dvh-var(--live-chat-top,14rem)))]'])

        <div class="flex min-w-0 flex-col gap-5 lg:col-start-1 lg:row-start-2">
            {{-- Zap and share right under the stage: the two ways to support it (user: the zap was buried at the bottom). --}}
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-center">
                @if ($this->zapQr)
                    <section aria-labelledby="live-zap-h" class="flex items-center gap-4 rounded-lg bg-card px-4 py-4 lg:px-5" data-test="live-zap">
                        <img src="{{ $this->zapQr }}" width="112" height="112" alt="{{ __('QR code to zap the stream') }}" class="size-28 shrink-0 rounded-sm bg-white p-2 [image-rendering:pixelated]">
                        <div class="flex min-w-0 flex-col gap-1">
                            <h2 id="live-zap-h" class="m-0 text-[15px] font-bold">{{ __('Zap the stream') }}</h2>
                            <p class="m-0 text-xs leading-5 text-ink-2">{{ __('Scan the code with your Lightning wallet, or tap the bolt in your Nostr client.') }}</p>
                            <p class="m-0 text-xs leading-5 font-bold text-btc-hi" data-test="live-zap-pool">{{ __('Zaps go to the league pool for prizes.') }}</p>
                        </div>
                    </section>
                @endif
                {{-- Share: the page link, the system share sheet (else the clipboard), Telegram. --}}
                <div class="flex flex-col gap-2" data-test="live-share"
                     x-data="{ copied: false, hint: '', canShare: typeof navigator.share === 'function', url: @js($pageUrl), text: @js($shareText),
                               async copy(hint = '') { try { await navigator.clipboard.writeText(this.url); this.copied = true; this.hint = hint; setTimeout(() => { this.copied = false; this.hint = ''; }, 2500); } catch (e) { this.hint = @js(__('Copy did not work here. Select the link and copy it.')); } },
                               async share(hint) { if (this.canShare) { try { await navigator.share({ title: document.title, text: this.text, url: this.url }); return; } catch (e) { if (e?.name === 'AbortError') return; } } await this.copy(hint); } }">
                    <span class="text-xs text-ink-2">{{ __('Bring your friends to the stream') }}</span>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" x-on:click="copy()" class="btn-w inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-3.5 text-[13px] text-ink">
                            <x-icon name="copy" :size="16" /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy link'))">{{ __('Copy link') }}</span>
                        </button>
                        <button type="button" x-on:click="share(@js(__('Link copied. Paste it into a note in your Nostr app.')))" class="btn-w inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-3.5 text-[13px] text-ink"><x-icon name="chat" :size="16" />Nostr</button>
                        <a href="https://t.me/share/url?url={{ urlencode($pageUrl) }}&amp;text={{ urlencode($shareText) }}" target="_blank" rel="noopener noreferrer"
                           class="btn-w inline-flex h-11 items-center gap-2 rounded-md border border-line bg-well px-3.5 text-[13px] text-ink hover:text-ink"><x-icon name="send" :size="16" />Telegram</a>
                    </div>
                    <span class="text-xs text-win" role="status" x-show="hint" x-text="hint" x-cloak></span>
                </div>
            </div>

            {{-- P45: the stream on Nostr (its NIP-53 live event) and a follow of the stream key; its zap QR is right above. --}}
            <x-nostr-bar :bar="\App\Support\Nostr\NostrBar::stream(\App\Support\StreamBot\StreamCoordinates::fromConfig())" />

            {{-- The programme: what is on the stream. Under the stage from lg; the second view below lg. --}}
            <aside id="live-programme" class="flex flex-col rounded-lg bg-card max-lg:order-first" :class="tab === 'programme' ? '' : 'max-lg:hidden'" aria-label="{{ __('On the stream') }}" data-test="live-programme">
                {{-- The 30-second poll refreshes only this island (performance plan P3): the two lists, not the whole page. --}}
                @island(name: 'programme')
                <div class="flex flex-col" wire:poll.30s.visible data-test="live-programme-island">
                <section aria-labelledby="live-now-h" class="flex flex-col gap-2 px-4 py-4 lg:px-5" data-test="live-now">
                    <span class="flex items-baseline justify-between gap-3">
                        <h2 id="live-now-h" class="m-0 text-[15px] font-bold">{{ __('Live now') }}</h2>
                        <a href="{{ route('games.index') }}" class="inline-flex min-h-11 items-center text-xs lg:min-h-6">{{ __('All live games') }}</a>
                    </span>
                    @if ($this->games->isEmpty())
                        <p class="m-0 text-[13px] leading-5 text-ink-2">
                            {{ $this->status->live ? __('No game running. Start a blitz game and it is on the stream.') : __('No game running right now.') }}
                            <a href="{{ route('chess.lobby') }}">{{ __('Play blitz') }}</a>
                        </p>
                    @else
                        <ul class="m-0 flex list-none flex-col p-0">
                            @foreach ($this->games as $game)
                                <li wire:key="live-game-{{ $game->id }}" class="border-t border-hairline first:border-0" data-test="live-now-game">
                                    <a href="{{ route('games.show', $game) }}" class="flex min-h-12 items-center gap-3 py-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink">
                                        <span class="flex min-w-0 grow flex-col gap-1">
                                            <span class="flex min-w-0 items-center gap-1.5"><x-avatar :user="$game->white" :size="18" class="rounded-sm" /><span class="truncate">{{ $game->white->displayName() }}</span></span>
                                            <span class="flex min-w-0 items-center gap-1.5"><x-avatar :user="$game->black" :size="18" class="rounded-sm" /><span class="truncate">{{ $game->black->displayName() }}</span></span>
                                        </span>
                                        <span class="shrink-0 text-right text-xs text-ink-2">{{ \App\Support\Chess\ChessModes::short($game->mode) }}<br>{{ __('move :n', ['n' => intdiv($game->ply, 2) + 1]) }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @if ($this->tournaments->isNotEmpty())
                    <section aria-labelledby="live-tournaments-h" class="flex flex-col gap-2 border-t border-hairline px-4 py-4 lg:px-5" data-test="live-tournaments">
                        <h2 id="live-tournaments-h" class="m-0 text-[15px] font-bold">{{ __('Tournaments running') }}</h2>
                        <ul class="m-0 flex list-none flex-col gap-3 p-0">
                            @foreach ($this->tournaments as $tournament)
                                {{-- The game's cover beside the name (P53): which game runs reads before the words. --}}
                                <li wire:key="live-tournament-{{ $tournament->id }}" class="grid grid-cols-[80px_minmax(0,1fr)] items-center gap-x-3">
                                    <x-game-cover :game="$tournament->game" size="thumb" loading="eager" class="row-span-2 w-20 rounded-xs" data-test="live-tournament-cover" />
                                    <b class="text-[13px] break-words">{{ $tournament->name }}</b>
                                    <span class="flex flex-wrap gap-x-4 text-xs">
                                        <a href="{{ route('tournaments.show', $tournament) }}" class="inline-flex min-h-11 items-center lg:min-h-6">{{ __('Bracket') }}</a>
                                        <a href="{{ route('tournaments.tv', $tournament) }}" class="inline-flex min-h-11 items-center lg:min-h-6" data-test="live-tournament-tv">{{ __('TV view') }}</a>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                </div>
                @endisland
            </aside>
        </div>
    </div>
</div>
