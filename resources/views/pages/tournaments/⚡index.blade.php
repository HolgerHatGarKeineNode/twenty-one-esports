<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\SeasonChain\Seasons;
use App\Support\Tournaments\FormatCopy;
use App\Support\Tournaments\TournamentSignups;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Tournaments (Tournaments.dc.html; before Block 0 the head of
 * TournamentsPrelaunch.dc.html): the next tournament open for sign-up, every
 * published tournament, and the formats in one line each. Drafts never show
 * here. The artboard's pool amounts and payout box follow with P9; its line
 * "rated tournament games mine blocks" is outdated (user, 2026-09-26:
 * tournaments never mine).
 */
new #[Title('Tournaments')] #[Layout('layouts::app', ['section' => 'tournaments'])] class extends Component {
    /**
     * @return Collection<int, Tournament>
     */
    #[Computed]
    public function tournaments(): Collection
    {
        return Tournament::query()->where('status', '!=', TournamentStatus::Draft)
            ->orderByRaw("case status when 'signup' then 0 when 'drawing' then 1 when 'running' then 2 else 3 end")
            ->orderByDesc('starts_at')->limit(100)->get();
    }

    #[Computed]
    public function next(): ?Tournament
    {
        return Tournament::query()->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())
            ->orderBy('signup_closes_at')->first();
    }
}; ?>

@php
    $live = Seasons::isLive();
    $zone = (string) (auth()->user()->timezone ?? config('esports.preseason.display_timezone'));
    $counts = [
        [__('Open for sign-up'), $this->tournaments->where('status', TournamentStatus::Signup)->count()],
        [__('Running'), $this->tournaments->whereIn('status', [TournamentStatus::Drawing, TournamentStatus::Running])->count()],
        [__('Finished'), $this->tournaments->where('status', TournamentStatus::Finished)->count()],
    ];
    $next = $this->next;
    $places = $next === null ? null : app(TournamentSignups::class)->places($next);
    $modeLabel = fn (Tournament $t): string => ($t->game === 'chess' ? __('Chess') : 'Rocket League').' '.($t->mode === 'correspondence' ? __('Daily') : ($t->mode === 'blitz' ? __('Blitz 5+3') : $t->mode));
@endphp

<div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12" data-test="tournaments-index">
    <div class="flex flex-col gap-2 lg:flex-row lg:items-baseline lg:gap-4">
        <h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ __('Tournaments') }}</h1>
        <p class="m-0 text-[13px] leading-normal text-ink-2">
            {{ $live
                ? __('Tournament matches are normal challenges and count for Elo. They never mine season blocks; mix teams play without Elo.')
                : __('Sign-ups are open. Until Block 0 every tournament match is casual and moves the casual Elo.') }}
        </p>
        @can('create-tournaments')
            <div class="flex flex-wrap gap-2 lg:ml-auto lg:shrink-0 lg:self-center">
                <x-button :href="route('admin.tournaments.create')" class="whitespace-nowrap" data-test="index-new-tournament">+ {{ __('New tournament') }}</x-button>
                <x-button variant="secondary" :href="route('admin.tournaments')" class="whitespace-nowrap" data-test="index-manage-tournaments">{{ __('Manage tournaments') }}</x-button>
            </div>
        @endcan
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ($counts as [$label, $count])
            <div class="flex flex-col gap-1 rounded-lg bg-card px-5 py-4">
                <span class="text-xs text-ink-2">{{ $label }}</span>
                <span class="font-display text-[28px] font-bold">{{ $count }}</span>
            </div>
        @endforeach
    </div>

    @if ($next !== null)
        <section aria-labelledby="next-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:flex-row lg:gap-8 lg:px-6" data-test="next-tournament">
            <div class="flex min-w-0 grow flex-col gap-2">
                <span class="text-xs text-ink-2">{{ __('Next tournament') }}</span>
                <h2 id="next-h" class="m-0 font-display text-[26px] font-bold break-words"><a href="{{ route('tournaments.show', $next) }}" class="text-ink hover:text-ink">{{ $next->name }}</a></h2>
                <p class="m-0 text-[13px] text-ink-2">{{ $modeLabel($next) }} · {{ $next->format->label() }} · {{ $next->starts_at->copy()->timezone($zone)->format('D Y-m-d H:i') }}</p>
                <div class="flex flex-col gap-1.5 pt-2">
                    <span class="text-[13px]">{{ __(':taken of :places places taken', ['taken' => $places['taken'], 'places' => $places['places']]) }}</span>
                    <span class="h-2 w-full overflow-hidden rounded-full bg-raised" aria-hidden="true"><span class="block h-full bg-btc" style="width: {{ $places['places'] > 0 ? min(100, (int) round(100 * $places['taken'] / $places['places'])) : 0 }}%"></span></span>
                </div>
            </div>
            <div class="flex shrink-0 flex-col gap-3 lg:w-[320px]">
                <span class="text-xs text-ink-2">{{ __('Registration closes') }}</span>
                <span class="font-display text-xl font-bold">{{ $next->signup_closes_at->copy()->timezone($zone)->format('D H:i') }}</span>
                <span class="text-xs text-ink-3">{{ $next->signup_closes_at->diffForHumans() }}</span>
                <x-button :href="auth()->check() ? route('tournaments.signup', $next) : route('login')" data-test="register">{{ __('Register') }}</x-button>
            </div>
        </section>
    @endif

    <section aria-labelledby="all-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
        <h2 id="all-h" class="m-0 text-[15px] font-bold">{{ __('All tournaments') }}</h2>
        @if ($this->tournaments->isEmpty())
            <p class="m-0 text-[13px] text-ink-2">{{ __('No tournament is published yet.') }}</p>
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->tournaments as $tournament)
                    <li class="flex flex-col gap-1 border-t border-hairline py-2.5 text-[13px] sm:flex-row sm:items-center sm:gap-4" wire:key="t-{{ $tournament->id }}" data-test="tournament-item">
                        <a href="{{ route('tournaments.show', $tournament) }}" class="min-w-0 font-bold sm:w-[34%] sm:truncate">{{ $tournament->name }}</a>
                        <span class="text-ink-2 sm:w-[18%]">{{ $tournament->starts_at->copy()->timezone($zone)->format('Y-m-d') }}</span>
                        <span class="text-ink-2 sm:w-[20%]">{{ $tournament->format->label() }}</span>
                        <span class="text-ink-2 sm:grow">{{ $modeLabel($tournament) }}</span>
                        <span class="inline-flex h-6 items-center self-start rounded-xs bg-btc-chip px-2 text-xs font-bold text-btc-hi sm:self-auto">{{ $tournament->status->label() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="formats-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
        <h2 id="formats-h" class="m-0 text-[15px] font-bold">{{ __('Formats') }}</h2>
        <dl class="m-0 grid gap-x-6 lg:grid-cols-2">
            @foreach ([TournamentFormat::SingleElimination, TournamentFormat::DoubleElimination, TournamentFormat::Swiss, TournamentFormat::RoundRobin, TournamentFormat::TwoStage] as $format)
                <div class="flex flex-col gap-0.5 border-t border-hairline py-2.5">
                    <dt class="text-[13px] font-bold">{{ $format->label() }}</dt>
                    <dd class="m-0 text-xs text-ink-2">{{ __(FormatCopy::for($format)['short']) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
</div>
