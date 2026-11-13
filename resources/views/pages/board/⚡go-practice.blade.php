<?php

use App\Games\Go;
use App\Livewire\Concerns\JudgesPractice;
use App\Support\Board\GoLabels;
use App\Support\Board\GoPractice;
use App\Support\Cards\PageCard;
use App\Support\GameNames;
use App\Support\PageMeta;
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
 * keeps no game: `play` and `open` (App\Livewire\Concerns\JudgesPractice) are a judge without memory, they get the
 * position the browser holds and answer by GoRules (App\Support\Board\GoPractice). The bots and the tips run in the
 * browser on its go engine. The view is the one of every board game's practice (<x-board.practice>).
 *
 * One page for all sizes, at /games/go/practice (each Go lobby's tile leads here), while one is switched on; 404
 * otherwise. Its context bar and card are those of the smallest size on (Go::home()).
 */
new #[Layout('layouts::app', ['scripts' => ['resources/js/go/practice.js']])] class extends Component
{
    use JudgesPractice;

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

    protected function practice(): GoPractice
    {
        return app(GoPractice::class);
    }

    /**
     * What Go's practice has of its own for the practice script (JudgesPractice::practiceConfig()).
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return $this->practiceConfig(
            GoPractice::LESSONS,
            fn (array $lesson): array => [
                'min' => $lesson['min'] ?? null,
                'point' => $lesson['point'] ?? null,
                'points' => $lesson['points'] ?? [],
                'closing' => $lesson['closing'] ?? null,
                'count' => $lesson['count'] ?? false,
                'pass' => $lesson['pass'] ?? false,
                'closingNote' => isset($lesson['closingNote']) ? __($lesson['closingNote']) : null,
            ],
            [
                'hodler' => __('Never lets a stone go: takes whatever stands in atari and saves his own.'),
                'miner' => __('Digs: plays a few thousand games in his head before each move.'),
                'whale' => __('Thinks long, up to forty thousand games a move. Few beat him on 9 x 9.'),
            ],
            [
                'stones' => [trans_choice(':count stone|:count stones', 1, ['count' => ':count']), trans_choice(':count stone|:count stones', 2, ['count' => ':count'])],
                'result' => ['won' => __('You beat :name by :points points!'), 'lost' => __(':name wins this one by :points points.')],
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
            ],
            GoLabels::board(),
        );
    }
}; ?>

<x-board.practice :slug="$slug" :config="$this->config()" data="goPractice" :title="__(':game · Practice', ['game' => 'Go'])"
                  :first="__('Who plays Black and starts?')" :note="__('Practice games are not stored and count for nothing. Black moves first; White gets 7.5 points of komi for it.')">
    {{-- The count as it stands (in a bot game and in the lesson about it), and Go's bar: Place and Pass, as on a game's page. --}}
    @include('pages.board.partials.area-count')
    @include('pages.board.partials.stone-input', ['bottom' => 'max-lg:bottom-0'])
</x-board.practice>
