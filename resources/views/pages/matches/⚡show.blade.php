<?php

use App\Enums\ReportStatus;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Support\PageMeta;
use App\Support\Seo\LocalizedUrls;
use App\Support\Seo\StructuredData;
use App\Support\Series\SeriesPresenter;
use App\Support\Series\SeriesService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * The public page of a series, 1:1 from MatchShow.dc.html (plan "Refactor und
 * Design-Revamp", P5): head, the course of the games, who played, inputs and
 * outputs, and the Proof. Public fields only; the one exception is a
 * tournament match's league lobby, shown to the tournament's directors and the
 * admins alone (LeagueLobby::visibleTo()), so they can join as spectators.
 * The two players have it in the room.
 *
 * Casual (before Block 0): casual Elo only (P7b), no league record; the
 * right card shows the casual stakes. An unknown number shows the "Match not found"
 * state of States.dc.html with a 404.
 *
 * A chess or Blockli team match (plan "Schach Rapid und Clan", P5; NIP rev. 9.25) shows its boards
 * live instead of the flow of games and the score sheet
 * (components/⚡team-match-boards), on a page with the websocket on, also for
 * guests: anyone may watch.
 */
new #[Layout('layouts::app', ['section' => 'matches'])] class extends Component {
    public int $number;

    public function mount(string $match): void
    {
        $this->number = (int) $match;

        // Chess games share the league match numbers (P7b): their number opens the game.
        $chessGame = ChessGame::query()->where('number', $this->number)->value('id');

        if ($chessGame !== null) {
            throw new HttpResponseException(redirect()->route('games.show', $chessGame));
        }

        if (! SeriesMatch::query()->where('number', $this->number)->exists()) {
            $latest = SeriesMatch::query()->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->max('number');

            abort(response()->view('pages.matches.not-found', ['number' => $this->number, 'latest' => $latest], 404));
        }
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $match = $this->match;
        $locale = app()->getLocale();
        $series = $match->isTeamMatch() ? __('Team match :number', ['number' => $match->label()]) : __('Series :number', ['number' => $match->label()]);
        $title = $series.': '.$match->challenger_name.' vs '.$match->challenged_name;
        $view->title($title);

        $description = $match->isTeamMatch()
            ? ($match->playsBoardGames()
                // A Blockli team match (NIP rev. 9.25): the same sentence with its game.
                ? __(':game team match over :boards boards between :challenger and :challenged in the TWENTY ONE esports league.', ['game' => \App\Support\GameNames::game($match->game), 'boards' => $match->boards, 'challenger' => $match->challenger_name, 'challenged' => $match->challenged_name])
                : __('Chess team match over :boards boards between :challenger and :challenged in the TWENTY ONE esports league.', ['boards' => $match->boards, 'challenger' => $match->challenger_name, 'challenged' => $match->challenged_name]))
            : __(':game :mode series, best of :best_of, between :challenger and :challenged in the TWENTY ONE esports league.', [
                'game' => \App\Support\GameNames::game($match->game),
                'mode' => $match->mode, 'best_of' => $match->best_of, 'challenger' => $match->challenger_name, 'challenged' => $match->challenged_name,
            ]);

        if ($match->isTeamMatch()) {
            // The boards update live for every spectator (resources/js/teamMatchBoards.js).
            $view->layoutData(['realtime' => true]);
        }

        if ($match->status->hasResult()) {
            $description .= ' '.(in_array($match->winner, SeriesMatch::SIDES, true)
                ? __('Result: :clan won.', ['clan' => $match->sideName((string) $match->winner)])
                : ($match->isTeamMatch() && $match->resolution === \App\Enums\SeriesResolution::Admin ? __('Team draw · no bonus') : __('Void · no winner')));
        }

        app(PageMeta::class)
            ->describe($title, $description)
            ->card(fn () => \App\Support\Cards\PageCard::series($match))
            ->addStructuredData(StructuredData::series($match, LocalizedUrls::for($locale)))
            ->addStructuredData(StructuredData::breadcrumbs([
                [__('Home'), LocalizedUrls::for($locale, route('home'))],
                [__('Matches'), LocalizedUrls::for($locale, route('matches.index'))],
                [$series, LocalizedUrls::for($locale, route('matches.show', $match->number))],
            ]));
    }

    #[Computed]
    public function match(): SeriesMatch
    {
        return SeriesMatch::query()
            ->with(['challengerLineup.clan', 'challengerLineup.seats.user.clanMember', 'challengedLineup.clan', 'challengedLineup.seats.user.clanMember',
                'latestReport.event', 'latestReport.responseEvent', 'challengeEvent', 'answerEvent', 'answeredBy', 'resolvedBy'])
            ->where('number', $this->number)
            ->firstOrFail();
    }

    /**
     * Who played, per side: the latest report's roster, or the lineups'
     * current regulars while nothing is reported.
     *
     * @return list<array{name: string, role: string, side: string, npub: string, user: \App\Models\User|null}>
     */
    #[Computed]
    public function roster(): array
    {
        $match = $this->match;
        $report = $match->latestReport;

        if ($report !== null) {
            $users = \App\Models\User::query()->whereIn('pubkey', array_column($report->roster, 'pubkey'))->get()->keyBy('pubkey');

            // The live profile name: the report froze the name of its day, and a renamed player read as a stranger (2026-10-03).
            return array_map(fn (array $entry) => ['name' => $users->get($entry['pubkey'])?->displayName() ?? $entry['name'], 'role' => $entry['role'], 'side' => $entry['side'], 'npub' => \App\Support\Nostr\NostrKeys::hexToNpub($entry['pubkey']), 'user' => $users->get($entry['pubkey'])], $report->roster);
        }

        $series = app(SeriesService::class);
        $roster = [];

        foreach (SeriesMatch::SIDES as $side) {
            foreach ($series->rosterSeats($match, $side) as $seat) {
                $roster[] = ['name' => $seat->user->displayName(), 'role' => $seat->role->value, 'side' => $side, 'npub' => $seat->user->npub, 'user' => $seat->user];
            }
        }

        return $roster;
    }
}; ?>

@php
    $match = $this->match;
    $viewer = auth()->user();
    $games = $match->currentGames();
    $wins = SeriesMatch::seriesScore($games);
    // Age of Empires II has no goals: the flow and the summary count games won instead.
    $hasGoals = $match->hasGoals();
    $goals = ['challenger' => array_sum(array_map(fn ($g) => (int) ($g['challenger'] ?? 0), $games)), 'challenged' => array_sum(array_map(fn ($g) => (int) ($g['challenged'] ?? 0), $games))];
    $report = $match->latestReport;
    $mySide = $match->captainSideOf($viewer);
    $toAnswer = $match->status === SeriesStatus::Reported && $report !== null && $report->status === ReportStatus::Open && $mySide !== null && $mySide !== $report->side;
    $colors = ['challenger' => '#F7931A', 'challenged' => '#A78BFA'];
    $elo = SeriesPresenter::ratingFacts($match);
    $team = $match->isTeamMatch();
    $banner = match (true) {
        // A team match: its result from the boards, a draw is no void (plan "Schach Rapid und Clan", P5).
        $team && $match->status->hasResult() && ! in_array($match->winner, SeriesMatch::SIDES, true) => [$match->resolution === \App\Enums\SeriesResolution::Admin ? __('Team draw · no bonus') : __('Void · no winner'), 'bg-well text-ink'],
        $team && $match->status->hasResult() => [__(':clan won · :how', ['clan' => $match->sideName((string) $match->winner), 'how' => $match->resolution === \App\Enums\SeriesResolution::Forfeit ? __('Forfeit') : __('on board points')]), 'bg-win-tint text-win'],
        $team => [SeriesPresenter::chip($match)['label'], 'bg-well text-ink-2'],
        $toAnswer => [__(':clan reported · your confirmation needed', ['clan' => $match->sideName($report->side)]), 'bg-btc-chip text-btc-hi'],
        $match->status === SeriesStatus::Reported && $report !== null => [__(':clan reported · waiting for :other', ['clan' => $match->sideName($report->side), 'other' => $match->sideName(SeriesMatch::otherSide($report->side))]), 'bg-btc-chip text-btc-hi'],
        $match->status === SeriesStatus::Disputed => [__('Disputed · an admin decides'), 'bg-loss-tint text-loss'],
        $match->status->hasResult() => [$match->winner === 'none' ? __('Void · no winner') : __(':clan won · :how', ['clan' => $match->sideName((string) $match->winner), 'how' => $match->resolution?->label() ?? '']), 'bg-win-tint text-win'],
        default => [SeriesPresenter::chip($match)['label'], 'bg-well text-ink-2'],
    };
    $height = max(1, count($games)) * 95;
@endphp

{{--
    MatchShow.dc.html (T3/T13 public page): breadcrumb, a head card (cover, chips, "A against B", the state, the
    score, the Elo moved), the course as a table of games on the left, "Inputs & Outputs" as a fact list in the
    right rail. A tournament match's league lobby sits on top of that rail for its directors and the admins only
    (LeagueLobby::visibleTo); nobody else ever gets it in this page.
--}}
@php($leagueLobby = \App\Support\Tournaments\LeagueLobby::visibleTo($match, $viewer))
<div class="flex grow flex-col gap-6 px-4 pt-6 pb-10 lg:mx-auto lg:w-full lg:max-w-[1376px] lg:px-12" data-test="match-detail">
    <nav aria-label="{{ __('Breadcrumb') }}" class="flex flex-wrap items-center gap-2 text-[13px] text-ink-3">
        <a href="{{ route('home') }}" class="rv-lk">{{ __('Home') }}</a><span aria-hidden="true">/</span><a href="{{ route('matches.index') }}" class="rv-lk">{{ __('Matches') }}</a>
    </nav>

    <section aria-labelledby="match-h" class="rv-card flex flex-col gap-5 p-4 sm:flex-row sm:items-center sm:p-6" style="background: linear-gradient(90deg, var(--color-feature), var(--color-card) 60%)" data-test="match-head">
        <a href="{{ \App\Support\GameNames::page($match->game) }}" class="shrink-0" aria-label="{{ \App\Support\GameNames::game($match->game) }}"><x-game-cover :game="$match->game" size="thumb" class="w-full rounded-[4px] sm:w-[200px]" data-test="match-game-cover" /></a>
        <div class="flex min-w-0 grow flex-col gap-2">
            <span class="flex flex-wrap gap-2">
                <span class="rv-tag">{{ $team ? __('Team match :number', ['number' => $match->label()]) : __('Series :number', ['number' => $match->label()]) }}</span>
                <span class="rv-tag h-auto min-h-5 max-w-full py-0.5 whitespace-normal">{{ \App\Support\GameNames::game($match->game) }} {{ $match->mode }}{{ $team ? '' : ', '.__('Best of :n', ['n' => $match->best_of]) }}</span>
                <span class="rv-tag">{{ $match->rated ? __('Rated') : __('Casual') }}</span>
                @if ($match->tournamentMatch !== null)
                    <a href="{{ route('tournaments.show', $match->tournamentMatch->tournament_id) }}" class="rv-tag is-btc hover:text-btc-hi"><x-icon name="trophy" :size="12" />{{ $match->tournamentMatch->tournament?->name }}</a>
                @endif
            </span>
            <h1 id="match-h" class="m-0 font-display text-[26px] leading-[1.15] font-bold [overflow-wrap:anywhere] lg:text-[30px]">{{ __(':a against :b', ['a' => $match->sideName('challenger'), 'b' => $match->sideName('challenged')]) }}</h1>
            <span class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <span @class(['rv-tag h-auto min-h-6 max-w-full py-0.5 whitespace-normal [overflow-wrap:anywhere]', 'is-ok' => str_contains($banner[1], 'win'), 'is-bad' => str_contains($banner[1], 'loss'), 'is-btc' => str_contains($banner[1], 'btc')]) data-test="match-banner">@if (str_contains($banner[1], 'win'))<x-icon name="check" :size="12" />@endif{{ $banner[0] }}</span>
                @unless ($team)
                    <b class="font-display text-[34px] leading-none font-bold tabular-nums" data-test="match-score">{{ $wins['challenger'] }} : {{ $wins['challenged'] }}</b>
                @endunless
                <span class="rv-tag is-btc h-auto min-h-6 py-0.5 whitespace-normal [overflow-wrap:anywhere]" data-test="match-elo">{{ $elo['stake'] }}{{ $elo['casual'] ? ' '.__('casual Elo') : '' }}</span>
                @if ($match->tournamentMatch?->isDirectorResult())
                    @include('pages.tournaments.partials.marker', ['marker' => \App\Support\Tournaments\TournamentView::marker((array) $match->tournamentMatch->result)])
                @endif
            </span>
        </div>
        <button type="button" x-data="{ copied: false }" x-on:click="navigator.clipboard?.writeText(window.location.href); copied = true; setTimeout(() => copied = false, 1500)" class="rv-b2 h-12 shrink-0 px-4" data-test="match-copy-link">
            <x-icon name="copy" :size="16" /><span x-text="copied ? @js(__('Link copied')) : @js(__('Copy link'))">{{ __('Copy link') }}</span>
        </button>
    </section>

    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
        <div class="flex min-w-0 flex-col gap-6">
            @if ($team)
                {{-- The boards first: they are what a spectator came for; the facts follow. --}}
                <livewire:team-match-boards :match="$match" :wire:key="'team-boards-'.$match->id" />
            @else
                <section aria-labelledby="flow-h" class="rv-card flex flex-col gap-3 p-4 sm:p-6" data-test="flow">
                    <span class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="flow-h" class="rv-h3">{{ __('Course') }}</h2>
                        @if (! $match->status->hasResult() && $games !== [])
                            <span class="rv-m text-[13px]">{{ __('Shown as provisional until both captains confirm.') }}</span>
                        @endif
                    </span>
                    @if ($games === [])
                        <p class="m-0 text-[13px] text-ink-2">{{ __('No game played yet. The course fills up as the games are entered.') }}</p>
                    @else
                        <div class="relative overflow-x-auto">
                            <table class="rv-tb">
                                <thead><tr><th scope="col">{{ __('Game') }}</th>@if ($hasGoals)<th scope="col" class="is-r sm:max-w-40 sm:truncate"><abbr class="no-underline sm:hidden" title="{{ $match->sideName('challenger') }}">{{ $match->sideTag('challenger') }}</abbr><span class="max-sm:hidden">{{ $match->sideName('challenger') }}</span></th><th scope="col" class="is-r sm:max-w-40 sm:truncate"><abbr class="no-underline sm:hidden" title="{{ $match->sideName('challenged') }}">{{ $match->sideTag('challenged') }}</abbr><span class="max-sm:hidden">{{ $match->sideName('challenged') }}</span></th>@endif<th scope="col">{{ __('Winner') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($games as $index => $game)
                                        <tr data-test="flow-game">
                                            <td class="whitespace-nowrap">{{ __('Game :n', ['n' => $index + 1]) }}</td>
                                            @if ($hasGoals)
                                                <td class="is-r font-bold tabular-nums">{{ $game['challenger'] ?? '–' }}</td>
                                                <td class="is-r font-bold tabular-nums">{{ $game['challenged'] ?? '–' }}</td>
                                            @endif
                                            <td class="[overflow-wrap:anywhere]"><span class="sm:hidden">{{ $match->sideTag((string) $game['winner']) }}</span><span class="max-sm:hidden">{{ $match->sideName((string) $game['winner']) }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                {{-- Who played: the reported roster (or the regulars before a report), each with its player card and npub. --}}
                <section aria-labelledby="played-h" class="rv-card flex flex-col gap-3 p-4 sm:p-6">
                    <h2 id="played-h" class="rv-h3">{{ __('Who played') }}</h2>
                    <ul class="m-0 grid list-none grid-cols-1 gap-x-6 gap-y-1 p-0 md:grid-cols-2" data-test="roster">
                        @foreach ($this->roster as $entry)
                            <li class="grid min-h-10 grid-cols-[minmax(0,1fr)_auto] items-center gap-3 text-[13px]">
                                <span class="flex min-w-0 flex-col"><span class="flex min-w-0 items-center gap-1.5">@if ($entry['user'])<x-player-link :user="$entry['user']" class="shrink-0"><x-avatar :user="$entry['user']" :size="20" class="rounded-sm" /></x-player-link>@endif<span class="truncate"><b>{{ $entry['name'] }}</b> <span class="text-ink-3">{{ \App\Enums\LineupRole::tryFrom($entry['role'])?->label() }}</span></span>@if ($entry['npub'] !== auth()->user()?->npub)<x-copy-npub :npub="$entry['npub']" :name="$entry['name']" />@endif</span><span class="text-[11px] text-ink-3">{{ $match->sideName($entry['side']) }}</span></span>
                                <span class="inline-flex items-center gap-1 text-win">@if ($report)<x-icon name="check" :size="14" />{{ __('played') }}@else<span class="text-ink-3">{{ __('regular') }}</span>@endif</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($toAnswer || ($match->participantSideOf($viewer) !== null && $match->status->isRunning()) || ($match->rated && $match->status->hasResult() && $match->participantSideOf($viewer) !== null))
                <div class="flex flex-wrap items-center gap-3">
                    @if ($toAnswer)
                        <a href="{{ route('matches.room', $match) }}#check" class="rv-b2 h-12 border-loss! text-loss!" data-test="detail-report-problem">{{ __('Report a problem') }}</a>
                        <a href="{{ route('matches.room', $match) }}#check" class="rv-b h-12"><x-icon name="shield-check" :size="18" />{{ __('Accept result') }}</a>
                    @elseif ($match->participantSideOf($viewer) !== null && $match->status->isRunning())
                        <a href="{{ route('matches.room', $match) }}" class="rv-b h-12"><x-icon name="matches" :size="18" />{{ __('Open the match room') }}</a>
                    @else
                        {{-- P11: a rated series can be a rank up or a mined block; the share cards live on one page. --}}
                        <a href="{{ route('settings.badges') }}#share" class="rv-b2 h-12" data-test="match-share"><x-icon name="send" :size="16" />{{ __('Badges and share cards') }}</a>
                    @endif
                </div>
            @endif

            {{-- P46: the winning side posts the win with the series card; renders nothing for anyone else --}}
            @if (! $team && $viewer !== null && $match->status->hasResult() && rescue(fn () => app(\App\Support\Cards\SharePosts::class)->post($viewer, 'series', (string) $match->number), null, false) !== null)
                <div class="flex min-w-0 flex-col gap-2" data-test="series-win-share">
                    <livewire:share-button type="series" :moment="(string) $match->number" :label="__('Post the win')" :wire:key="'series-share-'.$match->number" />
                </div>
            @endif
        </div>

        <aside class="flex min-w-0 flex-col gap-6" aria-label="{{ __('Inputs & Outputs') }}">
            @if ($leagueLobby)
                {{-- The league's lobby, for the directors (to watch) and the admins: name and password in plain mono. --}}
                <section aria-labelledby="league-lobby-h" class="rv-lobby" x-data="{ copied: '' }" data-test="match-league-lobby">
                    <h2 id="league-lobby-h" class="m-0 flex items-center gap-1.5 text-[15px] font-bold"><x-icon name="lock" :size="14" class="text-btc-hi" />{{ __('Lobby, league access') }}</h2>
                    <span class="rv-m text-[13px]">{{ __('Join as a spectator. Only the two players and the tournament directors see this.') }}</span>
                    @foreach (['name' => [__('Name'), $match->lobby_name, __('Copy lobby name')], 'password' => [__('Password'), $match->lobby_password, __('Copy password')]] as $field => [$label, $value, $aria])
                        <div class="flex min-w-0 flex-col gap-1">
                            <span class="rv-m text-[13px]">{{ $label }}</span>
                            <div class="flex min-w-0 gap-2">
                                <b class="rv-cred text-lg!" data-test="match-league-lobby-{{ $field }}">{{ $value }}</b>
                                <button type="button" x-on:click="navigator.clipboard?.writeText(@js((string) $value)); copied = '{{ $field }}'" aria-label="{{ $aria }}" class="rv-b2 rv-cred-copy w-14 px-0">
                                    <span x-show="copied !== '{{ $field }}'"><x-icon name="copy" :size="16" /></span><span x-show="copied === '{{ $field }}'" x-cloak class="text-win"><x-icon name="check" :size="16" /></span>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </section>
            @endif

            <section aria-labelledby="io-h" class="rv-card flex flex-col px-4 py-4 sm:px-6">
                <h2 id="io-h" class="rv-h3 pb-2">{{ __('Inputs & Outputs') }}</h2>
                <dl class="m-0 flex flex-col">
                    @foreach (array_filter([
                        [__('Challenge sent'), SeriesPresenter::time($match->created_at ?? now(), $viewer).($match->answered_at && $match->start_at ? ', '.__('accepted :time', ['time' => SeriesPresenter::time($match->answered_at, $viewer, 'H:i')]) : '')],
                        [__('Played'), $match->start_at ? SeriesPresenter::time($match->start_at, $viewer).($match->finished_at ? ' · '.__('result :time', ['time' => SeriesPresenter::time($match->finished_at, $viewer, 'H:i')]) : '') : __('not yet')],
                        [__('Format'), \App\Support\GameNames::game($match->game).' · '.$match->mode.' · '.($team ? trans_choice(':count board|:count boards', (int) $match->boards) : 'BO'.$match->best_of)],
                        $team
                            ? [__('Lineup lock'), \App\Support\Chess\ChessTeamMatches::lockAt($match) === null ? __('not yet') : SeriesPresenter::time(\App\Support\Chess\ChessTeamMatches::lockAt($match), $viewer)]
                            : [__('Lobby'), \App\Support\Tournaments\LeagueLobby::isLeagueMade($match) ? __('League lobby, hosted by :clan, players and directors only', ['clan' => $match->sideName($match->host_side === 'challenged' ? 'challenged' : 'challenger')]) : __('Hosted by :clan, lineups only', ['clan' => $match->challenger_name])],
                        [__('Match kind'), $match->rated ? __('Rated') : __('Casual')],
                        [__('Elo before'), $elo['before'].($elo['casual'] ? ' · '.__('casual Elo') : '')],
                        [__('At stake'), $elo['stake'].($elo['casual'] ? ' · '.__('casual Elo only, no rank') : '')],
                        [__('League record'), $team ? ($match->rated ? __('one per board, when it ends') : __('none for friendlies')) : ($match->rated ? __('after both captains confirm') : __('none for casual matches'))],
                        \App\Support\Series\SeriesService::isDirectorEntered($match) ? [__('Results'), __('Results are entered by the tournament directors.')] : null,
                    ]) as [$key, $value])
                        <div class="flex flex-col gap-0.5 border-b border-hairline py-2.5 last:border-0">
                            <dt class="rv-m">{{ $key }}</dt>
                            <dd class="m-0 text-[14px] [overflow-wrap:anywhere]">{{ $value }}</dd>
                        </div>
                    @endforeach
                    @unless ($team)
                        @foreach (SeriesMatch::SIDES as $side)
                            <div class="flex flex-col gap-0.5 border-b border-hairline py-2.5 last:border-0">
                                <dt class="rv-m">{{ $match->sideName($side) }}</dt>
                                <dd class="m-0 text-[14px]" data-test="side-elo-{{ $side }}">{{ $elo['sides'][$side] }}</dd>
                            </div>
                        @endforeach
                    @endunless
                </dl>
                @if ($match->resolution_reason)
                    <p class="mt-3 mb-0 rounded-[4px] bg-ground px-3.5 py-2.5 text-xs leading-normal text-ink-2 shadow-ring">{{ __('Admin decision') }}: {{ \App\Support\Series\SeriesPresenter::resolutionReason($match->resolution_reason) }}</p>
                @endif
            </section>

            {{-- P45: the match on Nostr (its challenge), a message to the other side for its players --}}
            <x-nostr-bar :bar="\App\Support\Nostr\NostrBar::match($match)" />
        </aside>
    </div>

    {{-- P47: a tip for the winners (NIP-57), each whose profile has a Lightning address; nothing otherwise --}}
    @if ($match->status->hasResult() && ! $team)
        <livewire:zap-winner type="series" :subject="(string) $match->number" :wire:key="'zap-series-'.$match->number" />
    @endif

    <x-proof toggle="show" class="border-0 bg-proof-fill shadow-[inset_0_0_0_1px_var(--color-proof-ring)]" :rows="SeriesPresenter::proofRows($match)">
        {{ $match->rated ? __('Every step of this series is a Nostr event anyone can check.') : __('A casual match publishes no events: the NIP gives casual games no match-flow events. Rated matches start at Block 0.') }}
    </x-proof>

    {{-- P48: comments and likes on the series' challenge (NIP-22, NIP-25); a casual series has no league event and none --}}
    @if ($match->challenge_event_id !== null)
        <livewire:nostr-comments type="series" :target="(string) $match->number" :key="'comments-series-'.$match->number" />
    @endif
</div>
