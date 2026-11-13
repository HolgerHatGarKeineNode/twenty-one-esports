<?php

use App\Games\Go;
use App\Support\Board\GoLabels;
use App\Support\Board\GoPractice;
use App\Support\Cards\PageCard;
use App\Support\PageMeta;
use Livewire\Attributes\Json;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Go practice against bots (DerCaddy, 2026-10-27: Go "nach dem Vorbild von Blockli", "auch mit einem interaktiven
 * Lerntutorial"): eight short lessons on the board (liberties and capturing, groups, out of atari, forbidden points, ko,
 * two eyes, the ladder, the end and the count), then three bots, the Hodler (never lets a stone go), the Miner (a short
 * search) and the Whale (a long one). On 9 x 9 whichever Go lobby leads here. For guests too; nothing is stored,
 * nothing counts.
 *
 * As Blockli's practice (pages/board/⚡practice): the game lives in the browser (resources/js/go/practice.js) and plays
 * on the league's own board (boardGame.js, its `source`), with the stone input and Go's bar of a real game. The page
 * keeps no game: `play` and `open` are a judge without memory, they get the position the browser holds and answer by
 * GoRules (App\Support\Board\GoPractice). The bots and the tips run in the browser on its go engine.
 *
 * One page for all sizes, at /games/go/practice (each Go lobby's tile leads here), while one is switched on; 404
 * otherwise. Its context bar and card are those of the smallest size on (Go::home()).
 */
new #[Layout('layouts::app', ['scripts' => ['resources/js/go/practice.js']])] class extends Component
{
    #[Locked]
    public string $slug = '';

    public function mount(): void
    {
        $this->slug = Go::home() ?? abort(404);
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $title = __(':game · Practice', ['game' => 'Go']);
        $view->title($title);
        app(PageMeta::class)->describe($title, __('Learn Go in eight short lessons on the board, then play three bots on 9 x 9, with small tips on the way. Nothing here counts for the ladder.'))
            ->card(fn () => PageCard::page('board.'.$this->slug));
    }

    /** What the board needs for a position the browser starts from (GoPractice::open()). @return array<string, mixed> */
    #[Json]
    public function open(mixed $position = null): array
    {
        return app(GoPractice::class)->open($position);
    }

    /** A move in the position the browser holds, judged by the rules (GoPractice::play()). @return array<string, mixed> */
    #[Json]
    public function play(mixed $position = null, mixed $move = null, mixed $history = []): array
    {
        return app(GoPractice::class)->play($position, $move, $history);
    }

    /**
     * Everything the practice script needs, translated once here: the board's drawing and labels, the start position,
     * the lessons with their positions opened, the bots, and the words of the tips.
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        $practice = app(GoPractice::class);
        $rules = $practice->rules();
        $start = $rules->serialize($rules->start());
        $layout = $rules->view($rules->start());
        unset($layout['pieces']);

        return [
            'layout' => $layout,
            'start' => ['position' => $start, 'view' => $practice->open($start)],
            'lessons' => array_map(fn (array $lesson): array => [
                'id' => $lesson['id'],
                'position' => $lesson['position'],
                'goal' => $lesson['goal'],
                'min' => $lesson['min'] ?? null,
                'point' => $lesson['point'] ?? null,
                'points' => $lesson['points'] ?? [],
                'closing' => $lesson['closing'] ?? null,
                'reply' => $lesson['reply'],
                'count' => $lesson['count'] ?? false,
                'pass' => $lesson['pass'] ?? false,
                'title' => __($lesson['title']),
                'text' => __($lesson['text']),
                'task' => __($lesson['task']),
                'done' => __($lesson['done']),
                'retry' => __($lesson['retry']),
                'closingNote' => isset($lesson['closingNote']) ? __($lesson['closingNote']) : null,
                'view' => $practice->open($lesson['position']),
            ], GoPractice::LESSONS),
            'bots' => [
                'hodler' => ['name' => __('Hodler'), 'level' => __('Easy'), 'text' => __('Never lets a stone go: takes whatever stands in atari and saves his own.')],
                'miner' => ['name' => __('Miner'), 'level' => __('Medium'), 'text' => __('Digs: plays a few thousand games in his head before each move.')],
                'whale' => ['name' => __('Whale'), 'level' => __('Hard'), 'text' => __('Thinks long, up to forty thousand games a move. Few beat him on 9 x 9.')],
            ],
            'labels' => [
                'you' => __('You'),
                'white' => __('White'),
                'lessonOf' => __('Lesson :n of :total'),
                'nextLesson' => __('Next lesson'),
                'toBots' => __('On to the bots'),
                'yourMove' => __('Your move'),
                'toMove' => __(':name to move'),
                'thinking' => __(':name is thinking …'),
                'botFailed' => __('The bot could not move. Start a new game.'),
                'stones' => [trans_choice(':count stone|:count stones', 1, ['count' => ':count']), trans_choice(':count stone|:count stones', 2, ['count' => ':count'])],
                'result' => [
                    'won' => __('You beat :name by :points points!'),
                    'lost' => __(':name wins this one by :points points.'),
                    'resigned' => __('You resigned, :name wins.'),
                    'draw' => __('Draw.'),
                ],
                // The tips that come up by themselves (resources/js/go/tips.js autoTip); :name is the bot.
                'tips' => [
                    'captured' => __(':name took :stones of yours. A group with one liberty left is in atari: look out for it before it is too late.'),
                    'atari' => __('Careful: your group at :point (:stones) has one liberty left. Run onto it, or let it go on purpose.'),
                    'canTake' => __('A group of :name\'s (:stones) has one liberty left: you can take it.'),
                    'selfAtari' => __('That stone took a liberty from your own group: :stones stand in atari now.'),
                    'firstLine' => __('Early on, a stone on the edge brings little: the third and fourth lines make more area.'),
                    'botPassed' => __(':name passed. Before you pass too: does a stone of :name\'s still stand in your area? It counts as long as it stands, so take it first.'),
                ],
                // The tip button (tips.js adviceFor): first what kind of move fits, then where; never the move itself.
                'advice' => [
                    'thinking' => __('Looking for a tip …'),
                    'capture' => __('You can take stones here, and it pays.'),
                    'captureMore' => __('Look :where.'),
                    'save' => __('One of your groups stands in atari: save it.'),
                    'saveMore' => __('It stands :where.'),
                    'atari' => __('Put a group of :name\'s in atari.'),
                    'atariMore' => __('Look :where.'),
                    'big' => __('Play where the most is still open.'),
                    'bigMore' => __('The biggest move is :where.'),
                    'pass' => __('Nothing is left to gain. If no stone of :name\'s stands in your area, pass.'),
                    'areas' => [
                        'upperLeft' => __('in the upper left corner'), 'upperSide' => __('on the upper side'), 'upperRight' => __('in the upper right corner'),
                        'leftSide' => __('on the left side'), 'centre' => __('in the centre'), 'rightSide' => __('on the right side'),
                        'lowerLeft' => __('in the lower left corner'), 'lowerSide' => __('on the lower side'), 'lowerRight' => __('in the lower right corner'),
                    ],
                ],
                // The board's own words (boardGame.js), as on a game's page; Go's first side is Black.
                'board' => [
                    'white' => __('Black'),
                    'black' => __('White'),
                    'firstMove' => __(':side: first move within :s s'),
                    'takeBack' => ['button' => __('Take back'), 'left' => __('Take back your move, :seconds s left'), 'notice' => __(':name took the move back.')],
                    'status' => ['live' => __(':side to move'), 'daily' => __(':side to move'), 'over' => __('Game over'), 'aborted' => __('Aborted')],
                    'deadline' => __(':side moves by :time, or loses on time.'),
                    'toMove' => __(':name to move'),
                    'yourMove' => __('Your move'),
                    'connection' => ['connected' => __('Connected'), 'connecting' => __('Connecting …'), 'polling' => __('Live via server')],
                    'outcome' => ['wins' => __(':name wins'), 'draw' => __('Draw'), 'aborted' => __('Game aborted')],
                    'reasons' => ['resignation' => __('Resignation'), ...array_map(fn (string $label): string => __($label), $rules->reasons())],
                    'errors' => ['illegal_move' => __('That move is not legal here.'), 'not_your_turn' => __('It is not your turn.'),
                        'out_of_sync' => __('The board was behind. It shows the latest position now.'), 'game_over' => __('The game is already over.'),
                        'no_position' => __('That did not work. The board shows the server\'s state.'),
                        'default' => __('That did not work. The board shows the server\'s state.')],
                    ...GoLabels::board(),
                ],
            ],
        ];
    }
}; ?>

@php
    $config = $this->config();
@endphp

{{-- The match view T2 of a game's page (pages/board/⚡show), with the practice panel in the middle column, as Blockli's practice. --}}
<div class="flex grow flex-col px-4 pb-8 lg:px-12 lg:pb-10" data-test="board-page" data-focus-page>
    <div x-data="goPractice(@js($config))" class="mx-auto flex w-full max-w-[1000px] flex-col" data-test="practice">
        <div wire:ignore x-data="boardGame(boardConfig())" x-on:keydown.window="browseKey($event)" class="flex flex-col gap-2 lg:gap-5" data-test="board-game">

            {{-- Title row: the page, the way back to the lobby, the lessons or the bots. --}}
            <div class="flex flex-wrap items-center gap-x-2 gap-y-2 lg:gap-x-4">
                <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ __(':game · Practice', ['game' => 'Go']) }}</h1>
                <a href="{{ route('board.lobby', $slug) }}" @navigate(route('board.lobby', $slug)) class="text-[13px] text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc" data-test="board-lobby-link">{{ __('Lobby') }}</a>
                <span class="grow"></span>
                <div role="group" aria-label="{{ __('Practice') }}" class="flex gap-1 rounded-md bg-well p-1" data-test="practice-tabs">
                    <button type="button" class="h-11 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="mode === 'learn' ? 'bg-btc-press font-bold text-btc-hi' : 'text-ink-2 hover:text-ink'"
                            :aria-pressed="(mode === 'learn').toString()" x-on:click="mode === 'learn' || openLesson(Math.max(0, cfg.lessons.findIndex((l) => !done.includes(l.id))))" data-test="tab-lessons">{{ __('Lessons') }}</button>
                    <button type="button" class="h-11 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="mode === 'bots' ? 'bg-btc-press font-bold text-btc-hi' : 'text-ink-2 hover:text-ink'"
                            :aria-pressed="(mode === 'bots').toString()" x-on:click="mode === 'bots' || pickBot(botId)" data-test="tab-bots">{{ __('Bots') }}</button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,560px)_minmax(0,1fr)] lg:gap-x-7 lg:gap-y-5">
                {{-- The board: the rules describe it, the script draws it. On a phone the lesson's task stands above it, in view with the board. --}}
                <div class="flex min-w-0 flex-col gap-2 lg:gap-3" data-test="board-column">
                    <div class="flex items-center gap-2 lg:hidden" x-cloak x-show="mode === 'learn'">
                        <p class="m-0 min-w-0 grow text-[13px] leading-snug font-bold" :class="lessonState === 'done' ? 'text-win' : lessonState === 'retry' ? 'text-loss' : 'text-btc-hi'"
                           x-text="lessonState === 'task' ? lesson.task : lessonNote" data-test="lesson-task-phone"></p>
                        <button type="button" class="rv-b shrink-0" x-cloak x-show="lessonState === 'done'" x-on:click="nextLesson()" data-test="lesson-next-phone"
                                x-text="lessonIndex < cfg.lessons.length - 1 ? cfg.labels.nextLesson : cfg.labels.toBots"></button>
                        <button type="button" class="rv-b2 shrink-0" x-cloak x-show="lessonState === 'retry'" x-on:click="openLesson(lessonIndex)" data-test="lesson-again-phone">{{ __('Start again') }}</button>
                    </div>
                    <div class="flex items-center gap-2 lg:hidden" x-cloak x-show="mode === 'bots' && stage !== 'pick'">
                        {{-- On a phone the tips card lies under the board and Go's bar: a tip shows here, in view with the board. --}}
                        <p class="m-0 min-w-0 grow text-[13px] leading-snug text-ink" :class="stage === 'play' && tip ? 'font-normal' : 'font-bold'"
                           x-text="stage === 'over' ? resultLine : (tip ? tip.text : turnLine)" data-test="turn-phone"></p>
                        <button type="button" class="rv-b2 shrink-0" x-cloak x-show="stage === 'play'" x-bind:disabled="!canAsk" x-on:click="askTip()"
                                x-text="asked === 0 ? @js(__('Get a tip')) : @js(__('Another tip'))" data-test="ask-tip-phone">{{ __('Get a tip') }}</button>
                        <button type="button" class="rv-b2 shrink-0" x-cloak x-show="stage === 'over'" x-on:click="play()" data-test="play-again-phone">{{ __('Another game') }}</button>
                    </div>
                    <div class="relative mx-auto w-full" data-board-box>
                        <svg x-ref="board" data-focus-board data-live-clear viewBox="0 0 {{ $config['layout']['width'] }} {{ $config['layout']['height'] }}"
                             x-on:click="pick($event)" x-on:keydown="pickKey($event)"
                             role="group" aria-label="{{ __('Game board') }}" tabindex="0"
                             style="aspect-ratio: {{ $config['layout']['width'] }} / {{ $config['layout']['height'] }}"
                             class="block w-full touch-manipulation rounded-[4px] bg-well select-none [&:focus:not(:focus-visible)]:outline-none" data-test="board"></svg>
                    </div>
                </div>

                {{-- The practice panel: the lesson, or the bots and the game against one. --}}
                <div class="flex min-w-0 flex-col gap-3" :class="mode === 'bots' && stage === 'pick' ? 'max-lg:order-first' : ''" data-test="practice-panel">

                    {{-- A lesson: what it explains, the task, the verdict. --}}
                    <section x-cloak x-show="mode === 'learn'" aria-labelledby="lesson-h" class="flex flex-col gap-3 rounded-lg bg-card p-4" data-test="lesson">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs text-ink-2" x-text="cfg.labels.lessonOf.replace(':n', lessonIndex + 1).replace(':total', cfg.lessons.length)" data-test="lesson-count"></span>
                            <ol class="m-0 flex list-none flex-wrap gap-1 p-0" aria-label="{{ __('Lessons') }}">
                                <template x-for="(item, i) in cfg.lessons" :key="item.id">
                                    <li><button type="button" class="grid size-11 cursor-pointer place-items-center rounded-[4px] text-xs font-bold"
                                                :class="i === lessonIndex ? 'bg-btc text-on-btc' : done.includes(item.id) ? 'bg-btc-press text-btc-hi' : 'bg-well text-ink-2 hover:text-ink'"
                                                :aria-current="i === lessonIndex ? 'step' : null" :aria-label="item.title" x-on:click="openLesson(i)" x-text="i + 1" data-test="lesson-dot"></button></li>
                                </template>
                            </ol>
                        </div>
                        <h2 id="lesson-h" class="m-0 font-display text-lg font-bold" x-text="lesson.title" data-test="lesson-title"></h2>
                        <p class="m-0 text-[13px] leading-normal text-ink-2" x-text="lesson.text"></p>
                        <p class="m-0 rounded-[4px] border border-btc-ring bg-feature py-2 pr-3 pl-4 text-[13px] font-bold text-btc-hi shadow-[inset_3px_0_0_var(--color-btc)]" x-cloak x-show="lessonState === 'task'" x-text="lesson.task" data-test="lesson-task"></p>
                        <p class="m-0 text-[13px] leading-normal text-ink" x-cloak x-show="triedClosing && lessonState === 'task' && lesson.closingNote" x-text="lesson.closingNote" data-test="lesson-closing"></p>
                        <p class="m-0 text-[13px] text-ink-2" x-cloak x-show="lessonState === 'task' && turnLine && state.turn !== color" x-text="turnLine"></p>
                        <div role="status" aria-live="polite">
                            <p class="m-0 text-[13px] leading-normal font-bold" :class="lessonState === 'done' ? 'text-win' : 'text-loss'" x-cloak x-show="lessonState !== 'task'" x-text="lessonNote" data-test="lesson-note"></p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-button x-cloak x-show="lessonState === 'done'" x-on:click="nextLesson()" data-test="lesson-next"><span x-text="lessonIndex < cfg.lessons.length - 1 ? cfg.labels.nextLesson : cfg.labels.toBots"></span></x-button>
                            <x-button variant="quiet" x-cloak x-show="lessonState !== 'task' || state.ply > 0" x-on:click="openLesson(lessonIndex)" data-test="lesson-again">{{ __('Start again') }}</x-button>
                            <x-button variant="secondary" x-cloak x-show="lessonState !== 'done'" x-on:click="nextLesson()" data-test="lesson-skip">{{ __('Skip') }}</x-button>
                        </div>
                    </section>

                    {{-- The bots: pick one and who starts, then the game with its tips. --}}
                    <section x-cloak x-show="mode === 'bots'" aria-labelledby="bots-h" class="flex flex-col gap-3" data-test="bots">
                        <div x-cloak x-show="stage === 'pick'" class="flex flex-col gap-3 rounded-lg bg-card p-4" data-test="bot-pick">
                            <h2 id="bots-h" class="m-0 text-[15px] font-bold">{{ __('Choose a bot') }}</h2>
                            <div role="radiogroup" aria-labelledby="bots-h" class="flex flex-col gap-2">
                                <template x-for="(bot, id) in cfg.bots" :key="id">
                                    <button type="button" role="radio" :aria-checked="(botId === id).toString()" x-on:click="pickBot(id)" :data-test="'bot-' + id"
                                            class="flex w-full cursor-pointer items-center gap-3 rounded-[4px] border px-3 py-2 text-left" :class="botId === id ? 'border-btc bg-btc-press' : 'border-line bg-well hover:bg-row-hover'">
                                        <span class="grid size-10 shrink-0 place-items-center rounded-[4px] bg-btc-press font-display text-sm font-bold text-btc" aria-hidden="true" x-text="bot.name.slice(0, 1)"></span>
                                        <span class="flex min-w-0 grow flex-col">
                                            <b class="text-[15px]"><span x-text="bot.name"></span> <span class="text-xs font-normal text-ink-3" x-text="bot.level"></span></b>
                                            <span class="text-[13px] leading-snug text-ink-2" x-text="bot.text"></span>
                                        </span>
                                        <span class="shrink-0 text-xs font-bold text-win" x-cloak x-show="beaten.includes(id)">{{ __('beaten') }}</span>
                                    </button>
                                </template>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-[13px] text-ink-2" id="first-h">{{ __('Who plays Black and starts?') }}</span>
                                <div role="group" aria-labelledby="first-h" class="flex gap-1 rounded-md bg-well p-1">
                                    <button type="button" class="h-11 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="humanFirst ? 'bg-btc-press font-bold text-btc-hi' : 'text-ink-2'" :aria-pressed="humanFirst.toString()" x-on:click="setFirst(true)" data-test="first-you">{{ __('You') }}</button>
                                    <button type="button" class="h-11 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="humanFirst ? 'text-ink-2' : 'bg-btc-press font-bold text-btc-hi'" :aria-pressed="(!humanFirst).toString()" x-on:click="setFirst(false)" x-text="cfg.bots[botId].name" data-test="first-bot"></button>
                                </div>
                            </div>
                            {{-- The primary action: below lg a bar fixed at the bottom (where Go's bar takes over once the game runs),
                                 so it is on the first screen at any height; from lg in the card. --}}
                            <div class="max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-20 max-lg:flex max-lg:border-t max-lg:border-line max-lg:bg-bar max-lg:px-4 max-lg:py-1.5 max-lg:shadow-[0_-16px_32px_rgba(10,10,11,.8)]" data-page-bar data-test="bot-play-bar">
                                <x-button icon="bolt" class="max-lg:grow" x-on:click="play()" data-test="bot-play"><span x-text="@js(__('Play :name')).replace(':name', cfg.bots[botId].name)"></span></x-button>
                            </div>
                            <p class="m-0 text-xs text-ink-3">{{ __('Practice games are not stored and count for nothing. Black moves first; White gets 7.5 points of komi for it.') }}</p>
                        </div>

                        <div x-cloak x-show="stage !== 'pick'" class="flex flex-col gap-3" data-test="bot-game">
                            <div class="flex items-center gap-3 rounded-lg bg-card p-3" data-test="bot-card">
                                <span class="grid size-11 shrink-0 place-items-center rounded-[4px] bg-btc-press font-display text-sm font-bold text-btc" aria-hidden="true" x-text="botName.slice(0, 1)"></span>
                                <span class="flex min-w-0 grow flex-col">
                                    <b class="text-[15px]"><span x-text="botName"></span> <span class="text-xs font-normal text-ink-3" x-text="cfg.bots[botId].level"></span></b>
                                    <span role="status" class="text-[13px] text-ink-2" x-text="stage === 'over' ? resultLine : turnLine" data-test="turn-line"></span>
                                </span>
                            </div>

                            <div x-cloak x-show="stage === 'over'" class="flex flex-col gap-3 rounded-lg bg-card p-4" data-test="practice-result">
                                <b class="text-base" :class="won ? 'text-win' : 'text-ink'" x-text="resultLine" data-test="result"></b>
                                <div class="flex flex-wrap gap-2">
                                    <x-button x-cloak x-show="nextBot" x-on:click="const next = nextBot; pickBot(next); play()" data-test="next-bot"><span x-text="nextBot ? @js(__('Play :name')).replace(':name', cfg.bots[nextBot].name) : ''"></span></x-button>
                                    <x-button variant="quiet" x-on:click="play()" data-test="play-again">{{ __('Another game') }}</x-button>
                                    <x-button variant="secondary" x-on:click="pickBot(botId)" data-test="choose-bot">{{ __('Choose a bot') }}</x-button>
                                </div>
                            </div>

                            {{-- The tips: a line now and then by themselves, and the tip button for a nudge (never the move). --}}
                            <div class="flex flex-col gap-2 rounded-lg bg-card p-4" data-test="tips">
                                <div class="flex items-center justify-between gap-2">
                                    <h2 class="m-0 text-[15px] font-bold">{{ __('Tips') }}</h2>
                                    {{-- R11: the board's checkbox, never the native one; 44 px tall. --}}
                                    <x-ui.check class="text-[13px] text-ink-2" x-bind:aria-checked="tipsOn.toString()" x-on:click="setTips(!tipsOn)" data-test="tips-on">{{ __('Show by themselves') }}</x-ui.check>
                                </div>
                                <p role="status" aria-live="polite" class="m-0 min-h-5 text-[13px] leading-normal" :class="tip ? 'text-ink' : 'text-ink-3'" x-text="tip ? tip.text : @js(__('Stuck? Ask for a tip: it tells you what kind of move fits, never the move itself.'))" data-test="tip"></p>
                                <x-button variant="quiet" class="self-start" x-bind:disabled="!canAsk" x-on:click="askTip()" data-test="ask-tip"><span x-text="asked === 0 ? @js(__('Get a tip')) : @js(__('Another tip'))"></span></x-button>
                            </div>

                            <p role="alert" class="m-0 text-[13px] text-loss" x-cloak x-show="failed" x-text="failed"></p>

                            <div class="flex flex-wrap items-center justify-end gap-2" x-cloak x-show="stage === 'play' || canUndo" data-test="practice-actions">
                                <x-button variant="quiet" x-bind:disabled="!canUndo" x-on:click="undo()" data-test="undo">{{ __('Take back') }}</x-button>
                                <template x-if="state.status === 'active'">
                                    <x-button variant="secondary" x-on:click="resign()" class="text-loss! border-[#5A2A2E]!" data-test="resign">
                                        <span x-text="confirmResign ? @js(__('Resign this game?')) : @js(__('Resign'))"></span>
                                    </x-button>
                                </template>
                            </div>
                        </div>
                    </section>

                    {{-- The count as it stands (in a bot game and in the lesson about it), and Go's bar: Place and Pass, as on a game's page. --}}
                    @include('pages.board.partials.area-count')
                    @include('pages.board.partials.stone-input', ['bottom' => 'max-lg:bottom-0'])
                    <p role="alert" class="m-0 text-[13px] text-loss" x-cloak x-show="error" x-text="error" data-test="board-error"></p>

                    {{-- The moves of a game against a bot, two columns per move number; browsing steps through them. --}}
                    <div x-cloak x-show="mode === 'bots' && stage !== 'pick'" class="flex min-h-0 flex-col gap-2 rounded-lg bg-card p-4" data-test="moves-card">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="m-0 text-[15px] font-bold">{{ __('Moves') }}</h2>
                            <div class="flex items-center gap-1" data-test="history-nav">
                                @foreach ([['0', 'first', '«', __('First position')], ['shownPly - 1', 'back', '‹', __('Previous move')], ['shownPly + 1', 'forward', '›', __('Next move')], ['state.moves.length', 'last', '»', __('Latest move')]] as [$target, $key, $glyph, $label])
                                    <button type="button" x-on:click="browse({{ $target }})" aria-label="{{ $label }}" title="{{ $label }}" data-test="history-{{ $key }}"
                                            class="inline-flex size-11 cursor-pointer items-center justify-center rounded-md bg-well text-base text-ink hover:bg-row-hover">{{ $glyph }}</button>
                                @endforeach
                            </div>
                        </div>
                        <p class="m-0 flex flex-wrap items-center gap-2 text-xs text-btc-hi" x-cloak x-show="viewIndex !== null" data-test="history-pinned">
                            <span x-text="@js(__('Showing move :n of :total')).replace(':n', shownPly).replace(':total', state.moves.length)"></span>
                            <button type="button" class="cursor-pointer text-ink underline decoration-edge underline-offset-4" x-on:click="browse(state.moves.length)" data-test="history-back-to-game">{{ __('Back to the game') }}</button>
                        </p>
                        <ol class="m-0 grid max-h-60 min-h-0 list-none grid-cols-2 content-start gap-x-2 overflow-y-auto p-0 text-[13px] tabular-nums" data-test="moves">
                            <template x-for="m in state.moves" :key="m.ply">
                                <li class="min-w-0 border-b border-hairline"><button type="button" x-on:click="browse(m.ply)" :aria-current="shownPly === m.ply ? 'step' : null" data-test="move"
                                            class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-sm px-2 text-left text-ink" :class="shownPly === m.ply ? 'bg-btc-press font-bold text-btc-hi' : 'hover:bg-row-hover'"><span class="w-7 shrink-0 text-ink-3" x-text="m.ply % 2 ? Math.ceil(m.ply / 2) + '.' : ''"></span><b class="truncate font-bold" x-text="m.notation"></b></button></li>
                            </template>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
