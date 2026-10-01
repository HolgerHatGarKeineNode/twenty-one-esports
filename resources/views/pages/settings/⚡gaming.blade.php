<?php

use App\Games\GameRegistry;
use App\Models\User;
use App\Support\Stacker\StackerSettings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Gamer tags (P51): the account names a player may keep here, per game, and
 * all of them optional. Who sees a saved tag, as of 2026-09-28: the player
 * alone. It is stored with the account on the league server, never shown on
 * a profile or to another player and never published on Nostr; the only
 * place it leaves this page is the player's own card composer in an EA FC
 * 1v1 room (`ea`, pages/matches/⚡room), where it is a prefill the player
 * still sends, sealed to the opponent. No flow requires a saved tag.
 * Avatar, platform, time zone and language moved to the account tab.
 *
 * Blockfill controls (plan "Blockfill", P3), only while Blockfill is on:
 * handling (DAS/ARR/SDF) and keys, one or two per action, each key once
 * (StackerSettings); the game page reads them.
 */
new #[Title('Gamer tags')] class extends Component {
    /** @var array<string, string> */
    public array $gamerTags = [];

    /** @var array{das: int, arr: int, sdf: int, keys: array<string, list<string>>} */
    public array $stacker = StackerSettings::DEFAULT_HANDLING + ['keys' => StackerSettings::DEFAULT_KEYS];

    public function mount(): void
    {
        $this->gamerTags = $this->fields($this->user()->gamer_tags ?? []);
        $this->stacker = StackerSettings::of($this->user());
    }

    public function saveStacker(): void
    {
        abort_unless((bool) config('esports.blockfill.enabled'), 404);

        $rules = ['stacker.keys' => ['required', 'array']];
        foreach (StackerSettings::LIMITS as $key => [$low, $high]) {
            $rules['stacker.'.$key] = ['required', 'integer', 'min:'.$low, 'max:'.$high];
        }
        $this->validate($rules);

        $keys = array_map(
            fn (mixed $codes): array => array_values(array_filter((array) $codes, fn (mixed $code): bool => is_string($code) && $code !== '')),
            (array) $this->stacker['keys'],
        );
        $wanted = ['das' => (int) $this->stacker['das'], 'arr' => (int) $this->stacker['arr'], 'sdf' => (int) $this->stacker['sdf'], 'keys' => $keys];

        // normalize() falls back to the defaults for anything off: a difference means the input was off.
        if (StackerSettings::normalize($wanted) !== $wanted) {
            // back to what is saved, so the form never shows a binding that was refused
            $this->stacker = StackerSettings::of($this->user());
            $this->addError('stacker.keys', __('Give every action one or two keys, and use each key only once.'));

            return;
        }

        $this->user()->forceFill(['stacker_settings' => $wanted])->save();
        $this->stacker = $wanted;
        $this->dispatch('stacker-saved');
    }

    public function resetStacker(): void
    {
        abort_unless((bool) config('esports.blockfill.enabled'), 404);

        $this->user()->forceFill(['stacker_settings' => null])->save();
        $this->stacker = StackerSettings::of(null);
    }

    public function save(): void
    {
        $services = array_keys(config('esports.gamer_tags'));

        $validated = $this->validate([
            'gamerTags' => ['array:'.implode(',', $services)],
            'gamerTags.*' => ['nullable', 'string', 'max:64'],
        ]);

        $this->store(array_filter(array_map('trim', $validated['gamerTags'] ?? [])));
        $this->gamerTags = $this->fields($this->user()->gamer_tags ?? []);

        $this->dispatch('gamer-tags-saved');
    }

    /**
     * Removes one saved tag at once, without saving what else was typed.
     */
    public function clear(string $service): void
    {
        if (! array_key_exists($service, config('esports.gamer_tags'))) {
            return;
        }

        $saved = $this->user()->gamer_tags ?? [];
        unset($saved[$service]);

        $this->store($saved);
        $this->gamerTags[$service] = '';
    }

    /**
     * Removes every saved tag: all accounts private again.
     */
    public function clearAll(): void
    {
        $this->store([]);
        $this->gamerTags = $this->fields([]);
    }

    /**
     * @param  array<string, string>  $tags
     */
    private function store(array $tags): void
    {
        $this->user()->forceFill(['gamer_tags' => $tags === [] ? null : $tags])->save();
    }

    /**
     * One field per service of `esports.gamer_tags`, filled with the saved tag.
     *
     * @param  array<string, string>  $saved
     * @return array<string, string>
     */
    private function fields(array $saved): array
    {
        $fields = array_fill_keys(array_keys(config('esports.gamer_tags')), '');

        foreach ($fields as $service => $empty) {
            $fields[$service] = (string) ($saved[$service] ?? '');
        }

        return $fields;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $saved = array_filter(auth()->user()->gamer_tags ?? []);
    $labels = config('esports.gamer_tags');
    $registry = app(GameRegistry::class);

    // Which tags each game's card holds, and what reads them today. A registered game in no card needs no tag.
    $cards = [];
    $placed = [];
    // A service has one field on the page: a later card that needs it too (Age of Empires II: Steam, Xbox) points to it.
    $fields = [];

    foreach ([
        [['rocket-league'], ['epic', 'steam', 'psn', 'xbox', 'nintendo'], __('Only for you: nothing on this site reads these. In a Rocket League 1v1 the host shares a private match name and password in the room chat instead.')],
        [['ea-sports-fc-27', 'ea-sports-fc-26'], ['ea'], __('Fills in the EA ID card of your EA Sports FC 1v1 room. It goes out only when you press Send card, and only to your opponent.')],
        [['age-of-empires-2'], ['steam', 'xbox'], __('Only for you: nothing on this site reads these. In an Age of Empires II 1v1 the host shares a lobby name and password in the room chat instead.')],
    ] as [$games, $services, $use]) {
        $games = array_values(array_filter($games, fn (string $slug): bool => $registry->find($slug) !== null));
        $services = array_values(array_filter($services, fn (string $service): bool => isset($labels[$service])));

        if ($games !== [] && $services !== []) {
            $cards[] = ['games' => $games, 'services' => array_values(array_diff($services, $fields)), 'shared' => array_values(array_intersect($services, $fields)), 'use' => $use];
            $placed = [...$placed, ...$games];
            $fields = array_values(array_unique([...$fields, ...$services]));
        }
    }

    $untagged = array_values(array_diff(array_keys($registry->all()), $placed));
    $gameNames = fn (array $slugs): string => \Illuminate\Support\Arr::join(array_map(fn (string $slug): string => \App\Support\GameNames::game($slug), $slugs), ', ', ' '.__('and').' ');
@endphp

<div class="flex grow flex-col gap-5 px-4 pb-8 lg:px-12 lg:pb-10" data-test="gamer-tag-settings">
    <x-settings.header current="gaming" />

    {{-- Privacy first: what a saved tag is, and the private way. --}}
    <section aria-labelledby="gt-privacy-h" class="flex flex-col gap-5 rounded-lg bg-card px-4 py-5 shadow-ring lg:px-6" data-test="gamer-tag-privacy">
        <div class="flex flex-col gap-2">
            <h2 id="gt-privacy-h" class="m-0 font-display text-lg font-bold lg:text-xl">{{ __('Every field is optional') }}</h2>
            <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2">{{ __('You can play every game here without one. An empty field keeps that account private.') }}</p>
        </div>

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <div class="flex gap-3" data-test="privacy-saved">
                <x-icon name="lock" :size="18" class="mt-0.5 shrink-0 text-ink-2" />
                <p class="m-0 max-w-[60ch] text-[13px] leading-normal text-ink-2"><b class="text-ink">{{ __('Saved here') }}</b><br>{{ __('Stored on the league server with your account. Only you see them: they are not on your profile, not shown to other players and not published on Nostr.') }}</p>
            </div>
            <div class="flex gap-3" data-test="privacy-room">
                <x-icon name="key" :size="18" class="mt-0.5 shrink-0 text-ink-2" />
                <div class="flex flex-col gap-2">
                    <p class="m-0 max-w-[60ch] text-[13px] leading-normal text-ink-2"><b class="text-ink">{{ __('Shared in a match room') }}</b><br>{{ __('In a 1v1 room you send your EA ID or a private match to your opponent in the room chat, end-to-end encrypted over Nostr (NIP-17). The league only learns that a card went out, never what is in it.') }}</p>
                    <span class="flex flex-wrap gap-x-5">
                        <a href="{{ route('rules') }}#casual-1v1" class="inline-flex min-h-11 items-center text-[13px] font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink" data-test="privacy-rules-link">{{ __('How a 1v1 works') }}</a>
                        <a href="{{ route('play') }}" class="inline-flex min-h-11 items-center text-[13px] font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink" data-test="privacy-play-link">{{ __('Play 1v1 casual') }}</a>
                    </span>
                </div>
            </div>
        </div>

        <div class="flex min-h-11 flex-wrap items-center gap-x-4 gap-y-2 border-t border-hairline pt-4" data-test="gamer-tag-state">
            @if ($saved === [])
                <span class="flex items-center gap-2 text-[13px] text-win" data-test="all-private"><x-icon name="lock" :size="16" class="shrink-0" />{{ __('Nothing saved. All your accounts stay private.') }}</span>
            @else
                <span class="text-[13px] text-ink-2" data-test="saved-count">{{ trans_choice('{1} One tag saved.|[2,*] :count tags saved.', count($saved), ['count' => count($saved)]) }}</span>
                <x-button variant="quiet" icon="lock" wire:click="clearAll" wire:confirm="{{ __('Remove all saved gamer tags?') }}" data-test="clear-all-tags">{{ __('Keep all private') }}</x-button>
            @endif
        </div>
    </section>

    <form wire:submit="save" class="flex flex-col gap-5" aria-label="{{ __('Gamer tags') }}">
        {{-- Two columns on a desktop: the first game's card left, the others stacked right. --}}
        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-2">
            <div class="flex min-w-0 flex-col gap-5">
            @foreach ($cards as $i => $card)
                @if ($i === 1)
            </div>
            <div class="flex min-w-0 flex-col gap-5">
                @endif
                <section aria-labelledby="gt-card-{{ $i }}" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="tag-card-{{ $card['games'][0] }}">
                    <div class="flex items-start gap-4">
                        <x-game-cover :game="$card['games'][0]" size="thumb" class="w-16 rounded-sm sm:w-24" />
                        <div class="flex min-w-0 flex-col gap-1">
                            <h3 id="gt-card-{{ $i }}" class="m-0 text-[15px] font-bold">{{ $gameNames($card['games']) }}</h3>
                            <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="tag-use">{{ $card['use'] }}</p>
                            @if ($card['shared'] !== [])
                                <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="tag-shared">{{ __('Your :services tags above count here too.', ['services' => \Illuminate\Support\Arr::join(array_map(fn (string $service): string => $labels[$service], $card['shared']), ', ', ' '.__('and').' ')]) }}</p>
                            @endif
                        </div>
                    </div>

                    @foreach ($card['services'] as $service)
                        <div class="flex flex-col gap-1.5 border-t border-hairline pt-4" wire:key="tag-{{ $service }}" data-test="tag-field-{{ $service }}">
                            <label for="tag-{{ $service }}" class="text-sm">{{ $labels[$service] }}</label>
                            <div class="flex gap-2">
                                <input id="tag-{{ $service }}" wire:model="gamerTags.{{ $service }}" maxlength="64" autocomplete="off" spellcheck="false" aria-describedby="tag-{{ $service }}-state"
                                       class="h-11 min-w-0 grow rounded-md border border-edge bg-ground px-3 text-[13px] text-ink" data-test="tag-input-{{ $service }}">
                                @if (isset($saved[$service]))
                                    <x-button variant="quiet" icon="close" wire:click="clear('{{ $service }}')" class="shrink-0" aria-label="{{ __('Remove your :service tag', ['service' => $labels[$service]]) }}" data-test="tag-clear-{{ $service }}">{{ __('Remove') }}</x-button>
                                @endif
                            </div>
                            @if (isset($saved[$service]))
                                <span id="tag-{{ $service }}-state" class="flex items-center gap-1.5 text-xs text-ink-2" data-test="tag-state-saved"><x-icon name="check" :size="14" class="shrink-0" />{{ __('Saved. Only you see it.') }}</span>
                            @else
                                <span id="tag-{{ $service }}-state" class="flex items-center gap-1.5 text-xs text-win" data-test="tag-state-private"><x-icon name="lock" :size="14" class="shrink-0" />{{ __('Private. Nothing saved.') }}</span>
                            @endif
                            @error('gamerTags.'.$service)<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                        </div>
                    @endforeach
                </section>
            @endforeach
            @if (count($cards) < 2)
            </div>
            <div class="flex min-w-0 flex-col gap-5">
            @endif

            @if ($untagged !== [])
                <section aria-labelledby="gt-card-none" class="flex items-start gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="tag-card-none">
                    <x-game-cover :game="$untagged[0]" size="thumb" class="w-16 rounded-sm sm:w-24" />
                    <div class="flex min-w-0 flex-col gap-1">
                        <h3 id="gt-card-none" class="m-0 text-[15px] font-bold">{{ $gameNames($untagged) }}</h3>
                        <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Played right here on the site. It needs no gamer tag.') }}</p>
                    </div>
                </section>
            @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <x-button type="submit" data-test="gamer-tags-save">{{ __('Save gamer tags') }}</x-button>
            <span role="status" class="flex items-center gap-1.5 text-[13px] text-win" x-data="{ shown: false }" x-show="shown" x-cloak
                  x-on:gamer-tags-saved.window="shown = true; setTimeout(() => shown = false, 2000)" data-test="gamer-tags-saved"><x-icon name="check" :size="16" />{{ __('Saved.') }}</span>
        </div>
    </form>

    @if (config('esports.blockfill.enabled'))
        {{-- Blockfill controls: a key is set by clicking its slot and pressing the key (KeyboardEvent.code). --}}
        <form wire:submit="saveStacker" id="blockfill" class="flex flex-col gap-5 rounded-lg bg-card px-4 py-5 lg:px-6" aria-labelledby="stacker-h" data-test="stacker-settings"
              x-data="{ listening: null, listen(action, slot) { this.listening = [action, slot]; }, isListening(action, slot) { return this.listening !== null && this.listening[0] === action && this.listening[1] === slot; } }"
              x-on:keydown.window="if (listening !== null) { $event.preventDefault(); const [action, slot] = listening; listening = null; if ($event.code !== 'Escape') { $wire.set(`stacker.keys.${action}.${slot}`, $event.code); } }">
            <div class="flex min-w-0 flex-col gap-1">
                <h2 id="stacker-h" class="m-0 font-display text-lg font-bold lg:text-xl">Blockfill</h2>
                <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2">{{ __('How the pieces move for you, in ticks of 1/60 s. Saved with your account; a ranked run carries the values it was played with.') }}</p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach (['das' => __('DAS: ticks before a held key repeats'), 'arr' => __('ARR: ticks between repeats (0 = to the wall)'), 'sdf' => __('Soft drop speed (41 = instant)')] as $field => $label)
                    <label class="flex flex-col gap-1.5 text-sm">{{ $label }}
                        <input type="number" min="{{ StackerSettings::LIMITS[$field][0] }}" max="{{ StackerSettings::LIMITS[$field][1] }}" wire:model="stacker.{{ $field }}" class="h-11 w-28 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink" data-test="stacker-{{ $field }}">
                        @error('stacker.'.$field)<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                    </label>
                @endforeach
            </div>

            <div class="flex flex-col gap-2 border-t border-hairline pt-4">
                <span class="text-sm">{{ __('Keys: click a slot, then press the key. Escape keeps the old one.') }}</span>
                <dl class="m-0 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                    @foreach (['left' => __('Move left'), 'right' => __('Move right'), 'soft' => __('Soft drop'), 'hard' => __('Hard drop'), 'ccw' => __('Turn left'), 'cw' => __('Turn right'), 'flip' => __('Turn 180°'), 'hold' => __('Hold'), 'restart' => __('Restart at once')] as $action => $label)
                        <div class="flex items-center justify-between gap-3" data-test="stacker-key-{{ $action }}">
                            <dt class="text-[13px] text-ink-2">{{ $label }}</dt>
                            <dd class="m-0 flex gap-2">
                                @foreach ([0, 1] as $slot)
                                    <button type="button" class="h-11 min-w-[88px] rounded-md border border-line bg-well px-2 font-mono text-[12px] text-ink"
                                            x-on:click="listen('{{ $action }}', {{ $slot }})" x-bind:class="isListening('{{ $action }}', {{ $slot }}) && 'border-btc text-btc'"
                                            data-test="stacker-slot-{{ $action }}-{{ $slot }}">{{ isset($stacker['keys'][$action][$slot]) ? StackerSettings::label($stacker['keys'][$action][$slot]) : '–' }}</button>
                                @endforeach
                            </dd>
                        </div>
                    @endforeach
                </dl>
                @error('stacker.keys')<span class="text-xs text-loss" role="alert" data-test="stacker-keys-error">{{ $message }}</span>@enderror
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <x-button type="submit" data-test="stacker-save">{{ __('Save Blockfill controls') }}</x-button>
                <x-button variant="quiet" wire:click="resetStacker" data-test="stacker-reset">{{ __('Back to the defaults') }}</x-button>
                <span role="status" class="flex items-center gap-1.5 text-[13px] text-win" x-data="{ shown: false }" x-show="shown" x-cloak
                      x-on:stacker-saved.window="shown = true; setTimeout(() => shown = false, 2000)" data-test="stacker-saved"><x-icon name="check" :size="16" />{{ __('Saved.') }}</span>
            </div>
        </form>
    @endif
</div>
