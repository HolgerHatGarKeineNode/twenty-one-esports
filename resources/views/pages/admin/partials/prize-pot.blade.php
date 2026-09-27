{{--
    The optional prize pot of a tournament (P9 scope addition), shared by
    the create and edit pages and the pool page; the component uses
    App\Livewire\Concerns\EditsPrizePot. A stored wallet connection is never
    rendered: it shows as "connected" with replace and remove.

    $potTournament: Tournament|null (null on the create page)
    $potSave: string|null, the action of this section's own save button (null = saved with the page)
--}}
@php
    $potPool = app(\App\Support\Prizes\PrizePool::class);
    $potLeagueOk = \App\Support\Prizes\PrizePool::leagueCanHold() || $potTournament?->pot_source === \App\Models\Tournament::POT_LEAGUE || ($potTournament?->pot_source === null && $potTournament?->pool_opened_at !== null);
    $potStored = $potTournament !== null && $potTournament->pot_nwc_uri !== null;
    $potSplitOpen = $potTournament === null || $potPool->canChangeSplit($potTournament);
    $potValues = array_map(fn ($value): int => is_numeric($value) ? (int) $value : 0, $this->potSplit);
    $potSum = array_sum($potValues);
    $potPreset = \App\Support\Prizes\PrizePool::presetOf($this->potSplit);
    $potPreview = $this->potPreviewSats();
    $potFormat = fn (int $value): string => \App\Support\PreSeason::formatSats($value);
    $potZone = \App\Support\LeagueTime::zone();
    $potPresets = ['winner' => __('Winner takes all'), '60-30-10' => '60 / 30 / 10', '50-30-20' => '50 / 30 / 20', 'top-4' => __('Top 4: 40 / 30 / 20 / 10')];
@endphp

<section aria-labelledby="pot-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="prize-pot">
    <div class="flex flex-col gap-1">
        <h2 id="pot-h" class="m-0 text-[15px] font-bold">{{ __('Prize pot') }}</h2>
        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('Optional. The pot shows on the tournament page and in the lists, with what each place can win. It is never a number you type: it is what the wallet holds.') }}</p>
    </div>

    <label class="flex min-h-11 items-center gap-3 self-start text-[13px]">
        <input type="checkbox" wire:model.live="potEnabled" class="size-5 accent-btc" data-test="pot-enabled">
        {{ __('This tournament has a prize pot') }}
    </label>

    @if ($this->potEnabled)
        <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
            <legend class="mb-1.5 p-0 text-xs text-ink-2">{{ __('Where the pot is held') }}</legend>
            <div class="grid gap-2 sm:grid-cols-2">
                <label @class(['flex min-h-11 cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 text-[13px]', 'border-btc bg-btc-chip' => $this->potSource === 'league', 'border-line bg-well' => $this->potSource !== 'league', 'opacity-60' => ! $potLeagueOk])>
                    <input type="radio" value="league" wire:model.live="potSource" @disabled(! $potLeagueOk) class="mt-0.5 accent-btc" data-test="pot-source-league">
                    <span class="flex flex-col gap-0.5">
                        <b>{{ __('League pot') }}</b>
                        <span class="text-xs text-ink-2">{{ $potLeagueOk ? __('Anyone zaps the league’s pool key; every receipt names this tournament.') : __('Not available: the league wallet cannot take payments yet.') }}</span>
                    </span>
                </label>
                <label @class(['flex min-h-11 cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 text-[13px]', 'border-btc bg-btc-chip' => $this->potSource === 'wallet', 'border-line bg-well' => $this->potSource !== 'wallet'])>
                    <input type="radio" value="wallet" wire:model.live="potSource" class="mt-0.5 accent-btc" data-test="pot-source-wallet">
                    <span class="flex flex-col gap-0.5">
                        <b>{{ __('Own wallet (Nostr Wallet Connect)') }}</b>
                        <span class="text-xs text-ink-2">{{ __('The pot is the balance of a wallet you connect; the winners are paid from it.') }}</span>
                    </span>
                </label>
            </div>
        </fieldset>

        @if ($this->potSource === 'wallet')
            <div class="flex flex-col gap-2 rounded-md bg-ground p-3 shadow-ring" data-test="pot-wallet">
                @if ($potStored && ! $this->potReplacing)
                    <p class="m-0 flex flex-wrap items-center gap-2 text-[13px]" data-test="pot-connected">
                        <span class="inline-flex h-6 items-center gap-1 rounded-xs bg-win-tint px-2 text-xs font-bold text-win"><x-icon name="check" :size="12" />{{ __('Connected') }}</span>
                        @if ($potTournament->pot_lud16)
                            <span class="text-xs break-all text-ink-2">{{ $potTournament->pot_lud16 }}</span>
                        @endif
                    </p>
                    @if ($potTournament->pot_balance_at)
                        <p @class(['m-0 text-xs', 'text-ink-2' => ! \App\Support\Prizes\PrizePool::isBalanceStale($potTournament), 'text-loss' => \App\Support\Prizes\PrizePool::isBalanceStale($potTournament)]) data-test="pot-balance">
                            {{ __(':sats sats as of :time', ['sats' => $potFormat((int) $potTournament->pot_balance_sats), 'time' => $potTournament->pot_balance_at->copy()->timezone($potZone)->format('Y-m-d H:i')]) }}
                            @if ($potTournament->pot_balance_error)
                                · {{ __('the last read failed (:code)', ['code' => $potTournament->pot_balance_error]) }}
                            @endif
                        </p>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        <x-button variant="quiet" wire:click="readPotBalance" wire:loading.attr="disabled" data-test="pot-read">{{ __('Read balance now') }}</x-button>
                        <x-button variant="quiet" wire:click="replacePotWallet" data-test="pot-replace">{{ __('Replace') }}</x-button>
                        <x-button variant="secondary" wire:click="removePotWallet" wire:confirm="{{ __('Remove the wallet connection? The tournament has no prize pot then; the sats stay in that wallet.') }}" data-test="pot-remove">{{ __('Remove') }}</x-button>
                    </div>
                @else
                    <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                        {{ __('Connection string (nostr+walletconnect://…)') }}
                        <input type="password" wire:model="potUri" autocomplete="off" spellcheck="false" class="h-11 rounded-md border border-line bg-well px-3 font-mono text-[13px] text-ink" data-test="pot-uri">
                        <span class="text-ink-3">{{ __('Create a connection in your wallet (for example Alby Hub) that may read the balance and pay invoices; set a budget as high as the pot. It is stored encrypted and never shown again.') }}</span>
                    </label>
                    <div><x-button variant="quiet" wire:click="checkPotConnection" wire:loading.attr="disabled" data-test="pot-check">{{ __('Check connection') }}</x-button></div>
                @endif
            </div>
        @endif

        <label class="flex flex-col gap-1.5 text-xs text-ink-2 sm:max-w-[320px]">
            {{ __('Target in sats (optional, shown as “X of Y”)') }}
            <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="potTarget" class="h-11 rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="pot-target">
        </label>

        <fieldset class="m-0 flex flex-col gap-2 border-0 p-0" @disabled(! $potSplitOpen)>
            <legend class="mb-1.5 p-0 text-xs text-ink-2">{{ __('What each place wins, in percent of the pot') }}</legend>
            <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Presets') }}">
                @foreach ($potPresets as $key => $label)
                    <button type="button" wire:click="usePotPreset('{{ $key }}')" @class(['inline-flex h-11 cursor-pointer items-center rounded-md border px-3 text-[13px]', 'border-btc bg-btc-chip font-bold text-btc-hi' => $potPreset === $key, 'border-line bg-well text-ink' => $potPreset !== $key]) aria-pressed="{{ $potPreset === $key ? 'true' : 'false' }}" data-test="pot-preset-{{ $key }}">{{ $label }}</button>
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
            @unless ($potSplitOpen)
                <p class="m-0 text-xs text-ink-2">{{ __('The split is part of the rules players signed up under; it cannot change after sign-up closed.') }}</p>
            @endunless
        </fieldset>

        <div class="flex flex-col gap-1.5" data-test="pot-preview">
            @if ($potPreview !== null)
                <span class="text-xs text-ink-2">
                    {{ $this->potSource === 'wallet' ? __('Preview from :sats sats, after :percent % held back for routing fees:', ['sats' => $potFormat($potPreview), 'percent' => \App\Support\Prizes\PrizePool::WALLET_FEE_PERCENT]) : __('Preview from :sats sats:', ['sats' => $potFormat($potPreview)]) }}
                </span>
                <ol class="m-0 flex list-none flex-wrap gap-2 p-0">
                    @foreach ($potValues as $index => $percent)
                        <li class="flex flex-col rounded-md bg-ground px-3 py-2 shadow-ring-hairline" wire:key="pot-preview-{{ $index }}">
                            <span class="text-xs text-ink-2">{{ __(':place. place', ['place' => $index + 1]) }}, {{ $percent }} %</span>
                            <b class="font-display text-[15px] tabular-nums">{{ __(':sats sats', ['sats' => $potFormat(intdiv($potPreview * max(0, $percent), 100))]) }}</b>
                        </li>
                    @endforeach
                </ol>
            @else
                <span class="text-xs text-ink-3">{{ __('Set a target or check the wallet to see the prizes in sats.') }}</span>
            @endif
            <span class="text-xs text-ink-3">{{ __('Tied places share their percentages; a team’s share is split equally among its roster.') }}</span>
        </div>
    @endif

    @if ($this->potNotice !== '')<p class="m-0 text-[13px] text-win" role="status" data-test="pot-notice">{{ $this->potNotice }}</p>@endif
    @if ($this->potError !== '')<p class="m-0 rounded-md bg-loss-tint px-3 py-2 text-[13px] text-loss" role="alert" data-test="pot-error">{{ $this->potError }}</p>@endif

    @if ($potSave !== null)
        <div><x-button wire:click="{{ $potSave }}" wire:loading.attr="disabled" data-test="pot-save">{{ __('Save prize pot') }}</x-button></div>
    @endif
</section>
