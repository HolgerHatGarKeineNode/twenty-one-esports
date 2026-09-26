<?php

use App\Models\User;
use App\Support\Board;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignerMessages;
use App\Support\PreSeason;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\ConsensusParameters;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\SeasonChain\SeasonReleaseRefused;
use App\Support\SeasonChain\Seasons;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * AdminSeason (AdminSeason.dc.html), the season chain part (P7c): the chain
 * status (tip, supply issued, halving schedule), the estimator from live
 * data of the last 4 weeks, the release of Block 0 and the rule changes
 * during a season with the public change log.
 *
 * Every admin sees the page; releasing Block 0 and changing rules is for the
 * board (the public admin list) only, checked again in SeasonRelease and
 * SeasonChains. Not built here: the rating, rank and hashrate settings, the
 * soft-reset preview, the season review and settlement (P9).
 */
new #[Title('Seasons')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    public string $supply = '';

    public string $message = '';

    /** The planned end of the release, fixed when the page opens. */
    #[Locked]
    public int $endsAt = 0;

    public string $releaseError = '';

    /** @var array<string, string> `<game>/<mode>` => factor, e.g. "1.5" */
    public array $weights = [];

    /** @var array<string, string> */
    public array $shares = [];

    /** @var array<string, string> */
    public array $daily = [];

    public string $pairDay = '';

    public string $pairSeason = '';

    public string $subtree = '';

    public string $moves = '';

    public string $reason = '';

    public string $changeError = '';

    public string $notice = '';

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->message = (string) PreSeason::genesisMessage();
        $this->endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());
        $this->fillChangeForm();
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function chain(): array
    {
        $season = Seasons::live();

        return $season === null ? app(ChainOverview::class)->draft() : app(ChainOverview::class)->live($season);
    }

    #[Computed]
    public function releaseRefusal(): ?string
    {
        return SeasonRelease::refusal($this->admin());
    }

    #[Computed]
    public function isBoard(): bool
    {
        return Board::contains($this->admin()->pubkey);
    }

    /**
     * Step 1 of the release: the unsigned label for the admin's signer.
     *
     * @return list<array<string, mixed>>|null
     */
    public function prepareRelease(): ?array
    {
        $this->releaseError = '';

        try {
            return [app(SeasonRelease::class)->prepare($this->admin(), $this->supply, $this->message, $this->endsAt)];
        } catch (SeasonReleaseRefused $refused) {
            $this->releaseError = $refused->getMessage();

            return null;
        }
    }

    /** Step 2: the signed label; the league signs the genesis. */
    public function release(string $signed): void
    {
        $this->releaseError = '';

        try {
            app(SeasonRelease::class)->release($this->admin(), $this->supply, $this->message, $this->endsAt, json_decode($signed, true));
        } catch (SeasonReleaseRefused $refused) {
            $this->releaseError = $refused->getMessage();

            return;
        } catch (RejectedEvent) {
            $this->releaseError = __('The signed release was refused. Please start again.');

            return;
        }

        $this->notice = __('Block 0 is released. The Pre-Season chain runs.');
        $this->reset('supply');
        unset($this->chain, $this->releaseRefusal);
        $this->fillChangeForm();
    }

    public function saveChange(): void
    {
        $this->changeError = '';
        $this->notice = '';
        $current = $this->chain['in_force'];

        if (! $current instanceof ConsensusParameters) {
            return;
        }

        try {
            $changes = $this->changedParameters($current);
            app(SeasonChains::class)->changeParameters($this->admin(), $changes, $this->reason, CarbonImmutable::now());
        } catch (SeasonReleaseRefused $refused) {
            $this->changeError = $refused->getMessage();

            return;
        }

        $this->notice = __('Saved. The change counts for blocks saved from now on and is in the public change log.');
        $this->reset('reason');
        unset($this->chain);
        $this->fillChangeForm();
    }

    /**
     * Only what differs from the rules in force; a value that is not a
     * number is refused.
     *
     * @return array{weights?: array<string, int>, shares?: array<string, int>, daily?: array<string, int>, pairlimit?: array{0: int, 1: int}, subtree?: int, moves?: int}
     *
     * @throws SeasonReleaseRefused
     */
    private function changedParameters(ConsensusParameters $current): array
    {
        $int = function (string $value): int {
            if (preg_match('/^\d{1,6}$/', trim($value)) !== 1) {
                throw new SeasonReleaseRefused(__('A value is out of range. Check the limits next to each field.'));
            }

            return (int) trim($value);
        };
        $changes = [];

        foreach ($this->weights as $key => $factor) {
            $normalized = str_replace(',', '.', trim($factor));

            if (preg_match('/^\d{1,2}(\.\d{1,3})?$/', $normalized) !== 1) {
                throw new SeasonReleaseRefused(__('A value is out of range. Check the limits next to each field.'));
            }

            $milli = (int) round((float) $normalized * 1000);

            if ($milli !== $current->weightFor($key)) {
                $changes['weights'][$key] = $milli;
            }
        }

        foreach (['shares' => $this->shares, 'daily' => $this->daily] as $name => $values) {
            foreach ($values as $game => $value) {
                $before = $name === 'shares' ? $current->shareFor($game) : $current->dailyLimitFor($game);

                if ($int($value) !== $before) {
                    $changes[$name][$game] = $int($value);
                }
            }
        }

        if ($int($this->pairDay) !== $current->pairLimitPerDay || $int($this->pairSeason) !== $current->pairLimitPerSeason) {
            $changes['pairlimit'] = [$int($this->pairDay), $int($this->pairSeason)];
        }

        if ($int($this->subtree) !== $current->subtree) {
            $changes['subtree'] = $int($this->subtree);
        }

        if ($int($this->moves) !== $current->moves) {
            $changes['moves'] = $int($this->moves);
        }

        return $changes;
    }

    private function fillChangeForm(): void
    {
        $current = $this->chain['in_force'];

        if (! $current instanceof ConsensusParameters) {
            return;
        }

        $this->weights = array_map(fn (int $milli): string => SeasonRelease::factor($milli), $current->weights);
        $this->shares = array_map(strval(...), $current->shares);
        $this->daily = array_map(strval(...), $current->daily);
        $this->pairDay = (string) $current->pairLimitPerDay;
        $this->pairSeason = (string) $current->pairLimitPerSeason;
        $this->subtree = (string) $current->subtree;
        $this->moves = (string) $current->moves;
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $viewer = auth()->user();
    $zone = PreSeason::timezoneFor($viewer);
    $chain = $this->chain;
    $live = $chain['season'] !== null;
    $season = $chain['season'];
    $sats = fn (int $value): string => PreSeason::formatSats($value);
    $date = fn ($at, string $format = 'D j M Y, H:i'): string => $at->copy()->setTimezone($zone)->locale(app()->getLocale())->translatedFormat($format);
    $estimate = $chain['estimate'];
    $genesisAt = $live ? CarbonImmutable::instance($season->genesis_at) : $chain['now'];
    $milestoneDate = fn (float|int $weeks): CarbonImmutable => $genesisAt->addSeconds((int) round($weeks * 604800));
    $games = array_keys($chain['in_force']->shares + $chain['in_force']->daily);
    $input = 'h-11 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
    $warnings = [
        'season-shorter-than-min-weeks' => __('The season is shorter than :weeks weeks.', ['weeks' => (int) config('season.estimator.min_weeks')]),
        'season-longer-than-max-weeks' => __('The season is longer than :weeks weeks.', ['weeks' => (int) config('season.estimator.max_weeks')]),
        'supply-mined-before-min-weeks' => __('At this rate the pot is mined out in under :weeks weeks.', ['weeks' => (int) config('season.estimator.min_weeks')]),
        'most-of-the-supply-stays-unmined' => __('At this rate most of the pot stays unmined.'),
    ];
@endphp

<div class="flex grow flex-col" data-test="admin-season" data-state="{{ Seasons::state() }}">
    <x-admin.nav active="seasons" />

    <div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12">
        <div class="flex flex-col gap-2">
            <h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ __('Seasons') }}</h1>
            <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('Every season is its own chain. Tournament prize pools stay separate and pay out when their tournament ends.') }}</p>
        </div>

        @if ($notice)
            <p class="m-0 rounded-md bg-win-tint px-4 py-3 text-[13px] text-win shadow-[inset_0_0_0_1px_#1F5A34]" role="status" data-test="season-notice">{{ $notice }}</p>
        @endif

        {{-- Chain status --}}
        <section aria-labelledby="status-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="season-status">
            <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="status-h" class="m-0 text-[15px] font-bold">{{ __('Pre-Season chain') }}</h2>
                <span @class(['inline-flex h-7 items-center rounded-sm px-2.5 text-xs font-bold', 'bg-win-tint text-win' => $live, 'bg-btc-chip text-btc-hi' => ! $live])>{{ $live ? __('live') : __('draft, not released') }}</span>
            </span>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                @foreach ($live ? [
                    [__('Tip'), $chain['tip']['height'] === null ? __('Block 0') : __('Block :height', ['height' => $chain['tip']['height']]), $chain['last_block_at'] ? __('last block :when', ['when' => $date($chain['last_block_at'], 'D H:i')]) : __('no block yet')],
                    [__('Supply issued'), $sats($chain['mined']), __('of :supply sats', ['supply' => $sats($chain['supply'])])],
                    [__('Left in the pot'), $sats($chain['remaining']), __('sats')],
                    [__('Era'), (string) ($chain['era'] ?? '–'), $chain['next_halving'] ? __('next halving :when', ['when' => $date($chain['next_halving'], 'D j M, H:i')]) : __('last era')],
                    [__('Wins that did not mine'), (string) array_sum($chain['rejected']), trans_choice(':count block|:count blocks', $chain['blocks'])],
                ] : [
                    [__('Tip'), __('none'), __('waiting for Block 0')],
                    [__('Supply'), $sats($chain['supply']), __('fixed at Block 0')],
                    [__('Base subsidy'), $sats($chain['parameters']->subsidy), __('per winning player at weight 1')],
                    [__('Eras'), (string) $chain['parameters']->eras(), __(':days days each', ['days' => intdiv($chain['parameters']->halvingSeconds, 86400)])],
                    [__('Planned Block 0'), PreSeason::block0At() ? $date(PreSeason::block0At(), 'D j M, H:i') : __('not set'), __('the countdown on the home page')],
                ] as [$label, $value, $sub])
                    <div @class(['flex min-w-0 flex-col gap-1 rounded-md bg-ground px-3.5 py-3 shadow-ring', 'col-span-2 lg:col-span-1' => $loop->last])>
                        <span class="text-xs text-ink-2">{{ $label }}</span><b class="font-display text-lg leading-tight">{{ $value }}</b><span class="text-xs text-ink-3">{{ $sub }}</span>
                    </div>
                @endforeach
            </div>
            @if ($live)
                <p class="m-0 text-[13px] text-ink-2">{{ __('Block 0 released :when by :name, supply retyped as :supply.', ['when' => $date($season->genesis_at), 'name' => $season->releasedBy?->displayName() ?? substr($season->released_by_pubkey, 0, 8), 'supply' => $sats($season->supply)]) }}</p>
                <x-proof :label="__('published by the league key, NIP rev. 5')" :rows="[
                    [__('Block 0 id'), $season->genesisId()],
                    [__('Parameter digest'), $season->digest],
                    [__('Tip id'), $chain['tip']['id']],
                    [__('League key'), NostrKeys::hexToNpub($season->league_pubkey)],
                ]" />
            @endif
        </section>

        {{-- Halving schedule --}}
        <section aria-labelledby="schedule-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="season-schedule">
            <h2 id="schedule-h" class="m-0 text-[15px] font-bold">{{ __('Halving schedule') }} <span class="font-normal text-ink-3">{{ __('rewards in sats per winning player') }}</span></h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] border-collapse text-left text-xs">
                    <thead class="text-ink-2">
                        <tr class="border-b border-hairline">
                            <th scope="col" class="py-2 pr-3 font-normal">{{ __('Era') }}</th>
                            <th scope="col" class="py-2 pr-3 font-normal">{{ __('From') }}</th>
                            <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('Budget') }}</th>
                            @foreach ($games as $game)
                                <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __(':game cap', ['game' => ChainOverview::gameLabel($game)]) }}</th>
                            @endforeach
                            @foreach (array_keys($chain['rewards_now']) as $key)
                                <th scope="col" class="py-2 pr-3 text-right font-normal" @unless (ChainOverview::mines($key)) data-test="reward-not-open" @endunless>{{ ChainOverview::keyLabel($key) }}@unless (ChainOverview::mines($key)) <span class="text-ink-3">· {{ __('not open') }}</span>@endunless</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($chain['schedule'] as $row)
                            <tr @class(['border-b border-hairline last:border-0', 'text-btc-hi' => $row['current']])>
                                <td class="py-2 pr-3 font-bold">{{ $row['era'] }}</td>
                                <td class="py-2 pr-3 whitespace-nowrap">{{ $date($row['from'], 'D j M') }}</td>
                                <td class="py-2 pr-3 text-right">{{ $sats($row['budget']) }}</td>
                                @foreach ($games as $game)
                                    <td class="py-2 pr-3 text-right">{{ $sats($row['caps'][$game] ?? 0) }}</td>
                                @endforeach
                                @foreach ($row['rewards'] as $key => $reward)
                                    <td class="py-2 pr-3 text-right">{{ ChainOverview::mines((string) $key) ? $sats($reward) : '–' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @unless (collect(array_keys($chain['rewards_now']))->every(fn (string $key): bool => ChainOverview::mines($key)))
                <p class="m-0 text-xs text-ink-2" data-test="rated-chess-not-open">{{ __('Rated chess is not open yet (ESPORTS_RATED_CHESS), so chess wins do not mine and the estimator leaves chess out. The chess weights apply from the day rated blitz opens.') }}</p>
            @endunless
        </section>

        {{-- Estimator --}}
        <section aria-labelledby="estimator-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="season-estimator">
            <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="estimator-h" class="m-0 text-[15px] font-bold">{{ __('Estimator') }} <span class="font-normal text-ink-3">{{ __('milestones = share of the pot mined') }}</span></h2>
                <span class="text-xs text-ink-3">{{ $live ? __('forecast from the blocks of the last 4 weeks') : __('forecast from the finished wins of the last 4 weeks, casual included: nothing is rated before Block 0') }}</span>
            </span>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                @foreach ($estimate['milestone_weeks'] as $share => $weeks)
                    <div class="flex flex-col gap-1 rounded-md bg-ground px-3.5 py-3 shadow-ring">
                        <span class="text-xs text-ink-2">{{ __(':percent % mined', ['percent' => rtrim(rtrim(number_format((float) $share * 100, 1, '.', ''), '0'), '.')]) }}</span>
                        <b class="text-[15px]">{{ $weeks === null ? __('not reached') : __('week :week', ['week' => number_format((float) $weeks, 1)]) }}</b>
                        <span class="text-xs text-ink-3">{{ $weeks === null ? __('before the season end') : $date($milestoneDate($weeks), 'D j M') }}</span>
                    </div>
                @endforeach
                <div class="flex flex-col gap-1 rounded-md bg-ground px-3.5 py-3 shadow-ring">
                    <span class="text-xs text-ink-2">{{ __('Mined at the season end') }}</span><b class="text-[15px]">{{ $sats($estimate['end_mined']) }}</b><span class="text-xs text-ink-3">{{ $estimate['end_mined_percent'] }} %</span>
                </div>
                <div class="flex flex-col gap-1 rounded-md bg-ground px-3.5 py-3 shadow-ring">
                    <span class="text-xs text-ink-2">{{ __('Forecast') }}</span>
                    <b class="text-[15px]">{{ trans_choice(':count win a week|:count wins a week', (int) round(array_sum($estimate['forecast_per_week']))) }}</b>
                    <span class="text-xs text-ink-3">{{ collect($estimate['forecast_per_week'])->map(fn ($n, $key) => ChainOverview::keyLabel((string) $key).' '.number_format((float) $n, 2))->implode(' · ') ?: __('no activity yet') }}</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[480px] border-collapse text-left text-xs">
                    <thead class="text-ink-2"><tr class="border-b border-hairline"><th scope="col" class="py-2 pr-3 font-normal">{{ __('Era') }}</th>@foreach ($games as $game)<th scope="col" class="py-2 pr-3 text-right font-normal">{{ __(':game mined', ['game' => ChainOverview::gameLabel($game)]) }}</th>@endforeach<th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('Payout per week') }}</th><th scope="col" class="py-2 pr-3 font-normal">{{ __('Wins the caps allow') }}</th></tr></thead>
                    <tbody>
                        @foreach ($estimate['per_era'] as $index => $era)
                            <tr class="border-b border-hairline last:border-0">
                                <td class="py-2 pr-3 font-bold">{{ $era['era'] }}</td>
                                @foreach ($games as $game)<td class="py-2 pr-3 text-right">{{ $sats($era['mined'][$game] ?? 0) }}</td>@endforeach
                                <td class="py-2 pr-3 text-right">{{ $sats($estimate['payout_per_week'][$index] ?? 0) }}</td>
                                <td class="py-2 pr-3 text-ink-2">{{ collect($estimate['wins_per_era'][$index] ?? [])->map(fn ($n, $key) => ChainOverview::keyLabel((string) $key).' '.$n)->implode(' · ') ?: '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @foreach ($estimate['warnings'] as $warning)
                <p class="m-0 rounded-md bg-btc-chip px-3.5 py-2.5 text-[13px] text-btc-hi" data-test="estimator-warning">{{ $warnings[$warning] ?? $warning }}</p>
            @endforeach
        </section>

        @unless ($live)
            {{-- Release Block 0 --}}
            <section aria-labelledby="release-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#3A2A12] lg:px-6"
                     x-data="nostrAction({ pubkey: @js($viewer->pubkey), messages: @js(SignerMessages::labels()) })" data-test="release-block0">
                <span class="flex flex-col gap-1">
                    <h2 id="release-h" class="m-0 text-[15px] font-bold">{{ __('Release Block 0') }}</h2>
                    <span class="text-xs text-ink-2">{{ __('Any one board member on the public admin list can release it. Rated play and mining start with it; nothing can be undone.') }}</span>
                </span>
                <ol class="m-0 grid list-none grid-cols-1 gap-4 p-0 lg:grid-cols-3">
                    <li class="flex flex-col gap-2 rounded-md bg-ground px-3.5 py-3 shadow-ring">
                        <b class="text-[13px]">{{ __('1. Check the numbers') }}</b>
                        <dl class="m-0 flex flex-col text-xs">
                            @foreach ([
                                [__('Supply'), $sats($chain['supply']).' sats'],
                                [__('Base subsidy'), $sats($chain['parameters']->subsidy).' sats'],
                                [__('Eras'), __(':eras × :days days', ['eras' => $chain['parameters']->eras(), 'days' => intdiv($chain['parameters']->halvingSeconds, 86400)])],
                                [__('Season end'), $date(CarbonImmutable::createFromTimestamp($this->endsAt))],
                                [__('Mined at the season end'), $sats($estimate['end_mined']).' ('.$estimate['end_mined_percent'].' %)'],
                            ] as [$term, $value])
                                <div class="flex justify-between gap-3 border-b border-hairline py-1.5 last:border-0"><dt class="text-ink-2">{{ $term }}</dt><dd class="m-0 text-right">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                    </li>
                    <li class="flex flex-col gap-2 rounded-md bg-ground px-3.5 py-3 shadow-ring">
                        <label for="genesis-message" class="text-[13px] font-bold">{{ __('2. Genesis message, published with Block 0') }}</label>
                        <textarea id="genesis-message" wire:model="message" rows="4" maxlength="{{ SeasonRelease::MESSAGE_MAX }}" class="w-full rounded-md border border-edge bg-card px-3 py-2 text-[13px] text-ink"></textarea>
                    </li>
                    <li class="flex flex-col gap-2 rounded-md bg-ground px-3.5 py-3 shadow-ring">
                        <label for="retype-supply" class="text-[13px] font-bold">{{ __('3. Type the supply to confirm') }}</label>
                        <input id="retype-supply" type="text" inputmode="numeric" wire:model="supply" autocomplete="off" placeholder="{{ $sats($chain['supply']) }}" class="{{ $input }}" data-test="retype-supply">
                        <button type="button" x-on:click="run('prepareRelease', 'release')" x-bind:disabled="busy" @disabled($this->releaseRefusal !== null)
                                class="btn-p inline-flex h-11 cursor-pointer items-center justify-center rounded-md border-0 bg-btc px-5 text-sm font-bold text-on-btc disabled:cursor-not-allowed disabled:opacity-50" data-test="release-button">{{ __('Release Block 0') }}</button>
                    </li>
                </ol>
                @if ($this->releaseRefusal)
                    <p class="m-0 text-[13px] text-ink-2" data-test="release-refusal">{{ $this->releaseRefusal }}</p>
                @endif
                <p class="m-0 text-[13px] text-loss" role="alert" x-show="error" x-text="error"></p>
                @if ($releaseError)
                    <p class="m-0 text-[13px] text-loss" role="alert" data-test="release-error">{{ $releaseError }}</p>
                @endif
            </section>
        @else
            {{-- Rule changes during the season (2158) --}}
            <section aria-labelledby="change-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="season-change">
                <span class="flex flex-col gap-1">
                    <h2 id="change-h" class="m-0 text-[15px] font-bold">{{ __('Change the chain rules') }}</h2>
                    <span class="text-xs text-ink-2">{{ __('A change counts only for blocks saved after it, never back. Supply, subsidy, eras and the season end stay as released.') }}</span>
                </span>
                <form wire:submit="saveChange" class="flex flex-col gap-4">
                    <fieldset class="m-0 grid grid-cols-1 gap-3 border-0 p-0 sm:grid-cols-2 lg:grid-cols-5" @disabled(! $this->isBoard)>
                        <legend class="mb-2 text-xs text-ink-2">{{ __('Weight per winning player (0 to 10; 0 stops a game from mining)') }}</legend>
                        @foreach ($weights as $key => $value)
                            <label class="flex flex-col gap-1 text-xs text-ink-2">{{ ChainOverview::keyLabel($key) }}<input type="text" inputmode="decimal" wire:model="weights.{{ $key }}" class="{{ $input }}"></label>
                        @endforeach
                    </fieldset>
                    <fieldset class="m-0 grid grid-cols-2 gap-3 border-0 p-0 lg:grid-cols-4" @disabled(! $this->isBoard)>
                        <legend class="mb-2 text-xs text-ink-2">{{ __('Share cap per era (1 to 100 %) and blocks per player a day (1 to 100)') }}</legend>
                        @foreach ($shares as $game => $value)
                            <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __(':game share %', ['game' => ChainOverview::gameLabel($game)]) }}<input type="text" inputmode="numeric" wire:model="shares.{{ $game }}" class="{{ $input }}"></label>
                        @endforeach
                        @foreach ($daily as $game => $value)
                            <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __(':game a day', ['game' => ChainOverview::gameLabel($game)]) }}<input type="text" inputmode="numeric" wire:model="daily.{{ $game }}" class="{{ $input }}"></label>
                        @endforeach
                    </fieldset>
                    <fieldset class="m-0 grid grid-cols-2 gap-3 border-0 p-0 lg:grid-cols-4" @disabled(! $this->isBoard)>
                        <legend class="mb-2 text-xs text-ink-2">{{ __('Consensus rules 4, 8, 7 and 2') }}</legend>
                        <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Blocks per pairing a day') }}<input type="text" inputmode="numeric" wire:model="pairDay" class="{{ $input }}"></label>
                        <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Blocks per pairing a season') }}<input type="text" inputmode="numeric" wire:model="pairSeason" class="{{ $input }}"></label>
                        <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Same trust circle from % (101 = off)') }}<input type="text" inputmode="numeric" wire:model="subtree" class="{{ $input }}"></label>
                        <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Minimum chess moves') }}<input type="text" inputmode="numeric" wire:model="moves" class="{{ $input }}"></label>
                    </fieldset>
                    <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Why are you changing it? Shown in the public change log') }}
                        <input type="text" wire:model="reason" maxlength="{{ SeasonRelease::MESSAGE_MAX }}" class="{{ $input }}" @disabled(! $this->isBoard) data-test="change-reason">
                    </label>
                    <span class="flex flex-wrap items-center gap-3">
                        <button type="submit" @disabled(! $this->isBoard) class="btn-p inline-flex h-11 cursor-pointer items-center rounded-md border-0 bg-btc px-5 text-sm font-bold text-on-btc disabled:cursor-not-allowed disabled:opacity-50" data-test="save-change">{{ __('Save the change') }}</button>
                        @unless ($this->isBoard)<span class="text-xs text-ink-3">{{ __('Only a board member on the public admin list can change the chain rules.') }}</span>@endunless
                    </span>
                    @if ($changeError)
                        <p class="m-0 text-[13px] text-loss" role="alert" data-test="change-error">{{ $changeError }}</p>
                    @endif
                </form>
            </section>

            <section aria-labelledby="log-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="season-log">
                <h2 id="log-h" class="m-0 text-[15px] font-bold">{{ __('Change log') }} <span class="font-normal text-ink-3">{{ __('public, also on the mining page') }}</span></h2>
                @forelse ($chain['changes'] as $change)
                    <div class="flex flex-col gap-1 border-b border-hairline py-2.5 text-[13px] last:border-0">
                        <span class="flex flex-wrap gap-x-3"><b>{{ $date($change->effective_at) }}</b><span class="text-ink-2">{{ __('by :name', ['name' => $change->changedBy?->displayName() ?? substr($change->changed_by_pubkey, 0, 8)]) }}</span></span>
                        <span class="text-ink-2">{{ $change->reason }}</span>
                        <span class="text-xs text-ink-3">{{ collect($change->parameters)->map(fn ($value, $name) => $name.': '.json_encode($value))->implode(' · ') }}</span>
                    </div>
                @empty
                    <p class="m-0 text-[13px] text-ink-2">{{ __('No change since Block 0.') }}</p>
                @endforelse
            </section>
        @endunless
    </div>
</div>
