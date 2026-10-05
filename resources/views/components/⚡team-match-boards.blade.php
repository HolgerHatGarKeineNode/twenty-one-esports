<?php

use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\SeriesMatch;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessTeamMatches;
use App\Support\Series\SeriesPresenter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The live view of a chess team match on its public page (plan "Schach Rapid
 * und Clan", P5; NIP rev. 9.22, "Start and board forfeit", "Team result"):
 * every board as a small board with both players and their clocks, the team
 * score over the boards that have ended, and a link to each board's own page,
 * where anyone with the link watches live as at any game.
 *
 * Live: resources/js/teamMatchBoards.js listens on each board's public watch
 * channel (`game.{id}.watch`) and asks for a render after a move, and polls
 * slowly for what has no push for spectators (the start, the team result).
 * The running clocks count down in the browser between renders. Before the
 * lock the players stay hidden, as everywhere (ChessTeamMatches::visibleTo()).
 */
new class extends Component {
    #[Locked]
    public int $matchId;

    public function mount(SeriesMatch $match): void
    {
        $this->matchId = $match->id;
    }

    #[Computed]
    public function match(): SeriesMatch
    {
        return SeriesMatch::query()->with(['challengerLineup.clan', 'challengedLineup.clan'])->findOrFail($this->matchId);
    }
}; ?>

@php
    $m = $this->match;
    $viewer = auth()->user();
    $locked = $m->lineup_locked_at !== null;
    $results = $locked ? ChessTeamMatches::boardResults($m) : [];
    $score = ChessTeamMatches::score($results);
    $nowMs = (int) now()->getTimestampMs();
    $service = app(ChessGameService::class);
    $live = $m->status === SeriesStatus::Accepted;
    $colors = ['challenger' => '#F7931A', 'challenged' => '#A78BFA'];
    $status = match (true) {
        $m->status === SeriesStatus::Resolved && $m->resolution === SeriesResolution::Void => [__('Void · no winner'), 'bg-well text-ink-2'],
        $m->status === SeriesStatus::Resolved && $m->resolution === SeriesResolution::Forfeit => [__(':clan wins by forfeit', ['clan' => $m->sideName((string) $m->winner)]), 'bg-win-tint text-win'],
        $m->status->hasResult() && in_array($m->winner, SeriesMatch::SIDES, true) => [__(':clan wins the team match', ['clan' => $m->sideName((string) $m->winner)]), 'bg-win-tint text-win'],
        $m->status->hasResult() => [__('Team draw · no bonus'), 'bg-well text-ink'],
        ! $live => [SeriesPresenter::chip($m)['label'], 'bg-well text-ink-2'],
        ! $locked => [__('Lineups lock :time', ['time' => SeriesPresenter::time(ChessTeamMatches::lockAt($m) ?? now(), $viewer, 'D H:i')]), 'bg-well text-ink-2'],
        $m->start_at !== null && $m->start_at->isFuture() => [__('Boards start :time', ['time' => SeriesPresenter::time($m->start_at, $viewer, 'H:i')]), 'bg-btc-chip text-btc-hi'],
        default => [__('Live'), 'bg-loss-tint text-loss'],
    };
    // Only what stays put between renders: a changed x-data would start the component again. The boards
    // (data-game) and the server time (data-server-now) are read from the markup.
    $config = [
        'running' => $live,
        'startAt' => $m->start_at?->getTimestampMs(),
        'poll' => 20,
    ];
    $grid = (int) $m->boards === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-2';
    // As resources/js/teamMatchBoards.js formats it between renders: m:ss, rounded up.
    $clock = fn (int $ms): string => intdiv((int) ceil(max(0, $ms) / 1000), 60).':'.str_pad((string) ((int) ceil(max(0, $ms) / 1000) % 60), 2, '0', STR_PAD_LEFT);
@endphp

<section aria-labelledby="team-boards-h" class="flex min-w-0 flex-col gap-4" x-data="teamMatchBoards(@js($config))" data-server-now="{{ $nowMs }}" data-test="team-match-boards">
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <h2 id="team-boards-h" class="m-0 font-display text-xl font-bold">{{ trans_choice(':count board|:count boards', (int) $m->boards) }}</h2>
        <span class="inline-flex min-h-8 items-center rounded-md px-3.5 text-[13px] font-bold {{ $status[1] }}" data-test="team-status">{{ $status[0] }}</span>
    </div>

    <div class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3 rounded-lg bg-card px-4 py-4 lg:px-6" data-test="team-score">
        @foreach (SeriesMatch::SIDES as $index => $side)
            @if ($index === 1)
                <b class="font-display text-[28px] leading-none tabular-nums lg:text-[34px]" aria-label="{{ __('Team score') }}" data-test="team-score-value">{{ ChessTeamMatches::points($score['challenger']) }} : {{ ChessTeamMatches::points($score['challenged']) }}</b>
            @endif
            <span @class(['flex min-w-0 items-center gap-2 text-[13px] lg:text-[15px]', 'justify-end text-right' => $side === 'challenger'])>
                <span class="size-3 shrink-0 rounded-full" style="background: {{ $colors[$side] }}" aria-hidden="true"></span>
                <b class="min-w-0 [overflow-wrap:anywhere]">{{ $m->sideName($side) }}</b>
            </span>
        @endforeach
    </div>

    @unless ($locked)
        <p class="m-0 rounded-lg bg-card px-4 py-4 text-[13px] leading-normal text-ink-2 lg:px-6" data-test="team-boards-hidden">{{ __('The players and their boards are public from the lineup lock, :minutes minutes before the start.', ['minutes' => ChessTeamMatches::lockMinutes()]) }}</p>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 {{ $grid }}">
            @foreach ($results as $row)
                @php
                    $game = $row['game'];
                    $black = SeriesMatch::otherSide($row['white']);
                    $clocks = $game === null ? null : $service->clocks($game, $nowMs);
                    $running = $game !== null && $game->isActive() && $game->clocksRunning() ? $game->turn() : null;
                    $firstMove = $game !== null && $game->isActive() && ! $game->clocksRunning() ? $game->turn() : null;
                    $last = $game !== null && $game->ply > 0 ? $game->moves()->where('ply', $game->ply)->first() : null;
                    $chip = match ($row['outcome']) {
                        'live' => [__('Live'), 'text-loss'],
                        'pending' => [__('Not started'), 'text-ink-2'],
                        'void' => [__('Void'), 'text-ink-2'],
                        'forfeit' => [__(':clan by forfeit', ['clan' => $m->sideName($row['showed'][0] ?? 'challenger')]), 'text-win'],
                        default => [$game?->result === '1/2-1/2' ? __('Draw') : __(':clan wins', ['clan' => $m->sideName($row['points']['challenger'] > $row['points']['challenged'] ? 'challenger' : 'challenged')]), 'text-win'],
                    };
                @endphp
                <article class="flex min-w-0 flex-col gap-3 rounded-lg bg-card p-4" wire:key="team-board-{{ $row['board'] }}" data-test="team-board-{{ $row['board'] }}" @if ($game) data-game="{{ $game->id }}" @endif>
                    <span class="flex items-baseline justify-between gap-3">
                        <b class="text-[15px]">{{ __('Board :n', ['n' => $row['board']]) }}</b>
                        <span class="text-xs font-bold {{ $chip[1] }}" data-test="team-board-state-{{ $row['board'] }}">{{ $chip[0] }}</span>
                    </span>
                    @foreach ([$black => 'b', $row['white'] => 'w'] as $side => $color)
                        @if ($color === 'w')
                            <x-match-dock.board class="w-full" :fen="$game?->fen ?? \App\Models\ChessGame::START_FEN" :last="$last ? [substr($last->uci, 0, 2), substr($last->uci, 2, 2)] : []"
                                                :label="__('Board :n of :number', ['n' => $row['board'], 'number' => $m->label()])" data-test="team-board-fen-{{ $row['board'] }}" data-fen="{{ $game?->fen ?? \App\Models\ChessGame::START_FEN }}" />
                        @endif
                        <span class="flex min-h-9 min-w-0 items-center gap-2 text-[13px]">
                            <span class="size-3 shrink-0 rounded-full" style="background: {{ $colors[$side] }}" aria-hidden="true"></span>
                            <span class="flex min-w-0 grow flex-col">
                                <span class="truncate font-bold">{{ $row['players'][$side]?->displayName() ?? __('Deleted account') }}</span>
                                <span class="text-[11px] text-ink-3">{{ $color === 'w' ? __('White') : __('Black') }} · {{ $m->sideName($side) }}</span>
                            </span>
                            @if ($clocks !== null)
                                <b @class(['shrink-0 rounded-sm px-2 py-1 font-mono text-[15px] tabular-nums', 'bg-btc text-on-btc' => $running === $color, 'bg-well text-ink' => $running !== $color])
                                   data-clock='@json(['ms' => $clocks[$color], 'running' => $running === $color])' data-test="team-board-clock-{{ $row['board'] }}-{{ $color }}">{{ $clock($clocks[$color]) }}</b>
                            @endif
                        </span>
                    @endforeach
                    @if ($firstMove !== null && $game->deadline_ms !== null)
                        <span class="text-xs text-btc-hi" data-test="team-board-first-move-{{ $row['board'] }}">{{ $firstMove === 'w' ? __('White to make the first move') : __('Black to make the first move') }} · <span data-clock='@json(['ms' => max(0, $game->deadline_ms - $nowMs), 'running' => true])'>{{ $clock($game->deadline_ms - $nowMs) }}</span></span>
                    @endif
                    @if ($game !== null)
                        <a href="{{ route('games.show', $game) }}" class="btn-w inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-line bg-well px-4 text-[13px] text-ink hover:text-ink" data-test="team-board-link-{{ $row['board'] }}">
                            <x-icon name="eye" :size="16" />{{ $game->isActive() ? __('Watch board :n', ['n' => $row['board']]) : __('Open board :n', ['n' => $row['board']]) }}
                        </a>
                    @endif
                </article>
            @endforeach
        </div>
        <p class="m-0 text-xs leading-normal text-ink-2">{{ __('A win is 1 point, a draw ½. A player who misses the first move within :minutes minutes of the start loses the board by forfeit. More points win the team match; equal points are a team draw without the team bonus.', ['minutes' => intdiv(ChessTeamMatches::firstMoveSeconds(), 60)]) }}</p>
    @endunless
</section>
