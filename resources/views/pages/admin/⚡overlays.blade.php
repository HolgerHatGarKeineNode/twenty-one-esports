<?php

use App\Enums\OverlayVariant;
use App\Enums\TournamentStatus;
use App\Models\OverlayPreset;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * OBS overlays (plan "OBS-Broadcast-Overlays", P2): an admin sets up a preset once (variant, tournament for the
 * tournament and bracket variants, language, modules) and gets a secret URL for an OBS browser source; everything
 * runs on its own after that. The URL is shown once, right after creating or rotating (only the token's SHA-256 is
 * stored); rotating makes the old URL answer 404. Admins only.
 */
new #[Title('OBS overlays')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    /** The preset being edited; null while the form creates a new one. */
    public ?int $editing = null;

    public string $name = '';

    public string $variant = 'league-live';

    public ?int $tournament = null;

    public string $locale = 'de';

    /** @var array<string, bool> */
    public array $modules = OverlayPreset::MODULES;

    /** The URL of the preset just created or rotated: shown once, gone with the next action. */
    public string $revealedUrl = '';

    public ?int $revealedFor = null;

    public string $flash = '';

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * @return Collection<int, OverlayPreset>
     */
    #[Computed]
    public function presets(): Collection
    {
        return OverlayPreset::query()->with('tournament')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Tournaments an overlay can follow: public, not over, soonest first.
     *
     * @return Collection<int, Tournament>
     */
    #[Computed]
    public function tournaments(): Collection
    {
        return Tournament::query()->exceptLeagueWeeks()->whereNotNull('published_at')
            ->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running])
            ->orderBy('starts_at')->orderBy('id')->limit(100)->get();
    }

    /**
     * @return array<string, string>
     */
    public static function moduleLabels(): array
    {
        return [
            'ticker' => __('Ticker'),
            'pride' => __('Pride moments'),
            'ads' => __('Ads for the site'),
            'stats' => __('League numbers'),
            'qr' => __('QR code'),
            'pots' => __('Prize pots'),
            'sound' => __('Stinger sounds'),
            'cam-frame' => __('Camera frame'),
        ];
    }

    public function save(): void
    {
        Gate::authorize('admin');
        $this->revealedUrl = '';
        $this->revealedFor = null;
        $this->flash = '';

        $needsTournament = OverlayVariant::tryFrom($this->variant)?->needsTournament() ?? false;
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'variant' => ['required', Rule::enum(OverlayVariant::class)],
            'tournament' => $needsTournament ? ['required', 'integer', Rule::in($this->tournaments->modelKeys())] : ['nullable'],
            'locale' => ['required', Rule::in(OverlayPreset::LOCALES)],
            'modules' => ['array'],
            'modules.*' => ['boolean'],
        ], [], ['tournament' => __('Tournament')]);

        $attributes = [
            'name' => trim($this->name),
            'variant' => $this->variant,
            'tournament_id' => $needsTournament ? $this->tournament : null,
            'locale' => $this->locale,
            'modules' => array_map(fn (string $module): bool => (bool) ($this->modules[$module] ?? false), array_combine(array_keys(OverlayPreset::MODULES), array_keys(OverlayPreset::MODULES))),
        ];

        if ($this->editing !== null) {
            OverlayPreset::query()->findOrFail($this->editing)->update($attributes);
            $this->flash = __('The preset was saved. Its URL stays the same.');
        } else {
            $token = OverlayPreset::newToken();
            $preset = OverlayPreset::query()->create($attributes + ['token_hash' => OverlayPreset::hashToken($token), 'created_by_id' => auth()->id()]);
            $this->revealedUrl = OverlayPreset::url($token);
            $this->revealedFor = $preset->id;
        }

        $this->resetForm();
        unset($this->presets);
    }

    public function edit(int $presetId): void
    {
        Gate::authorize('admin');
        $preset = OverlayPreset::query()->findOrFail($presetId);

        $this->revealedUrl = '';
        $this->revealedFor = null;
        $this->flash = '';
        $this->editing = $preset->id;
        $this->name = $preset->name;
        $this->variant = $preset->variant->value;
        $this->tournament = $preset->tournament_id;
        $this->locale = $preset->locale;
        $this->modules = $preset->moduleStates();
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function rotate(int $presetId): void
    {
        Gate::authorize('admin');

        $preset = OverlayPreset::query()->findOrFail($presetId);
        $this->flash = '';
        $this->revealedUrl = OverlayPreset::url($preset->rotate());
        $this->revealedFor = $preset->id;
        unset($this->presets);
    }

    public function remove(int $presetId): void
    {
        Gate::authorize('admin');

        OverlayPreset::query()->whereKey($presetId)->delete();
        $this->revealedUrl = '';
        $this->revealedFor = null;
        $this->flash = __('The preset was deleted. Its URL no longer works.');

        if ($this->editing === $presetId) {
            $this->resetForm();
        }

        unset($this->presets);
    }

    public function dismiss(): void
    {
        $this->revealedUrl = '';
        $this->revealedFor = null;
    }

    private function resetForm(): void
    {
        $this->editing = null;
        $this->name = '';
        $this->variant = OverlayVariant::LeagueLive->value;
        $this->tournament = null;
        $this->locale = 'de';
        $this->modules = OverlayPreset::MODULES;
        $this->resetValidation();
    }
}; ?>

@php
    $input = 'h-11 w-full rounded-md border border-edge bg-ground px-3 text-sm text-ink';
    $needsTournament = OverlayVariant::tryFrom($variant)?->needsTournament() ?? false;
    $revealedName = $revealedFor === null ? null : $this->presets->firstWhere('id', $revealedFor)?->name;
@endphp

<x-admin.page active="overlays" :title="__('OBS overlays')" :lead="__('A preset is one browser source in OBS: choose what it shows, copy its secret URL into OBS once, and it runs on its own. Anyone with the URL sees the overlay, so rotate it when it got out.')" data-test="admin-overlays">
    @if ($revealedUrl !== '')
        <section class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 shadow-[inset_0_0_0_1px_var(--color-btc)] lg:px-6" data-test="overlay-revealed"
                 {{-- Created from the form further down: on a phone the URL would be out of view. --}}
                 x-init="$el.scrollIntoView({ block: 'start' })"
                 x-data="{ copied: false, hint: '', url: @js($revealedUrl), async copy() { try { await navigator.clipboard.writeText(this.url); this.copied = true; this.hint = ''; setTimeout(() => this.copied = false, 2500); } catch (e) { this.hint = @js(__('Copy did not work here. Select the link and copy it.')); } } }">
            <h2 class="m-0 text-[15px] font-bold">{{ __('The URL of :name', ['name' => $revealedName ?? '']) }}</h2>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Copy it now: it is shown only this once. Nobody can read it again, not even an admin; if it is lost, rotate it for a new one.') }}</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                <label class="sr-only" for="overlay-url">{{ __('Overlay URL') }}</label>
                <input id="overlay-url" type="text" readonly value="{{ $revealedUrl }}" x-on:focus="$el.select()" class="{{ $input }} font-mono text-[13px]" data-test="overlay-url">
                <button type="button" x-on:click="copy()" class="btn-p h-11 cursor-pointer rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc" data-test="overlay-copy">
                    <span x-show="! copied">{{ __('Copy URL') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                </button>
            </div>
            <p class="m-0 text-xs text-loss" x-show="hint !== ''" x-text="hint" x-cloak></p>
            <div><button type="button" wire:click="dismiss" class="h-11 cursor-pointer rounded-md border border-edge bg-transparent px-3 text-xs text-ink" data-test="overlay-dismiss">{{ __('I copied it') }}</button></div>
        </section>
    @endif

    <x-admin.flash :message="$flash" data-test="overlay-flash" />

    <x-admin.panel :title="$editing === null ? __('New preset') : __('Edit preset')" data-test="overlay-form-panel">
        <form wire:submit="save" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4" data-test="overlay-form">
            <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2 lg:col-span-2">{{ __('Name') }}
                <input type="text" wire:model="name" maxlength="80" placeholder="{{ __('Laptop stream') }}" class="{{ $input }}" data-test="overlay-name">
            </label>
            <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ __('What it shows') }}
                <select wire:model.live="variant" class="{{ $input }}" data-test="overlay-variant">
                    @foreach (OverlayVariant::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2">{{ __('Language') }}
                <select wire:model="locale" class="{{ $input }}" data-test="overlay-locale">
                    <option value="de">Deutsch</option>
                    <option value="en">English</option>
                </select>
            </label>
            @if ($needsTournament)
                <label class="flex min-w-0 flex-col gap-1 text-xs text-ink-2 sm:col-span-2 lg:col-span-4">{{ __('Tournament') }}
                    <select wire:model="tournament" class="{{ $input }}" data-test="overlay-tournament">
                        <option value="">{{ __('Choose a tournament') }}</option>
                        @foreach ($this->tournaments as $choice)
                            <option value="{{ $choice->id }}">{{ $choice->name }} · {{ $choice->status->label() }} · {{ $choice->starts_at?->translatedFormat('j M Y, H:i') }}</option>
                        @endforeach
                    </select>
                    @if ($this->tournaments->isEmpty())
                        <span class="text-xs text-ink-3">{{ __('No tournament is open for sign-up or running right now.') }}</span>
                    @endif
                </label>
            @endif
            <fieldset class="m-0 flex flex-col gap-2 border-0 p-0 sm:col-span-2 lg:col-span-4">
                <legend class="mb-1 text-xs text-ink-2">{{ __('Modules') }}</legend>
                <div class="grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($this->moduleLabels() as $module => $label)
                        <label class="flex min-h-11 items-center gap-2 text-[13px] text-ink">
                            <input type="checkbox" wire:model="modules.{{ $module }}" class="size-4 accent-[var(--color-btc)]" data-test="overlay-module-{{ $module }}">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
                <button type="submit" class="btn-p h-11 cursor-pointer rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc" data-test="overlay-save">{{ $editing === null ? __('Create preset') : __('Save preset') }}</button>
                @if ($editing !== null)
                    <button type="button" wire:click="cancel" class="h-11 cursor-pointer rounded-md border border-edge bg-transparent px-3 text-xs text-ink" data-test="overlay-cancel">{{ __('Cancel') }}</button>
                @endif
            </div>
            @if ($errors->any())
                <p class="m-0 text-[13px] text-loss sm:col-span-2 lg:col-span-4" data-test="overlay-error">{{ $errors->first() }}</p>
            @endif
        </form>
    </x-admin.panel>

    <x-admin.panel :title="__('Presets')" :meta="trans_choice(':count preset|:count presets', $this->presets->count())" flush data-test="overlay-presets">
        @forelse ($this->presets as $preset)
            <div wire:key="overlay-{{ $preset->id }}" class="grid grid-cols-1 gap-2 border-t border-hairline py-3 text-[13px] first:border-t-0 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center lg:gap-4" data-test="overlay-row">
                <span class="flex min-w-0 flex-col gap-0.5">
                    <b class="truncate">{{ $preset->name }}</b>
                    <span class="text-xs text-ink-2">
                        {{ $preset->variant->label() }} · {{ strtoupper($preset->locale) }}
                        @if ($preset->variant->needsTournament())
                            · {{ $preset->tournament?->name ?? __('Tournament no longer exists') }}
                        @endif
                        · {{ $preset->rotated_at === null ? __('URL from :date', ['date' => $preset->created_at?->translatedFormat('j M Y')]) : __('URL rotated :date', ['date' => $preset->rotated_at->translatedFormat('j M Y')]) }}
                    </span>
                </span>
                <span class="flex flex-wrap gap-2">
                    <button type="button" wire:click="edit({{ $preset->id }})" class="h-11 cursor-pointer rounded-md border border-edge bg-transparent px-3 text-xs text-ink" data-test="overlay-edit">{{ __('Edit') }}</button>
                    <button type="button" wire:click="rotate({{ $preset->id }})" wire:confirm="{{ __('Rotate the URL of :name? The old URL stops working at once; OBS needs the new one.', ['name' => $preset->name]) }}" class="h-11 cursor-pointer rounded-md border border-edge bg-transparent px-3 text-xs text-ink" data-test="overlay-rotate">{{ __('New URL') }}</button>
                    <button type="button" wire:click="remove({{ $preset->id }})" wire:confirm="{{ __('Delete :name? Its URL stops working.', ['name' => $preset->name]) }}" class="h-11 cursor-pointer rounded-md border border-[#5A2A2E] bg-transparent px-3 text-xs text-loss" data-test="overlay-remove">{{ __('Delete') }}</button>
                </span>
            </div>
        @empty
            <x-admin.empty :text="__('No preset yet. Create the first one above.')" class="py-4" />
        @endforelse
    </x-admin.panel>

    <x-admin.panel :title="__('Setting it up in OBS')" data-test="overlay-help">
        <ol class="m-0 flex list-decimal flex-col gap-2 pl-5 text-[13px] leading-normal text-ink-2">
            <li>{{ __('In OBS, add a source of the type Browser and paste the URL.') }}</li>
            <li>{{ __('Width 1920 and height 1080, or 3840 by 2160 for a 4K stream: the overlay scales without losing sharpness.') }}</li>
            <li>{{ __('Turn off "Shutdown source when not visible", so the overlay keeps its place in the program when you switch scenes.') }}</li>
            <li>{{ __('Leave the custom CSS empty: the overlay brings its own transparent background.') }}</li>
            <li>{{ __('Sound: tick "Control audio via OBS" and set the source\'s level in the audio mixer.') }}</li>
            <li>{{ __('Put the overlay above your game or camera; the centre stays free for the stream.') }}</li>
        </ol>
    </x-admin.panel>
</x-admin.page>
