<?php

use App\Games\Blockspace;
use App\Games\GameRegistry;
use App\Support\Board\BlockspacePractice;
use App\Support\Board\BlockspaceRules;
use App\Support\Cards\PageCard;
use App\Support\GameNames;
use App\Support\PageMeta;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * The Blockspace tutorial: nine short lessons on the league's own board, then three bots. For guests too; nothing is
 * stored, nothing counts. The game lives in the browser (resources/js/blockspace/practice.js), on the same board and
 * mempool as a real game. Only while Blockspace is switched on (the lobby's tile leads here); 404 otherwise.
 */
new #[Layout('layouts::app', ['scripts' => ['resources/js/blockspace/practice.js']])] class extends Component
{
    public function mount(): void
    {
        abort_unless(app(GameRegistry::class)->isBoard(Blockspace::SLUG), 404);
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $name = GameNames::game(Blockspace::SLUG);
        $title = __(':game · Tutorial', ['game' => $name]);
        $view->title($title);
        app(PageMeta::class)->describe($title, __('Learn :game in nine short lessons on the board, then play three bots. Nothing here counts for the ladder.', ['game' => $name]))
            ->card(fn () => PageCard::page('board.'.Blockspace::SLUG));
    }

    /**
     * Everything the practice script needs, translated once here.
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        $rules = new BlockspaceRules;
        $layout = $rules->view($rules->start());
        unset($layout['pieces']);

        return [
            'layout' => $layout,
            'lessons' => array_map(fn (array $lesson): array => [
                'id' => $lesson['id'],
                'position' => BlockspacePractice::position($lesson),
                'goal' => $lesson['goal'],
                'count' => $lesson['count'] ?? 1,
                'slot' => $lesson['slot'] ?? 0,
                'title' => __($lesson['title']),
                'text' => __($lesson['text']),
                'task' => __($lesson['task']),
                'done' => __($lesson['done']),
                'retry' => __($lesson['retry']),
            ], BlockspacePractice::LESSONS),
            'bots' => [
                'hodler' => ['level' => 'leicht', 'name' => __('Hodler'), 'grade' => __('Easy'), 'text' => __('Takes what pays well right now and looks no further.')],
                'miner' => ['level' => 'mittel', 'name' => __('Miner'), 'grade' => __('Medium'), 'text' => __('Weighs the yield against open corners and blocks your way.')],
                'whale' => ['level' => 'stark', 'name' => __('Whale'), 'grade' => __('Hard'), 'text' => __('Plays each move out to the end of the game. Few beat him.')],
            ],
            'labels' => [
                'you' => __('You'),
                'black' => GameNames::sides(Blockspace::SLUG)['b'],
                'lessonOf' => __('Lesson :n of :total'),
                'nextLesson' => __('Next lesson'),
                'toBots' => __('On to the bots'),
                'yourMove' => __('Your move'),
                'thinking' => __(':name is thinking …'),
                'result' => ['won' => __('You beat :name!'), 'lost' => __(':name wins this one.'), 'resigned' => __('You resigned, :name wins.'), 'draw' => __('Draw.')],
                'board' => [
                    'white' => GameNames::sides(Blockspace::SLUG)['w'],
                    'black' => GameNames::sides(Blockspace::SLUG)['b'],
                    'firstMove' => __(':side: first move within :s s'),
                    'takeBack' => ['button' => __('Take back'), 'left' => __('Take back your move, :seconds s left'), 'notice' => __(':name took the move back.')],
                    'status' => ['live' => __(':side to move'), 'daily' => __(':side to move'), 'over' => __('Game over'), 'aborted' => __('Aborted')],
                    'deadline' => __(':side moves by :time, or loses on time.'),
                    'toMove' => __(':name to move'),
                    'yourMove' => __('Your move'),
                    'connection' => ['connected' => __('Connected'), 'connecting' => __('Connecting …'), 'polling' => __('Live via server')],
                    'outcome' => ['wins' => __(':name wins'), 'draw' => __('Draw'), 'aborted' => __('Game aborted')],
                    'reasons' => ['resignation' => __('Resignation'), ...array_map(fn (string $label): string => __($label), $rules->reasons())],
                    'pieces' => BlockspacePractice::boardWords(),
                    'errors' => ['illegal_move' => __('That move is not legal here.'), 'not_your_turn' => __('It is not your turn.'),
                        'out_of_sync' => __('The board was behind. It shows the latest position now.'), 'game_over' => __('The game is already over.'),
                        'default' => __('That did not work. The board shows the server\'s state.')],
                ],
            ],
        ];
    }
}; ?>

@php
    $config = $this->config();
    $name = GameNames::game(Blockspace::SLUG);
@endphp

<div class="flex grow flex-col px-4 pb-8 lg:px-12 lg:pb-10" data-test="board-page" data-focus-page>
    <div x-data="blockspacePractice(@js($config))" x-on:blockspace-refused="refused()" class="mx-auto flex w-full max-w-[1040px] flex-col" data-test="practice">
        <div wire:ignore x-data="boardGame(boardConfig())" x-on:keydown.window="browseKey($event)" class="flex flex-col gap-2 lg:gap-5" data-test="board-game">

            <div class="flex flex-wrap items-center gap-x-2 gap-y-2 lg:gap-x-4">
                <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ __(':game · Tutorial', ['game' => $name]) }}</h1>
                <a href="{{ route('board.lobby', Blockspace::SLUG) }}" @navigate(route('board.lobby', Blockspace::SLUG)) class="text-[13px] text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc" data-test="board-lobby-link">{{ __('Find an opponent') }}</a>
                <span class="grow"></span>
                <div role="group" aria-label="{{ __('Practice') }}" class="flex gap-1 rounded-md bg-well p-1" data-test="practice-tabs">
                    <button type="button" class="h-11 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="mode === 'learn' ? 'bg-btc-press font-bold text-btc-hi' : 'text-ink-2 hover:text-ink'"
                            :aria-pressed="(mode === 'learn').toString()" x-on:click="mode === 'learn' || openLesson(Math.max(0, cfg.lessons.findIndex((l) => !done.includes(l.id))))" data-test="tab-learn">{{ __('Lessons') }}</button>
                    <button type="button" class="h-11 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="mode === 'bots' ? 'bg-btc-press font-bold text-btc-hi' : 'text-ink-2 hover:text-ink'"
                            :aria-pressed="(mode === 'bots').toString()" x-on:click="mode === 'bots' || pickBot(botId)" data-test="tab-bots">{{ __('Bots') }}</button>
                </div>
            </div>

            <div class="flex gap-3 max-lg:flex-col lg:gap-8" data-fit-room="380">
                <div class="flex min-w-0 flex-none flex-col gap-2 max-lg:w-full lg:gap-3" :class="mode === 'bots' && stage === 'pick' ? 'max-lg:hidden' : ''" data-test="board-column">
                    {{-- On a phone the lesson or the bot game is one line above the block; it opens over the block, so the block keeps the screen. --}}
                    <div class="sticky z-20 -mx-2.5 bg-bar lg:hidden" data-phone-bar x-show="mode === 'learn' || stage !== 'pick'" data-test="phone-bar">
                        <div class="flex min-h-11 items-center gap-2 px-2.5 py-1">
                            <button type="button" class="flex min-h-11 min-w-0 grow cursor-pointer items-center gap-2 border-0 bg-transparent p-0 text-left" :aria-expanded="sheetOpen.toString()" x-on:click="sheetOpen = ! sheetOpen" data-test="phone-bar-toggle">
                                <span class="min-w-0 grow text-[13px] leading-snug font-bold" :class="phoneTone" x-text="phoneLine" data-test="lesson-task-phone"></span>
                                <span class="shrink-0 text-base text-ink-2" aria-hidden="true" x-text="sheetOpen ? '▴' : '▾'"></span>
                            </button>
                            <button type="button" class="rv-b shrink-0" x-cloak x-show="mode === 'learn' && lessonState === 'done'" x-on:click="nextLesson()" data-test="lesson-next-phone"
                                    x-text="lessonIndex < cfg.lessons.length - 1 ? cfg.labels.nextLesson : cfg.labels.toBots"></button>
                            <button type="button" class="rv-b2 shrink-0" x-cloak x-show="mode === 'learn' && lessonState === 'retry'" x-on:click="openLesson(lessonIndex)" data-test="lesson-again-phone">{{ __('Start again') }}</button>
                        </div>
                        <div x-cloak x-show="sheetOpen" class="absolute inset-x-0 top-full flex max-h-[70svh] flex-col gap-3 overflow-y-auto border-b border-line bg-bar p-3 shadow-[0_16px_32px_rgba(10,10,11,.8)]" data-test="phone-sheet">
                            <template x-if="mode === 'learn'">
                                <div class="flex flex-col gap-3">
                                    <span class="text-xs text-ink-2" x-text="cfg.labels.lessonOf.replace(':n', lessonIndex + 1).replace(':total', cfg.lessons.length)"></span>
                                    <h2 class="m-0 font-display text-base font-bold" x-text="lesson.title"></h2>
                                    <p class="m-0 text-[13px] leading-normal text-ink-2" x-text="lesson.text"></p>
                                    <ol class="m-0 flex list-none flex-wrap gap-1 p-0" aria-label="{{ __('Lessons') }}">
                                        <template x-for="(item, i) in cfg.lessons" :key="item.id">
                                            <li><button type="button" class="grid size-11 cursor-pointer place-items-center rounded-[4px] text-xs font-bold"
                                                        :class="i === lessonIndex ? 'bg-btc text-on-btc' : done.includes(item.id) ? 'bg-btc-press text-btc-hi' : 'bg-well text-ink-2'"
                                                        :aria-label="item.title" x-on:click="openLesson(i)" x-text="i + 1"></button></li>
                                        </template>
                                    </ol>
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" class="rv-b grow" x-on:click="sheetOpen = false" data-test="sheet-close">{{ __('Got it') }}</button>
                                        <button type="button" class="rv-b2" x-on:click="nextLesson()">{{ __('Skip') }}</button>
                                        <button type="button" class="rv-b2" x-on:click="pickBot(botId)">{{ __('Bots') }}</button>
                                    </div>
                                </div>
                            </template>
                            <template x-if="mode === 'bots'">
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="rv-b grow" x-show="stage === 'over'" x-on:click="play()">{{ __('Another game') }}</button>
                                    <button type="button" class="rv-b2 grow" x-on:click="pickBot(botId)">{{ __('Choose a bot') }}</button>
                                    <button type="button" class="rv-b2 grow" x-show="stage === 'play'" x-on:click="sheetOpen = false; call('resign')">{{ __('Resign') }}</button>
                                    <button type="button" class="rv-b2 grow" x-on:click="openLesson(Math.max(0, cfg.lessons.findIndex((l) => !done.includes(l.id))))">{{ __('Lessons') }}</button>
                                </div>
                            </template>
                        </div>
                    </div>
                    @include('pages.board.partials.blockspace-stage')
                </div>

                <div class="flex min-w-0 grow flex-col gap-3" :class="mode === 'bots' && stage === 'pick' ? '' : 'max-lg:hidden'" data-test="practice-panel">
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
                        <p class="m-0 text-[13px] leading-normal text-ink" x-cloak x-show="lessonHint && lessonState === 'task'" x-text="lessonHint" data-test="lesson-hint"></p>
                        <p class="m-0 text-[13px] text-ink-2" x-cloak x-show="thinking" x-text="turnLine"></p>
                        <div role="status" aria-live="polite">
                            <p class="m-0 text-[13px] leading-normal font-bold" :class="lessonState === 'done' ? 'text-win' : 'text-loss'" x-cloak x-show="lessonState !== 'task'" x-text="lessonNote" data-test="lesson-note"></p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-button x-cloak x-show="lessonState === 'done'" x-on:click="nextLesson()" data-test="lesson-next"><span x-text="lessonIndex < cfg.lessons.length - 1 ? cfg.labels.nextLesson : cfg.labels.toBots"></span></x-button>
                            <x-button variant="quiet" x-cloak x-show="lessonState !== 'task' || state.ply > 0" x-on:click="openLesson(lessonIndex)" data-test="lesson-again">{{ __('Start again') }}</x-button>
                            <x-button variant="secondary" x-cloak x-show="lessonState !== 'done'" x-on:click="nextLesson()" data-test="lesson-skip">{{ __('Skip') }}</x-button>
                        </div>
                    </section>

                    <section x-cloak x-show="mode === 'bots'" aria-labelledby="bots-h" class="flex flex-col gap-3" data-test="bots">
                        <div x-cloak x-show="stage === 'pick'" class="flex flex-col gap-3 rounded-lg bg-card p-4" data-test="bot-pick">
                            <h2 id="bots-h" class="m-0 text-[15px] font-bold">{{ __('Choose a bot') }}</h2>
                            <div role="radiogroup" aria-labelledby="bots-h" class="flex flex-col gap-2">
                                <template x-for="(bot, id) in cfg.bots" :key="id">
                                    <button type="button" role="radio" :aria-checked="(botId === id).toString()" x-on:click="pickBot(id)" :data-test="'bot-' + id"
                                            class="flex w-full cursor-pointer items-center gap-3 rounded-[4px] border px-3 py-2 text-left" :class="botId === id ? 'border-btc bg-btc-press' : 'border-line bg-well hover:bg-row-hover'">
                                        <span class="flex min-w-0 grow flex-col">
                                            <b class="text-[15px]"><span x-text="bot.name"></span> <span class="text-xs font-normal text-ink-3" x-text="bot.grade"></span></b>
                                            <span class="text-[13px] leading-snug text-ink-2" x-text="bot.text"></span>
                                        </span>
                                        <span class="shrink-0 text-xs font-bold text-win" x-cloak x-show="beaten.includes(id)">{{ __('beaten') }}</span>
                                    </button>
                                </template>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-[13px] text-ink-2" id="first-h">{{ __('Who starts?') }}</span>
                                <div role="group" aria-labelledby="first-h" class="flex gap-1 rounded-md bg-well p-1">
                                    <button type="button" class="h-11 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="humanFirst ? 'bg-btc-press font-bold text-btc-hi' : 'text-ink-2'" :aria-pressed="humanFirst.toString()" x-on:click="humanFirst = true" data-test="first-me">{{ __('Me') }}</button>
                                    <button type="button" class="h-11 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="humanFirst ? 'text-ink-2' : 'bg-btc-press font-bold text-btc-hi'" :aria-pressed="(!humanFirst).toString()" x-on:click="humanFirst = false" data-test="first-bot">{{ __('The bot') }}</button>
                                </div>
                                <div role="group" aria-label="{{ __('The fuse') }}" class="flex gap-1 rounded-md bg-well p-1">
                                    <button type="button" class="h-11 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="fuse ? 'text-ink-2' : 'bg-btc-press font-bold text-btc-hi'" :aria-pressed="(!fuse).toString()" x-on:click="fuse = false" data-test="fuse-off">{{ __('Normal') }}</button>
                                    <button type="button" class="h-11 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="fuse ? 'bg-btc-press font-bold text-btc-hi' : 'text-ink-2'" :aria-pressed="fuse.toString()" x-on:click="fuse = true" data-test="fuse-on">{{ __('With the fuse') }}</button>
                                </div>
                            </div>
                            <x-button icon="bolt" x-on:click="play()" data-test="bot-play"><span x-text="@js(__('Play :name')).replace(':name', cfg.bots[botId].name)"></span></x-button>
                            <p class="m-0 text-xs text-ink-3">{{ __('Practice games are not stored and count for nothing.') }}</p>
                        </div>

                        <div x-cloak x-show="stage !== 'pick'" class="flex flex-col gap-3 rounded-lg bg-card p-4" data-test="bot-game">
                            <b class="text-[15px]"><span x-text="botName"></span> <span class="text-xs font-normal text-ink-3" x-text="cfg.bots[botId].grade"></span></b>
                            <span role="status" class="text-[13px]" :class="won ? 'font-bold text-win' : 'text-ink-2'" x-text="stage === 'over' ? resultLine : turnLine" data-test="turn-line"></span>
                            <div class="flex flex-wrap gap-2">
                                <x-button x-cloak x-show="stage === 'over'" x-on:click="play()" data-test="play-again">{{ __('Another game') }}</x-button>
                                <x-button variant="quiet" x-cloak x-show="stage === 'over'" x-on:click="pickBot(botId)" data-test="choose-bot">{{ __('Choose a bot') }}</x-button>
                                <x-button variant="quiet" x-cloak x-show="stage === 'play'" x-on:click="call('resign')" data-test="resign">{{ __('Resign') }}</x-button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</div>
