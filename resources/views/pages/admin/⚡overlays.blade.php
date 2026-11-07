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
 * tournament and bracket variants and optionally the break scene, the break scene's state, language, modules) and gets a secret URL for an OBS browser source; everything
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

    /** The break scene's state: '' follows the tournament and the clock, else one of OverlayPreset::SCENES. */
    public string $scene = '';

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
     * Tournaments an overlay can follow, public only: the ones not over, soonest first; for the break scene and the
     * full-screen bracket (both show the champion) also the latest finished ones; and the edited preset's own
     * tournament whatever its state, so a preset whose tournament finished can still be saved.
     *
     * @return Collection<int, Tournament>
     */
    #[Computed]
    public function tournaments(): Collection
    {
        $public = fn () => Tournament::query()->exceptLeagueWeeks()->whereNotNull('published_at')->where('status', '!=', TournamentStatus::Draft);
        $variant = OverlayVariant::tryFrom($this->variant);
        $current = $this->editing === null ? null : OverlayPreset::query()->whereKey($this->editing)->value('tournament_id');

        $open = $public()->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running])
            ->orderBy('starts_at')->orderBy('id')->limit(100)->get();
        $finished = in_array($variant, [OverlayVariant::Break, OverlayVariant::Bracket], true)
            ? $public()->where('status', TournamentStatus::Finished)->orderByDesc('starts_at')->orderByDesc('id')->limit(20)->get()
            : new Collection;
        $kept = $current === null ? new Collection : $public()->whereKey($current)->get();

        return $open->merge($finished)->merge($kept)->values();
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
            'music' => __('Music in the break scene'),
        ];
    }

    public function save(): void
    {
        Gate::authorize('admin');
        $this->revealedUrl = '';
        $this->revealedFor = null;
        $this->flash = '';

        $variant = OverlayVariant::tryFrom($this->variant);
        $needsTournament = $variant?->needsTournament() ?? false;
        $takesTournament = $variant?->takesTournament() ?? false;
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'variant' => ['required', Rule::enum(OverlayVariant::class)],
            'tournament' => $needsTournament ? ['required', 'integer', Rule::in($this->tournaments->modelKeys())] : ($takesTournament ? ['nullable', 'integer', Rule::in($this->tournaments->modelKeys())] : ['nullable']),
            'scene' => ['nullable', Rule::in(['', ...OverlayPreset::SCENES])],
            'locale' => ['required', Rule::in(OverlayPreset::LOCALES)],
            'modules' => ['array'],
            'modules.*' => ['boolean'],
        ], [], ['tournament' => __('Tournament')]);

        $attributes = [
            'name' => trim($this->name),
            'variant' => $this->variant,
            'tournament_id' => $takesTournament ? $this->tournament : null,
            'scene' => $variant === OverlayVariant::Break && $this->scene !== '' ? $this->scene : null,
            'locale' => $this->locale,
            'modules' => array_map(fn (string $module): bool => (bool) ($this->modules[$module] ?? false), array_combine(array_keys(OverlayPreset::MODULES), array_keys(OverlayPreset::MODULES))),
        ];

        if ($this->editing !== null) {
            OverlayPreset::query()->findOrFail($this->editing)->update($attributes);
            $this->flash = __('The preset was saved. Its URL stays the same.');
        } else {
            $token = OverlayPreset::newToken();
            $preset = OverlayPreset::query()->create($attributes + OverlayPreset::tokenAttributes($token) + ['created_by_id' => auth()->id()]);
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
        $this->scene = (string) $preset->scene;
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
        $this->scene = '';
        $this->locale = 'de';
        $this->modules = OverlayPreset::MODULES;
        $this->resetValidation();
    }
}; ?>

@php
    $input = 'rv-in';
    $needsTournament = OverlayVariant::tryFrom($variant)?->needsTournament() ?? false;
    $takesTournament = OverlayVariant::tryFrom($variant)?->takesTournament() ?? false;
    $isBreak = $variant === OverlayVariant::Break->value;
    $revealedName = $revealedFor === null ? null : $this->presets->firstWhere('id', $revealedFor)?->name;
@endphp

<x-admin.page active="overlays" :title="__('OBS overlays')" :lead="__('A preset is one browser source in OBS: choose what it shows, copy its secret URL into OBS once, and it runs on its own. Anyone with the URL sees the overlay, so rotate it when it got out.')" data-test="admin-overlays">
    <x-slot:readout>
        <span class="flex items-baseline gap-2"><span class="rv-m">{{ __('Presets') }}</span><span class="rv-num text-base text-ink" data-test="readout-value">{{ $this->presets->count() }}</span></span>
    </x-slot:readout>

    @if ($revealedUrl !== '')
        <section class="flex flex-col gap-3 rounded border border-hairline bg-card px-4 py-5 shadow-[inset_0_0_0_1px_var(--color-btc)] lg:px-6" data-test="overlay-revealed"
                 {{-- Created from the form further down: on a phone the URL would be out of view. --}}
                 x-init="$el.scrollIntoView({ block: 'start' })"
                 x-data="{ copied: false, hint: '', url: @js($revealedUrl), async copy() { try { await navigator.clipboard.writeText(this.url); this.copied = true; this.hint = ''; setTimeout(() => this.copied = false, 2500); } catch (e) { this.hint = @js(__('Copy did not work here. Select the link and copy it.')); } } }">
            <h2 class="rv-h3">{{ __('The URL of :name', ['name' => $revealedName ?? '']) }}</h2>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Paste it into OBS. It stays in the list below, ready to copy again.') }}</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                <label class="sr-only" for="overlay-url">{{ __('Overlay URL') }}</label>
                <input id="overlay-url" type="text" readonly value="{{ $revealedUrl }}" x-on:focus="$el.select()" class="{{ $input }} font-mono text-[13px]" data-test="overlay-url">
                <button type="button" x-on:click="copy()" class="rv-b" data-test="overlay-copy">
                    <span x-show="! copied">{{ __('Copy URL') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                </button>
            </div>
            <p class="m-0 text-xs text-loss" x-show="hint !== ''" x-text="hint" x-cloak></p>
            <div><button type="button" wire:click="dismiss" class="rv-b2" data-test="overlay-dismiss">{{ __('Done') }}</button></div>
        </section>
    @endif

    <x-admin.flash :message="$flash" data-test="overlay-flash" />

    {{-- AdminOverlays.dc.html: the new preset on the left, the OBS set-up beside it (360 px), the presets below. --}}
    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
    <x-admin.panel :title="$editing === null ? __('New preset') : __('Edit preset')" data-test="overlay-form-panel">
        <form wire:submit="save" class="flex flex-col gap-4" data-test="overlay-form">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <label class="min-w-0"><span class="rv-lb">{{ __('Name') }}</span>
                    <input type="text" wire:model="name" maxlength="80" placeholder="{{ __('Laptop stream') }}" class="{{ $input }}" data-test="overlay-name">
                </label>
                <div class="min-w-0"><span class="rv-lb" id="overlay-locale-l">{{ __('Language') }}</span>
                    <x-ui.select-menu model="locale" labelledby="overlay-locale-l" :value="$this->locale" :options="['de' => 'Deutsch', 'en' => 'English']" data-test="overlay-locale" /></div>
            </div>
            <div class="min-w-0"><span class="rv-lb" id="overlay-variant-l">{{ __('What it shows') }}</span>
                <x-ui.select-menu model="variant" live labelledby="overlay-variant-l" :value="$this->variant" :options="collect(OverlayVariant::cases())->mapWithKeys(fn ($case): array => [$case->value => $case->label()])->all()" data-test="overlay-variant" /></div>
            @if ($takesTournament)
                <div class="min-w-0"><span class="rv-lb" id="overlay-tournament-l">{{ __('Tournament') }}</span>
                    <x-ui.select-menu model="tournament" labelledby="overlay-tournament-l" :value="$this->tournament" data-test="overlay-tournament"
                                      :options="['' => $needsTournament ? __('Choose a tournament') : __('The next cup or tournament, automatically')] + $this->tournaments->mapWithKeys(fn ($choice): array => [$choice->id => $choice->name.', '.$choice->status->label().', '.\App\Support\Formats::date($choice->starts_at)])->all()" />
                    @if ($this->tournaments->isEmpty())
                        <span class="mt-1 block rv-m">{{ __('No tournament is open for sign-up or running right now.') }}</span>
                    @endif
                </div>
            @endif
            @if ($isBreak)
                <div class="min-w-0" x-data="{ scene: $wire.$entangle('scene', false) }">
                    <span class="rv-lb" id="overlay-scene-l">{{ __('Scene') }}</span>
                    <div role="radiogroup" aria-labelledby="overlay-scene-l" class="flex flex-wrap gap-2" data-test="overlay-scene">
                        @foreach (['' => __('Follows the tournament'), 'soon' => __('Starting soon'), 'break' => __('Short break'), 'end' => __('Thanks for watching')] as $value => $label)
                            <button type="button" role="radio" class="rv-chip" aria-checked="{{ (string) $this->scene === (string) $value ? 'true' : 'false' }}" :aria-checked="String(scene ?? '') === @js((string) $value) ? 'true' : 'false'"
                                    x-bind:aria-pressed="String(scene ?? '') === @js((string) $value) ? 'true' : 'false'" x-on:click="scene = @js($value)" data-test="overlay-scene-{{ $value === '' ? 'auto' : $value }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
            <fieldset class="m-0 flex flex-col border-0 p-0">
                <legend class="rv-lb">{{ __('Modules') }}</legend>
                <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
                    @foreach ($this->moduleLabels() as $module => $label)
                        <x-ui.check :model="'modules.'.$module" :checked="(bool) ($this->modules[$module] ?? false)" data-test="overlay-module-{{ $module }}">{{ $label }}</x-ui.check>
                    @endforeach
                </div>
            </fieldset>
            @if ($errors->any())
                <p class="m-0 text-[13px] text-loss" role="alert" data-test="overlay-error">{{ $errors->first() }}</p>
            @endif
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="rv-b" data-test="overlay-save">{{ $editing === null ? __('Create preset') : __('Save preset') }}</button>
                @if ($editing !== null)
                    <button type="button" wire:click="cancel" class="rv-b2" data-test="overlay-cancel">{{ __('Cancel') }}</button>
                @endif
            </div>
        </form>
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
    </div>

    <x-admin.panel :title="__('Presets')" :tag="trans_choice(':count preset|:count presets', $this->presets->count())" data-test="overlay-presets">
        @forelse ($this->presets as $preset)
            <div wire:key="overlay-{{ $preset->id }}" class="grid grid-cols-1 gap-2 border-t border-hairline py-3 text-[13px] first:border-t-0 lg:grid-cols-[160px_auto_minmax(0,1fr)_auto] lg:items-center lg:gap-3" data-test="overlay-row">
                <b class="truncate">{{ $preset->name }}</b>
                <span class="rv-tag w-fit">{{ $preset->variant->label() }}</span>
                <span class="flex min-w-0 flex-col gap-0.5">
                    @php
                        $presetMeta = array_filter([
                            strtoupper($preset->locale),
                            $preset->variant->needsTournament() ? ($preset->tournament?->name ?? __('Tournament no longer exists')) : ($preset->variant->takesTournament() ? $preset->tournament?->name : null),
                            $preset->rotated_at === null ? __('URL from :date', ['date' => \App\Support\Formats::day($preset->created_at)]) : __('URL rotated :date', ['date' => \App\Support\Formats::day($preset->rotated_at)]),
                        ]);
                    @endphp
                    <span class="rv-m">{{ implode(', ', $presetMeta) }}</span>
                </span>
                <span class="flex flex-wrap gap-2">
                    <button type="button" wire:click="edit({{ $preset->id }})" class="rv-b2 is-sm" data-test="overlay-edit">{{ __('Edit') }}</button>
                    <button type="button" wire:click="rotate({{ $preset->id }})" wire:confirm="{{ __('Rotate the URL of :name? The old URL stops working at once; OBS needs the new one.', ['name' => $preset->name]) }}" class="rv-b2 is-sm" data-test="overlay-rotate">{{ __('New URL') }}</button>
                    <button type="button" wire:click="remove({{ $preset->id }})" wire:confirm="{{ __('Delete :name? Its URL stops working.', ['name' => $preset->name]) }}" class="rv-bd is-sm" data-test="overlay-remove">{{ __('Delete') }}</button>
                </span>
                {{--
                    The preset's URL, always there to copy (user, 2026-10-11): selectable mono text that wraps, Copy (the
                    clipboard call inside the click; refused, the text is selected instead, so Ctrl+C takes it) and Open.
                    A preset made before tokens were kept says how to get one, without a dead copy button.
                --}}
                @php($presetUrl = $preset->storedUrl())
                @if ($presetUrl !== null)
                    <div class="flex flex-col gap-2 rounded border border-hairline bg-ground p-2 sm:flex-row sm:items-center lg:col-span-4" wire:key="overlay-url-{{ $preset->id }}-{{ $preset->rotated_at?->timestamp }}" data-test="overlay-row-url"
                         x-data="{ copied: false, timer: null, copy() { navigator.clipboard.writeText(@js($presetUrl)).then(() => { this.copied = true; clearTimeout(this.timer); this.timer = setTimeout(() => this.copied = false, 2000); }, () => { const range = document.createRange(); range.selectNodeContents(this.$refs.url); const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range); }); } }">
                        <code x-ref="url" class="min-w-0 grow px-1 font-mono text-xs leading-normal break-all text-ink-2 select-all" data-test="overlay-row-url-text">{{ $presetUrl }}</code>
                        <span class="flex shrink-0 gap-2">
                            <button type="button" x-on:click="copy()" class="rv-b is-sm" data-test="overlay-row-copy">
                                <span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak role="status">{{ __('Copied') }}</span>
                            </button>
                            <a href="{{ $presetUrl }}" target="_blank" rel="noopener" class="rv-b2 is-sm" data-test="overlay-row-open">{{ __('Open the overlay') }}</a>
                        </span>
                    </div>
                @else
                    <p class="m-0 rounded border border-dashed border-dash px-3 py-2 text-xs leading-normal text-ink-2 lg:col-span-4" data-test="overlay-row-legacy">{{ __('URL created before the update: press “New URL” once and it stays visible here.') }}</p>
                @endif
            </div>
        @empty
            <x-admin.empty :text="__('No preset yet. Create the first one above.')" />
        @endforelse
    </x-admin.panel>

</x-admin.page>
