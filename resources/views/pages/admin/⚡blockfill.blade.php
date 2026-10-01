<?php

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Games\ScoreMetric;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Stacker\StackerReplays;
use App\Support\Stacker\StackerRuns;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Blockfill runs held for cheat hints (plan "Blockfill", P5): the verifier
 * replayed them to exactly the claimed time, but something in them looks
 * like a program (StackerReplays::hintLines()). Fastest first, each with its
 * replay. "Approve" makes it count (StackerRuns::approve(): verified, on its
 * week's board), "Reject" keeps it off for good. Admins only (route
 * middleware `admin` and Gate `admin` on every action); routed only while
 * Blockfill is switched on.
 */
new #[Title('Blockfill runs')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component
{
    public const LIMIT = 50;

    public string $flash = '';

    public string $error = '';

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * @return Collection<int, StackerRun>
     */
    #[Computed]
    public function held(): Collection
    {
        return StackerRun::query()->where('status', StackerRunStatus::Review)->with('user')
            ->orderBy('ticks')->orderBy('id')->limit(self::LIMIT)->get();
    }

    #[Computed]
    public function total(): int
    {
        return StackerRun::query()->where('status', StackerRunStatus::Review)->count();
    }

    /**
     * The last decisions, newest first.
     *
     * @return Collection<int, StackerRun>
     */
    #[Computed]
    public function decided(): Collection
    {
        return StackerRun::query()->whereNotNull('flags->review->decision')->with('user')->latest('updated_at')->latest('id')->limit(20)->get();
    }

    public function approve(int $runId, StackerRuns $runs): void
    {
        Gate::authorize('admin');
        $this->decide($runId, fn (StackerRun $run): bool => $runs->approve($run, $this->admin(), now()), __('Approved: the run counts on its week\'s board.'));
    }

    public function reject(int $runId, StackerRuns $runs): void
    {
        Gate::authorize('admin');
        $this->decide($runId, fn (StackerRun $run): bool => $runs->reject($run, $this->admin(), now()), __('Rejected: the run does not count.'));
    }

    public function time(StackerRun $run): string
    {
        return ScoreMetric::time()->format(Blockfill::milliseconds((int) $run->ticks));
    }

    /**
     * The admins who decided the runs listed under "Decided lately", by id: one query.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function reviewers(): Collection
    {
        return User::query()->whereKey($this->decided->map(fn (StackerRun $run): int => (int) ($run->flags['review']['by'] ?? 0))->unique()->values()->all())->get()->keyBy('id');
    }

    public function decidedBy(StackerRun $run): string
    {
        $review = (array) ($run->flags['review'] ?? []);
        $name = $this->reviewers->get((int) ($review['by'] ?? 0))?->displayName() ?? '?';

        return ($review['decision'] ?? '') === 'approved' ? __('Approved by :name', ['name' => $name]) : __('Rejected by :name', ['name' => $name]);
    }

    private function decide(int $runId, Closure $decision, string $done): void
    {
        $this->flash = '';
        $this->error = '';
        $run = StackerRun::query()->find($runId);

        if ($run === null || ! $decision($run)) {
            $this->error = __('This run was decided already.');
        } else {
            $this->flash = $done;
        }

        unset($this->held, $this->total, $this->decided, $this->reviewers);
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<x-admin.page active="blockfill" :title="__('Blockfill review')" :lead="__('Runs the league replayed to the claimed time that still look like a program. Watch the replay, then approve the run so it counts, or reject it. A held run counts nowhere until you approve it.')" data-test="admin-blockfill">
    <x-admin.panel :title="__('Held for a check')" :meta="trans_choice(':count run|:count runs', $this->total)" data-test="blockfill-held">
        @if ($flash !== '')
            <p class="m-0 text-[13px] text-win" role="status" data-test="blockfill-flash">{{ $flash }}</p>
        @endif
        @if ($error !== '')
            <p class="m-0 text-[13px] text-loss" role="alert" data-test="blockfill-error">{{ $error }}</p>
        @endif

        @if ($this->held->isEmpty())
            <x-admin.empty :text="__('No run waits for a check.')" />
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->held as $run)
                    <li wire:key="held-{{ $run->id }}" class="flex flex-col gap-3 border-t border-hairline py-3 first:border-t-0" data-test="blockfill-run">
                        <div class="flex flex-wrap items-start gap-x-4 gap-y-3">
                            <span class="flex min-w-0 grow flex-col gap-1">
                                <span class="flex min-w-0 items-center gap-2 text-[13px]">
                                    @if ($run->user)<x-avatar :user="$run->user" :size="24" class="shrink-0 rounded-sm" />@endif
                                    <b class="min-w-0 truncate whitespace-nowrap">{{ $run->user?->displayName() ?? __('Deleted account') }}</b>
                                    <span class="shrink-0 font-mono tabular-nums" data-test="blockfill-time">{{ $this->time($run) }}</span>
                                </span>
                                <span class="text-xs text-ink-2">{{ __('played :at', ['at' => LeagueTime::stamp($run->submitted_at ?? $run->created_at ?? now())]) }}</span>
                                <ul class="m-0 flex list-disc flex-col gap-0.5 pl-5 text-xs text-ink-2" data-test="blockfill-hints">
                                    @foreach (StackerReplays::hintLines($run) as $line)
                                        <li>{{ $line }}</li>
                                    @endforeach
                                </ul>
                            </span>
                            <span class="flex flex-wrap gap-2">
                                @if ($run->replay !== null)
                                    <x-button variant="quiet" :href="route('stacker.replay', $run)" data-test="blockfill-watch">{{ __('Watch replay') }}</x-button>
                                @endif
                                <x-button wire:click="approve({{ $run->id }})" wire:confirm="{{ __('Approve this run? It counts on its week\'s board.') }}" data-test="blockfill-approve">{{ __('Approve') }}</x-button>
                                <x-button variant="secondary" wire:click="reject({{ $run->id }})" wire:confirm="{{ __('Reject this run? It never counts, and its replay is deleted.') }}" data-test="blockfill-reject">{{ __('Reject') }}</x-button>
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin.panel>

    <x-admin.panel :title="__('Decided lately')" data-test="blockfill-decided">
        @if ($this->decided->isEmpty())
            <x-admin.empty :text="__('No held run was decided yet.')" />
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->decided as $run)
                    <li wire:key="done-{{ $run->id }}" class="flex min-h-11 flex-wrap items-center gap-x-4 gap-y-1 border-t border-hairline py-2 text-[13px] first:border-t-0" data-test="blockfill-decision">
                        <span class="min-w-0 grow [overflow-wrap:anywhere]">{{ $run->user?->displayName() ?? __('Deleted account') }} <span class="font-mono tabular-nums">{{ $this->time($run) }}</span></span>
                        <span @class(['text-xs', 'text-win' => $run->status === StackerRunStatus::Verified, 'text-loss' => $run->status !== StackerRunStatus::Verified])>{{ $this->decidedBy($run) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin.panel>
</x-admin.page>
