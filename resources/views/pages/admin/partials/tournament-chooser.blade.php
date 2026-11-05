@php
    use App\Enums\TournamentFormat;
    use App\Enums\TournamentResultsMode;
    use App\Games\GameRegistry;
    use App\Support\GameNames;
    use App\Support\Tournaments\Estimator;
    use App\Support\Tournaments\FormatCopy;
    use App\Support\Tournaments\FormatOptions;
    use App\Support\Tournaments\GameProfile;
    use App\Support\Tournaments\Lobbies;
    use App\Support\Tournaments\Preview;

    /*
     * The format chooser and "Who enters results" (AdminTournamentCreate.dc.html,
     * the `renderVals` of its logic script). Rendered inside the `chooser`
     * island of pages::admin.tournament-create and pages::admin.tournament-edit
     * (state and actions: App\Livewire\TournamentFormatChooser).
     */
    $profile = $this->profile();
    $evaluation = $this->evaluation;
    $options = $evaluation->options;
    $format = $this->format;
    $n = $this->count();
    $window = (float) $this->window;
    $daily = $profile->isDaily();
    $series = $profile->what === 'series';
    $teams = $profile->entersTeams();
    $site = $this->stationLimit() !== null;
    $duration = fn (float $value): string => Estimator::format($value, $profile);
    $who = fn (int $count): string => $teams ? trans_choice(':count team|:count teams', $count) : trans_choice(':count player|:count players', $count);
    $gamesCount = fn (int $count): string => trans_choice(':count game|:count games', $count);
    // A lobby game (P10, Lobbies): its tournaments are one lobby match, Free for All is the only format.
    $lobbyGame = Lobbies::isLobbyGame($profile->game);
    $lobbyFormat = $lobbyGame && $format === TournamentFormat::FreeForAll;
    $lobbySizes = $lobbyGame ? Lobbies::split($profile->game, $n) : [];

    // The time axis of the bars: 30 % past the window, ticks every 30 or 60 min (daily: every month).
    $axis = $window * 1.3;
    $step = $daily ? 30 : ($window <= 120 ? 30 : 60);
    $ticks = [];

    for ($tick = $step; $tick < $axis; $tick += $step) {
        $ticks[] = [
            'pct' => round($tick / $axis * 100, 2),
            'label' => $daily ? __(':count mo', ['count' => $tick / 30]) : ($tick % 60 !== 0 ? (intdiv($tick, 60) > 0 ? __(':hours h 30', ['hours' => intdiv($tick, 60)]) : __('30 min')) : __(':hours h', ['hours' => intdiv($tick, 60)])),
        ];
    }

    $windowPct = round($window / $axis * 100, 2);
    $rows = [];

    foreach ($evaluation->rows as $row) {
        $copy = FormatCopy::for($row->format);

        if (! $row->enabled) {
            $rows[] = ['row' => $row, 'copy' => $copy];

            continue;
        }

        $x = 0.0;
        $blocks = [];
        $clipped = false;
        $selectedRow = $row->format === $format;

        foreach ($row->duration->blocks as $index => $block) {
            $start = $x + ($index > 0 ? $profile->break : 0);
            $end = $start + $block['t'];

            if ($start >= $axis) {
                $clipped = true;

                break;
            }

            $over = $end > $window;
            $blocks[] = [
                'w' => round(max(0.6, (min($end, $axis) - $start) / $axis * 100 - 0.35), 2),
                'gap' => round($index > 0 ? max(0.35, $profile->break / $axis * 100) : 0, 2),
                'tone' => $over ? 'over' : ($selectedRow ? 'selected' : 'plain'),
                'ifNeeded' => $block['ifNeeded'],
                'tip' => ($block['merged'] > 0 ? __('All :count rounds at once', ['count' => $block['merged']]) : __('Round :number', ['number' => $index + 1]))
                    .', '.$duration($block['t'])
                    .($block['waves'] > 1 ? ', '.__(':count waves', ['count' => $block['waves']]) : '')
                    .($block['ifNeeded'] ? ', '.__('only if needed') : ''),
            ];
            $x = $end;
        }

        $diff = $row->total() - $window;
        $fitText = match (true) {
            $row->heavy => __(':count games at once each', ['count' => $row->atOnce]),
            $diff <= 0 && -$diff < 1 => __('fits exactly'),
            $diff <= 0 => __(':duration to spare', ['duration' => $duration(-$diff)]),
            default => __(':duration too long', ['duration' => $duration($diff)]),
        };

        $rows[] = ['row' => $row, 'copy' => $copy, 'blocks' => $blocks, 'clipped' => $clipped, 'fitText' => $fitText, 'fits' => $diff <= 0 && ! $row->heavy,
            'selected' => $selectedRow, 'recommended' => $row->format === $evaluation->recommended];
    }

    $recommended = $evaluation->recommended !== null ? $evaluation->row($evaluation->recommended) : null;
    $recommendedName = $recommended === null ? '' : $recommended->format->label().($recommended->format === TournamentFormat::Swiss ? ', '.trans_choice(':count round|:count rounds', $evaluation->swissRounds) : '');
    $lever = $daily ? __('Allow more time or pick fewer rounds.')
        : ($series ? ($site ? __('Allow more time, add stations or play shorter series.') : __('Allow more time or play shorter series.'))
        : ($site ? __('Allow more time or add boards.') : __('Allow more time.')));
    $recommendedWhy = $recommended === null ? '' : ($lobbyGame
        // A lobby game (P10): one match for everyone, no final, nobody to find; the time is the lobby's.
        ? __('One lobby match for everyone, all lobbies at the same time: up to :limit of play with its Time Limit, about :duration with filling the lobby. No final, no opponent to find.', ['limit' => \App\Support\Pages\RulesPage::minutes(Lobbies::timeLimit($profile->game)), 'duration' => $duration($recommended->total())])
            .($evaluation->nothingFits ? ' '.__('Allow at least :duration.', ['duration' => $duration($recommended->total())]) : '')
        : ($evaluation->nothingFits
        ? __('Nothing fits into :window. This is the shortest: :duration.', ['window' => $duration($window), 'duration' => $duration($recommended->total())]).' '.$lever
        : ($profile->isSeries() && $recommended->format->hasFinal()
            // Series games recommend a format with a final first (user, 2026-09-26, for Rocket League), so the reason names it.
            ? ($teams
                ? __('Ends with a final; every team plays at least :games, and it takes about :duration of your :window.', ['games' => $gamesCount($recommended->guaranteedGames()), 'duration' => $duration($recommended->total()), 'window' => $duration($window)])
                : __('Ends with a final; every player plays at least :games, and it takes about :duration of your :window.', ['games' => $gamesCount($recommended->guaranteedGames()), 'duration' => $duration($recommended->total()), 'window' => $duration($window)]))
            : __('Everyone gets at least :games, and it takes about :duration of your :window.', ['games' => $gamesCount($recommended->guaranteedGames()), 'duration' => $duration($recommended->total()), 'window' => $duration($window)]))));

    $chosen = $evaluation->row($format);
    $structure = $chosen->structure;
    $plan = $chosen->duration;
    $copy = FormatCopy::for($format, $options->groupStage);
    $rounds = $evaluation->swissRounds;
    $odd = $n % 2 === 1;

    // Blockli plays two games a match (one per pairing in a table, by choice): the counts read in games, and say what a
    // pairing is (DerCaddy, 2026-11-04: "mindestens 9 Spiele" and "Alle spielen 9 Partien" for 18 games, 6 against each).
    $perMatch = $chosen->perMatch;
    $guaranteed = match ($format) {
        TournamentFormat::Swiss => $perMatch > 1
            ? ($odd
                ? __('Everyone plays :fewer or :rounds pairings of :per games each, so :fewerGames or :games games. With an odd number, one player per round gets a free win (bye).', ['fewer' => $rounds - 1, 'rounds' => $rounds, 'per' => $perMatch, 'fewerGames' => ($rounds - 1) * $perMatch, 'games' => $rounds * $perMatch])
                : __('Everyone plays :rounds pairings of :per games each, so :games games.', ['rounds' => $rounds, 'per' => $perMatch, 'games' => $rounds * $perMatch]))
            : ($odd ? __('Everyone plays :fewer or :rounds games. With an odd number, one player per round gets a free win (bye).', ['fewer' => $rounds - 1, 'rounds' => $rounds]) : __('Everyone plays :rounds games.', ['rounds' => $rounds])),
        TournamentFormat::RoundRobin => ($perMatch > 1
                ? __('Everyone plays :games games: :pairings pairings of :per games each, :against against each opponent.', ['games' => $chosen->guaranteedGames(), 'pairings' => $structure?->guaranteed ?? 0, 'per' => $perMatch, 'against' => $options->iterations * $perMatch])
                : __('Everyone plays :games games.', ['games' => $structure?->guaranteed ?? 0]))
            .($chosen->atOnce > 0 ? ' '.__('In daily chess all of them start at once: :count games running at the same time for each player.', ['count' => $chosen->atOnce]) : ''),
        TournamentFormat::TwoStage => trans_choice('Everyone plays at least :games game in the groups. The top :advance of each group play on.|Everyone plays at least :games games in the groups. The top :advance of each group play on.', $chosen->guaranteedGames(), ['games' => $chosen->guaranteedGames(), 'advance' => $structure?->advance ?? 0]),
        TournamentFormat::SingleElimination => __('The weakest may play only 1 match. The winner plays :rounds.', ['rounds' => $structure?->max ?? 0]).(($structure?->byes ?? 0) > 0 ? ' '.__(':byes of :n skip round 1 (bye), because :n does not fill the bracket.', ['byes' => $structure?->byes, 'n' => $n]) : ''),
        TournamentFormat::FreeForAll => __('Everyone plays exactly 1 match: their lobby\'s game.'),
        default => ($structure?->guaranteed ?? 2) < 2
            ? __('The lowest seeds may play only 1 match, everyone else at least 2. The winner plays up to :max.', ['max' => $structure?->max ?? 0])
            : __('Everyone plays at least 2 matches. The winner plays up to :max.', ['max' => $structure?->max ?? 0]),
    };
    $splitFrom = intdiv(1 << Estimator::log2($n), 2) + 1;

    $firstBlock = $plan?->blocks[0] ?? null;
    $roundsText = $firstBlock !== null && $firstBlock['merged'] > 0
        ? (count($plan->blocks) === 1 ? __('all at once') : __('groups at once, then :count', ['count' => count($plan->blocks) - 1]))
        : (string) count($plan?->blocks ?? []);
    $facts = [
        [__('Rounds'), $roundsText],
        [__('Matches'), $format === TournamentFormat::DoubleElimination && $options->grandFinal === 'reset' ? __(':fewer or :matches', ['fewer' => ($structure?->matches ?? 1) - 1, 'matches' => $structure?->matches ?? 0]) : (string) ($structure?->matches ?? 0)],
        [$teams ? __('Games per team') : __('Games per player'), $structure !== null && $structure->min === $structure->max ? (string) $chosen->games($structure->min) : __(':min to :max', ['min' => $chosen->games($structure?->min ?? 0), 'max' => $chosen->maxGames()])],
        [__('Guaranteed'), (string) $chosen->guaranteedGames()],
    ];

    $caption = match ($format) {
        TournamentFormat::Swiss => __(':who, :rounds rounds. After round 1, pairs form by score, so the columns split into score groups.', ['who' => $who($n), 'rounds' => $rounds]),
        TournamentFormat::RoundRobin => __(':who. One square per match, lit in the round it is played.', ['who' => $who($n)]),
        TournamentFormat::TwoStage => __(':who, :groups groups of :sizes, then :finalists in the final stage.', ['who' => $who($n), 'groups' => count($structure?->groups ?? []), 'sizes' => implode(', ', $structure?->groups ?? []), 'finalists' => $structure?->finalists ?? 0]),
        TournamentFormat::SingleElimination => ($structure?->byes ?? 0) > 0 ? __(':who, :byes dashed boxes are byes.', ['who' => $who($n), 'byes' => $structure?->byes]) : $who($n).'.',
        TournamentFormat::FreeForAll => trans_choice(':who in :count lobby: :sizes.|:who in :count lobbies: :sizes.', count($lobbySizes), ['who' => $who($n), 'sizes' => implode(' + ', $lobbySizes)]),
        default => __(':who, upper and lower bracket.', ['who' => $who($n)]),
    };

    $previewDesktop = Preview::for($format, $n, $options->withSwissRounds($rounds), 480, 208);
    $previewMobile = Preview::for($format, $n, $options->withSwissRounds($rounds), 326, 196);
    $previewKey = md5(json_encode([$format->value, $n, $options->toArray(), $rounds]));

    $assumption = $lobbyFormat
        ? __('One lobby game runs to its Time Limit of :limit, plus :setup min to fill the lobby. All lobbies play at the same time.', ['limit' => \App\Support\Pages\RulesPage::minutes(Lobbies::timeLimit($profile->game)), 'setup' => Lobbies::plannedMinutes($profile->game) - Lobbies::timeLimit($profile->game)])
        : ($daily
        ? __('A daily chess game is planned at :days days, 1 move a day. :break day between rounds.', ['days' => $profile->gameLength + 0, 'break' => $profile->break + 0])
        : ($series
            ? __('One :game game: about :minutes min, plus :setup min to set up each series. A Bo:best series is planned at :slot min (all games played). :break min between rounds.', ['game' => GameNames::game($profile->game), 'minutes' => $profile->gameLength + 0, 'setup' => $profile->setup + 0, 'best' => $options->bestOf, 'slot' => $profile->slot($options->bestOf) + 0, 'break' => $profile->break + 0])
            : __('One :mode game: up to :minutes min, including pairing. :break min between rounds.', ['mode' => GameNames::mode($profile->game, $profile->mode), 'minutes' => $profile->gameLength + 0, 'break' => $profile->break + 0])));

    $tieBreakLabels = [
        'median-buchholz' => __('Buchholz, median'),
        'head-to-head' => __('Head-to-head'),
        'match-wins' => __('Matches won'),
        'game-wins' => __('Games won'),
        'game-win-percentage' => __('Game win percentage'),
        'game-difference' => __('Game difference'),
        'points-scored' => __('Points scored'),
        'points-difference' => __('Points difference'),
    ];
    // Control and help side by side only where the options section is wide enough for both (a container query: the
    // section's width decides, not the viewport's); narrower, the help sits under its control. 280 px for the help:
    // at 200 it ran 8–10 lines (design-lead, 601–628 px sections at 1024 and 1920).
    // The editor's layout follows the width its column really has (the admin page's `admin` container: 720 px at 1024, 976 at
    // 1280, 1136 at 1440, 1616 at 1920), not the viewport, which the 240 px sidebar takes from (E1, 2026-10-20: facts in 53 px,
    // the systems' tick labels on top of each other, help in 156 px boxes at 1024 and 1280). From 1400 px the systems and their explanation stand side
    // by side; below, the systems take the whole width and, from 900 px, options and explanation share the row under them.
    $optionRow = 'grid gap-2 border-t border-hairline py-3 @[560px]:grid-cols-[minmax(0,1fr)_280px] @[560px]:gap-4';
    $help = 'text-xs leading-normal text-ink-2';
@endphp

<div class="flex flex-col gap-4" data-test="tournament-chooser" data-format="{{ $format->value }}" data-recommended="{{ $evaluation->recommended?->value }}">
    <section aria-labelledby="fmt-h" class="flex flex-col gap-5 rounded border border-hairline bg-card p-4 lg:p-6">
        <div class="flex flex-col gap-1.5 @min-[900px]/admin:flex-row @min-[900px]/admin:items-baseline @min-[900px]/admin:gap-4">
            <h2 id="fmt-h" class="m-0 shrink-0 font-display text-lg font-bold lg:text-xl">{{ __('Pick a format') }}</h2>
            <span class="text-[13px] leading-normal text-ink-2">{{ __('Tell us who plays and how much time you have. We compare every system for you.') }}</span>
        </div>

        {{-- Inputs: game, participants, time, where --}}
        {{-- The game list needs about 540 px (a name of two lines and three modes in one row): four columns from 1400 px, below
             it the whole first row and the other three inputs under it. --}}
        <div class="grid items-start gap-5 @min-[640px]/admin:grid-cols-[160px_minmax(0,1fr)_minmax(0,1.2fr)] @min-[640px]/admin:gap-6 @min-[1400px]/admin:grid-cols-[minmax(0,1.6fr)_minmax(0,0.6fr)_minmax(0,1.2fr)_minmax(0,1fr)]">
            <span class="flex max-w-[720px] flex-col gap-1.5 @min-[640px]/admin:col-span-3 @min-[1400px]/admin:col-span-1">
                <span id="game-l" class="text-xs text-ink-2">{{ __('Game, mode') }}</span>
                {{-- One row per registered game with a tournament profile (TournamentGames): its cover, its name and one button per mode. --}}
                @foreach (\App\Support\Tournaments\TournamentGames::grouped($this->game) as $gameRow)
                    {{-- Phones: the name over its modes; wider, beside them in 200 px (two lines for "Age of Empires II: Definitive Edition"). --}}
                    <span class="flex flex-col items-stretch gap-1.5 @min-[480px]/admin:flex-row @min-[480px]/admin:items-center @min-[480px]/admin:gap-2" wire:key="game-row-{{ $gameRow['slug'] }}" data-test="game-row-{{ $gameRow['slug'] }}">
                        <span class="flex shrink-0 items-center gap-2 text-xs text-ink-3 @min-[480px]/admin:w-[200px]">
                            <x-game-cover :game="$gameRow['slug']" size="thumb" class="w-10 rounded-xs" />
                            <span class="min-w-0 leading-tight">{{ GameNames::game($gameRow['slug']) }}</span>
                        </span>
                        <x-tournaments.segmented labelledby="game-l" method="pickGame" :current="$this->game" class="grow" data-test="game-{{ $gameRow['slug'] }}"
                            :options="$gameRow['options']" />
                    </span>
                @endforeach
            </span>

            <span class="flex flex-col gap-1.5">
                <label for="n-in" class="text-xs text-ink-2">{{ $teams ? __('Teams') : __('Players') }}</label>
                <x-tournaments.stepper id="n-in" model="players" :value="$n" decrement="stepPlayers(-1)" increment="stepPlayers(1)" class="self-start"
                    :less="$teams ? __('One team less') : __('One player less')" :more="$teams ? __('One team more') : __('One player more')" />
                <span class="text-xs text-ink-3">{{ __('How many you expect. 2 to 64.') }}</span>
            </span>

            <span class="flex flex-col gap-1.5">
                <span id="win-l" class="text-xs text-ink-2">{{ __('Time you have') }}</span>
                <x-tournaments.segmented labelledby="win-l" method="pickWindow" :current="$this->window" :options="$this->windows()" data-test="window" />
            </span>

            <span class="flex flex-col gap-1.5">
                <span id="where-l" class="text-xs text-ink-2">{{ __('Where') }}</span>
                @if ($daily)
                    <span class="text-xs leading-normal text-ink-3">{{ __('Daily chess is always played online, so boards don’t limit it.') }}</span>
                @else
                    <x-tournaments.segmented labelledby="where-l" method="pickWhere" :current="$site ? 'site' : 'online'" :options="[['online', __('Online')], ['site', __('On site')]]" />
                    @if ($site)
                        <span class="flex flex-wrap items-center gap-2.5">
                            <span class="text-xs text-ink-2">{{ $series ? __('Stations') : __('Boards') }}</span>
                            <x-tournaments.stepper :value="$this->stations" decrement="stepStations(-1)" increment="stepStations(1)" :less="__('One less')" :more="__('One more')" />
                        </span>
                        <span class="text-xs leading-normal text-ink-3">{{ $series ? __('Matches that can run at the same time: one station = one setup per team.') : __('Games that can run at the same time.') }}</span>
                    @else
                        <span class="text-xs leading-normal text-ink-3">{{ __('All matches of a round run at the same time.') }}</span>
                    @endif
                @endif
            </span>
        </div>

        {{-- The planning values --}}
        <details class="rounded-md border border-hairline bg-ground">
            {{-- "Change times" goes under the assumption where both do not fit (the sentence ran 4 lines in 184 px at 390). --}}
            <summary class="flex min-h-11 cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-xs leading-normal text-ink-2 [&::-webkit-details-marker]:hidden">
                <span class="min-w-0 grow basis-[18rem]">{{ $assumption }}</span>
                <span class="shrink-0 text-btc">{{ __('Change times') }}</span>
            </summary>
            <div class="flex flex-wrap items-end gap-4 border-t border-hairline px-4 py-3">
                <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                    {{ $daily ? __('Days per game') : __('Minutes per game') }}
                    <input wire:model.live.debounce.500ms="gameLength" inputmode="decimal" placeholder="{{ $profile->gameLength + 0 }}" class="h-11 w-[120px] rounded-md border border-edge bg-ground px-3 text-[13px] text-ink">
                </label>
                @if ($series)
                    <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                        {{ __('Setup per series, minutes') }}
                        <input wire:model.live.debounce.500ms="setup" inputmode="decimal" placeholder="{{ $profile->setup + 0 }}" class="h-11 w-[120px] rounded-md border border-edge bg-ground px-3 text-[13px] text-ink">
                    </label>
                @endif
                <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                    {{ $daily ? __('Break between rounds, days') : __('Break between rounds, minutes') }}
                    <input wire:model.live.debounce.500ms="break" inputmode="decimal" placeholder="{{ $profile->break + 0 }}" class="h-11 w-[120px] rounded-md border border-edge bg-ground px-3 text-[13px] text-ink">
                </label>
                <span class="max-w-[420px] text-xs leading-normal text-ink-3">{{ __('These are planning values, not measurements. They apply to this tournament only; league defaults live in Settings.') }}</span>
            </div>
        </details>

        {{-- The recommendation --}}
        @if ($recommended !== null)
            <div class="flex flex-col gap-3 rounded-lg bg-btc-chip p-4 shadow-[inset_3px_0_0_var(--color-btc)] lg:px-5 @min-[900px]/admin:flex-row @min-[900px]/admin:items-center @min-[900px]/admin:gap-4" data-test="recommendation">
                <span class="flex min-w-0 items-start gap-3 @min-[900px]/admin:grow">
                    <x-icon name="award" :size="20" class="mt-0.5 shrink-0 text-btc" />
                    <span class="flex min-w-0 flex-col gap-1">
                        <span class="text-xs text-ink-2">{{ $evaluation->nothingFits ? __('Nothing fits your time. The shortest is') : __('Recommended for :who in :window', ['who' => $who($n), 'window' => $duration($window)]) }}</span>
                        <span class="font-display text-lg font-bold" data-test="recommendation-name">{{ $recommendedName }}</span>
                        <span class="text-[13px] leading-normal text-ink-2" data-test="recommendation-why">{{ $recommendedWhy }}</span>
                    </span>
                </span>
                @if ($recommended->format === $format)
                    <span class="flex items-center gap-1.5 text-[13px] whitespace-nowrap text-ink-2"><x-icon name="check" :size="16" />{{ __('Selected') }}</span>
                @else
                    <x-button wire:click="useRecommended" class="shrink-0 self-start whitespace-nowrap @min-[900px]/admin:self-auto" data-test="use-recommended">{{ __('Use :format', ['format' => $recommended->format->label()]) }}</x-button>
                @endif
            </div>
        @endif

        <div class="grid gap-6 @min-[1400px]/admin:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] @min-[1400px]/admin:items-start">
            {{-- Every system for this input: a table with its time bars from 820 px of its own width (the `rows` container), cards below. --}}
            <div class="@container/rows flex min-w-0 flex-col gap-2">
                <div role="radiogroup" aria-labelledby="cmp-h" class="flex flex-col gap-1 @min-[820px]/rows:gap-0.5 @min-[820px]/rows:pt-[22px]" data-test="format-rows">
                    <span id="cmp-h" class="pb-1 text-[13px] font-bold @min-[820px]/rows:hidden">{{ __('All systems for your time') }}</span>
                    <div class="hidden items-end gap-3 px-3 pb-2.5 text-xs text-ink-3 @min-[820px]/rows:grid @min-[820px]/rows:grid-cols-[300px_minmax(0,1fr)_184px_88px]" aria-hidden="true">
                        <span>{{ __('System') }}</span>
                        <span class="relative h-[18px]">
                            @foreach ($ticks as $tick)
                                <span class="absolute -translate-x-1/2 whitespace-nowrap" style="left: {{ $tick['pct'] }}%">{{ $tick['label'] }}</span>
                            @endforeach
                            <span class="absolute -top-5 -translate-x-1/2 font-bold whitespace-nowrap text-ink" style="left: {{ $windowPct }}%">{{ __('your :window', ['window' => $duration($window)]) }}</span>
                        </span>
                        <span>{{ __('Time') }}</span>
                        <span class="text-right leading-tight">{{ __('Min. games') }}</span>
                    </div>

                    @if ($lobbyGame)
                        <p class="m-0 rounded-md bg-btc-chip px-3 py-2.5 text-xs leading-normal text-ink shadow-[inset_0_0_0_1px_var(--color-btc)]" data-test="lobby-only">{{ __(':game tournaments are one lobby match: every player plays one game in a lobby of up to :max, the lobbies split evenly; nobody moves on. It is the only format for this game.', ['game' => GameNames::game($profile->game), 'max' => Lobbies::maxPlayers($profile->game)]) }}</p>
                    @endif
                    @foreach ($rows as $entry)
                        @php($row = $entry['row'])
                        @if (! $row->enabled)
                            <div aria-disabled="true" class="flex flex-col gap-1 rounded-md p-3 @min-[820px]/rows:grid @min-[820px]/rows:grid-cols-[300px_minmax(0,1fr)] @min-[820px]/rows:items-center @min-[820px]/rows:gap-3" wire:key="row-{{ $row->format->value }}" data-test="row-{{ $row->format->value }}" data-disabled="true">
                                <span class="flex flex-col gap-1">
                                    <span class="flex items-center gap-1.5 text-sm font-bold text-ink-3 @min-[820px]/rows:text-[13px]"><x-icon name="lock" :size="14" />{{ $row->format->label() }}</span>
                                    <span class="hidden text-xs text-ink-3 @min-[820px]/rows:block">{{ __($entry['copy']['short']) }}</span>
                                </span>
                                <span class="text-xs leading-normal text-ink-2" data-test="row-reason">{{ __('Not available for this game.') }} {{ __($row->reason) }}</span>
                            </div>
                        @else
                            <button type="button" role="radio" aria-checked="{{ $entry['selected'] ? 'true' : 'false' }}" wire:click="select('{{ $row->format->value }}')" wire:key="row-{{ $row->format->value }}"
                                    data-test="row-{{ $row->format->value }}"
                                    aria-label="{{ $row->format->label() }}, {{ __('about :duration', ['duration' => $duration($row->total())]) }}, {{ $entry['fitText'] }}, {{ __('at least :games for everyone', ['games' => $gamesCount($row->guaranteedGames())]) }}{{ $entry['recommended'] ? ', '.__('recommended') : '' }}"
                                    @class(['flex w-full cursor-pointer flex-col gap-2.5 rounded-md border-0 p-3 text-left text-ink hover:bg-row-hover @min-[820px]/rows:grid @min-[820px]/rows:min-h-16 @min-[820px]/rows:grid-cols-[300px_minmax(0,1fr)_184px_88px] @min-[820px]/rows:items-center @min-[820px]/rows:gap-3 @min-[820px]/rows:px-3 @min-[820px]/rows:py-2.5',
                                        'bg-btc-chip shadow-[inset_0_0_0_1px_var(--color-btc)]' => $entry['selected'], 'bg-transparent' => ! $entry['selected']])>
                                {{-- A card (under 820 px): time and minimum go under the name where the description would wrap beside them (R11, 151 px at 390). --}}
                                <span class="flex w-full flex-wrap items-start gap-x-3 gap-y-1 @min-[820px]/rows:w-auto">
                                    <span class="flex min-w-0 grow basis-[16rem] flex-col gap-1">
                                        <span class="text-sm font-bold @min-[820px]/rows:text-[13px]">{{ $row->format->label() }}</span>
                                        <span class="text-xs text-ink-3">{{ __($entry['copy']['short']) }}</span>
                                        @if ($entry['recommended'])
                                            <span class="hidden h-[22px] items-center gap-1 self-start rounded-xs bg-btc-press px-2 text-[11px] font-bold whitespace-nowrap text-btc-hi @min-[820px]/rows:inline-flex" data-test="recommended-chip"><x-icon name="award" :size="12" />{{ __('Recommended') }}</span>
                                        @endif
                                    </span>
                                    <span class="flex shrink-0 flex-col items-end gap-0.5 whitespace-nowrap @min-[820px]/rows:hidden">
                                        <span class="text-[13px] font-bold">{{ $duration($row->total()) }}</span>
                                        <span class="text-xs text-ink-2">{{ __(':games at least', ['games' => $gamesCount($row->guaranteedGames())]) }}</span>
                                    </span>
                                </span>
                                <span class="block w-full py-1.5 @min-[820px]/rows:py-0" aria-hidden="true">
                                    <span class="relative block h-[22px]">
                                        @foreach ($ticks as $tick)
                                            <span class="absolute -top-1 -bottom-1 w-px bg-hairline" style="left: {{ $tick['pct'] }}%"></span>
                                        @endforeach
                                        <span class="absolute -top-2 -bottom-2 w-0 border-l-2 border-dashed border-ink-2" style="left: {{ $windowPct }}%"></span>
                                        <span class="absolute inset-0 flex items-stretch">
                                            @foreach ($entry['blocks'] as $block)
                                                <span title="{{ $block['tip'] }}" style="width: {{ $block['w'] }}%; margin-left: {{ $block['gap'] }}%"
                                                      @class(['shrink-0 rounded-[2px]',
                                                          'border border-dashed' => $block['ifNeeded'],
                                                          'bg-loss' => ! $block['ifNeeded'] && $block['tone'] === 'over', 'border-loss' => $block['ifNeeded'] && $block['tone'] === 'over',
                                                          'bg-btc' => ! $block['ifNeeded'] && $block['tone'] === 'selected', 'border-btc' => $block['ifNeeded'] && $block['tone'] === 'selected',
                                                          'bg-ink-3' => ! $block['ifNeeded'] && $block['tone'] === 'plain', 'border-ink-3' => $block['ifNeeded'] && $block['tone'] === 'plain'])></span>
                                            @endforeach
                                        </span>
                                        @if ($entry['clipped'])
                                            <span class="absolute top-[3px] -right-0.5 h-4 w-2.5 bg-[repeating-linear-gradient(135deg,var(--color-loss)_0_2px,transparent_2px_5px)]"></span>
                                        @endif
                                    </span>
                                </span>
                                <span class="flex w-full items-center justify-between gap-2 @min-[820px]/rows:w-auto @min-[820px]/rows:flex-col @min-[820px]/rows:items-start @min-[820px]/rows:gap-1">
                                    <span class="hidden text-[13px] font-bold @min-[820px]/rows:block">{{ __('about :duration', ['duration' => $duration($row->total())]) }}</span>
                                    <span @class(['flex items-center gap-1.5 text-xs', 'text-ink-2' => $entry['fits'], 'text-loss' => ! $entry['fits']]) data-test="row-fit">
                                        <x-icon :name="$entry['fits'] ? 'check' : 'warn'" :size="14" />{{ $entry['fitText'] }}
                                    </span>
                                    @if ($entry['recommended'])
                                        <span class="inline-flex h-[22px] items-center gap-1 rounded-xs bg-btc-press px-2 text-[11px] font-bold whitespace-nowrap text-btc-hi @min-[820px]/rows:hidden"><x-icon name="award" :size="12" />{{ __('Recommended') }}</span>
                                    @endif
                                </span>
                                <span class="hidden text-right font-display text-lg font-bold @min-[820px]/rows:block">{{ $row->guaranteedGames() }}</span>
                            </button>
                        @endif
                    @endforeach
                </div>
                <p class="m-0 mt-1 text-xs leading-relaxed text-ink-3">{{ __('Each block is one round. The dashed line is the end of your time. Times are planned for the slowest match of each round, so most evenings end a bit earlier.') }}</p>
            </div>

            <div class="grid items-start gap-4 @min-[900px]/admin:grid-cols-2 @min-[1400px]/admin:grid-cols-1">
                {{-- Options of the selected format, each with its help --}}
                <section aria-labelledby="opt-h" class="@container flex flex-col rounded-lg border border-hairline bg-ground px-4 py-3 lg:px-5">
                    <h3 id="opt-h" class="m-0 pb-2 text-[13px] font-bold">{{ __('Options for :format', ['format' => $format->label()]) }}</h3>

                    @if ($profile->isHyper() && in_array($format, [TournamentFormat::SingleElimination, TournamentFormat::FreeForAll], true))
                        {{-- Hyperbitcoinization (P5b): players alone, or clans as 2v2 or 3v3 teams (a knockout only). --}}
                        <div class="{{ $optionRow }}" wire:key="hyper-teamSize">
                            <span class="flex flex-col items-start gap-2">
                                <span id="hy-teamSize" class="text-[13px] font-bold">{{ __('Who plays') }}</span>
                                <span role="radiogroup" aria-labelledby="hy-teamSize" class="flex flex-wrap gap-1 rounded-md border border-edge bg-ground p-[3px]" data-test="hyper-teamSize">
                                    @foreach ([1 => __('Players'), 2 => __('Clan 2v2'), 3 => __('Clan 3v3')] as $value => $label)
                                        <button type="button" role="radio" aria-checked="{{ $options->teamSize === $value ? 'true' : 'false' }}" wire:click="option('teamSize', {{ $value }})" wire:key="hy-teamSize-{{ $value }}" data-value="{{ $value }}"
                                                @class(['min-h-[38px] min-w-11 cursor-pointer rounded-sm border-0 px-3 text-[13px] font-bold', 'bg-raised text-btc' => $options->teamSize === $value, 'bg-transparent text-ink-2' => $options->teamSize !== $value])>{{ $label }}</button>
                                    @endforeach
                                </span>
                            </span>
                            <span class="{{ $help }}">{{ $options->teamSize > 1 ? __('Clans sign up as teams and name their players before each match. Every match is one team table on the league server.') : __('Every player signs up alone.') }}</span>
                        </div>
                    @endif

                    @if ($format === TournamentFormat::SingleElimination)
                        <div class="{{ $optionRow }}">
                            <x-ui.check :checked="$options->thirdPlace" wire:click="option('thirdPlace', {{ $options->thirdPlace ? 'false' : 'true' }})" class="font-bold" data-test="third-place-toggle">{{ __('Match for 3rd place') }}</x-ui.check>
                            <span class="{{ $help }}">{{ __('The two semifinal losers play one more match. Without it, both share 3rd place.') }}</span>
                        </div>
                    @endif

                    @if ($format === TournamentFormat::DoubleElimination)
                        <div class="flex flex-col gap-2 border-t border-hairline py-3">
                            <span class="text-[13px] font-bold" id="gf-l">{{ __('Grand final') }}</span>
                            {{--
                                Each choice is a one-line radio (rule R11: a control never wraps its text); what it does stands
                                under it as its description, outside the control. Title and sentence inside the radio ran to
                                6 lines at 390 and squeezed the sentence into 236 px (V4).
                            --}}
                            <div role="radiogroup" aria-labelledby="gf-l" class="flex flex-col gap-3" data-test="grand-final">
                            @foreach ([
                                ['reset', __('Bracket reset'), __('The lower-bracket winner must win twice. Fair: the upper-bracket winner has not lost yet, so they get a second chance too. Adds a match only if needed.')],
                                ['single', __('One match'), __('Shorter. The upper-bracket winner loses the advantage of being unbeaten.')],
                                ['skip', __('No grand final'), __('The upper-bracket winner wins the tournament, the lower-bracket winner is 2nd. They never play each other.')],
                            ] as [$value, $label, $text])
                                <div class="flex flex-col gap-1" wire:key="gf-{{ $value }}">
                                    <button type="button" role="radio" class="rv-opt" aria-checked="{{ $options->grandFinal === $value ? 'true' : 'false' }}" aria-describedby="gf-{{ $value }}-d" wire:click="option('grandFinal', '{{ $value }}')" data-test="grand-final-{{ $value }}">
                                        <i aria-hidden="true"></i>
                                        <span class="text-[13px] font-bold whitespace-nowrap">{{ $label }}</span>
                                    </button>
                                    <p id="gf-{{ $value }}-d" class="m-0 cursor-pointer px-3 {{ $help }}" wire:click="option('grandFinal', '{{ $value }}')" data-test="grand-final-{{ $value }}-help">{{ $text }}</p>
                                </div>
                            @endforeach
                            </div>
                        </div>
                        <div class="{{ $optionRow }}">
                            <x-ui.check :checked="$options->split" wire:click="option('split', {{ $options->split ? 'false' : 'true' }})" class="font-bold" data-test="split-toggle">{{ __('Lower seeds start in the lower bracket') }}</x-ui.check>
                            <span class="{{ $help }}" data-test="split-help">{{ __('The lowest seeds start in the lower bracket, with one life: here :seeds. Shorter, but less fair.', ['seeds' => $splitFrom === $n ? __('seed :seed', ['seed' => $n]) : __('seeds :from to :to', ['from' => $splitFrom, 'to' => $n])]) }}</span>
                        </div>
                    @endif

                    @if ($format === TournamentFormat::FreeForAll && $profile->isHyper())
                        {{-- Hyperbitcoinization (P5): the table size and how many of each table move on. --}}
                        @foreach ([['heatSize', __('Players per table'), range(...\App\Support\Tournaments\FormatOptions::hyperTables()), $options->heatSize],
                                   ['heatAdvance', __('Move on per table'), range(1, max(1, $options->heatSize - 1)), $options->heatAdvance]] as [$key, $label, $choices, $current])
                            <div class="{{ $optionRow }}" wire:key="hyper-{{ $key }}">
                                <span class="flex flex-col items-start gap-2">
                                    <span id="hy-{{ $key }}" class="text-[13px] font-bold">{{ $label }}</span>
                                    <span role="radiogroup" aria-labelledby="hy-{{ $key }}" class="flex gap-1 rounded-md border border-edge bg-ground p-[3px]" data-test="hyper-{{ $key }}">
                                        @foreach ($choices as $value)
                                            <button type="button" role="radio" aria-checked="{{ $current === $value ? 'true' : 'false' }}" wire:click="option('{{ $key }}', {{ $value }})" wire:key="hy-{{ $key }}-{{ $value }}"
                                                    @class(['min-h-[38px] min-w-11 cursor-pointer rounded-sm border-0 px-3 text-[13px] font-bold', 'bg-raised text-btc' => $current === $value, 'bg-transparent text-ink-2' => $current !== $value])>{{ $value }}</button>
                                        @endforeach
                                    </span>
                                </span>
                                <span class="{{ $help }}">{{ $key === 'heatSize' ? __('Every table is one match on the league server; the last table is the final.') : __('The best of each table play the next round, by their place.') }}</span>
                            </div>
                        @endforeach
                    @endif

                    @if ($format === TournamentFormat::RoundRobin)
                        <div class="{{ $optionRow }}">
                            <span class="flex flex-col items-start gap-2">
                                <span id="k-l" class="text-[13px] font-bold">{{ __('Everyone meets once, twice or three times') }}</span>
                                <span role="radiogroup" aria-labelledby="k-l" class="flex gap-1 rounded-md border border-edge bg-ground p-[3px]">
                                    @foreach ([[1, __('Once')], [2, __('Twice')], [3, __('3 times')]] as [$value, $label])
                                        <button type="button" role="radio" aria-checked="{{ $options->iterations === $value ? 'true' : 'false' }}" wire:click="option('iterations', {{ $value }})" wire:key="k-{{ $value }}"
                                                @class(['min-h-[38px] cursor-pointer rounded-sm border-0 px-3 text-[13px] font-bold whitespace-nowrap', 'bg-raised text-btc' => $options->iterations === $value, 'bg-transparent text-ink-2' => $options->iterations !== $value])>{{ $label }}</button>
                                    @endforeach
                                </span>
                            </span>
                            {{-- A two-leg game (Blockli) multiplies this with its games per pairing below: say what comes out (DerCaddy, 2026-11-04:
                                 "spielt man dann 6 Spiele gegen einen Gegner?"). --}}
                            <span class="{{ $help }}" data-test="iterations-help">{{ \App\Support\Tournaments\TournamentRunner::tableChoosesLegs($profile->game, $format, $options)
                                ? __('Each time is one more pairing with the same opponent. With the games per pairing below that makes :against games against each opponent and :games games for everyone.', ['against' => $options->iterations * $perMatch, 'games' => $chosen->guaranteedGames()])
                                : __('Twice means a return match; in chess with the other color.') }}</span>
                        </div>
                        <div class="{{ $optionRow }}">
                            <span class="flex flex-col items-start gap-2">
                                <span class="text-[13px] font-bold" id="rank-by-l">{{ __('Rank by') }}</span>
                                <x-ui.select-menu model="options.rankBy" live labelledby="rank-by-l" :value="$options->rankBy" width="16rem" data-test="rank-by"
                                                  :options="['points' => __('Points (win 1, draw ½)'), 'match-wins' => __('Matches won'), 'game-wins' => __('Games won'), 'custom' => __('Custom points')]" />
                            </span>
                            {{-- The help explains the choice made, not all four (DerCaddy, 2026-11-04). --}}
                            <span class="{{ $help }}" data-test="rank-by-help">{{ match ($options->rankBy) {
                                'match-wins' => __('Matches won: only won matches count, a draw counts nothing.'),
                                'game-wins' => __('Games won: every single game won counts, also inside a series or a pairing.'),
                                'custom' => __('Custom points: you set the points for a win and a draw.'),
                                default => __('Points: a win 1, a draw ½.'),
                            } }}</span>
                        </div>
                        @if ($options->rankBy === 'custom')
                            {{-- Custom points had nothing to set them with in a round robin (DerCaddy, 2026-11-04): the Swiss fields, without the bye. --}}
                            <div class="{{ $optionRow }}" data-test="custom-points">
                                <span class="flex flex-col items-start gap-2">
                                    <span class="text-[13px] font-bold">{{ __('Points for a win and a draw') }}</span>
                                    <span class="flex gap-2">
                                        @foreach ([['pointsWin', __('Win')], ['pointsTie', __('Draw')]] as [$key, $label])
                                            <label class="flex flex-col gap-1 text-xs text-ink-2" wire:key="rr-pts-{{ $key }}">{{ $label }}
                                                <input wire:model.live.debounce.500ms="options.{{ $key }}" inputmode="decimal" class="h-11 w-16 rounded-md border border-edge bg-ground px-2 text-center text-[13px] text-ink">
                                            </label>
                                        @endforeach
                                    </span>
                                </span>
                                <span class="{{ $help }}">{{ __('From 0 to 10 points each.') }}</span>
                            </div>
                        @endif
                        {{-- Counted game by game, everyone's games won follow from the points (DerCaddy, 2026-11-04). --}}
                        @include('pages.admin.partials.tournament-tie-breaks', ['key' => 'roundRobinTieBreaks', 'current' => $options->roundRobinTieBreaks, 'labels' => array_diff_key($tieBreakLabels, ['median-buchholz' => true]),
                            'same' => $options->rankBy === 'points' && $options->perLeg() && \App\Support\Tournaments\TournamentRunner::tableChoosesLegs($profile->game, $format, $options) ? ['game-wins', 'game-difference', 'game-win-percentage'] : []])
                    @endif

                    @if ($format === TournamentFormat::Swiss)
                        <div class="{{ $optionRow }}">
                            <span class="flex flex-col items-start gap-2">
                                <span class="text-[13px] font-bold">{{ __('Rounds') }}</span>
                                <x-tournaments.stepper :value="$rounds" decrement="stepOption('swissRounds', -1)" increment="stepOption('swissRounds', 1)" :less="__('One round less')" :more="__('One round more')" data-test="swiss-rounds" />
                            </span>
                            <span class="{{ $help }}">{{ __('We suggest :rounds for :who and your time. More rounds give a clearer winner; stay at :max or below so every pairing stays new.', ['rounds' => $evaluation->swissRounds, 'who' => $who($n), 'max' => Estimator::swissMax($n)]) }}</span>
                        </div>
                        <div class="{{ $optionRow }}">
                            <span class="flex flex-col items-start gap-2">
                                <span class="text-[13px] font-bold">{{ __('Points for a win, a draw, a bye') }}</span>
                                <span class="flex gap-2">
                                    @foreach ([['pointsWin', __('Win')], ['pointsTie', __('Draw')], ['pointsBye', __('Bye')]] as [$key, $label])
                                        <label class="flex flex-col gap-1 text-xs text-ink-2" wire:key="pts-{{ $key }}">{{ $label }}
                                            <input wire:model.live.debounce.500ms="options.{{ $key }}" inputmode="decimal" class="h-11 w-16 rounded-md border border-edge bg-ground px-2 text-center text-[13px] text-ink">
                                        </label>
                                    @endforeach
                                </span>
                            </span>
                            <span class="{{ $help }}">{{ __('A bye is a free round when the number of players is odd. By default it counts like a win.') }}</span>
                        </div>
                        @include('pages.admin.partials.tournament-tie-breaks', ['key' => 'swissTieBreaks', 'current' => $options->swissTieBreaks, 'labels' => $tieBreakLabels])
                    @endif

                    @if ($format === TournamentFormat::TwoStage)
                        <div class="{{ $optionRow }}">
                            <span class="flex flex-col items-start gap-2">
                                <span id="gs-l" class="text-[13px] font-bold">{{ __('Group stage') }}</span>
                                <x-tournaments.segmented labelledby="gs-l" method="pickGroupStage" :current="$options->groupStage" :grow="false"
                                    :options="[['round-robin', __('Everyone vs everyone')], ['single-elimination', __('Single Elim')], ['double-elimination', __('Double Elim')]]" />
                            </span>
                            <span class="{{ $help }}">{{ __('Everyone against everyone is the usual choice. A bracket inside each group is shorter.') }}</span>
                        </div>
                        <div class="{{ $optionRow }}">
                            <span class="flex flex-col items-start gap-2">
                                <span class="text-[13px] font-bold">{{ __('Group size') }}</span>
                                <x-tournaments.stepper :value="$options->groupSize" decrement="stepOption('groupSize', -1)" increment="stepOption('groupSize', 1)" :less="__('One less')" :more="__('One more')" />
                            </span>
                            <span class="{{ $help }}">{{ __('Bigger groups mean more games for everyone, and a longer group stage.') }}</span>
                        </div>
                        <div class="{{ $optionRow }}">
                            <span class="flex flex-col items-start gap-2">
                                <span class="text-[13px] font-bold">{{ __('Move on per group') }}</span>
                                <x-tournaments.stepper :value="$structure?->advance ?? $options->advance" decrement="stepOption('advance', -1)" increment="stepOption('advance', 1)" :less="__('One less')" :more="__('One more')" />
                            </span>
                            <span class="{{ $help }}">{{ __('How many of each group play the final stage.') }}</span>
                        </div>
                        <div class="{{ $optionRow }}">
                            <span class="flex flex-col items-start gap-2">
                                <span id="fs-l" class="text-[13px] font-bold">{{ __('Final stage') }}</span>
                                <x-tournaments.segmented labelledby="fs-l" method="pickFinalStage" :current="$options->finalStage" :grow="false"
                                    :options="[['single-elimination', __('Single Elimination')], ['double-elimination', __('Double Elimination')]]" />
                            </span>
                            <span class="{{ $help }}">{{ __('Double Elimination gives everyone in the final stage a second chance.') }}</span>
                        </div>
                    @endif

                    @if (\App\Support\Tournaments\TournamentRunner::tableChoosesLegs($profile->game, $format, $options))
                        {{-- A two-leg game (Blockli) in a round-robin table (DerCaddy, 2026-10-18: "das sollte man vorher einstellen können",
                             "sollte man dann auch zwei Punkte bekommen können"): one game or two per pairing, points per pairing or per game.
                             A knockout keeps its two legs and decider. --}}
                        <div class="{{ $optionRow }}" data-test="legs-option">
                            <span class="flex flex-col items-start gap-2">
                                <span id="legs-l" class="text-[13px] font-bold">{{ __('Games per pairing') }}</span>
                                <x-tournaments.segmented labelledby="legs-l" method="pickLegs" :current="$options->legs" :grow="false" data-test="legs"
                                    :options="[[2, __('Two games')], [1, __('One game')]]" />
                            </span>
                            <span class="{{ $help }}">{{ __('Two games with the colours swapped are fairer: everyone starts once. One game per pairing halves the games; the colours even out over the rounds.') }}</span>
                        </div>
                        @if ($options->legs === 2)
                            <div class="{{ $optionRow }}" data-test="leg-points-option">
                                <span class="flex flex-col items-start gap-2">
                                    <span id="legp-l" class="text-[13px] font-bold">{{ __('Points in the table') }}</span>
                                    <x-tournaments.segmented labelledby="legp-l" method="pickLegPoints" :current="$options->legPoints" :grow="false" data-test="leg-points"
                                        :options="[[false, __('Per pairing')], [true, __('Per game')]]" />
                                </span>
                                <span class="{{ $help }}">{{ __('Per game: every game counts, a win 1 and a draw ½, so up to 2 points per pairing, and each game is in the table once it ends. Per pairing: the pairing counts once, 1 for winning it, ½ when each wins a game.') }}</span>
                            </div>
                        @endif
                    @endif

                    @if ($lobbyFormat)
                        <div class="flex flex-col gap-2 border-t border-hairline py-3" data-test="lobby-options">
                            <span class="text-[13px] font-bold">{{ __('Lobbies') }}</span>
                            <span class="{{ $help }}">{{ __('Set by the league, not here: lobbies of up to :max, split evenly at the draw, one diplomacy game each. The map size follows each lobby\'s players: :sizes.', ['max' => Lobbies::maxPlayers($profile->game), 'sizes' => Lobbies::mapSizesLine($profile->game)]) }}</span>
                        </div>
                    @endif

                    @if ($series && ! $lobbyFormat)
                        <div class="{{ $optionRow }}">
                            <span class="flex flex-col items-start gap-2">
                                <span id="bo-l" class="text-[13px] font-bold">{{ __('Series length') }}</span>
                                <x-tournaments.segmented labelledby="bo-l" method="pickBestOf" :current="$options->bestOf" :grow="false"
                                    :options="array_map(fn (int $best): array => [$best, 'Bo'.$best], $profile->bestOfOptions)" />
                            </span>
                            <span class="{{ $help }}">{{ __('Best of 3: the first team to win 2 games wins the series. Longer series are fairer but take more time.') }}</span>
                        </div>
                        <div class="{{ $optionRow }}">
                            <span class="flex flex-col items-start gap-2">
                                <span id="fbo-l" class="text-[13px] font-bold">{{ __('Final series') }}</span>
                                <x-tournaments.segmented labelledby="fbo-l" method="pickFinalBestOf" :current="$options->finalBestOf" :grow="false"
                                    :options="array_map(fn (int $best): array => [$best, 'Bo'.$best], $profile->bestOfOptions)" />
                            </span>
                            <span class="{{ $help }}">{{ __('The last match can be longer than the rest.') }}</span>
                        </div>
                    @endif
                    {{-- Chess plays one game per match: the 2-game match (one with each color) has no execution path yet (P8b DoD gate). --}}
                </section>

                {{-- The selected format, explained --}}
                <section aria-labelledby="sel-h" class="@container/sel flex min-w-0 flex-col gap-4 rounded-lg border border-hairline bg-ground px-4 py-5 lg:px-5" data-test="selected-format">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h3 id="sel-h" class="m-0 font-display text-lg font-bold">{{ $format->label() }}</h3>
                        @if ($format === $evaluation->recommended)
                            <span class="inline-flex h-[22px] items-center gap-1 rounded-xs bg-btc-press px-2 text-[11px] font-bold text-btc-hi"><x-icon name="award" :size="12" />{{ __('Recommended') }}</span>
                        @endif
                        <span class="grow"></span>
                        <span class="text-[13px] text-ink-2">{{ __('about') }} <b class="text-ink">{{ $duration($chosen->total()) }}</b></span>
                    </div>
                    @include('pages.admin.partials.tournament-range')
                    <p class="m-0 text-[13px] leading-relaxed">{{ __($copy['how']) }}</p>

                    <figure class="m-0 flex flex-col gap-2 rounded-md bg-card p-3" x-data x-ref="figure" wire:key="preview-{{ $previewKey }}" data-test="preview">
                        @foreach ([['desktop', $previewDesktop, 480, 208, 'hidden @min-[440px]/sel:flex'], ['mobile', $previewMobile, 326, 196, 'flex @min-[440px]/sel:hidden']] as [$size, $preview, $width, $height, $visibility])
                            {{-- The top row's labels above the drawing as HTML (Preview::heads, as on the tournament page): inside the SVG
                                 "Finalrunde, die besten 2 jeder Gruppe" ran 68 px past the drawing's edge and was cut (E1). --}}
                            @php(['heads' => $heads, 'texts' => $inside] = Preview::heads($preview, $width))
                            @php($top = $heads === [] ? 0 : 12)
                            <div class="{{ $visibility }} flex-col gap-1" wire:key="pv-{{ $size }}">
                                @if ($heads !== [])
                                    <div class="grid text-xs leading-[1.25] text-ink-3" aria-hidden="true" data-test="preview-heads">
                                        @foreach ($heads as $head)
                                            <span @class(['col-start-1 row-start-1 self-end [overflow-wrap:anywhere]', 'text-right' => $head['end']]) style="margin-left: {{ $head['left'] }}%; width: {{ $head['width'] }}%">{{ __($head['text'], $head['replace']) }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                <svg viewBox="0 {{ $top }} {{ $width }} {{ $height - $top }}" class="tf-preview h-auto w-full" role="img" aria-label="{{ __('Preview of :format for :who', ['format' => $format->label(), 'who' => $who($n)]) }}">
                                    @foreach ($preview['lines'] as $line)
                                        <path d="{{ $line['d'] }}" fill="none" stroke="#3A3A42" stroke-width="1" style="animation-delay: {{ $line['delay'] }}s" />
                                    @endforeach
                                    @foreach ($preview['rects'] as $rect)
                                        <rect x="{{ $rect['x'] }}" y="{{ $rect['y'] }}" width="{{ $rect['w'] }}" height="{{ $rect['h'] }}" rx="2"
                                              fill="{{ $rect['ghost'] ? 'transparent' : '#F7931A' }}" stroke="{{ $rect['ghost'] ? '#8B8B90' : 'none' }}" @if ($rect['ghost']) stroke-dasharray="3 3" @endif
                                              style="animation-delay: {{ $rect['delay'] }}s" />
                                    @endforeach
                                    @foreach ($inside as $text)
                                        <text x="{{ $text['x'] }}" y="{{ $text['y'] }}" text-anchor="{{ $text['anchor'] }}" fill="#8B8B90" font-size="10" font-family="JetBrains Mono, monospace">{{ __($text['text'], $text['replace']) }}</text>
                                    @endforeach
                                </svg>
                            </div>
                        @endforeach
                        {{-- The replay link goes under the caption where the two do not fit (it squeezed the caption into 103 px at 390). --}}
                        <figcaption class="flex flex-wrap items-start gap-x-3 gap-y-1 text-xs leading-normal text-ink-2">
                            <span class="min-w-0 grow basis-[16rem]">{{ $caption }}</span>
                            <button type="button" class="shrink-0 cursor-pointer text-btc hover:text-btc-hi" data-test="preview-replay"
                                    x-on:click="$refs.figure.querySelectorAll('svg').forEach((svg) => { svg.classList.remove('tf-preview'); void svg.getBoundingClientRect(); svg.classList.add('tf-preview'); })">{{ __('Play again') }}</button>
                        </figcaption>
                    </figure>

                    {{-- Each figure on one line (a value like "Gruppen gleichzeitig, dann 3" ran 3 lines in 53 px): the tiles share a row as
                         far as their figures fit and wrap as whole tiles. --}}
                    <dl class="m-0 flex flex-wrap gap-2" data-test="facts">
                        @foreach ($facts as [$term, $value])
                            <div class="flex grow basis-0 flex-col gap-0.5 rounded-md bg-card px-3 py-2" data-fact>
                                <dt class="text-[11px] whitespace-nowrap text-ink-3">{{ $term }}</dt>
                                <dd class="m-0 text-[13px] font-bold whitespace-nowrap">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="m-0 text-[13px] leading-relaxed"><b>{{ __('Guaranteed games.') }}</b> <span data-test="guaranteed">{{ $guaranteed }}</span></p>
                    @if ($format === TournamentFormat::DoubleElimination && $options->grandFinal === 'reset')
                        <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Planned with the rematch: :duration, or :shorter if the grand final needs no rematch.', ['duration' => $duration($chosen->total()), 'shorter' => $duration($plan?->withoutIfNeeded ?? 0)]) }}</p>
                    @endif
                    <p class="m-0 text-[13px] leading-relaxed text-ink-2"><b class="text-ink">{{ __('Good for:') }}</b> {{ __($copy['good']) }}</p>
                    <div class="grid gap-4 @min-[560px]/sel:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <span class="text-xs font-bold text-ink-2">{{ __('Upsides') }}</span>
                            @foreach ($copy['pros'] as $pro)
                                <span class="flex gap-2 text-[13px] leading-normal"><x-icon name="check" :size="16" class="mt-0.5 shrink-0 text-win" />{{ __($pro) }}</span>
                            @endforeach
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-xs font-bold text-ink-2">{{ __('Downsides') }}</span>
                            @foreach ($copy['cons'] as $con)
                                <span class="flex gap-2 text-[13px] leading-normal"><x-icon name="warn" :size="16" class="mt-0.5 shrink-0 text-loss" />{{ __($con) }}</span>
                            @endforeach
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </section>

    {{-- Who enters results (director mode, TOURNAMENT-FORMATS.md section 6) --}}
    <section aria-labelledby="res-h" class="flex flex-col gap-4 rounded border border-hairline bg-card p-4 lg:p-6" data-test="results-mode">
        {{-- A game the league server plays (GameProfile::playedOnLeague()) has no results to enter in players mode: the choice is
             where the games are played (DerCaddy, 2026-11-04). --}}
        @php($playedHere = $profile->playedOnLeague())
        <h2 id="res-h" class="m-0 font-display text-lg font-bold">{{ $playedHere ? __('Where the games are played') : __('Who enters results') }}</h2>
        {{-- One card per row up to 1400 px of the admin column: side by side at 1280 the director's sentence ran 5 lines in 405 px,
             at 1024 7 lines in 277 (E1). --}}
        {{--
            Each mode is a one-line radio (rule R11) with its short title (choice()); what it means stands under it, outside the
            control, and picks it too, as the grand final choices do. Title and sentence inside the radio ran 7 lines in 277 px.
        --}}
        <div role="radiogroup" aria-labelledby="res-h" class="grid gap-3 @min-[1400px]/admin:grid-cols-2 @min-[1400px]/admin:gap-x-4">
            @foreach (TournamentResultsMode::cases() as $mode)
                <div class="flex flex-col gap-1" wire:key="mode-{{ $mode->value }}">
                    <button type="button" role="radio" class="rv-opt" aria-checked="{{ $this->resultsMode === $mode->value ? 'true' : 'false' }}" aria-describedby="res-{{ $mode->value }}-d" wire:click="pickResultsMode('{{ $mode->value }}')" data-test="results-{{ $mode->value }}">
                        <i aria-hidden="true"></i>
                        <span class="text-[13px] font-bold whitespace-nowrap">{{ $mode->choiceFor($profile) }}</span>
                    </button>
                    <p id="res-{{ $mode->value }}-d" class="m-0 cursor-pointer px-3 text-xs leading-normal text-ink-2" wire:click="pickResultsMode('{{ $mode->value }}')" data-test="results-{{ $mode->value }}-help">{{ $mode->helpFor($profile) }}</p>
                </div>
            @endforeach
        </div>

        @if ($this->resultsMode === TournamentResultsMode::Director->value)
            <div class="flex flex-col gap-3" data-test="directors">
                <span class="text-[13px] font-bold">{{ __('Tournament directors') }}</span>
                <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
                    @if ($chooserCreator = $this->chooserCreator())
                        <li class="inline-flex h-9 items-center gap-2 rounded-md bg-ground px-2.5 text-[13px] shadow-ring">
                            <x-avatar :user="$chooserCreator" :size="24" />{{ $chooserCreator->displayName() }} <span class="text-ink-3">{{ $chooserCreator->id === auth()->id() ? __('(you)') : __('(creator)') }}</span>
                        </li>
                    @endif
                    @foreach ($this->directors as $director)
                        <li class="inline-flex h-9 items-center gap-2 rounded-md bg-ground pl-2.5 text-[13px] shadow-ring" wire:key="director-{{ $director->id }}">
                            <x-avatar :user="$director" :size="24" />{{ $director->displayName() }}
                            <button type="button" class="flex size-9 cursor-pointer items-center justify-center text-ink-2 hover:text-ink" wire:click="removeDirector({{ $director->id }})" aria-label="{{ __('Remove :name', ['name' => $director->displayName()]) }}"><x-icon name="close" :size="14" /></button>
                        </li>
                    @endforeach
                </ul>
                <form wire:submit="addDirector" class="flex flex-col gap-1.5">
                    <span class="flex items-end gap-2">
                        <x-player-picker id="dir-new" wire:model="directorId" :label="__('Add a director')" :exclude="array_values(array_filter([$this->chooserCreator()?->id, ...$this->directorIds]))" submit-on-pick class="grow lg:max-w-80" />
                        <x-button variant="quiet" type="submit" data-test="add-director">{{ __('Add') }}</x-button>
                    </span>
                    @if ($this->directorError !== '')
                        <span class="text-xs text-loss" role="alert">{{ $this->directorError }}</span>
                    @endif
                </form>
                <ul class="m-0 flex list-disc flex-col gap-1 pl-5 text-xs leading-normal text-ink-2">
                    <li>{{ __('Directors enter results on the director desk; players see them right away, marked “Entered by the tournament director”.') }}</li>
                    <li>{{ __('Every entry and correction is saved with name and time and shown on the match.') }}</li>
                    <li>{{ __('Results can be corrected until the round is closed.') }}</li>
                    <li>{{ __('Rated games still count for Elo. Tournament games never mine season blocks.') }}</li>
                </ul>
            </div>
        @endif
    </section>
</div>
