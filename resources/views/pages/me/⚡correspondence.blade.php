<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\ChessChallenge;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\DailyChallenges;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * "Your daily games" (ChessCorrespondenceList.dc.html): the numbers, the
 * games where it is your move and where it is theirs, the finished ones.
 * Open challenges (ChessOverlays "Daily challenge received") sit above the
 * games; the list design has no place for them.
 *
 * The player's correspondence board games (plan "Mühle und Dame", P8) are
 * listed below, each leading to its board, with the board challenges to
 * answer; they are played and answered on the board games' own pages.
 */
new #[Title('Daily chess')] #[Layout('layouts::app', ['section' => 'chess', 'scripts' => ['resources/js/chess.js']])] class extends Component {
    public string $error = '';

    public function acceptChallenge(int $id): void
    {
        $this->attempt(function (User $user) use ($id): void {
            $game = app(DailyChallenges::class)->accept(ChessChallenge::query()->findOrFail($id), $user);
            $this->redirectRoute('games.show', ['game' => $game]);
        });
    }

    public function declineChallenge(int $id): void
    {
        $this->attempt(fn (User $user) => app(DailyChallenges::class)->close(ChessChallenge::query()->findOrFail($id), $user));
    }

    /**
     * @return Collection<int, ChessGame>
     */
    #[Computed]
    public function running(): Collection
    {
        return ChessGame::query()->daily()->playedBy($this->user())->where('status', ChessGameStatus::Active)
            ->with(['white.clanMember.clan', 'black.clanMember.clan'])
            ->orderBy('deadline_ms')
            ->get();
    }

    /**
     * @return Collection<int, ChessGame>
     */
    #[Computed]
    public function finished(): Collection
    {
        return ChessGame::query()->daily()->playedBy($this->user())->where('status', '!=', ChessGameStatus::Active)
            ->with(['white', 'black'])
            ->latest('ended_at')
            ->limit(20)
            ->get();
    }

    /**
     * @return Collection<int, ChessChallenge>
     */
    #[Computed]
    public function incoming(): Collection
    {
        return app(DailyChallenges::class)->incoming($this->user());
    }

    /**
     * @return Collection<int, ChessChallenge>
     */
    #[Computed]
    public function outgoing(): Collection
    {
        return app(DailyChallenges::class)->outgoing($this->user());
    }

    /**
     * The running correspondence board games of this player (P8), theirs to
     * move first, and how many board challenges wait per board game; empty
     * while the board games are off.
     *
     * @return array{games: Collection<int, \App\Models\BoardGame>, challenges: array<string, int>}
     */
    #[Computed]
    public function boardCorrespondence(): array
    {
        $user = $this->user();

        if (! \Illuminate\Support\Facades\Route::has('board.correspondence')) {
            return ['games' => collect(), 'challenges' => []];
        }

        $registry = app(\App\Games\GameRegistry::class);
        $games = \App\Models\BoardGame::query()->correspondence()->playedBy($user)->where('status', \App\Enums\BoardGameStatus::Active)
            ->with(['white', 'black'])->orderBy('deadline_ms')->get()
            ->filter(fn (\App\Models\BoardGame $game): bool => $registry->isBoard($game->game))
            ->sortBy(fn (\App\Models\BoardGame $game): int => $game->turn === $game->colorOf($user) ? 0 : 1)->values();
        $challenges = app(\App\Support\Board\BoardChallenges::class)->incoming($user)
            ->filter(fn (\App\Models\BoardChallenge $challenge): bool => $registry->isBoard($challenge->game))
            ->countBy('game')->all();

        return ['games' => $games, 'challenges' => $challenges];
    }

    public function withdrawChallenge(int $id): void
    {
        $this->attempt(fn (User $user) => app(DailyChallenges::class)->close(ChessChallenge::query()->findOrFail($id), $user));
    }

    /**
     * One game card (mine or theirs).
     *
     * @return array<string, mixed>
     */
    public function card(ChessGame $game): array
    {
        $user = $this->user();
        $color = (string) $game->colorOf($user);
        $opponent = $game->opponentOf($user);
        $last = $game->moves()->reorder('ply', 'desc')->first();
        $left = max(0, (int) $game->deadline_ms - (int) now()->getTimestampMs());
        $minutes = intdiv($left, 60_000);

        return [
            'game' => $game,
            'opponent' => $opponent,
            'mine' => $game->turn() === $color,
            'colorText' => __('You play :color, move :n', ['color' => $color === 'w' ? __('White') : __('Black'), 'n' => intdiv($game->ply, 2) + 1]),
            'last' => $last === null ? null : intdiv($last->ply + 1, 2).($last->ply % 2 === 1 ? '. ' : '… ').\App\Support\Chess\SanNotation::display($last->san),
            'lastSquares' => $last === null ? [] : [substr($last->uci, 0, 2), substr($last->uci, 2, 2)],
            'left' => __(':h h :m min left', ['h' => intdiv($minutes, 60), 'm' => str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT)]),
            'share' => round($left / max(1, $game->initial_ms) * 100, 1),
            'urgent' => $left < 8 * 3_600_000,
            'flip' => $color === 'b',
        ];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @param  Closure(User): mixed  $action
     */
    private function attempt(Closure $action): void
    {
        $this->error = '';

        try {
            $action($this->user());
        } catch (ChessRuleViolation $violation) {
            $this->error = match ($violation->reason) {
                'challenge_closed' => __('That challenge is no longer open.'),
                default => __('That did not work, please try again.'),
            };
        }

        unset($this->incoming, $this->outgoing, $this->running, $this->finished);
    }
}; ?>

@php
    $user = auth()->user();
    $cards = $this->running->map(fn ($game) => $this->card($game));
    $mine = $cards->where('mine', true)->values();
    $theirs = $cards->where('mine', false)->values();
    $finished = $this->finished;
    $outcomes = $finished->map(function ($game) use ($user) {
        $color = $game->colorOf($user);

        return match (true) {
            $game->result === null => 'aborted',
            $game->result === '1/2-1/2' => 'draw',
            ($game->result === '1-0') === ($color === 'w') => 'win',
            default => 'loss',
        };
    });
    $next = $mine->first();
    $settings = $user->chessSettings();
    // What goes out while the player is away, by the Notifier's own rules (ChessSettings::remoteChannels): never a DM for a move.
    $daily = (new \App\Models\ChessGame)->forceFill(['mode' => \App\Models\ChessGame::CORRESPONDENCE]);
    $moveReach = $settings->remoteChannels(\App\Enums\NotificationKind::YourMove, $daily);
    $reminderReach = $settings->remoteChannels(\App\Enums\NotificationKind::Reminder, $daily);
    $reminderChannels = array_values(array_filter([in_array('dm', $reminderReach, true) ? __('Nostr DM') : null, in_array('push', $reminderReach, true) ? __('browser push') : null]));
    $notificationsOn = $moveReach !== [] || $reminderReach !== [];
    $pool = \App\Support\Rating\Ratings::headline(null, 'chess', 'correspondence')['pool'];
    $opponentRatings = \App\Support\Rating\Ratings::forUsers($cards->map(fn ($card) => $card['opponent']?->id)->all(), 'chess', 'correspondence', $pool);
    $myDeltas = \App\Models\RatingChange::query()->where('source', \App\Models\RatingChange::CHESS)->whereIn('source_id', $finished->pluck('id'))
        ->whereHas('rating', fn ($query) => $query->where('subject', 'user:'.$user->id))->pluck('delta', 'source_id');
    /*
     * One list for every correspondence game (Correspondence.dc.html, T14, plan "Refactor und Design-Revamp", P5): chess,
     * Blockli, nine men's morris and checkers, running ones first by whose move it is and the deadline, then the finished
     * chess games. Each row: game, pairing, move state, deadline, one action.
     */
    $leftOf = fn (int $deadlineMs): int => intdiv(max(0, $deadlineMs - (int) now()->getTimestampMs()), 60_000);
    $corrRows = collect();

    foreach ($cards as $card) {
        $left = $leftOf((int) $card['game']->deadline_ms);
        $corrRows->push(['key' => 'c'.$card['game']->id, 'game' => 'chess', 'status' => $card['mine'] ? 'mine' : 'theirs', 'href' => route('games.show', $card['game']), 'number' => $card['game']->number(),
            'opponent' => $card['opponent']?->displayName() ?? __('Deleted player'), 'detail' => $card['last'] === null ? __('No move yet') : __('after'), 'move' => $card['last'], 'npub' => $card['opponent']?->npub,
            'left' => intdiv($left, 60).':'.str_pad((string) ($left % 60), 2, '0', STR_PAD_LEFT), 'urgent' => $card['urgent'], 'test' => $card['mine'] ? 'daily-mine' : 'daily-theirs']);
    }

    foreach ($this->boardCorrespondence['games'] as $boardGame) {
        $boardMine = $boardGame->turn === $boardGame->colorOf($user);
        $left = $leftOf((int) $boardGame->deadline_ms);
        $corrRows->push(['key' => 'b'.$boardGame->id, 'game' => $boardGame->game, 'status' => $boardMine ? 'mine' : 'theirs', 'href' => route('board.show', $boardGame), 'number' => \App\Support\GameNames::game($boardGame->game),
            'opponent' => $boardGame->opponentOf($user)?->displayName() ?? __('Deleted player'), 'detail' => __('move :n', ['n' => intdiv($boardGame->ply, 2) + 1]),
            'left' => intdiv($left, 60).':'.str_pad((string) ($left % 60), 2, '0', STR_PAD_LEFT), 'urgent' => $left < 8 * 60, 'test' => 'board-correspondence-game']);
    }

    // Yours to move first, each group by the deadline (the rows came in deadline order).
    $corrRows = $corrRows->sortBy(fn (array $row): int => $row['status'] === 'mine' ? 0 : 1)->values();

    foreach ($finished as $i => $game) {
        $outcome = $outcomes[$i];
        $corrRows->push(['key' => 'f'.$game->id, 'game' => 'chess', 'status' => 'done', 'href' => route('games.show', $game), 'number' => $game->number(),
            'opponent' => $game->opponentOf($user)?->displayName() ?? __('Deleted player'), 'detail' => ($game->result ? str_replace(['1/2', '-'], ['½', '–'], $game->result).', ' : '').['win' => __('Win'), 'loss' => __('Loss'), 'draw' => __('Draw'), 'aborted' => __('Aborted')][$outcome],
            'left' => $game->ended_at?->diffForHumans() ?? '–', 'urgent' => false, 'test' => 'daily-finished', 'outcome' => $outcome]);
    }

    $reachText = $notificationsOn
        ? trim(($moveReach !== [] ? __('A browser push for an opponent\'s move, at most once an hour and only while you are away.') : __('Opponent moves show here and in the bell.')).' '.($reminderChannels !== [] ? __(':channels :hours h before your deadline.', ['channels' => ucfirst(implode(' '.__('and').' ', $reminderChannels)), 'hours' => $settings->remindHours]) : __('No reminder before your deadline.')))
        : __('You only see new moves here. Turn on a reminder so no deadline slips by.');
    $corrCounts = ['all' => $corrRows->count(), 'mine' => $corrRows->where('status', 'mine')->count(), 'theirs' => $corrRows->where('status', 'theirs')->count(), 'done' => $corrRows->where('status', 'done')->count()];
@endphp

<div class="flex min-w-0 grow flex-col gap-5 px-4 pt-6 pb-8 lg:mx-auto lg:w-full lg:max-w-[1376px] lg:px-12 lg:pb-10" data-test="correspondence-list" x-data="{ show: 'all' }">
    {{-- The player's own pages, one tab row (Profile.dc.html, P7): this is "Games". --}}
    @auth
        <x-account.tabs active="games" />
    @endauth
    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 rounded-[4px] border-l-4 border-chess bg-gradient-to-r from-card to-transparent px-4 py-3">
        <h1 class="m-0 font-display text-[28px] leading-tight font-bold lg:text-[34px]">{{ __('Your correspondence games') }}</h1>
        {{-- Go (2026-10-27) joins the line while one of its sizes is switched on. --}}
        @php($withGo = collect(['go-9', 'go-13', 'go-19'])->contains(fn (string $slug): bool => app(\App\Games\GameRegistry::class)->find($slug) !== null))
        <span class="rv-m text-[13px]">{{ $withGo ? __('Chess, Blockli, nine men\'s morris, checkers, Go: one list') : __('Chess, Blockli, nine men\'s morris, checkers: one list') }}</span>
    </div>

    @if (session('status'))
        <p role="status" class="m-0 rounded-[4px] bg-win-tint px-4 py-3 text-[13px] text-win">{{ session('status') }}</p>
    @endif
    @if ($error)
        <p role="alert" class="m-0 rounded-[4px] bg-loss-tint px-4 py-3 text-[13px] text-loss">{{ $error }}</p>
    @endif

    {{-- Challenges to answer stay above the list: the list has no row for a game that has not started. --}}
    @foreach ($this->incoming as $challenge)
        <section wire:key="in-{{ $challenge->id }}" aria-labelledby="ch-{{ $challenge->id }}" class="rv-card flex flex-col gap-3 px-4 py-4 lg:flex-row lg:items-center lg:gap-6 lg:px-6" data-test="challenge-received">
            <span class="flex min-w-0 grow items-start gap-3">
                <x-player-link :user="$challenge->challenger" class="shrink-0"><x-avatar :user="$challenge->challenger" :size="40" class="rounded-[4px]" /></x-player-link>
                <span class="flex min-w-0 flex-col gap-1">
                    <b id="ch-{{ $challenge->id }}" class="text-base">{{ __(':name challenges you', ['name' => $challenge->challenger->displayName()]) }}</b>
                    <span class="text-xs text-ink-2">{{ __('Daily chess · Casual · you play :color', ['color' => match ($challenge->color) { 'white' => __('Black'), 'black' => __('White'), default => __('a random colour') }]) }}</span>
                    @if ($challenge->message)<span class="text-[13px] text-ink">“{{ $challenge->message }}”</span>@endif
                    <span class="text-xs text-ink-3">{{ __('1 move per day, max 24 h per move. Open for :hours more hours.', ['hours' => max(1, (int) ceil(now()->diffInMinutes($challenge->expires_at) / 60))]) }}</span>
                </span>
            </span>
            <span class="grid grid-cols-2 gap-2 lg:flex">
                <x-button variant="quiet" wire:click="declineChallenge({{ $challenge->id }})">{{ __('Decline') }}</x-button>
                <x-button icon="shield-check" wire:click="acceptChallenge({{ $challenge->id }})" data-test="accept-challenge">{{ __('Accept') }}</x-button>
            </span>
        </section>
    @endforeach
    @foreach ($this->outgoing as $challenge)
        <section wire:key="out-{{ $challenge->id }}" class="rv-card flex flex-col gap-3 px-4 py-4 lg:flex-row lg:items-center lg:gap-6 lg:px-6" data-test="challenge-sent">
            <span class="flex min-w-0 grow items-center gap-3">
                <span aria-hidden="true" class="block size-5 shrink-0 animate-spin rounded-full border-2 border-line border-t-btc motion-reduce:animate-none"></span>
                <span class="flex min-w-0 flex-col gap-0.5">
                    <b class="text-[15px]">{{ __('Waiting for :name', ['name' => $challenge->challenged->displayName()]) }}</b>
                    <span class="text-xs text-ink-2">{{ __('Daily chess · Casual · open until :at', ['at' => $challenge->expires_at->timezone($user->timezone ?? config('app.timezone'))->isoFormat('ddd HH:mm')]) }}</span>
                </span>
            </span>
            <button type="button" wire:click="withdrawChallenge({{ $challenge->id }})" class="rv-b2 border-[#5A2A2E]! text-loss!">{{ __('Withdraw') }}</button>
        </section>
    @endforeach
    @foreach ($this->boardCorrespondence['challenges'] as $slug => $count)
        <a wire:key="bc-{{ $slug }}" href="{{ route('board.correspondence', $slug) }}" class="rv-card flex min-h-12 min-w-0 items-center justify-between gap-3 px-4 text-[13px] text-ink hover:text-ink" data-test="board-correspondence-challenges">
            <span class="truncate">{{ __(':game correspondence', ['game' => \App\Support\GameNames::game($slug)]) }}</span>
            <span class="shrink-0 font-bold text-btc-hi">{{ trans_choice(':count challenge to answer|:count challenges to answer', $count) }}</span>
        </a>
    @endforeach

    @if ($corrRows->isEmpty() && $this->incoming->isEmpty() && $this->outgoing->isEmpty())
        <section class="rv-card flex flex-col items-start gap-3 px-4 py-6 lg:px-6" data-test="correspondence-empty">
            <b class="font-display text-lg">{{ __('No daily games yet') }}</b>
            <x-button icon="pawn" :href="route('chess.challenge')">{{ __('Start daily chess') }}</x-button>
        </section>
    @else
        {{-- Status chips: all first, then by count; a zero stays visible, dimmed (rule "Chip filter rows"). --}}
        <div role="group" aria-label="{{ __('Status') }}" class="flex max-w-full min-w-0 items-center gap-2 overflow-x-auto" data-test="correspondence-filter">
            <span class="rv-m mr-2 shrink-0 text-[13px]">{{ __('Status') }}</span>
            @foreach (['all' => __('All'), 'mine' => __('Your move'), 'theirs' => __('Their move'), 'done' => __('Finished')] as $filter => $label)
                <button type="button" x-on:click="show = '{{ $filter }}'" :aria-pressed="(show === '{{ $filter }}').toString()" data-test="correspondence-filter-{{ $filter }}"
                        class="rv-b2 shrink-0 gap-2 {{ $corrCounts[$filter] === 0 ? 'opacity-60' : '' }}" :class="show === '{{ $filter }}' ? 'border-btc! bg-btc! text-on-btc! font-bold' : ''">
                    {{ $label }} <span class="tabular-nums opacity-80" data-test="{{ ['mine' => 'count-mine', 'all' => 'count-running', 'theirs' => 'count-theirs', 'done' => 'count-done'][$filter] }}">{{ $corrCounts[$filter] }}</span>
                </button>
            @endforeach
        </div>

        {{-- relative: the sr-only header cell is absolute and would scroll the page from outside this scrollport. --}}
        <div class="rv-card relative max-w-full min-w-0 overflow-x-auto">
            <table class="rv-tb min-w-[640px]">
                <thead>
                    <tr><th scope="col" class="w-24">{{ __('Game') }}</th><th scope="col">{{ __('Pairing') }}</th><th scope="col">{{ __('Move') }}</th><th scope="col" class="is-r">{{ __('Deadline') }}</th><th scope="col"><span class="sr-only">{{ __('Action') }}</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($corrRows as $row)
                        <tr wire:key="{{ $row['key'] }}" x-show="show === 'all' || show === '{{ $row['status'] }}'" @class(['is-featured' => $row['status'] === 'mine']) data-test="{{ $row['test'] }}" data-mine="{{ $row['status'] === 'mine' ? 'true' : 'false' }}">
                            <td><x-game-cover :game="$row['game']" size="thumb" class="w-16 rounded-[2px]" /></td>
                            <td class="max-w-[300px]"><span class="flex min-w-0 items-center gap-1.5"><a href="{{ $row['href'] }}" class="block min-w-0 truncate font-bold text-ink hover:text-btc-hi" data-test="correspondence-opponent">{{ __(':number against :name', ['number' => $row['number'], 'name' => $row['opponent']]) }}</a>@if (($row['npub'] ?? null) !== null)<x-copy-npub :npub="$row['npub']" :name="$row['opponent']" />@endif</span></td>
                            <td>
                                <span class="flex flex-wrap items-center gap-2">
                                    @if ($row['status'] === 'mine')
                                        <span class="rv-live" style="height: 22px"><i></i>{{ __('your move') }}</span>
                                    @elseif ($row['status'] === 'theirs')
                                        <span class="rv-tag">{{ __('their move') }}</span>
                                    @else
                                        <span @class(['rv-tag', 'is-ok' => ($row['outcome'] ?? '') === 'win', 'is-bad' => ($row['outcome'] ?? '') === 'loss'])>{{ __('finished') }}</span>
                                    @endif
                                    <span class="text-ink-2">{{ $row['detail'] }}@if (($row['move'] ?? null) !== null) <b>{{ $row['move'] }}</b>@endif</span>
                                </span>
                            </td>
                            <td class="is-r"><b @class(['whitespace-nowrap tabular-nums', 'font-display text-xl' => $row['status'] !== 'done', 'text-loss' => $row['urgent'] && $row['status'] === 'mine', 'text-ink-2 font-normal' => $row['status'] === 'done'])>{{ $row['left'] }}</b></td>
                            <td class="is-r">
                                @if ($row['status'] === 'mine')
                                    <a href="{{ $row['href'] }}" class="rv-b is-sm h-9" data-test="play-your-move">{{ __('Move') }}</a>
                                @else
                                    <a href="{{ $row['href'] }}" class="rv-b2 is-sm h-9">{{ __('View') }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
        <x-button icon="pawn" :href="route('chess.challenge')" data-test="new-challenge">{{ __('Challenge a player') }}</x-button>
        {{-- Notifications: one line, the details in the settings (the list design has no panel for it). --}}
        <span class="flex min-w-0 grow items-center gap-3 text-[13px]">
            <x-icon name="bell" :size="16" class="shrink-0 text-proof" />
            <span class="min-w-0">
                <b @class(['text-win' => $notificationsOn, 'text-ink-2' => ! $notificationsOn])>{{ $notificationsOn ? __('Notifications on') : __('Notifications off') }}</b>
                @if ($notificationsOn)<span class="text-xs leading-normal text-ink-2" data-test="correspondence-reach">{{ $reachText }}</span>@else<span class="text-xs leading-normal text-ink-2">{{ $reachText }}</span>@endif
            </span>
            <x-button variant="quiet" :href="route('settings.notifications')" class="shrink-0" data-test="correspondence-notifications-change">{{ __('Change') }}</x-button>
        </span>
    </div>
</div>
