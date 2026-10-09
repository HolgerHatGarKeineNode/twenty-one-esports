<?php

namespace App\Support\Pong;

use App\Games\ProofOfPong;
use App\Support\Hyper\HyperRng;
use InvalidArgumentException;

/**
 * Proof of Pong's rules (plan "Proof of Pong", P1), mirrored by resources/js/pong/rules.js: a game to
 * `pointsToWin`, two points ahead (at 20:20 it goes on until one side leads by two), and every `eventEvery`-th
 * rally a meme event, the same for both sides:
 *
 * - halving: the ball is half as big, and its goal counts twice;
 * - brrr: the ball is half as fast again (serve, speed-up and top speed);
 * - pizza: two balls at once, served to both sides, each goal counts;
 * - difficulty: both paddles are a third shorter;
 * - tax (Steuern sind Raub, P7): the tax office's block patrols the centre line, the ball bounces off it;
 * - controls (Kapitalverkehrskontrolle, P7): a border wall across the centre with a wandering gap; a ball that hits
 *   the wall goes back to the side that played it;
 * - few (Few understand, P7): the ball is invisible in the middle third of the field (only drawn so, the physics is
 *   a plain rally's);
 * - pow (Proof of Work, P7): each hit makes the hitting side's paddle longer, up to a cap, for the rally;
 * - arbeitsamt (Arbeitsamt – Bitte warten, P7): each time the ball crosses the centre line it waits a second in
 *   the queue, then goes on unchanged.
 *
 * The obstacles and the queue are PongPhysics::step(), the paddles' length PongPhysics::halfOf().
 *
 * Each event lasts its one rally. Their order is drawn from the game's seed (all nine events in a shuffled round,
 * then the next round), and each rally's serve from its own seed (rallySeed()), so a rally is replayable on its own.
 * The RNG is Hyperbitcoinization's (xoshiro128++), mirrored by resources/js/pong/rng.js.
 */
final readonly class PongRules
{
    public const string HALVING = 'halving';

    public const string BRRR = 'brrr';

    public const string PIZZA = 'pizza';

    public const string DIFFICULTY = 'difficulty';

    public const string TAX = 'tax';

    public const string CONTROLS = 'controls';

    public const string FEW = 'few';

    public const string POW = 'pow';

    public const string ARBEITSAMT = 'arbeitsamt';

    public const array EVENTS = [self::HALVING, self::BRRR, self::PIZZA, self::DIFFICULTY, self::TAX, self::CONTROLS, self::FEW, self::POW, self::ARBEITSAMT];

    /** Mixed into the game's seed for the order of events, so it does not repeat the first rally's draws. */
    private const int EVENT_SALT = 0x504F4E47;

    private const int MASK = 0xFFFFFFFF;

    public function __construct(
        public int $pointsToWin = ProofOfPong::POINTS_TO_WIN,
        public int $winBy = ProofOfPong::WIN_BY,
        public int $eventEvery = 21,
    ) {
        if ($pointsToWin < 1 || $winBy < 1 || $eventEvery < 1) {
            throw new InvalidArgumentException('Points to win, lead and event interval are positive.');
        }
    }

    /**
     * The rules as `config('esports.pong')` sets them.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            (int) ($config['points_to_win'] ?? ProofOfPong::POINTS_TO_WIN),
            (int) ($config['win_by'] ?? ProofOfPong::WIN_BY),
            (int) ($config['event_every_rallies'] ?? 21),
        );
    }

    /**
     * @return array{points_to_win: int, win_by: int, event_every_rallies: int}
     */
    public function toArray(): array
    {
        return ['points_to_win' => $this->pointsToWin, 'win_by' => $this->winBy, 'event_every_rallies' => $this->eventEvery];
    }

    /**
     * The event of rally `$rally` (1 = the first rally), or null for a plain rally.
     */
    public function eventOf(int $seed, int $rally): ?string
    {
        if ($rally < 1 || $rally % $this->eventEvery !== 0) {
            return null;
        }

        $index = intdiv($rally, $this->eventEvery) - 1;
        $rng = HyperRng::seeded(($seed ^ self::EVENT_SALT) & self::MASK);
        $order = [];

        $count = count(self::EVENTS);

        // Every event once per round of draws, each round its own shuffle.
        for ($round = 0; $round <= intdiv($index, $count); $round++) {
            $order = $rng->shuffle(self::EVENTS);
        }

        return $order[$index % $count];
    }

    /**
     * The winning side, or null while the game runs.
     *
     * @param  array{int, int}  $score
     */
    public function winner(array $score): ?int
    {
        foreach ([0, 1] as $side) {
            if ($score[$side] >= $this->pointsToWin && $this->winBy <= $score[$side] - $score[1 - $side]) {
                return $side;
            }
        }

        return null;
    }

    /**
     * The seed of rally `$rally`: the game's seed mixed with the rally's number (golden-ratio step), 32 bits.
     */
    public static function rallySeed(int $seed, int $rally): int
    {
        return ($seed ^ (($rally * 0x9E3779B9) & self::MASK)) & self::MASK;
    }
}
