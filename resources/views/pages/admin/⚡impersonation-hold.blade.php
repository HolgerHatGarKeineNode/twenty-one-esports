<?php

use App\Models\ImpersonationHold;
use App\Models\ListedProfile;
use App\Models\TrustRank;
use App\Models\User;
use App\Support\FairPlay\Bars;
use App\Support\FairPlay\FairPlayRefused;
use App\Support\FairPlay\Impersonation;
use App\Support\LeagueTime;
use App\Support\Nostr\Nip05Names;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\Seasons;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * One impersonation hold (App\Support\FairPlay\Impersonation, plan "Profile
 * standard and impersonation", P2): the held account and the account it
 * looks like side by side (name, picture, NIP-05, first login, trust rank,
 * Verein, the opponents both list), what matched, and the NIP-56 reports of
 * type `impersonation` against the held account from accounts ranked 50 or
 * more. The decision: release, link as the same person (AccountLinks) or bar
 * (Bars, with a reason and an end). The form is deferred: picking a choice
 * and typing write nothing, only the confirm button decides. Admins only.
 *
 * P3: a hold an admin set by hand (`manual`) has no matched account, so it
 * shows the held account alone and cannot be linked from here; an account
 * that never logged in (a member lists it, D13) shows the profile the league
 * read from the relays (ListedProfiles).
 */
new #[Title('Impersonation check')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component
{
    public ImpersonationHold $hold;

    /** `release`, `link` or `bar`; nothing is written until the confirm button. */
    public string $decision = '';

    /** Required to link or bar, saved with the decision; never shown to the player. */
    public string $reason = '';

    /** The end of a bar as a datetime-local value in the league's zone; empty = the end of the live season. */
    public string $until = '';

    public string $notice = '';

    public function mount(ImpersonationHold $hold): void
    {
        Gate::authorize('admin');
        $this->hold = $hold;
    }

    #[Computed]
    public function current(): ImpersonationHold
    {
        return ImpersonationHold::query()->with('decidedBy')->findOrFail($this->hold->id);
    }

    /**
     * Both accounts as the decision needs them, held first.
     *
     * @return array<string, array{pubkey: string, user: User|null, listed: ListedProfile|null, label: string, rank: int|null, nip05: string|null, nip05_checked: bool, league_name: string|null}>
     */
    #[Computed]
    public function sides(): array
    {
        $hold = $this->current;
        $pubkeys = array_values(array_filter([$hold->pubkey, $hold->matched_pubkey]));
        /** @var array<string, User> $users */
        $users = User::query()->whereIn('pubkey', $pubkeys)->get()->keyBy('pubkey')->all();
        /** @var array<string, ListedProfile> $listed */
        $listed = ListedProfile::query()->whereIn('pubkey', $pubkeys)->get()->keyBy('pubkey')->all();
        /** @var array<string, int> $ranks */
        $ranks = TrustRank::query()->whereIn('pubkey', $pubkeys)->pluck('rank', 'pubkey')->all();
        $sides = [];
        $shown = ['held' => [$hold->pubkey, __('Held account')]] + ($hold->matched_pubkey === null ? [] : ['matched' => [$hold->matched_pubkey, __('Looks like')]]);

        foreach ($shown as $side => [$pubkey, $label]) {
            $user = $users[$pubkey] ?? null;
            $sides[$side] = [
                'pubkey' => $pubkey,
                'user' => $user,
                'listed' => $user === null ? ($listed[$pubkey] ?? null) : null,
                'label' => $label,
                'rank' => isset($ranks[$pubkey]) ? (int) $ranks[$pubkey] : null,
                'nip05' => $user?->nip05,
                'nip05_checked' => $user?->nip05_verified_at !== null,
                'league_name' => $user === null ? null : Nip05Names::address($user),
            ];
        }

        return $sides;
    }

    /** @return array{shared: list<string>, mutual: bool} */
    #[Computed]
    public function overlap(): array
    {
        return $this->current->matched_pubkey === null ? ['shared' => [], 'mutual' => false] : Impersonation::overlap($this->current->pubkey, $this->current->matched_pubkey);
    }

    #[Computed]
    public function reports(): int
    {
        return Impersonation::reports($this->current->pubkey);
    }

    /** @return array<string, string> pubkey => display name of the shared opponents shown */
    #[Computed]
    public function sharedNames(): array
    {
        $shared = array_slice($this->overlap['shared'], 0, 8);

        return User::query()->whereIn('pubkey', $shared)->get()->mapWithKeys(fn (User $user): array => [$user->pubkey => $user->displayName()])->all()
            + array_combine($shared, array_map(fn (string $pubkey): string => Str::limit(NostrKeys::hexToNpub($pubkey), 16, '…'), $shared));
    }

    /**
     * The decision: only this action writes. Each choice goes through
     * Impersonation, which checks the admin again and refuses a hold decided
     * meanwhile.
     */
    public function decide(): void
    {
        Gate::authorize('admin');
        $admin = auth()->user();
        abort_unless($admin instanceof User, 403);
        $this->resetErrorBag();
        $this->notice = '';
        $impersonation = app(Impersonation::class);

        try {
            $this->notice = match ($this->decision) {
                'release' => (function () use ($impersonation, $admin): string {
                    $impersonation->release($admin, $this->current, $this->reason);

                    return __('Released. The account plays rated again; the same name and picture are not held again.');
                })(),
                'link' => (function () use ($impersonation, $admin): string {
                    $done = $impersonation->link($admin, $this->current, $this->reason);

                    return __('Linked. Results voided between the accounts: :voided. Prizes withheld: :withheld.', ['voided' => $done['voided'], 'withheld' => $done['withheld']]);
                })(),
                'bar' => (function () use ($impersonation, $admin): string {
                    try {
                        $until = trim($this->until) === '' ? null : LeagueTime::parse($this->until);
                    } catch (InvalidArgumentException $invalid) {
                        throw new FairPlayRefused($invalid->getMessage());
                    }

                    $impersonation->bar($admin, $this->current, $this->reason, $until);

                    return __('Barred from rated play. The hold has ended.');
                })(),
                default => throw new FairPlayRefused(__('Pick a decision first.')),
            };
        } catch (FairPlayRefused $refused) {
            $this->addError('decision', $refused->getMessage());
        }

        unset($this->current);
    }

    public function name(?string $pubkey): string
    {
        foreach ($this->sides as $side) {
            if ($side['pubkey'] === $pubkey && $side['user'] !== null) {
                return $side['user']->displayName();
            }

            if ($side['pubkey'] === $pubkey && $side['listed']?->name !== null) {
                return (string) $side['listed']->name;
            }
        }

        return NostrKeys::isHexPubkey($pubkey) ? Str::limit(NostrKeys::hexToNpub($pubkey), 16, '…') : '–';
    }
}; ?>

@php
    $hold = $this->current;
    $sides = $this->sides;
    $overlap = $this->overlap;
    $open = $hold->isOpen();
    $input = 'rv-in';
    $seasonEnds = Seasons::live()?->ends_at;
    $matchedLabel = match ($hold->matched) {
        'both' => __('Name and picture'),
        'picture' => __('Picture'),
        'manual' => __('Held for review'),
        default => __('Name'),
    };
    $choices = [
        'release' => [__('Release'), __('Not an impersonation: rated play opens again.')],
        'link' => [__('Same person'), __('Link to :name as its main account. Results between them are voided.', ['name' => $this->name($hold->matched_pubkey)])],
        'bar' => [__('Bar from rated play'), __('With a reason and an end, like a bar by hand.')],
    ];
    if ($hold->isManual()) {
        unset($choices['link']);
    }
@endphp

<x-admin.page active="checks" :title="__('Impersonation check')" :crumbs="[[__('Checks'), route('admin.checks')]]" :notice="$notice" notice-test="hold-notice" data-test="admin-hold">
    <x-slot:badge>
        <span @class(['inline-flex h-9 items-center gap-2 rounded-md px-3.5 text-[13px] font-bold', 'bg-loss-tint text-loss' => $open, 'bg-win-tint text-win' => ! $open]) data-test="hold-state" data-open="{{ $open ? '1' : '0' }}">
            {{ $open ? __('Open since :time', ['time' => LeagueTime::stamp($hold->detected_at)]) : __('Decided :time', ['time' => LeagueTime::stamp($hold->decided_at ?? now())]) }}
        </span>
    </x-slot:badge>
    <x-slot:readout>
        <span class="flex items-baseline gap-2"><span class="rv-m">{{ __('reports') }}</span><span class="rv-num text-base {{ $this->reports > 0 ? 'text-btc-hi' : 'text-ink' }}" data-test="readout-value">{{ $this->reports }}</span></span>
    </x-slot:readout>

    <p class="m-0 flex flex-wrap items-center gap-2 text-[13px] text-ink-2" data-test="hold-matched">
        <span>{{ __('Matched') }}</span><span class="rv-tag is-btc h-auto min-h-5 py-0.5 whitespace-normal" data-matched="{{ $hold->matched }}">{{ $matchedLabel }}</span>
        <span data-test="hold-reports">{{ trans_choice(':count impersonation report from accounts ranked 50 or more|:count impersonation reports from accounts ranked 50 or more', $this->reports) }}</span>
    </p>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2" data-test="hold-sides">
        @foreach ($sides as $key => $side)
            @php($user = $side['user'])
            <section aria-labelledby="side-{{ $key }}" class="flex min-w-0 flex-col gap-3 rounded border border-hairline bg-card px-4 py-4 lg:px-6" data-test="hold-side" data-side="{{ $key }}">
                <span class="flex min-w-0 items-center gap-3">
                    @if ($user)
                        <x-avatar :user="$user" :size="56" class="shrink-0 rounded-md" />
                    @endif
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span id="side-{{ $key }}" class="text-xs text-ink-3">{{ $side['label'] }}</span>
                        <b class="text-[15px] [overflow-wrap:anywhere]" data-test="hold-side-name">{{ $user?->displayName() ?? $this->name($side['pubkey']) }}</b>
                        @if ($user)
                            <a href="{{ route('admin.checks.player', $user->pubkey) }}" class="inline-flex min-h-11 items-center text-xs text-btc underline" wire:navigate data-test="hold-side-check">{{ __('Account check') }}</a>
                        @elseif ($side['listed'])
                            <span class="text-xs text-ink-3" data-test="hold-side-listed">{{ __('Never logged in; profile read from the relays, on a member’s list since :time', ['time' => LeagueTime::stamp($side['listed']->first_seen_at)]) }}</span>
                        @endif
                    </span>
                </span>
                <div class="flex flex-col">
                    @foreach ([
                        [__('Name'), $user?->name ?? $side['listed']?->name ?? '–', 'name'],
                        [__('Picture'), $user?->picture ?? $side['listed']?->picture ?? '–', 'picture'],
                        [__('NIP-05'), $side['nip05'] === null ? '–' : $side['nip05'].' · '.($side['nip05_checked'] ? __('checked') : __('not checked')), 'nip05'],
                        [__('League name'), $side['league_name'] ?? '–', 'league-name'],
                        [__('First login'), $user?->created_at ? LeagueTime::stamp($user->created_at) : '–', 'first-login'],
                        [__('Trust rank'), $side['rank'] === null ? '–' : (string) $side['rank'], 'rank'],
                        [__('Verein'), $user?->is_member ? __('member') : __('not a member'), 'member'],
                        [__('npub'), $user?->npub ?? NostrKeys::hexToNpub($side['pubkey']), 'npub'],
                    ] as [$label, $value, $test])
                        <div class="grid min-h-11 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-1.5 text-[13px] last:border-0" data-test="hold-row-{{ $test }}">
                            <span class="text-ink-2">{{ $label }}</span><span class="min-w-0 [overflow-wrap:anywhere]">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    @if ($hold->matched_pubkey !== null)
    <x-admin.panel :title="__('Opponent lists')" :meta="$overlap['mutual'] ? __('they list each other') : __('they do not list each other')" data-test="hold-overlap">
        <p class="m-0 text-[13px] text-ink-2" data-test="hold-shared">{{ trans_choice(':count opponent on both lists|:count opponents on both lists', count($overlap['shared'])) }}</p>
        @if ($overlap['shared'] !== [])
            <span class="flex flex-wrap gap-1.5">
                @foreach ($this->sharedNames as $name)
                    <span class="rv-tag h-auto min-h-5 py-0.5 whitespace-normal">{{ $name }}</span>
                @endforeach
            </span>
        @endif
    </x-admin.panel>
    @endif

    <section aria-labelledby="dec-h" class="flex flex-col gap-4 rounded border border-hairline bg-card px-4 py-5 lg:px-6" data-test="hold-decision">
        <h2 id="dec-h" class="rv-h3">{{ __('Decision') }}</h2>
        @if (! $open)
            <p class="m-0 text-[13px] text-ink-2" data-test="hold-decided" data-decision="{{ $hold->decision }}">{{ __(':decision by :name on :time: :note', [
                'decision' => match ($hold->decision) { 'released' => __('Release'), 'linked' => __('Same person'), default => __('Bar from rated play') },
                'name' => $hold->decidedBy?->displayName() ?? $this->name((string) $hold->decided_by_pubkey),
                'time' => LeagueTime::stamp($hold->decided_at ?? now()),
                'note' => $hold->note ?? '–',
            ]) }}</p>
        @else
            <form wire:submit="decide" wire:confirm="{{ __('Decide this hold now?') }}" class="flex flex-col gap-4" data-test="hold-form">
                <div role="radiogroup" aria-labelledby="dec-h" @class(['grid grid-cols-1 gap-3', 'lg:grid-cols-3' => count($choices) === 3, 'lg:grid-cols-2' => count($choices) === 2])>
                    @foreach ($choices as $value => [$title, $text])
                        <button type="button" role="radio" wire:key="choice-{{ $value }}" wire:click="$set('decision', '{{ $value }}')" aria-checked="{{ $decision === $value ? 'true' : 'false' }}" class="rv-opt h-auto items-start py-3" data-test="hold-choice-{{ $value }}">
                            <i class="mt-0.5"></i><span class="flex min-w-0 flex-col gap-0.5"><b class="text-[13px]">{{ $title }}</b><span class="text-xs leading-normal text-ink-2">{{ $text }}</span></span>
                        </button>
                    @endforeach
                </div>
                <label class="flex max-w-xl flex-col gap-1.5 text-xs text-ink-2">{{ __('Reason for your decision (required to link or bar, saved with it, never shown to the player)') }}
                    <input type="text" wire:model="reason" maxlength="{{ Bars::REASON_MAX }}" class="{{ $input }}" data-test="hold-reason">
                </label>
                @if ($decision === 'bar')
                    <label class="flex max-w-xl flex-col gap-1.5 text-xs text-ink-2" wire:key="hold-until">{{ __('Barred until (:zone)', ['zone' => LeagueTime::zoneName()]) }}
                        <input type="datetime-local" wire:model="until" class="{{ $input }}" data-test="hold-until">
                        <span class="text-xs text-ink-3">{{ $seasonEnds ? __('Empty: until the end of the live season, :time.', ['time' => LeagueTime::stamp($seasonEnds)]) : __('No season is live: give the bar an end.') }}</span>
                    </label>
                @endif
                @error('decision')<p class="m-0 text-[13px] text-loss" role="alert" data-test="hold-error">{{ $message }}</p>@enderror
                <span class="flex flex-wrap gap-3">
                    <x-button type="submit" variant="danger" icon="shield-check" data-test="hold-confirm">{{ __('Confirm decision') }}</x-button>
                </span>
            </form>
        @endif
    </section>
</x-admin.page>
