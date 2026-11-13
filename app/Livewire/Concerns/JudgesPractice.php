<?php

namespace App\Livewire\Concerns;

use App\Support\Board\BoardPractice;
use App\Support\Board\BoardThrottle;
use App\Support\GameNames;
use Closure;
use Livewire\Attributes\Json;

/**
 * A board game's practice page (pages/board/⚡practice for Blockli, ⚡go-practice for Go; the view is
 * <x-board.practice>): the judge the browser asks, and what the practice script needs. The page keeps no game:
 * `open` and `play` get the position the browser holds and answer by the game's rules (App\Support\Board\BoardPractice),
 * throttled per visitor (BoardThrottle). The page names its game (`$slug`) and its practice().
 */
trait JudgesPractice
{
    /** @return BoardPractice<mixed> */
    abstract protected function practice(): BoardPractice;

    /**
     * What the board needs for a position the browser starts from (BoardPractice::open()).
     *
     * @return array<string, mixed>
     */
    #[Json]
    public function open(mixed $position = null): array
    {
        return BoardThrottle::allows('practice', BoardThrottle::PRACTICE_PER_MINUTE) ? $this->practice()->open($position) : ['ok' => false, 'error' => 'too_many'];
    }

    /**
     * A move in the position the browser holds, judged by the rules (BoardPractice::play()).
     *
     * @return array<string, mixed>
     */
    #[Json]
    public function play(mixed $position = null, mixed $move = null, mixed $history = []): array
    {
        return BoardThrottle::allows('practice', BoardThrottle::PRACTICE_PER_MINUTE) ? $this->practice()->play($position, $move, $history) : ['ok' => false, 'error' => 'too_many'];
    }

    /**
     * Everything the practice script needs (resources/js/boardPractice.js), translated once here: the board's drawing
     * and labels, the start position, the lessons with their positions opened, the bots, and the words of the tips.
     * The page gives what its game alone has: the lessons with each one's own fields (`$own`), what each bot is like,
     * its own labels (`result.won` and `result.lost`, `tips`, `advice` and the words of its lessons) and the words of
     * its board's input.
     *
     * @param  list<array<string, mixed>>  $lessons
     * @param  Closure(array<string, mixed>): array<string, mixed>  $own
     * @param  array{hodler: string, miner: string, whale: string}  $bots
     * @param  array<string, mixed>  $labels
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    protected function practiceConfig(array $lessons, Closure $own, array $bots, array $labels, array $board): array
    {
        $practice = $this->practice();
        $rules = $practice->rules();
        // The names of the two sides as the game gives them (in Go the first side, `w`, is Black).
        $sides = GameNames::sides($this->slug);
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
                'reply' => $lesson['reply'],
                'title' => __($lesson['title']),
                'text' => __($lesson['text']),
                'task' => __($lesson['task']),
                'done' => __($lesson['done']),
                'retry' => __($lesson['retry']),
                'view' => $practice->open($lesson['position']),
                ...$own($lesson),
            ], $lessons),
            'bots' => [
                'hodler' => ['name' => __('Hodler'), 'level' => __('Easy'), 'text' => $bots['hodler']],
                'miner' => ['name' => __('Miner'), 'level' => __('Medium'), 'text' => $bots['miner']],
                'whale' => ['name' => __('Whale'), 'level' => __('Hard'), 'text' => $bots['whale']],
            ],
            'labels' => [
                'you' => __('You'),
                // The lessons' opponent: the second side.
                'rival' => $sides['b'],
                'lessonOf' => __('Lesson :n of :total'),
                'nextLesson' => __('Next lesson'),
                'toBots' => __('On to the bots'),
                'yourMove' => __('Your move'),
                'toMove' => __(':name to move'),
                'thinking' => __(':name is thinking …'),
                'botFailed' => __('The bot could not move. Start a new game.'),
                ...$labels,
                'result' => [...$labels['result'], 'resigned' => __('You resigned, :name wins.'), 'draw' => __('Draw.')],
                'advice' => ['thinking' => __('Looking for a tip …'), ...$labels['advice']],
                // The board's own words (boardGame.js), as on a game's page.
                'board' => [
                    'white' => $sides['w'],
                    'black' => $sides['b'],
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
                    ...$board,
                ],
            ],
        ];
    }
}
