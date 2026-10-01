<?php

use App\Models\ScoreAccountChange;
use App\Models\ScoreAccountClaim;
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
     * The reason typed per account (the key: game and account id hashed).
     *
     * @var array<string, string>
     */
    public array $accountReasons = [];

    /**
     * The confirmed claims, with every player who stored the same id.
     *
     * @return list<array{claim: ScoreAccountClaim, game: ScoreGame|null, claimers: list<User>}>
     */
    #[Computed]
    public function claims(): array
    {
        return array_values(ScoreAccounts::confirmed()->map(function (ScoreAccountClaim $claim): array {
            $game = app(GameRegistry::class)->find($claim->game);

            return ['claim' => $claim, 'game' => $game instanceof ScoreGame ? $game : null,
                'claimers' => $game instanceof ScoreGame ? array_values(User::query()->whereKey(ScoreAccounts::claimers($game, $claim->account_id))->orderBy('id')->get()->all()) : []];
        })->all());
    }

    /**
     * The last decisions about accounts, newest first.
     *
     * @return Collection<int, ScoreAccountChange>
     */
    #[Computed]
    public function accountLog(): Collection
    {
        return ScoreAccountChange::query()->with(['fromUser', 'toUser', 'admin'])->latest('id')->limit(20)->get();
    }

    public function accountKey(string $game, string $account): string
    {
        return md5($game.'|'.$account);
    }

    /**
     * Confirm a stored account id as one claimer's (re-audit F4): it maps to them from now on, and its pending
     * finishes are handed over.
     */
    public function confirmAccount(string $game, string $account, int $userId): void
    {
        $this->decideAccount($game, $account, function (ScoreGame $score, string $reason) use ($account, $userId): string {
            $player = User::query()->findOrFail($userId);
            $moved = ScoreAccounts::confirm($score, $account, $player, $this->admin(), $reason);

            return trans_choice('Confirmed for :name: :count finish handed over.|Confirmed for :name: :count finishes handed over.', $moved, ['name' => $player->displayName()]);
        });
    }

    /**
     * Move a confirmed account to another of its claimers, with its runs.
     */
    public function reassignAccount(string $game, string $account, int $userId): void
    {
        $this->decideAccount($game, $account, function (ScoreGame $score, string $reason) use ($account, $userId): string {
            $player = User::query()->findOrFail($userId);
            $moved = ScoreAccounts::reassign($score, $account, $player, $this->admin(), $reason);

            return trans_choice('Moved to :name: :count finish moved.|Moved to :name: :count finishes moved.', $moved, ['name' => $player->displayName()]);
        });
    }

    /**
     * Take a confirmation back: its runs go back to pending.
     */
    public function revokeAccount(string $game, string $account): void
    {
        $this->decideAccount($game, $account, function (ScoreGame $score, string $reason) use ($account): string {
            $moved = ScoreAccounts::revoke($score, $account, $this->admin(), $reason);

            return trans_choice('Revoked: :count finish waits again.|Revoked: :count finishes wait again.', $moved);
        });
    }

    /**
     * @param  Closure(ScoreGame, string): string  $decision
     */
    private function decideAccount(string $game, string $account, Closure $decision): void
    {
        Gate::authorize('admin');
        $this->flash = '';
        $this->error = '';
        $score = app(GameRegistry::class)->find($game);

        if (! $score instanceof ScoreGame) {
            return;
        }

        try {
            $this->flash = $decision($score, (string) ($this->accountReasons[$this->accountKey($game, $account)] ?? ''));
        } catch (TournamentRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return;
        }

        unset($this->accountReasons[$this->accountKey($game, $account)], $this->accounts, $this->claims, $this->accountLog);
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

    @php
        $field = 'h-11 w-full min-w-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
        $claimerCard = fn (User $claimer): string => $claimer->displayName().' · '.$claimer->shortNpub();
    @endphp

    <x-admin.panel :title="__('Unconfirmed accounts')" :meta="trans_choice(':count account|:count accounts', count($this->accounts))" data-test="scores-accounts">
        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('Finishes our servers reported for a game account nobody confirmed. A stored id is only a claim: nothing here counts until you confirm the account for the player it belongs to, after checking how you know (a login with the game, a message from the account). Every player who stored the id is listed. Ids are private: never copy them anywhere public.') }}</p>
        @if ($this->accounts === [])
            <x-admin.empty :text="__('No finish waits for an account.')" />
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->accounts as $group)
                    @php($key = $this->accountKey($group['game']->slug(), $group['account']))
                    <li wire:key="account-{{ $key }}" class="flex flex-col gap-3 border-t border-hairline py-3 first:border-t-0" data-test="score-account">
                        <span class="flex flex-col gap-0.5">
                            <b class="font-mono text-[13px] [overflow-wrap:anywhere]">{{ $group['account'] }}</b>
                            <span class="text-xs text-ink-2">{{ \App\Support\GameNames::game($group['game']->slug()) }} · {{ trans_choice(':count finish waits|:count finishes wait', $group['runs']) }} · {{ $group['claimers'] === [] ? __('no player stored this id') : trans_choice(':count player stored this id|:count players stored this id', count($group['claimers'])) }}</span>
                        </span>
                        @if ($group['claimers'] !== [])
                            <ul class="m-0 grid list-none grid-cols-1 gap-2 p-0 sm:grid-cols-2" data-test="score-account-claimers">
                                @foreach ($group['claimers'] as $claimer)
                                    <li class="flex min-w-0 flex-col gap-2 rounded-md bg-ground p-3 shadow-ring-hairline" wire:key="claimer-{{ $key }}-{{ $claimer->id }}" data-test="score-account-claimer">
                                        <span class="flex min-w-0 items-center gap-2 text-[13px]"><x-avatar :user="$claimer" :size="24" class="shrink-0 rounded-sm" /><span class="truncate">{{ $claimerCard($claimer) }}</span></span>
                                        <x-button variant="secondary" wire:click="confirmAccount('{{ $group['game']->slug() }}', @js($group['account']), {{ $claimer->id }})"
                                                  wire:confirm="{{ __('Confirm this account for :name?', ['name' => $claimer->displayName()]) }}" data-test="score-account-confirm">{{ __('Confirm for :name', ['name' => $claimer->displayName()]) }}</x-button>
                                    </li>
                                @endforeach
                            </ul>
                            <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                                {{ __('How you know whose it is (kept in the log)') }}
                                <input wire:model="accountReasons.{{ $key }}" maxlength="500" autocomplete="off" class="{{ $field }}" data-test="score-account-reason">
                            </label>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin.panel>

    <x-admin.panel :title="__('Confirmed accounts')" :meta="trans_choice(':count account|:count accounts', count($this->claims))" data-test="scores-claims">
        @if ($this->claims === [])
            <x-admin.empty :text="__('No account is confirmed yet.')" />
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->claims as $entry)
                    @php($claim = $entry['claim'])
                    @php($key = $this->accountKey($claim->game, $claim->account_id))
                    <li wire:key="claim-{{ $claim->id }}" class="flex flex-col gap-3 border-t border-hairline py-3 first:border-t-0" data-test="score-claim">
                        <span class="flex flex-col gap-0.5">
                            <b class="font-mono text-[13px] [overflow-wrap:anywhere]">{{ $claim->account_id }}</b>
                            <span class="text-xs text-ink-2">{{ \App\Support\GameNames::game($claim->game) }} · {{ __('confirmed for :name', ['name' => $claim->user?->displayName() ?? __('Deleted account')]) }} · {{ trans_choice(':count player stored this id|:count players stored this id', count($entry['claimers'])) }}</span>
                        </span>
                        <span class="flex flex-wrap gap-2">
                            @foreach ($entry['claimers'] as $claimer)
                                @if ($claimer->id !== $claim->user_id)
                                    <x-button variant="secondary" wire:click="reassignAccount('{{ $claim->game }}', @js($claim->account_id), {{ $claimer->id }})"
                                              wire:confirm="{{ __('Move this account and its finishes to :name?', ['name' => $claimer->displayName()]) }}" data-test="score-claim-reassign">{{ __('Move to :name', ['name' => $claimerCard($claimer)]) }}</x-button>
                                @endif
                            @endforeach
                            <x-button variant="quiet" wire:click="revokeAccount('{{ $claim->game }}', @js($claim->account_id))" wire:confirm="{{ __('Revoke this account? Its finishes wait for an admin again.') }}" data-test="score-claim-revoke">{{ __('Revoke') }}</x-button>
                        </span>
                        <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                            {{ __('Why (kept in the log)') }}
                            <input wire:model="accountReasons.{{ $key }}" maxlength="500" autocomplete="off" class="{{ $field }}" data-test="score-claim-reason">
                        </label>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin.panel>

    <x-admin.panel :title="__('Account log')" data-test="scores-account-log">
        @if ($this->accountLog->isEmpty())
            <x-admin.empty :text="__('No account was decided yet.')" />
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->accountLog as $change)
                    <li wire:key="change-{{ $change->id }}" class="flex flex-wrap gap-x-3 border-t border-hairline py-2 text-xs leading-normal first:border-t-0" data-test="score-account-change">
                        <span class="shrink-0 text-ink-3">{{ \App\Support\LeagueTime::stamp($change->created_at ?? now()) }}</span>
                        <span><b>{{ $change->action }}</b> {{ $change->fromUser?->displayName() ?? '–' }} → {{ $change->toUser?->displayName() ?? '–' }} {{ __('by :name', ['name' => $change->admin?->displayName() ?? '?']) }} · {{ trans_choice(':count finish moved|:count finishes moved', $change->runs_moved) }} · {{ $change->reason }}</span>
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
