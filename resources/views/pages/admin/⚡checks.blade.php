<?php

use App\Models\ImpersonationHold;
use App\Models\Season;
use App\Models\User;
use App\Support\FairPlay\Checks;
use App\Support\FairPlay\Impersonation;
use App\Support\Formats;
use App\Support\LeagueTime;
use App\Support\Nostr\NostrKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The checking view (App\Support\FairPlay\Checks): the players of the live
 * season who look conspicuous, each with the chips that put them here. The
 * page only reads. "Bar…" leads to the bar form of /admin/fair-play with the
 * player picked; the admin still gives a reason there and presses the button.
 * Nothing is punished automatically and no player ever sees this list.
 *
 * On top, the open impersonation holds (App\Support\FairPlay\Impersonation):
 * each leads to its decision page with both profiles side by side. Shown
 * while the hold is switched on, or while a hold is still open.
 */
new #[Title('Checks')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component
{
    /** Also the players without any chip. */
    #[Url(except: false)]
    public bool $all = false;

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    #[Computed]
    public function season(): ?Season
    {
        return Checks::season();
    }

    /**
     * @return array{rows: list<array{pubkey: string, name: string, npub: string|null, blocks: int, sats: int, signals: list<array{key: string, label: string}>, states: list<array{key: string, label: string}>, opponents: list<array{name: string, won: int, lost: int}>}>, players: int, flagged: int, truncated: bool}
     */
    #[Computed]
    public function checks(): array
    {
        Gate::authorize('admin');

        return $this->season === null ? ['rows' => [], 'players' => 0, 'flagged' => 0, 'truncated' => false] : app(Checks::class)->read($this->season, $this->all);
    }

    /**
     * The open impersonation holds, oldest first.
     *
     * @return Collection<int, ImpersonationHold>
     */
    #[Computed]
    public function holds(): Collection
    {
        Gate::authorize('admin');

        return ImpersonationHold::query()->open()->orderBy('detected_at')->orderBy('id')->limit(100)->get();
    }

    /** @return array<string, string> pubkey => display name of every account the holds name */
    #[Computed]
    public function holdNames(): array
    {
        $pubkeys = $this->holds->flatMap(fn (ImpersonationHold $hold): array => [$hold->pubkey, $hold->matched_pubkey])->unique()->values()->all();

        return User::query()->whereIn('pubkey', $pubkeys)->get(['id', 'pubkey', 'npub', 'name'])->mapWithKeys(fn (User $user): array => [$user->pubkey => $user->displayName()])->all();
    }

    public function holdName(string $pubkey): string
    {
        return $this->holdNames[$pubkey] ?? (NostrKeys::isHexPubkey($pubkey) ? Str::limit(NostrKeys::hexToNpub($pubkey), 16, '…') : '–');
    }

    public function toggleAll(): void
    {
        Gate::authorize('admin');
        $this->all = ! $this->all;
        unset($this->checks);
    }
}; ?>

@php
    $checks = $this->checks;
    $listed = $all ? $checks['players'] : $checks['flagged'];
@endphp

<x-admin.page active="checks" :title="__('Checks')" :lead="__('Players of the live season who look conspicuous. The page only shows: nothing is barred automatically, and players never see it.')" data-test="admin-checks">
    <x-slot:readout>
        <span class="flex items-baseline gap-2"><span class="rv-m">{{ __('conspicuous') }}</span><span class="rv-num text-base {{ $checks['flagged'] > 0 ? 'text-btc-hi' : 'text-ink' }}" data-test="readout-value">{{ $checks['flagged'] }}</span></span><span class="flex items-baseline gap-2"><span class="rv-m">{{ __('players') }}</span><span class="rv-num text-base text-ink" data-test="checks-players">{{ $checks['players'] }}</span></span>
    </x-slot:readout>

    @if (Impersonation::enabled() || $this->holds->isNotEmpty())
        <x-admin.panel :title="__('Held accounts')" :meta="__('look like another account, oldest first')" flush data-test="checks-holds">
            @forelse ($this->holds as $hold)
                <div class="flex flex-col gap-2 border-t border-hairline py-3 first:border-t-0 lg:flex-row lg:items-center lg:justify-between lg:gap-4" wire:key="hold-{{ $hold->id }}" data-test="checks-hold" data-hold="{{ $hold->id }}">
                    <span class="flex min-w-0 flex-col gap-0.5 text-[13px]">
                        <b class="[overflow-wrap:anywhere]" data-test="checks-hold-name">{{ $this->holdName($hold->pubkey) }}</b>
                        <span class="text-xs text-ink-2 [overflow-wrap:anywhere]">{{ __('looks like :name', ['name' => $this->holdName($hold->matched_pubkey)]) }} · {{ match ($hold->matched) { 'both' => __('name and picture'), 'picture' => __('picture'), default => __('name') } }} · {{ LeagueTime::stamp($hold->detected_at) }}</span>
                    </span>
                    <span class="flex shrink-0 flex-wrap gap-2">
                        <x-button variant="secondary" :href="route('admin.checks.hold', $hold)" data-test="checks-hold-decide">{{ __('Decide…') }}</x-button>
                    </span>
                </div>
            @empty
                <x-admin.empty :text="__('No account is held. An account whose name or picture looks like another account\'s is listed here until you decide.')" data-test="checks-holds-empty" />
            @endforelse
        </x-admin.panel>
    @endif

    <x-admin.panel :title="$all ? __('All players of the season') : __('Conspicuous players')" :meta="__('most chips first')" flush data-test="checks-list">
        @if ($this->season !== null && $checks['players'] > 0)
            <x-slot:actions>
                <x-button variant="secondary" wire:click="toggleAll" data-test="checks-toggle-all" aria-pressed="{{ $all ? 'true' : 'false' }}">{{ $all ? __('Only conspicuous players') : __('All players') }}</x-button>
            </x-slot:actions>
        @endif

        @if ($this->season === null)
            <x-admin.empty :text="__('No season is live. The checks start with Block 0 and read the live season only.')" data-test="checks-empty" data-empty="no-season" />
        @elseif ($checks['players'] === 0)
            <x-admin.empty :text="__('Nobody has mined yet. A player appears here with the first win that mines or tries to mine a block.')" data-test="checks-empty" data-empty="no-players" />
        @elseif ($checks['rows'] === [])
            <x-admin.empty :text="__('Nobody is conspicuous. Switch to all players to see everybody who mined.')" data-test="checks-empty" data-empty="no-signals" />
        @else
            @if ($checks['truncated'])
                <p class="m-0 text-xs text-btc-hi" data-test="checks-truncated">{{ __('The season holds more wins than this page reads; the oldest are left out.') }}</p>
            @endif
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($checks['rows'] as $row)
                    <li class="flex flex-col gap-2 border-t border-hairline py-3 first:border-t-0" wire:key="check-{{ $row['pubkey'] }}" data-test="checks-row" data-player="{{ $row['npub'] ?? $row['pubkey'] }}" data-signals="{{ count($row['signals']) }}">
                        <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between lg:gap-4">
                            <span class="flex min-w-0 flex-col gap-0.5 text-[13px]">
                                <b class="[overflow-wrap:anywhere]" data-test="checks-name">{{ $row['name'] }}</b>
                                <span class="text-xs text-ink-2" data-test="checks-mined">{{ trans_choice(':count block|:count blocks', $row['blocks']) }} · <span class="rv-num">{{ Formats::number($row['sats']) }}</span> sat</span>
                            </span>
                            <span class="flex shrink-0 flex-wrap gap-2">
                                @if ($row['npub'] !== null)
                                    <x-button variant="secondary" :href="route('players.show', $row['npub'])" data-test="checks-player-page">{{ __('Player page') }}</x-button>
                                    <x-button variant="danger" icon="shield-check" :href="route('admin.fair-play', ['bar' => $row['npub']]).'#fair-play-bar'" data-test="checks-bar">{{ __('Bar…') }}</x-button>
                                @endif
                            </span>
                        </div>
                        @if ($row['signals'] !== [] || $row['states'] !== [])
                            <span class="flex flex-wrap gap-1.5" data-test="checks-chips">
                                @foreach ($row['signals'] as $chip)
                                    <span class="rv-tag is-btc h-auto min-h-5 py-0.5 whitespace-normal" data-test="checks-chip" data-signal="{{ $chip['key'] }}">{{ $chip['label'] }}</span>
                                @endforeach
                                @foreach ($row['states'] as $chip)
                                    <span class="rv-tag is-bad h-auto min-h-5 py-0.5 whitespace-normal" data-test="checks-state" data-state="{{ $chip['key'] }}">{{ $chip['label'] }}</span>
                                @endforeach
                            </span>
                        @endif
                        @if ($row['opponents'] !== [])
                            {{-- wire:ignore.self: a round trip of the page (the "all players" switch) morphs the row, and its `open` (closed on the server) would snap it shut. --}}
                            <details wire:ignore.self wire:key="check-opponents-{{ $row['pubkey'] }}" class="text-[13px]" data-test="checks-opponents">
                                <summary class="inline-flex min-h-11 cursor-pointer items-center text-xs text-ink-2">{{ trans_choice(':count opponent, blocks each way|:count opponents, blocks each way', count($row['opponents'])) }}</summary>
                                <ul class="m-0 flex max-w-xl list-none flex-col p-0">
                                    @foreach ($row['opponents'] as $opponent)
                                        <li class="flex items-baseline justify-between gap-4 border-t border-hairline py-1.5 first:border-t-0" data-test="checks-opponent">
                                            <span class="min-w-0 [overflow-wrap:anywhere]">{{ $opponent['name'] }}</span>
                                            <span class="rv-num shrink-0 text-xs text-ink-2">{{ __(':won won · :lost lost', ['won' => $opponent['won'], 'lost' => $opponent['lost']]) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($listed > count($checks['rows']))
                <p class="m-0 text-xs text-ink-2" data-test="checks-more">{{ __('Showing :shown of :total.', ['shown' => count($checks['rows']), 'total' => $listed]) }}</p>
            @endif
        @endif
    </x-admin.panel>
</x-admin.page>
