{{--
    The public view of each stage (TournamentShow.dc.html bracket,
    TournamentFormats.dc.html for the other systems): a bracket in columns
    by round, a table with its pairings, or heats.

    $stages: TournamentView::stages()
--}}
@foreach ($stages as $stage)
    <section aria-label="{{ count($stages) > 1 ? $stage['title'] : __('Bracket') }}" class="flex flex-col gap-3" data-test="stage" data-format="{{ $stage['format']->value }}">
        @if (count($stages) > 1)
            <h3 class="m-0 text-[15px] font-bold">{{ $stage['title'] }}</h3>
        @endif

        <div @class(['grid gap-4', 'lg:grid-cols-2' => count($stage['parts']) > 1])>
            @foreach ($stage['parts'] as $part)
                <div @class(['flex min-w-0 flex-col gap-3', 'rv-card px-4 py-5 lg:px-6' => count($stage['parts']) > 1 || count($stages) > 1])>
                    @if ($part['title'])
                        <h3 class="m-0 text-[15px] font-bold">{{ $part['title'] }}</h3>
                    @endif

                    @if ($part['kind'] === 'bracket')
                        @foreach ($part['sections'] as $section)
                            @if ($section['title'])
                                <h4 class="m-0 text-xs font-normal text-ink-3">{{ $section['title'] }}</h4>
                            @endif
                            {{-- Only this container scrolls sideways on a phone, never the page. --}}
                            <div class="overflow-x-auto overscroll-x-contain pb-1" data-test="bracket-scroll">
                                <div class="tl-bracket flex min-w-max gap-6">
                                    @foreach ($section['columns'] as $column)
                                        <div class="tl-col flex w-[190px] flex-col gap-3">
                                            <span class="text-xs text-ink-3">{{ $column['label'] }}</span>
                                            <div class="flex grow flex-col justify-around gap-3">
                                                @foreach ($column['matches'] as $box)
                                                    <div class="tl-match relative">
                                                        @include('pages.tournaments.partials.box', ['box' => $box])
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                    @if (($champion ?? null) !== null && $loop->parent->parent->last && $loop->last && $part['kind'] === 'bracket')
                                        {{-- The champion card at the end of the bracket (TournamentDetail.dc.html): the name, the prize and the record. --}}
                                        <div class="flex w-[220px] flex-col justify-center" data-test="bracket-champion">
                                            <div class="rv-glow flex flex-col gap-2 rounded-[4px] bg-feature p-4">
                                                <span class="flex items-center gap-1.5 text-[13px] font-bold text-btc"><x-icon name="trophy" :size="16" />{{ __('Champion') }}</span>
                                                <b class="text-lg leading-tight [overflow-wrap:anywhere]">{{ $champion['name'] }}</b>
                                                @if ($champion['line'] !== '')
                                                    <span class="rv-m">{{ $champion['line'] }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
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
                                        <tr @class(['border-t border-hairline', 'text-ink-3' => $row['out'] ?? false]) @if ($row['out'] ?? false) data-out @endif>
                                            <td class="py-2 pr-2 text-ink-3">{{ $row['rank'] }}</td>
                                            <td class="w-full max-w-0 py-2 pr-2"><span class="flex min-w-0 items-center gap-2"><x-clan-tag :clan="$row['clan']" :tag="$row['tag']" size="sm" /><span class="min-w-0 truncate">{{ $row['name'] }}</span>@if ($row['out'] ?? false)<span class="rv-tag is-bad shrink-0" title="{{ __('Disqualified') }}" data-test="standings-out"><span class="sm:hidden">{{ __('DQ') }}</span><span class="max-sm:hidden">{{ __('Disqualified') }}</span></span>@endif</span></td>
                                            <td class="py-2 pr-2 text-right font-bold tabular-nums">{{ $row['points'] }}</td>
                                            <td class="py-2 pr-2 text-right whitespace-nowrap tabular-nums text-ink-2">{{ $row['wins'] }} {{ $row['ties'] }} {{ $row['losses'] }}</td>
                                            <td class="py-2 text-right tabular-nums text-ink-2">{{ $stage['format'] === \App\Enums\TournamentFormat::Swiss ? $row['buchholz'] : $row['games'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if (($part['first_tie'] ?? null) !== null)
                            {{-- A tie for place 1 nothing split (FirstPlaceTie; DerCaddy, 2026-11-06: "und dann auch irgendwo sichtbar sein"):
                                 who is level and what decides, the organizer's choice. --}}
                            <p class="m-0 max-w-[68ch] text-xs leading-snug text-ink-2" data-test="standings-first-tie" data-mode="{{ $part['first_tie']['mode'] }}">{{ $part['first_tie']['mode'] === 'playoff'
                                ? __('Level on points and every tie-break: :names. A tie-break match decides 1st place.', ['names' => implode(', ', $part['first_tie']['names'])])
                                : __('Level on points and every tie-break: :names share 1st place.', ['names' => implode(', ', $part['first_tie']['names'])]) }}</p>
                        @endif
                        @if ($part['two_legs'] ?? false)
                            {{-- Blockli: the table ranks pairings of two legs (Nidan21 2026-10-17: a won and a lost game read as a wrong draw), or
                                 each game where the organizer chose points per game (DerCaddy, 2026-10-18), every game in the table once it ends. --}}
                            {{-- A combined tournament's pairing plays its whole sequence: the note counts its games (leg_count). --}}
                            @php($legCount = $part['leg_count'] ?? 2)
                            <p class="m-0 max-w-[68ch] text-xs leading-snug text-ink-3" data-test="standings-legs-note">{{ match (true) {
                                ($part['per_leg'] ?? false) && $legCount === 2 => __('Every game counts: a win 1 point, a draw ½, up to 2 points per pairing. W D L counts games; a game enters the table once it is over.'),
                                $part['per_leg'] ?? false => __('Every game counts: a win 1 point, a draw ½, up to :count points per pairing. W D L counts games; a game enters the table once it is over.', ['count' => $legCount]),
                                $legCount === 2 => __('W D L counts pairings: first and second leg together. A pairing enters the table once both legs are over.'),
                                default => __('W D L counts pairings: all :count games together. A pairing enters the table once all :count are over.', ['count' => $legCount]),
                            } }}</p>
                            @if ($part['open'] !== [])
                                <div class="flex flex-col gap-1" data-test="standings-open">
                                    <span class="text-xs text-ink-3">{{ __('Pairings under way') }}</span>
                                    <ul class="m-0 flex list-none flex-col p-0 text-[13px]">
                                        @foreach ($part['open'] as $open)
                                            <li class="flex items-center gap-2 border-t border-hairline py-1.5" data-test="standings-open-row">
                                                <span class="min-w-0 flex-1 truncate">{{ $open['a'] }}</span>
                                                <b class="shrink-0 tabular-nums">{{ $open['score'] }}</b>
                                                <span class="min-w-0 flex-1 truncate text-right">{{ $open['b'] }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endif
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
