<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\Tournament;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatCopy;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * A tournament (P8a, minimal): what it is, when, which system and who enters
 * results. TournamentShow.dc.html with sign-up, bracket, prize pool and the
 * director markers follows in P8b. A draft is only seen by its creator, its
 * directors and admins; everyone else gets the 404 of a tournament that
 * does not exist.
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
        $view->title($this->tournament->name);
    }
}; ?>

@php
    $tournament = $this->tournament;
    $profile = $tournament->profile();
    $options = $tournament->formatOptions();
    $who = $profile->entersTeams() ? trans_choice(':count team|:count teams', $tournament->capacity) : trans_choice(':count player|:count players', $tournament->capacity);
    $facts = [
        [__('Game, mode'), ($tournament->game === 'chess' ? __('Chess') : __('Rocket League')).' '.($tournament->mode === 'correspondence' ? __('Daily') : ($tournament->mode === 'blitz' ? __('Blitz 5+3') : $tournament->mode))],
        [__('Starts'), $tournament->starts_at->format('Y-m-d H:i')],
        [__('Format'), $tournament->format->label().($tournament->format === TournamentFormat::Swiss && $options->swissRounds !== null ? ', '.trans_choice(':count round|:count rounds', $options->swissRounds) : '')],
        [__('Planned for'), $who],
        [__('Planned duration'), __('about :duration', ['duration' => Estimator::format($tournament->plannedDuration(), $profile)])],
        [__('Where'), $tournament->on_site ? __('On site').', '.trans_choice(':count station|:count stations', (int) $tournament->stations) : __('Online')],
        [__('Results'), $tournament->results_mode->label()],
    ];
@endphp

<div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12" data-test="tournament-show">
    <div class="flex flex-col gap-2">
        <span class="inline-flex h-6 items-center self-start rounded-xs bg-btc-chip px-2 text-xs font-bold text-btc-hi">{{ $tournament->status->label() }}</span>
        <h1 class="m-0 font-display text-[28px] font-bold break-words lg:text-[34px]">{{ $tournament->name }}</h1>
        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __(FormatCopy::for($tournament->format)['how']) }}</p>
    </div>

    <section aria-labelledby="facts-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
        <h2 id="facts-h" class="m-0 text-[15px] font-bold">{{ __('About this tournament') }}</h2>
        <dl class="m-0 grid gap-x-6 lg:grid-cols-2">
            @foreach ($facts as [$term, $value])
                <div class="flex justify-between gap-3 border-b border-hairline py-2 text-[13px]"><dt class="text-ink-2">{{ $term }}</dt><dd class="m-0 text-right">{{ $value }}</dd></div>
            @endforeach
        </dl>
        @if ($tournament->results_mode === TournamentResultsMode::Director)
            <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Results are entered by the tournament directors.') }}</p>
        @endif
        <p class="m-0 text-xs leading-normal text-ink-3">{{ __('Sign-up, the bracket and the prize pool appear here once the tournament is published.') }}</p>
    </section>
</div>
