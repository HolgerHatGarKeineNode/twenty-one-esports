<?php

use App\Models\AccountCheckAction;
use App\Models\AccountScan;
use App\Models\ImpersonationHold;
use App\Models\TrustRank;
use App\Models\User;
use App\Support\FairPlay\AccountChecks;
use App\Support\FairPlay\AccountScans;
use App\Support\FairPlay\Bars;
use App\Support\FairPlay\FairPlay;
use App\Support\FairPlay\FairPlayRefused;
use App\Support\FairPlay\Impersonation;
use App\Support\FairPlay\ProfileStandard;
use App\Support\FairPlay\ReceivedZaps;
use App\Support\FairPlay\SoftMarks;
use App\Support\LeagueTime;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\TrustAdmin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One account's check (plan "Profile standard and impersonation", P3): the
 * state first (bars, holds, hard marks of the profile standard, soft marks),
 * the profile beside it, then the AI scan and the quick actions, and the log
 * of what admins did here. Admins only; players never see any of it.
 *
 * The AI scan runs only when an admin presses its button and confirms
 * (AccountScans); its result is a hint and triggers nothing. Every quick
 * action (AccountChecks: clear, hold, link, bar, exclude) is deferred:
 * picking it and typing write nothing, only the confirm button acts, and
 * each one is logged.
 */
new #[Title('Account check')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component
{
    public string $pubkey;

    /** `clear`, `hold`, `link`, `bar` or `exclude`; nothing is written until the confirm button. */
    public string $action = '';

    /** The admin's reason or note, saved with the action; never shown to the player. */
    public string $note = '';

    /** The main account (npub or hex) for `link`. */
    public string $mainKey = '';

    /** The end of a bar as a datetime-local value in the league's zone; empty = the end of the live season. */
    public string $until = '';

    public string $notice = '';

    public function mount(string $pubkey): void
    {
        Gate::authorize('admin');
        abort_unless(User::query()->where('pubkey', $pubkey)->exists(), 404);
        $this->pubkey = $pubkey;
    }

    #[Computed]
    public function player(): User
    {
        return User::query()->where('pubkey', $this->pubkey)->firstOrFail();
    }

    /** @return array{kind: string, until: \Carbon\CarbonImmutable|null, main: string|null}|null */
    #[Computed]
    public function bar(): ?array
    {
        return FairPlay::barred([$this->pubkey])[$this->pubkey] ?? null;
    }

    /** @return list<'known'|'nip05'|'profile'> */
    #[Computed]
    public function missing(): array
    {
        return ProfileStandard::missing($this->player);
    }

    /** @return list<array{key: string, label: string, tone: 'good'|'warn'|'none', weight: int}> */
    #[Computed]
    public function marks(): array
    {
        return SoftMarks::for([$this->pubkey => $this->player->id])[$this->pubkey] ?? [];
    }

    /** @return Collection<int, ImpersonationHold> */
    #[Computed]
    public function holds(): Collection
    {
        return ImpersonationHold::query()->where('pubkey', $this->pubkey)->with('matchedUser')->latest('id')->limit(10)->get();
    }

    #[Computed]
    public function scan(): ?AccountScan
    {
        return AccountScan::query()->where('pubkey', $this->pubkey)->with('startedBy')->latest('id')->first();
    }

    #[Computed]
    public function scans(): int
    {
        return AccountScan::query()->where('pubkey', $this->pubkey)->count();
    }

    /** @return Collection<int, AccountCheckAction> */
    #[Computed]
    public function log(): Collection
    {
        return AccountCheckAction::query()->where('pubkey', $this->pubkey)->with('admin')->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function rank(): ?int
    {
        $rank = TrustRank::query()->where('pubkey', $this->pubkey)->value('rank');

        return $rank === null ? null : (int) $rank;
    }

    #[Computed]
    public function zapsReadAt(): ?int
    {
        return ReceivedZaps::readAt([$this->pubkey])[$this->pubkey] ?? null;
    }

    /** Start the AI scan; the button asks for a confirm first, since each scan costs money. */
    public function startScan(): void
    {
        $admin = $this->admin();
        $this->resetErrorBag();
        $this->notice = '';

        try {
            $scan = app(AccountScans::class)->scan($admin, $this->player);
        } catch (FairPlayRefused $refused) {
            $this->addError('scan', $refused->getMessage());

            return;
        }

        $this->notice = $scan->status === AccountScan::DONE ? __('Scan done. It is a hint; the decision is yours.') : __('The scan failed. Nothing was decided.');
        unset($this->scan, $this->scans);
    }

    /** Read the account's received zaps from the relays now. */
    public function readZaps(): void
    {
        $this->admin();
        $this->notice = app(ReceivedZaps::class)->read($this->pubkey)['answered'] === 0
            ? __('No relay answered. Try again later.')
            : __('Zaps read.');
        unset($this->marks, $this->zapsReadAt);
    }

    /** The quick action: only this writes. */
    public function act(): void
    {
        $admin = $this->admin();
        $this->resetErrorBag();
        $this->notice = '';
        $checks = app(AccountChecks::class);
        $player = $this->player;

        try {
            $this->notice = match ($this->action) {
                'clear' => (function () use ($checks, $admin, $player): string {
                    $checks->clear($admin, $player, $this->note);

                    return __('Marked as fine. Open holds of the account have ended.');
                })(),
                'hold' => (function () use ($checks, $admin, $player): string {
                    $checks->hold($admin, $player, $this->note);

                    return __('Held for review. The account cannot start new rated play until an admin decides.');
                })(),
                'link' => (function () use ($checks, $admin, $player): string {
                    $checks->link($admin, $player, $this->mainKey, $this->note);

                    return __('Linked to the main account. Open holds of the account have ended.');
                })(),
                'bar' => (function () use ($checks, $admin, $player): string {
                    try {
                        $until = trim($this->until) === '' ? null : LeagueTime::parse($this->until);
                    } catch (InvalidArgumentException $invalid) {
                        throw new FairPlayRefused($invalid->getMessage());
                    }

                    $checks->bar($admin, $player, $this->note, $until);

                    return __('Barred from rated play. Open holds of the account have ended.');
                })(),
                'exclude' => (function () use ($checks, $admin, $player): string {
                    $checks->exclude($admin, $player, $this->note);

                    return __('Excluded from the trust ranks from the next run.');
                })(),
                default => throw new FairPlayRefused(__('Pick an action first.')),
            };
        } catch (FairPlayRefused $refused) {
            $this->addError('action', $refused->getMessage());

            return;
        }

        $this->reset('action', 'note', 'mainKey', 'until');
        unset($this->bar, $this->holds, $this->log, $this->player);
    }

    private function admin(): User
    {
        Gate::authorize('admin');
        $admin = auth()->user();
        abort_unless($admin instanceof User, 403);

        return $admin;
    }
}; ?>

@php
    $player = $this->player;
    $bar = $this->bar;
    $scan = $this->scan;
    $npub = $player->npub ?? NostrKeys::hexToNpub($player->pubkey);
    $openHolds = $this->holds->filter(fn ($hold) => $hold->isOpen());
    $input = 'rv-in';
    $seasonEnds = Seasons::live()?->ends_at;
    $mayExclude = TrustAdmin::canExclude(auth()->user());
    $state = match (true) {
        $bar !== null && $bar['kind'] === 'held' => [__('Account being checked'), 'bad'],
        $bar !== null => [__('Barred from rated play'), 'bad'],
        $openHolds->isNotEmpty() => [__('Held (counts once the check is on)'), 'warn'],
        default => [__('Not barred'), 'ok'],
    };
    $hard = [
        'known' => __('Known to us for fewer than :days days', ['days' => ProfileStandard::knownDays()]),
        'nip05' => __('No NIP-05'),
        // Which half is missing: the avatar beside the name is a generated one when there is no picture.
        'profile' => match (true) {
            trim((string) $player->name) === '' && trim((string) $player->picture) === '' => __('No name and no profile picture'),
            trim((string) $player->name) === '' => __('No name in the profile'),
            default => __('No profile picture'),
        },
    ];
    $picture = trim((string) $player->picture);
    $actions = [
        'clear' => [__('Mark as fine'), __('Ends every open hold of the account.'), true],
        'hold' => [__('Hold for review'), Impersonation::enabled() ? __('No new rated play until an admin decides.') : __('Only while the impersonation check is switched on.'), true],
        'link' => [__('Link to a main account'), __('A second account of the same player. Results between them are voided.'), true],
        'bar' => [__('Bar from rated play'), __('With a reason and an end.'), true],
        'exclude' => [__('Exclude from trust'), $mayExclude ? __('Out of the trust ranks from the next run.') : __('Board members only.'), $mayExclude],
    ];
    $verdicts = ['fine' => [__('Fine'), 'is-ok'], 'unclear' => [__('Unclear'), ''], 'suspicious' => [__('Suspicious'), 'is-bad']];
@endphp

<x-admin.page active="checks" :title="$player->displayName()" :current="__('Account check')" :crumbs="[[__('Checks'), route('admin.checks')]]" :notice="$notice" notice-test="check-notice" data-test="admin-check-player">
    <x-slot:badge>
        <span @class(['inline-flex h-9 items-center rounded-md px-3.5 text-[13px] font-bold whitespace-nowrap', 'bg-loss-tint text-loss' => $state[1] === 'bad', 'bg-well text-btc-hi' => $state[1] === 'warn', 'bg-win-tint text-win' => $state[1] === 'ok']) data-test="check-state" data-state="{{ $bar['kind'] ?? ($openHolds->isNotEmpty() ? 'open-hold' : 'none') }}">{{ $state[0] }}</span>
    </x-slot:badge>
    <x-slot:readout>
        <span class="flex items-baseline gap-2"><span class="rv-m">{{ __('AI scan') }}</span><span class="rv-num text-base text-ink" data-test="readout-value">{{ $scan?->verdict !== null ? $verdicts[$scan->verdict][0] : '–' }}</span></span>
    </x-slot:readout>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <x-admin.panel :title="__('Marks')" data-test="check-marks">
            <div class="flex flex-col gap-1.5">
                <span class="text-xs text-ink-2">{{ __('Profile standard (the player sees these)') }}</span>
                <span class="flex flex-wrap gap-1.5" data-test="check-hard">
                    @forelse ($this->missing as $missing)
                        <span class="rv-tag is-bad h-auto min-h-5 py-0.5 whitespace-normal" data-test="check-hard-mark" data-mark="{{ $missing }}">{{ $hard[$missing] }}</span>
                    @empty
                        <span class="rv-tag is-ok h-auto min-h-5 py-0.5 whitespace-normal" data-test="check-hard-met">{{ __('All met') }}</span>
                    @endforelse
                </span>
            </div>
            <div class="flex flex-col gap-1.5">
                <span class="text-xs text-ink-2">{{ __('Soft marks (admins only)') }}</span>
                <span class="flex flex-wrap gap-1.5" data-test="check-soft">
                    @forelse ($this->marks as $mark)
                        <x-admin.soft-mark :mark="$mark" />
                    @empty
                        <span class="text-xs text-ink-3">{{ __('All soft marks are switched off in the settings.') }}</span>
                    @endforelse
                </span>
                <span class="flex flex-wrap items-center gap-x-3 text-xs text-ink-3">
                    <span data-test="check-zaps-read">{{ $this->zapsReadAt === null ? __('Zaps never read') : __('Zaps read :time', ['time' => LeagueTime::stamp(\Carbon\CarbonImmutable::createFromTimestamp($this->zapsReadAt))]) }}</span>
                    <button type="button" wire:click="readZaps" class="inline-flex min-h-11 cursor-pointer items-center border-0 bg-transparent p-0 text-xs text-btc underline" data-test="check-read-zaps">{{ __('Read zaps now') }}</button>
                </span>
            </div>
            <div class="flex flex-col gap-1.5" data-test="check-holds">
                <span class="text-xs text-ink-2">{{ __('Impersonation holds') }}</span>
                @forelse ($this->holds as $hold)
                    <a href="{{ route('admin.checks.hold', $hold) }}" @navigate(route('admin.checks.hold', $hold)) class="flex min-h-11 flex-wrap items-center gap-x-2 gap-y-0.5 border-t border-hairline py-1.5 text-[13px] text-ink first:border-t-0 hover:text-btc" wire:key="check-hold-{{ $hold->id }}" data-test="check-hold" data-open="{{ $hold->isOpen() ? '1' : '0' }}">
                        <span @class(['rv-tag', 'is-bad' => $hold->isOpen()])>{{ $hold->isOpen() ? __('open') : __('decided') }}</span>
                        <span class="min-w-0 [overflow-wrap:anywhere]">{{ $hold->isManual() ? __('held for review') : __('looks like :name', ['name' => $hold->matchedUser?->displayName() ?? __('an account that never logged in')]) }}</span>
                        <span class="text-xs text-ink-3">{{ LeagueTime::stamp($hold->detected_at) }}</span>
                    </a>
                @empty
                    <span class="text-xs text-ink-3">{{ __('Never held.') }}</span>
                @endforelse
            </div>
        </x-admin.panel>

        <x-admin.panel :title="__('Profile')" data-test="check-profile">
            <span class="flex min-w-0 items-center gap-3">
                <x-avatar :user="$player" :size="56" class="shrink-0 rounded-md" />
                <span class="flex min-w-0 flex-col gap-0.5">
                    <b class="text-[15px] [overflow-wrap:anywhere]" data-test="check-name">{{ $player->displayName() }}</b>
                    <span class="font-mono text-xs break-all text-ink-3" data-test="check-npub">{{ $npub }}</span>
                </span>
            </span>
            <span class="flex flex-wrap gap-2">
                <x-copy-npub :npub="$npub" :name="$player->displayName()" variant="button" :label="__('Copy npub')" />
                <x-button variant="secondary" :href="route('players.show', $npub)" data-test="check-open-profile">{{ __('Open profile') }}</x-button>
            </span>
            <div class="flex flex-col">
                @foreach ([
                    [__('Picture'), $picture === '' ? __('none (the avatar shown is generated)') : $picture, 'picture'],
                    [__('NIP-05'), $player->nip05 === null ? '–' : $player->nip05.' · '.($player->nip05_verified_at !== null ? __('checked') : __('not checked')), 'nip05'],
                    [__('First login'), $player->created_at ? LeagueTime::stamp($player->created_at) : '–', 'first-login'],
                    [__('Trust rank'), $this->rank === null ? '–' : (string) $this->rank, 'rank'],
                    [__('Verein'), $player->is_member ? __('member') : __('not a member'), 'member'],
                    [__('Website'), $player->website ?? '–', 'website'],
                    [__('About'), $player->about ?? '–', 'about'],
                ] as [$label, $value, $test])
                    <div class="grid min-h-11 grid-cols-[96px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-1.5 text-[13px] last:border-0" data-test="check-row-{{ $test }}">
                        <span class="text-ink-2">{{ $label }}</span><span class="min-w-0 whitespace-pre-line [overflow-wrap:anywhere]">{{ $value }}</span>
                    </div>
                @endforeach
            </div>
        </x-admin.panel>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <x-admin.panel :title="__('AI scan')" :meta="trans_choice(':count scan so far|:count scans so far', $this->scans)" data-test="check-scan">
            <p class="m-0 text-xs leading-normal text-ink-2">{{ __('A hint from a language model, never a decision: it reads the profile and the marks, and nothing happens because of it.') }}</p>
            @if ($scan === null)
                <x-admin.empty :text="__('No scan yet.')" data-test="check-scan-none" />
            @elseif ($scan->status === \App\Models\AccountScan::FAILED)
                <p class="m-0 text-[13px] text-loss" data-test="check-scan-failed">{{ __('Scan failed') }}</p>
            @else
                <span class="flex flex-wrap items-center gap-2">
                    <span class="rv-tag {{ $verdicts[$scan->verdict][1] }}" data-test="check-scan-verdict" data-verdict="{{ $scan->verdict }}">{{ $verdicts[$scan->verdict][0] }}</span>
                </span>
                <ul class="m-0 flex list-disc flex-col gap-1 pl-5 text-[13px]" data-test="check-scan-reasons">
                    @foreach ($scan->reasons ?? [] as $reason)
                        <li class="[overflow-wrap:anywhere]">{{ $reason }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($scan !== null)
                <span class="text-xs text-ink-3" data-test="check-scan-meta">{{ __(':model · :tokens tokens · about $:cost · :time · by :name', [
                    'model' => $scan->model,
                    'tokens' => $scan->input_tokens + $scan->output_tokens,
                    'cost' => number_format((float) $scan->cost_usd, 4),
                    'time' => LeagueTime::stamp($scan->started_at),
                    'name' => $scan->startedBy?->displayName() ?? substr($scan->started_by_pubkey, 0, 8),
                ]) }}</span>
                <details class="group text-xs text-ink-2" data-test="check-scan-inputs" wire:ignore.self wire:key="scan-inputs-{{ $scan->id }}">
                    <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-1.5 whitespace-nowrap text-btc hover:text-btc-hi [&::-webkit-details-marker]:hidden" data-test="check-scan-inputs-toggle">
                        <x-icon name="chevron-down" :size="16" class="shrink-0 transition-transform duration-150 group-open:rotate-180 motion-reduce:transition-none" />
                        <span class="underline underline-offset-2">{{ __('What the model saw') }}</span>
                    </summary>
                    <pre class="m-0 max-h-80 overflow-auto rounded bg-well p-3 font-mono text-[11px] whitespace-pre-wrap [overflow-wrap:anywhere]">{{ json_encode($scan->inputs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </details>
            @endif
            @error('scan')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
            <span class="flex flex-wrap gap-3">
                <x-button variant="secondary" icon="eye" wire:click="startScan" wire:confirm="{{ __('Start an AI scan of :name? It costs money and only gives a hint.', ['name' => $player->displayName()]) }}" data-test="check-start-scan">{{ __('Start scan') }}</x-button>
            </span>
        </x-admin.panel>

        <section aria-labelledby="act-h" class="rv-sec" data-test="check-actions">
            <h2 id="act-h" class="rv-h3">{{ __('Quick actions') }}</h2>
            <form wire:submit="act" wire:confirm="{{ __('Do this now? It is logged with your name.') }}" class="flex flex-col gap-4" data-test="check-form">
                <div role="radiogroup" aria-labelledby="act-h" class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($actions as $value => [$title, $text, $enabled])
                        <button type="button" role="radio" wire:key="action-{{ $value }}" @if ($enabled) wire:click="$set('action', '{{ $value }}')" @else disabled @endif aria-checked="{{ $action === $value ? 'true' : 'false' }}" class="rv-opt h-auto items-start py-3 disabled:cursor-not-allowed disabled:opacity-60" data-test="check-action-{{ $value }}">
                            <i class="mt-0.5"></i><span class="flex min-w-0 flex-col gap-0.5"><b class="text-[13px]">{{ $title }}</b><span class="text-xs leading-normal text-ink-2">{{ $text }}</span></span>
                        </button>
                    @endforeach
                </div>
                @if ($action === 'link')
                    <label class="flex flex-col gap-1.5 text-xs text-ink-2" wire:key="check-main">{{ __('Main account (npub or hex)') }}
                        <input type="text" wire:model="mainKey" autocomplete="off" spellcheck="false" class="{{ $input }} font-mono" data-test="check-main">
                    </label>
                @endif
                <label class="flex flex-col gap-1.5 text-xs text-ink-2">{{ __('Reason (required, except to mark as fine; saved with the action, never shown to the player)') }}
                    <input type="text" wire:model="note" maxlength="{{ Bars::REASON_MAX }}" class="{{ $input }}" data-test="check-note">
                </label>
                @if ($action === 'bar')
                    <label class="flex flex-col gap-1.5 text-xs text-ink-2" wire:key="check-until">{{ __('Barred until (:zone)', ['zone' => LeagueTime::zoneName()]) }}
                        <input type="datetime-local" wire:model="until" class="{{ $input }}" data-test="check-until">
                        <span class="text-xs text-ink-3">{{ $seasonEnds ? __('Empty: until the end of the live season, :time.', ['time' => LeagueTime::stamp($seasonEnds)]) : __('No season is live: give the bar an end.') }}</span>
                    </label>
                @endif
                @error('action')<p class="m-0 text-[13px] text-loss" role="alert" data-test="check-error">{{ $message }}</p>@enderror
                <span class="flex flex-wrap gap-3">
                    <x-button type="submit" variant="danger" icon="shield-check" data-test="check-confirm">{{ __('Confirm action') }}</x-button>
                </span>
            </form>
        </section>
    </div>

    <x-admin.panel :title="__('Done here')" :meta="__('newest first')" flush data-test="check-log">
        @forelse ($this->log as $entry)
            <div class="flex flex-col gap-0.5 border-t border-hairline py-2 text-[13px] first:border-t-0 sm:flex-row sm:items-baseline sm:gap-4" wire:key="check-log-{{ $entry->id }}" data-test="check-log-row" data-action="{{ $entry->action }}">
                <span class="shrink-0 text-xs text-ink-2 sm:w-[180px]">{{ LeagueTime::stamp($entry->created_at ?? now()) }}</span>
                <span class="min-w-0 [overflow-wrap:anywhere]"><b>{{ $actions[$entry->action][0] ?? $entry->action }}</b> · {{ $entry->note ?? '–' }} <span class="text-ink-2">{{ __('by :name', ['name' => $entry->admin?->displayName() ?? substr($entry->admin_pubkey, 0, 8)]) }}</span></span>
            </div>
        @empty
            <x-admin.empty :text="__('No action yet.')" />
        @endforelse
    </x-admin.panel>
</x-admin.page>
