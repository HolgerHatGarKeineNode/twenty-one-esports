<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentRound;
use App\Models\User;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The "Control" section of the admin edit page (P18, slice 4): full control
 * over a running tournament for admins and the tournament's organizer
 * (gate `manage-tournament`, checked on mount and again in
 * App\Support\Tournaments\TournamentControl for every action). Set or
 * correct a result, disqualify an entry, pause and resume, restart a round,
 * call the tournament off and write to all players; while it runs, "Who
 * blocks what" (components/⚡tournament-waits, slice 5). Every destructive step
 * asks for a typed reason; every step lands in the moderation log, which
 * the page refreshes on `tournament-controlled`.
 */
new class extends Component {
    #[Locked]
    public Tournament $tournament;

    public string $error = '';

    public string $notice = '';

    /** The match whose result form is open; null = none. */
    public ?int $editing = null;

    /** Chess: `1-0`, `1/2-1/2`, `0-1`, `noshow-0`, `noshow-1`. */
    public string $chessResult = '';

    /** Series: `0` or `1` wins, or `noshow-0`/`noshow-1`. */
    public string $seriesWinner = '';

    /** Series: games won by the loser. */
    public int $loserGames = 0;

    public string $resultReason = '';

    public ?int $disqualifying = null;

    public string $disqualifyReason = '';

    public ?int $restarting = null;

    public string $restartReason = '';

    public string $pauseReason = '';

    public bool $aborting = false;

    public string $abortReason = '';

    public string $message = '';

    public function mount(Tournament $tournament): void
    {
        Gate::authorize('manage-tournament', $tournament);

        $this->tournament = $tournament;
    }

    /**
     * Rounds with their matches, in play order.
     *
     * @return Collection<int, TournamentRound>
     */
    #[Computed]
    public function rounds(): Collection
    {
        return TournamentRound::query()->whereHas('stage', fn ($stage) => $stage->where('tournament_id', $this->tournament->id))
            ->with(['stage', 'matches' => fn ($matches) => $matches->where('bracket', '!=', 'bye')->with(['slots.participant', 'seriesMatch', 'chessGame'])])
            ->get()->sortBy(fn (TournamentRound $round): array => [$round->stage->number, $round->number])->values();
    }

    /**
     * @return Collection<int, TournamentParticipant>
     */
    #[Computed]
    public function participants(): Collection
    {
        return $this->tournament->participants()->orderBy('seed')->orderBy('id')->get();
    }

    /**
     * What saving the result form now would do to the Elo (the form states
     * it before the save); null when it moves none.
     *
     * @return array{reverted: array{0: int, 1: int}, applied: array{0: int, 1: int}|null}|null
     */
    #[Computed]
    public function eloPreview(): ?array
    {
        if ($this->editing === null) {
            return null;
        }

        $match = TournamentMatch::query()->where('tournament_id', $this->tournament->id)->with('seriesMatch')->find($this->editing);

        return $match === null ? null : app(TournamentControl::class)->eloPreview($this->tournament, $match->id, $this->resultInput($match));
    }

    public function edit(int $matchId): void
    {
        $this->editing = $matchId;
        $this->chessResult = $this->seriesWinner = $this->resultReason = '';
        $this->loserGames = 0;
        $this->error = '';
    }

    public function setResult(): void
    {
        if ($this->editing === null) {
            return;
        }

        $match = TournamentMatch::query()->where('tournament_id', $this->tournament->id)->findOrFail($this->editing);

        $this->attempt(function () use ($match): string {
            $changed = app(TournamentControl::class)->setResult($this->tournament, $this->user(), $match->id, $this->resultInput($match), $this->resultReason);
            $this->editing = null;

            return $changed ? __('The result is set; the bracket moved on.') : __('Nothing changed.');
        });
    }

    public function startDisqualify(int $participantId): void
    {
        $this->disqualifying = $participantId;
        $this->disqualifyReason = $this->error = '';
    }

    public function disqualify(): void
    {
        if ($this->disqualifying === null) {
            return;
        }

        $this->attempt(function (): string {
            $changed = app(TournamentControl::class)->disqualify($this->tournament, $this->user(), (int) $this->disqualifying, $this->disqualifyReason);
            $this->disqualifying = null;

            return $changed ? __('Disqualified. Their remaining matches are lost by forfeit.') : __('Nothing changed.');
        });
    }

    public function pause(): void
    {
        $this->attempt(fn (): string => app(TournamentControl::class)->pause($this->tournament, $this->user(), $this->pauseReason)
            ? __('Paused. No match starts and no deadline runs until you resume.') : __('Nothing changed.'));
    }

    public function resume(): void
    {
        $this->attempt(fn (): string => app(TournamentControl::class)->resume($this->tournament, $this->user())
            ? __('Resumed. Every running deadline moved by the length of the pause.') : __('Nothing changed.'));
    }

    public function startRestart(int $roundId): void
    {
        $this->restarting = $roundId;
        $this->restartReason = $this->error = '';
    }

    /**
     * @param  int  $restarts  the round's restart count as shown: a second click finds it restarted
     */
    public function restart(int $restarts): void
    {
        if ($this->restarting === null) {
            return;
        }

        $this->attempt(function () use ($restarts): string {
            $changed = app(TournamentControl::class)->restartRound($this->tournament, $this->user(), (int) $this->restarting, $restarts, $this->restartReason);
            $this->restarting = null;

            return $changed ? __('The round starts again.') : __('Nothing changed.');
        });
    }

    public function abort(): void
    {
        $this->attempt(function (): string {
            $changed = app(TournamentControl::class)->abort($this->tournament, $this->user(), $this->abortReason);
            $this->aborting = false;

            return $changed ? __('The tournament is called off.') : __('Nothing changed.');
        });
    }

    public function send(): void
    {
        $this->attempt(function (): string {
            $count = app(TournamentControl::class)->message($this->tournament, $this->user(), $this->message);
            $this->message = '';

            return $count === 0 ? __('This message went out a moment ago.') : trans_choice('Sent to :count player.|Sent to :count players.', $count);
        });
    }

    /**
     * The result form as TournamentRunner reads it (the director desk's input).
     *
     * @return array<string, mixed>
     */
    private function resultInput(TournamentMatch $match): array
    {
        // A board game's result is one game, entered as chess's (plan "Mühle und Dame", P5).
        if ($this->tournament->profile()->isChess() || $this->tournament->profile()->isBoard()) {
            return ['result' => $this->chessResult];
        }

        if (str_starts_with($this->seriesWinner, 'noshow-')) {
            return ['noshow' => (int) substr($this->seriesWinner, 7)];
        }

        if (! in_array($this->seriesWinner, ['0', '1'], true)) {
            return ['winners' => []];
        }

        $winner = (int) $this->seriesWinner;
        $bestOf = $match->seriesMatch !== null ? $match->seriesMatch->best_of
            : (TournamentRunner::isFinal($match) ? $this->tournament->formatOptions()->finalBestOf : $this->tournament->formatOptions()->bestOf);
        $wins = intdiv($bestOf, 2) + 1;
        $losses = max(0, min($wins - 1, $this->loserGames));

        // The loser's games first, so the winner clinches the series with its last game.
        return ['winners' => [...array_fill(0, $losses, 1 - $winner), ...array_fill(0, $wins, $winner)]];
    }

    private function attempt(callable $action): void
    {
        $this->error = $this->notice = '';

        try {
            $this->notice = $action();
        } catch (TournamentRuleViolation $violation) {
            $this->error = $violation->getMessage();
        }

        $this->tournament->refresh();
        unset($this->rounds, $this->participants, $this->eloPreview);
        $this->dispatch('tournament-controlled');
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $tournament = $this->tournament;
    $status = $tournament->status;
    $running = $status === TournamentStatus::Running;
    $chess = $tournament->profile()->isChess() || $tournament->profile()->isBoard();
    $field = 'h-11 w-full min-w-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
    $name = fn (TournamentMatch $match, int $slot): string => $match->slots[$slot]->participant->name ?? __('open');
@endphp

<section id="control" aria-labelledby="control-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="control">
    <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <h2 id="control-h" class="m-0 text-[15px] font-bold">{{ __('Tournament control') }}</h2>
        @if ($tournament->isPaused())
            <span class="inline-flex h-6 items-center rounded-xs bg-btc-chip px-2 text-xs font-bold text-btc-hi" data-test="control-paused">{{ __('Paused') }}</span>
        @endif
    </span>
    <p class="m-0 max-w-[80ch] text-xs leading-normal text-ink-2">{{ __('Admins and the organizer of this tournament. Every step is logged with your name, and the players read the reasons. A result set here is the league’s decision; correcting a played result that moved Elo reverts that Elo and applies the corrected one. A rated decision on a disputed series stays on the disputes page.') }}</p>

    @if ($notice !== '')
        <p class="m-0 rounded-md bg-win-tint px-4 py-3 text-[13px] text-win shadow-[inset_0_0_0_1px_#1F5A34]" role="status" data-test="control-notice">{{ $notice }}</p>
    @endif
    @if ($error !== '')
        <p class="m-0 rounded-md px-4 py-3 text-[13px] text-loss shadow-[inset_0_0_0_1px_#5A2A2E]" role="alert" data-test="control-error">{{ $error }}</p>
    @endif

    @if ($running)
        <div class="flex flex-col gap-2 rounded-md bg-ground p-3 lg:flex-row lg:items-end lg:gap-3" data-test="control-pause">
            @if ($tournament->isPaused())
                <span class="min-w-0 grow text-[13px] text-ink-2">{{ __('No match starts and no deadline runs. Resuming moves every running deadline by the length of the pause.') }}</span>
                <x-button wire:click="resume" class="shrink-0" data-test="control-resume">{{ __('Resume') }}</x-button>
            @else
                <label class="flex min-w-0 grow flex-col gap-1.5 text-xs text-ink-2">
                    {{ __('Pause: reason (optional, the players read it)') }}
                    <input wire:model="pauseReason" maxlength="500" class="{{ $field }}" data-test="control-pause-reason">
                </label>
                <x-button variant="secondary" wire:click="pause" class="shrink-0" data-test="control-pause-button">{{ __('Pause') }}</x-button>
            @endif
        </div>

        {{-- Who blocks what (slice 5): every open match, what it waits for, the countdown and a reminder per player. --}}
        <livewire:tournament-waits :tournament="$tournament" :wire:key="'waits-'.$tournament->id" />
    @endif

    @if ($this->rounds->isNotEmpty())
        <div class="flex flex-col gap-3" data-test="control-rounds">
            @foreach ($this->rounds as $round)
                @php
                    $undecided = $round->matches->filter(fn (TournamentMatch $match): bool => $match->status === 'ready' && $match->result === null);
                @endphp
                <div class="flex flex-col gap-2 border-t border-hairline pt-3" wire:key="round-{{ $round->id }}" data-test="control-round">
                    <span class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="m-0 text-[13px] font-bold">{{ __('Round :round', ['round' => $round->number]) }}@if ($tournament->format === \App\Enums\TournamentFormat::TwoStage) <span class="font-normal text-ink-3">({{ $round->stage->number === 1 ? __('Group stage') : __('Final stage') }})</span>@endif</h3>
                        @if ($running && ! $tournament->isDirectorMode() && $undecided->isNotEmpty())
                            <x-button variant="quiet" wire:click="startRestart({{ $round->id }})" class="h-9 px-3" data-test="control-restart-{{ $round->number }}">{{ __('Restart round') }}</x-button>
                        @endif
                    </span>

                    @if ($restarting === $round->id)
                        <form wire:submit="restart({{ $round->restarts }})" class="flex flex-col gap-2 rounded-md bg-ground p-3 shadow-ring" data-test="control-restart-form">
                            <span class="text-xs leading-normal text-ink-2">{{ __('Every undecided match of this round starts again: a series or game under way, also one reported but not confirmed, is voided and played anew. Decided results stay.') }}</span>
                            <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                                {{ __('Reason (the players read it)') }}
                                <input wire:model="restartReason" maxlength="500" class="{{ $field }}" data-test="control-restart-reason">
                            </label>
                            <span class="flex flex-wrap gap-2">
                                <x-button type="submit" data-test="control-restart-confirm">{{ __('Restart round :round', ['round' => $round->number]) }}</x-button>
                                <x-button variant="quiet" wire:click="$set('restarting', null)">{{ __('Cancel') }}</x-button>
                            </span>
                        </form>
                    @endif

                    <ul class="m-0 flex list-none flex-col gap-2 p-0">
                        @foreach ($round->matches as $match)
                            @php
                                $playable = in_array($match->status, ['ready', 'done'], true) && $match->slots->count() === 2 && $match->slots->every(fn ($slot) => $slot->participant !== null);
                                $label = $match->result['label'] ?? null;
                            @endphp
                            <li class="flex flex-col gap-2 rounded-md bg-raised px-3 py-2.5 text-[13px]" wire:key="control-match-{{ $match->id }}" data-test="control-match" data-key="{{ $match->key }}">
                                <span class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 lg:grid-cols-[80px_minmax(0,1fr)_auto_auto]">
                                    <span class="text-[11px] text-ink-3">{{ strtoupper($match->key) }}</span>
                                    <span class="col-span-2 min-w-0 [overflow-wrap:anywhere] lg:col-span-1 lg:order-none">{{ $name($match, 0) }} <span class="text-ink-3">{{ __('vs') }}</span> {{ $name($match, 1) }}</span>
                                    <span class="text-xs" data-test="control-result">
                                        @if ($match->held !== null)
                                            <span class="font-bold text-loss" data-test="control-held">{{ __('on hold, was :old', ['old' => $match->held['was']['label'] ?? '?']) }}</span>
                                        @elseif ($label !== null)
                                            <b>{{ $label }}</b>@if (($match->result['by'] ?? null) === 'control') <span class="text-ink-3">{{ __('(set here)') }}</span>@endif
                                        @else
                                            <span class="text-ink-3">{{ $match->status === 'waiting' ? __('waiting') : __('open') }}</span>
                                        @endif
                                    </span>
                                    @if ($playable && in_array($status, [TournamentStatus::Running, TournamentStatus::Finished], true))
                                        <x-button variant="secondary" wire:click="edit({{ $match->id }})" class="h-9 px-3" data-test="control-edit-{{ $match->key }}">{{ $label === null ? __('Set result') : __('Correct') }}</x-button>
                                    @endif
                                </span>

                                @if ($editing === $match->id)
                                    <form wire:submit="setResult" class="flex flex-col gap-2 rounded-md bg-ground p-3 shadow-ring" data-test="control-result-form">
                                        @if ($chess)
                                            <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                                                {{ __('Result') }}
                                                <select wire:model.live="chessResult" class="{{ $field }}" data-test="control-chess-result">
                                                    <option value="">{{ __('Pick a result') }}</option>
                                                    <option value="1-0">{{ __(':name wins', ['name' => $name($match, 0)]) }}</option>
                                                    <option value="1/2-1/2">{{ __('Draw') }}</option>
                                                    <option value="0-1">{{ __(':name wins', ['name' => $name($match, 1)]) }}</option>
                                                    <option value="noshow-1">{{ __(':name did not show up', ['name' => $name($match, 1)]) }}</option>
                                                    <option value="noshow-0">{{ __(':name did not show up', ['name' => $name($match, 0)]) }}</option>
                                                </select>
                                            </label>
                                        @else
                                            <span class="grid gap-2 lg:grid-cols-2">
                                                <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                                                    {{ __('Winner') }}
                                                    <select wire:model.live="seriesWinner" class="{{ $field }}" data-test="control-series-winner">
                                                        <option value="">{{ __('Pick the winner') }}</option>
                                                        <option value="0">{{ $name($match, 0) }}</option>
                                                        <option value="1">{{ $name($match, 1) }}</option>
                                                        <option value="noshow-1">{{ __(':name did not show up', ['name' => $name($match, 1)]) }}</option>
                                                        <option value="noshow-0">{{ __(':name did not show up', ['name' => $name($match, 0)]) }}</option>
                                                    </select>
                                                </label>
                                                <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                                                    {{ __('Games the loser won') }}
                                                    <input type="number" min="0" max="5" wire:model="loserGames" class="{{ $field }}" data-test="control-loser-games">
                                                </label>
                                            </span>
                                        @endif
                                        <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                                            {{ __('Reason (the players read it)') }}
                                            <input wire:model="resultReason" maxlength="500" class="{{ $field }}" data-test="control-result-reason">
                                        </label>
                                        <span class="text-xs leading-normal text-ink-3">{{ __('Later matches whose sides change are paired again; one already played is put on hold until you decide it.') }}</span>
                                        @if ($this->eloPreview !== null)
                                            <span class="text-xs font-bold leading-normal text-btc-hi" data-test="control-elo-effect">{{ __('Elo: :effect', ['effect' => TournamentControl::describeElo($this->eloPreview)]) }}</span>
                                        @endif
                                        <span class="flex flex-wrap gap-2">
                                            <x-button type="submit" data-test="control-result-confirm">{{ __('Save result') }}</x-button>
                                            <x-button variant="quiet" wire:click="$set('editing', null)">{{ __('Cancel') }}</x-button>
                                        </span>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif

    @if ($running && $this->participants->isNotEmpty())
        <div class="flex flex-col gap-2 border-t border-hairline pt-3" data-test="control-entries">
            <h3 class="m-0 text-[13px] font-bold">{{ __('Entries') }}</h3>
            <ul class="m-0 flex list-none flex-col gap-2 p-0">
                @foreach ($this->participants as $participant)
                    <li class="flex flex-col gap-2 text-[13px]" wire:key="control-entry-{{ $participant->id }}">
                        <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="min-w-0 grow [overflow-wrap:anywhere]"><b>{{ $participant->name }}</b>@if ($participant->seed) <span class="text-xs text-ink-3">{{ __('seed :seed', ['seed' => $participant->seed]) }}</span>@endif</span>
                            @if ($participant->isDisqualified())
                                <span class="text-xs font-bold text-loss" data-test="control-disqualified">{{ __('disqualified') }}</span>
                            @else
                                <x-button variant="quiet" wire:click="startDisqualify({{ $participant->id }})" class="h-9 px-3" data-test="control-dq-{{ $participant->id }}">{{ __('Disqualify') }}</x-button>
                            @endif
                        </span>
                        @if ($disqualifying === $participant->id)
                            <form wire:submit="disqualify" class="flex flex-col gap-2 rounded-md bg-ground p-3 shadow-ring" data-test="control-dq-form">
                                <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                                    {{ __('Reason (the players read it)') }}
                                    <input wire:model="disqualifyReason" maxlength="500" class="{{ $field }}" data-test="control-dq-reason">
                                </label>
                                <span class="flex flex-wrap gap-2">
                                    <x-button type="submit" data-test="control-dq-confirm">{{ __('Disqualify :name', ['name' => $participant->name]) }}</x-button>
                                    <x-button variant="quiet" wire:click="$set('disqualifying', null)">{{ __('Cancel') }}</x-button>
                                </span>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($status !== TournamentStatus::Draft)
        <form wire:submit="send" class="flex flex-col gap-2 border-t border-hairline pt-3" data-test="control-message">
            <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                {{ __('Message to all players (the bell, and a Nostr DM for those who get DMs)') }}
                <textarea wire:model="message" maxlength="500" rows="2" class="min-h-16 w-full rounded-md border border-edge bg-ground px-3 py-2.5 text-[13px] leading-normal text-ink" data-test="control-message-text"></textarea>
            </label>
            <x-button variant="secondary" type="submit" class="self-start" data-test="control-message-send">{{ __('Send to all players') }}</x-button>
        </form>
    @endif

    @if (in_array($status, [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running], true))
        <div class="flex flex-col gap-2 border-t border-hairline pt-3" data-test="control-abort">
            @if ($aborting)
                <form wire:submit="abort" class="flex flex-col gap-2 rounded-md bg-ground p-3 shadow-[inset_0_0_0_1px_#5A2A2E]" data-test="control-abort-form">
                    <span class="text-xs leading-normal text-ink-2">{{ __('Calling off ends the tournament for good: every series and game under way is voided, nothing is rated after it, and the league publishes a new version of the tournament that says it was called off.') }}</span>
                    <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                        {{ __('Reason (the players read it)') }}
                        <input wire:model="abortReason" maxlength="500" class="{{ $field }}" data-test="control-abort-reason">
                    </label>
                    <span class="flex flex-wrap gap-2">
                        <x-button type="submit" data-test="control-abort-confirm">{{ __('Call off the tournament') }}</x-button>
                        <x-button variant="quiet" wire:click="$set('aborting', false)">{{ __('Cancel') }}</x-button>
                    </span>
                </form>
            @else
                <x-button variant="quiet" wire:click="$set('aborting', true)" class="self-start text-loss" data-test="control-abort-start">{{ __('Call off the tournament') }}</x-button>
            @endif
        </div>
    @endif
</section>
