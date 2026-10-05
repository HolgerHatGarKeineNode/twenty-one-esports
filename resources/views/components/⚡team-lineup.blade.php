<?php

use App\Enums\SeriesStatus;
use App\Models\SeriesMatch;
use App\Models\SeriesMatchBoard;
use App\Models\User;
use App\Support\Chess\ChessTeamMatches;
use App\Support\Series\SeriesPresenter;
use App\Support\Series\SeriesRuleViolation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * The lineup step of a chess team match in the match room (plan "Schach
 * Rapid und Clan", P4; NIP rev. 9.22, "Lineup lock"; the rules are
 * App\Support\Chess\ChessTeamMatches):
 *
 * - a captain picks exactly `boards` players of his lineup until the lock,
 *   30 minutes before the start (a rated match: only the players the accept
 *   pinned as Trusted);
 * - the other side sees whether a lineup is named, never whom, until the
 *   lock;
 * - from the lock on, everyone sees the boards in rapid Elo order with the
 *   rating they were ordered by, and the colours (the challenger has White
 *   on odd boards).
 *
 * It renders again on the room's sync (`team-lineup-sync`, a push for this
 * series or a clock edge such as the lock).
 */
new class extends Component {
    #[Locked]
    public int $matchId;

    /** @var list<int> the captain's pick, user ids */
    public array $picked = [];

    public string $error = '';

    public string $saved = '';

    public function mount(SeriesMatch $match): void
    {
        $this->matchId = $match->id;
        $side = $this->match->captainSideOf($this->viewer());

        if ($side !== null) {
            $this->picked = SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', $side)->whereNotNull('user_id')->orderBy('id')->pluck('user_id')->map(intval(...))->all();
        }
    }

    #[On('team-lineup-sync')]
    public function syncFromRoom(): void
    {
        unset($this->match);
    }

    public function toggle(int $userId): void
    {
        $this->saved = '';
        $this->error = '';

        if (in_array($userId, $this->picked, true)) {
            $this->picked = array_values(array_diff($this->picked, [$userId]));

            return;
        }

        if (count($this->picked) < (int) $this->match->boards) {
            $this->picked[] = $userId;
        }
    }

    public function save(ChessTeamMatches $teamMatches): void
    {
        $this->error = '';
        $this->saved = '';

        try {
            $teamMatches->name($this->match, $this->viewer(), $this->picked);
        } catch (SeriesRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return;
        }

        unset($this->match);
        $this->saved = __('Lineup saved. You can change it until the lock.');
    }

    #[Computed]
    public function match(): SeriesMatch
    {
        return SeriesMatch::query()
            ->with(['challengerLineup.clan', 'challengerLineup.seats.user.clanMember', 'challengedLineup.clan', 'challengedLineup.seats.user.clanMember'])
            ->findOrFail($this->matchId);
    }

    private function viewer(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $m = $this->match;
    $viewer = auth()->user();
    $teamMatches = app(ChessTeamMatches::class);
    $captainSide = $m->captainSideOf($viewer);
    $lockAt = ChessTeamMatches::lockAt($m);
    $open = ChessTeamMatches::lineupOpen($m);
    $locked = $m->lineup_locked_at !== null;
    $choices = $captainSide !== null && $open ? $teamMatches->choices($m, $captainSide) : [];
    $sides = [];

    foreach (SeriesMatch::SIDES as $side) {
        $sides[$side] = ['named' => ChessTeamMatches::hasNamed($m, $side), 'players' => $teamMatches->lineupFor($m, $side, $viewer)];
    }
@endphp

<section aria-labelledby="team-lineup-h" class="flex min-w-0 flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="team-lineup">
    <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 id="team-lineup-h" class="m-0 text-[15px] font-bold">{{ trans_choice('Lineup, :count board|Lineup, :count boards', $m->boards) }}</h2>
        @if ($locked)
            <span class="text-xs font-bold text-win" data-test="team-lineup-locked">{{ __('Locked') }}</span>
        @elseif ($lockAt !== null)
            <span class="text-xs text-ink-2" data-test="team-lineup-lock-at">{{ __('Locks :time', ['time' => SeriesPresenter::time($lockAt, $viewer, 'D H:i')]) }}</span>
        @endif
    </span>

    @unless ($locked)
        <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Each captain picks :n players until :minutes minutes before the start. The league orders the boards by rapid Elo. A clan without a lineup at the lock loses the team match by forfeit.', ['n' => $m->boards, 'minutes' => ChessTeamMatches::lockMinutes()]) }}</p>
    @endunless

    @if ($captainSide !== null && $open)
        <div class="flex flex-col gap-2" data-test="team-lineup-picker">
            <span class="flex flex-wrap items-baseline justify-between gap-2">
                <b class="text-[13px]">{{ __('Who plays for :clan', ['clan' => $m->sideName($captainSide)]) }}</b>
                <span @class(['text-xs', 'text-win' => count($picked) === $m->boards, 'text-btc-hi' => count($picked) !== $m->boards]) data-test="team-lineup-count">{{ __(':n of :m picked', ['n' => count($picked), 'm' => $m->boards]) }}</span>
            </span>
            @if ($m->rated)
                <span class="text-xs leading-normal text-ink-2">{{ __('Rated: only players who were Trusted when the match was accepted can play.') }}</span>
            @endif
            <div class="flex flex-col">
                @forelse ($choices as $seat)
                    @php($on = in_array($seat->user_id, $picked, true))
                    <button type="button" wire:key="tl-{{ $seat->user_id }}" wire:click="toggle({{ $seat->user_id }})" role="checkbox" aria-checked="{{ $on ? 'true' : 'false' }}"
                            @disabled(! $on && count($picked) >= $m->boards) data-test="team-lineup-pick-{{ $seat->user_id }}"
                            class="flex min-h-12 w-full min-w-0 cursor-pointer items-center justify-between gap-3 border-0 border-b border-hairline bg-transparent px-0 py-1 text-left text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-50">
                        <span class="flex min-w-0 flex-col">
                            <span class="truncate">{{ $seat->user->displayName() }}@if ($seat->user_id === $viewer->id) ({{ __('you') }})@endif</span>
                            <span class="text-[11px] text-ink-3">{{ $seat->role->label() }}</span>
                        </span>
                        <span @class(['flex size-6 shrink-0 items-center justify-center rounded-sm border', 'border-btc bg-btc text-on-btc' => $on, 'border-edge' => ! $on])>@if ($on)<x-icon name="check" :size="14" />@endif</span>
                    </button>
                @empty
                    <p class="m-0 text-[13px] text-ink-2">{{ __('Nobody in your lineup can play this match.') }}</p>
                @endforelse
            </div>
            <div class="flex flex-wrap items-center gap-3 pt-1">
                <x-button icon="shield-check" wire:click="save" wire:loading.attr="disabled" :disabled="count($picked) !== $m->boards" class="disabled:cursor-not-allowed disabled:opacity-50" data-test="team-lineup-save">{{ __('Save lineup') }}</x-button>
                @if ($saved !== '')<span class="text-xs font-bold text-win" role="status" data-test="team-lineup-saved">{{ $saved }}</span>@endif
            </div>
            @if ($error !== '')<p class="m-0 text-[13px] text-loss" role="alert" data-test="team-lineup-error">{{ $error }}</p>@endif
        </div>
    @endif

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        @foreach (SeriesMatch::SIDES as $side)
            @php(['named' => $named, 'players' => $players] = $sides[$side])
            <div class="flex min-w-0 flex-col gap-2 rounded-md bg-well px-4 py-3" data-test="team-lineup-side-{{ $side }}">
                <b class="text-[13px] [overflow-wrap:anywhere]">{{ $m->sideName($side) }}</b>
                @if ($players === null)
                    <span class="text-xs leading-normal text-ink-2" data-test="team-lineup-hidden-{{ $side }}">{{ $named ? __('Lineup named. You see it at the lock.') : __('No lineup named yet.') }}</span>
                @elseif ($players->isEmpty())
                    <span class="text-xs text-ink-2">{{ __('No lineup named yet.') }}</span>
                @else
                    <ol class="m-0 flex list-none flex-col gap-1 p-0">
                        @foreach ($players as $player)
                            @php($white = $player->board !== null && (($player->board % 2 === 1) === ($side === 'challenger')))
                            <li class="flex min-h-8 min-w-0 items-center gap-2 text-[13px]" data-test="team-lineup-player">
                                @if ($player->board !== null)
                                    <span class="w-14 shrink-0 text-xs text-ink-3">{{ __('Board :n', ['n' => $player->board]) }}</span>
                                @endif
                                <span class="min-w-0 grow truncate">{{ $player->user?->displayName() ?? __('Deleted account') }}</span>
                                @if ($player->board !== null)
                                    <span class="shrink-0 text-xs text-ink-2">{{ $player->rating }} · {{ $white ? __('White') : __('Black') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        @endforeach
    </div>

    @if ($locked && $m->status === SeriesStatus::Accepted)
        <p class="m-0 text-xs leading-normal text-ink-2" data-test="team-lineup-reserved">{{ __('The players above start no other game until the team match is over.') }}</p>
    @endif
</section>
