{{--
    The scoreboard result entry (T13, MatchRoom-3-Ergebnis.dc.html and its phone board; user 2026-10-10: "konnte
    nicht schnell erkennen, wie ich eintragen muss, weil diese beiden Namen so grau und so klein sind"): both
    sides as big named blocks, the leader marked live; per game −/+ steppers of 48 px around a big score field,
    "Winner only" as a switch (goals dropped, a "… wins" pair instead), the refusal at its own row. A game without
    goals (Age of Empires II) has only the "… wins" pair. The submit and the summary line follow in the report
    block. Needs $m, $sheet, $hasGoals, $editable, $mySide, $wins, $casual, $errorRow, $error and $captainOf;
    optional $sheetTitle and $sheetHint (the check step names it "Check the result", who reported). A game the
    series no longer needs and nobody entered is left out (board MatchRoom-3: only the games played).
--}}
@php
    use App\Models\SeriesMatch;
    use App\Enums\SeriesStatus;

    $leader = $wins['challenger'] === $wins['challenged'] ? null : ($wins['challenger'] > $wins['challenged'] ? 'challenger' : 'challenged');
    $hostSide = $m->host_side === 'challenged' ? 'challenged' : 'challenger';
    $hosted = $casual || \App\Support\Tournaments\LeagueLobby::isLeagueMade($m);
    $roleOf = function (string $side) use ($hosted, $hostSide, $mySide, $m, $captainOf): string {
        $role = $hosted ? ($side === $hostSide ? __('host') : __('guest')) : ($captainOf($side) !== '' && $captainOf($side) !== $m->sideName($side) ? __('captain :name', ['name' => $captainOf($side)]) : '');

        return trim($role.($side === $mySide ? ($role === '' ? '' : ', ').__('you') : ''));
    };
    $initial = fn (string $name): string => mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : '?', 0, 1));
    $playingNow = $m->status === SeriesStatus::Accepted && ! $m->start_at?->isFuture();
@endphp
<section aria-labelledby="games-h" class="flex flex-col gap-4 px-4 pt-5 pb-4 lg:px-6" data-test="games">
    <span class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="games-h" class="rv-h3">{{ $sheetTitle ?? ($editable ? __('Report the result') : __('Games in this series')) }}</h2>
        <span class="rv-m text-[13px]">{{ $sheetHint ?? ($hasGoals ? __('after each game, enter the team goals from the end screen') : __('after each game, pick its winner')) }}</span>
    </span>

    {{-- The two sides, big and named; the one ahead is marked (never by colour alone: "leads"). --}}
    <div class="rv-sb" data-test="scoreboard-sides">
        @foreach (['challenger', 'vs', 'challenged'] as $cell)
            @if ($cell === 'vs')
                <span class="font-display text-xl text-ink-3 max-sm:hidden" aria-hidden="true">{{ __('against') }}</span>
            @else
                <div @class(['rv-side', 'is-lead' => $leader === $cell, 'sm:flex-row-reverse sm:text-right' => $cell === 'challenged']) data-test="scoreboard-side-{{ $cell }}" data-lead="{{ $leader === $cell ? '1' : '0' }}">
                    <span @class(['rv-av', 'is-o' => $cell === 'challenger']) @style(['background: var(--color-ink-2); color: var(--color-on-btc)' => $cell === 'challenged']) aria-hidden="true">{{ $initial($m->sideName($cell)) }}</span>
                    <span @class(['flex min-w-0 grow flex-col', 'sm:items-end' => $cell === 'challenged'])>
                        <b class="rv-side-name" title="{{ $m->sideName($cell) }}" data-test="sheet-name-{{ $cell }}">{{ $m->sideName($cell) }}</b>
                        <span class="flex flex-wrap items-center gap-2">
                            @if ($roleOf($cell) !== '')<span class="rv-m">{{ $roleOf($cell) }}</span>@endif
                            @if ($leader === $cell)<span class="rv-tag is-btc">{{ __('leads') }}</span>@endif
                        </span>
                    </span>
                </div>
            @endif
        @endforeach
    </div>

    <div class="flex flex-col">
        @foreach ($sheet as $index => $row)
            @php
                $decided = $index > 0 && max(SeriesMatch::seriesScore(array_slice(array_map(fn ($r) => ['winner' => $r['winner']], $sheet), 0, $index))) >= intdiv($m->best_of, 2) + 1;
                $current = $row['winner'] === null && ! $decided && ($index === 0 || $sheet[$index - 1]['winner'] !== null);
                $winnerMode = ! $hasGoals || $row['unknown'];
                $canEdit = $editable && ! $decided;
                $c = is_numeric($row['c']) ? (int) $row['c'] : null;
                $d = is_numeric($row['d']) ? (int) $row['d'] : null;
                $rowLead = $c !== null && $d !== null && $c !== $d ? ($c > $d ? 'c' : 'd') : null;
                $rowError = $errorRow === $index && $error !== '' ? $error : null;
                $statusText = match (true) {
                    $row['winner'] !== null => __(':tag win', ['tag' => $m->sideName($row['winner'])]),
                    $decided => __('not needed'),
                    $current && $playingNow => __('playing now'),
                    default => __('not played'),
                };
            @endphp
            @continue($decided && $row['winner'] === null && $row['c'] === null && $row['d'] === null)
            <div wire:key="g-{{ $index }}" @class(['grid grid-cols-1 items-center gap-3 border-b border-hairline py-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:gap-4', 'opacity-50' => $decided]) data-test="game-row" data-index="{{ $index }}">
                <span class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <b class="whitespace-nowrap">{{ __('Game :n', ['n' => $index + 1]) }}</b>
                    @if ($hasGoals && $canEdit)
                        <button type="button" role="switch" class="rv-ck" aria-checked="{{ $row['unknown'] ? 'true' : 'false' }}" wire:click="toggleWinnerOnly({{ $index }})" data-test="winner-only-{{ $index }}"><i aria-hidden="true"></i>{{ __('Winner only') }}</button>
                    @elseif ($hasGoals && $row['unknown'])
                        <span class="rv-m">{{ __('Winner only') }}</span>
                    @endif
                </span>

                @if ($winnerMode)
                    <div role="group" aria-label="{{ __('Winner of game :n', ['n' => $index + 1]) }}" class="grid w-full grid-cols-2 gap-2 sm:w-[352px]" data-test="winner-{{ $index }}">
                        @foreach (SeriesMatch::SIDES as $side)
                            <button type="button" class="rv-win" aria-pressed="{{ $row['winner'] === $side ? 'true' : 'false' }}" wire:click="pickWinner({{ $index }}, '{{ $side }}')" @disabled(! $canEdit) data-test="winner-{{ $index }}-{{ $side }}">
                                <span>{{ __(':name wins', ['name' => $m->sideName($side)]) }}</span>
                            </button>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-center">
                        @foreach (['c' => 'challenger', 'd' => 'challenged'] as $box => $side)
                            @if ($box === 'd')
                                <span class="font-display text-[22px] text-ink-3 max-sm:hidden" aria-hidden="true">:</span>
                            @endif
                            <span class="flex items-center gap-2">
                                <span class="min-w-0 grow truncate font-bold sm:hidden">{{ $m->sideName($side) }}</span>
                                @if ($canEdit)
                                    <button type="button" class="rv-step" wire:click="stepGoal({{ $index }}, '{{ $box }}', -1)" aria-label="{{ __('One goal less for :name in game :n', ['name' => $m->sideName($side), 'n' => $index + 1]) }}" @disabled(($box === 'c' ? $c : $d) === 0) data-test="goals-{{ $index }}-{{ $box }}-down">−</button>
                                @endif
                                <input type="number" inputmode="numeric" min="0" max="99" wire:model.live.blur="sheet.{{ $index }}.{{ $box }}" @disabled(! $canEdit) placeholder="0"
                                       aria-label="{{ __('Goals of :clan in game :n', ['clan' => $m->sideName($side), 'n' => $index + 1]) }}" data-test="goals-{{ $index }}-{{ $box }}"
                                       @class(['rv-sc', 'is-lead' => $rowLead === $box, 'is-err' => $rowError !== null && ($box === 'c' ? $c : $d) === null])>
                                @if ($canEdit)
                                    <button type="button" class="rv-step" wire:click="stepGoal({{ $index }}, '{{ $box }}', 1)" aria-label="{{ __('One goal more for :name in game :n', ['name' => $m->sideName($side), 'n' => $index + 1]) }}" @disabled(($box === 'c' ? $c : $d) === 99) data-test="goals-{{ $index }}-{{ $box }}-up">+</button>
                                @endif
                            </span>
                        @endforeach
                    </div>
                @endif

                <span class="flex min-w-0 flex-col gap-1 sm:items-end sm:text-right">
                    {{-- A long name shortens with an ellipsis, the verb stays ("…Replay gewinnt"): clamped whole, the line lost "gewinnt". --}}
                    @php([$winBefore, $winAfter] = $row['winner'] !== null ? array_pad(explode('%name%', __(':tag win', ['tag' => '%name%']), 2), 2, '') : ['', ''])
                    <span title="{{ $statusText }}" @class(['flex max-w-full min-w-0 text-[13px] whitespace-nowrap sm:justify-end', 'text-win' => $row['winner'] !== null && $row['winner'] === ($mySide ?? 'challenger'), 'text-loss' => $row['winner'] !== null && $row['winner'] !== ($mySide ?? 'challenger'), 'text-btc' => $row['winner'] === null && $current && $playingNow, 'text-ink-3' => $row['winner'] === null && ! ($current && $playingNow)]) data-test="game-status-{{ $index }}">@if ($row['winner'] !== null)<span class="shrink-0 whitespace-pre">{{ $winBefore }}</span><span class="min-w-0 truncate">{{ $m->sideName($row['winner']) }}</span><span class="shrink-0 whitespace-pre">{{ $winAfter }}</span>@else{{ $statusText }}@endif</span>
                    @if ($rowError !== null)
                        <span class="inline-flex items-center gap-1 text-[13px] text-loss" role="alert" data-test="game-error-{{ $index }}"><x-icon name="close" :size="12" class="shrink-0" />{{ $rowError }}</span>
                    @endif
                </span>
            </div>
        @endforeach
    </div>
</section>
