<?php

use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Support\Nostr\NostrKeys;
use App\Support\Tournaments\Estimator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * AdminTournaments (AdminTournaments.dc.html, P8a): the tournaments, newest
 * first — all of them for an admin, their own for an organizer — and, for
 * admins, the organizers who may create tournaments. Editing, sign-up, the
 * prize pool and sponsors of the artboard follow in P8b/P9.
 */
new #[Title('Tournaments')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    public string $organizerKey = '';

    public function mount(): void
    {
        Gate::authorize('create-tournaments');
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return Gate::allows('admin');
    }

    /**
     * @return Collection<int, Tournament>
     */
    #[Computed]
    public function tournaments(): Collection
    {
        return Tournament::query()
            ->when(! $this->isAdmin, fn ($query) => $query->where('created_by_id', auth()->id()))
            ->with('creator')
            ->latest('id')
            ->limit(200)
            ->get();
    }

    /**
     * @return Collection<int, TournamentOrganizer>
     */
    #[Computed]
    public function organizers(): Collection
    {
        return TournamentOrganizer::query()->latest('id')->get();
    }

    public function addOrganizer(): void
    {
        Gate::authorize('admin');

        $this->validate(['organizerKey' => ['required', 'string', 'max:100']]);

        $pubkey = NostrKeys::toHex($this->organizerKey);

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
        unset($this->organizers);
    }

    public function removeOrganizer(int $organizerId): void
    {
        Gate::authorize('admin');

        TournamentOrganizer::query()->whereKey($organizerId)->delete();

        unset($this->organizers);
    }
}; ?>

<div class="flex grow flex-col" data-test="admin-tournaments">
    @if ($this->isAdmin)
        <x-admin.nav active="tournaments" />
    @endif

    <div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-4">
            <div class="flex flex-col gap-2 lg:grow">
                <h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ __('Tournaments') }}</h1>
                <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('Tournament matches are normal rated challenges and count for Elo. They never mine season blocks; every tournament has its own prize pool.') }}</p>
            </div>
            <x-button :href="route('admin.tournaments.create')" class="self-start lg:self-auto" data-test="new-tournament">+ {{ __('New tournament') }}</x-button>
        </div>

        @if (session('status'))
            <p class="m-0 rounded-md bg-win-tint px-4 py-3 text-[13px] text-win shadow-[inset_0_0_0_1px_#1F5A34]" role="status" data-test="tournaments-notice">{{ session('status') }}</p>
        @endif

        <section aria-labelledby="list-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
            <span class="flex items-baseline gap-3">
                <h2 id="list-h" class="m-0 text-[15px] font-bold">{{ $this->isAdmin ? __('All tournaments') : __('Your tournaments') }}</h2>
                <span class="text-xs text-ink-3">{{ __('newest first') }}</span>
            </span>

            @if ($this->tournaments->isEmpty())
                <p class="m-0 text-[13px] text-ink-2">{{ __('No tournaments yet.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] border-collapse text-left text-[13px]">
                        <thead class="text-xs text-ink-3">
                            <tr>
                                <th class="py-2 pr-3 font-normal">{{ __('Status') }}</th>
                                <th class="py-2 pr-3 font-normal">{{ __('Tournament') }}</th>
                                <th class="py-2 pr-3 font-normal">{{ __('Game, mode') }}</th>
                                <th class="py-2 pr-3 font-normal">{{ __('Starts') }}</th>
                                <th class="py-2 pr-3 font-normal">{{ __('Format') }}</th>
                                <th class="py-2 pr-3 text-right font-normal">{{ __('Entries') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->tournaments as $tournament)
                                <tr class="border-t border-hairline hover:bg-row-hover" wire:key="t-{{ $tournament->id }}" data-test="tournament-row">
                                    <td class="py-2.5 pr-3"><span class="inline-flex h-6 items-center rounded-xs bg-btc-chip px-2 text-xs font-bold text-btc-hi">{{ $tournament->status->label() }}</span></td>
                                    <td class="py-2.5 pr-3">
                                        <a href="{{ route('tournaments.show', $tournament) }}" class="font-bold">{{ $tournament->name }}</a>
                                        <span class="block text-xs text-ink-3">{{ __('by :name', ['name' => $tournament->creator?->displayName() ?? __('a former player')]) }}</span>
                                    </td>
                                    <td class="py-2.5 pr-3">{{ $tournament->game === 'chess' ? __('Chess') : __('Rocket League') }} {{ $tournament->mode === 'correspondence' ? __('Daily') : ($tournament->mode === 'blitz' ? __('Blitz 5+3') : $tournament->mode) }}</td>
                                    <td class="py-2.5 pr-3 whitespace-nowrap">{{ $tournament->starts_at->format('Y-m-d H:i') }}</td>
                                    <td class="py-2.5 pr-3">{{ $tournament->format->label() }}
                                        <span class="block text-xs text-ink-3">{{ __('about :duration', ['duration' => Estimator::format($tournament->plannedDuration(), $tournament->profile())]) }}</span>
                                    </td>
                                    <td class="py-2.5 pr-3 text-right">{{ $tournament->capacity }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if ($this->isAdmin)
            <section aria-labelledby="org-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="organizers">
                <div class="flex flex-col gap-1">
                    <h2 id="org-h" class="m-0 text-[15px] font-bold">{{ __('Organizers') }}</h2>
                    <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Organizers may create tournaments and manage their own. They see nothing else of the admin area.') }}</p>
                </div>

                <form wire:submit="addOrganizer" class="flex flex-col gap-1.5">
                    <label for="organizer-key" class="text-xs text-ink-2">{{ __('npub or hex public key') }}</label>
                    <span class="flex gap-2">
                        <input id="organizer-key" wire:model="organizerKey" class="h-11 min-w-0 grow rounded-md border border-edge bg-ground px-3 font-mono text-[13px] text-ink lg:max-w-[560px]">
                        <x-button type="submit" variant="quiet">{{ __('Add organizer') }}</x-button>
                    </span>
                    @error('organizerKey')<span class="text-xs text-loss" role="alert">{{ $message }}</span>@enderror
                </form>

                <ul class="m-0 flex list-none flex-col gap-2 p-0">
                    @forelse ($this->organizers as $organizer)
                        <li class="flex items-center justify-between gap-4" wire:key="organizer-{{ $organizer->id }}">
                            <span class="min-w-0 font-mono text-xs break-all">{{ $organizer->npub() }}</span>
                            <x-button variant="secondary" wire:click="removeOrganizer({{ $organizer->id }})" class="shrink-0">{{ __('Remove') }}</x-button>
                        </li>
                    @empty
                        <li class="text-[13px] text-ink-2">{{ __('No organizers yet.') }}</li>
                    @endforelse
                </ul>
            </section>
        @endif
    </div>
</div>
