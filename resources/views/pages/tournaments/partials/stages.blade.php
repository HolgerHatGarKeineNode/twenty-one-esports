{{--
    The public view of each stage (TournamentShow.dc.html bracket,
    TournamentFormats.dc.html for the other systems): a bracket in columns
    by round, a table with its pairings, or heats.

    $stages: TournamentView::stages()
--}}
@foreach ($stages as $stage)
    <section aria-labelledby="stage-{{ $stage['number'] }}-h" class="flex flex-col gap-3" data-test="stage" data-format="{{ $stage['format']->value }}">
        <h2 id="stage-{{ $stage['number'] }}-h" class="m-0 font-display text-xl font-bold">{{ count($stages) > 1 ? $stage['title'] : __('Bracket') }}</h2>

        <div @class(['grid gap-4', 'lg:grid-cols-2' => count($stage['parts']) > 1])>
            @foreach ($stage['parts'] as $part)
                <div class="flex min-w-0 flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
                    @if ($part['title'])
                        <h3 class="m-0 text-[15px] font-bold">{{ $part['title'] }}</h3>
                    @endif

                    @if ($part['kind'] === 'bracket')
                        @foreach ($part['sections'] as $section)
                            @if ($section['title'])
                                <h4 class="m-0 text-xs font-normal text-ink-3">{{ $section['title'] }}</h4>
                            @endif
                            <div class="overflow-x-auto" data-test="bracket-scroll">
                                <div class="flex min-w-max gap-4">
                                    @foreach ($section['columns'] as $column)
                                        <div class="flex w-[220px] flex-col justify-around gap-3">
                                            <span class="text-xs text-ink-3">{{ $column['label'] }}</span>
                                            @foreach ($column['matches'] as $box)
                                                @include('pages.tournaments.partials.box', ['box' => $box])
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    @elseif ($part['kind'] === 'table')
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[320px] border-collapse text-left text-[13px]" data-test="standings">
                                <thead class="text-xs text-ink-3">
                                    <tr>
                                        <th class="py-2 pr-2 font-normal">#</th>
                                        <th class="py-2 pr-2 font-normal">{{ $tournament->profile()->entersTeams() ? __('Team') : __('Player') }}</th>
                                        <th class="py-2 pr-2 text-right font-normal">{{ __('Pts') }}</th>
                                        <th class="py-2 pr-2 text-right font-normal">{{ __('W D L') }}</th>
                                        @if ($stage['format'] === \App\Enums\TournamentFormat::Swiss)
                                            <th class="py-2 text-right font-normal">{{ __('Buchholz') }}</th>
                                        @else
                                            <th class="py-2 text-right font-normal">{{ __('Games') }}</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($part['rows'] as $row)
                                        <tr class="border-t border-hairline">
                                            <td class="py-2 pr-2 text-ink-3">{{ $row['rank'] }}</td>
                                            <td class="py-2 pr-2"><span class="flex items-center gap-2"><x-clan-tag :clan="$row['clan']" :tag="$row['tag']" size="sm" /><span class="truncate">{{ $row['name'] }}</span></span></td>
                                            <td class="py-2 pr-2 text-right font-bold tabular-nums">{{ $row['points'] }}</td>
                                            <td class="py-2 pr-2 text-right whitespace-nowrap tabular-nums text-ink-2">{{ $row['wins'] }} {{ $row['ties'] }} {{ $row['losses'] }}</td>
                                            <td class="py-2 text-right tabular-nums text-ink-2">{{ $stage['format'] === \App\Enums\TournamentFormat::Swiss ? $row['buchholz'] : $row['games'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @foreach (array_reverse($part['rounds'], true) as $number => $round)
                            <details @if ($loop->first) open @endif class="group">
                                <summary class="flex min-h-9 cursor-pointer items-center gap-2 text-[13px] font-bold">
                                    {{ __('Round :round', ['round' => $number]) }}
                                    <x-icon name="chevron-down" :size="14" class="transition-transform group-open:rotate-180" />
                                </summary>
                                <div class="grid gap-2 pt-2 sm:grid-cols-2">
                                    @foreach ($round as $box)
                                        @if ($box['bracket'] === 'bye')
                                            <span class="rounded-md bg-raised px-3 py-2.5 text-[13px] text-ink-2">{{ __(':name has a bye this round.', ['name' => $box['sides'][0]['name'] ?? '']) }}</span>
                                        @else
                                            @include('pages.tournaments.partials.box', ['box' => $box])
                                        @endif
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                    @else
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($part['heats'] as $box)
                                @include('pages.tournaments.partials.box', ['box' => $box])
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endforeach
