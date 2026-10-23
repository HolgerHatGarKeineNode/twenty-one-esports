<?php

use App\Games\Blockli;
use App\Games\GameRegistry;
use App\Support\Board\BlockliPractice;
use App\Support\Cards\PageCard;
use App\Support\GameNames;
use App\Support\PageMeta;
use Livewire\Attributes\Json;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Blockli practice against bots (DerCaddy, 2026-10-23: a small way to play against bots, where newcomers get the game
 * explained and small tips): six short lessons on the board, then three bots, the Hodler (only runs), the Miner (sets a
 * block whenever one costs a step) and the Whale (searches deep). For guests too; nothing is stored, nothing counts.
 *
 * The game lives in the browser (resources/js/blockli/practice.js) and plays on the league's own board (boardGame.js,
 * its `source`): the same squares, block input and Blockli bar as a real game. The page itself keeps no game: `play`
 * and `open` are a judge without memory, they get the position the browser holds and answer by BlockliRules
 * (App\Support\Board\BlockliPractice). The bots and the tips run in the browser on the engine of DerCaddy's prototype.
 *
 * Only for Blockli, and only while Blockli is switched on (the lobby's tile leads here); 404 otherwise.
 */
new #[Layout('layouts::app', ['scripts' => ['resources/js/blockli/practice.js']])] class extends Component
{
    #[Locked]
    public string $slug = '';

    public function mount(string $board): void
    {
        abort_unless($board === Blockli::SLUG && app(GameRegistry::class)->isBoard($board), 404);

        $this->slug = $board;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $title = __(':game · Practice', ['game' => GameNames::game($this->slug)]);
        $view->title($title);
        app(PageMeta::class)->describe($title, __('Learn :game in six short lessons on the board, then play three bots, with small tips on the way. Nothing here counts for the ladder.', ['game' => GameNames::game($this->slug)]))
            ->card(fn () => PageCard::page('board.'.$this->slug));
    }

    /** What the board needs for a position the browser starts from (BlockliPractice::open()). @return array<string, mixed> */
    #[Json]
    public function open(mixed $position = null): array
    {
        return app(BlockliPractice::class)->open($position);
    }

    /** A move in the position the browser holds, judged by the rules (BlockliPractice::play()). @return array<string, mixed> */
    #[Json]
    public function play(mixed $position = null, mixed $move = null, mixed $history = []): array
    {
        return app(BlockliPractice::class)->play($position, $move, $history);
    }

    /**
     * Everything the practice script needs, translated once here: the board's drawing and labels, the start
     * position, the lessons with their positions opened, the bots, and the words of the tips.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $practice = app(BlockliPractice::class);
        $rules = $practice->rules();
        $start = $rules->serialize($rules->start());
        $layout = $rules->view($rules->start());
        unset($layout['pieces']);
        // The forms for one and for more, filled in the browser (:count).
        $steps = [trans_choice(':count step|:count steps', 1, ['count' => ':count']), trans_choice(':count step|:count steps', 2, ['count' => ':count'])];
        $blocks = [trans_choice(':count block|:count blocks', 1, ['count' => ':count']), trans_choice(':count block|:count blocks', 2, ['count' => ':count'])];

        return [
            'layout' => $layout,
            'start' => ['position' => $start, 'view' => $practice->open($start)],
            'lessons' => array_map(fn (array $lesson): array => [
                'id' => $lesson['id'],
                'position' => $lesson['position'],
                'goal' => $lesson['goal'],
                'steps' => $lesson['steps'] ?? 0,
                'reply' => $lesson['reply'],
                // The fence lesson's refused block as the board names it (crossing and direction).
                'closing' => isset($lesson['closing']) ? $rules->path($lesson['closing']) : null,
                'title' => __($lesson['title']),
                'text' => __($lesson['text']),
                'task' => __($lesson['task']),
                'done' => __($lesson['done']),
                'retry' => __($lesson['retry']),
                'view' => $practice->open($lesson['position']),
            ], BlockliPractice::LESSONS),
            'bots' => [
                'hodler' => ['name' => __('Hodler'), 'level' => __('Easy'), 'text' => __('Never gives a block away. He only runs.')],
                'miner' => ['name' => __('Miner'), 'level' => __('Medium'), 'text' => __('Sets a block whenever one costs you a step. Then he has none left.')],
                'whale' => ['name' => __('Whale'), 'level' => __('Hard'), 'text' => __('Thinks many moves ahead. Few beat him.')],
            ],
            'labels' => [
                'you' => __('You'),
                'black' => __('Black'),
                'lessonOf' => __('Lesson :n of :total'),
                'nextLesson' => __('Next lesson'),
                'toBots' => __('On to the bots'),
                'lessonBlockOnly' => __('A step of your pawn does not stop him. Set a block.'),
                'closing' => __('Red: that block would lock Black in completely, so the rules refuse it.'),
                'yourMove' => __('Your move'),
                'toMove' => __(':name to move'),
                'thinking' => __(':name is thinking …'),
                'botFailed' => __('The bot could not move. Start a new game.'),
                'race' => __('You need :mine and have :blocks; :name needs :theirs and has :botBlocks.'),
                'steps' => $steps,
                'blocks' => $blocks,
                'result' => [
                    'won' => __('You beat :name!'),
                    'lost' => __(':name wins this one.'),
                    'resigned' => __('You resigned, :name wins.'),
                    'draw' => __('Draw.'),
                ],
                // The tips that come up by themselves (resources/js/blockli/tips.js autoTip); :name is the bot.
                'tips' => [
                    'selfBlock' => __('That block cost you :mine and :name only :theirs: it hurt you more.'),
                    'weakBlock' => __('A block that costs only one step rarely pays. Keep it for a narrow place on his way.'),
                    'away' => __('That step made your way longer. Unless you are dodging a block, head for your goal.'),
                    'hit' => __('Ouch: that block costs you :steps. It sat where your way ran through a single gap.'),
                    'danger' => __(':name needs only :steps and gets there first if you both just run. A block on his way can turn the race.'),
                    'botEmpty' => __(':name has no blocks left. Nothing can stop you now: take your shortest way.'),
                    'youEmpty' => __('You have no blocks left. From now on only running counts.'),
                    'threat' => __('Careful: your way runs through a narrow place. One block of :name\'s would cost you :steps there. A block of yours beside it can guard it.'),
                    'jump' => __('You stand right in front of :name: jump over him and save a step.'),
                ],
                // The tip button (tips.js adviceFor): first what kind of move, then where; never the move itself.
                'advice' => [
                    'thinking' => __('Looking for a tip …'),
                    'run' => __('Run: your shortest way is the best move here.'),
                    'runMore' => __('You need :mine, :name needs :theirs. Keep going.'),
                    'detour' => __('Not straight ahead: a step to the side is better here.'),
                    'detourMore' => __('You need :mine, :name needs :theirs. The side step keeps your way clear of his blocks.'),
                    'block' => __('A block pays now: the best one costs :name :steps.'),
                    'blockMore' => __('Look :where, on his way.'),
                    'guard' => __('Guard your way: one block of :name\'s could cost you :steps. Take its place with a block of yours.'),
                    'guardMore' => __('The danger lies :where.'),
                    'ranks' => __('between ranks :a and :b'),
                    'files' => __('between files :a and :b'),
                ],
                // The board's own words (boardGame.js), as on a game's page.
                'board' => [
                    'white' => __('White'),
                    'black' => __('Black'),
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
                    'blocks' => ['tap' => __('Tap a groove: the block shows where it lands.'), 'point' => __('Point at a groove: the block follows.'),
                        'confirm' => __('Tap the block again or press Confirm.'), 'click' => __('Click to set the block.'), 'illegal' => __('No block fits here.'),
                        'keys' => __('Arrow keys move the block, R turns it, Enter sets it, Escape cancels.'),
                        'at' => __('Block at :crossing, :direction.'), 'horizontal' => __('horizontal'), 'vertical' => __('vertical'),
                        'enter' => __('Enter sets it, R turns it.')],
                    'repetition' => __('The same position :count times. Once more and the game is drawn.'),
                    'standing' => ['line' => __('Race: :white, :black. A block counts :rate steps: :lead'), 'side' => __(':name needs :steps and has :blocks'),
                        'steps' => $steps,
                        'blocks' => $blocks,
                        'lead' => __(':name leads by :steps.'), 'level' => __('Both are level.')],
                ],
            ],
        ];
    }
}; ?>

@php
    $config = $this->config();
    $name = GameNames::game($slug);
@endphp

{{-- The match view T2 of a game's page (pages/board/⚡show), with the practice panel in the middle column. --}}
<div class="flex grow flex-col px-4 pb-8 lg:px-12 lg:pb-10" data-test="board-page" data-focus-page>
    <div x-data="blockliPractice(@js($config))" class="mx-auto flex w-full max-w-[1000px] flex-col" data-test="practice">
        <div wire:ignore x-data="boardGame(boardConfig())" x-on:keydown.window="browseKey($event)" class="flex flex-col gap-2 lg:gap-5" data-test="board-game">

            {{-- Title row: the page, the way back to the lobby, the lessons or the bots. --}}
            <div class="flex flex-wrap items-center gap-x-2 gap-y-2 lg:gap-x-4">
                <div class="flex min-w-0 items-baseline gap-2 lg:block">
                    <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ __(':game · Practice', ['game' => $name]) }}</h1>
                    <x-game-credit :game="$slug" />
                </div>
                <a href="{{ route('board.lobby', $slug) }}" @navigate(route('board.lobby', $slug)) class="text-[13px] text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc" data-test="board-lobby-link">{{ __('Lobby') }}</a>
                <span class="grow"></span>
                <div role="group" aria-label="{{ __('Practice') }}" class="flex gap-1 rounded-md bg-well p-1" data-test="practice-tabs">
                    <button type="button" class="h-9 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="mode === 'learn' ? 'bg-btc-press font-bold text-btc-hi' : 'text-ink-2 hover:text-ink'"
                            :aria-pressed="(mode === 'learn').toString()" x-on:click="mode === 'learn' || openLesson(Math.max(0, cfg.lessons.findIndex((l) => !done.includes(l.id))))" data-test="tab-lessons">{{ __('Lessons') }}</button>
                    <button type="button" class="h-9 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="mode === 'bots' ? 'bg-btc-press font-bold text-btc-hi' : 'text-ink-2 hover:text-ink'"
                            :aria-pressed="(mode === 'bots').toString()" x-on:click="mode === 'bots' || pickBot(botId)" data-test="tab-bots">{{ __('Bots') }}</button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,560px)_minmax(0,1fr)] lg:gap-x-7 lg:gap-y-5">
                {{-- The board: the rules describe it, the script draws it. On a phone the lesson's task stands above it, in view with the board. --}}
                <div class="flex min-w-0 flex-col gap-2 lg:gap-3" data-test="board-column">
                    {{-- With the verdict, its next step beside it: on a phone the lesson card lies below the board. --}}
                    <div class="flex items-center gap-2 lg:hidden" x-cloak x-show="mode === 'learn'">
                        <p class="m-0 min-w-0 grow text-[13px] leading-snug font-bold" :class="lessonState === 'done' ? 'text-win' : lessonState === 'retry' ? 'text-loss' : 'text-btc-hi'"
                           x-text="lessonState === 'task' ? lesson.task : lessonNote" data-test="lesson-task-phone"></p>
                        <button type="button" class="rv-b is-sm shrink-0" x-cloak x-show="lessonState === 'done'" x-on:click="nextLesson()" data-test="lesson-next-phone"
                                x-text="lessonIndex < cfg.lessons.length - 1 ? cfg.labels.nextLesson : cfg.labels.toBots"></button>
                        <button type="button" class="rv-b2 is-sm shrink-0" x-cloak x-show="lessonState === 'retry'" x-on:click="openLesson(lessonIndex)" data-test="lesson-again-phone">{{ __('Start again') }}</button>
                    </div>
                    <div class="flex items-center gap-2 lg:hidden" x-cloak x-show="mode === 'bots' && stage !== 'pick'">
                        <p class="m-0 min-w-0 grow text-[13px] leading-snug font-bold text-ink" x-text="stage === 'over' ? resultLine : turnLine" data-test="turn-phone"></p>
                        <button type="button" class="rv-b2 is-sm shrink-0" x-cloak x-show="stage === 'over'" x-on:click="play()" data-test="play-again-phone">{{ __('Another game') }}</button>
                    </div>
                    <div class="relative mx-auto w-full" data-board-box>
                        <svg x-ref="board" data-focus-board data-live-clear viewBox="0 0 {{ $config['layout']['width'] }} {{ $config['layout']['height'] }}"
                             x-on:click="pick($event)" x-on:keydown="pickKey($event)"
                             role="group" aria-label="{{ __('Game board') }}"
                             x-bind:class="color === 'b' ? 'rotate-180' : ''"
                             style="aspect-ratio: {{ $config['layout']['width'] }} / {{ $config['layout']['height'] }}"
                             class="block w-full touch-manipulation rounded-[4px] bg-well select-none" data-test="board"></svg>
                    </div>
                </div>

                {{-- The practice panel: the lesson, or the bots and the game against one. --}}
                <div class="flex min-w-0 flex-col gap-3" data-test="practice-panel">

                    {{-- A lesson: what it explains, the task, the verdict. --}}
                    <section x-cloak x-show="mode === 'learn'" aria-labelledby="lesson-h" class="flex flex-col gap-3 rounded-lg bg-card p-4" data-test="lesson">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs text-ink-2" x-text="cfg.labels.lessonOf.replace(':n', lessonIndex + 1).replace(':total', cfg.lessons.length)" data-test="lesson-count"></span>
                            <ol class="m-0 flex list-none gap-1 p-0" aria-label="{{ __('Lessons') }}">
                                <template x-for="(item, i) in cfg.lessons" :key="item.id">
                                    <li><button type="button" class="grid size-8 cursor-pointer place-items-center rounded-[4px] text-xs font-bold"
                                                :class="i === lessonIndex ? 'bg-btc text-on-btc' : done.includes(item.id) ? 'bg-btc-press text-btc-hi' : 'bg-well text-ink-2 hover:text-ink'"
                                                :aria-current="i === lessonIndex ? 'step' : null" :aria-label="item.title" x-on:click="openLesson(i)" x-text="i + 1" data-test="lesson-dot"></button></li>
                                </template>
                            </ol>
                        </div>
                        <h2 id="lesson-h" class="m-0 font-display text-lg font-bold" x-text="lesson.title" data-test="lesson-title"></h2>
                        <p class="m-0 text-[13px] leading-normal text-ink-2" x-text="lesson.text"></p>
                        <p class="m-0 rounded-[4px] border border-btc-ring bg-feature py-2 pr-3 pl-4 text-[13px] font-bold text-btc-hi shadow-[inset_3px_0_0_var(--color-btc)]" x-cloak x-show="lessonState === 'task'" x-text="lesson.task" data-test="lesson-task"></p>
                        <p class="m-0 text-[13px] leading-normal text-ink" x-cloak x-show="triedClosing && lessonState === 'task'" x-text="cfg.labels.closing" data-test="lesson-closing"></p>
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
                                <span class="text-[13px] text-ink-2" id="first-h">{{ __('Who starts?') }}</span>
                                <div role="group" aria-labelledby="first-h" class="flex gap-1 rounded-md bg-well p-1">
                                    <button type="button" class="h-9 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="humanFirst ? 'bg-btc-press font-bold text-btc-hi' : 'text-ink-2'" :aria-pressed="humanFirst.toString()" x-on:click="setFirst(true)" data-test="first-you">{{ __('You') }}</button>
                                    <button type="button" class="h-9 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="humanFirst ? 'text-ink-2' : 'bg-btc-press font-bold text-btc-hi'" :aria-pressed="(!humanFirst).toString()" x-on:click="setFirst(false)" x-text="cfg.bots[botId].name" data-test="first-bot"></button>
                                </div>
                            </div>
                            <x-button icon="bolt" x-on:click="play()" data-test="bot-play"><span x-text="@js(__('Play :name')).replace(':name', cfg.bots[botId].name)"></span></x-button>
                            <p class="m-0 text-xs text-ink-3">{{ __('Practice games are not stored and count for nothing. The first move is an advantage.') }}</p>
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
                                    <label class="flex cursor-pointer items-center gap-2 text-[13px] text-ink-2">
                                        <input type="checkbox" class="size-4 accent-btc" :checked="tipsOn" x-on:change="setTips($event.target.checked)" data-test="tips-on">
                                        {{ __('Show by themselves') }}
                                    </label>
                                </div>
                                <p role="status" aria-live="polite" class="m-0 min-h-5 text-[13px] leading-normal" :class="tip ? 'text-ink' : 'text-ink-3'" x-text="tip ? tip.text : @js(__('Stuck? Ask for a tip: it tells you what kind of move fits, never the move itself.'))" data-test="tip"></p>
                                <x-button variant="quiet" class="self-start" x-bind:disabled="!canAsk" x-on:click="askTip()" data-test="ask-tip"><span x-text="asked === 0 ? @js(__('Get a tip')) : @js(__('Another tip'))"></span></x-button>
                            </div>

                            <p class="m-0 text-[13px] text-ink-2" x-cloak x-show="raceLine" x-text="raceLine" data-test="race"></p>
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

                    {{-- The block input (Blockli), as on a game's page: on a phone a bar fixed at the bottom, from lg here in the column. --}}
                    <template x-if="layout.input === 'blocks' && color && state.status === 'active'">
                        <div class="flex flex-col gap-2 max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-20 max-lg:flex-row max-lg:items-center max-lg:gap-3 max-lg:border-t max-lg:border-line max-lg:bg-bar max-lg:px-4 max-lg:py-1.5 max-lg:shadow-[0_-16px_32px_rgba(10,10,11,.8)]" x-ref="dock" data-page-bar data-test="block-input">
                            <p class="m-0 line-clamp-2 min-w-0 grow text-xs lg:order-2 lg:text-[13px]"
                               :class="[preview && !preview.move && settingBlock ? 'text-loss' : 'text-ink-2', blockButtons ? 'max-lg:hidden' : '', settingBlock ? '' : 'lg:hidden']"
                               x-text="barLine" data-test="block-status"></p>
                            <button type="button" class="inline-flex h-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md px-[18px] text-[13px] disabled:cursor-default disabled:opacity-50 lg:order-1 lg:w-full"
                                    :class="[blockMode ? 'border border-btc bg-btc-press font-bold text-btc-hi' : 'btn-w border border-line bg-well text-ink', blockButtons ? 'max-lg:hidden' : '']"
                                    :aria-pressed="blockMode ? 'true' : 'false'" :disabled="!myTurn || !canSetBlocks" x-on:click="setBlockMode(!blockMode, $event)" aria-keyshortcuts="ArrowUp ArrowDown ArrowLeft ArrowRight R Enter Escape"
                                    data-test="mode-block">
                                {{ __('Set a block') }} <span class="tabular-nums" x-text="blocksLeft"></span>
                            </button>
                            <div class="min-w-0 grow gap-2 lg:order-3" :class="blockButtons ? 'flex' : 'hidden'" data-block-buttons>
                                <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md border border-line bg-well px-2 text-xs whitespace-nowrap min-[360px]:text-[13px] text-ink btn-w hover:text-ink disabled:cursor-default disabled:opacity-50"
                                        x-bind:disabled="!preview" x-on:click="rotateBlock()" data-test="rotate-block">{{ __('Rotate') }}</button>
                                <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md bg-btc px-2 text-xs whitespace-nowrap min-[360px]:text-[13px] font-bold text-on-btc btn-p hover:text-on-btc disabled:cursor-default disabled:opacity-50"
                                        x-bind:disabled="!preview || !preview.move" x-on:click="setBlock()" data-test="set-block">{{ __('Confirm') }}</button>
                                <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md border border-edge px-2 text-xs whitespace-nowrap min-[360px]:text-[13px] text-ink btn-s hover:text-ink"
                                        x-on:click="setBlockMode(false)" data-test="mode-move">{{ __('Move the pawn') }}</button>
                            </div>
                            {{-- Always in the page, so a screen reader hears each new hint (the keys say where the block is). --}}
                            <p class="sr-only" role="status" aria-live="polite" x-text="blockHint" data-test="block-hint"></p>
                        </div>
                    </template>
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
                                            class="flex min-h-10 w-full cursor-pointer items-center gap-2 rounded-sm px-2 text-left text-ink" :class="shownPly === m.ply ? 'bg-btc-press font-bold text-btc-hi' : 'hover:bg-row-hover'"><span class="w-7 shrink-0 text-ink-3" x-text="m.ply % 2 ? Math.ceil(m.ply / 2) + '.' : ''"></span><b class="truncate font-bold" x-text="m.notation"></b></button></li>
                            </template>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
