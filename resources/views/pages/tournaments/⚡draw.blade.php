<?php

use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\MemeNames;
use App\Support\Tournaments\TournamentDraws;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * The draw (TournamentDraw.dc.html, NIP "Tournament Draw", `sha256-v1`): the
 * frozen solo pool, the Bitcoin block the draw committed to, and the mix
 * teams its hash makes, recomputed here from the published inputs, so the
 * page shows what anyone can re-check with a block explorer.
 */
new #[Layout('layouts::app', ['section' => 'tournaments'])] class extends Component {
    public Tournament $tournament;

    public function mount(Tournament $tournament): void
    {
        abort_unless($tournament->isVisibleTo(auth()->user()), 404);

        $this->tournament = $tournament;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('The draw').': '.$this->tournament->name);
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function pool(): \Illuminate\Support\Collection
    {
        $ids = TournamentSignup::query()->where('tournament_id', $this->tournament->id)->active()->whereNull('lineup_id')
            ->orderBy('id')->pluck('members')->flatten()->all();

        return User::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @return array{teams: list<array{name: string, members: list<int>}>, reserves: list<int>}|null
     */
    #[Computed]
    public function drawn(): ?array
    {
        return $this->tournament->draw_hash === null ? null : app(TournamentDraws::class)->mixTeams($this->tournament, $this->tournament->draw_hash);
    }
}; ?>

@php
    $tournament = $this->tournament;
    $drawn = $this->drawn;
    $pool = $this->pool;
    $name = fn (int $id): string => $pool->get($id)?->displayName() ?? '?';
    $rows = array_values(array_filter([
        [__('Block'), $tournament->draw_height === null ? __('chosen when registration closes') : '#'.$tournament->draw_height],
        [__('Block hash'), $tournament->draw_hash ?? __('not mined yet')],
        [__('Algorithm'), 'sha256-v1'],
        [__('Team size'), (string) $tournament->teamSize()],
    ]));
    $proof = array_values(array_filter([
        $tournament->drawEvent ? [__('Draw event'), $tournament->drawEvent->event_id] : null,
        $tournament->naddr() ? ['naddr', $tournament->naddr()] : null,
    ]));
@endphp

<div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12" data-test="tournament-draw">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-4">
        <h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ __('The draw') }}</h1>
        <a href="{{ route('tournaments.show', $tournament) }}" class="text-[13px] lg:grow">{{ $tournament->name }}</a>
        @if ($drawn !== null)
            <span class="inline-flex min-h-9 items-center gap-2 self-start rounded-sm bg-win-tint px-3 text-xs text-win shadow-[inset_0_0_0_1px_#1F5A34]"><x-icon name="shield-check" :size="14" />{{ __('Verified draw, anyone can re-check it') }}</span>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
            <dl class="m-0 flex flex-col">
                @foreach ($rows as [$term, $value])
                    <div class="flex justify-between gap-3 border-b border-hairline py-2 text-[13px]"><dt class="shrink-0 text-ink-2">{{ $term }}</dt><dd class="m-0 min-w-0 truncate text-right" title="{{ $value }}">{{ $value }}</dd></div>
                @endforeach
            </dl>
            @if ($proof !== [])
                <x-proof :rows="$proof" />
            @endif
        </section>
        <section class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
            <span class="flex items-baseline justify-between gap-3">
                <h2 class="m-0 text-[15px] font-bold">{{ __('Solo pool') }}</h2>
                <span class="text-xs text-ink-3">{{ trans_choice(':count player|:count players', $pool->count()) }}</span>
            </span>
            <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
                @forelse ($pool as $player)
                    <li class="rounded-sm bg-raised px-2.5 py-1 text-[13px]">{{ $player->displayName() }}</li>
                @empty
                    <li class="text-[13px] text-ink-2">{{ __('Nobody entered solo.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>

    <section aria-labelledby="teams-h" class="flex flex-col gap-3">
        <h2 id="teams-h" class="m-0 font-display text-xl font-bold">{{ __('Mix teams drawn') }}</h2>
        <p class="m-0 text-xs text-ink-2">{{ __('Mix teams have no Elo; they are seeded after the clan lineups. Their results still count for the tournament.') }}</p>
        @if ($drawn === null)
            <p class="m-0 rounded-lg bg-card px-4 py-5 text-[13px] text-ink-2">{{ __('The teams are drawn once the block is mined.') }}</p>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-test="mix-teams">
                @foreach ($drawn['teams'] as $team)
                    <div class="flex flex-col gap-2 rounded-lg bg-card px-4 py-4 shadow-[inset_0_0_0_1px_#5A3A12]">
                        <span class="font-bold text-btc">{{ $team['name'] }}</span>
                        @foreach ($team['members'] as $member)
                            <span class="text-[13px]">{{ $name($member) }}</span>
                        @endforeach
                    </div>
                @endforeach
                @if ($drawn['reserves'] !== [])
                    <div class="flex flex-col gap-2 rounded-lg border border-dashed border-edge px-4 py-4">
                        <span class="font-bold">{{ __('Reserve') }}</span>
                        @foreach ($drawn['reserves'] as $member)
                            <span class="text-[13px]">{{ $name($member) }}</span>
                        @endforeach
                        <span class="text-xs text-ink-3">{{ __('Steps in if a mix team player drops out.') }}</span>
                    </div>
                @endif
            </div>
        @endif
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
            <h2 class="m-0 text-[15px] font-bold">{{ __('How the draw works') }}</h2>
            <ol class="m-0 flex flex-col gap-2 pl-5 text-[13px] leading-normal text-ink-2">
                <li>{{ __('When registration closes, the solo pool is frozen and published with the number of the next Bitcoin block.') }}</li>
                <li>{{ __('Once that block is mined, every player gets SHA-256 of the block hash and their public key.') }}</li>
                <li>{{ __('Sorted by that number, each run of :size players is one team; the rest wait as reserves.', ['size' => $tournament->teamSize()]) }}</li>
                <li>{{ __('Team 1 gets the first meme name, team 2 the second, and so on.') }}</li>
            </ol>
        </section>
        <section class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
            <h2 class="m-0 text-[15px] font-bold">{{ __('Meme names') }}</h2>
            <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
                @foreach (MemeNames::for((string) $tournament->slug, max(1, intdiv($pool->count(), max(1, $tournament->teamSize())))) as $meme)
                    <li class="rounded-sm border border-line px-2.5 py-1 text-[13px]">{{ $meme }}</li>
                @endforeach
            </ul>
        </section>
    </div>
</div>
