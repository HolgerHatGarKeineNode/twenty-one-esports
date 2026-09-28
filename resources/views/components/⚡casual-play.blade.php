<?php

use App\Enums\Platform;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualLobby;
use App\Support\Series\CasualMatches;
use App\Support\Series\CasualQueue;
use App\Support\Series\SeriesRuleViolation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/*
 * "Play 1v1 casual" (P23 S3): the entry to a casual 1v1 on the Rocket League
 * and EA FC pages and on /play (there with a choice of the game). In the
 * house style of the chess lobby v2: one row of tiles (Find opponent, the
 * platform, Looking to play, the players looking), the state of the player
 * under it (searching, waiting for an invite's answer, locked, playing), the
 * invites received above everything (they expire in two minutes), and the
 * players looking for this game with Invite.
 *
 * Nothing here decides a rule: CasualQueue, CasualInvites and CasualMatches
 * refuse, this shows their message. The gate in front of every committing
 * step is the browser's (resources/js/casualPlay.js signerGate): no signer
 * that encrypts with NIP-44, no search, because the lobby travels only in
 * the encrypted match chat. With no chat relay the module offers nothing.
 * Every change tells the ready prompt (components/⚡casual-ready), which
 * shows a pairing at once and polls while the player waits.
 */
new class extends Component
{
    /** A module that lets the player pick the game (/play). */
    #[Locked]
    public bool $choose = false;

    public string $game = '';

    public string $error = '';

    public function mount(?string $game = null): void
    {
        $games = CasualLobby::games();
        $this->choose = $game === null;
        $looking = (string) auth()->user()?->looking_to_play;
        $lookingGame = str_ends_with($looking, '/'.CasualMatches::mode()) ? explode('/', $looking)[0] : null;

        $this->game = $game ?? (in_array($lookingGame, $games, true) ? $lookingGame : ($games[0] ?? ''));
    }

    public function pickGame(string $game): void
    {
        if ($this->choose && CasualLobby::offers($game)) {
            $this->game = $game;
            $this->error = '';
        }
    }

    public function setPlatform(string $platform): void
    {
        $this->attempt(function (User $user) use ($platform): void {
            $settings = $this->settings;
            $this->save($user, Platform::tryFrom($platform) ?? $settings['platform'], $settings['crossplay']);
        });
    }

    public function setCrossplay(bool $crossplay): void
    {
        $this->attempt(fn (User $user) => $this->save($user, $this->settings['platform'], $crossplay));
    }

    public function find(): void
    {
        $this->attempt(function (User $user): void {
            $this->assertChatOn();
            $settings = $this->settings;
            app(CasualQueue::class)->join($user, $this->game, $settings['platform'], $settings['crossplay']);
        });
    }

    public function cancel(): void
    {
        $this->attempt(fn (User $user) => app(CasualQueue::class)->leave($user));
    }

    /**
     * "Looking to play" for this game: the wanted state, never a flip (the
     * chess lobby's reason: Livewire squashes identical queued calls).
     * Off leaves another game's choice alone. Answers with the stored state.
     */
    #[Renderless]
    public function setLooking(bool $looking): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! CasualLobby::chatOn()) {
            return false;
        }

        $mine = $this->game.'/'.CasualMatches::mode();

        if ($looking || $user->looking_to_play === $mine) {
            app(CasualQueue::class)->setLooking($user, $looking ? $this->game : null);
        }

        return $user->refresh()->looking_to_play === $mine;
    }

    public function invite(int $userId): void
    {
        $this->attempt(function (User $user) use ($userId): void {
            $this->assertChatOn();
            $settings = $this->settings;
            app(CasualInvites::class)->invite($user, User::query()->findOrFail($userId), $this->game, $settings['platform'], $settings['crossplay']);
        });
    }

    public function withdraw(): void
    {
        $this->attempt(fn (User $user) => app(CasualInvites::class)->withdrawOutgoing($user));
    }

    public function accept(int $inviteId): void
    {
        $this->attempt(function (User $user) use ($inviteId): void {
            $this->assertChatOn();
            $invite = SeriesInvite::query()->findOrFail($inviteId);
            $settings = app(CasualLobby::class)->settings($user, $invite->game);
            app(CasualInvites::class)->accept($invite, $user, $settings['platform'], $settings['crossplay']);
        });
    }

    public function decline(int $inviteId): void
    {
        $this->attempt(fn (User $user) => app(CasualInvites::class)->close(SeriesInvite::query()->findOrFail($inviteId), $user));
    }

    /** The ready prompt or a push saw a change: render again. */
    #[On('casual-refresh')]
    public function refresh(): void
    {
        $this->forget();
    }

    /**
     * @return array{platform: Platform, crossplay: bool}
     */
    #[Computed]
    public function settings(): array
    {
        $user = auth()->user();

        return $user instanceof User ? app(CasualLobby::class)->settings($user, $this->game) : ['platform' => Platform::Pc, 'crossplay' => true];
    }

    #[Computed]
    public function entry(): ?SeriesQueueEntry
    {
        $user = auth()->user();

        return $user instanceof User ? app(CasualQueue::class)->entryOf($user) : null;
    }

    #[Computed]
    public function outgoing(): ?SeriesInvite
    {
        $user = auth()->user();

        return $user instanceof User ? app(CasualInvites::class)->outgoing($user) : null;
    }

    /**
     * Invites received: this game's here, every game's on /play.
     *
     * @return Collection<int, SeriesInvite>
     */
    #[Computed]
    public function incoming(): Collection
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(CasualInvites::class)->incoming($user)->filter(fn (SeriesInvite $invite): bool => $this->choose || $invite->game === $this->game)->values()
            : collect();
    }

    #[Computed]
    public function lockedUntil(): ?CarbonInterface
    {
        $user = auth()->user();

        return $user instanceof User ? app(CasualMatches::class)->lockedUntil($user) : null;
    }

    #[Computed]
    public function running(): ?SeriesMatch
    {
        $user = auth()->user();

        return $user instanceof User ? app(CasualMatches::class)->activeMatchOf($user) : null;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function looking(): Collection
    {
        return app(CasualLobby::class)->lookingPlayers($this->game, auth()->user());
    }

    #[Computed]
    public function searching(): int
    {
        return app(CasualLobby::class)->searching($this->game);
    }

    /**
     * @throws SeriesRuleViolation
     */
    private function assertChatOn(): void
    {
        if (! CasualLobby::chatOn()) {
            throw new SeriesRuleViolation('chat_off', __('Casual 1v1 is off here: it needs the encrypted match chat.'));
        }
    }

    private function save(User $user, Platform $platform, bool $crossplay): void
    {
        app(CasualLobby::class)->remember($user, $this->game, $platform, $crossplay);

        // Searching this game already: the queue takes the new choice (and may pair with it).
        if ($this->entry?->game === $this->game) {
            app(CasualQueue::class)->join($user, $this->game, $platform, $crossplay);
        }
    }

    /**
     * @param  Closure(User): mixed  $action
     */
    private function attempt(Closure $action): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login');

            return;
        }

        $this->error = '';

        try {
            $action($user);
        } catch (SeriesRuleViolation $refused) {
            $this->error = $refused->getMessage();
        }

        $this->forget();
        // The ready prompt shows a pairing at once, and starts or stops its poll.
        $this->dispatch('casual-changed');
    }

    private function forget(): void
    {
        unset($this->settings, $this->entry, $this->outgoing, $this->incoming, $this->lockedUntil, $this->running, $this->looking, $this->searching);
    }
}; ?>

@php
    use App\Support\GameNames;
    use App\Support\PreSeason;

    $user = auth()->user();
    $chatOn = CasualLobby::chatOn();
    $games = CasualLobby::games();
    $gameName = GameNames::game($game);
    $assets = app(App\Games\GameRegistry::class)->find($game)?->assets();
    $settings = $this->settings;
    $entry = $this->entry;
    $searchingHere = $entry !== null && $entry->game === $game;
    $outgoing = $this->outgoing;
    $incoming = $this->incoming;
    $locked = $this->lockedUntil;
    $running = $this->running;
    $looking = $this->looking;
    $zone = PreSeason::timezoneFor($user);
    $nowMs = (int) now()->getTimestampMs();
    $lookingHere = $user?->looking_to_play === $game.'/'.CasualMatches::mode();
    $excluded = (array) config('esports.casual.crossplay_excluded.'.$game, []);
    $crossplayOff = in_array($settings['platform']->value, $excluded, true);
    $tag = 'inline-block rounded-xs px-1.5 text-[11px] leading-4 font-bold';
    $config = [
        'userId' => $user?->id,
        'looking' => $lookingHere,
        'serverNow' => $nowMs,
        'poll' => 30,
        'messages' => App\Support\Nostr\SignerMessages::labels() + ['noNip44' => __('Your signer cannot encrypt messages (NIP-44), so you cannot get the lobby. Use a Nostr extension or signer app with NIP-44 to play casual 1v1.')],
    ];
    $inviteTotal = (int) config('esports.casual.invite_seconds') * 1000;
@endphp

<section aria-labelledby="casual-h" id="casual" class="flex scroll-mt-24 flex-col gap-3 rounded-lg bg-card p-3 shadow-ring lg:gap-4 lg:p-4"
         style="--game: {{ $assets?->colour ?? 'var(--color-btc)' }}" x-data="casualPlay(@js($config))"
         data-test="casual-play" data-game="{{ $game }}">
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <h2 id="casual-h" class="m-0 flex items-center gap-2 font-display text-lg leading-tight font-bold lg:text-xl">
            <span class="block h-5 w-1 shrink-0 rounded-xs bg-(--game)" aria-hidden="true"></span>{{ __('Play 1v1 casual') }}
        </h2>
        <span class="text-xs text-ink-2" data-test="casual-counts">{{ trans_choice(':count searching|:count searching', $this->searching) }}, {{ trans_choice(':count looking|:count looking', $looking->count()) }}</span>
    </div>

    @if ($choose)
        {{-- /play: the game first. --}}
        <div role="radiogroup" aria-label="{{ __('Game') }}" class="grid grid-cols-3 gap-2" data-test="casual-games">
            @foreach ($games as $slug)
                <button type="button" role="radio" aria-checked="{{ $slug === $game ? 'true' : 'false' }}" wire:click="pickGame('{{ $slug }}')" data-test="casual-game-{{ $slug }}"
                        @class(['flex min-h-11 min-w-0 cursor-pointer flex-col items-stretch gap-1.5 rounded-md p-1.5 text-left text-xs', 'bg-raised shadow-[inset_0_0_0_2px_var(--color-btc)]' => $slug === $game, 'bg-well shadow-ring hover:bg-row-hover' => $slug !== $game])>
                    <x-game-cover :game="$slug" size="thumb" loading="eager" class="w-full rounded-sm max-sm:hidden" />
                    <b class="truncate px-1 leading-5">{{ GameNames::game($slug) }}</b>
                </button>
            @endforeach
        </div>
    @endif

    @if (! $chatOn)
        <p class="m-0 rounded-md bg-well px-4 py-3 text-[13px] leading-normal text-ink-2" data-test="casual-off"><span class="block max-w-[68ch]">{{ __('Casual 1v1 is off here: it needs the encrypted match chat, where the lobby travels, and this site runs no chat relay right now.') }}</span></p>
    @else
        @if ($error)
            <p role="alert" class="m-0 rounded-md bg-loss-tint px-4 py-3 text-[13px] text-loss" data-test="casual-error">{{ $error }}</p>
        @endif
        <div x-show="gateError" x-cloak role="alert" class="flex flex-col gap-1 rounded-md bg-loss-tint px-4 py-3 text-[13px] leading-normal" data-test="casual-gate">
            <b class="text-loss" x-text="gateError"></b>
            <span class="text-ink-2">{{ __('The lobby reaches your opponent only through the encrypted match chat. A browser extension such as Alby or nos2x, or a signer app such as Amber with a bunker link, can do it.') }}</span>
        </div>

        {{-- Invites received: they expire in two minutes, so they come first. --}}
        @foreach ($incoming as $invite)
            <div wire:key="casual-in-{{ $invite->id }}" class="flex flex-col gap-3 rounded-md bg-btc-chip p-3 shadow-ring-btc lg:flex-row lg:items-center" data-test="casual-incoming">
                <span class="flex min-w-0 grow items-center gap-3">
                    <x-player-link :user="$invite->inviter" class="flex size-11 shrink-0 items-center justify-center"><x-avatar :user="$invite->inviter" :size="40" class="rounded-md" /></x-player-link>
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <b class="text-[15px] break-words">{{ __(':name invites you', ['name' => $invite->inviter->displayName()]) }}</b>
                        <span class="text-xs text-ink-2">{{ GameNames::game($invite->game) }} 1v1, {{ $invite->platform->label() }}@if ($invite->crossplay), {{ __('crossplay') }}@endif</span>
                    </span>
                </span>
                <span class="flex items-center gap-2">
                    <b class="w-12 shrink-0 text-center font-display text-[15px] tabular-nums" role="timer" x-text="left({{ $invite->expires_at->getTimestampMs() }})" data-test="casual-incoming-clock"></b>
                    <x-button variant="quiet" wire:click="decline({{ $invite->id }})" class="grow lg:grow-0" data-test="casual-decline">{{ __('Decline') }}</x-button>
                    <x-button icon="check" x-on:click="act('accept', {{ $invite->id }})" ::disabled="busy" class="grow lg:grow-0" data-test="casual-accept">{{ __('Accept') }}</x-button>
                </span>
            </div>
        @endforeach

        @guest
            <div class="flex flex-col gap-3 rounded-md bg-well p-4 sm:flex-row sm:items-center">
                <p class="m-0 max-w-[68ch] grow text-[13px] leading-normal text-ink-2">{{ __('Find an opponent for :game in seconds: one match, no clan needed.', ['game' => $gameName]) }}</p>
                <x-button :href="route('login')" data-test="casual-login">{{ __('Log in to play') }}</x-button>
            </div>
        @else
            @if ($running)
                {{-- One match at a time. --}}
                <a href="{{ route('matches.room', $running) }}" class="flex items-center gap-3 rounded-md bg-btc-chip px-4 py-3 text-ink shadow-ring-btc hover:text-ink" data-test="casual-running">
                    <span class="size-2 shrink-0 animate-live rounded-full bg-btc" aria-hidden="true"></span>
                    <span class="flex min-w-0 grow flex-col"><b class="text-[15px]">{{ __('Your match :number is on', ['number' => $running->label()]) }}</b><span class="truncate text-xs text-ink-2">{{ __('against :name', ['name' => $running->sideName($running->isRosterSideMember('challenger', $user) ? 'challenged' : 'challenger')]) }}</span></span>
                    <span class="inline-flex h-11 shrink-0 items-center rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc">{{ __('Open match room') }}</span>
                </a>
            @elseif ($locked)
                <p class="m-0 flex items-center gap-3 rounded-md bg-loss-tint px-4 py-3 text-[13px] leading-normal" data-test="casual-locked">
                    <x-icon name="lock" :size="18" class="shrink-0 text-loss" />
                    <span><b class="text-ink">{{ __('You can play again at :time.', ['time' => $locked->copy()->timezone($zone)->format('H:i')]) }}</b> <span class="text-ink-2">{{ __('After :noshows missed matches within :hours hours, casual 1v1 pauses for :minutes minutes.', ['noshows' => (int) \App\Support\Settings\LeagueSettings::get('esports.casual.lock.noshows'), 'hours' => (int) \App\Support\Settings\LeagueSettings::get('esports.casual.lock.window_hours'), 'minutes' => (int) \App\Support\Settings\LeagueSettings::get('esports.casual.lock.minutes')]) }}</span></span>
                </p>
            @endif

            {{-- The tiles: find, platform, looking to play, the players looking. --}}
            <ul role="list" class="m-0 grid list-none grid-cols-2 gap-2 p-0 lg:grid-cols-4 lg:gap-3" data-test="casual-tiles" x-data="{ panel: null }">
                <li>
                    @if ($searchingHere)
                        <x-chess.lobby-tile :label="__('Find opponent')" variant="primary" x-on:click="$root.querySelector('[data-test=casual-cancel]')?.focus()" aria-describedby="casual-searching" data-test="casual-find">
                            <x-slot:glyph><span class="font-display text-lg leading-none font-bold lg:text-[1.75rem]">1v1</span></x-slot:glyph>
                            <x-slot:meta><span class="{{ $tag }} bg-btc text-on-btc" data-test="casual-find-state">{{ __('You are searching') }}</span></x-slot:meta>
                        </x-chess.lobby-tile>
                    @else
                        <x-chess.lobby-tile :label="__('Find opponent')" variant="primary" x-on:click="act('find')" x-bind:disabled="busy || {{ $running !== null || $locked !== null ? 'true' : 'false' }}" :disabled="$running !== null || $locked !== null" class="disabled:cursor-not-allowed disabled:opacity-60" data-test="casual-find">
                            <x-slot:glyph><span class="font-display text-lg leading-none font-bold lg:text-[1.75rem]">1v1</span></x-slot:glyph>
                            <x-slot:meta><b class="text-ink">{{ $this->searching }}</b> {{ __('searching') }}</x-slot:meta>
                        </x-chess.lobby-tile>
                    @endif
                </li>
                <li>
                    <x-chess.lobby-tile :label="$settings['platform']->label()" icon="settings" x-on:click="panel = panel === 'platform' ? null : 'platform'" x-bind:aria-expanded="(panel === 'platform').toString()" aria-expanded="false" aria-controls="casual-platform" data-test="casual-platform-tile">
                        <x-slot:meta>{{ $settings['crossplay'] && ! $crossplayOff ? __('Crossplay on') : __('Crossplay off') }}</x-slot:meta>
                    </x-chess.lobby-tile>
                </li>
                <li>
                    <x-chess.lobby-tile :label="__('Looking to play')" icon="bell" role="switch" wire:ignore x-on:click="toggleLooking()" x-bind:aria-checked="looking ? 'true' : 'false'" aria-checked="{{ $lookingHere ? 'true' : 'false' }}" data-test="casual-looking">
                        <x-slot:meta><b x-bind:class="looking ? 'text-win' : 'text-ink-2'" x-text="looking ? @js(__('On')) : @js(__('Off'))" data-test="casual-looking-state">{{ $lookingHere ? __('On') : __('Off') }}</b></x-slot:meta>
                    </x-chess.lobby-tile>
                </li>
                <li>
                    <x-chess.lobby-tile :label="__('Invite a player')" icon="send" aria-controls="casual-list" data-test="casual-invite-tile"
                                        x-on:click="document.getElementById('casual-list').scrollIntoView({ block: 'nearest', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); ($root.querySelector('[data-test=casual-invite]') ?? document.getElementById('casual-list-h')).focus({ preventScroll: true })">
                        <x-slot:meta><b class="text-ink">{{ $looking->count() }}</b> {{ __('looking') }}</x-slot:meta>
                    </x-chess.lobby-tile>
                </li>

                {{-- Platform and crossplay, opened by their tile; remembered for this game. --}}
                <li id="casual-platform" x-show="panel === 'platform'" x-cloak class="col-span-2 flex flex-col gap-3 rounded-md bg-well p-3 shadow-ring lg:col-span-4 lg:flex-row lg:items-center" data-test="casual-platform">
                    <div role="radiogroup" aria-label="{{ __('Your platform') }}" class="grid grow grid-cols-2 gap-1 rounded-md bg-ground p-1 shadow-ring sm:grid-cols-4">
                        @foreach (App\Enums\Platform::cases() as $platform)
                            <button type="button" role="radio" aria-checked="{{ $settings['platform'] === $platform ? 'true' : 'false' }}" wire:click="setPlatform('{{ $platform->value }}')" data-test="casual-platform-{{ $platform->value }}"
                                    @class(['flex min-h-11 cursor-pointer items-center justify-center rounded-sm px-2 text-[13px] font-bold', 'bg-raised text-btc-hi shadow-[inset_0_-2px_0_var(--color-btc)]' => $settings['platform'] === $platform, 'text-ink' => $settings['platform'] !== $platform])>{{ $platform->label() }}</button>
                        @endforeach
                    </div>
                    <button type="button" role="switch" aria-checked="{{ $settings['crossplay'] && ! $crossplayOff ? 'true' : 'false' }}" wire:click="setCrossplay({{ $settings['crossplay'] ? 'false' : 'true' }})" @disabled($crossplayOff) data-test="casual-crossplay"
                            class="flex min-h-11 shrink-0 cursor-pointer items-center gap-2.5 rounded-md border border-line bg-card px-3 text-[13px] text-ink disabled:cursor-not-allowed disabled:text-ink-3">
                        <span @class(['relative h-5 w-9 shrink-0 rounded-full', 'bg-btc' => $settings['crossplay'] && ! $crossplayOff, 'bg-raised shadow-ring' => ! $settings['crossplay'] || $crossplayOff])><span @class(['absolute top-0.5 size-4 rounded-full bg-ink', 'left-[18px]' => $settings['crossplay'] && ! $crossplayOff, 'left-0.5' => ! $settings['crossplay'] || $crossplayOff])></span></span>
                        {{ __('Crossplay') }}
                    </button>
                    @if ($crossplayOff)
                        <span class="text-xs text-ink-2 lg:max-w-56">{{ __(':game on :platform plays only against the same platform.', ['game' => $gameName, 'platform' => $settings['platform']->label()]) }}</span>
                    @endif
                </li>
            </ul>
            <p x-show="lookingFailed" x-cloak role="alert" class="m-0 text-[13px] text-loss">{{ __('That did not save. The switch is back where it was, please try again.') }}</p>

            @if ($searchingHere)
                <div id="casual-searching" role="status" aria-live="polite" class="flex flex-col gap-3 rounded-md bg-btc-chip p-4 shadow-ring-btc sm:flex-row sm:items-center" data-test="casual-searching">
                    <span class="flex min-w-0 grow flex-col gap-2">
                        <span class="flex flex-wrap items-baseline gap-x-3 gap-y-1"><b class="font-display text-base">{{ __('Finding an opponent') }}</b><span class="text-xs text-ink-2">{{ $gameName }} 1v1, {{ $entry->platform->label() }}@if ($entry->crossplay && ! $crossplayOff), {{ __('crossplay') }}@endif</span></span>
                        <span class="block h-1 w-full overflow-hidden rounded-xs bg-raised"><span class="sweep block h-1 w-2/5 rounded-xs bg-btc"></span></span>
                    </span>
                    <span class="flex items-center gap-3">
                        <b class="font-display text-xl tabular-nums" role="timer" aria-label="{{ __('Searching for') }}" x-text="since({{ $entry->joined_at->getTimestampMs() }})" data-test="casual-search-time"></b>
                        <x-button variant="quiet" wire:click="cancel" class="grow sm:grow-0" data-test="casual-cancel">{{ __('Cancel') }}</x-button>
                    </span>
                </div>
            @elseif ($outgoing)
                <div role="status" class="flex flex-col gap-3 rounded-md bg-well p-4 shadow-ring sm:flex-row sm:items-center" data-test="casual-waiting">
                    <span class="flex min-w-0 grow items-center gap-3">
                        <x-avatar :user="$outgoing->invitee" :size="32" class="rounded-md" />
                        <span class="flex min-w-0 flex-col gap-1.5">
                            <b class="truncate text-[15px]">{{ __('Waiting for :name', ['name' => $outgoing->invitee->displayName()]) }}</b>
                            <span class="block h-1 w-full max-w-72 overflow-hidden rounded-xs bg-raised" aria-hidden="true"><span class="block h-1 rounded-xs bg-btc" x-bind:style="{ width: (share({{ $outgoing->expires_at->getTimestampMs() }}, {{ max(1, $outgoing->expires_at->getTimestampMs() - ($outgoing->created_at?->getTimestampMs() ?? $nowMs)) }}) * 100) + '%' }"></span></span>
                        </span>
                    </span>
                    <span class="flex items-center gap-3">
                        <b class="font-display text-xl tabular-nums" role="timer" x-text="left({{ $outgoing->expires_at->getTimestampMs() }})" data-test="casual-waiting-clock"></b>
                        <button type="button" wire:click="withdraw" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss sm:grow-0" data-test="casual-withdraw">{{ __('Withdraw') }}</button>
                    </span>
                </div>
            @endif

            {{-- Who looks for this game right now. --}}
            <div id="casual-list" class="flex scroll-mt-24 flex-col gap-1" data-test="casual-list">
                <span class="flex flex-wrap items-center justify-between gap-x-3">
                    <h3 id="casual-list-h" tabindex="-1" class="m-0 flex items-center gap-2 text-[13px] font-bold"><span class="size-2 rounded-full bg-win" aria-hidden="true"></span>{{ __('Looking for :game', ['game' => $gameName]) }}</h3>
                    {{-- Not now, but at a set time (P23 S4): the challenge form, this game preselected. --}}
                    <a href="{{ route('challenges.casual', ['game' => $game]) }}" class="inline-flex min-h-11 items-center gap-1.5 text-[13px]" data-test="casual-schedule"><x-icon name="calendar" :size="14" />{{ __('Schedule a 1v1') }}</a>
                </span>
                @if ($looking->isEmpty())
                    <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2" data-test="casual-list-empty">{{ __('Nobody else is looking right now. Find an opponent to join the queue, or switch on Looking to play to get invites.') }}</p>
                @else
                    <ul role="list" class="m-0 grid list-none grid-cols-1 gap-x-4 p-0 lg:grid-cols-2">
                        @foreach ($looking as $player)
                            <li wire:key="casual-looking-{{ $player->id }}" class="flex min-h-14 items-center gap-3 border-b border-hairline text-[13px]" data-test="casual-player">
                                <x-player-link :user="$player" class="flex min-h-11 min-w-0 grow items-center gap-3 text-ink hover:text-ink">
                                    <x-avatar :user="$player" :size="28" class="rounded-sm" /><b class="min-w-0 truncate">{{ $player->displayName() }}</b>
                                </x-player-link>
                                <a href="{{ route('challenges.casual', ['to' => $player->id, 'game' => $game]) }}" aria-label="{{ __('Schedule a 1v1 with :name', ['name' => $player->displayName()]) }}" title="{{ __('Schedule a 1v1') }}"
                                   class="btn-w inline-flex size-11 shrink-0 items-center justify-center rounded-md border border-line bg-well text-ink-2 hover:text-ink" data-test="casual-schedule-player"><x-icon name="calendar" :size="16" /></a>
                                @if ($outgoing?->invitee_id === $player->id)
                                    <span class="shrink-0 text-xs text-ink-2" data-test="casual-invited">{{ __('Invited') }}</span>
                                @elseif (! $running && ! $locked)
                                    <button type="button" x-on:click="act('invite', {{ $player->id }})" x-bind:disabled="busy" class="btn-w inline-flex h-11 shrink-0 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="casual-invite">{{ __('Invite') }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endguest
    @endif
</section>
