<?php

use App\Enums\JoinRequestStatus;
use App\Enums\Platform;
use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\ClanJoinRequest;
use App\Models\User;
use App\Support\Clans\ClanApplication;
use App\Support\Clans\ClanJoinRequests;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Nostr\NostrBar;
use App\Support\Nostr\SignerMessages;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * "Apply" on a clan page (plan "Clan-Bewerbungen", P3): the short form
 * (games and platforms as chips, the time zone prefilled from the browser,
 * a message of at most 280 characters), then the applicant's status with
 * "Withdraw" while it is open.
 *
 * Submitting stores the application (ClanJoinRequests::apply(): the bell for
 * the owner and every captain) and hands back one text per captain; the
 * browser sends them as NIP-17 DMs from the applicant's own signer
 * (clanDmsUi.js). A guest gets the way to log in; a member of the clan sees
 * nothing; a clan with "Applications open" off says so instead of the button.
 */
new class extends Component
{
    #[Locked]
    public int $clanId;

    #[Locked]
    public bool $open = false;

    /** @var list<string> */
    public array $games = [];

    /** @var list<string> */
    public array $platforms = [];

    public string $timezone = '';

    public string $message = '';

    public function mount(int $clanId, bool $open = false): void
    {
        $this->clanId = $clanId;
        $this->open = $open && $this->state() === 'apply';
    }

    #[Computed]
    public function clan(): Clan
    {
        return Clan::query()->findOrFail($this->clanId);
    }

    /**
     * The viewer's latest request to this clan; a withdrawn one counts as none.
     */
    #[Computed]
    public function request(): ?ClanJoinRequest
    {
        $user = Auth::user();
        $request = $user instanceof User ? app(ClanJoinRequests::class)->latestFor($this->clan, $user) : null;

        return $request?->status === JoinRequestStatus::Withdrawn ? null : $request;
    }

    /**
     * guest | member | open (a request waits) | listed | apply | closed
     */
    public function state(): string
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return 'guest';
        }

        if ($this->clan->memberOf($user) !== null) {
            return 'member';
        }

        $status = $this->request?->status;

        return match (true) {
            $status !== null && $status->isOpen() => 'open',
            $status === JoinRequestStatus::Listed && $this->request?->clanInvite !== null => 'listed',
            $this->clan->applications_open => 'apply',
            default => 'closed',
        };
    }

    /**
     * Opens the form; `timezone` is the browser's, taken while none is picked.
     */
    public function openForm(?string $timezone = null): void
    {
        if ($this->state() !== 'apply') {
            return;
        }

        $this->open = true;

        if ($this->timezone === '' && is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $this->timezone = $timezone;
        }
    }

    public function closeForm(): void
    {
        $this->open = false;
        $this->resetErrorBag();
    }

    /**
     * Stores the application and returns the texts for the captains' DMs;
     * null when the form or a rule refused it.
     *
     * @return list<array{pubkey: string, content: string}>|null
     */
    public function apply(ClanJoinRequests $requests): ?array
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $this->games = array_values(array_unique($this->games));
        $this->platforms = array_values(array_unique($this->platforms));
        $this->validate();

        try {
            $request = $requests->apply($this->clan, $user, new ClanApplication(
                $this->games,
                $this->platforms,
                $this->timezone,
                trim($this->message) === '' ? null : trim($this->message),
            ));
        } catch (ClanRuleViolation $violation) {
            $this->addError('application', $violation->getMessage());

            return null;
        }

        $this->reset('open', 'games', 'platforms', 'message');
        unset($this->request);

        return $requests->applicationDms($request);
    }

    public function withdraw(ClanJoinRequests $requests): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        if ($this->request === null) {
            return;
        }

        try {
            $requests->withdraw($this->request, $user);
        } catch (ClanRuleViolation $violation) {
            $this->addError('application', $violation->getMessage());
        }

        unset($this->request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'games' => ['required', 'array', 'min:1'],
            'games.*' => ['string', Rule::in(array_keys(app(GameRegistry::class)->all()))],
            'platforms' => ['array'],
            'platforms.*' => ['string', Rule::enum(Platform::class)],
            'timezone' => ['required', 'string', 'timezone:all'],
            'message' => ['nullable', 'string', 'max:'.ClanApplication::MESSAGE_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'games.required' => __('Pick at least one game.'),
            'games.min' => __('Pick at least one game.'),
            'games.*.in' => __('Pick games from the list.'),
            'platforms.*.enum' => __('Pick platforms from the list.'),
            'timezone.required' => __('Pick your time zone.'),
            'timezone.timezone' => __('Pick your time zone.'),
            'message.max' => __('At most :max characters.', ['max' => ClanApplication::MESSAGE_MAX]),
        ];
    }
}; ?>

@php
    $state = $this->state();
    $clan = $this->clan;
    $request = $this->request;
    $chip = 'inline-flex h-11 min-w-11 cursor-pointer items-center justify-center gap-2 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink-2 select-none has-checked:border-btc has-checked:bg-btc-chip has-checked:font-bold has-checked:text-btc-hi has-focus-visible:outline-2 has-focus-visible:outline-btc-hi';
    $zones = collect(DateTimeZone::listIdentifiers())->groupBy(fn (string $zone): string => str_contains($zone, '/') ? strstr($zone, '/', true) : $zone);
@endphp

{{-- A member of the clan sees nothing; the root stays for Livewire. --}}
<div @if ($state === 'member') hidden @endif
     x-data="clanDms({ me: @js(auth()->user()?->pubkey), relays: @js(NostrBar::browserRelays()), labels: @js([
         'sent' => __('Your application reached :sent of :total captains as an encrypted Nostr message.'),
         'none' => __('Your application is stored and the captains see it on the site; no Nostr message reached them.'),
         'noSigner' => __('Your application is stored and the captains see it on the site. No Nostr signer was found to message them.'),
         'signer' => SignerMessages::labels(),
         'no_nip44' => __("Your signer can't send private messages the modern way — update it or use one that supports NIP-44."),
     ]) })"
     x-init="if (@js($open) && ! $wire.timezone) { $wire.openForm(Intl.DateTimeFormat().resolvedOptions().timeZone) }">
@if ($state !== 'member')
    <section aria-labelledby="clan-apply-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-4 shadow-ring-btc lg:px-6"
             data-test="clan-apply" data-state="{{ $state }}" @if ($request) data-status="{{ $request->status->value }}" @endif>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-6">
            <span class="flex min-w-0 grow items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-md bg-btc-tint text-btc" aria-hidden="true"><x-icon name="shield-check" :size="20" /></span>
                <span class="flex min-w-0 flex-col gap-1">
                    <h2 id="clan-apply-h" class="m-0 font-display text-base leading-[1.25] font-bold break-words">{{ __('Play with :clan', ['clan' => $clan->name]) }}</h2>
                    <span class="max-w-[68ch] text-[13px] leading-normal text-ink-2" data-test="clan-apply-text">
                        @if ($state === 'open' && $request?->status === JoinRequestStatus::Approved)
                            {{ __('A captain said yes. Waiting for the founder to add you to the clan record.') }}
                        @elseif ($state === 'open')
                            {{ __('Your application was sent. Waiting for a captain of :clan.', ['clan' => $clan->name]) }}
                        @elseif ($state === 'listed')
                            {{ __(':clan said yes. Confirm your membership to join the roster.', ['clan' => $clan->name]) }}
                        @elseif ($request?->status === JoinRequestStatus::Declined)
                            {{ __(':clan declined your application.', ['clan' => $clan->name]) }}
                            @if ($state === 'apply') {{ __('You can apply again.') }} @endif
                        @elseif ($state === 'closed')
                            {{ __(':clan is not taking applications right now.', ['clan' => $clan->name]) }}
                        @else
                            {{ __('Apply with your games, platforms and time zone. The captains answer here and get your application as a Nostr message.') }}
                        @endif
                    </span>
                </span>
            </span>

            <span class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($state === 'guest')
                    <a href="{{ route('login') }}" data-test="clan-apply-login"
                       class="inline-flex h-11 items-center justify-center rounded-md border border-btc px-[18px] text-[13px] font-bold whitespace-nowrap text-btc-hi hover:bg-btc-press hover:text-btc-hi">{{ __('Log in to apply') }}</a>
                @elseif ($state === 'apply' && ! $open)
                    <button type="button" x-on:click="$wire.openForm(Intl.DateTimeFormat().resolvedOptions().timeZone)" data-test="clan-apply-open"
                            class="btn-p inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-5 text-sm font-bold whitespace-nowrap text-on-btc max-sm:grow">{{ __('Apply') }}</button>
                @elseif ($state === 'open')
                    <button type="button" wire:click="withdraw" wire:loading.attr="disabled" data-test="clan-apply-withdraw"
                            class="btn-w inline-flex h-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well px-4 text-[13px] text-ink max-sm:grow">{{ __('Withdraw') }}</button>
                @elseif ($state === 'listed')
                    <a href="{{ route('invites.show', $request->clanInvite) }}" data-test="clan-apply-join"
                       class="btn-p inline-flex h-11 items-center justify-center rounded-md bg-btc px-5 text-sm font-bold whitespace-nowrap text-on-btc hover:text-on-btc max-sm:grow">{{ __('Join') }}</a>
                @endif
            </span>
        </div>

        <p class="m-0 text-xs text-btc-hi" x-show="dmBusy" x-cloak role="status" data-test="clan-apply-dm-busy">{{ __('Sending your application to the captains as a Nostr message…') }}</p>
        <p class="m-0 text-xs text-ink-2" x-show="dmNote" x-text="dmNote" x-cloak role="status" data-test="clan-apply-dm-note"></p>
        <p class="m-0 text-xs text-loss" x-show="dmError" x-text="dmError" x-cloak role="alert" data-test="clan-apply-dm-error"></p>
        <p class="m-0 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-2" x-show="waiting.length > 0" x-cloak data-test="clan-apply-dm-confirm">
            <span x-text="@js(__('No DM inbox relays (NIP-17) for :count of them. Send it as an older NIP-04 message instead?')).replace(':count', waiting.length)"></span>
            <button type="button" x-on:click="sendWaiting()" x-bind:disabled="dmBusy" data-test="clan-apply-dm-nip04"
                    class="inline-flex h-11 cursor-pointer items-center rounded-md border border-line bg-well px-4 text-[13px] text-ink disabled:cursor-wait disabled:opacity-70">{{ __('Send as NIP-04') }}</button>
        </p>
        @error('application')<p class="m-0 text-xs text-loss" role="alert" data-test="clan-apply-error">{{ $message }}</p>@enderror

        @if ($state === 'apply' && $open)
            <form class="flex flex-col gap-4 border-t border-hairline pt-4" data-test="clan-apply-form"
                  x-on:submit.prevent="deliver(await $wire.apply())">
                <fieldset class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0">
                    <legend class="mb-2 p-0 text-xs text-ink-2">{{ __('Games you want to play with :clan', ['clan' => $clan->name]) }}</legend>
                    <span class="flex flex-wrap gap-2">
                        @foreach (app(GameRegistry::class)->all() as $slug => $game)
                            <label wire:key="g-{{ $slug }}" class="{{ $chip }}" data-test="clan-apply-game">
                                <input type="checkbox" wire:model="games" value="{{ $slug }}" class="sr-only">{{ $game->name() }}
                            </label>
                        @endforeach
                    </span>
                    @error('games')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                    @error('games.*')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                </fieldset>

                <fieldset class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0">
                    <legend class="mb-2 p-0 text-xs text-ink-2">{{ __('Platforms you play on') }}</legend>
                    <span class="flex flex-wrap gap-2">
                        @foreach (Platform::cases() as $platform)
                            <label wire:key="p-{{ $platform->value }}" class="{{ $chip }}" data-test="clan-apply-platform">
                                <input type="checkbox" wire:model="platforms" value="{{ $platform->value }}" class="sr-only">{{ $platform->label() }}
                            </label>
                        @endforeach
                    </span>
                    @error('platforms.*')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                </fieldset>

                <label class="flex max-w-md flex-col gap-2"><span class="text-xs text-ink-2">{{ __('Your time zone') }}</span>
                    <select wire:model="timezone" class="h-11 w-full rounded-lg border border-edge bg-ground px-3 text-[13px] text-ink" data-test="clan-apply-timezone">
                        <option value="">{{ __('Pick your time zone') }}</option>
                        @foreach ($zones as $region => $names)
                            <optgroup label="{{ $region }}">
                                @foreach ($names as $zone)
                                    <option value="{{ $zone }}">{{ str_replace('_', ' ', $zone) }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('timezone')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                </label>

                <label class="flex flex-col gap-2" x-data="{ count: 0 }"><span class="flex justify-between gap-3 text-xs text-ink-2"><span>{{ __('Message to the captains (optional)') }}</span><span x-text="count + ' / {{ ClanApplication::MESSAGE_MAX }}'" aria-hidden="true"></span></span>
                    <textarea wire:model="message" maxlength="{{ ClanApplication::MESSAGE_MAX }}" rows="3" x-on:input="count = $el.value.length" x-init="count = $el.value.length"
                              placeholder="{{ __('What you play, when you are online, what you are looking for.') }}"
                              class="w-full rounded-lg border border-edge bg-ground px-3 py-2.5 text-[13px] leading-normal text-ink placeholder:text-ink-3" data-test="clan-apply-message"></textarea>
                    @error('message')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                </label>

                <span class="flex flex-wrap items-center gap-2">
                    <button type="submit" wire:loading.attr="disabled" x-bind:disabled="dmBusy" data-test="clan-apply-send"
                            class="btn-p inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-5 text-sm font-bold text-on-btc disabled:cursor-wait disabled:opacity-70 max-sm:grow">{{ __('Send application') }}</button>
                    <button type="button" wire:click="closeForm" data-test="clan-apply-cancel"
                            class="inline-flex h-11 cursor-pointer items-center rounded-md border-0 bg-transparent px-3 text-[13px] text-ink-2 hover:text-ink">{{ __('Cancel') }}</button>
                    <span class="text-xs leading-normal text-ink-3">{{ __('The captains see it here; your own signer sends it to them as an encrypted Nostr message.') }}</span>
                </span>
            </form>
        @endif
    </section>
@endif
</div>
