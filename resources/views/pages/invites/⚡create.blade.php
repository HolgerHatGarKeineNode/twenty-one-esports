<?php

use App\Enums\InviteLinkType;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessModes;
use App\Support\GameNames;
use App\Support\Invites\InviteGames;
use App\Support\Invites\InviteLinkRefused;
use App\Support\Invites\InviteLinks;
use App\Support\PageMeta;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * "Invite a friend" (/invite): every game of the registry a friend can be
 * invited to (InviteGames), the game the player is in picked
 * (`?game=` wins, else the context bar's game), and one button that makes
 * the link and opens it to share.
 *
 *  - chess and a board game: a casual game by link, in the mode picked;
 *  - a score game such as Blockfill: "beat my time", no seat, no options;
 *  - a series game: the team challenge needs a lineup and times, so the
 *    button leads to the challenge page, which makes that link.
 *
 * A guest sees the same picker and logs in to make the link.
 */
new #[Layout('layouts::app')] class extends Component {
    #[Url(as: 'game')]
    public string $game = '';

    public string $mode = '';

    public string $error = '';

    public function mount(): void
    {
        $games = app(InviteGames::class);

        if ($games->find($this->game) === null) {
            $this->game = $games->contextGame();
        }

        $this->mode = $games->find($this->game)['modes'][0] ?? '';
    }

    public function pick(string $slug): void
    {
        $game = app(InviteGames::class)->find($slug);

        if ($game === null) {
            return;
        }

        $this->game = $slug;
        $this->mode = $game['modes'][0] ?? '';
        $this->error = '';
    }

    public function createLink(InviteLinks $links): void
    {
        $this->error = '';
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $games = app(InviteGames::class);
        $game = $games->find($this->game);
        $type = $game === null ? null : $games->linkType($this->game, $this->mode);

        if ($game === null || $type === null || ($game['modes'] !== [] && ! in_array($this->mode, $game['modes'], true))) {
            $this->error = __('Pick a game.');

            return;
        }

        try {
            $link = $links->create($user, $type, ['game' => $this->game, 'mode' => $this->mode]);
        } catch (InviteLinkRefused $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->redirectRoute('invites.link', $link);
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Invite a friend'));
        app(PageMeta::class)->noindex = true;
    }
}; ?>

@php
    $games = app(InviteGames::class)->all();
    $picked = $games[$game] ?? null;
    $viewer = auth()->user();
    $kindLabel = fn (array $entry): string => match ($entry['kind']) {
        'score' => __('Beat my time'),
        'series' => __('Team challenge'),
        default => implode(' · ', array_map(fn (string $mode): string => $entry['kind'] === 'chess' ? ChessModes::short($mode === 'daily' ? ChessGame::CORRESPONDENCE : $mode) : GameNames::mode($entry['slug'], $mode), $entry['modes'])),
    };
    $best = $picked !== null && $picked['kind'] === 'score' && $viewer instanceof User ? app(InviteGames::class)->bestLabel($viewer, $picked['slug']) : null;
    $primary = 'btn-p inline-flex min-h-14 w-full cursor-pointer items-center justify-center gap-2.5 rounded-lg bg-btc px-5 text-[15px] font-bold text-on-btc hover:text-on-btc disabled:cursor-wait disabled:opacity-70';
@endphp

<div class="mx-auto flex w-full max-w-[1080px] grow flex-col gap-5 px-4 pt-6 pb-10 lg:gap-8 lg:pt-10 lg:pb-16" data-test="invite-page">
    <h1 class="m-0 font-display text-[28px] leading-tight font-bold lg:text-[40px]">{{ __('Invite a friend') }}</h1>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_380px] lg:gap-10">
        {{-- The picked game and the one next step; first on a phone, beside the list from lg. --}}
        @if ($picked !== null)
            <section id="invite-panel" aria-labelledby="invite-panel-h" class="flex scroll-mt-20 flex-col gap-4 self-start rounded-lg bg-card p-4 shadow-ring-btc lg:sticky lg:top-24 lg:col-start-2 lg:row-start-1 lg:p-6"
                     data-test="invite-panel" data-game="{{ $picked['slug'] }}" data-kind="{{ $picked['kind'] }}" wire:key="panel-{{ $picked['slug'] }}">
                <x-game-cover :game="$picked['slug']" size="card" loading="eager" class="w-full rounded-md" />
                <div class="flex flex-col gap-1">
                    <h2 id="invite-panel-h" class="m-0 font-display text-xl leading-tight font-bold break-words">{{ $picked['kind'] === 'score' ? __('Beat my time in :game', ['game' => $picked['name']]) : $picked['name'] }}</h2>
                    @if ($picked['kind'] === 'score')
                        <p class="m-0 text-[13px] text-ink-2" data-test="invite-best">{{ $best === null ? __('No time this week yet. Your friend sets the first one.') : __('Your best this week: :time', ['time' => $best]) }}</p>
                    @elseif ($picked['kind'] === 'series')
                        <p class="m-0 text-[13px] text-ink-2">{{ __('Your lineup against theirs. Pick the times on the challenge page.') }}</p>
                    @else
                        <p class="m-0 text-[13px] text-ink-2">{{ __('Casual, no rating change.') }}</p>
                    @endif
                </div>

                @if (count($picked['modes']) > 1)
                    <div role="radiogroup" aria-label="{{ __('Game type') }}" @class(['grid gap-2', 'grid-cols-3' => count($picked['modes']) === 3, 'grid-cols-2' => count($picked['modes']) !== 3])>
                        @foreach ($picked['modes'] as $value)
                            <button type="button" role="radio" wire:click="$set('mode', '{{ $value }}')" aria-checked="{{ $mode === $value ? 'true' : 'false' }}" data-test="invite-mode-{{ $value }}"
                                    @class(['min-h-11 cursor-pointer rounded-md border bg-ground px-2 text-[13px] text-ink', 'border-btc' => $mode === $value, 'border-line' => $mode !== $value])>{{ $picked['kind'] === 'chess' ? ($value === 'daily' ? __('Daily, 1 move a day') : ChessModes::label($value)) : GameNames::mode($picked['slug'], $value) }}</button>
                        @endforeach
                    </div>
                @endif

                @if ($error !== '')
                    <p class="m-0 text-[13px] text-loss" role="alert" data-test="invite-error">{{ $error }}</p>
                @endif

                @if ($viewer === null)
                    <a href="{{ route('login') }}" class="{{ $primary }}" data-test="invite-login">{{ __('Log in to invite') }}</a>
                @elseif ($picked['kind'] === 'series')
                    <a href="{{ route('challenges.create', ['game' => $picked['slug']]) }}" class="{{ $primary }}" data-test="invite-series">{{ __('Set up the challenge') }}</a>
                @else
                    <button type="button" wire:click="createLink" wire:loading.attr="disabled" class="{{ $primary }}" data-test="invite-create">
                        <x-icon name="link" :size="18" />{{ $picked['kind'] === 'score' ? __('Create challenge link') : __('Create invite link') }}
                    </button>
                @endif
            </section>
        @endif

        {{-- Every game, the picked one marked. --}}
        <section aria-labelledby="invite-picker-h" class="flex min-w-0 flex-col gap-3 lg:col-start-1 lg:row-start-1">
            <h2 id="invite-picker-h" class="m-0 text-[13px] font-bold text-ink-2">{{ __('Pick a game') }}</h2>
            <div role="radiogroup" aria-labelledby="invite-picker-h" class="grid grid-cols-1 gap-2 sm:grid-cols-2" data-test="invite-picker" data-selected="{{ $game }}">
                @foreach ($games as $entry)
                    <button type="button" role="radio" aria-checked="{{ $entry['slug'] === $game ? 'true' : 'false' }}" wire:click="pick('{{ $entry['slug'] }}')" wire:key="pick-{{ $entry['slug'] }}"
                            x-on:click="if (! matchMedia('(min-width: 1024px)').matches) $nextTick(() => document.getElementById('invite-panel')?.scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }))"
                            data-invite-game="{{ $entry['slug'] }}" data-kind="{{ $entry['kind'] }}"
                            @class(['flex min-h-16 min-w-0 cursor-pointer items-center gap-3 rounded-lg border bg-card p-2 pr-3 text-left text-ink',
                                'border-btc shadow-[inset_0_0_0_1px_#F7931A]' => $entry['slug'] === $game, 'border-line hover:border-edge' => $entry['slug'] !== $game])>
                        <x-game-cover :game="$entry['slug']" size="thumb" class="w-20 shrink-0 rounded-md" />
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <b class="truncate text-sm">{{ $entry['name'] }}</b>
                            <span class="truncate text-xs text-ink-2">{{ $kindLabel($entry) }}</span>
                        </span>
                    </button>
                @endforeach
            </div>
        </section>
    </div>
</div>
