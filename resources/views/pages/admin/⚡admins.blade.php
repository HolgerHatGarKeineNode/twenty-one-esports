<?php

use App\Models\Admin;
use App\Support\Board;
use App\Support\Nostr\NostrKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admins')] class extends Component {
    public string $key = '';

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

    public function add(): void
    {
        Gate::authorize('admin');

        $this->validate(['key' => ['required', 'string', 'max:100']]);

        $pubkey = NostrKeys::toHex($this->key);

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
        unset($this->admins);
    }

    public function remove(int $adminId): void
    {
        Gate::authorize('admin');

        Admin::query()->whereKey($adminId)->delete();

        unset($this->admins);
    }
}; ?>

<section class="w-full max-w-2xl space-y-8">
    <flux:heading size="xl" level="1">{{ __('Admins') }}</flux:heading>

    <div class="space-y-2">
        <flux:heading level="2">{{ __('Board') }}</flux:heading>
        <flux:text>{{ __('Board members are always admins. The list is maintained in the configuration.') }}</flux:text>

        <ul class="space-y-1">
            @foreach ($this->board as $npub)
                <li class="font-mono text-sm break-all" wire:key="board-{{ $npub }}">{{ $npub }}</li>
            @endforeach
        </ul>
    </div>

    <div class="space-y-4">
        <flux:heading level="2">{{ __('Further admins') }}</flux:heading>

        <form wire:submit="add" class="flex items-end gap-2">
            <flux:input wire:model="key" :label="__('npub or hex public key')" class="flex-1" />
            <flux:button type="submit" variant="primary">{{ __('Add admin') }}</flux:button>
        </form>

        <ul class="space-y-2">
            @forelse ($this->admins as $admin)
                <li class="flex items-center justify-between gap-4" wire:key="admin-{{ $admin->id }}">
                    <span class="font-mono text-sm break-all">{{ $admin->npub() }}</span>
                    <flux:button size="sm" variant="danger" wire:click="remove({{ $admin->id }})">
                        {{ __('Remove') }}
                    </flux:button>
                </li>
            @empty
                <li><flux:text>{{ __('No further admins yet.') }}</flux:text></li>
            @endforelse
        </ul>
    </div>
</section>
