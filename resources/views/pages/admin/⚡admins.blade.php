<?php

use App\Models\Admin;
use App\Models\User;
use App\Support\Board;
use App\Support\Nostr\NostrKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admins')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    /** The hex pubkey picked in <x-player-picker allow-npub>; null = nothing picked. */
    public ?string $key = null;

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * Board members from config/esports.php, as npubs. Read-only here.
     *
     * @return list<string>
     */
    #[Computed]
    public function board(): array
    {
        return array_map(NostrKeys::hexToNpub(...), Board::pubkeys());
    }

    /**
     * @return Collection<int, Admin>
     */
    #[Computed]
    public function admins(): Collection
    {
        return Admin::query()->latest()->get();
    }

    /**
     * Players who are admins already: the picker does not suggest them.
     *
     * @return list<int>
     */
    #[Computed]
    public function adminUserIds(): array
    {
        return array_values(User::query()->whereIn('pubkey', [...Board::pubkeys(), ...$this->admins->pluck('pubkey')->all()])->pluck('id')->all());
    }

    /**
     * The accounts behind the listed keys, by hex pubkey: a name and a picture where there is one.
     *
     * @return array<string, User>
     */
    #[Computed]
    public function users(): array
    {
        return User::query()->whereIn('pubkey', [...Board::pubkeys(), ...$this->admins->pluck('pubkey')->all()])->get()->keyBy('pubkey')->all();
    }

    public function add(): void
    {
        Gate::authorize('admin');

        $this->validate(['key' => ['required', 'string', 'max:100']], ['key.required' => __('Pick a player from the suggestions or paste a full npub.')]);

        $pubkey = NostrKeys::toHex((string) $this->key);

        if ($pubkey === null) {
            $this->addError('key', __('Enter an npub or a 64-character hex public key.'));

            return;
        }

        if (Board::contains($pubkey) || Admin::query()->where('pubkey', $pubkey)->exists()) {
            $this->addError('key', __('This key is already an admin.'));

            return;
        }

        Admin::query()->create([
            'pubkey' => $pubkey,
            'added_by_pubkey' => auth()->user()?->pubkey,
        ]);

        $this->reset('key');
        unset($this->admins, $this->adminUserIds, $this->users);
    }

    public function remove(int $adminId): void
    {
        Gate::authorize('admin');

        Admin::query()->whereKey($adminId)->delete();

        unset($this->admins, $this->adminUserIds, $this->users);
    }
}; ?>

@php($me = auth()->user()?->pubkey)

<x-admin.page active="admins" :title="__('Admins')" :lead="__('Admins decide disputes, send payouts and release seasons. Board members are always admins.')" data-test="admin-admins">
    <x-admin.panel :title="__('Board')" :meta="__('from the configuration, not editable here')" flush>
        <ul class="m-0 flex list-none flex-col p-0">
            @foreach (\App\Support\Board::pubkeys() as $pubkey)
                @php($npub = \App\Support\Nostr\NostrKeys::hexToNpub($pubkey))
                <x-admin.key-row :npub="$npub" :user="$this->users[$pubkey] ?? null" :note="$pubkey === $me ? __('that’s you') : null" wire:key="board-{{ $pubkey }}" />
            @endforeach
        </ul>
    </x-admin.panel>

    <x-admin.panel :title="__('Further admins')" :meta="__('added here, by any admin')">
        <form wire:submit="add" class="flex flex-col gap-1.5">
            <span class="flex flex-wrap items-end gap-2">
                <x-player-picker id="admin-key" wire:model="key" allow-npub :label="__('Player or npub')" :exclude="$this->adminUserIds" class="min-w-0 grow basis-[16rem] lg:max-w-[560px]" />
                <x-button type="submit" variant="quiet">{{ __('Add admin') }}</x-button>
            </span>
            @error('key')<span class="text-xs text-loss" role="alert" data-test="admin-key-error">{{ $message }}</span>@enderror
        </form>

        @if ($this->admins->isEmpty())
            <x-admin.empty :text="__('No further admins yet. Pick a player above to add one.')" />
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->admins as $admin)
                    <x-admin.key-row :npub="$admin->npub()" :user="$this->users[$admin->pubkey] ?? null" :note="$admin->pubkey === $me ? __('that’s you') : null" wire:key="admin-{{ $admin->id }}">
                        <x-button variant="secondary" wire:click="remove({{ $admin->id }})" wire:confirm="{{ __('Remove this admin?') }}">{{ __('Remove') }}</x-button>
                    </x-admin.key-row>
                @endforeach
            </ul>
        @endif
    </x-admin.panel>
</x-admin.page>
