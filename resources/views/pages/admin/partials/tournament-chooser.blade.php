@php
    use App\Enums\TournamentFormat;
    use App\Enums\TournamentResultsMode;
    use App\Support\Tournaments\Estimator;
    use App\Support\Tournaments\FormatCopy;
    use App\Support\Tournaments\FormatOptions;
    use App\Support\Tournaments\Preview;

    /*
     * The format chooser and "Who enters results" (AdminTournamentCreate.dc.html,
     * the `renderVals` of its logic script). Rendered inside the `chooser`
     * island of pages::admin.tournament-create.
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
    $recommendedWhy = $recommended === null ? '' : ($evaluation->nothingFits
        ? __('Nothing fits into :window. This is the shortest: :duration.', ['window' => $duration($window), 'duration' => $duration($recommended->total())]).' '.$lever
        : ($profile->isRocketLeague() && $recommended->format->hasFinal()
            // Rocket League recommends a format with a final first (user, 2026-09-26), so the reason names it.
            ? ($teams
                ? __('Ends with a final; every team plays at least :games, and it takes about :duration of your :window.', ['games' => $gamesCount($recommended->guaranteed()), 'duration' => $duration($recommended->total()), 'window' => $duration($window)])
                : __('Ends with a final; every player plays at least :games, and it takes about :duration of your :window.', ['games' => $gamesCount($recommended->guaranteed()), 'duration' => $duration($recommended->total()), 'window' => $duration($window)]))
            : __('Everyone gets at least :games, and it takes about :duration of your :window.', ['games' => $gamesCount($recommended->guaranteed()), 'duration' => $duration($recommended->total()), 'window' => $duration($window)])));

    $chosen = $evaluation->row($format);
    $structure = $chosen->structure;
    $plan = $chosen->duration;
    $copy = FormatCopy::for($format);
    $rounds = $evaluation->swissRounds;
    $odd = $n % 2 === 1;

    $guaranteed = match ($format) {
        TournamentFormat::Swiss => $odd ? __('Everyone plays :fewer or :rounds games. With an odd number, one player per round gets a free win (bye).', ['fewer' => $rounds - 1, 'rounds' => $rounds]) : __('Everyone plays :rounds games.', ['rounds' => $rounds]),
        TournamentFormat::RoundRobin => __('Everyone plays :games games.', ['games' => $structure?->guaranteed ?? 0]).($chosen->atOnce > 0 ? ' '.__('In daily chess all of them start at once: :count games running at the same time for each player.', ['count' => $chosen->atOnce]) : ''),
        TournamentFormat::TwoStage => __('Everyone plays at least :games games in the groups. The top :advance of each group play on.', ['games' => $structure?->guaranteed ?? 0, 'advance' => $structure?->advance ?? 0]),
        TournamentFormat::SingleElimination => __('The weakest may play only 1 match. The winner plays :rounds.', ['rounds' => $structure?->max ?? 0]).(($structure?->byes ?? 0) > 0 ? ' '.__(':byes of :n skip round 1 (bye), because :n does not fill the bracket.', ['byes' => $structure?->byes, 'n' => $n]) : ''),
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
        [$teams ? __('Games per team') : __('Games per player'), $structure !== null && $structure->min === $structure->max ? (string) $structure->min : __(':min to :max', ['min' => $structure?->min ?? 0, 'max' => $structure?->max ?? 0])],
        [__('Guaranteed'), (string) ($structure?->guaranteed ?? 0)],
    ];

    $caption = match ($format) {
        TournamentFormat::Swiss => __(':who, :rounds rounds. After round 1, pairs form by score, so the columns split into score groups.', ['who' => $who($n), 'rounds' => $rounds]),
        TournamentFormat::RoundRobin => __(':who. One square per match, lit in the round it is played.', ['who' => $who($n)]),
        TournamentFormat::TwoStage => __(':who, :groups groups of :sizes, then :finalists in the final stage.', ['who' => $who($n), 'groups' => count($structure?->groups ?? []), 'sizes' => implode(', ', $structure?->groups ?? []), 'finalists' => $structure?->finalists ?? 0]),
        TournamentFormat::SingleElimination => ($structure?->byes ?? 0) > 0 ? __(':who, :byes dashed boxes are byes.', ['who' => $who($n), 'byes' => $structure?->byes]) : $who($n).'.',
        default => __(':who, upper and lower bracket.', ['who' => $who($n)]),
    };

    $previewDesktop = Preview::for($format, $n, $options->withSwissRounds($rounds), 480, 208);
    $previewMobile = Preview::for($format, $n, $options->withSwissRounds($rounds), 326, 196);
    $previewKey = md5(json_encode([$format->value, $n, $options->toArray(), $rounds]));

    $assumption = $daily
        ? __('A daily chess game is planned at :days days, 1 move a day. :break day between rounds.', ['days' => $profile->gameLength + 0, 'break' => $profile->break + 0])
        : ($series
            ? __('One Rocket League game: about :minutes min, plus :setup min to set up each series. A Bo:best series is planned at :slot min (all games played). :break min between rounds.', ['minutes' => $profile->gameLength + 0, 'setup' => $profile->setup + 0, 'best' => $options->bestOf, 'slot' => $profile->slot($options->bestOf) + 0, 'break' => $profile->break + 0])
            : __('One Blitz 5+3 game: up to :minutes min, including pairing. :break min between rounds.', ['minutes' => $profile->gameLength + 0, 'break' => $profile->break + 0]));

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
    $optionRow = 'grid gap-2 border-t border-hairline py-3 lg:grid-cols-[minmax(0,1fr)_200px] lg:gap-4';
    $help = 'text-xs leading-normal text-ink-2';
@endphp

<div class="flex flex-col gap-4" data-test="tournament-chooser" data-format="{{ $format->value }}" data-recommended="{{ $evaluation->recommended?->value }}">
    <section aria-labelledby="fmt-h" class="flex flex-col gap-5 rounded-lg bg-card px-4 py-5 lg:p-6">
        <div class="flex flex-col gap-1.5 lg:flex-row lg:items-baseline lg:gap-4">
            <h2 id="fmt-h" class="m-0 font-display text-lg font-bold lg:text-xl">{{ __('Pick a format') }}</h2>
            <span class="text-[13px] leading-normal text-ink-2">{{ __('Tell us who plays and how much time you have. We compare every system for you.') }}</span>
        </div>

        {{-- Inputs: game, participants, time, where --}}
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,0.8fr)_minmax(0,1.35fr)_minmax(0,1fr)] lg:items-start lg:gap-6">
            <span class="flex flex-col gap-1.5">
                <span id="game-l" class="text-xs text-ink-2">{{ __('Game, mode') }}</span>
                <span class="flex items-center gap-2">
                    <span class="w-[104px] shrink-0 text-xs text-ink-3">{{ __('Chess') }}</span>
                    <x-tournaments.segmented labelledby="game-l" method="pickGame" :current="$this->game" class="grow" data-test="game-chess"
                        :options="[['blitz', __('Blitz 5+3')], ['daily', __('Daily')]]" />
                </span>
                <span class="flex items-center gap-2">
                    <span class="w-[104px] shrink-0 text-xs text-ink-3">{{ __('Rocket League') }}</span>
                    <x-tournaments.segmented labelledby="game-l" method="pickGame" :current="$this->game" class="grow" data-test="game-rl"
                        :options="[['rl1', '1v1'], ['rl2', '2v2'], ['rl3', '3v3']]" />
                </span>
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
            <summary class="flex min-h-11 cursor-pointer list-none items-center gap-3 px-4 py-2 text-xs leading-normal text-ink-2 [&::-webkit-details-marker]:hidden">
                <span class="grow">{{ $assumption }}</span>
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
            <div class="flex flex-col gap-3 rounded-lg bg-btc-chip p-4 shadow-[inset_3px_0_0_var(--color-btc)] lg:flex-row lg:items-center lg:gap-4 lg:px-5" data-test="recommendation">
                <span class="flex items-start gap-3 lg:grow">
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
                    <x-button wire:click="useRecommended" class="self-start lg:self-auto">{{ __('Use :format', ['format' => $recommended->format->label()]) }}</x-button>
                @endif
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] lg:items-start">
            {{-- Every system for this input --}}
            <div class="flex flex-col gap-2 lg:pt-[22px]">
                <div role="radiogroup" aria-labelledby="cmp-h" class="flex flex-col gap-1 lg:gap-0.5" data-test="format-rows">
                    <span id="cmp-h" class="pb-1 text-[13px] font-bold lg:hidden">{{ __('All systems for your time') }}</span>
                    <div class="hidden items-end gap-3 px-3 pb-2.5 text-xs text-ink-3 lg:grid lg:grid-cols-[164px_minmax(0,1fr)_164px_76px]" aria-hidden="true">
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

                    @foreach ($rows as $entry)
                        @php($row = $entry['row'])
                        @if (! $row->enabled)
                            <div aria-disabled="true" class="flex flex-col gap-1 rounded-md p-3 lg:grid lg:grid-cols-[164px_minmax(0,1fr)] lg:items-center lg:gap-3" wire:key="row-{{ $row->format->value }}" data-test="row-{{ $row->format->value }}" data-disabled="true">
                                <span class="flex flex-col gap-1">
                                    <span class="flex items-center gap-1.5 text-sm font-bold text-ink-3 lg:text-[13px]"><x-icon name="lock" :size="14" />{{ $row->format->label() }}</span>
                                    <span class="hidden text-xs text-ink-3 lg:block">{{ __($entry['copy']['short']) }}</span>
                                </span>
                                <span class="text-xs leading-normal text-ink-2" data-test="row-reason">{{ __('Not available for this game.') }} {{ __($row->reason) }}</span>
                            </div>
                        @else
                            <button type="button" role="radio" aria-checked="{{ $entry['selected'] ? 'true' : 'false' }}" wire:click="select('{{ $row->format->value }}')" wire:key="row-{{ $row->format->value }}"
                                    data-test="row-{{ $row->format->value }}"
                                    aria-label="{{ $row->format->label() }}, {{ __('about :duration', ['duration' => $duration($row->total())]) }}, {{ $entry['fitText'] }}, {{ __('at least :games for everyone', ['games' => $gamesCount($row->guaranteed())]) }}{{ $entry['recommended'] ? ', '.__('recommended') : '' }}"
                                    @class(['flex w-full cursor-pointer flex-col gap-2.5 rounded-md border-0 p-3 text-left text-ink hover:bg-row-hover lg:grid lg:min-h-16 lg:grid-cols-[164px_minmax(0,1fr)_164px_76px] lg:items-center lg:gap-3 lg:px-3 lg:py-2.5',
                                        'bg-btc-chip shadow-[inset_0_0_0_1px_var(--color-btc)]' => $entry['selected'], 'bg-transparent' => ! $entry['selected']])>
                                <span class="flex w-full items-start gap-3 lg:w-auto">
                                    <span class="flex min-w-0 grow flex-col gap-1">
                                        <span class="text-sm font-bold lg:text-[13px]">{{ $row->format->label() }}</span>
                                        <span class="text-xs text-ink-3">{{ __($entry['copy']['short']) }}</span>
                                        @if ($entry['recommended'])
                                            <span class="hidden h-[22px] items-center gap-1 self-start rounded-xs bg-btc-press px-2 text-[11px] font-bold whitespace-nowrap text-btc-hi lg:inline-flex" data-test="recommended-chip"><x-icon name="award" :size="12" />{{ __('Recommended') }}</span>
                                        @endif
                                    </span>
                                    <span class="flex shrink-0 flex-col items-end gap-0.5 lg:hidden">
                                        <span class="text-[13px] font-bold">{{ $duration($row->total()) }}</span>
                                        <span class="text-xs text-ink-2">{{ __(':games at least', ['games' => $gamesCount($row->guaranteed())]) }}</span>
                                    </span>
                                </span>
                                <span class="block w-full py-1.5 lg:py-0" aria-hidden="true">
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
                                <span class="flex w-full items-center justify-between gap-2 lg:w-auto lg:flex-col lg:items-start lg:gap-1">
                                    <span class="hidden text-[13px] font-bold lg:block">{{ __('about :duration', ['duration' => $duration($row->total())]) }}</span>
                                    <span @class(['flex items-center gap-1.5 text-xs', 'text-ink-2' => $entry['fits'], 'text-loss' => ! $entry['fits']]) data-test="row-fit">
                                        <x-icon :name="$entry['fits'] ? 'check' : 'warn'" :size="14" />{{ $entry['fitText'] }}
                                    </span>
                                    @if ($entry['recommended'])
                                        <span class="inline-flex h-[22px] items-center gap-1 rounded-xs bg-btc-press px-2 text-[11px] font-bold whitespace-nowrap text-btc-hi lg:hidden"><x-icon name="award" :size="12" />{{ __('Recommended') }}</span>
                                    @endif
                                </span>
                                <span class="hidden text-right font-display text-lg font-bold lg:block">{{ $row->guaranteed() }}</span>
                            </button>
                        @endif
                    @endforeach
                </div>
                <p class="m-0 mt-1 text-xs leading-relaxed text-ink-3">{{ __('Each block is one round. The dashed line is the end of your time. Times are planned for the slowest match of each round, so most evenings end a bit earlier.') }}</p>
            </div>

            <div class="flex flex-col gap-4">
                {{-- Options of the selected format, each with its help --}}
                <section aria-labelledby="opt-h" class="flex flex-col rounded-lg border border-hairline bg-ground px-4 py-3 lg:px-5">
                    <h3 id="opt-h" class="m-0 pb-2 text-[13px] font-bold">{{ __('Options for :format', ['format' => $format->label()]) }}</h3>

                    @if ($format === TournamentFormat::SingleElimination)
                        <div class="{{ $optionRow }}">
                            <label class="flex min-h-11 cursor-pointer items-center gap-2.5 text-[13px] font-bold">
                                <input type="checkbox" class="size-4 accent-btc" @checked($options->thirdPlace) wire:click="option('thirdPlace', {{ $options->thirdPlace ? 'false' : 'true' }})">
                                {{ __('Match for 3rd place') }}
                            </label>
                            <span class="{{ $help }}">{{ __('The two semifinal losers play one more match. Without it, both share 3rd place.') }}</span>
                        </div>
                    @endif

                    @if ($format === TournamentFormat::DoubleElimination)
                        <div class="flex flex-col gap-2 border-t border-hairline py-3">
                            <span class="text-[13px] font-bold">{{ __('Grand final') }}</span>
                            @foreach ([
                                ['reset', __('Grand final: lower-bracket winner must win twice'), __('Fair: the upper-bracket winner has not lost yet, so they get a second chance too. Adds a match only if needed.')],
                                ['single', __('Grand final: one match'), __('Shorter. The upper-bracket winner loses the advantage of being unbeaten.')],
                                ['skip', __('No grand final'), __('The upper-bracket winner wins the tournament, the lower-bracket winner is 2nd. They never play each other.')],
                            ] as [$value, $label, $text])
                                <label wire:key="gf-{{ $value }}" @class(['grid cursor-pointer grid-cols-[18px_minmax(0,1fr)] gap-2.5 rounded-md p-3', 'bg-btc-chip shadow-[inset_0_0_0_1px_var(--color-btc)]' => $options->grandFinal === $value, 'shadow-ring' => $options->grandFinal !== $value])>
                                    <input type="radio" name="grand-final" class="mt-0.5 accent-btc" @checked($options->grandFinal === $value) wire:click="option('grandFinal', '{{ $value }}')">
                                    <span class="flex flex-col gap-1"><span class="text-[13px] font-bold">{{ $label }}</span><span class="{{ $help }}">{{ $text }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        <div class="{{ $optionRow }}">
                            <label class="flex min-h-11 cursor-pointer items-center gap-2.5 text-[13px] font-bold">
                                <input type="checkbox" class="size-4 accent-btc" @checked($options->split) wire:click="option('split', {{ $options->split ? 'false' : 'true' }})" data-test="split-toggle">
                                {{ __('Lower seeds start in the lower bracket') }}
                            </label>
                            <span class="{{ $help }}" data-test="split-help">{{ __('The lowest seeds start in the lower bracket, with one life: here :seeds. Shorter, but less fair.', ['seeds' => $splitFrom === $n ? __('seed :seed', ['seed' => $n]) : __('seeds :from to :to', ['from' => $splitFrom, 'to' => $n])]) }}</span>
                        </div>
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
                            <span class="{{ $help }}">{{ __('Twice means a return match; in chess with the other color.') }}</span>
                        </div>
                        <div class="{{ $optionRow }}">
                            <label class="flex flex-col items-start gap-2 text-[13px] font-bold">
                                {{ __('Rank by') }}
                                <select wire:model.live="options.rankBy" class="h-11 w-full max-w-64 rounded-md border border-edge bg-ground px-3 text-[13px] font-normal text-ink">
                                    <option value="points">{{ __('Points (win 1, draw ½)') }}</option>
                                    <option value="match-wins">{{ __('Matches won') }}</option>
                                    <option value="game-wins">{{ __('Games won') }}</option>
                                    <option value="custom">{{ __('Custom points') }}</option>
                                </select>
                            </label>
                            <span class="{{ $help }}">{{ __('Matches won counts wins. Games won counts single games inside a series. Custom points lets you set points for a win and a draw.') }}</span>
                        </div>
                        @include('pages.admin.partials.tournament-tie-breaks', ['key' => 'roundRobinTieBreaks', 'current' => $options->roundRobinTieBreaks, 'labels' => array_diff_key($tieBreakLabels, ['median-buchholz' => true])])
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

                    @if ($series)
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
                <section aria-labelledby="sel-h" class="flex flex-col gap-4 rounded-lg border border-hairline bg-ground px-4 py-5 lg:px-5" data-test="selected-format">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h3 id="sel-h" class="m-0 font-display text-lg font-bold">{{ $format->label() }}</h3>
                        @if ($format === $evaluation->recommended)
                            <span class="inline-flex h-[22px] items-center gap-1 rounded-xs bg-btc-press px-2 text-[11px] font-bold text-btc-hi"><x-icon name="award" :size="12" />{{ __('Recommended') }}</span>
                        @endif
                        <span class="grow"></span>
                        <span class="text-[13px] text-ink-2">{{ __('about') }} <b class="text-ink">{{ $duration($chosen->total()) }}</b></span>
                    </div>
                    <p class="m-0 text-[13px] leading-relaxed">{{ __($copy['how']) }}</p>

                    <figure class="m-0 flex flex-col gap-2 rounded-md bg-card p-3" x-data x-ref="figure" wire:key="preview-{{ $previewKey }}" data-test="preview">
                        @foreach ([['desktop', $previewDesktop, 480, 208, 'hidden lg:block'], ['mobile', $previewMobile, 326, 196, 'lg:hidden']] as [$size, $preview, $width, $height, $visibility])
                            <svg viewBox="0 0 {{ $width }} {{ $height }}" class="tf-preview {{ $visibility }} h-auto w-full" role="img" aria-label="{{ __('Preview of :format for :who', ['format' => $format->label(), 'who' => $who($n)]) }}" wire:key="pv-{{ $size }}">
                                @foreach ($preview['lines'] as $line)
                                    <path d="{{ $line['d'] }}" fill="none" stroke="#3A3A42" stroke-width="1" style="animation-delay: {{ $line['delay'] }}s" />
                                @endforeach
                                @foreach ($preview['rects'] as $rect)
                                    <rect x="{{ $rect['x'] }}" y="{{ $rect['y'] }}" width="{{ $rect['w'] }}" height="{{ $rect['h'] }}" rx="2"
                                          fill="{{ $rect['ghost'] ? 'transparent' : '#F7931A' }}" stroke="{{ $rect['ghost'] ? '#8B8B90' : 'none' }}" @if ($rect['ghost']) stroke-dasharray="3 3" @endif
                                          style="animation-delay: {{ $rect['delay'] }}s" />
                                @endforeach
                                @foreach ($preview['texts'] as $text)
                                    <text x="{{ $text['x'] }}" y="{{ $text['y'] }}" text-anchor="{{ $text['anchor'] }}" fill="#8B8B90" font-size="10" font-family="JetBrains Mono, monospace">{{ __($text['text'], $text['replace']) }}</text>
                                @endforeach
                            </svg>
                        @endforeach
                        <figcaption class="flex items-start gap-3 text-xs leading-normal text-ink-2">
                            <span class="grow">{{ $caption }}</span>
                            <button type="button" class="shrink-0 cursor-pointer text-btc hover:text-btc-hi" data-test="preview-replay"
                                    x-on:click="$refs.figure.querySelectorAll('svg').forEach((svg) => { svg.classList.remove('tf-preview'); void svg.getBoundingClientRect(); svg.classList.add('tf-preview'); })">{{ __('Play again') }}</button>
                        </figcaption>
                    </figure>

                    <dl class="m-0 grid grid-cols-2 gap-2 lg:grid-cols-4">
                        @foreach ($facts as [$term, $value])
                            <div class="flex flex-col gap-0.5 rounded-md bg-card px-3 py-2">
                                <dt class="text-[11px] text-ink-3">{{ $term }}</dt>
                                <dd class="m-0 text-[13px] font-bold">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="m-0 text-[13px] leading-relaxed"><b>{{ __('Guaranteed games.') }}</b> <span data-test="guaranteed">{{ $guaranteed }}</span></p>
                    @if ($format === TournamentFormat::DoubleElimination && $options->grandFinal === 'reset')
                        <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Planned with the rematch: :duration, or :shorter if the grand final needs no rematch.', ['duration' => $duration($chosen->total()), 'shorter' => $duration($plan?->withoutIfNeeded ?? 0)]) }}</p>
                    @endif
                    <p class="m-0 text-[13px] leading-relaxed text-ink-2"><b class="text-ink">{{ __('Good for:') }}</b> {{ __($copy['good']) }}</p>
                    <div class="grid gap-4 lg:grid-cols-2">
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
    <section aria-labelledby="res-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:p-6" data-test="results-mode">
        <h2 id="res-h" class="m-0 font-display text-lg font-bold">{{ __('Who enters results') }}</h2>
        <div class="grid gap-2 lg:grid-cols-2">
            @foreach (TournamentResultsMode::cases() as $mode)
                <label wire:key="mode-{{ $mode->value }}" @class(['grid cursor-pointer grid-cols-[18px_minmax(0,1fr)] gap-2.5 rounded-md p-3', 'bg-btc-chip shadow-[inset_0_0_0_1px_var(--color-btc)]' => $this->resultsMode === $mode->value, 'bg-ground shadow-ring' => $this->resultsMode !== $mode->value])>
                    <input type="radio" name="results-mode" class="mt-0.5 accent-btc" @checked($this->resultsMode === $mode->value) wire:click="pickResultsMode('{{ $mode->value }}')" data-test="results-{{ $mode->value }}">
                    <span class="flex flex-col gap-1"><span class="text-[13px] font-bold">{{ $mode->label() }}</span><span class="text-xs leading-normal text-ink-2">{{ $mode->help() }}</span></span>
                </label>
            @endforeach
        </div>

        @if ($this->resultsMode === TournamentResultsMode::Director->value)
            <div class="flex flex-col gap-3" data-test="directors">
                <span class="text-[13px] font-bold">{{ __('Tournament directors') }}</span>
                <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
                    <li class="inline-flex h-9 items-center gap-2 rounded-md bg-ground px-2.5 text-[13px] shadow-ring">
                        <x-avatar :user="auth()->user()" :size="24" />{{ auth()->user()->displayName() }} <span class="text-ink-3">{{ __('(you)') }}</span>
                    </li>
                    @foreach ($this->directors as $director)
                        <li class="inline-flex h-9 items-center gap-2 rounded-md bg-ground pl-2.5 text-[13px] shadow-ring" wire:key="director-{{ $director->id }}">
                            <x-avatar :user="$director" :size="24" />{{ $director->displayName() }}
                            <button type="button" class="flex size-9 cursor-pointer items-center justify-center text-ink-2 hover:text-ink" wire:click="removeDirector({{ $director->id }})" aria-label="{{ __('Remove :name', ['name' => $director->displayName()]) }}"><x-icon name="close" :size="14" /></button>
                        </li>
                    @endforeach
                </ul>
                <form wire:submit="addDirector" class="flex flex-col gap-1.5">
                    <label for="dir-new" class="text-xs text-ink-2">{{ __('Add a director by name') }}</label>
                    <span class="flex gap-2">
                        <input id="dir-new" wire:model="directorName" placeholder="{{ __('Player name, e.g. nonce_nick') }}" class="h-11 min-w-0 grow rounded-md border border-edge bg-ground px-3 text-[13px] text-ink lg:max-w-80">
                        <x-button variant="quiet" type="submit">{{ __('Add') }}</x-button>
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
