<?php

use App\Games\Blockli;
use App\Games\GameRegistry;
use App\Livewire\Concerns\JudgesPractice;
use App\Support\Board\BlockliPractice;
use App\Support\Cards\PageCard;
use App\Support\GameNames;
use App\Support\PageMeta;
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
 * and `open` (App\Livewire\Concerns\JudgesPractice) are a judge without memory, they get the position the browser
 * holds and answer by BlockliRules (App\Support\Board\BlockliPractice). The bots and the tips run in the browser on
 * the engine of DerCaddy's prototype. The view is the one of every board game's practice (<x-board.practice>).
 *
 * Only for Blockli, and only while Blockli is switched on (the lobby's tile leads here); 404 otherwise.
 */
new #[Layout('layouts::app', ['scripts' => ['resources/js/blockli/practice.js']])] class extends Component
{
    use JudgesPractice;

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

    protected function practice(): BlockliPractice
    {
        return app(BlockliPractice::class);
    }

    /**
     * What Blockli's practice has of its own for the practice script (JudgesPractice::practiceConfig()).
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        $rules = $this->practice()->rules();
        // The forms for one and for more, filled in the browser (:count).
        $steps = [trans_choice(':count step|:count steps', 1, ['count' => ':count']), trans_choice(':count step|:count steps', 2, ['count' => ':count'])];
        $blocks = [trans_choice(':count block|:count blocks', 1, ['count' => ':count']), trans_choice(':count block|:count blocks', 2, ['count' => ':count'])];

        return $this->practiceConfig(
            BlockliPractice::LESSONS,
            fn (array $lesson): array => [
                'steps' => $lesson['steps'] ?? 0,
                // The fence lesson's refused block as the board names it (crossing and direction), and why it is refused.
                'closing' => isset($lesson['closing']) ? $rules->path($lesson['closing']) : null,
                'closingNote' => isset($lesson['closing']) ? __('Red: that block would lock Black in completely, so the rules refuse it.') : null,
            ],
            ['hodler' => __('Never gives a block away. He only runs.'), 'miner' => __('Sets a block whenever one costs you a step. Then he has none left.'), 'whale' => __('Thinks many moves ahead. Few beat him.')],
            [
                'lessonBlockOnly' => __('A step of your pawn does not stop him. Set a block.'),
                'race' => __('You need :mine and have :blocks; :name needs :theirs and has :botBlocks.'),
                'steps' => $steps,
                'blocks' => $blocks,
                'result' => ['won' => __('You beat :name!'), 'lost' => __(':name wins this one.')],
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
            ],
            [
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
        );
    }
}; ?>

<x-board.practice :slug="$slug" :config="$this->config()" data="blockliPractice" :title="__(':game · Practice', ['game' => GameNames::game($slug)])"
                  :first="__('Who starts?')" :note="__('Practice games are not stored and count for nothing. The first move is an advantage.')">
    <x-slot:credit><x-game-credit :game="$slug" /></x-slot:credit>
    <x-slot:standing><p class="m-0 text-[13px] text-ink-2" x-cloak x-show="raceLine" x-text="raceLine" data-test="race"></p></x-slot:standing>

    {{-- The block input (Blockli), as on a game's page: on a phone a bar fixed at the bottom, from lg here in the column. --}}
    <template x-if="layout.input === 'blocks' && color && state.status === 'active'">
        <x-board.input-bar name="block-input" status="block-status" line="lg:order-2"
                           tone="[preview && !preview.move && settingBlock ? 'text-loss' : 'text-ink-2', blockButtons ? 'max-lg:hidden' : '', settingBlock ? '' : 'lg:hidden']">
            <button type="button" class="inline-flex h-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md px-[18px] max-[359px]:px-2 text-[13px] disabled:cursor-default disabled:opacity-50 lg:order-1 lg:w-full"
                    :class="[blockMode ? 'border border-btc bg-btc-press font-bold text-btc-hi' : 'btn-w border border-line bg-well text-ink', blockButtons ? 'max-lg:hidden' : '']"
                    :aria-pressed="toggleMoves ? 'false' : (blockMode ? 'true' : 'false')" :disabled="!myTurn || !canSetBlocks" x-on:click="setBlockMode(!blockMode, $event)" aria-keyshortcuts="ArrowUp ArrowDown ArrowLeft ArrowRight R Enter Escape"
                    data-test="mode-block">
                    {{-- Both labels in one cell, the one not shown invisible: the button is as wide as the longer one and does not jump
                         when the label swaps or the count goes from 10 to 9 (measured 2026-11-13: 8 to 16 px in German on a phone). --}}
                    <span class="grid whitespace-nowrap" data-toggle-label>
                        <span class="col-start-1 row-start-1" :class="toggleMoves ? 'invisible' : ''">{{ __('Set a block') }} <span class="inline-block w-[2ch] text-start tabular-nums" x-text="blocksLeft"></span></span>
                        <span class="col-start-1 row-start-1" :class="toggleMoves ? '' : 'invisible'">{{ __('Move the pawn') }}</span>
                    </span>
            </button>
            <div class="min-w-0 grow gap-2 lg:order-3" :class="blockButtons ? 'flex' : 'hidden'" data-block-buttons>
                <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md border border-line bg-well px-2 text-xs whitespace-nowrap min-[360px]:text-[13px] text-ink btn-w hover:text-ink disabled:cursor-default disabled:opacity-50"
                        x-bind:disabled="!preview" x-on:click="rotateBlock()" data-test="rotate-block">{{ __('Rotate') }}</button>
                <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md bg-btc px-2 text-xs whitespace-nowrap min-[360px]:text-[13px] font-bold text-on-btc btn-p hover:text-on-btc disabled:cursor-default disabled:opacity-50"
                        x-bind:disabled="!preview || !preview.move" x-on:click="setBlock()" data-test="set-block">{{ __('Confirm') }}</button>
                <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md border border-edge px-2 text-xs whitespace-nowrap min-[360px]:text-[13px] text-ink btn-s hover:text-ink"
                        :class="toggleMoves ? 'lg:hidden' : ''" x-on:click="setBlockMode(false)" data-test="mode-move">{{ __('Move the pawn') }}</button>
            </div>
            {{-- Always in the page, so a screen reader hears each new hint (the keys say where the block is). --}}
            <p class="sr-only" role="status" aria-live="polite" x-text="blockHint" data-test="block-hint"></p>
        </x-board.input-bar>
    </template>
</x-board.practice>
