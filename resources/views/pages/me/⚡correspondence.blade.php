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
    $channels = array_filter([$settings->dmFor('your_move') ? __('Nostr DM') : null, $settings->push ? __('browser push') : null]);
    $notificationsOn = $channels !== [] && ($settings->wants('your_move') || $settings->wants('reminder'));
    $pool = \App\Support\Rating\Ratings::headline(null, 'chess', 'correspondence')['pool'];
    $opponentRatings = \App\Support\Rating\Ratings::forUsers($cards->map(fn ($card) => $card['opponent']?->id)->all(), 'chess', 'correspondence', $pool);
    $myDeltas = \App\Models\RatingChange::query()->where('source', \App\Models\RatingChange::CHESS)->whereIn('source_id', $finished->pluck('id'))
        ->whereHas('rating', fn ($query) => $query->where('subject', 'user:'.$user->id))->pluck('delta', 'source_id');
@endphp

<div class="flex grow flex-col gap-4 px-4 pb-8 lg:px-12 lg:pb-6" data-test="correspondence-list">
    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
        <h1 class="m-0 font-display text-[28px] font-bold lg:text-[40px]">{{ __('Daily chess') }}</h1>
        <span class="text-[13px] text-ink-2">{{ __('your games, 1 move per day, every move saved and verified') }}</span>
    </div>

    @if (session('status'))
        <p role="status" class="m-0 rounded-lg bg-win-tint px-4 py-3 text-[13px] text-win">{{ session('status') }}</p>
    @endif
    @if ($error)
        <p role="alert" class="m-0 rounded-lg bg-loss-tint px-4 py-3 text-[13px] text-loss">{{ $error }}</p>
    @endif

    {{-- Numbers and notifications: one row from 1440 px (the design width; the row needs ~1420 px), below that four numbers over the notification block (at 1024 px the row ran 163 px past the edge). --}}
    <section aria-label="{{ __('Overview') }}" class="grid grid-cols-2 gap-x-6 gap-y-4 rounded-lg bg-card px-4 py-4 lg:grid-cols-4 lg:px-6 lg:py-5 min-[90rem]:flex min-[90rem]:items-center min-[90rem]:gap-6">
        <span class="flex flex-col gap-1 min-[90rem]:min-w-[180px]"><span class="text-xs text-ink-2">{{ __('In progress') }}</span><b class="font-display text-[22px]" data-test="count-running">{{ $cards->count() }}</b></span>
        <span class="flex flex-col gap-1 min-[90rem]:min-w-[180px]"><span class="text-xs text-ink-2">{{ __('Your move') }}</span><b class="font-display text-[22px] text-btc" data-test="count-mine">{{ $mine->count() }}</b></span>
        <span class="flex flex-col gap-1 min-[90rem]:min-w-[180px]">
            <span class="text-xs text-ink-2">{{ __('Next deadline') }}</span>
            @if ($next)
                @php($leftMin = intdiv(max(0, (int) $next['game']->deadline_ms - (int) now()->getTimestampMs()), 60_000))
                <b class="font-display text-[22px]">{{ intdiv($leftMin, 60) }}:{{ str_pad((string) ($leftMin % 60), 2, '0', STR_PAD_LEFT) }}</b>
                <span class="text-xs text-ink-2">{{ __('h, vs :name', ['name' => $next['opponent']?->displayName()]) }}</span>
            @else
                <b class="font-display text-[22px]">–</b>
                <span class="text-xs text-ink-2">{{ __('nothing due') }}</span>
            @endif
        </span>
        <span class="flex flex-col gap-1 min-[90rem]:min-w-[180px]">
            <span class="text-xs text-ink-2">{{ __('Finished') }}</span>
            <b class="font-display text-[22px]">{{ $finished->count() }}</b>
            <span class="text-xs text-ink-2">{{ __(':wins won · :draws drawn · :losses lost', ['wins' => $outcomes->filter(fn ($o) => $o === 'win')->count(), 'draws' => $outcomes->filter(fn ($o) => $o === 'draw')->count(), 'losses' => $outcomes->filter(fn ($o) => $o === 'loss')->count()]) }}</span>
        </span>
        <span class="col-span-2 flex items-center gap-3.5 border-t border-hairline pt-4 lg:col-span-4 min-[90rem]:ml-auto min-[90rem]:border-t-0 min-[90rem]:border-l min-[90rem]:pt-0 min-[90rem]:pl-6">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-md bg-proof-fill text-proof"><x-icon name="bell" :size="16" /></span>
            <span class="flex min-w-0 grow flex-col gap-0.5 text-[13px] lg:max-w-[280px]">
                @if ($notificationsOn)
                    <b class="flex items-center gap-1.5 text-win"><x-icon name="check" :size="14" />{{ __('Notifications on') }}</b>
                    <span class="text-xs leading-normal text-ink-2">{{ __(':channels on every opponent move and :hours h before your deadline', ['channels' => ucfirst(implode(' '.__('and').' ', $channels)), 'hours' => $settings->remindHours]) }}</span>
                @else
                    <b class="text-ink-2">{{ __('Notifications off') }}</b>
                    <span class="text-xs leading-normal text-ink-2">{{ __('You only see new moves here. Turn on a reminder so no deadline slips by.') }}</span>
                @endif
            </span>
            <x-button variant="quiet" :href="route('settings.chess').'#notifications'" data-test="correspondence-notifications-change">{{ __('Change') }}</x-button>
        </span>
    </section>

    {{-- Challenges (ChessOverlays "Daily challenge received") --}}
    @foreach ($this->incoming as $challenge)
        <section wire:key="in-{{ $challenge->id }}" aria-labelledby="ch-{{ $challenge->id }}" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 shadow-ring lg:flex-row lg:items-center lg:gap-6 lg:px-6" data-test="challenge-received">
            <span class="flex min-w-0 grow items-start gap-3">
                <x-player-link :user="$challenge->challenger" class="shrink-0"><x-avatar :user="$challenge->challenger" :size="40" class="rounded-md" /></x-player-link>
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
        <section wire:key="out-{{ $challenge->id }}" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:flex-row lg:items-center lg:gap-6 lg:px-6" data-test="challenge-sent">
            <span class="flex min-w-0 grow items-center gap-3">
                <span aria-hidden="true" class="block size-5 shrink-0 animate-spin rounded-full border-2 border-line border-t-btc"></span>
                <span class="flex min-w-0 flex-col gap-0.5">
                    <b class="text-[15px]">{{ __('Waiting for :name', ['name' => $challenge->challenged->displayName()]) }}</b>
                    <span class="text-xs text-ink-2">{{ __('Daily chess · Casual · open until :at', ['at' => $challenge->expires_at->timezone($user->timezone ?? config('app.timezone'))->isoFormat('ddd HH:mm')]) }}</span>
                </span>
            </span>
            <button type="button" wire:click="withdrawChallenge({{ $challenge->id }})" class="inline-flex h-11 cursor-pointer items-center justify-center rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss">{{ __('Withdraw') }}</button>
        </section>
    @endforeach

    @if ($cards->isEmpty() && $finished->isEmpty() && $this->incoming->isEmpty() && $this->outgoing->isEmpty())
        <section class="flex flex-col items-start gap-3 rounded-lg bg-card px-4 py-6 lg:px-6" data-test="correspondence-empty">
            <b class="font-display text-lg">{{ __('No daily games yet') }}</b>
            <span class="text-[13px] leading-normal text-ink-2">{{ __('Challenge someone: one move a day, at your pace, casual until Block 0.') }}</span>
            <x-button icon="pawn" :href="route('chess.challenge')">{{ __('Start daily chess') }}</x-button>
        </section>
    @endif

    @foreach ([[__('Your move'), $mine, true], [__('Their move'), $theirs, false]] as [$heading, $group, $isMine])
        @if ($group->isNotEmpty())
            <h2 class="m-0 mt-1 text-[15px] font-bold">{{ $heading }} <span class="font-normal text-ink-3">{{ $group->count() }}</span></h2>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:gap-5 xl:grid-cols-3">
                @foreach ($group as $card)
                    @php($game = $card['game'])
                    @php($opp = $card['opponent'])
                    <article wire:key="g-{{ $game->id }}" class="flex items-start gap-4 rounded-lg bg-card p-4 lg:gap-5 lg:p-5" data-test="{{ $isMine ? 'daily-mine' : 'daily-theirs' }}">
                        <div class="w-[136px] shrink-0 pt-4 pr-4 max-sm:hidden lg:w-[176px]" x-data="{ cells: window.chessBoardCells(@js($game->fen), { noCoords: true, flip: @js($card['flip']), last: @js($card['lastSquares']) }), boardLabel: @js(__('Board of :number', ['number' => $game->number()])) }">
                            <x-chess.board />
                        </div>
                        <div class="flex min-w-0 grow flex-col gap-2">
                            <span class="flex items-center gap-2 text-xs text-ink-3"><a href="{{ route('games.show', $game) }}">{{ $game->number() }}</a>{{ __('Daily chess') }}</span>
                            <span class="flex min-w-0 flex-wrap items-center gap-2">
                                @if ($opp)<x-player-link :user="$opp" class="flex min-h-6 min-w-0 items-center gap-2 text-[15px] font-bold" data-test="correspondence-opponent"><x-avatar :user="$opp" :size="20" class="rounded-sm" /><span class="truncate">{{ $opp->displayName() }}</span></x-player-link>@endif
                                <x-clan-tag :clan="$opp?->clanMember?->clan" size="sm" />
                                @if ($opp?->is_member)<x-member-badge />@endif
                                @if ($opp)<x-copy-npub :npub="$opp->npub" :name="$opp->displayName()" />@endif
                            </span>
                            @if ($opp)<x-rating :rating="$opponentRatings[$opp->id]" :label="__('Daily')" class="text-xs text-ink-2" />@endif
                            <span class="text-xs text-ink-2">{{ $card['colorText'] }}</span>
                            <span class="text-[13px]">@if ($card['last']){{ __('Last move') }} <b>{{ $card['last'] }}</b>@else{{ __('No move yet') }}@endif</span>
                            <span class="flex flex-col gap-1 pt-1">
                                <span class="flex flex-wrap justify-between gap-x-2 gap-y-0.5 text-xs">
                                    <span class="text-ink-2">{{ $isMine ? __('your deadline') : __('deadline for :name', ['name' => $opp?->displayName()]) }}</span>
                                    <b class="flex items-center gap-1.5 whitespace-nowrap">@if ($isMine && $card['urgent'])<x-icon name="warn" :size="14" class="text-loss" />@endif{{ $card['left'] }}</b>
                                </span>
                                <span role="img" aria-label="{{ ($isMine ? __('your deadline') : __('deadline for :name', ['name' => $opp?->displayName()])).': '.$card['left'] }}" class="block h-1.5 overflow-hidden rounded-[3px] bg-raised">
                                    <span class="block h-1.5" style="width: {{ $card['share'] }}%; background: {{ ! $isMine ? '#63636A' : ($card['urgent'] ? '#F87171' : '#F7931A') }}"></span>
                                </span>
                            </span>
                            @if ($isMine)
                                <x-button :href="route('games.show', $game)" class="mt-1 self-start font-bold" aria-label="{{ __('Open game :number vs :name', ['number' => $game->number(), 'name' => $opp?->displayName()]) }}" data-test="play-your-move">{{ __('Play your move') }}</x-button>
                            @else
                                <x-button variant="quiet" :href="route('games.show', $game)" class="mt-1 self-start">{{ __('View') }}</x-button>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    @endforeach

    @if ($finished->isNotEmpty())
        <section aria-labelledby="fin-h" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5" data-test="daily-finished">
            <h2 id="fin-h" class="m-0 text-[15px] font-bold">{{ __('Finished') }} <span class="font-normal text-ink-3">{{ $finished->count() }}</span></h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] border-collapse text-left text-[13px]">
                    <thead>
                        <tr class="text-xs text-ink-2">
                            @foreach (['#', __('Opponent'), __('You'), __('Result'), __('For you'), __('Ending'), __('Moves'), __('Elo'), __('Date'), __('Record')] as $column)
                                <th scope="col" class="h-10 border-b border-hairline px-2 font-normal">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($finished as $i => $game)
                            @php($outcome = $outcomes[$i])
                            @php($opp = $game->opponentOf($user))
                            <tr class="h-11 border-b border-hairline last:border-0">
                                <td class="px-2"><a href="{{ route('games.show', $game) }}">{{ $game->number() }}</a></td>
                                <td class="max-w-[200px] truncate px-2">{{ $opp?->displayName() }}</td>
                                <td class="px-2">{{ $game->colorOf($user) === 'w' ? __('White') : __('Black') }}</td>
                                <td class="px-2">{{ $game->result ? str_replace(['1/2', '-'], ['½', '–'], $game->result) : '–' }}</td>
                                <td @class(['px-2', 'text-win' => $outcome === 'win', 'text-loss' => $outcome === 'loss', 'text-ink-2' => $outcome === 'aborted'])>{{ ['win' => __('Win'), 'loss' => __('Loss'), 'draw' => __('Draw'), 'aborted' => __('Aborted')][$outcome] }}</td>
                                <td class="px-2">{{ $game->end_reason === ChessEndReason::Timeout ? __('Time, deadline missed') : __($game->end_reason?->label() ?? '') }}</td>
                                <td class="px-2 text-right tabular-nums">{{ intdiv($game->ply + 1, 2) }}</td>
                                @php($delta = $myDeltas[$game->id] ?? null)
                                <td @class(['px-2 tabular-nums', 'text-win' => $delta > 0, 'text-loss' => $delta < 0, 'text-ink-3' => ! $delta])>{{ $delta === null ? '–' : ($delta > 0 ? '+'.$delta : ($delta < 0 ? '−'.abs($delta) : '±0')) }}</td>
                                <td class="px-2 text-ink-2">{{ $game->ended_at?->diffForHumans() }}</td>
                                <td @class(['px-2', 'text-win' => $game->record_event_id, 'text-ink-3' => ! $game->record_event_id])>{{ $game->record_event_id ? __('verified') : ($game->result ? __('not signed yet') : '–') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <x-button icon="pawn" :href="route('chess.challenge')" data-test="new-challenge">{{ __('Challenge a player') }}</x-button>
    </div>
</div>
