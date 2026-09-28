<?php

use App\Models\Rating;
use App\Models\Season;
use App\Models\SeasonPlan;
use App\Models\SeasonSettingChange;
use App\Models\User;
use App\Support\Board;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignerMessages;
use App\Support\PreSeason;
use App\Support\Rating\RankTiers;
use App\Support\Rating\RatingSettings;
use App\Support\Rating\SoftReset;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\ConsensusParameters;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonPlans;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\SeasonChain\SeasonReleaseRefused;
use App\Support\SeasonChain\SeasonReview;
use App\Support\SeasonChain\Seasons;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * AdminSeason (AdminSeason.dc.html), the season chain part (P7c): the chain
 * status (tip, supply issued, halving schedule), the estimator from live
 * data of the last 4 weeks, the release of Block 0 and the rule changes
 * during a season with the public change log.
 *
 * P35: the rating, rank and hashrate values for Block 0 (RatingSettings,
 * which documents per value why it is editable and when it locks) with the
 * log of who changed what, the read-only soft-reset preview (SoftReset) and
 * the review of the last ended season (SeasonReview). There is no settle
 * action: the season-chain payout is not built.
 *
 * P38: the season planner (SeasonPlans) for the season after the newest
 * one, with its log; the release of Block 0 then releases the planned
 * season with the soft reset, and the soft-reset preview starts at the
 * planned f.
 *
 * Every admin sees the page; editing the rating draft, releasing Block 0,
 * planning a season and changing chain rules is for the board (the public
 * admin list) only (P39), shown read-only to the others and checked again
 * in RatingSettings, SeasonRelease, SeasonPlans and SeasonChains.
 */
new #[Title('Seasons')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    use WithPagination;

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

    /** @var array<string, string> the rating draft, `start` … `daily_pair_limit` */
    public array $rating = [];

    /** @var array<string, string> tier token => minimum rating */
    public array $tiers = [];

    /** @var array<string, string> `win`, `draw`, `loss`, `team_win_bonus` */
    public array $hashrate = [];

    public string $settingsError = '';

    /** The soft-reset preview's carry-over factor f: the planned one, else 0.5 as in the NIP examples. */
    public string $resetFactor = '0.5';

    public string $planName = '';

    /** The planned Block 0 as `Y-m-d\TH:i` in the viewer's time zone (a datetime-local input). */
    public string $planStartsAt = '';

    public string $planWeeks = '';

    public string $planFactor = '';

    public string $planError = '';

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->message = (string) PreSeason::genesisMessage();
        $this->endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());
        $this->fillChangeForm();
        $this->fillSettingsForm();
        $this->fillPlanForm();
    }

    /** The season the planner plans after: the newest one, live or ended. */
    #[Computed]
    public function planAfter(): ?Season
    {
        return Seasons::latest();
    }

    #[Computed]
    public function plan(): ?SeasonPlan
    {
        return SeasonPlans::current();
    }

    #[Computed]
    public function planRefusal(): ?string
    {
        return SeasonPlans::refusal($this->admin());
    }

    /**
     * @return Collection<int, SeasonPlan>
     */
    #[Computed]
    public function planLog(): Collection
    {
        $after = $this->planAfter;

        return $after === null ? new Collection : SeasonPlan::query()->with('changedBy')->where('after_season_id', $after->id)->latest('id')->limit(20)->get();
    }

    /** Save the plan of the next season (board only, checked again in SeasonPlans), with its log row. */
    public function savePlan(): void
    {
        Gate::authorize('admin');

        $this->planError = '';
        $this->notice = '';
        $zone = PreSeason::timezoneFor($this->admin());
        $startsAt = null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $this->planStartsAt) === 1) {
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->planStartsAt, $zone) ?: null;
        }

        try {
            $plan = app(SeasonPlans::class)->save($this->admin(), $this->planName, $startsAt, $this->planWeeks, $this->planFactor);
        } catch (SeasonReleaseRefused $refused) {
            $this->planError = $refused->getMessage();

            return;
        }

        $this->notice = $plan === null ? __('Nothing changed.') : __('Saved and announced. :season can be released from its Block 0 on.', ['season' => $plan->name]);
        unset($this->plan, $this->planLog, $this->releaseRefusal);
        $this->endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());
        $this->fillPlanForm();
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

    /** The rating draft cannot change once a season has been released. */
    #[Computed]
    public function settingsLocked(): bool
    {
        return RatingSettings::locked();
    }

    /**
     * @return Collection<int, SeasonSettingChange>
     */
    #[Computed]
    public function settingsLog(): Collection
    {
        return SeasonSettingChange::query()->with('changedBy')->latest('id')->limit(20)->get();
    }

    /** The season the soft reset would carry over from: the newest one, live or ended. */
    #[Computed]
    public function resetFrom(): ?Season
    {
        return Seasons::latest();
    }

    /** The factor in thousandths, or null while the input is not a factor from 0 to 1. */
    #[Computed]
    public function resetFactorMilli(): ?int
    {
        return SoftReset::factorMilli($this->resetFactor);
    }

    /**
     * @return LengthAwarePaginator<int, Rating>|null
     */
    #[Computed]
    public function resetRows(): ?LengthAwarePaginator
    {
        return $this->resetFrom === null ? null : SoftReset::carriedOver($this->resetFrom)->paginate(25, pageName: 'reset');
    }

    #[Computed]
    public function endedSeason(): ?Season
    {
        return SeasonReview::latestEnded();
    }

    /**
     * @return array{champions: list<array{ladder: string, name: string, rating: int, results: int, tier: string}>, blocks: int, mined: int, remaining: int, supply: int, rejected: int}|null
     */
    #[Computed]
    public function review(): ?array
    {
        return $this->endedSeason === null ? null : app(SeasonReview::class)->of($this->endedSeason);
    }

    public function updatedResetFactor(): void
    {
        $this->resetPage('reset');
    }

    /** Save the rating, rank and hashrate draft for Block 0, with the audit row. */
    public function saveSettings(): void
    {
        Gate::authorize('admin');

        $this->settingsError = '';
        $this->notice = '';

        try {
            $change = RatingSettings::saveDraft($this->admin(), $this->settingsValues());
        } catch (SeasonReleaseRefused $refused) {
            $this->settingsError = $refused->getMessage();

            return;
        }

        $this->notice = $change === null ? __('Nothing changed.') : __('Saved. Block 0 releases the season with these values.');
        unset($this->settingsLog);
        $this->fillSettingsForm();
    }

    /**
     * Field labels, by dot path.
     *
     * @return array<string, string>
     */
    public function settingLabels(): array
    {
        return [
            'rating.start' => __('Start rating'),
            'rating.k' => __('K-factor'),
            'rating.provisional_k' => __('K while provisional'),
            'rating.provisional' => __('Results until a rank'),
            'rating.scale' => __('Rating scale'),
            'rating.daily_pair_limit' => __('Pairing limit per day'),
            'hashrate.win' => __('Hashrate for a win'),
            'hashrate.draw' => __('Hashrate for a draw'),
            'hashrate.loss' => __('Hashrate for a loss'),
            'hashrate.team_win_bonus' => __('Bonus for a team win'),
        ];
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
        $name = SeasonRelease::seasonName();

        try {
            app(SeasonRelease::class)->release($this->admin(), $this->supply, $this->message, $this->endsAt, json_decode($signed, true));
        } catch (SeasonReleaseRefused $refused) {
            $this->releaseError = $refused->getMessage();

            return;
        } catch (RejectedEvent) {
            $this->releaseError = __('The signed release was refused. Please start again.');

            return;
        }

        $this->notice = __('Block 0 is released. The :season chain runs.', ['season' => $name]);
        $this->reset('supply');
        unset($this->chain, $this->releaseRefusal, $this->planAfter, $this->plan, $this->planLog, $this->planRefusal, $this->settingsLocked, $this->resetFrom);
        $this->fillChangeForm();
        $this->fillSettingsForm();
        $this->fillPlanForm();
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

    /**
     * The validated draft from the form. Only the known keys are read, so a
     * key added from the browser is ignored; the lowest tier stays at 0.
     *
     * @return array{rating: array{start: int, k: int, provisional_k: int, provisional: int, scale: int, daily_pair_limit: int|null}, tiers: array<string, int>, hashrate: array{win: int, draw: int, loss: int, team_win_bonus: int}}
     *
     * @throws SeasonReleaseRefused
     */
    private function settingsValues(): array
    {
        $defaults = RatingSettings::defaults();
        $labels = $this->settingLabels();
        $number = function (mixed $value, string $limit, string $field): int {
            [$min, $max] = RatingSettings::LIMITS[$limit];
            $value = is_scalar($value) ? trim((string) $value) : '';

            if (preg_match('/^\d{1,5}$/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
                throw new SeasonReleaseRefused(__(':field must be a whole number from :min to :max.', ['field' => $field, 'min' => $min, 'max' => $max]));
            }

            return (int) $value;
        };

        $rating = [];

        foreach (array_keys($defaults['rating']) as $key) {
            $raw = $this->rating[$key] ?? '';

            // An empty pairing limit means no limit, as `null` in config/season.php.
            $rating[$key] = $key === 'daily_pair_limit' && trim((string) $raw) === ''
                ? null
                : $number($raw, 'rating.'.$key, $labels['rating.'.$key]);
        }

        $tiers = [];
        $previous = null;

        foreach (array_keys($defaults['tiers']) as $index => $token) {
            $minimum = $index === 0 ? 0 : $number($this->tiers[$token] ?? '', 'tiers', RankTiers::label($token));

            if ($previous !== null && $minimum <= $previous) {
                throw new SeasonReleaseRefused(__(':tier must start above the rank below it.', ['tier' => RankTiers::label($token)]));
            }

            $tiers[$token] = $previous = $minimum;
        }

        $hashrate = [];

        foreach (array_keys($defaults['hashrate']) as $key) {
            $hashrate[$key] = $number($this->hashrate[$key] ?? '', 'hashrate', $labels['hashrate.'.$key]);
        }

        /** @var array{start: int, k: int, provisional_k: int, provisional: int, scale: int, daily_pair_limit: int|null} $rating */
        /** @var array{win: int, draw: int, loss: int, team_win_bonus: int} $hashrate */
        return ['rating' => $rating, 'tiers' => $tiers, 'hashrate' => $hashrate];
    }

    /** The draft while it can change, else what the newest season froze. */
    private function fillSettingsForm(): void
    {
        $values = RatingSettings::locked() ? RatingSettings::inForce() : RatingSettings::draft();
        $text = fn (?int $value): string => $value === null ? '' : (string) $value;

        $this->rating = array_map($text, $values['rating']);
        $this->tiers = array_map($text, $values['tiers']);
        $this->hashrate = array_map($text, $values['hashrate']);
    }

    /** The plan in force, else the defaults for the season after the newest one. */
    private function fillPlanForm(): void
    {
        $after = Seasons::latest();

        if ($after === null) {
            return;
        }

        $plan = SeasonPlans::current();
        $values = $plan === null ? SeasonPlans::defaults($after) : ['name' => $plan->name, 'starts_at' => CarbonImmutable::instance($plan->starts_at), 'weeks' => $plan->weeks, 'reset_factor_milli' => $plan->reset_factor_milli];

        $this->planName = $values['name'];
        $this->planStartsAt = $values['starts_at']->setTimezone(PreSeason::timezoneFor($this->admin()))->format('Y-m-d\TH:i');
        $this->planWeeks = (string) $values['weeks'];
        $this->planFactor = SeasonRelease::factor($values['reset_factor_milli']);

        if ($plan !== null) {
            $this->resetFactor = $this->planFactor;
        }
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

<x-admin.page active="seasons" :title="__('Seasons')" :lead="__('Every season is its own chain. Tournament prize pools stay separate and pay out when their tournament ends.')" :notice="$notice" notice-test="season-notice" data-test="admin-season" data-state="{{ Seasons::state() }}">
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
                <x-admin.empty :text="__('No change since Block 0.')" />
            @endforelse
        </section>
    @endunless

    {{-- Season planner (P38, SeasonPlans) --}}
    @php($after = $this->planAfter)
    @php($plan = $this->plan)
    <x-admin.panel :title="__('Next season')" :meta="$plan ? __(':slug, announced for Block 0 :when', ['slug' => $plan->slug, 'when' => $date(CarbonImmutable::instance($plan->starts_at))]) : __('not planned')" data-test="season-planner">
        @if ($after === null)
            <x-admin.empty :text="__('The planner is for the seasons after the Pre-Season. Release the Pre-Season first.')" />
        @else
            <p class="m-0 text-xs text-ink-2">{{ __('Plan the season after :season. Saving announces it with its planned Block 0; any one board member releases it above from then on, once :season has ended. The release carries every rating over with the soft reset: next start = start rating + (final rating − start rating) × f, rounded.', ['season' => $after->slug]) }}</p>
            <form wire:submit="savePlan" class="flex flex-col gap-4">
                <fieldset class="m-0 grid grid-cols-1 gap-3 border-0 p-0 sm:grid-cols-2 lg:grid-cols-4" @disabled($this->planRefusal !== null)>
                    <legend class="mb-2 text-xs text-ink-2">{{ __(':slug, rating values from the section below', ['slug' => SeasonPlans::nextSlug($after)]) }}</legend>
                    <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ __('Name') }}
                        <input type="text" wire:model="planName" maxlength="{{ SeasonPlans::NAME_MAX }}" class="{{ $input }}" data-test="plan-name">
                    </label>
                    <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ __('Block 0 (:zone)', ['zone' => $zone]) }}
                        <input type="datetime-local" wire:model="planStartsAt" class="{{ $input }}" data-test="plan-starts-at">
                        <span class="text-ink-3">{{ __('after :season ends, :when', ['season' => $after->slug, 'when' => $date(CarbonImmutable::instance($after->ends_at), 'D j M, H:i')]) }}</span>
                    </label>
                    <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ __('Length in weeks') }}
                        <input type="text" inputmode="numeric" wire:model="planWeeks" class="{{ $input }}" data-test="plan-weeks">
                        <span class="text-ink-3">{{ __(':min to :max', ['min' => SeasonPlans::minWeeks(), 'max' => SeasonPlans::maxWeeks()]) }}</span>
                    </label>
                    <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ __('Carry-over factor f (0 to 1)') }}
                        <input type="text" inputmode="decimal" wire:model="planFactor" class="{{ $input }}" data-test="plan-factor">
                        <span class="text-ink-3">{{ __('0 resets everyone, 1 keeps every rating') }}</span>
                    </label>
                </fieldset>
                <span class="flex flex-wrap items-center gap-3">
                    <button type="submit" @disabled($this->planRefusal !== null) class="btn-p inline-flex h-11 cursor-pointer items-center rounded-md border-0 bg-btc px-5 text-sm font-bold text-on-btc disabled:cursor-not-allowed disabled:opacity-50" data-test="save-plan">{{ __('Save and announce') }}</button>
                    @if ($this->planRefusal)<span class="text-xs text-ink-3" data-test="plan-refusal">{{ $this->planRefusal }}</span>@endif
                </span>
                @if ($planError)
                    <p class="m-0 text-[13px] text-loss" role="alert" data-test="plan-error">{{ $planError }}</p>
                @endif
            </form>
            <div class="flex flex-col" data-test="plan-log">
                <h3 class="m-0 pb-1 text-[13px] font-bold">{{ __('Who planned what') }}</h3>
                @forelse ($this->planLog as $change)
                    <div wire:key="plan-change-{{ $change->id }}" class="flex flex-col gap-1 border-b border-hairline py-2.5 text-[13px] last:border-0">
                        <span class="flex flex-wrap gap-x-3"><b>{{ $date(CarbonImmutable::instance($change->created_at)) }}</b><span class="text-ink-2">{{ __('by :name', ['name' => $change->changedBy?->displayName() ?? substr($change->changed_by_pubkey, 0, 8)]) }}</span></span>
                        <span class="text-xs break-words text-ink-3">{{ collect($change->changes)->map(fn (array $pair, string $field): string => match ($field) {
                            'starts_at' => __('Block 0'),
                            'weeks' => __('Length in weeks'),
                            'reset_factor_milli' => __('f'),
                            default => __('Name'),
                        }.': '.collect($pair)->map(fn ($value): string => $value === null ? '–' : match ($field) {
                            'starts_at' => $date(CarbonImmutable::createFromTimestamp((int) $value), 'D j M Y, H:i'),
                            'reset_factor_milli' => SeasonRelease::factor((int) $value),
                            default => (string) $value,
                        })->implode(' → '))->implode(' · ') }}</span>
                    </div>
                @empty
                    <x-admin.empty :text="__('Not planned yet.')" />
                @endforelse
            </div>
        @endif
    </x-admin.panel>

    {{-- Rating, rank and hashrate values for Block 0 (P35, RatingSettings) --}}
    @php($locked = $this->settingsLocked)
    @php($readOnly = $locked || ! $this->isBoard)
    @php($labels = $this->settingLabels())
    @php($limit = fn (string $key): string => __(':min to :max', ['min' => RatingSettings::LIMITS[$key][0], 'max' => RatingSettings::LIMITS[$key][1]]))
    <x-admin.panel :title="__('Rating, ranks and hashrate')" :meta="$locked ? __('frozen at Block 0 in the signed ladders') : __('the values Block 0 releases the season with')" data-test="season-settings">
        <p class="m-0 text-xs text-ink-2">{{ $locked
            ? __('A season has been released. Its ladders carry these values, and every rating is replayed from them, so they cannot change during or after the season.')
            : __('The board can change these until Block 0. The release freezes them for the whole season; each change is logged below with who made it.') }}</p>
        @if (! $locked && ! $this->isBoard)
            <p class="m-0 text-xs text-ink-3" data-test="settings-board-only">{{ __('Only a board member on the public admin list can change these values.') }}</p>
        @endif
        <form wire:submit="saveSettings" class="flex flex-col gap-4">
            <fieldset class="m-0 grid grid-cols-2 gap-3 border-0 p-0 lg:grid-cols-6" data-test="settings-fields-rating" @disabled($readOnly)>
                <legend class="mb-2 text-xs text-ink-2">{{ __('Rating') }}</legend>
                @foreach (array_keys(RatingSettings::defaults()['rating']) as $key)
                    <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ $labels['rating.'.$key] }}
                        <input type="text" inputmode="numeric" wire:model="rating.{{ $key }}" class="{{ $input }}" data-test="setting-rating-{{ $key }}">
                        <span class="text-ink-3">{{ $key === 'daily_pair_limit' ? __(':range, empty = no limit', ['range' => $limit('rating.'.$key)]) : $limit('rating.'.$key) }}</span>
                    </label>
                @endforeach
            </fieldset>
            <fieldset class="m-0 grid grid-cols-2 gap-3 border-0 p-0 sm:grid-cols-3 lg:grid-cols-7" data-test="settings-fields-tiers" @disabled($readOnly)>
                <legend class="mb-2 text-xs text-ink-2">{{ __('Minimum rating per rank (:range, each above the one below; Bronze I starts at 0)', ['range' => $limit('tiers')]) }}</legend>
                @foreach (array_keys(RatingSettings::defaults()['tiers']) as $token)
                    <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ RankTiers::label($token) }}
                        <input type="text" inputmode="numeric" wire:model="tiers.{{ $token }}" class="{{ $input }}" @disabled($loop->first) data-test="setting-tier-{{ $token }}">
                    </label>
                @endforeach
            </fieldset>
            <fieldset class="m-0 grid grid-cols-2 gap-3 border-0 p-0 lg:grid-cols-4" data-test="settings-fields-hashrate" @disabled($readOnly)>
                <legend class="mb-2 text-xs text-ink-2">{{ __('Clan hashrate points per rated result (:range)', ['range' => $limit('hashrate')]) }}</legend>
                @foreach (array_keys(RatingSettings::defaults()['hashrate']) as $key)
                    <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ $labels['hashrate.'.$key] }}<input type="text" inputmode="numeric" wire:model="hashrate.{{ $key }}" class="{{ $input }}" data-test="setting-hashrate-{{ $key }}"></label>
                @endforeach
            </fieldset>
            @unless ($readOnly)
                <span class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="btn-p inline-flex h-11 cursor-pointer items-center rounded-md border-0 bg-btc px-5 text-sm font-bold text-on-btc" data-test="save-settings">{{ __('Save the values') }}</button>
                </span>
            @endunless
            @if ($settingsError)
                <p class="m-0 text-[13px] text-loss" role="alert" data-test="settings-error">{{ $settingsError }}</p>
            @endif
        </form>
        <div class="flex flex-col" data-test="settings-log">
            <h3 class="m-0 pb-1 text-[13px] font-bold">{{ __('Who changed what') }}</h3>
            @forelse ($this->settingsLog as $change)
                <div wire:key="setting-change-{{ $change->id }}" class="flex flex-col gap-1 border-b border-hairline py-2.5 text-[13px] last:border-0">
                    <span class="flex flex-wrap gap-x-3"><b>{{ $date(CarbonImmutable::instance($change->created_at)) }}</b><span class="text-ink-2">{{ __('by :name', ['name' => $change->changedBy?->displayName() ?? substr($change->changed_by_pubkey, 0, 8)]) }}</span></span>
                    <span class="text-xs break-words text-ink-3">{{ collect($change->changes)->map(fn (array $pair, string $path): string => ($labels[$path] ?? (str_starts_with($path, 'tiers.') ? RankTiers::label(substr($path, 6)) : $path)).': '.($pair[0] ?? '–').' → '.($pair[1] ?? '–'))->implode(' · ') }}</span>
                </div>
            @empty
                <x-admin.empty :text="__('No change yet: config/season.php holds the values.')" />
            @endforelse
        </div>
    </x-admin.panel>

    {{-- Soft-reset preview (P35, SoftReset) --}}
    @php($from = $this->resetFrom)
    <x-admin.panel :title="__('Soft-reset preview')" :meta="__('read-only, the release of the planned season applies it')" data-test="season-soft-reset">
        <p class="m-0 text-xs text-ink-2">{{ __('The NIP soft reset: next start = start rating + (rating now − start rating of the season) × f, rounded. f = 0 resets everyone, f = 1 keeps every rating. The field starts at the planned f; trying another value here changes nothing.') }}</p>
        @if ($from === null)
            <x-admin.empty :text="__('No season has been released, so there are no rated ratings to carry over.')" />
        @else
            @php($startFrom = RatingSettings::forSeason($from)['rating']['start'])
            @php($startNext = RatingSettings::draft()['rating']['start'])
            @php($factor = $this->resetFactorMilli)
            <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
                <label class="flex w-60 flex-col gap-1 text-xs text-ink-2">{{ __('Carry-over factor f (0 to 1)') }}<input type="text" inputmode="decimal" wire:model.live.debounce.400ms="resetFactor" class="{{ $input }}" data-test="reset-factor"></label>
                <span class="text-xs text-ink-2">{{ __('From :season (start :from), next start :next', ['season' => $from->slug, 'from' => $startFrom, 'next' => $startNext]) }}@if ($from->isLiveAt(CarbonImmutable::now())) · {{ __('the season is still live: ratings so far') }}@endif</span>
            </div>
            @if ($factor === null)
                <p class="m-0 text-[13px] text-loss" role="alert" data-test="reset-factor-error">{{ __('The factor is a number from 0 to 1 with at most three decimals.') }}</p>
            @else
                @php($rows = $this->resetRows)
                @if ($rows->isEmpty())
                    <x-admin.empty :text="__('No rated result in this season yet, so every player would start at :start.', ['start' => $startNext])" />
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] border-collapse text-left text-xs" data-test="reset-table">
                            <thead class="text-ink-2">
                                <tr class="border-b border-hairline">
                                    <th scope="col" class="py-2 pr-3 font-normal">{{ __('Ladder') }}</th>
                                    <th scope="col" class="py-2 pr-3 font-normal">{{ __('Player or lineup') }}</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('Results') }}</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('Rating now') }}</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('Next start') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    @php($seed = SoftReset::seed($row->rating, $startFrom, $startNext, $factor))
                                    <tr wire:key="reset-{{ $row->id }}" class="border-b border-hairline last:border-0" data-test="reset-row">
                                        <td class="py-2 pr-3 whitespace-nowrap text-ink-2">{{ ChainOverview::keyLabel($row->game.'/'.$row->mode) }}</td>
                                        <td class="max-w-[16rem] truncate py-2 pr-3">{{ SeasonReview::entityName($row) }}</td>
                                        <td class="py-2 pr-3 text-right">{{ $row->results }}</td>
                                        <td class="py-2 pr-3 text-right">{{ $row->rating }}</td>
                                        <td class="py-2 pr-3 text-right font-bold" data-test="reset-seed">{{ $seed }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($rows->hasPages())
                        @php($page = $rows->currentPage())
                        @php($last = $rows->lastPage())
                        <nav aria-label="{{ __('Pages') }}" class="flex justify-center" data-test="reset-pages">
                            <div class="flex gap-0.5 overflow-hidden rounded-md">
                                @foreach ([['«', 1, __('First page')], ['‹', max(1, $page - 1), __('Previous page')]] as [$glyph, $target, $label])
                                    <button type="button" wire:click="gotoPage({{ $target }}, 'reset')" aria-label="{{ $label }}" @disabled($page === 1) class="flex size-11 cursor-pointer items-center justify-center border-0 bg-ground text-[13px] text-ink-2 disabled:cursor-default disabled:text-[#4A4A50]">{{ $glyph }}</button>
                                @endforeach
                                @foreach (range(max(1, $page - 2), min($last, $page + 2)) as $number)
                                    <button type="button" wire:click="gotoPage({{ $number }}, 'reset')" @if ($number === $page) aria-current="page" @endif
                                            @class(['flex size-11 cursor-pointer items-center justify-center border-0 text-[13px]', 'bg-btc font-bold text-on-btc' => $number === $page, 'bg-ground text-ink-2' => $number !== $page])>{{ $number }}</button>
                                @endforeach
                                @foreach ([['›', min($last, $page + 1), __('Next page')], ['»', $last, __('Last page')]] as [$glyph, $target, $label])
                                    <button type="button" wire:click="gotoPage({{ $target }}, 'reset')" aria-label="{{ $label }}" @disabled($page === $last) class="flex size-11 cursor-pointer items-center justify-center border-0 bg-ground text-[13px] text-ink-2 disabled:cursor-default disabled:text-[#4A4A50]">{{ $glyph }}</button>
                                @endforeach
                            </div>
                        </nav>
                    @endif
                @endif
            @endif
        @endif
    </x-admin.panel>

    {{-- Review of the last ended season (P35, SeasonReview) --}}
    @php($ended = $this->endedSeason)
    <x-admin.panel :title="__('Season review')" :meta="$ended ? __(':season, ended :when', ['season' => $ended->slug, 'when' => $date(CarbonImmutable::instance($ended->ends_at))]) : null" data-test="season-review">
        @if ($ended === null)
            <x-admin.empty :text="__('No season has ended yet. The review shows the champions and the chain once one has.')" />
        @else
            @php($review = $this->review)
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ([
                    [__('Blocks'), (string) $review['blocks'], __('mined in the season')],
                    [__('Supply issued'), $sats($review['mined']), __('of :supply sats', ['supply' => $sats($review['supply'])])],
                    [__('Left in the pot'), $sats($review['remaining']), __('sats')],
                    [__('Wins that did not mine'), (string) $review['rejected'], __('rejected by a consensus rule')],
                ] as [$label, $value, $sub])
                    <div class="flex min-w-0 flex-col gap-1 rounded-md bg-ground px-3.5 py-3 shadow-ring">
                        <span class="text-xs text-ink-2">{{ $label }}</span><b class="font-display text-lg leading-tight">{{ $value }}</b><span class="text-xs text-ink-3">{{ $sub }}</span>
                    </div>
                @endforeach
            </div>
            <h3 class="m-0 text-[13px] font-bold">{{ __('Champions') }}</h3>
            @forelse ($review['champions'] as $champion)
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-hairline py-2 text-[13px] last:border-0" data-test="review-champion">
                    <span class="flex min-w-0 flex-col"><span class="text-xs text-ink-2">{{ $champion['ladder'] }}</span><b class="truncate">{{ $champion['name'] }}</b></span>
                    <span class="text-xs text-ink-2">{{ __(':rating Elo · :tier · :count rated results', ['rating' => $champion['rating'], 'tier' => RankTiers::label($champion['tier']), 'count' => $champion['results']]) }}</span>
                </div>
            @empty
                <x-admin.empty :text="__('No ladder had a rated result in this season.')" />
            @endforelse
            <h3 class="m-0 text-[13px] font-bold">{{ __('Season payouts') }}</h3>
            <p class="m-0 text-[13px] text-ink-2" data-test="review-payouts">{{ __('No season payouts have been made. The settlement of the chain rewards is not built, so there is nothing to settle here.') }}</p>
        @endif
    </x-admin.panel>
</x-admin.page>
