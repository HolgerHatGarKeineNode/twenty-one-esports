@props(['tournament', 'page', 'current', 'organizer' => false])

{{--
    The head of every T6 sub-page (TournamentSignup, TournamentPool, TournamentDraw, TournamentLive, TournamentOps
    .dc.html; plan "Refactor und Design-Revamp", P6): the cover thumb, the marks (organizer tournament, status, pot,
    game line), "<name>: <page>" and, on the right, the page's own slot (a countdown, a state chip); under it the tabs
    in one order: for players Überblick, Eintragen, Auslosung; for the organizer, after "nur Veranstalter", Turnierleitung,
    Preispool, Live-Steuerung and Bearbeiten, each only where the viewer may open it. Harmonised: one head, one tab row.

    `page`: the page's name after the colon; `current`: the current tab's key; `organizer`: the organizer's tab row.
--}}
@php
    use App\Enums\TournamentStatus;
    use Illuminate\Support\Facades\Gate;

    $short = \App\Support\Engagement\HomeBoard::shortName($tournament);
    $pot = \App\Support\Prizes\PrizeChips::of($tournament)['sats'] ?? null;
    $open = $tournament->status === TournamentStatus::Signup && $tournament->isSignupOpen();
    $user = auth()->user();
    $tabs = $organizer
        ? array_values(array_filter([
            $user && Gate::allows('direct-tournament', $tournament) ? ['director', route('tournaments.director', $tournament), __('Director desk')] : null,
            $user && Gate::allows('manage-tournament', $tournament) ? ['pool', route('tournaments.pool', $tournament), __('Prize pool')] : null,
            $user ? ['live', route('tournaments.live', $tournament), __('Live control')] : null,
            $user && Gate::allows('manage-tournament', $tournament) && \Illuminate\Support\Facades\Route::has('admin.tournaments.edit') ? ['edit', route('admin.tournaments.edit', $tournament), __('Edit')] : null,
        ]))
        : array_values(array_filter([
            ['overview', route('tournaments.show', $tournament), __('Overview')],
            in_array($tournament->status, [TournamentStatus::Signup, TournamentStatus::Drawing], true) ? ['signup', route('tournaments.signup', $tournament), __('Enter the tournament')] : null,
            ['draw', route('tournaments.draw', $tournament), __('Drawing the bracket')],
        ]));
@endphp

<div {{ $attributes->class('flex min-w-0 flex-col gap-4') }} data-test="tournament-page-head">
    <div class="rv-thead flex-wrap sm:flex-nowrap">
        <a href="{{ route('tournaments.show', $tournament) }}" class="max-sm:hidden" tabindex="-1" aria-hidden="true">
            <x-game-cover :game="$tournament->game" :games="$tournament->coverGames()" size="thumb" class="h-[72px] w-32 flex-none rounded-[4px] [&_img]:object-cover" />
        </a>
        <div class="flex min-w-0 grow flex-col gap-2">
            <span class="flex min-w-0 flex-wrap items-center gap-2">
                @unless ($tournament->isCasualCup() || $tournament->isLeagueWeek())
                    <span class="rv-tag is-btc"><x-icon name="trophy" :size="12" />{{ __('Organizer tournament') }}</span>
                @endunless
                <span @class(['rv-tag', 'is-ok' => $open || $tournament->status === TournamentStatus::Finished, 'is-bad' => $tournament->status === TournamentStatus::Cancelled])>@if ($open)<span class="rv-live is-ok" aria-hidden="true"><i></i></span>@endif{{ $tournament->status->label() }}</span>
                @if ($pot !== null && $pot > 0)
                    <span class="rv-tag is-btc"><x-icon name="bolt" :size="12" />{{ __('Pot :sats Sats', ['sats' => \App\Support\Engagement\HomeBoard::sats($pot)]) }}</span>
                @endif
                <span class="rv-m min-w-0 truncate max-lg:hidden">{{ implode(', ', array_filter([\App\Support\Tournaments\Lobbies::gameLine($tournament), \App\Support\Tournaments\Lobbies::formatLabel($tournament)])) }}</span>
            </span>
            <h1 class="m-0 font-display text-2xl leading-[1.15] font-bold [overflow-wrap:anywhere] lg:text-[30px]" data-test="page-head-title">
                <a href="{{ route('tournaments.show', $tournament) }}" class="text-ink hover:text-ink">{{ $short }}</a>: {{ $page }}
            </h1>
        </div>
        @isset($side)
            <div class="flex flex-none flex-col items-start gap-1 sm:items-end" data-test="page-head-side">{{ $side }}</div>
        @endisset
    </div>

    <nav class="rv-tabs" aria-label="{{ __('Tournament') }}" data-test="tournament-page-tabs">
        @if ($organizer)
            <span class="rv-tag is-btc-soft mr-2 flex-none">{{ __('Organizers only') }}</span>
        @endif
        @foreach ($tabs as [$key, $href, $label])
            <a href="{{ $href }}" class="rv-tab" @if ($key === $current) aria-current="page" @endif data-test="page-tab-{{ $key }}">{{ $label }}</a>
        @endforeach
    </nav>
</div>
