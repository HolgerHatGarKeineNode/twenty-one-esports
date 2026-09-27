<?php

use App\Enums\ChessGameStatus;
use App\Enums\ChessInviteStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\ChessInvite;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Cards\ShareCard;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessRuleViolation;
use App\Support\LeagueTime;
use App\Support\PageMeta;
use App\Support\Seo\LocalizedUrls;
use App\Support\Seo\StructuredData;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatCopy;
use App\Support\Tournaments\Preview;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentLanding;
use App\Support\Tournaments\TournamentPrizePool;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentView;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * A tournament's public page (TournamentShow.dc.html, P8b), built as its
 * landing and invite page: the game's cover, the name, the call to action
 * for the viewer's state with the countdown and the places, who plays (with
 * the seed each entry would get now), the bracket (projected before the
 * draw, the engine's public view after it, the match up now highlighted),
 * the prize pool once P9 has one, then the facts, how it works, the FAQ and
 * the Nostr proof. A draft is only seen by its creator, its directors and
 * admins (everyone else gets the 404 of a tournament that does not exist);
 * its manager publishes it here. Sign-up itself is its own page.
 *
 * While sign-up, the draw or the matches move, the page polls every 15
 * seconds (only the TV view, pages::tournaments.tv, listens on the
 * tournament's Reverb channel); a new entry drops into the grid when it
 * arrives.
 *
 * The prize pool (P9): the pot, the split and the sponsors come from the
 * league's pool (App\Support\Prizes\WalletPrizePool behind
 * TournamentPrizePool); the zap panel, the payouts and the organizer's and
 * admin's links are their own component (components/⚡tournament-pool).
 *
 * A casual cup (P25, CasualCups) carries a "Casual" marker, and a player
 * with an open chess match in it gets the match card: the opponent, the
 * deadline, the league's auto slot, and "Play your cup match" (an invite
 * only to that opponent) or the opponent's invite to accept.
 */
new #[Layout('layouts::app', ['section' => 'tournaments'])] class extends Component {
    public Tournament $tournament;

    public string $closesAt = '';

    public string $error = '';

    public string $cupError = '';

    public function mount(Tournament $tournament): void
    {
        abort_unless($tournament->isVisibleTo(auth()->user()), 404);

        $this->tournament = $tournament;
        $this->closesAt = LeagueTime::input($tournament->starts_at->copy()->subHour());
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $tournament = $this->tournament;

        // Only a published tournament is public (TournamentPublisher sets both); a draft stays noindex.
        // The first title() call wins (Livewire merges page params first-come), so each branch sets it once.
        if ($tournament->status === TournamentStatus::Draft || $tournament->published_at === null) {
            $view->title($tournament->name);

            return;
        }

        $locale = app()->getLocale();
        $title = $tournament->name.' · '.__(':game tournament', ['game' => \App\Support\GameNames::game($tournament->game)]);
        $view->title($title);

        $meta = app(PageMeta::class)
            ->describe($title, $this->description())
            ->addStructuredData(StructuredData::tournament($tournament, LocalizedUrls::for($locale)))
            ->addStructuredData(StructuredData::breadcrumbs([
                [__('Home'), LocalizedUrls::for($locale, route('home'))],
                [__('Tournaments'), LocalizedUrls::for($locale, route('tournaments.index'))],
                [$tournament->name, LocalizedUrls::for($locale, route('tournaments.show', $tournament))],
            ]));

        // The link preview: the tournament's own card with its cover and places (ShareCard `tournament-invite`).
        $card = ShareCard::tournamentInvite($tournament);
        $meta->images = [[$card->url('wide'), 1200, 630, __(':tournament, sign-up and bracket', ['tournament' => $tournament->name])]];
    }

    /**
     * The search and preview description: game, mode, format, size, place,
     * start and where the tournament stands, in the display time zone.
     */
    private function description(): string
    {
        $tournament = $this->tournament;
        $zone = (string) config('esports.preseason.display_timezone');
        $date = fn (\Carbon\CarbonInterface $at): string => $at->copy()->timezone($zone)->format('Y-m-d H:i T');
        $teams = $tournament->profile()->entersTeams();

        $description = __(':game tournament (:mode), :format, for :who, :where, starting :date.', [
            'game' => \App\Support\GameNames::game($tournament->game),
            'mode' => $tournament->mode === 'correspondence' ? __('Daily') : ($tournament->mode === 'blitz' ? __('Blitz 5+3') : $tournament->mode),
            'format' => $tournament->format->label(),
            'who' => $teams ? trans_choice(':count team|:count teams', $tournament->capacity) : trans_choice(':count player|:count players', $tournament->capacity),
            'where' => $tournament->on_site ? __('on site') : __('online'),
            'date' => $date($tournament->starts_at),
        ]);

        $champion = $this->champion;

        return $description.' '.match ($tournament->status) {
            TournamentStatus::Signup => $tournament->isSignupOpen() && $tournament->signup_closes_at !== null
                ? __('Sign-up is open until :date.', ['date' => $date($tournament->signup_closes_at)])
                : __('Sign-up has closed. The draw follows.'),
            TournamentStatus::Drawing => __('Sign-up has closed. The draw follows.'),
            TournamentStatus::Running => $tournament->isPaused() ? __('The tournament is paused: no match starts and no deadline runs until it goes on.') : __('The tournament is running.'),
            TournamentStatus::Finished => $champion === null ? __('The tournament has finished.') : __('Finished. Winner: :name.', ['name' => $champion->name]),
            TournamentStatus::Cancelled => __('The tournament was called off.'),
            TournamentStatus::Draft => '',
        };
    }

    /**
     * The viewer's open chess match in this casual cup, with its invites.
     *
     * @return array{match: TournamentMatch, opponent: string, endsAt: \Carbon\CarbonInterface, slot: \Carbon\CarbonImmutable, game: \App\Models\ChessGame|null, incoming: ChessInvite|null, outgoing: ChessInvite|null}|null
     */
    #[Computed]
    public function cupMatch(): ?array
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $this->tournament->isCasualCup() || $this->tournament->status !== TournamentStatus::Running || ! $this->tournament->profile()->isChess()) {
            return null;
        }

        $match = TournamentMatch::query()->where('tournament_id', $this->tournament->id)->where('status', 'ready')->whereNull('result')
            ->where('bracket', '!=', 'bye')->whereHas('round', fn ($query) => $query->whereNotNull('window_ends_at'))
            ->whereHas('slots.participant', fn ($query) => $query->where('user_id', $user->id))
            ->with(['round', 'slots.participant', 'chessGame'])->first();

        if ($match === null || $match->round->window_ends_at === null) {
            return null;
        }

        $opponent = $match->slots->first(fn ($slot): bool => $slot->participant !== null && $slot->participant->user_id !== $user->id)?->participant;
        $open = fn () => ChessInvite::query()->where('tournament_match_id', $match->id)->where('status', ChessInviteStatus::Pending)->where('expires_at', '>', now())->latest('id');

        return [
            'match' => $match,
            'opponent' => $opponent->name ?? '',
            'endsAt' => $match->round->window_ends_at,
            'slot' => CasualCups::autoSlot($match->round->window_ends_at),
            'game' => $match->chessGame?->status === ChessGameStatus::Active ? $match->chessGame : null,
            'incoming' => $open()->where('invitee_id', $user->id)->first(),
            'outgoing' => $open()->where('inviter_id', $user->id)->first(),
        ];
    }

    public function playCupMatch(): void
    {
        $this->cupAttempt(function (User $user): void {
            $match = $this->cupMatch['match'] ?? null;

            if ($match === null) {
                throw new ChessRuleViolation('match_not_open');
            }

            $game = app(ChessInvites::class)->inviteToCupMatch($user, $match)->game;

            if ($game !== null) {
                $this->redirectRoute('games.show', ['game' => $game]);
            }
        });
    }

    public function acceptCupInvite(int $inviteId): void
    {
        $this->cupAttempt(function (User $user) use ($inviteId): void {
            $game = app(ChessInvites::class)->accept(ChessInvite::query()->whereNotNull('tournament_match_id')->findOrFail($inviteId), $user);
            $this->redirectRoute('games.show', ['game' => $game]);
        });
    }

    private function cupAttempt(\Closure $action): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login');

            return;
        }

        $this->cupError = '';

        try {
            $action($user);
        } catch (ChessRuleViolation $violation) {
            $this->cupError = match ($violation->reason) {
                'already_playing', 'accept_while_playing' => __('You are in a live game. One live game at a time: finish it, then play your cup match.'),
                'opponent_playing' => __('Your opponent is in another live game right now. Try again when it is over.'),
                'invite_closed' => __('That invite is no longer open.'),
                'match_not_open', 'not_your_match' => __('This match cannot be started now.'),
                default => __('That did not work, please try again.'),
            };
        }

        unset($this->cupMatch);
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
            $closes = LeagueTime::parse($this->closesAt);
        } catch (\InvalidArgumentException $invalid) {
            $this->error = $this->closesAt === '' ? __('Pick the date and time sign-up closes.') : $invalid->getMessage();

            return;
        }

        try {
            $this->tournament = app(TournamentPublisher::class)->publish($this->tournament, $user, $closes);
        } catch (TournamentRuleViolation $violation) {
            $this->error = $violation->getMessage();
        }
    }

    #[Computed]
    public function landing(): TournamentLanding
    {
        return new TournamentLanding($this->tournament, auth()->user());
    }

    #[Computed]
    public function champion(): ?TournamentParticipant
    {
        return $this->tournament->status === TournamentStatus::Finished ? app(TournamentChampion::class)->of($this->tournament) : null;
    }

    /**
     * @return array{sats: int, split: list<array{place: int, percent: int, sats: int}>, sponsors: list<array{name: string, logo: string|null, url: string|null}>}|null
     */
    #[Computed]
    public function pool(): ?array
    {
        return app(TournamentPrizePool::class)->for($this->tournament);
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
}; ?>

@php
    $tournament = $this->tournament;
    $landing = $this->landing;
    $profile = $tournament->profile();
    $options = $tournament->formatOptions();
    $zone = (string) (auth()->user()->timezone ?? config('esports.preseason.display_timezone'));
    $teams = $profile->entersTeams();
    $chess = $profile->isChess();
    $status = $tournament->status;
    $drawn = $landing->drawn();
    $published = $status !== TournamentStatus::Draft && $tournament->published_at !== null;
    $places = $landing->places();
    $roster = $landing->roster();
    $cta = $landing->cta();
    $deadline = $landing->deadline();
    $open = $landing->openSeats();
    $yourSeed = $landing->yourSeed();
    $champion = $this->champion;
    $pageUrl = route('tournaments.show', $tournament);
    $gameLine = \App\Support\GameNames::full($tournament->game, $tournament->mode);
    $at = fn (\Carbon\CarbonInterface $moment): string => LeagueTime::stamp($moment);
    $poll = in_array($status, [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running], true) && $published;

    // Who is in, for the line under the button: the two best seeds by name, the rest counted.
    $named = array_map(fn (array $row): string => $row['name'], array_slice($roster, 0, 2));
    $inLine = match (count($roster)) {
        0 => __('Nobody is in yet. The first seat is yours.'),
        1 => __(':a is in.', ['a' => $named[0]]),
        2 => __(':a and :b are in.', ['a' => $named[0], 'b' => $named[1]]),
        default => trans_choice(':a, :b and :count other are in.|:a, :b and :count others are in.', count($roster) - 2, ['a' => $named[0], 'b' => $named[1]]),
    };
    $faces = array_values(array_filter(array_map(fn (array $row) => $row['users'][0] ?? null, array_slice($roster, 0, 5))));

    $countdown = $landing->countdown();

    $shareText = $status === TournamentStatus::Signup && $tournament->isSignupOpen()
        ? __('Play :tournament with me on TWENTY ONE Esports: :game, :spots.', ['tournament' => $tournament->name, 'game' => $gameLine, 'spots' => trans_choice(':count spot left|:count spots left', $open)])
        : __(':tournament on TWENTY ONE Esports: :game.', ['tournament' => $tournament->name, 'game' => $gameLine]);

    $chips = [
        ['tournaments', __('Format'), $tournament->format->label().($tournament->format === TournamentFormat::Swiss && $options->swissRounds !== null ? ', '.trans_choice(':count round|:count rounds', $options->swissRounds) : ''), 'format'],
        // Online the end is open (P18): the start, and when it is expected to end, never a planned duration.
        ['flag', __('Starts'), $at($tournament->starts_at).(($openEnd = $landing->openEndLine($zone)) !== null ? ' · '.$openEnd : ''), 'starts'],
        ...($openEnd === null ? [['clock', __('Planned duration'), __('about :duration', ['duration' => Estimator::format($tournament->plannedDuration(), $profile)]), 'duration']] : []),
        [$tournament->on_site ? 'home' : 'wifi', __('Where'), $tournament->on_site ? __('On site').', '.trans_choice(':count station|:count stations', (int) $tournament->stations) : __('Online'), 'where'],
        ['shield-check', __('Results'), $tournament->results_mode->label(), 'results'],
        ['ladder', __('Rated'), match (true) {
            $status === TournamentStatus::Draft => __('decided when it is published'),
            $tournament->ladder_address === null => __('no: published before Block 0, so every match is casual'),
            ! $chess && ! $tournament->isDirectorMode() => __('yes, if its ladder is open at the pairing and the trust gate passes; counts once the other side confirms. Mix teams and same-clan pairings play casual'),
            default => __('yes, on its ladder while that is open and the trust gate passes'),
        }, 'rated'],
        ['award', __('Seeding'), __('by Elo at sign-up close'), 'seeding'],
        ['mining', __('Season chain'), __('separate: tournament matches never mine season blocks'), 'chain'],
    ];

    if (! $drawn && $tournament->signup_closes_at !== null) {
        array_splice($chips, 2, 0, [['lock', __('Sign-up closes'), $at($tournament->signup_closes_at), 'closes']]);
    }

    $proof = array_values(array_filter([
        $tournament->naddr() ? ['naddr', $tournament->naddr()] : null,
        $tournament->event ? [__('Calendar event'), $tournament->event->event_id] : null,
        $tournament->draw_height ? [__('Draw block'), (string) $tournament->draw_height] : null,
        $tournament->draw_hash ? [__('Block hash'), $tournament->draw_hash] : null,
        $tournament->drawEvent ? [__('Draw event'), $tournament->drawEvent->event_id] : null,
    ]));

    $steps = [
        [__('Sign up'), $teams
            ? __('Captains enter their lineup; everyone else enters solo and is drawn into a mix team. You confirm with your Nostr key and can pull out until sign-up closes.')
            : __('Confirm with your Nostr key. You can pull out until sign-up closes.')],
        [__('The draw'), $teams
            ? __('When sign-up closes, lineups are seeded by Elo. The hash of the next Bitcoin block draws the mix teams and seeds the bracket, so anyone can re-check it.')
            : __('When sign-up closes, players are seeded by Elo, equal Elo by earlier sign-up. The hash of the next Bitcoin block seeds the bracket.')],
        [__('Play'), $tournament->isDirectorMode()
            ? __('Play your match; the tournament directors enter the result. Winners move on until the last match decides.')
            : ($chess ? __('Your games start here on the site, with a clock. Winners move on until the last round decides.') : __('Report your series; the other side confirms it. Winners move on until the last match decides.'))],
    ];

    $formatCopy = FormatCopy::for($tournament->format);
    $noShow = $tournament->isDirectorMode()
        ? __('A tournament director can record a no-show. The other side wins by forfeit, and no Elo changes hands.')
        : ($chess
            ? __('Games run here with a clock, like every game on the site.')
            : __('Results come from the players: one side reports, the other confirms. If they disagree, an admin decides.'));

    // Projected bracket before the draw: the chooser's animated preview for the planned size, and round 1 if sign-up closed now.
    $projection = $landing->projection();
    $previewOptions = $tournament->format === TournamentFormat::Swiss && $options->swissRounds === null ? $options->withSwissRounds(Estimator::swissDefault($tournament->capacity)) : $options;
    $preview = $projection !== null ? Preview::for($tournament->format, $tournament->capacity, $previewOptions, 480, 208) : null;
    $previewSmall = $projection !== null ? Preview::for($tournament->format, $tournament->capacity, $previewOptions, 326, 196) : null;

    $cellCount = $places['places'] <= 96 ? $places['places'] : 0;
    $fillStep = $places['taken'] > 0 ? min(70, (int) round(900 / $places['taken'])) : 0;
@endphp

<div class="flex flex-col gap-12 pb-16 lg:gap-16" data-test="tournament-show" data-cta="{{ $cta }}" @if ($poll) wire:poll.15s.visible @endif>
    {{-- Hero: cover, name, the call to action with the countdown, the places --}}
    <section aria-labelledby="t-name" class="tl-hero relative isolate" data-test="tournament-hero">
        <div class="grid gap-6 px-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,440px)] lg:items-center lg:gap-12 lg:px-12">
            <div class="flex min-w-0 flex-col gap-5 lg:order-2">
                @include('pages.tournaments.partials.cover', ['tournament' => $tournament, 'class' => 'tl-poster-in w-full lg:max-w-[440px] lg:justify-self-end'])
            </div>

            <div class="flex min-w-0 flex-col gap-5 lg:order-1">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="inline-flex h-8 items-center gap-2 rounded-sm bg-btc-chip px-3 font-bold text-btc-hi" data-test="tournament-status">
                        <span @class(['size-1.5 rounded-full bg-btc', 'animate-live' => in_array($cta, ['open', 'live'], true)])></span>{{ $status->label() }}
                    </span>
                    <span class="inline-flex h-8 items-center gap-1.5 rounded-sm bg-raised px-3 text-ink-2"><x-icon :name="$chess ? 'pawn' : (app(\App\Games\GameRegistry::class)->find($tournament->game)?->assets()->icon ?? 'trophy')" :size="14" />{{ $gameLine }}</span>
                    <span class="inline-flex h-8 items-center rounded-sm bg-raised px-3 text-ink-2">{{ $tournament->format->label() }}</span>
                    <span class="inline-flex h-8 items-center rounded-sm bg-raised px-3 text-ink-2">{{ $tournament->on_site ? __('On site') : __('Online') }}</span>
                    @if ($tournament->isCasualCup())
                        <span class="inline-flex h-8 items-center rounded-sm bg-raised px-3 text-ink-2" data-test="casual-marker">{{ __('Casual') }}</span>
                    @endif
                    @if ($this->canManage)
                        <a href="{{ route('admin.tournaments.edit', $tournament) }}" class="inline-flex h-8 items-center rounded-sm border border-edge px-3 text-ink hover:text-ink" data-test="to-edit">{{ __('Edit') }}</a>
                    @endif
                </div>

                <h1 id="t-name" class="m-0 font-display text-[32px] leading-[1.08] font-bold break-words sm:text-[44px] xl:text-[56px]">{{ $tournament->name }}</h1>

                @include('pages.tournaments.partials.when', ['tournament' => $tournament, 'startsIn' => $landing->startsIn(), 'published' => $published])

                @if (filled($tournament->description))
                    <p class="m-0 max-w-[60ch] text-[15px] leading-relaxed whitespace-pre-line text-ink-2" data-test="tournament-description">{{ $tournament->description }}</p>
                @else
                    <p class="m-0 max-w-[60ch] text-[15px] leading-relaxed text-ink-2">{{ __($formatCopy['how']) }}</p>
                @endif

                {{-- The call to action for the viewer's state --}}
                @if ($cta !== 'draft')
                    <div class="tl-cta flex flex-col gap-4 rounded-card bg-card p-4 shadow-ring lg:p-5" data-test="signup-cta" data-state="{{ $cta }}">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-5">
                            @switch($cta)
                                @case('open')
                                    <a href="{{ route('tournaments.signup', $tournament) }}" data-test="to-signup"
                                       class="btn-p tl-go inline-flex min-h-14 shrink-0 items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-base font-bold text-on-btc hover:text-on-btc">
                                        <x-icon name="tournaments" :size="20" />{{ __('Sign up') }}
                                    </a>
                                    @break
                                @case('entered')
                                    <a href="{{ route('tournaments.signup', $tournament) }}" data-test="to-signup"
                                       class="inline-flex min-h-14 shrink-0 items-center justify-center gap-2.5 rounded-md bg-win-tint px-6 font-display text-base font-bold text-win shadow-ring-win hover:text-win">
                                        <x-icon name="check" :size="20" />{{ __('You’re in') }}
                                    </a>
                                    @break
                                @case('live')
                                @case('finished')
                                    <a href="#bracket" data-test="to-bracket"
                                       class="btn-p inline-flex min-h-14 shrink-0 items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-base font-bold text-on-btc hover:text-on-btc">
                                        <x-icon :name="$cta === 'live' ? 'eye' : 'trophy'" :size="20" />{{ $cta === 'live' ? __('Watch live') : __('See the results') }}
                                    </a>
                                    @break
                                @default
                                    <span class="inline-flex min-h-14 shrink-0 items-center justify-center gap-2.5 rounded-md bg-raised px-6 font-display text-base font-bold text-ink-2" data-test="cta-closed">
                                        <x-icon name="lock" :size="20" />{{ match ($cta) { 'full' => __('Sign-up is full'), 'cancelled' => __('Called off'), default => __('Sign-up closed') } }}
                                    </span>
                            @endswitch

                            <div class="flex min-w-0 flex-col gap-1">
                                @if ($countdown)
                                    <span class="text-xs text-ink-2">{{ $countdown['label'] }}</span>
                                    <span class="font-display text-xl font-bold tabular-nums sm:text-2xl" role="timer" data-test="countdown" title="{{ $countdown['when'] }}"
                                          x-data="countdown({ at: {{ $countdown['ms'] }}, days: @js(__(':count day|:count days')) })" x-text="text">{{ $countdown['text'] }}</span>
                                @endif
                                <span class="text-[13px] leading-normal text-ink-2" data-test="cta-note">
                                    @switch($cta)
                                        @case('open') {{ trans_choice(':count spot left. You can pull out until sign-up closes.|:count spots left. You can pull out until sign-up closes.', $open) }} @break
                                        @case('entered') {{ match (true) {
                                            $status === TournamentStatus::Drawing => __('Sign-up is closed. The draw waits for Bitcoin block :height; its hash seeds the mix teams and the bracket.', ['height' => $tournament->draw_height]),
                                            $yourSeed !== null => __('Seed :seed if sign-up closed now. Final seeds are set at sign-up close.', ['seed' => $yourSeed]),
                                            default => __('You are signed up: :entry. You can pull out until sign-up closes.', ['entry' => $landing->entry()?->name]),
                                        } }} @break
                                        @case('full') {{ __('Every spot is taken. Places open up when someone pulls out.') }} @break
                                        @case('closed') {{ $status === TournamentStatus::Drawing ? __('Sign-up is closed. The draw waits for Bitcoin block :height; its hash seeds the mix teams and the bracket.', ['height' => $tournament->draw_height]) : __('Sign-up has closed. The draw follows.') }} @break
                                        @case('live') {{ __('The matches are on. Results land in the bracket as they come in.') }} @break
                                        @case('finished') {{ $champion ? __('Finished. Winner: :name.', ['name' => $champion->name]) : __('The tournament has finished.') }} @break
                                        @case('cancelled') {{ __('The tournament was called off.') }} @break
                                    @endswitch
                                </span>
                            </div>
                        </div>

                        @if (! $drawn)
                            <div class="flex items-center gap-3 border-t border-hairline pt-4" data-test="who-is-in">
                                @if ($faces !== [])
                                    <span class="flex shrink-0 -space-x-2">
                                        @foreach ($faces as $face)
                                            <x-avatar :user="$face" :size="28" class="rounded-full ring-2 ring-card" />
                                        @endforeach
                                    </span>
                                @endif
                                <span class="min-w-0 text-[13px] leading-normal text-ink-2">{{ $inLine }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Invite: the page link to any chat, and the tournament's card as an image --}}
                @if ($published && $status !== TournamentStatus::Cancelled)
                    @include('pages.tournaments.partials.share', ['tournament' => $tournament, 'text' => $shareText, 'label' => $cta === 'open' || $cta === 'entered' ? __('Bring your friends') : __('Share this tournament')])
                @endif
            </div>
        </div>

        {{-- The places as seats: one block per spot, filled in the order people signed up --}}
        @if (! $drawn && $published)
            <div class="mt-8 flex flex-col gap-2.5 px-4 lg:mt-10 lg:px-12" data-test="places-meter">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="text-[13px] text-ink-2"><b class="font-display text-lg text-ink tabular-nums" x-data="countUp({{ $places['taken'] }})">{{ $places['taken'] }}</b> {{ __('of :places spots taken', ['places' => $places['places']]) }}</span>
                    @if ($teams)
                        <span class="text-xs text-ink-3">{{ trans_choice(':count lineup|:count lineups', $places['lineups']) }}, {{ trans_choice(':count solo player|:count solo players', $places['solos']) }}</span>
                    @endif
                </div>
                @if ($cellCount > 0)
                    <div @class(['tl-seats', 'is-dense' => $cellCount > 24]) role="meter" aria-valuemin="0" aria-valuemax="{{ $places['places'] }}" aria-valuenow="{{ $places['taken'] }}"
                         aria-label="{{ __(':taken of :places spots taken', ['taken' => $places['taken'], 'places' => $places['places']]) }}" style="--cells: {{ $cellCount }}">
                        @for ($cell = 0; $cell < $cellCount; $cell++)
                            <span @class(['tl-seat', 'is-taken' => $cell < $places['taken']]) @if ($cell < $places['taken']) style="animation-delay: {{ 200 + $cell * $fillStep }}ms" @endif></span>
                        @endfor
                    </div>
                @else
                    <div class="h-3 overflow-hidden rounded-sm bg-raised" role="meter" aria-valuemin="0" aria-valuemax="{{ $places['places'] }}" aria-valuenow="{{ $places['taken'] }}"
                         aria-label="{{ __(':taken of :places spots taken', ['taken' => $places['taken'], 'places' => $places['places']]) }}">
                        <span class="block h-full animate-fill bg-btc" style="width: {{ $places['places'] > 0 ? round($places['taken'] / $places['places'] * 100, 2) : 0 }}%"></span>
                    </div>
                @endif
            </div>
        @endif
    </section>

    @if ($this->cupMatch)
        @include('pages.tournaments.partials.cup-match', ['cup' => $this->cupMatch, 'error' => $cupError])
    @endif

    @if ($champion)
        {{-- The result (P11): the winner, the share card, and for the winners the share button. --}}
        <section aria-labelledby="tw-h" class="mx-4 flex flex-col gap-4 rounded-card bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#F7931A] sm:flex-row sm:items-center lg:mx-12 lg:px-6" data-test="tournament-winner">
            @php($winnerCard = \App\Support\Cards\ShareCard::tournament($tournament, $champion))
            <img src="{{ $winnerCard->path('wide') }}" alt="{{ __(':tournament winners', ['tournament' => $tournament->name]) }}" width="1200" height="630" loading="lazy"
                 class="aspect-[1200/630] h-auto w-full shrink-0 rounded-md shadow-ring sm:w-[280px]">
            <div class="flex min-w-0 flex-col gap-2">
                <span class="flex items-center gap-1.5 text-xs font-bold text-btc-hi"><x-icon name="trophy" :size="14" />{{ __('Winner') }}</span>
                <h2 id="tw-h" class="m-0 font-display text-2xl font-bold [overflow-wrap:anywhere]">{{ $champion->name }}</h2>
                @if (auth()->check() && in_array(auth()->id(), $champion->memberIds(), true))
                    <livewire:share-button type="tournament" :moment="(string) $tournament->id" />
                @endif
            </div>
        </section>
    @endif

    @if ($status === TournamentStatus::Draft && $this->canManage)
        <form wire:submit="publish" class="mx-4 flex flex-col gap-3 rounded-card bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#F7931A] lg:mx-12 lg:px-6" data-test="publish-form">
            <h2 class="m-0 text-[15px] font-bold">{{ __('Publish and open sign-up') }}</h2>
            <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('The league publishes the tournament to its calendar on Nostr. Players can sign up until the time you pick; then the draw runs from the next Bitcoin block.') }}</p>
            <x-berlin-datetime-input model="closesAt" :label="__('Sign-up closes')" :value="$closesAt" test="closes-at" class="sm:max-w-[400px]" />
            @if ($error !== '')
                <p class="m-0 text-[13px] text-loss" role="alert">{{ $error }}</p>
            @endif
            <div><x-button type="submit" icon="send" data-test="publish">{{ __('Publish tournament') }}</x-button></div>
        </form>
    @endif

    {{-- Who plays --}}
    <section aria-labelledby="entries-h" class="flex flex-col gap-4 px-4 lg:px-12" data-test="entries">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="entries-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Who plays') }}</h2>
            <span class="text-xs text-ink-3">{{ $drawn ? __('Seeded by Elo at sign-up close') : __('Seeds if sign-up closed now, by Elo; equal Elo by earlier sign-up') }}</span>
        </div>

        <ul class="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-2 p-0 sm:gap-3" x-data="{ settled: false }" x-init="setTimeout(() => settled = true, 50)">
            @foreach ($roster as $row)
                <li wire:key="{{ $row['key'] }}" x-init="settled && $el.classList.add('tl-arrive')" data-test="roster-entry" @if ($row['you']) data-you @endif
                    @class(['relative flex min-w-0 flex-col gap-3 rounded-card p-3.5', 'bg-card shadow-ring' => ! $row['you'], 'bg-btc-chip shadow-[inset_0_0_0_1px_var(--color-btc)]' => $row['you'],
                        'shadow-[inset_0_0_0_1px_#F7931A]' => $champion && $row['key'] === 'participant-'.$champion->id])>
                    <span class="flex items-start justify-between gap-2">
                        @if ($row['kind'] === 'lineup' && $row['clan'])
                            <x-clan-tag :clan="$row['clan']" :tile="40" class="flex size-10 shrink-0 items-center justify-center rounded-md bg-btc-tint text-[11px] font-bold text-btc" />
                        @elseif (isset($row['users'][0]) && in_array($row['kind'], ['player', 'solo'], true))
                            <x-avatar :user="$row['users'][0]" :size="40" class="rounded-md" />
                        @else
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-md bg-raised text-ink-2"><x-icon name="clans" :size="18" /></span>
                        @endif
                        @if ($row['seed'] !== null)
                            <span class="font-display text-lg leading-none font-bold text-ink-3 tabular-nums" title="{{ __('Seed :seed', ['seed' => $row['seed']]) }}"><span class="sr-only">{{ __('Seed') }} </span>{{ $row['seed'] }}</span>
                        @endif
                    </span>
                    <span class="flex min-w-0 flex-col gap-1">
                        <span class="flex min-w-0 items-center gap-1.5">
                            @if ($row['href'])
                                <a href="{{ $row['href'] }}" class="truncate text-[13px] font-bold text-ink hover:text-btc-hi">{{ $row['name'] }}</a>
                            @else
                                <span class="truncate text-[13px] font-bold">{{ $row['name'] }}</span>
                            @endif
                        </span>
                        <span class="flex flex-wrap items-center gap-1.5 text-xs text-ink-2">
                            @if ($row['kind'] !== 'lineup' && $row['clan'])
                                <x-clan-tag :clan="$row['clan']" size="sm" />
                            @endif
                            @if ($row['rating'] !== null)
                                <span class="tabular-nums">{{ __(':rating Elo', ['rating' => $row['rating']]) }}</span>
                            @elseif ($row['kind'] === 'solo')
                                <span>{{ __('Solo, drawn into a mix team') }}</span>
                            @elseif ($row['kind'] === 'mix')
                                <span>{{ __('Mix team') }}</span>
                            @endif
                        </span>
                    </span>
                    @if (count($row['users']) > 1)
                        <span class="flex -space-x-1.5">
                            @foreach (array_slice($row['users'], 0, 5) as $member)
                                <x-avatar :user="$member" :size="22" class="rounded-full ring-2 ring-card" />
                            @endforeach
                        </span>
                    @endif
                    @if ($row['you'])
                        <span class="absolute -top-2 left-3 inline-flex h-5 items-center rounded-xs bg-btc px-1.5 text-[11px] font-bold text-on-btc" data-test="you-chip">{{ __('You') }}</span>
                    @endif
                    @if ($champion && $row['key'] === 'participant-'.$champion->id)
                        <span class="absolute -top-2 right-3 inline-flex h-5 items-center gap-1 rounded-xs bg-btc px-1.5 text-[11px] font-bold text-on-btc"><x-icon name="trophy" :size="12" />{{ __('Winner') }}</span>
                    @endif
                </li>
            @endforeach

            @if (! $drawn && $published && $status !== TournamentStatus::Cancelled && $open > 0)
                {{-- Phones show three open tiles, wider screens six; the rest is counted. --}}
                @php($phoneTiles = 3)
                @for ($seat = 0; $seat < min($open, \App\Support\Tournaments\TournamentLanding::OPEN_TILES); $seat++)
                    <li wire:key="open-{{ $seat }}" data-test="open-seat" @class(['flex min-h-[132px] flex-col items-start justify-between gap-3 rounded-card border border-dashed border-dash p-3.5', 'max-sm:hidden' => $seat >= $phoneTiles])>
                        <span class="flex size-10 items-center justify-center rounded-md border border-dashed border-dash text-ink-3"><x-icon name="user" :size="18" /></span>
                        @if ($seat === 0 && $cta === 'open')
                            <a href="{{ route('tournaments.signup', $tournament) }}" class="text-[13px] font-bold" data-test="your-seat">{{ __('Your spot?') }}</a>
                        @else
                            <span class="text-[13px] text-ink-3">{{ __('Open spot') }}</span>
                        @endif
                    </li>
                @endfor
                @if ($open > $phoneTiles)
                    <li wire:key="open-more-phone" class="flex min-h-[132px] items-center justify-center rounded-card border border-dashed border-dash p-3.5 text-center text-[13px] text-ink-3 sm:hidden">
                        {{ trans_choice('+:count more open spot|+:count more open spots', $open - $phoneTiles) }}
                    </li>
                @endif
                @if ($open > \App\Support\Tournaments\TournamentLanding::OPEN_TILES)
                    <li wire:key="open-more" class="flex min-h-[132px] max-sm:hidden items-center justify-center rounded-card border border-dashed border-dash p-3.5 text-center text-[13px] text-ink-3" data-test="open-more">
                        {{ trans_choice('+:count more open spot|+:count more open spots', $open - \App\Support\Tournaments\TournamentLanding::OPEN_TILES) }}
                    </li>
                @endif
            @endif
        </ul>

        @if ($roster === [] && ($drawn || ! $published || $status === TournamentStatus::Cancelled))
            <p class="m-0 text-[13px] text-ink-2">{{ __('Nobody has signed up yet.') }}</p>
        @endif
    </section>

    {{-- The bracket: projected before the draw, the real one after it --}}
    <section id="bracket" aria-labelledby="bracket-h" class="flex scroll-mt-24 flex-col gap-4 px-4 lg:px-12" data-test="bracket">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="flex flex-wrap items-center gap-3">
                <h2 id="bracket-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Bracket') }}</h2>
                @if ($projection !== null)
                    <span class="inline-flex h-6 items-center rounded-xs border border-dashed border-edge px-2 text-[11px] text-ink-2" data-test="projected-chip">{{ __('Projected') }}</span>
                @endif
            </span>
            <span class="flex flex-wrap items-center gap-2">
                @if ($published && $status !== TournamentStatus::Cancelled)
                    {{-- The TV view (P19): the bracket full screen, live, for a big screen or a stream. --}}
                    <span class="text-xs text-ink-3 max-sm:hidden" id="tv-hint">{{ __('Full screen for a TV or a stream') }}</span>
                    <x-button variant="quiet" :href="route('tournaments.tv', $tournament)" icon="eye" data-test="to-tv" aria-describedby="tv-hint">{{ __('TV view') }}</x-button>
                @endif
                @if ($drawn && $tournament->draw_hash)
                    <x-button variant="quiet" :href="route('tournaments.draw', $tournament)" icon="shield-check">{{ __('The draw') }}</x-button>
                @endif
                @if ($drawn && $this->canDirect && $tournament->isDirectorMode())
                    <x-button variant="secondary" :href="route('tournaments.director', $tournament)" data-test="to-director">{{ __('Director desk') }}</x-button>
                @endif
            </span>
        </div>

        @if ($drawn)
            @include('pages.tournaments.partials.stages', ['stages' => $this->stages, 'tournament' => $tournament])
        @elseif ($projection !== null)
            <div class="grid gap-4 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:items-start" data-test="bracket-preview" data-format="{{ $tournament->format->value }}">
                <figure class="m-0 flex flex-col gap-2 rounded-card bg-card p-4" x-data x-ref="figure">
                    @foreach ([['desktop', $preview, 480, 208, 'hidden lg:block'], ['mobile', $previewSmall, 326, 196, 'lg:hidden']] as [$size, $shape, $width, $height, $visibility])
                        <svg viewBox="0 0 {{ $width }} {{ $height }}" class="tf-preview {{ $visibility }} h-auto w-full" role="img" wire:key="pv-{{ $size }}"
                             aria-label="{{ __('Preview of :format for :who', ['format' => $tournament->format->label(), 'who' => $teams ? trans_choice(':count team|:count teams', $tournament->capacity) : trans_choice(':count player|:count players', $tournament->capacity)]) }}">
                            @foreach ($shape['lines'] as $line)
                                <path d="{{ $line['d'] }}" fill="none" stroke="#3A3A42" stroke-width="1" style="animation-delay: {{ $line['delay'] }}s" />
                            @endforeach
                            @foreach ($shape['rects'] as $rect)
                                <rect x="{{ $rect['x'] }}" y="{{ $rect['y'] }}" width="{{ $rect['w'] }}" height="{{ $rect['h'] }}" rx="2"
                                      fill="{{ $rect['ghost'] ? 'transparent' : '#F7931A' }}" stroke="{{ $rect['ghost'] ? '#8B8B90' : 'none' }}" @if ($rect['ghost']) stroke-dasharray="3 3" @endif
                                      style="animation-delay: {{ $rect['delay'] }}s" />
                            @endforeach
                            @foreach ($shape['texts'] as $text)
                                <text x="{{ $text['x'] }}" y="{{ $text['y'] }}" text-anchor="{{ $text['anchor'] }}" fill="#8B8B90" font-size="10" font-family="JetBrains Mono, monospace">{{ __($text['text'], $text['replace']) }}</text>
                            @endforeach
                        </svg>
                    @endforeach
                    <figcaption class="flex items-start gap-3 text-xs leading-normal text-ink-2">
                        <span class="grow">{{ __(':format for :who. Each block is one match, lit in the round it is played.', ['format' => $tournament->format->label(), 'who' => $teams ? trans_choice(':count team|:count teams', $tournament->capacity) : trans_choice(':count player|:count players', $tournament->capacity)]) }}</span>
                        <button type="button" class="min-h-6 shrink-0 cursor-pointer text-btc hover:text-btc-hi" data-test="preview-replay"
                                x-on:click="$refs.figure.querySelectorAll('svg').forEach((svg) => { svg.classList.remove('tf-preview'); void svg.getBoundingClientRect(); svg.classList.add('tf-preview'); })">{{ __('Play again') }}</button>
                    </figcaption>
                </figure>

                <div class="flex min-w-0 flex-col gap-3 rounded-card bg-card p-4">
                    <h3 class="m-0 text-[15px] font-bold">{{ $projection['groups'] !== [] ? __('Groups if sign-up closed now') : __('Round 1 if sign-up closed now') }}</h3>
                    @if ($projection['groups'] !== [])
                        <div class="grid grid-cols-2 gap-2 sm:gap-3">
                            @foreach ($projection['groups'] as $number => $members)
                                <div class="flex flex-col gap-1.5 rounded-md bg-ground p-3 shadow-ring-hairline" wire:key="pg-{{ $number }}">
                                    <span class="text-xs font-bold text-ink-2">{{ __('Group :group', ['group' => chr(64 + $number)]) }}</span>
                                    @foreach ($members as $side)
                                        @include('pages.tournaments.partials.projected-side', ['side' => $side])
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @else
                        <ol class="m-0 grid list-none gap-2 p-0 sm:grid-cols-2">
                            @foreach ($projection['matches'] as $match)
                                <li class="flex flex-col gap-1 rounded-md bg-ground p-2.5 shadow-ring-hairline" wire:key="pm-{{ $match['key'] }}" data-test="projected-match">
                                    @foreach ($match['sides'] as $side)
                                        @include('pages.tournaments.partials.projected-side', ['side' => $side])
                                    @endforeach
                                </li>
                            @endforeach
                        </ol>
                        @if ($projection['byes'] !== [])
                            <p class="m-0 text-xs leading-normal text-ink-2">{{ trans_choice('Seed :seeds skips round 1 (bye).|Seeds :seeds skip round 1 (bye).', count($projection['byes']), ['seeds' => implode(', ', $projection['byes'])]) }}</p>
                        @endif
                    @endif
                    <p class="m-0 text-xs leading-normal text-ink-3">{{ __('A preview, not the draw. Seeds are fixed when sign-up closes; the hash of the next Bitcoin block seeds the real bracket.') }}</p>
                </div>
            </div>
        @else
            <p class="m-0 text-[13px] text-ink-2">{{ __('The tournament was called off.') }}</p>
        @endif

        @if ($drawn && $tournament->isDirectorMode())
            {{-- The director log is public (TOURNAMENT-FORMATS.md, section 6). --}}
            <details class="group rounded-card bg-card" data-test="public-log">
                <summary class="flex min-h-12 cursor-pointer items-center gap-3 px-4 lg:px-6">
                    <h3 class="m-0 text-[15px] font-bold">{{ __('Director log') }}</h3>
                    <span class="grow text-xs text-ink-3">{{ __('public, newest first') }}</span>
                    <x-icon name="chevron-down" :size="16" class="transition-transform group-open:rotate-180" />
                </summary>
                <div class="flex flex-col gap-2 px-4 pb-4 lg:px-6">
                    @forelse ($tournament->resultEntries()->with('match')->limit(20)->get() as $entry)
                        <p class="m-0 flex gap-3 border-t border-hairline pt-2 text-xs leading-normal">
                            <span class="shrink-0 text-ink-3">{{ $entry->created_at->copy()->timezone($zone)->format('H:i') }}</span>
                            <span><b>{{ strtoupper($entry->match->key) }} {{ $entry->result['label'] ?? '' }}</b> {{ __('by :name', ['name' => $entry->user_name]) }}@if ($entry->isCorrection()), <span class="text-btc">{{ __('correction, was :old', ['old' => $entry->replaced['label'] ?? '']) }}</span>@endif</span>
                        </p>
                    @empty
                        <p class="m-0 text-xs text-ink-2">{{ __('No result entered yet.') }}</p>
                    @endforelse
                </div>
            </details>
        @endif
    </section>

    {{-- The prize pool (P9): only when the league has one for this tournament --}}
    @if ($this->pool !== null)
        @include('pages.tournaments.partials.prize-pool', ['pool' => $this->pool])
    @endif
    @if ($tournament->pool_opened_at !== null || ($this->canManage && ! in_array($tournament->status, [TournamentStatus::Draft, TournamentStatus::Cancelled], true)))
        <livewire:tournament-pool :tournament="$tournament" :key="'pool-'.$tournament->id" />
    @endif

    {{-- How it works, and the facts --}}
    <section aria-labelledby="how-h" class="grid gap-8 px-4 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-12 lg:px-12">
        <div class="flex flex-col gap-4">
            <h2 id="how-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('How it works') }}</h2>
            <ol class="m-0 flex list-none flex-col gap-4 p-0" data-test="how-it-works">
                @foreach ($steps as $index => [$title, $text])
                    <li class="flex gap-3.5">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-md border border-btc-deep font-display text-sm font-bold text-btc-hi">{{ $index + 1 }}</span>
                        <span class="flex flex-col gap-1"><b class="text-[15px]">{{ $title }}</b><span class="text-[13px] leading-normal text-ink-2">{{ $text }}</span></span>
                    </li>
                @endforeach
            </ol>
        </div>

        <div class="flex flex-col gap-4">
            <h2 class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('The facts') }}</h2>
            <dl class="m-0 grid gap-2 sm:grid-cols-2" data-test="facts">
                @foreach ($chips as [$icon, $term, $value, $key])
                    <div class="flex gap-3 rounded-md bg-card px-3.5 py-3" data-fact="{{ $key }}">
                        <x-icon :name="$icon" :size="18" class="mt-0.5 shrink-0 text-btc" />
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <dt class="text-xs text-ink-3">{{ $term }}</dt>
                            <dd class="m-0 text-[13px] leading-normal">{{ $value }}</dd>
                        </span>
                    </div>
                @endforeach
            </dl>
            @if ($tournament->isDirectorMode())
                <p class="m-0 text-xs leading-normal text-ink-2" data-test="director-notice">{{ __('Results are entered by the tournament directors.') }}</p>
            @endif
        </div>
    </section>

    {{-- Questions, and the proof for the nerds --}}
    <section aria-labelledby="faq-h" class="flex flex-col gap-3 px-4 lg:px-12" data-test="faq">
        <h2 id="faq-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Questions') }}</h2>
        <div class="grid gap-2 lg:grid-cols-2 lg:items-start">
            @foreach ([
                [__('How does :format work?', ['format' => $tournament->format->label()]), __($formatCopy['how']).' '.__('Good for:').' '.__($formatCopy['good'])],
                [__('What if someone does not show up?'), $noShow],
                [__('How are seeds set?'), \Illuminate\Support\Str::ucfirst($teams
                    ? __('by Elo at sign-up close, equal Elo by earlier sign-up; mix teams after the lineups, in draw order')
                    : __('by Elo at sign-up close, equal Elo by earlier sign-up')).'.'],
                ...($this->pool !== null ? [[__('How is the prize pool paid out?'), __('When the tournament has ended, an admin checks it and closes the pool. The pool is split by place as shown; tied places share their percentages and a team’s share is split equally among its roster. Each player’s share goes to the Lightning address in their Nostr profile, and the league publishes every payment on Nostr with its proof.')]] : []),
            ] as [$question, $answer])
                <details class="group rounded-md bg-card">
                    <summary class="flex min-h-12 cursor-pointer items-center gap-3 px-4 text-[13px] font-bold">
                        <span class="grow">{{ $question }}</span>
                        <x-icon name="chevron-down" :size="16" class="shrink-0 transition-transform group-open:rotate-180" />
                    </summary>
                    <p class="m-0 px-4 pb-4 text-[13px] leading-relaxed text-ink-2">{{ $answer }}</p>
                </details>
            @endforeach
        </div>

        @if ($proof !== [])
            <details class="group rounded-md bg-card" data-test="nerds">
                <summary class="flex min-h-12 cursor-pointer items-center gap-3 px-4 text-[13px] font-bold">
                    <x-icon name="shield-check" :size="16" class="shrink-0 text-proof" />
                    <span class="grow">{{ __('For the nerds: the proof on Nostr') }}</span>
                    <x-icon name="chevron-down" :size="16" class="shrink-0 transition-transform group-open:rotate-180" />
                </summary>
                <div class="flex flex-col gap-3 px-4 pb-4">
                    <p class="m-0 text-xs leading-normal text-ink-2">{{ __('The league signs the tournament as a calendar event and, when it has a solo pool, the draw. Anyone can re-check them with these ids.') }}</p>
                    <x-proof :rows="$proof" open />
                </div>
            </details>
        @endif
    </section>
</div>
