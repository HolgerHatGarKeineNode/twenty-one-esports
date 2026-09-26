<?php

use App\Enums\Platform;
use App\Livewire\Actions\DeleteAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/*
 * Esports extras only. Name and picture come from the user's Nostr profile
 * and are edited in their own Nostr client, never here.
 */
new #[Title('Gaming profile')] class extends Component {
    use WithFileUploads;

    public ?TemporaryUploadedFile $avatar = null;

    public string $platform = '';

    /** @var array<string, string> */
    public array $gamerTags = [];

    public string $timezone = '';

    public string $locale = '';

    public bool $confirmDeletion = false;

    public function mount(): void
    {
        $user = $this->user();

        $this->platform = $user->platform->value ?? '';
        $this->gamerTags = array_merge(
            array_fill_keys(array_keys(config('esports.gamer_tags')), ''),
            $user->gamer_tags ?? [],
        );
        $this->timezone = $user->timezone ?? '';
        $this->locale = $user->locale ?? app()->getLocale();
    }

    public function save(): void
    {
        $services = array_keys(config('esports.gamer_tags'));

        $validated = $this->validate([
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'gamerTags' => ['array:'.implode(',', $services)],
            'gamerTags.*' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'timezone:all'],
            'locale' => ['required', Rule::in(config('app.supported_locales'))],
        ]);

        $user = $this->user();

        if ($this->avatar !== null) {
            $previous = $user->avatar_path;
            $user->avatar_path = $this->avatar->store('avatars', 'public') ?: null;

            if ($previous !== null) {
                Storage::disk('public')->delete($previous);
            }

            $this->avatar = null;
        }

        $user->fill([
            'platform' => $validated['platform'] ?: null,
            'gamer_tags' => array_filter(array_map('trim', $validated['gamerTags'] ?? [])) ?: null,
            'timezone' => $validated['timezone'] ?: null,
            'locale' => $validated['locale'],
        ])->save();

        session()->put('locale', $validated['locale']);

        $this->dispatch('gaming-profile-saved');
    }

    public function removeAvatar(): void
    {
        $user = $this->user();

        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }
    }

    public function deleteAccount(DeleteAccount $deleteAccount): void
    {
        $this->validate(['confirmDeletion' => ['accepted']]);

        $deleteAccount($this->user());

        $this->redirect(route('home'));
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<section class="w-full max-w-lg space-y-10 px-4 pb-8 lg:max-w-[calc(32rem+6rem)] lg:px-12 lg:pb-10">
    <nav aria-label="{{ __('Settings sections') }}" class="flex border-b border-hairline">
        <a href="{{ route('gaming.edit') }}" aria-current="page" class="flex h-11 items-center px-4 text-[13px] font-bold text-ink shadow-[inset_0_-2px_0_#F7931A] hover:text-ink">{{ __('General') }}</a>
        <a href="{{ route('settings.chess') }}" class="flex h-11 items-center px-4 text-[13px] text-ink-2 hover:text-ink" data-test="settings-chess-tab">{{ __('Chess') }}</a>
        <a href="{{ route('settings.opponents') }}" class="flex h-11 items-center px-4 text-[13px] text-ink-2 hover:text-ink" data-test="settings-opponents-tab">{{ __('Opponents') }}</a>
    </nav>

    <flux:heading size="xl" level="1">{{ __('Gaming profile') }}</flux:heading>

    <flux:text>
        {{ __('Your name and picture come from your Nostr profile. Change them in your Nostr app.') }}
    </flux:text>

    <form wire:submit="save" class="space-y-6">
        <div class="space-y-2">
            <flux:input type="file" wire:model="avatar" :label="__('Avatar')" accept="image/png,image/jpeg,image/webp" />

            @if (auth()->user()->avatar_path)
                <flux:button size="sm" variant="ghost" wire:click="removeAvatar">{{ __('Remove avatar') }}</flux:button>
            @endif
        </div>

        <flux:select wire:model="platform" :label="__('Platform')">
            <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
            @foreach (\App\Enums\Platform::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:fieldset>
            <flux:legend>{{ __('Gamer tags') }}</flux:legend>

            <div class="space-y-3">
                @foreach (config('esports.gamer_tags') as $service => $label)
                    <flux:input wire:model="gamerTags.{{ $service }}" :label="$label" wire:key="tag-{{ $service }}" />
                @endforeach
            </div>
        </flux:fieldset>

        <flux:select wire:model="timezone" :label="__('Time zone')">
            <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
            @foreach (\DateTimeZone::listIdentifiers() as $zone)
                <flux:select.option value="{{ $zone }}">{{ $zone }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="locale" :label="__('Language')">
            <flux:select.option value="en">English</flux:select.option>
            <flux:select.option value="de">Deutsch</flux:select.option>
        </flux:select>

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:text x-data="{ shown: false }" x-show="shown" x-cloak
                x-on:gaming-profile-saved.window="shown = true; setTimeout(() => shown = false, 2000)">
                {{ __('Saved.') }}
            </flux:text>
        </div>
    </form>

    <flux:separator />

    <div class="space-y-4">
        <flux:heading level="2">{{ __('Delete account') }}</flux:heading>
        <flux:text>
            {{ __('This deletes your gaming profile, avatar and settings on this site and logs you out.') }}
            {{ __('Results you confirmed stay public on Nostr, because you signed them with your key. Nobody can delete them.') }}
        </flux:text>

        <form wire:submit="deleteAccount" class="space-y-4">
            <flux:checkbox wire:model="confirmDeletion" :label="__('I understand and want to delete my account.')" />
            <flux:button type="submit" variant="danger">{{ __('Delete account') }}</flux:button>
        </form>
    </div>
</section>
