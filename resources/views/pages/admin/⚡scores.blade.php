<?php

use App\Models\ScoreRun;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Scores\ManualSubmissions;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Score submissions (plan "AoE2 und Trackmania", P4): the values players
 * submitted with a proof link, oldest first, with "Approve" and "Reject"
 * (a reason the player sees). A value counts on its leaderboard once
 * approved; while one waits, the league does not end that leaderboard on
 * its own. Nobody reviews their own. Admins only; routed only while a score
 * game is registered.
 */
new #[Title('Score submissions')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    public const LIMIT = 50;

    public ?int $rejecting = null;

    public string $reason = '';

    public string $flash = '';

    public string $error = '';

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * @return Collection<int, ScoreRun>
     */
    #[Computed]
    public function pending(): Collection
    {
        return ScoreRun::query()->pendingReview()->with(['user', 'tournament'])->orderBy('id')->limit(self::LIMIT)->get();
    }

    #[Computed]
    public function total(): int
    {
        return ScoreRun::query()->pendingReview()->count();
    }

    /**
     * The last decisions, newest first.
     *
     * @return Collection<int, ScoreRun>
     */
    #[Computed]
    public function decided(): Collection
    {
        return ScoreRun::query()->where('source', ScoreRun::MANUAL)->where(fn ($query) => $query->whereNotNull('verified_at')->orWhereNotNull('rejected_at'))
            ->with(['user', 'verifiedBy', 'tournament'])->latest('updated_at')->latest('id')->limit(20)->get();
    }

    public function approve(int $runId, ManualSubmissions $submissions): void
    {
        Gate::authorize('admin');
        $this->decide(fn (ScoreRun $run) => $submissions->approve($run, $this->admin()), $runId, __('Approved: it counts now.'));
    }

    public function startReject(int $runId): void
    {
        $this->rejecting = $runId;
        $this->reason = '';
        $this->error = '';
    }

    public function reject(ManualSubmissions $submissions): void
    {
        Gate::authorize('admin');

        if ($this->rejecting !== null) {
            $this->decide(fn (ScoreRun $run) => $submissions->reject($run, $this->admin(), $this->reason), $this->rejecting, __('Rejected. The player sees your reason.'));
        }
    }

    private function decide(Closure $decision, int $runId, string $done): void
    {
        $this->flash = '';
        $this->error = '';
        $run = ScoreRun::query()->find($runId);

        if ($run === null) {
            return;
        }

        try {
            $decision($run);
        } catch (TournamentRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return;
        }

        $this->rejecting = null;
        $this->flash = $done;
        unset($this->pending, $this->total, $this->decided);
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<x-admin.page active="scores" :title="__('Score submissions')" :lead="__('Values players submitted with a proof link. Open the proof, then approve it or reject it with a reason the player sees. A leaderboard is not ended on its own while a submission for it waits.')" data-test="admin-scores">
    <x-admin.panel :title="__('Waiting')" :meta="trans_choice(':count submission|:count submissions', $this->total)" data-test="scores-pending">
        @if ($flash !== '')
            <p class="m-0 text-[13px] text-win" role="status" data-test="scores-flash">{{ $flash }}</p>
        @endif
        @if ($error !== '')
            <p class="m-0 text-[13px] text-loss" role="alert" data-test="scores-error">{{ $error }}</p>
        @endif

        @if ($this->pending->isEmpty())
            <x-admin.empty :text="__('Nothing waits for a check.')" />
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->pending as $run)
                    <li wire:key="run-{{ $run->id }}" class="flex flex-col gap-3 border-t border-hairline py-3 first:border-t-0" data-test="score-submission">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <span class="flex min-w-0 grow flex-col gap-0.5">
                                <b class="text-[13px] [overflow-wrap:anywhere]">{{ $run->user?->displayName() ?? __('Deleted account') }} · <span class="font-mono tabular-nums">{{ $run->formatted() }}</span></b>
                                <span class="text-xs text-ink-2 [overflow-wrap:anywhere]">
                                    @if ($run->tournament)<a href="{{ route('tournaments.scores', $run->tournament) }}" class="text-ink-2 underline decoration-edge underline-offset-4">{{ $run->tournament->name }}</a> · @endif
                                    <span class="font-mono">{{ $run->course }}</span> · {{ __('set :at', ['at' => LeagueTime::stamp($run->achieved_at)]) }}
                                </span>
                                <a href="{{ $run->proof_url }}" rel="nofollow noopener noreferrer" target="_blank" class="text-xs text-ink underline decoration-edge underline-offset-4 [overflow-wrap:anywhere]" data-test="score-proof">{{ $run->proof_url }}</a>
                            </span>
                            <span class="flex flex-wrap gap-2">
                                <x-button wire:click="approve({{ $run->id }})" data-test="score-approve">{{ __('Approve') }}</x-button>
                                <x-button variant="secondary" wire:click="startReject({{ $run->id }})" data-test="score-reject">{{ __('Reject') }}</x-button>
                            </span>
                        </div>
                        @if ($rejecting === $run->id)
                            <form wire:submit="reject" class="flex flex-col gap-2 sm:flex-row sm:items-end" data-test="score-reject-form">
                                <label class="flex min-w-0 grow flex-col gap-1.5 text-xs text-ink-2">
                                    {{ __('Reason (the player sees it)') }}
                                    <input wire:model="reason" maxlength="500" autocomplete="off" class="h-11 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink" data-test="score-reject-reason">
                                </label>
                                <x-button type="submit" variant="secondary" data-test="score-reject-confirm">{{ __('Reject') }}</x-button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin.panel>

    <x-admin.panel :title="__('Decided lately')" data-test="scores-decided">
        @if ($this->decided->isEmpty())
            <x-admin.empty :text="__('No submission was decided yet.')" />
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->decided as $run)
                    <li wire:key="done-{{ $run->id }}" class="flex min-h-11 flex-wrap items-center gap-x-4 gap-y-1 border-t border-hairline py-2 text-[13px] first:border-t-0">
                        <span class="min-w-0 grow [overflow-wrap:anywhere]">{{ $run->user?->displayName() ?? __('Deleted account') }} · <span class="font-mono tabular-nums">{{ $run->formatted() }}</span></span>
                        <span @class(['text-xs', 'text-win' => $run->verified_at !== null, 'text-loss' => $run->rejected_at !== null])>
                            {{ $run->verified_at !== null ? __('approved by :name', ['name' => $run->verifiedBy?->displayName() ?? '?']) : __('rejected by :name: :reason', ['name' => $run->verifiedBy?->displayName() ?? '?', 'reason' => (string) $run->note]) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin.panel>
</x-admin.page>
