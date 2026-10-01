<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\QrCode;
use App\Support\Tournaments\TournamentLanding;
use App\Support\Tournaments\TournamentPrizePool;
use App\Support\Tournaments\TournamentTv;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * The tournament TV (P19): a full-screen 16:9 view of a published
 * tournament for a big screen or a stream, without the site around it.
 * Scenes rotate on their own (resources/js/tournamentTv.js): the bracket,
 * the matches up now, the tables, the pot and who can still win it, and the
 * champion once it is over; before the draw a lobby with the countdown.
 *
 * Live without a reload: TournamentChanged on the public `tournament.{id}`
 * channel asks the server at once, and a poll every 20 seconds catches what
 * a dropped websocket missed. Both call check(), which renders only when the
 * tournament's version moved, so a TV left on for hours re-renders only
 * when something happened. A draft is not public here, not even to its
 * managers (the TV is a public screen); every other status is, as on the
 * tournament page.
 */
new #[Layout('layouts::tv')] class extends Component {
    public Tournament $tournament;

    public string $version = '';

    public function mount(Tournament $tournament): void
    {
        abort_if($tournament->status === TournamentStatus::Draft, 404);
        // A Blockfill week (plan "Blockfill", P6) is a week-long leaderboard without matches: nothing for a big screen.
        abort_if($tournament->isBlockfillWeek(), 404);

        $this->tournament = $tournament;
        $this->version = (new TournamentTv($tournament))->version();
    }

    /**
     * Render again only when something the TV shows has changed.
     */
    public function check(): void
    {
        $this->tournament->refresh();
        $version = (new TournamentTv($this->tournament))->version();

        if ($version === $this->version) {
            $this->skipRender();

            return;
        }

        $this->version = $version;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__(':tournament on TV', ['tournament' => $this->tournament->name]));

        if ($this->tournament->published_at !== null) {
            $meta = app(\App\Support\PageMeta::class)
                ->describe(__(':tournament on TV', ['tournament' => $this->tournament->name]), __(':tournament live on one screen: the bracket, the matches running now and the results.', ['tournament' => $this->tournament->name]))
                ->card(fn () => \App\Support\Cards\PageCard::tournament($this->tournament));
            $meta->noindex = true;
        }
    }
}; ?>

@php
    $tournament = $this->tournament;
    $status = $tournament->status;
    $tv = new TournamentTv($tournament);
    $stages = $tv->stages();
    $current = TournamentTv::currentStage($stages);
    // A lobby tournament (P10): its lobbies are no duels; every live lobby is in the spotlight, on its own grid.
    $lobbyTv = \App\Support\Tournaments\Lobbies::isLobby($tournament);
    // At most 64 players are 8 lobbies; the lobby layout holds 10 (partials/tv-lobbies).
    $spotlight = TournamentTv::spotlight($stages, $lobbyTv ? 10 : 2);
    $tables = $tv->tables($stages);
    $ticker = $tv->ticker();
    $progress = $tv->progress();
    $champion = $status === TournamentStatus::Finished ? $tv->champion() : null;
    $pool = app(TournamentPrizePool::class)->for($tournament);
    $running = $status === TournamentStatus::Running;
    $lobby = in_array($status, [TournamentStatus::Signup, TournamentStatus::Drawing], true);
    $zone = (string) config('esports.preseason.display_timezone');
    $landing = $lobby ? new TournamentLanding($tournament, null) : null;
    $pageUrl = route('tournaments.show', $tournament);
    $shortUrl = preg_replace('#^https?://#', '', $pageUrl);
    $qr = QrCode::svg($pageUrl, label: __('QR code for :url', ['url' => $shortUrl]));
    $gameLine = \App\Support\Tournaments\Lobbies::gameLine($tournament);
    $sats = fn (int $amount): string => \App\Support\Cards\ShareCard::sats($amount);
    $placeLabel = fn (int $place): string => match ($place) { 1 => __('1st place'), 2 => __('2nd place'), 3 => __('3rd place'), default => __(':place. place', ['place' => $place]) };

    // The pot: only places still open. A played third-place match settles places 3 and 4 before the final.
    $settled = [];
    if ($current !== null) {
        foreach (TournamentTv::boxesOf($current) as $box) {
            if ($box['bracket'] === 'third-place' && $box['status'] === 'done') {
                $settled = [3, 4];
            }
        }
    }
    $openPlaces = $pool === null || $status === TournamentStatus::Finished || $status === TournamentStatus::Cancelled
        ? []
        : array_values(array_filter($pool['split'], fn (array $share): bool => ! in_array($share['place'], $settled, true)));
    $contenders = $pool !== null ? $tv->contenders() : [];

    // Scenes in rotation order, with how long each stays (seconds).
    $scenes = [];
    if ($lobby) {
        $scenes['lobby'] = [__('Up next'), 30];
    }
    if ($champion !== null) {
        $scenes['champion'] = [__('Champion'), 25];
    }
    if ($current !== null) {
        $scenes['bracket'] = [$status === TournamentStatus::Finished ? __('Results') : __('Bracket'), 22];
    }
    if ($running && $spotlight !== []) {
        $scenes['spotlight'] = [__('Up now'), 16];
    }
    if ($tables !== []) {
        $scenes['standings'] = [__('Standings'), 18];
    }
    if ($pool !== null && ($openPlaces !== [] || $lobby)) {
        $scenes['pot'] = [__('Prize pool'), 15];
    }
    if ($scenes === []) {
        $scenes['off'] = [$status === TournamentStatus::Cancelled ? __('Called off') : __('Bracket'), 30];
    }
    $first = array_key_first($scenes);

    // Bracket density: the tallest column decides the size of every box of a part.
    $rowsOf = function (array $part): int {
        $rows = 0;
        foreach ($part['sections'] as $section) {
            $rows += max(array_map(fn (array $column): int => count($column['matches']), $section['columns']) ?: [1]);
        }

        return max(1, $rows);
    };
@endphp

<div class="tv" x-data="tournamentTv({ id: {{ $tournament->id }}, hold: 8 })" data-test="tv" data-status="{{ $status->value }}" data-version="{{ $this->version }}">
    <div class="tv-stage" x-ref="stage" wire:ignore.self data-scene="{{ $first }}" data-cycle="a" data-renew-after="400" style="--dwell: {{ $scenes[$first][1] }}s">
        {{-- Who plays what, and where to follow it on a phone --}}
        <header class="tv-head">
            <x-game-cover :game="$tournament->game" size="thumb" loading="eager" class="tv-head-cover" />
            <div class="tv-head-title">
                <span class="tv-fit" style="--chars: {{ max(8, mb_strlen($tournament->name)) }}"><h1 class="tv-name">{{ $tournament->name }}</h1></span>
                <p class="tv-meta">
                    <span>{{ $gameLine }}</span>
                    <span>{{ \App\Support\Tournaments\Lobbies::formatLabel($tournament) }}</span>
                    @if ($running)
                        <span class="tv-status is-live" data-test="tv-live"><span class="tv-dot"></span>{{ __('Live') }}</span>
                        <span data-test="tv-progress">{{ __(':played of :total matches played', $progress) }}</span>
                    @else
                        <span class="tv-status">{{ $status->label() }}</span>
                    @endif
                </p>
            </div>
            <figure class="tv-qr" data-test="tv-qr">
                <span class="tv-qr-code">{!! $qr !!}</span>
                <figcaption>
                    <span class="tv-qr-lead">{{ $lobby ? __('Scan to sign up') : __('Follow on your phone') }}</span>
                    {{-- Breaks only after a slash, never inside a word. --}}
                    <span class="tv-qr-url">{!! implode('/<wbr>', array_map('e', explode('/', $shortUrl))) !!}</span>
                </figcaption>
            </figure>
        </header>

        <div class="tv-scenes">
            @if (isset($scenes['lobby']))
                <section class="tv-scene tv-lobby" data-scene-id="lobby" data-dwell="{{ $scenes['lobby'][1] }}" wire:key="scene-lobby" aria-label="{{ $scenes['lobby'][0] }}">
                    @php($places = $landing->places())
                    @php($countdown = $landing->countdown($zone))
                    @php($roster = $landing->roster())
                    <x-game-cover :game="$tournament->game" size="hero" loading="eager" class="tv-lobby-cover" />
                    <div class="tv-lobby-body">
                        @if ($countdown)
                            <p class="tv-lobby-when">
                                <span class="tv-kicker">{{ $countdown['label'] }}</span>
                                <span class="tv-countdown" role="timer" x-data="countdown({ at: {{ $countdown['ms'] }}, days: @js(__(':count day|:count days')) })" x-text="text">{{ $countdown['text'] }}</span>
                            </p>
                        @elseif ($status === TournamentStatus::Drawing)
                            <p class="tv-lobby-when"><span class="tv-kicker">{{ __('The draw waits for Bitcoin block :height.', ['height' => $tournament->draw_height]) }}</span></p>
                        @endif
                        <p class="tv-lobby-count"><b>{{ $places['taken'] }}</b> {{ __('of :places spots taken', ['places' => $places['places']]) }}</p>
                        <ul class="tv-lobby-faces" data-test="tv-lobby-faces">
                            @foreach (array_slice($roster, 0, 12) as $row)
                                <li wire:key="lobby-{{ $row['key'] }}">
                                    @if ($row['kind'] === 'lineup' && $row['clan'])
                                        <x-clan-tag :clan="$row['clan']" :tile="160" class="tv-face is-clan" />
                                    @elseif (isset($row['users'][0]))
                                        <x-avatar :user="$row['users'][0]" :size="160" class="tv-face rounded-[18%]" />
                                    @endif
                                    <span>{{ $row['name'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </section>
            @endif

            @if (isset($scenes['champion']))
                <section class="tv-scene tv-champion" data-scene-id="champion" data-dwell="{{ $scenes['champion'][1] }}" wire:key="scene-champion" aria-label="{{ $scenes['champion'][0] }}" data-test="tv-champion">
                    <div class="tv-confetti" aria-hidden="true">
                        @for ($piece = 0; $piece < 36; $piece++)
                            <i style="--x: {{ ($piece * 37) % 100 }}; --d: {{ ($piece * 7) % 23 / 10 }}s; --t: {{ 4 + ($piece * 5) % 4 }}s; --r: {{ ($piece * 53) % 360 }}deg"></i>
                        @endfor
                    </div>
                    @include('pages.tournaments.partials.tv-face', ['entry' => $champion, 'class' => 'tv-champion-face'])
                    <p class="tv-champion-kicker"><x-icon name="trophy" :size="24" />{{ __('Champion of :tournament', ['tournament' => $tournament->name]) }}</p>
                    <span class="tv-fit tv-champion-fit" style="--chars: {{ max(4, mb_strlen($champion['name'])) }}"><h2 class="tv-champion-name">{{ $champion['name'] }}</h2></span>
                    <p class="tv-champion-meta">
                        @if ($champion['tag'])
                            <x-clan-tag :clan="$champion['clan']" :tag="$champion['tag']" />
                        @endif
                        @if ($champion['seed'] !== null)
                            <span>{{ __('Seed :seed', ['seed' => $champion['seed']]) }}</span>
                        @endif
                        @if ($pool !== null && isset($pool['split'][0]) && $pool['split'][0]['place'] === 1)
                            <span class="tv-champion-prize">{{ __('wins :sats sats', ['sats' => $sats($pool['split'][0]['sats'])]) }}</span>
                        @endif
                    </p>
                    @if (count($champion['users']) > 1)
                        <ul class="tv-champion-team">
                            @foreach ($champion['users'] as $member)
                                <li wire:key="champ-{{ $member->id }}"><x-avatar :user="$member" :size="96" class="tv-face rounded-[18%]" /><span>{{ $member->displayName() }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if (isset($scenes['bracket']))
                <section class="tv-scene tv-bracket" data-scene-id="bracket" data-dwell="{{ $scenes['bracket'][1] }}" wire:key="scene-bracket" aria-label="{{ $scenes['bracket'][0] }}" data-test="tv-bracket">
                    @if (count($stages) > 1)
                        <h2 class="tv-scene-title">{{ $current['title'] }}</h2>
                    @endif
                    <div @class(['tv-parts', 'is-split' => count($current['parts']) > 1])>
                        @foreach ($current['parts'] as $partIndex => $part)
                            <div class="tv-part" wire:key="part-{{ $current['number'] }}-{{ $partIndex }}">
                                @if ($part['title'])
                                    <h3 class="tv-part-title">{{ $part['title'] }}</h3>
                                @endif
                                @if ($part['kind'] === 'bracket')
                                    <div class="tv-sections" style="--rows: {{ $rowsOf($part) }}">
                                        @foreach ($part['sections'] as $sectionIndex => $section)
                                            @php($sectionRows = max(array_map(fn (array $column): int => count($column['matches']), $section['columns']) ?: [1]))
                                            <div class="tv-section" style="--grow: {{ $sectionRows }}" wire:key="section-{{ $partIndex }}-{{ $sectionIndex }}" data-lines-host>
                                                <svg class="tv-lines" aria-hidden="true" data-lines wire:ignore></svg>
                                                @if ($section['title'])
                                                    <h4 class="tv-section-title">{{ $section['title'] }}</h4>
                                                @endif
                                                <div class="tv-cols">
                                                    @foreach ($section['columns'] as $column)
                                                        <div class="tv-col">
                                                            <span class="tv-col-label">{{ $column['label'] }}</span>
                                                            <div class="tv-col-boxes">
                                                                @foreach ($column['matches'] as $box)
                                                                    @include('pages.tournaments.partials.tv-box', ['box' => $box])
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif ($part['kind'] === 'table')
                                    @php($round = TournamentTv::tableRound($part))
                                    @if ($round !== null)
                                        <h4 class="tv-section-title">{{ __('Round :round', ['round' => $round['number']]) }}</h4>
                                        <div class="tv-grid" style="--rows: {{ max(1, (int) ceil(count($round['boxes']) / 2)) }}">
                                            @foreach ($round['boxes'] as $box)
                                                @if ($box['bracket'] === 'bye')
                                                    <p class="tv-bye" wire:key="tv-bye-{{ $box['key'] }}">{{ __(':name has a bye this round.', ['name' => $box['sides'][0]['name'] ?? '']) }}</p>
                                                @else
                                                    @include('pages.tournaments.partials.tv-box', ['box' => $box])
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                @elseif ($lobbyTv)
                                    @include('pages.tournaments.partials.tv-lobbies', ['boxes' => $part['heats']])
                                @else
                                    <div class="tv-grid" style="--rows: {{ max(1, (int) ceil(count($part['heats']) / 2)) }}">
                                        @foreach ($part['heats'] as $box)
                                            @include('pages.tournaments.partials.tv-box', ['box' => $box])
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if (isset($scenes['spotlight']))
                <section class="tv-scene tv-spotlight" data-scene-id="spotlight" data-dwell="{{ $scenes['spotlight'][1] }}" wire:key="scene-spotlight" aria-label="{{ $scenes['spotlight'][0] }}" data-test="tv-spotlight">
                    <x-game-cover :game="$tournament->game" size="hero" class="tv-spotlight-cover" />
                    @if ($lobbyTv)
                        @include('pages.tournaments.partials.tv-lobbies', ['boxes' => $spotlight])
                    @endif
                    @foreach ($lobbyTv ? [] : $spotlight as $duel)
                        <article @class(['tv-duel', 'is-solo' => count($spotlight) === 1]) wire:key="duel-{{ $duel['key'] }}" data-key="{{ $duel['key'] }}">
                            <p class="tv-duel-round"><span class="tv-status is-live"><span class="tv-dot"></span>{{ __('Live') }}</span><span>{{ $duel['round'] }}</span></p>
                            <div class="tv-duel-sides">
                                @foreach ($duel['sides'] as $index => $side)
                                    <div class="tv-duel-side" data-side="{{ $index }}">
                                        @include('pages.tournaments.partials.tv-face', ['entry' => $side['entry']])
                                        <span class="tv-fit" style="--chars: {{ max(4, mb_strlen($side['name'])) }}"><span class="tv-duel-name">{{ $side['name'] }}</span></span>
                                        <span class="tv-duel-meta">
                                            @if ($side['tag'])
                                                <x-clan-tag :clan="$side['clan']" :tag="$side['tag']" />
                                            @endif
                                            @if (($side['entry']['seed'] ?? null) !== null)
                                                <span>{{ __('Seed :seed', ['seed' => $side['entry']['seed']]) }}</span>
                                            @endif
                                        </span>
                                    </div>
                                    @if ($index === 0)
                                        <span class="tv-duel-vs">{{ __('vs') }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </section>
            @endif

            @if (isset($scenes['standings']))
                <section class="tv-scene tv-standings" data-scene-id="standings" data-dwell="{{ $scenes['standings'][1] }}" wire:key="scene-standings" aria-label="{{ $scenes['standings'][0] }}" data-test="tv-standings">
                    <div class="tv-tables" style="--tables: {{ min(count($tables), 4) }}">
                        @foreach (array_slice($tables, 0, 4) as $tableIndex => $table)
                            <div class="tv-table" wire:key="table-{{ $tableIndex }}">
                                <h3 class="tv-part-title">{{ $table['title'] }}</h3>
                                <ol class="tv-rows" style="--rows: {{ max(1, count($table['rows'])) }}">
                                    @foreach ($table['rows'] as $row)
                                        <li @class(['tv-row', 'is-through' => $table['advance'] !== null && $row['rank'] <= $table['advance']]) wire:key="row-{{ $tableIndex }}-{{ $loop->index }}">
                                            <span class="tv-row-rank">{{ $row['rank'] }}</span>
                                            @include('pages.tournaments.partials.tv-face', ['entry' => $row['participant']])
                                            <span class="tv-row-name">{{ $row['name'] }}</span>
                                            @if ($table['advance'] !== null && $row['rank'] <= $table['advance'])
                                                <span class="tv-row-through"><x-icon name="chevron-up" :size="16" />{{ __('Through') }}</span>
                                            @endif
                                            <span class="tv-row-record">{{ $row['wins'] }}–{{ $row['ties'] }}–{{ $row['losses'] }}</span>
                                            <span class="tv-row-points">{{ $row['points'] }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                                <p class="tv-table-legend">{{ __('Wins, draws, losses and points') }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if (isset($scenes['pot']))
                <section class="tv-scene tv-pot" data-scene-id="pot" data-dwell="{{ $scenes['pot'][1] }}" wire:key="scene-pot" aria-label="{{ $scenes['pot'][0] }}" data-test="tv-pot">
                    <div class="tv-pot-sum">
                        <span class="tv-kicker">{{ __('In the pot') }}</span>
                        <span class="tv-pot-sats" data-test="tv-pot-sats">{{ $sats($pool['sats']) }}</span>
                        <span class="tv-pot-unit">{{ __('sats') }}</span>
                    </div>
                    <div class="tv-pot-side">
                        @if ($openPlaces !== [])
                            <ol class="tv-pot-places">
                                @foreach ($openPlaces as $share)
                                    <li wire:key="pot-{{ $share['place'] }}">
                                        <span>{{ $placeLabel($share['place']) }}</span>
                                        <b>{{ __(':sats sats', ['sats' => $sats($share['sats'])]) }}</b>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                        @if ($contenders !== [])
                            <p class="tv-kicker">{{ trans_choice(':count entry can still win it|:count entries can still win it', count($contenders)) }}</p>
                            <ul class="tv-pot-contenders" data-test="tv-contenders">
                                @foreach (array_slice($contenders, 0, 8) as $entry)
                                    <li wire:key="hunt-{{ $entry['id'] }}">
                                        @include('pages.tournaments.partials.tv-face', ['entry' => $entry])
                                        <span>{{ $entry['name'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>
            @endif

            @if (isset($scenes['off']))
                <section class="tv-scene tv-off" data-scene-id="off" data-dwell="{{ $scenes['off'][1] }}" wire:key="scene-off" aria-label="{{ $scenes['off'][0] }}">
                    <p>{{ $status === TournamentStatus::Cancelled ? __('The tournament was called off.') : __('The bracket appears here once it is drawn.') }}</p>
                </section>
            @endif
        </div>

        <footer class="tv-foot">
            <div class="tv-ticker" data-test="tv-ticker">
                <span class="tv-ticker-lead">{{ __('Latest') }}</span>
                @if ($ticker === [])
                    <span class="tv-ticker-empty">{{ match (true) {
                        $running => __('Results appear here as they come in.'),
                        $lobby => __('Round 1 starts :date.', ['date' => $tournament->starts_at->copy()->timezone($zone)->locale(app()->getLocale())->translatedFormat('D Y-m-d H:i')]),
                        default => __('No results yet.'),
                    } }}</span>
                @else
                    <div class="tv-ticker-track" style="--items: {{ count($ticker) }}">
                        @foreach ([0, 1] as $copy)
                            <ul class="tv-ticker-list" @if ($copy === 1) aria-hidden="true" @endif>
                                @foreach ($ticker as $item)
                                    <li wire:key="tick-{{ $copy }}-{{ $item['key'] }}" @if ($copy === 0) data-test="tv-tick" @endif>
                                        @if ($item['draw'])
                                            {{ __(':a and :b draw :score', ['a' => $item['winner'], 'b' => $item['loser'] ?? '', 'score' => $item['label'] ?? '']) }}
                                        @else
                                            <b>{{ $item['winner'] }}</b> {{ $item['loser'] !== null ? __('beats :loser :score', ['loser' => $item['loser'], 'score' => $item['label'] ?? '']) : __('wins :score', ['score' => $item['label'] ?? '']) }}
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endforeach
                    </div>
                @endif
            </div>
            <nav class="tv-tabs" aria-label="{{ __('Scenes') }}">
                @foreach ($scenes as $id => [$label, $dwell])
                    <button type="button" class="tv-tab" data-tab="{{ $id }}" wire:key="tab-{{ $id }}" x-on:click="show(@js($id), true)">
                        <span>{{ $label }}</span><i class="tv-tab-bar"></i>
                    </button>
                @endforeach
            </nav>
            {{-- Over the ticker, inside the footer row: while someone is at the screen it never covers a scene. --}}
            <div class="tv-hint" data-test="tv-hint">
                <button type="button" class="tv-hint-button" x-on:click="fullscreen()">{{ __('Full screen') }}</button>
                <span>{{ __('Press F for full screen, arrows switch scenes, space pauses.') }}</span>
            </div>
        </footer>
    </div>
</div>
