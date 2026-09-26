<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatCopy;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentSignups;
use App\Support\Tournaments\TournamentView;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * A tournament (TournamentShow.dc.html, P8b): what it is, who is in, and,
 * once drawn, each stage's public view from the engine (bracket, table and
 * pairings, heats) with the director marker on director results. A draft is
 * only seen by its creator, its directors and admins (everyone else gets the
 * 404 of a tournament that does not exist); its manager publishes it here.
 * Sign-up itself is its own page (TournamentSignup.dc.html).
 *
 * The artboard's prize pool, zap and sponsor parts follow with P9; its
 * "rated series mine like any rated game" line is outdated: tournament
 * matches never mine (user, 2026-09-26).
 */
new #[Layout('layouts::app', ['section' => 'tournaments'])] class extends Component {
    public Tournament $tournament;

    public string $closesAt = '';

    public string $error = '';

    public function mount(Tournament $tournament): void
    {
        abort_unless($tournament->isVisibleTo(auth()->user()), 404);

        $this->tournament = $tournament;
        $this->closesAt = $tournament->starts_at->copy()->subHour()->timezone($this->zone())->format('Y-m-d\TH:i');
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title($this->tournament->name);
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->check() && Gate::allows('manage-tournament', $this->tournament);
    }

    #[Computed]
    public function canDirect(): bool
    {
        return auth()->check() && Gate::allows('direct-tournament', $this->tournament);
    }

    public function publish(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && Gate::allows('manage-tournament', $this->tournament), 403);

        $this->error = '';

        try {
            $closes = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->closesAt, $this->zone());
        } catch (\Throwable) {
            $closes = null;
        }

        if (! $closes instanceof CarbonImmutable) {
            $this->error = __('Pick the date and time sign-up closes.');

            return;
        }

        try {
            $this->tournament = app(TournamentPublisher::class)->publish($this->tournament, $user, $closes->utc());
        } catch (TournamentRuleViolation $violation) {
            $this->error = $violation->getMessage();
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, TournamentSignup>
     */
    #[Computed]
    public function entries(): \Illuminate\Support\Collection
    {
        return TournamentSignup::query()->where('tournament_id', $this->tournament->id)->active()->with('lineup.clan')->orderBy('id')->get();
    }

    #[Computed]
    public function myEntry(): ?TournamentSignup
    {
        $user = auth()->user();

        return $user === null ? null : app(TournamentSignups::class)->entryOf($this->tournament, $user);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function stages(): array
    {
        return in_array($this->tournament->status, [TournamentStatus::Running, TournamentStatus::Finished], true)
            ? (new TournamentView($this->tournament))->stages()
            : [];
    }

    private function zone(): string
    {
        return (string) (auth()->user()->timezone ?? config('esports.preseason.display_timezone'));
    }
}; ?>

@php
    $tournament = $this->tournament;
    $profile = $tournament->profile();
    $options = $tournament->formatOptions();
    $zone = (string) (auth()->user()->timezone ?? config('esports.preseason.display_timezone'));
    $teams = $profile->entersTeams();
    $who = $teams ? trans_choice(':count team|:count teams', $tournament->capacity) : trans_choice(':count player|:count players', $tournament->capacity);
    $places = app(\App\Support\Tournaments\TournamentSignups::class)->places($tournament);
    $facts = [
        [__('Game, mode'), ($tournament->game === 'chess' ? __('Chess') : __('Rocket League')).' '.($tournament->mode === 'correspondence' ? __('Daily') : ($tournament->mode === 'blitz' ? __('Blitz 5+3') : $tournament->mode))],
        [__('Starts'), $tournament->starts_at->copy()->timezone($zone)->format('Y-m-d H:i')],
        [__('Format'), $tournament->format->label().($tournament->format === TournamentFormat::Swiss && $options->swissRounds !== null ? ', '.trans_choice(':count round|:count rounds', $options->swissRounds) : '')],
        [__('Planned for'), $who],
        [__('Planned duration'), __('about :duration', ['duration' => Estimator::format($tournament->plannedDuration(), $profile)])],
        [__('Where'), $tournament->on_site ? __('On site').', '.trans_choice(':count station|:count stations', (int) $tournament->stations) : __('Online')],
        [__('Results'), $tournament->results_mode->label()],
    ];
    $rules = [
        [__('Sign-up closes'), $tournament->signup_closes_at === null ? __('when it is published') : $tournament->signup_closes_at->copy()->timezone($zone)->format('Y-m-d H:i')],
        [__('Entries'), $teams
            ? __(':lineups lineups and :solos solo players, :taken of :places places', ['lineups' => $places['lineups'], 'solos' => $places['solos'], 'taken' => $places['taken'], 'places' => $places['places']])
            : __(':taken of :places players', ['taken' => $places['taken'], 'places' => $places['places']])],
        [__('Seeding'), $teams
            ? __('by Elo at sign-up close, equal Elo by earlier sign-up; mix teams after the lineups, in draw order')
            : __('by Elo at sign-up close, equal Elo by earlier sign-up')],
        [__('Season chain'), __('separate: tournament matches count for Elo and never mine season blocks')],
        [__('Prize pool'), __('the tournament’s own pot, paid out when it ends after an admin check')],
    ];
    $proof = array_values(array_filter([
        $tournament->naddr() ? ['naddr', $tournament->naddr()] : null,
        $tournament->event ? [__('Calendar event'), $tournament->event->event_id] : null,
        $tournament->draw_height ? [__('Draw block'), (string) $tournament->draw_height] : null,
        $tournament->draw_hash ? [__('Block hash'), $tournament->draw_hash] : null,
        $tournament->drawEvent ? [__('Draw event'), $tournament->drawEvent->event_id] : null,
    ]));
@endphp

<div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12" data-test="tournament-show">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-4">
        <div class="flex min-w-0 flex-col gap-2 lg:grow">
            <h1 class="m-0 font-display text-[28px] font-bold break-words lg:text-[34px]">{{ $tournament->name }}</h1>
            <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __(FormatCopy::for($tournament->format)['how']) }}</p>
        </div>
        <span class="inline-flex h-8 items-center gap-2 self-start rounded-sm bg-btc-chip px-3 text-xs font-bold text-btc-hi lg:self-auto" data-test="tournament-status"><span class="size-1.5 rounded-full bg-btc"></span>{{ $tournament->status->label() }}</span>
    </div>

    @if ($tournament->status === TournamentStatus::Draft && $this->canManage)
        <form wire:submit="publish" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#F7931A] lg:px-6" data-test="publish-form">
            <h2 class="m-0 text-[15px] font-bold">{{ __('Publish and open sign-up') }}</h2>
            <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('The league publishes the tournament to its calendar on Nostr. Players can sign up until the time you pick; then the draw runs from the next Bitcoin block.') }}</p>
            <label class="flex flex-col gap-1.5 text-xs text-ink-2 sm:max-w-[320px]">
                {{ __('Sign-up closes') }}
                <input type="datetime-local" wire:model="closesAt" class="h-11 rounded-md border border-line bg-well px-3 text-[13px] text-ink" data-test="closes-at">
            </label>
            @if ($error !== '')
                <p class="m-0 text-[13px] text-loss" role="alert">{{ $error }}</p>
            @endif
            <div><x-button type="submit" icon="send" data-test="publish">{{ __('Publish tournament') }}</x-button></div>
        </form>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <section aria-labelledby="facts-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
            <h2 id="facts-h" class="m-0 text-[15px] font-bold">{{ __('About this tournament') }}</h2>
            <dl class="m-0 flex flex-col">
                @foreach ($facts as [$term, $value])
                    <div class="flex justify-between gap-3 border-b border-hairline py-2 text-[13px]"><dt class="text-ink-2">{{ $term }}</dt><dd class="m-0 text-right">{{ $value }}</dd></div>
                @endforeach
            </dl>
            @if ($tournament->isDirectorMode())
                <p class="m-0 text-xs leading-normal text-ink-2" data-test="director-notice">{{ __('Results are entered by the tournament directors.') }}</p>
            @endif
        </section>

        <section aria-labelledby="rules-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
            <h2 id="rules-h" class="m-0 text-[15px] font-bold">{{ __('Sign-up and rules') }}</h2>
            <dl class="m-0 flex flex-col">
                @foreach ($rules as [$term, $value])
                    <div class="flex justify-between gap-3 border-b border-hairline py-2 text-[13px]"><dt class="shrink-0 text-ink-2">{{ $term }}</dt><dd class="m-0 text-right">{{ $value }}</dd></div>
                @endforeach
            </dl>
            @if ($proof !== [])
                <x-proof :rows="$proof" />
            @endif
        </section>
    </div>

    @if ($tournament->status === TournamentStatus::Signup)
        <section class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:flex-row lg:items-center lg:px-6" data-test="signup-cta">
            <p class="m-0 grow text-[13px] leading-normal text-ink-2">
                @if ($this->myEntry)
                    {{ __('You are signed up: :entry. You can pull out until sign-up closes.', ['entry' => $this->myEntry->name]) }}
                @elseif ($tournament->isSignupOpen())
                    {{ $teams ? __('Captains enter their lineup; everyone else can enter solo and is drawn into a mix team.') : __('Sign up and play. Seeding is by Elo.') }}
                @else
                    {{ __('Sign-up has closed. The draw follows.') }}
                @endif
            </p>
            @if ($tournament->isSignupOpen())
                <x-button :href="route('tournaments.signup', $tournament)" data-test="to-signup">{{ $this->myEntry ? __('Your entry') : __('Sign up') }}</x-button>
            @endif
        </section>
    @endif

    @if ($tournament->status === TournamentStatus::Drawing)
        <section class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="draw-pending">
            <h2 class="m-0 text-[15px] font-bold">{{ __('Draw pending') }}</h2>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Sign-up is closed. The draw waits for Bitcoin block :height; its hash seeds the mix teams and the bracket.', ['height' => $tournament->draw_height]) }}</p>
        </section>
    @endif

    @if ($tournament->status === TournamentStatus::Running || $tournament->status === TournamentStatus::Finished)
        <div class="flex flex-wrap gap-3">
            @if ($tournament->draw_hash)
                <x-button variant="quiet" :href="route('tournaments.draw', $tournament)" icon="shield-check">{{ __('The draw') }}</x-button>
            @endif
            @if ($this->canDirect && $tournament->isDirectorMode())
                <x-button variant="secondary" :href="route('tournaments.director', $tournament)" data-test="to-director">{{ __('Director desk') }}</x-button>
            @endif
        </div>
        @include('pages.tournaments.partials.stages', ['stages' => $this->stages, 'tournament' => $tournament])
    @endif

    @if (in_array($tournament->status, [TournamentStatus::Signup, TournamentStatus::Drawing], true))
        <section aria-labelledby="entries-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="entries">
            <h2 id="entries-h" class="m-0 text-[15px] font-bold">{{ __('Entries') }}</h2>
            @if ($this->entries->isEmpty())
                <p class="m-0 text-[13px] text-ink-2">{{ __('Nobody has signed up yet.') }}</p>
            @else
                <ul class="m-0 flex list-none flex-col p-0">
                    @foreach ($this->entries as $entry)
                        <li class="flex items-center gap-3 border-t border-hairline py-2 text-[13px]" wire:key="entry-{{ $entry->id }}">
                            @if ($entry->lineup?->clan)
                                <x-clan-tag :tag="$entry->lineup->clan->clantag" size="sm" />
                            @else
                                <span class="inline-flex h-5 items-center rounded-xs bg-raised px-1.5 text-[10px] text-ink-2">{{ $teams ? __('solo') : __('player') }}</span>
                            @endif
                            <span class="grow truncate">{{ $entry->name }}</span>
                            @if ($entry->lineup_id)
                                <span class="text-xs text-ink-3">{{ trans_choice(':count player|:count players', count($entry->members)) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
