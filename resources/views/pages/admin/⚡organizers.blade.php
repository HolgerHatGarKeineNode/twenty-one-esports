<?php

use App\Models\TournamentOrganizer;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Organizers (P8a, own page since P17): the players an admin unlocked to
 * create tournaments and manage their own. Admins only; the tournaments
 * list links here.
 */
new #[Title('Organizers')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    /** The hex pubkey picked in <x-player-picker allow-npub>; null = nothing picked. */
    public ?string $organizerKey = null;

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * @return Collection<int, TournamentOrganizer>
     */
    #[Computed]
    public function organizers(): Collection
    {
        return TournamentOrganizer::query()->latest('id')->get();
    }

    /**
     * The accounts behind the organizer keys, by hex pubkey.
     *
     * @return array<string, User>
     */
    #[Computed]
    public function users(): array
    {
        return User::query()->whereIn('pubkey', $this->organizers->pluck('pubkey'))->get()->keyBy('pubkey')->all();
    }

    /**
     * Players who are organizers already: the picker does not suggest them.
     *
     * @return list<int>
     */
    #[Computed]
    public function organizerUserIds(): array
    {
        return array_values(array_map(fn (User $user): int => $user->id, $this->users));
    }

    public function addOrganizer(): void
    {
        Gate::authorize('admin');

        $this->validate(['organizerKey' => ['required', 'string', 'max:100']], ['organizerKey.required' => __('Pick a player from the suggestions or paste a full npub.')]);

        $pubkey = NostrKeys::toHex((string) $this->organizerKey);

        if ($pubkey === null) {
            $this->addError('organizerKey', __('Enter an npub or a 64-character hex public key.'));

            return;
        }

        if (TournamentOrganizer::query()->where('pubkey', $pubkey)->exists()) {
            $this->addError('organizerKey', __('This key is already an organizer.'));

            return;
        }

        TournamentOrganizer::query()->create(['pubkey' => $pubkey, 'added_by_pubkey' => auth()->user()?->pubkey]);

        $this->reset('organizerKey');
        unset($this->organizers, $this->users, $this->organizerUserIds);
    }

    public function removeOrganizer(int $organizerId): void
    {
        Gate::authorize('admin');

        TournamentOrganizer::query()->whereKey($organizerId)->delete();

        unset($this->organizers, $this->users, $this->organizerUserIds);
    }
}; ?>

<x-admin.page active="organizers" :title="__('Organizers')" :lead="__('Organizers may create tournaments and manage their own. They see nothing else of the admin area.')" data-test="admin-organizers">
    <x-slot:actions>
        <x-button variant="quiet" :href="route('admin.tournaments')">{{ __('All tournaments') }}</x-button>
    </x-slot:actions>

    <x-admin.panel :title="__('Organizers')" :meta="trans_choice(':count organizer|:count organizers', $this->organizers->count())" data-test="organizers">
        <form wire:submit="addOrganizer" class="flex flex-col gap-1.5">
            <span class="flex flex-wrap items-end gap-2">
                <x-player-picker id="organizer-key" wire:model="organizerKey" allow-npub :label="__('Player or npub')" :exclude="$this->organizerUserIds" class="min-w-0 grow basis-[16rem] lg:max-w-[560px]" />
                <x-button type="submit" variant="quiet">{{ __('Add organizer') }}</x-button>
            </span>
            @error('organizerKey')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
        </form>

        @if ($this->organizers->isEmpty())
            <x-admin.empty :text="__('No organizers yet. Pick a player above to let them create tournaments.')" />
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->organizers as $organizer)
                    <x-admin.key-row :npub="$organizer->npub()" :user="$this->users[$organizer->pubkey] ?? null" wire:key="organizer-{{ $organizer->id }}">
                        <x-button variant="secondary" wire:click="removeOrganizer({{ $organizer->id }})" wire:confirm="{{ __('Remove this organizer?') }}">{{ __('Remove') }}</x-button>
                    </x-admin.key-row>
                @endforeach
            </ul>
        @endif
    </x-admin.panel>
</x-admin.page>
