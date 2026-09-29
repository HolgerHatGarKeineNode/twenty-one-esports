<?php

use App\Models\User;
use App\Support\Nostr\Nip05Names;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Nostr addresses (P47): the NIP-05 names players claimed on the league's
 * domain, newest first, with a search, and "Revoke" for a name that
 * impersonates someone or breaks the rules. A revoked name answers no more,
 * nobody can claim it while the account exists, and the player picks another
 * one after the change limit (Nip05Names::revoke()). Admins only.
 */
new #[Title('Nostr addresses')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    public const LIMIT = 50;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $flash = '';

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function claimed(): Collection
    {
        $term = Nip05Names::normalize($this->search);

        return User::query()->whereNotNull('nip05_name')
            ->when($term !== '', fn ($query) => $query->where('nip05_name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->latest('nip05_changed_at')->limit(self::LIMIT)->get();
    }

    #[Computed]
    public function total(): int
    {
        return User::query()->whereNotNull('nip05_name')->count();
    }

    public function revoke(int $userId, Nip05Names $names): void
    {
        Gate::authorize('admin');

        $user = User::query()->find($userId);

        if ($user?->nip05_name === null) {
            return;
        }

        $name = $user->nip05_name;
        $names->revoke($user);
        $this->flash = __(':address was revoked.', ['address' => $name.'@'.Nip05Names::domain()]);

        unset($this->claimed, $this->total);
    }
}; ?>

<x-admin.page active="nip05" :title="__('Nostr addresses')" :lead="__('Names players claimed on :domain. Revoke one that impersonates someone or breaks the rules: it stops answering at once and nobody can claim it again.', ['domain' => Nip05Names::domain()])" data-test="admin-nip05">
    <x-admin.panel :title="__('Claimed names')" :meta="trans_choice(':count name|:count names', $this->total)" data-test="nip05-names">
        <label class="flex flex-col gap-1.5 lg:max-w-[420px]">
            <span class="text-[13px]">{{ __('Search a name') }}</span>
            <input type="search" wire:model.live.debounce.300ms="search" class="h-11 w-full rounded-md border border-edge bg-ground px-3 font-mono text-[13px] text-ink" data-test="nip05-search">
        </label>

        @if ($flash !== '')
            <p class="m-0 text-[13px] text-win" role="status" data-test="nip05-flash">{{ $flash }}</p>
        @endif

        @if ($this->claimed->isEmpty())
            <x-admin.empty :text="$search === '' ? __('No player claimed a name yet.') : __('No name matches.')" />
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->claimed as $player)
                    <x-admin.key-row :npub="$player->npub" :user="$player" :note="$player->nip05_name.'@'.Nip05Names::domain()" wire:key="nip05-{{ $player->id }}" data-test="nip05-row">
                        <x-button variant="secondary" wire:click="revoke({{ $player->id }})" wire:confirm="{{ __('Revoke :address?', ['address' => $player->nip05_name.'@'.Nip05Names::domain()]) }}" data-test="nip05-revoke">{{ __('Revoke') }}</x-button>
                    </x-admin.key-row>
                @endforeach
            </ul>
            @if ($this->total > $this->claimed->count())
                <p class="m-0 text-xs text-ink-2">{{ __('Showing :shown of :total. Search to find the others.', ['shown' => $this->claimed->count(), 'total' => $this->total]) }}</p>
            @endif
        @endif
    </x-admin.panel>
</x-admin.page>
