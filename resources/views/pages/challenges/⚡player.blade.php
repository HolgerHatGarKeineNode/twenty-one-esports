<?php

use App\Games\BoardGame;
use App\Games\GameRegistry;
use App\Models\User;
use App\Support\GameNames;
use App\Support\Nostr\NostrKeys;
use App\Support\Series\CasualLobby;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * "Challenge a player", for every game (DerCaddy, 2026-11-13: "Challenge :name"
 * on a profile led to daily chess only, instead of letting you pick the game).
 * The games a player can be challenged to directly, each to the page that
 * sends that challenge, the opponent filled in:
 *
 *  - daily chess (pages::chess.challenge),
 *  - each board game switched on with a correspondence mode
 *    (pages::board.correspondence; Blockli, Blockspace, nine men's morris,
 *    checkers),
 *  - a scheduled casual 1v1 in the games of `esports.casual.games`
 *    (pages::challenges.casual; Rocket League, EA FC).
 *
 * Nothing is sent here. `?to=` is an npub or a user id, as on those pages, and
 * is handed on; an unknown one, or the player's own, leaves the form empty
 * there. Live games (blitz, rapid) have no direct challenge: they are played
 * in a game's lobby, with an invite to a friend who is online. With one game
 * to offer (board games off, no casual 1v1) the page goes straight to it.
 */
new #[Title('Challenge a player')] #[Layout('layouts::app')] class extends Component {
    /** The player to challenge: an npub (a profile's link) or a user id; empty for "anyone". */
    #[Url(as: 'to')]
    public string $to = '';

    public function mount(): void
    {
        $options = $this->options;

        if (count($options) === 1) {
            $this->redirect($options[0]['href']);
        }
    }

    /** The player `to` names, never the one looking; null for none. */
    #[Computed]
    public function opponent(): ?User
    {
        $to = trim($this->to);
        $user = match (true) {
            $to === '' => null,
            ctype_digit($to) => User::query()->find((int) $to),
            NostrKeys::npubToHex($to) !== null => User::query()->where('npub', $to)->first(),
            default => null,
        };

        return $user instanceof User && ! $user->is(auth()->user()) ? $user : null;
    }

    /**
     * The challenges on offer, in the registry's order of games, the casual 1v1 last.
     *
     * @return list<array{key: string, game: ?string, games: list<string>, title: string, line: string, href: string}>
     */
    #[Computed]
    public function options(): array
    {
        $registry = app(GameRegistry::class);
        $to = $this->opponent === null ? [] : ['to' => $this->opponent->npub];
        $options = [];

        foreach ($registry->all() as $slug => $game) {
            if ($slug === 'chess') {
                $options[] = ['key' => 'chess', 'game' => 'chess', 'games' => [], 'title' => GameNames::game('chess'),
                    'line' => __('Correspondence, one move a day'), 'href' => route('chess.challenge', $to)];
            } elseif ($game instanceof BoardGame && $registry->mode($slug, \App\Models\BoardGame::CORRESPONDENCE) !== null && \Illuminate\Support\Facades\Route::has('board.correspondence')) {
                $options[] = ['key' => $slug, 'game' => $slug, 'games' => [], 'title' => GameNames::game($slug),
                    'line' => __('Correspondence, one move a day'), 'href' => route('board.correspondence', ['board' => $slug, ...$to])];
            }
        }

        if (($casual = CasualLobby::games()) !== []) {
            // A calendar instead of four covers, the first game by name: the list of games is on the next page.
            $games = count($casual) === 1 ? GameNames::game($casual[0]) : __(':game and :count more', ['game' => GameNames::game($casual[0]), 'count' => count($casual) - 1]);
            $options[] = ['key' => 'casual', 'game' => null, 'games' => [], 'title' => __('Schedule a 1v1'),
                'line' => $games.' · '.__('a time you agree on'),
                'href' => route('challenges.casual', array_filter(['to' => $this->opponent?->id]))];
        }

        return $options;
    }
}; ?>

@php($opponent = $this->opponent)
<div class="flex grow flex-col gap-5 px-4 pb-8 lg:mx-auto lg:w-full lg:max-w-[880px] lg:px-4 lg:pb-10" data-test="challenge-player">
    <div class="flex flex-col gap-1">
        <h1 class="m-0 flex min-w-0 items-center gap-3 font-display text-[24px] leading-tight font-bold sm:text-[28px] lg:text-[32px]">
            @if ($opponent)
                <x-avatar :user="$opponent" :size="36" class="shrink-0 rounded-md" />
                <span class="min-w-0 wrap-anywhere">{{ __('Challenge :name', ['name' => $opponent->displayName()]) }}</span>
            @else
                {{ __('Challenge a player') }}
            @endif
        </h1>
        <p class="m-0 text-[13px] text-ink-2">{{ __('Pick the game. Nothing is sent yet: the next page shows the challenge before you send it.') }}</p>
    </div>

    <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2" data-test="challenge-options">
        @foreach ($this->options as $option)
            <li>
                <a href="{{ $option['href'] }}" @navigate($option['href']) data-test="challenge-option-{{ $option['key'] }}"
                   class="flex min-h-[72px] items-center gap-4 rounded-lg bg-card p-3 text-ink shadow-ring hover:bg-row-hover hover:text-ink">
                    @if ($option['game'] !== null)
                        <x-game-cover :game="$option['game']" :games="$option['games']" size="thumb" class="w-24 rounded-md" />
                    @else
                        <span class="flex aspect-video w-24 shrink-0 items-center justify-center rounded-md bg-well text-ink-2" aria-hidden="true"><x-icon name="calendar" :size="24" /></span>
                    @endif
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span class="truncate text-[15px] font-bold">{{ $option['title'] }}</span>
                        <span class="text-[13px] leading-snug text-ink-2">{{ $option['line'] }}</span>
                    </span>
                    <x-icon name="chevron-down" :size="18" class="ms-auto shrink-0 -rotate-90 text-ink-3" />
                </a>
            </li>
        @endforeach
    </ul>

    <p class="m-0 text-[13px] leading-normal text-ink-2">
        {{ __('Blitz and rapid are played live: invite a friend who is online in the game\'s lobby.') }}
        <a href="{{ route('play') }}" @navigate(route('play')) class="inline-flex min-h-11 items-center">{{ __('All games and modes') }}</a>
    </p>
</div>
