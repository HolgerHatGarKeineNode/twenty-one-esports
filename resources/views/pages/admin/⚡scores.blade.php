<?php

use App\Models\ScoreRun;
use App\Models\User;
use App\Support\LeagueTime;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Support\Scores\ManualSubmissions;
use App\Support\Scores\ScoreAccounts;
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

    /**
     * Finishes our servers reported for a game account id no player owns
     * for sure yet, grouped by id, with the players who stored it.
     *
     * @return list<array{game: \App\Games\ScoreGame, account: string, runs: int, claimers: list<User>}>
     */
    #[Computed]
    public function accounts(): array
    {
        return ScoreAccounts::pending();
    }

    /**
     * Confirm a stored account id as one player's (security gate F4): it maps to them from now on, and its
     * pending finishes are handed over.
     */
    public function confirmAccount(string $game, string $account, int $userId): void
    {
        Gate::authorize('admin');
        $this->flash = '';
        $this->error = '';
        $score = app(GameRegistry::class)->find($game);
        $player = User::query()->find($userId);

        if (! $score instanceof ScoreGame || $player === null) {
            return;
        }

        try {
            $moved = ScoreAccounts::confirm($score, $account, $player, $this->admin());
        } catch (TournamentRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return;
        }

        $this->flash = trans_choice('Confirmed for :name: :count finish handed over.|Confirmed for :name: :count finishes handed over.', $moved, ['name' => $player->displayName()]);
        unset($this->accounts);
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

    <x-admin.panel :title="__('Unconfirmed accounts')" :meta="trans_choice(':count account|:count accounts', count($this->accounts))" data-test="scores-accounts">
        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('Finishes our servers reported for a game account no player owns for sure: nobody stored the id, or more than one player did. Nothing here counts until you confirm the account for the player it belongs to. Ids are private: never copy them anywhere public.') }}</p>
        @if ($this->accounts === [])
            <x-admin.empty :text="__('No finish waits for an account.')" />
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->accounts as $group)
                    <li wire:key="account-{{ $group['game']->slug() }}-{{ md5($group['account']) }}" class="flex flex-col gap-2 border-t border-hairline py-3 first:border-t-0" data-test="score-account">
                        <span class="flex flex-col gap-0.5">
                            <b class="font-mono text-[13px] [overflow-wrap:anywhere]">{{ $group['account'] }}</b>
                            <span class="text-xs text-ink-2">{{ \App\Support\GameNames::game($group['game']->slug()) }} · {{ trans_choice(':count finish waits|:count finishes wait', $group['runs']) }} · {{ $group['claimers'] === [] ? __('no player stored this id') : trans_choice(':count player stored this id|:count players stored this id', count($group['claimers'])) }}</span>
                        </span>
                        @if ($group['claimers'] !== [])
                            <span class="flex flex-wrap gap-2">
                                @foreach ($group['claimers'] as $claimer)
                                    <x-button variant="secondary" wire:click="confirmAccount('{{ $group['game']->slug() }}', @js($group['account']), {{ $claimer->id }})"
                                              wire:confirm="{{ __('Confirm this account for :name?', ['name' => $claimer->displayName()]) }}" data-test="score-account-confirm">{{ __('Confirm for :name', ['name' => $claimer->displayName()]) }}</x-button>
                                @endforeach
                            </span>
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
