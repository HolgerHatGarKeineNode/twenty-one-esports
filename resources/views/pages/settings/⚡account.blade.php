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
 * The account tab (P51, split off the gaming profile): avatar, platform, time
 * zone, language, and deleting the account. The name always comes from the
 * user's Nostr profile; an avatar uploaded here replaces the Nostr picture on
 * this site only (User::avatarUrl()).
 */
new #[Title('Account')] class extends Component {
    use WithFileUploads;

    public ?TemporaryUploadedFile $avatar = null;

    public string $platform = '';

    public string $timezone = '';

    public string $locale = '';

    public bool $confirmDeletion = false;

    public function mount(): void
    {
        $user = $this->user();

        $this->platform = $user->platform->value ?? '';
        $this->timezone = $user->timezone ?? '';
        $this->locale = $user->locale ?? app()->getLocale();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'platform' => ['nullable', Rule::enum(Platform::class)],
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
            'timezone' => $validated['timezone'] ?: null,
            'locale' => $validated['locale'],
        ])->save();

        session()->put('locale', $validated['locale']);

        $this->dispatch('account-saved');
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

@php
    $user = auth()->user();
    $field = 'h-11 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
@endphp

<div class="flex grow flex-col gap-5 px-4 pb-8 lg:px-12 lg:pb-10" data-test="account-settings">
    <x-settings.header current="account" />

    <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,560px)_minmax(0,400px)]">
        <form wire:submit="save" aria-labelledby="ac-h" class="flex flex-col gap-5 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="account-form">
            <h2 id="ac-h" class="m-0 text-[15px] font-bold">{{ __('On this site') }}</h2>

            <div class="flex flex-col gap-2 border-b border-hairline pb-5">
                <span class="text-sm">{{ __('Avatar') }}</span>
                <div class="flex items-center gap-4">
                    <x-avatar :user="$user" :size="56" class="size-14 shrink-0 rounded-full" />
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <input id="account-avatar" type="file" wire:model="avatar" accept="image/png,image/jpeg,image/webp" aria-label="{{ __('Upload an avatar') }}" data-test="account-avatar"
                               class="max-w-full text-[13px] text-ink-2 file:mr-3 file:h-11 file:cursor-pointer file:rounded-md file:border file:border-solid file:border-line file:bg-well file:px-3 file:text-[13px] file:text-ink">
                        @if ($user->avatar_path)
                            <button type="button" wire:click="removeAvatar" class="self-start text-[13px] text-ink-2 underline underline-offset-4 hover:text-ink min-h-11" data-test="account-remove-avatar">{{ __('Remove avatar') }}</button>
                        @endif
                    </div>
                </div>
                @error('avatar')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                <span class="text-xs leading-normal text-ink-2">{{ __('Your name comes from your Nostr profile. An avatar uploaded here replaces your Nostr picture on this site, and other players see it.') }}</span>
            </div>

            <label class="flex flex-col gap-1.5">
                <span class="text-sm">{{ __('Platform') }}</span>
                <select wire:model="platform" class="{{ $field }}" data-test="account-platform">
                    <option value="">{{ __('Not set') }}</option>
                    @foreach (Platform::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
                @error('platform')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                <span class="text-xs leading-normal text-ink-2">{{ __('Preselected when you look for a 1v1, until you pick one there.') }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="text-sm">{{ __('Time zone') }}</span>
                <select wire:model="timezone" class="{{ $field }}" data-test="account-timezone">
                    <option value="">{{ __('Not set') }}</option>
                    @foreach (\DateTimeZone::listIdentifiers() as $zone)
                        <option value="{{ $zone }}">{{ $zone }}</option>
                    @endforeach
                </select>
                @error('timezone')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                <span class="text-xs leading-normal text-ink-2">{{ __('Match times and reminders use it.') }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="text-sm">{{ __('Language') }}</span>
                <select wire:model="locale" class="{{ $field }}" data-test="account-locale">
                    <option value="en">English</option>
                    <option value="de">Deutsch</option>
                </select>
            </label>

            <div class="flex flex-wrap items-center gap-4">
                <x-button type="submit" data-test="account-save">{{ __('Save') }}</x-button>
                <span role="status" class="flex items-center gap-1.5 text-[13px] text-win" x-data="{ shown: false }" x-show="shown" x-cloak
                      x-on:account-saved.window="shown = true; setTimeout(() => shown = false, 2000)" data-test="account-saved"><x-icon name="check" :size="16" />{{ __('Saved.') }}</span>
            </div>
        </form>

        <section aria-labelledby="del-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="account-delete">
            <h2 id="del-h" class="m-0 text-[15px] font-bold">{{ __('Delete account') }}</h2>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('This deletes your gaming profile, avatar and settings on this site and logs you out.') }}</p>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Results you confirmed stay public on Nostr, because you signed them with your key. Nobody can delete them.') }}</p>

            <form wire:submit="deleteAccount" class="flex flex-col gap-3 border-t border-hairline pt-3">
                <label class="flex min-h-11 cursor-pointer items-center gap-3 text-[13px]">
                    <input type="checkbox" wire:model="confirmDeletion" class="size-5 shrink-0 cursor-pointer accent-btc" data-test="account-confirm-delete">
                    {{ __('I understand and want to delete my account.') }}
                </label>
                @error('confirmDeletion')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                <button type="submit" class="inline-flex h-11 cursor-pointer items-center justify-center self-start rounded-md border border-loss px-[18px] text-[13px] text-loss hover:bg-loss-tint" data-test="account-delete-button">{{ __('Delete account') }}</button>
            </form>
        </section>
    </div>
</div>
