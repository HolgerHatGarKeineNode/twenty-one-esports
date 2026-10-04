{{--
    The optional prize pot of a tournament (P9), shared by the create and
    edit pages and the pool page; the component uses
    App\Livewire\Concerns\EditsPrizePot. The pot is booked in the league
    wallet (user, 2026-10-02): no wallet of the tournament's own is asked for.

    $potTournament: Tournament|null (null on the create page)
    $potSave: string|null, the action of this section's own save button (null = saved with the page)
--}}
@php
    $potPool = app(\App\Support\Prizes\PrizePool::class);
    $potSplitOpen = $potTournament === null || $potPool->canChangeSplit($potTournament);
    $potFixedMode = $this->potMode === \App\Models\Tournament::PRIZES_FIXED;
    $potValues = array_map(fn ($value): int => is_numeric($value) ? (int) $value : 0, $this->potSplit);
    $potFixedValues = array_map(fn ($value): int => is_numeric($value) ? (int) $value : 0, $this->potFixed);
    $potSum = array_sum($potValues);
    $potFixedSum = array_sum($potFixedValues);
    $potFixedNeed = $potFixedSum;
    $potBalance = $this->potKnownBalance();
    $potPreset = \App\Support\Prizes\PrizePool::presetOf($this->potSplit);
    $potPreview = $this->potPreviewSats();
    $potFormat = fn (int $value): string => \App\Support\PreSeason::formatSats($value);
    $potZone = \App\Support\LeagueTime::zone();
    $potPresets = ['winner' => __('Winner takes all'), '60-30-10' => '60 / 30 / 10', '50-30-20' => '50 / 30 / 20', 'top-4' => __('Top 4: 40 / 30 / 20 / 10')];
    $potModeButton = 'inline-flex h-11 cursor-pointer items-center rounded-md border px-3 text-[13px]';
@endphp

<section aria-labelledby="pot-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="prize-pot">
    <div class="flex flex-col gap-1">
        <h2 id="pot-h" class="m-0 text-[15px] font-bold">{{ __('Prize pot') }}</h2>
        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('Optional. The pot is kept in the league wallet, booked for this tournament alone; it shows on the tournament page and in the lists, with what each place can win. Set the prizes as a share of the pot or as fixed amounts; an admin pays the winners from the league wallet.') }}</p>
    </div>

    <label class="flex min-h-11 items-center gap-3 self-start text-[13px]">
        <input type="checkbox" wire:model.live="potEnabled" class="size-5 accent-btc" data-test="pot-enabled">
        {{ __('This tournament has a prize pot') }}
    </label>

    @if ($this->potEnabled)
        <p class="m-0 text-xs text-ink-2" data-test="pot-league-wallet">{{ __('No wallet to connect: sponsors, top-ups and zaps are paid to the league wallet and counted for this pot.') }}</p>

        <fieldset class="m-0 flex flex-col gap-2 border-0 p-0" @disabled(! $potSplitOpen)>
            <legend class="mb-1.5 p-0 text-xs text-ink-2">{{ __('How the prizes are set') }}</legend>
            <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('How the prizes are set') }}">
                <button type="button" wire:click="usePotMode('percent')" @class([$potModeButton, 'border-btc bg-btc-chip font-bold text-btc-hi' => ! $potFixedMode, 'border-line bg-well text-ink' => $potFixedMode]) aria-pressed="{{ $potFixedMode ? 'false' : 'true' }}" data-test="pot-mode-percent">{{ __('Percent of the pot') }}</button>
                <button type="button" wire:click="usePotMode('fixed')" @class([$potModeButton, 'border-btc bg-btc-chip font-bold text-btc-hi' => $potFixedMode, 'border-line bg-well text-ink' => ! $potFixedMode]) aria-pressed="{{ $potFixedMode ? 'true' : 'false' }}" data-test="pot-mode-fixed">{{ __('Fixed amounts') }}</button>
            </div>

            @if ($potFixedMode)
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->potFixed as $index => $amount)
                        <label class="flex h-11 items-center gap-1.5 rounded-md border border-line bg-well px-3 text-[13px] text-ink-2" wire:key="pot-fixed-{{ $index }}">
                            {{ __(':place.', ['place' => $index + 1]) }}
                            <input type="number" min="1" step="1" wire:model.live.debounce.300ms="potFixed.{{ $index }}" class="w-24 bg-transparent text-ink outline-none" data-test="pot-fixed-{{ $index + 1 }}" aria-label="{{ __(':place. place, sats', ['place' => $index + 1]) }}">
                            {{ __('sats') }}
                        </label>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-button variant="quiet" wire:click="addPotPlace" data-test="pot-fixed-add">{{ __('Add a place') }}</x-button>
                    <x-button variant="quiet" wire:click="removePotPlace">{{ __('Remove the last place') }}</x-button>
                    <span class="text-xs text-ink-3" data-test="pot-fixed-sum">{{ __('Total: :sats sats', ['sats' => $potFormat($potFixedSum)]) }}</span>
                </div>
            @else
                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Presets') }}">
                    @foreach ($potPresets as $key => $label)
                        <button type="button" wire:click="usePotPreset('{{ $key }}')" @class([$potModeButton, 'border-btc bg-btc-chip font-bold text-btc-hi' => $potPreset === $key, 'border-line bg-well text-ink' => $potPreset !== $key]) aria-pressed="{{ $potPreset === $key ? 'true' : 'false' }}" data-test="pot-preset-{{ $key }}">{{ $label }}</button>
                    @endforeach
                    <span @class(['inline-flex h-11 items-center rounded-md border px-3 text-[13px]', 'border-btc bg-btc-chip font-bold text-btc-hi' => $potPreset === null, 'border-transparent text-ink-3' => $potPreset !== null])>{{ __('Custom') }}</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->potSplit as $index => $percent)
                        <label class="flex h-11 items-center gap-1.5 rounded-md border border-line bg-well px-3 text-[13px] text-ink-2" wire:key="pot-place-{{ $index }}">
                            {{ __(':place.', ['place' => $index + 1]) }}
                            <input type="number" min="1" max="100" step="1" wire:model.live.debounce.300ms="potSplit.{{ $index }}" class="w-14 bg-transparent text-ink outline-none" data-test="pot-split-{{ $index + 1 }}" aria-label="{{ __(':place. place, percent', ['place' => $index + 1]) }}">
                            %
                        </label>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-button variant="quiet" wire:click="addPotPlace">{{ __('Add a place') }}</x-button>
                    <x-button variant="quiet" wire:click="removePotPlace">{{ __('Remove the last place') }}</x-button>
                    <span @class(['text-xs', 'text-ink-3' => $potSum === 100, 'text-loss' => $potSum !== 100]) data-test="pot-split-sum">{{ __('Total: :sum %', ['sum' => $potSum]) }}</span>
                </div>
                <label class="flex flex-col gap-1.5 text-xs text-ink-2 sm:max-w-[320px]">
                    {{ __('Target in sats (optional, shown as “X of Y”)') }}
                    <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="potTarget" class="h-11 rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="pot-target">
                </label>
            @endif
            @unless ($potSplitOpen)
                <p class="m-0 text-xs text-ink-2">{{ __('The prizes are part of the rules players signed up under; they cannot change after sign-up closed.') }}</p>
            @endunless
        </fieldset>

        <div class="flex flex-col gap-1.5" data-test="pot-preview">
            @if ($potFixedMode)
                <ol class="m-0 flex list-none flex-wrap gap-2 p-0">
                    @foreach ($potFixedValues as $index => $amount)
                        <li class="flex flex-col rounded-md bg-ground px-3 py-2 shadow-ring-hairline" wire:key="pot-fixed-preview-{{ $index }}">
                            <span class="text-xs text-ink-2">{{ __(':place. place', ['place' => $index + 1]) }}</span>
                            <b class="font-display text-[15px] tabular-nums">{{ __(':sats sats', ['sats' => $potFormat(max(0, $amount))]) }}</b>
                        </li>
                    @endforeach
                </ol>
                <span class="text-xs text-ink-3">{{ __('Tied places share the sum of their amounts; a team’s share is split equally among its roster. What the pot holds beyond the prizes stays with the league.') }}</span>
            @else
                @if ($potPreview !== null)
                    <span class="text-xs text-ink-2">{{ __('Preview from :sats sats, after :percent % held back for routing fees:', ['sats' => $potFormat($potPreview), 'percent' => \App\Support\Prizes\PrizePool::WALLET_FEE_PERCENT]) }}</span>
                    <ol class="m-0 flex list-none flex-wrap gap-2 p-0">
                        @foreach ($potValues as $index => $percent)
                            <li class="flex flex-col rounded-md bg-ground px-3 py-2 shadow-ring-hairline" wire:key="pot-preview-{{ $index }}">
                                <span class="text-xs text-ink-2">{{ __(':place. place', ['place' => $index + 1]) }}, {{ $percent }} %</span>
                                <b class="font-display text-[15px] tabular-nums">{{ __(':sats sats', ['sats' => $potFormat(intdiv($potPreview * max(0, $percent), 100))]) }}</b>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <span class="text-xs text-ink-3">{{ __('Set a target to see the prizes in sats.') }}</span>
                @endif
                <span class="text-xs text-ink-3">{{ __('Tied places share their percentages; a team’s share is split equally among its roster.') }}</span>
            @endif
        </div>
    @endif

    @if ($this->potNotice !== '')<p class="m-0 text-[13px] text-win" role="status" data-test="pot-notice">{{ $this->potNotice }}</p>@endif
    @if ($this->potError !== '')<p class="m-0 rounded-md bg-loss-tint px-3 py-2 text-[13px] text-loss" role="alert" data-test="pot-error">{{ $this->potError }}</p>@endif

    @if ($potSave !== null)
        <div><x-button wire:click="{{ $potSave }}" wire:loading.attr="disabled" data-test="pot-save">{{ __('Save prize pot') }}</x-button></div>
    @endif
</section>
