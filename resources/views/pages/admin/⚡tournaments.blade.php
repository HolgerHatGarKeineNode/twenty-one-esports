<?php

use App\Models\Tournament;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\TournamentScheduler;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * AdminTournaments (AdminTournaments.dc.html, P8a): the tournaments, newest
 * first — all of them for an admin, their own for an organizer. The
 * organizers themselves have their own page since P17. Each row links its
 * management actions under its name (<x-tournaments.manage-actions>:
 * publish for a draft, first and orange, then prize pool, edit, payouts,
 * each behind its own gate).
 */
new #[Title('Tournaments')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    public function mount(): void
    {
        Gate::authorize('create-tournaments');
    }

    /**
     * The tournament clock's heartbeat (P18): a warning when it is stale.
     *
     * @return array{last_run_at: \Carbon\CarbonImmutable|null, stale: bool}
     */
    #[Computed]
    public function scheduler(): array
    {
        return TournamentScheduler::health();
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
}; ?>

<x-admin.page active="tournaments" :title="__('Tournaments')" :lead="__('Tournament matches are normal rated challenges and count for Elo. They never mine season blocks; every tournament has its own prize pool.')" flash-test="tournaments-notice" data-test="admin-tournaments">
    <x-slot:actions>
        @if ($this->isAdmin)
            <x-button variant="quiet" :href="route('admin.organizers')" data-test="to-organizers">{{ __('Organizers') }}</x-button>
        @endif
        <x-button :href="route('admin.tournaments.create')" data-test="new-tournament">{{ __('New tournament') }}</x-button>
    </x-slot:actions>

    @if ($this->scheduler['stale'])
        <x-admin.flash tone="error" data-test="scheduler-stale">
            <b>{{ __('The tournament clock is not running.') }}</b>
            {{ $this->scheduler['last_run_at'] === null
                ? __('It has not run yet: no sign-up closes, no draw runs and no deadline is applied until the scheduler runs `tournaments:tick` every minute.')
                : __('Its last run was :ago: no sign-up closes, no draw runs and no deadline is applied until the scheduler runs `tournaments:tick` every minute again.', ['ago' => $this->scheduler['last_run_at']->diffForHumans()]) }}
        </x-admin.flash>
    @endif

    <x-admin.panel :title="$this->isAdmin ? __('All tournaments') : __('Your tournaments')" :meta="__('newest first')" id="list-h">
        @if ($this->tournaments->isEmpty())
            <x-admin.empty :text="__('No tournaments yet. Create the first one as a draft; players see it once you publish.')">
                <x-button variant="quiet" :href="route('admin.tournaments.create')">{{ __('New tournament') }}</x-button>
            </x-admin.empty>
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
                            <th class="py-2 text-right font-normal">{{ __('Entries') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->tournaments as $tournament)
                            <tr class="border-t border-hairline hover:bg-row-hover" wire:key="t-{{ $tournament->id }}" data-test="tournament-row">
                                <td class="py-2.5 pr-3"><span class="inline-flex h-6 items-center rounded-xs bg-btc-chip px-2 text-xs font-bold text-btc-hi">{{ $tournament->status->label() }}</span></td>
                                <td class="py-2.5 pr-3">
                                    <a href="{{ route('tournaments.show', $tournament) }}" class="font-bold">{{ $tournament->name }}</a>
                                    <span class="block text-xs text-ink-3">{{ __('by :name', ['name' => $tournament->creator?->displayName() ?? __('a former player')]) }}</span>
                                    {{-- Prize pool, Edit and Payouts as buttons in the name column, reachable on a phone without scrolling the table sideways. --}}
                                    <x-tournaments.manage-actions :tournament="$tournament" class="pt-2" />
                                </td>
                                <td class="py-2.5 pr-3">{{ \App\Support\GameNames::full($tournament->game, $tournament->mode) }}</td>
                                <td class="py-2.5 pr-3 whitespace-nowrap"><x-league-time :at="$tournament->starts_at" /></td>
                                <td class="py-2.5 pr-3">{{ $tournament->format->label() }}
                                    <span class="block text-xs text-ink-3">{{ __('about :duration', ['duration' => Estimator::format($tournament->plannedDuration(), $tournament->profile())]) }}</span>
                                </td>
                                <td class="py-2.5 text-right">{{ $tournament->capacity }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.panel>
</x-admin.page>
