{{--
    A board game's practice page (pages/board/⚡practice for Blockli, ⚡go-practice for Go): the match view T2 of a game's
    page (pages/board/⚡show) with the practice panel in the middle column, one layout for both so they cannot drift
    apart. The lessons, the bots with the game against one, the tips and the moves are the same for every game
    (resources/js/boardPractice.js); the page gives what its game alone has:

    `slug` the game (the lobby the page leads back to), `config` the practice script's config
    (App\Livewire\Concerns\JudgesPractice), `data` its Alpine component, `title` the page's heading, `first` the question
    who starts and `note` the line under "Play".
    Slots: the game's input bar and what stands with it (the default slot), `credit` beside the heading, `standing` how
    the game against a bot stands, under the tips, `underBoard` what follows the board in its column.

    A board of stones (Go, `$stones`) brings the parts of its game's page instead of the plain ones here (design pass
    2026-11-14, pages/board/partials/go-practice-*, go-lesson-*): the lesson's head with its rail, the task and the
    verdict; both sides, the turn card and the result card of a game against a bot. Every other game renders as before.
--}}
@props(['slug', 'config', 'data', 'title', 'first', 'note'])

@php
    // A board of stones (Go) takes the keyboard and is never turned; any other turns for the second side.
    $stones = ($config['layout']['input'] ?? null) === 'stones';
@endphp

<div class="flex grow flex-col px-4 pb-8 lg:px-12 lg:pb-10" data-test="board-page" data-focus-page>
    <div x-data="{{ $data }}(@js($config))" class="mx-auto flex w-full max-w-[1000px] flex-col" data-test="practice">
        <div wire:ignore x-data="boardGame(boardConfig())" x-on:keydown.window="browseKey($event)" class="flex flex-col gap-2 lg:gap-5" data-test="board-game">

            {{-- Title row: the page, the way back to the lobby, the lessons or the bots. --}}
            <div class="flex flex-wrap items-center gap-x-2 gap-y-2 lg:gap-x-4">
                @isset($credit)
                    <div class="flex min-w-0 items-baseline gap-2 lg:block">
                        <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ $title }}</h1>
                        {{ $credit }}
                    </div>
                @else
                    <h1 class="m-0 font-display text-[22px] font-bold lg:text-[28px]">{{ $title }}</h1>
                @endisset
                <a href="{{ route('board.lobby', $slug) }}" @navigate(route('board.lobby', $slug)) @class(['text-[13px] text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc', '-my-2 inline-flex min-h-11 min-w-11 items-center justify-center' => $stones]) data-test="board-lobby-link">{{ __('Lobby') }}</a>
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
                    @if ($stones)
                        @include('pages.board.partials.go-practice-phone')
                    @else
                    {{-- With the verdict, its next step beside it: on a phone the lesson card lies below the board. --}}
                    <div class="flex items-center gap-2 lg:hidden" x-cloak x-show="mode === 'learn'">
                        <p class="m-0 min-w-0 grow text-[13px] leading-snug font-bold" :class="lessonState === 'done' ? 'text-win' : lessonState === 'retry' ? 'text-loss' : 'text-btc-hi'"
                           x-text="lessonState === 'task' ? lesson.task : lessonNote" data-test="lesson-task-phone"></p>
                        <button type="button" class="rv-b shrink-0" x-cloak x-show="lessonState === 'done'" x-on:click="nextLesson()" data-test="lesson-next-phone"
                                x-text="lessonIndex < cfg.lessons.length - 1 ? cfg.labels.nextLesson : cfg.labels.toBots"></button>
                        <button type="button" class="rv-b2 shrink-0" x-cloak x-show="lessonState === 'retry'" x-on:click="openLesson(lessonIndex)" data-test="lesson-again-phone">{{ __('Start again') }}</button>
                    </div>
                    <div class="flex items-center gap-2 lg:hidden" x-cloak x-show="mode === 'bots' && stage !== 'pick'">
                        {{-- On a phone the tips card lies under the board and the game's bar: a tip shows here, in view with the board,
                             and the turn stays in the bar. --}}
                        <p class="m-0 min-w-0 grow text-[13px] leading-snug text-ink" :class="stage === 'play' && tip ? 'font-normal' : 'font-bold'"
                           x-text="stage === 'over' ? resultLine : (tip ? tip.text : turnLine)" data-test="turn-phone"></p>
                        <button type="button" class="rv-b2 shrink-0" x-cloak x-show="stage === 'play'" x-bind:disabled="!canAsk" x-on:click="askTip()"
                                x-text="asked === 0 ? @js(__('Get a tip')) : @js(__('Another tip'))" data-test="ask-tip-phone">{{ __('Get a tip') }}</button>
                        <button type="button" class="rv-b2 shrink-0" x-cloak x-show="stage === 'over'" x-on:click="play()" data-test="play-again-phone">{{ __('Another game') }}</button>
                    </div>
                    @endif
                    <div class="relative mx-auto w-full" data-board-box>
                        <svg x-ref="board" data-focus-board data-live-clear viewBox="0 0 {{ $config['layout']['width'] }} {{ $config['layout']['height'] }}"
                             x-on:click="pick($event)" x-on:keydown="pickKey($event)"
                             role="group" aria-label="{{ __('Game board') }}" @if ($stones) tabindex="0" @else x-bind:class="color === 'b' ? 'rotate-180' : ''" @endif
                             style="aspect-ratio: {{ $config['layout']['width'] }} / {{ $config['layout']['height'] }}"
                             @class(['block w-full touch-manipulation rounded-[4px] bg-well select-none', '[&:focus:not(:focus-visible)]:outline-none' => $stones]) data-test="board"></svg>
                    </div>
                    {{ $underBoard ?? '' }}
                </div>

                {{-- The practice panel: the lesson, or the bots and the game against one. --}}
                {{-- Below lg, while a bot is being chosen, the chooser comes first: the board only shows the start position then. --}}
                <div class="flex min-w-0 flex-col gap-3" :class="mode === 'bots' && stage === 'pick' ? 'max-lg:order-first' : ''" data-test="practice-panel">

                    @if ($stones)
                        @include('pages.board.partials.go-practice-lesson')
                    @else
                    {{-- A lesson: what it explains, the task, the verdict. --}}
                    <section x-cloak x-show="mode === 'learn'" aria-labelledby="lesson-h" class="flex flex-col gap-3 rounded-lg bg-card p-4" data-test="lesson">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs text-ink-2" x-text="cfg.labels.lessonOf.replace(':n', lessonIndex + 1).replace(':total', cfg.lessons.length)" data-test="lesson-count"></span>
                            {{-- Up to six dots (Blockli) stand in one row as they did, more (Go's eight) wrap. --}}
                            <ol @class(['m-0 flex list-none', 'flex-wrap' => count($config['lessons']) > 6, 'gap-1 p-0']) aria-label="{{ __('Lessons') }}">
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
                    @endif

                    {{-- The bots: pick one and who starts, then the game with its tips. --}}
                    <section x-cloak x-show="mode === 'bots'" aria-labelledby="bots-h" class="flex flex-col gap-3" data-test="bots">
                        <div x-cloak x-show="stage === 'pick'" class="flex flex-col gap-3 rounded-lg bg-card p-4" data-test="bot-pick">
                            <h2 id="bots-h" class="m-0 text-[15px] font-bold">{{ __('Choose a bot') }}</h2>
                            <div role="radiogroup" aria-labelledby="bots-h" class="flex flex-col gap-2">
                                <template x-for="(bot, id) in cfg.bots" :key="id">
                                    <button type="button" role="radio" :aria-checked="(botId === id).toString()" x-on:click="pickBot(id)" :data-test="'bot-' + id"
                                            @class(['flex w-full cursor-pointer items-center gap-3 rounded-[4px] border px-3 py-2 text-left', 'flex-wrap gap-y-2' => $stones]) :class="botId === id ? 'border-btc bg-btc-press' : 'border-line bg-well hover:bg-row-hover'">
                                        @if ($stones)
                                            {{-- Go: the bot's letter plain (orange is the player's action), with the stone it would play. --}}
                                            <span class="relative shrink-0" aria-hidden="true">
                                                <span class="grid size-10 place-items-center rounded-[4px] bg-raised font-display text-sm font-bold text-ink" x-text="bot.name.slice(0, 1)"></span>
                                                <span class="absolute -right-1 -bottom-1 block size-4 rounded-full ring-2" :class="botId === id ? 'bg-btc-press ring-btc-press' : 'bg-well ring-well'" x-html="stoneGlyph(humanFirst ? 'b' : 'w')"></span>
                                            </span>
                                        @else
                                        <span class="grid size-10 shrink-0 place-items-center rounded-[4px] bg-btc-press font-display text-sm font-bold text-btc" aria-hidden="true" x-text="bot.name.slice(0, 1)"></span>
                                        @endif
                                        @if ($stones)
                                            {{-- Go: what the bot is like takes the button's whole width under its name (beside the tile it had 178 px at 320). --}}
                                            <b class="min-w-0 grow text-[15px]"><span x-text="bot.name"></span> <span class="text-xs font-normal text-ink-3" x-text="bot.level"></span></b>
                                            <span class="shrink-0 text-xs font-bold text-win" x-cloak x-show="beaten.includes(id)">{{ __('beaten') }}</span>
                                            <span class="basis-full text-[13px] leading-[18px] text-ink-2" x-text="bot.text"></span>
                                        @else
                                        <span class="flex min-w-0 grow flex-col">
                                            <b class="text-[15px]"><span x-text="bot.name"></span> <span class="text-xs font-normal text-ink-3" x-text="bot.level"></span></b>
                                            <span class="text-[13px] leading-snug text-ink-2" x-text="bot.text"></span>
                                        </span>
                                        <span class="shrink-0 text-xs font-bold text-win" x-cloak x-show="beaten.includes(id)">{{ __('beaten') }}</span>
                                        @endif
                                    </button>
                                </template>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-[13px] text-ink-2" id="first-h">{{ $first }}</span>
                                <div role="group" aria-labelledby="first-h" class="flex gap-1 rounded-md bg-well p-1">
                                    <button type="button" @class(['h-11 cursor-pointer rounded-[4px] px-3 text-[13px]', 'min-w-11' => $stones]) :class="humanFirst ? 'bg-btc-press font-bold text-btc-hi' : 'text-ink-2'" :aria-pressed="humanFirst.toString()" x-on:click="setFirst(true)" data-test="first-you">{{ __('You') }}</button>
                                    <button type="button" class="h-11 cursor-pointer rounded-[4px] px-3 text-[13px]" :class="humanFirst ? 'text-ink-2' : 'bg-btc-press font-bold text-btc-hi'" :aria-pressed="(!humanFirst).toString()" x-on:click="setFirst(false)" x-text="cfg.bots[botId].name" data-test="first-bot"></button>
                                </div>
                            </div>
                            {{-- The primary action: below lg a bar fixed at the bottom (where the game's bar takes over once the game runs),
                                 so it is on the first screen at any height; from lg in the card. --}}
                            <div class="max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-20 max-lg:flex max-lg:border-t max-lg:border-line max-lg:bg-bar max-lg:px-4 max-lg:py-1.5 max-lg:shadow-[0_-16px_32px_rgba(10,10,11,.8)]" data-page-bar data-test="bot-play-bar">
                                <x-button icon="bolt" class="max-lg:grow" x-on:click="play()" data-test="bot-play"><span x-text="@js(__('Play :name')).replace(':name', cfg.bots[botId].name)"></span></x-button>
                            </div>
                            <p class="m-0 text-xs text-ink-3">{{ $note }}</p>
                        </div>

                        <div x-cloak x-show="stage !== 'pick'" class="flex flex-col gap-3" @if ($stones) data-fit-after @endif data-test="bot-game">
                            @if ($stones)
                                @include('pages.board.partials.go-practice-game')
                            @else
                            <div class="flex items-center gap-3 rounded-lg bg-card p-3" data-test="bot-card">
                                <span class="grid size-11 shrink-0 place-items-center rounded-[4px] bg-btc-press font-display text-sm font-bold text-btc" aria-hidden="true" x-text="botName.slice(0, 1)"></span>
                                <span class="flex min-w-0 grow flex-col">
                                    <b class="text-[15px]"><span x-text="botName"></span> <span class="text-xs font-normal text-ink-3" x-text="cfg.bots[botId].level"></span></b>
                                    <span role="status" class="text-[13px] text-ink-2" x-text="stage === 'over' ? resultLine : turnLine" data-test="turn-line"></span>
                                </span>
                            </div>

                            <div x-cloak x-show="stage === 'over'" class="flex flex-col gap-3 rounded-lg bg-card p-4" data-test="practice-result">
                                <b class="text-base" :class="humanWon ? 'text-win' : 'text-ink'" x-text="resultLine" data-test="result"></b>
                                <div class="flex flex-wrap gap-2">
                                    <x-button x-cloak x-show="nextBot" x-on:click="const next = nextBot; pickBot(next); play()" data-test="next-bot"><span x-text="nextBot ? @js(__('Play :name')).replace(':name', cfg.bots[nextBot].name) : ''"></span></x-button>
                                    <x-button variant="quiet" x-on:click="play()" data-test="play-again">{{ __('Another game') }}</x-button>
                                    <x-button variant="secondary" x-on:click="pickBot(botId)" data-test="choose-bot">{{ __('Choose a bot') }}</x-button>
                                </div>
                            </div>
                            @endif

                            {{-- The tips: a line now and then by themselves, and the tip button for a nudge (never the move). --}}
                            {{-- Go: only while the game runs (a game that is over has no tip to give, R11). On a phone the tip has its
                                 place under the board and its button in the bar (partials/go-practice-tip): the card keeps the switch. --}}
                            <div class="flex flex-col gap-2 rounded-lg bg-card p-4" @if ($stones) x-cloak x-show="stage === 'play'" @endif data-test="tips">
                                <div class="flex items-center justify-between gap-2">
                                    <h2 class="m-0 text-[15px] font-bold">{{ __('Tips') }}</h2>
                                    {{-- R11: the board's checkbox, never the native one; 44 px tall. --}}
                                    <x-ui.check class="text-[13px] text-ink-2" x-bind:aria-checked="tipsOn.toString()" x-on:click="setTips(!tipsOn)" data-test="tips-on">{{ __('Show by themselves') }}</x-ui.check>
                                </div>
                                <p role="status" aria-live="polite" @class(['m-0 min-h-5 text-[13px] leading-normal', 'max-lg:hidden' => $stones]) :class="tip ? 'text-ink' : 'text-ink-3'" x-text="tip ? tip.text : @js(__('Stuck? Ask for a tip: it tells you what kind of move fits, never the move itself.'))" data-test="tip"></p>
                                <x-button variant="quiet" @class(['self-start', 'max-lg:hidden' => $stones]) x-bind:disabled="!canAsk" x-on:click="askTip()" data-test="ask-tip"><span x-text="asked === 0 ? @js(__('Get a tip')) : @js(__('Another tip'))"></span></x-button>
                            </div>

                            {{ $standing ?? '' }}
                            <p role="alert" class="m-0 text-[13px] text-loss" x-cloak x-show="failed" x-text="failed"></p>

                            {{-- Go: gone with the game's end, as the tips are (the result card has the ways on). --}}
                            <div class="flex flex-wrap items-center justify-end gap-2" x-cloak x-show="{{ $stones ? "stage === 'play'" : "stage === 'play' || canUndo" }}" data-test="practice-actions">
                                <x-button variant="quiet" x-bind:disabled="!canUndo" x-on:click="undo()" data-test="undo">{{ __('Take back') }}</x-button>
                                <template x-if="state.status === 'active'">
                                    <x-button variant="secondary" x-on:click="resign()" class="text-loss! border-[#5A2A2E]!" data-test="resign">
                                        <span x-text="confirmResign ? @js(__('Resign this game?')) : @js(__('Resign'))"></span>
                                    </x-button>
                                </template>
                            </div>
                        </div>
                    </section>

                    {{-- The game's input, as on a game's page: on a phone a bar fixed at the bottom, from lg here in the column. --}}
                    {{ $slot }}
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
                    @if ($stones)
                        @include('pages.board.partials.go-practice-you')
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
