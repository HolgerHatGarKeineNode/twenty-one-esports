<?php

namespace App\Support\Pong;

use App\Games\ProofOfPong;
use App\Support\Hyper\HyperRng;
use InvalidArgumentException;

/**
 * Proof of Pong's rules (plan "Proof of Pong", P1), mirrored by resources/js/pong/rules.js: a game to
 * `pointsToWin`, two points ahead (at 20:20 it goes on until one side leads by two), and meme events, the same for
 * both sides: every block of `eventBlock` rallies (P8, user 2026-10-10: "bei 21 Ballwechseln alle Phasen durchgespielt
 * … nicht überlappend") holds all nine events once, each on its own rally:
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
 * Each event lasts its one rally. Which rallies of a block and in which order are drawn from the game's seed, block
 * after block (blocks()): the nine events in a fresh shuffle on nine distinct rallies of the block, the other rallies
 * plain, never two events in one rally, and a block never opens with the event that closed the one before. A block
 * shorter than nine rallies (a test's seam) makes every rally an event, the first `eventBlock` of each shuffle. Each
 * rally's serve is drawn from its own seed (rallySeed()), so a rally is replayable on its own.
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

    /**
     * The code both sides compute a rally with: the server's rules and physics and the browser's mirror of them. Any
     * change to one of these files is a new version() (P8: a match tab opened before a deploy reloads itself).
     */
    public const array CODE = [
        'app/Support/Pong/PongRules.php',
        'app/Support/Pong/PongPhysics.php',
        'app/Support/Pong/PongRally.php',
        'resources/js/pong/rules.js',
        'resources/js/pong/physics.js',
        'resources/js/pong/rng.js',
    ];

    public function __construct(
        public int $pointsToWin = ProofOfPong::POINTS_TO_WIN,
        public int $winBy = ProofOfPong::WIN_BY,
        public int $eventBlock = 21,
    ) {
        if ($pointsToWin < 1 || $winBy < 1 || $eventBlock < 1) {
            throw new InvalidArgumentException('Points to win, lead and event block are positive.');
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
            (int) ($config['event_block_rallies'] ?? 21),
        );
    }

    /**
     * @return array{points_to_win: int, win_by: int, event_block_rallies: int}
     */
    public function toArray(): array
    {
        return ['points_to_win' => $this->pointsToWin, 'win_by' => $this->winBy, 'event_block_rallies' => $this->eventBlock];
    }

    /**
     * A short fingerprint of these rules and of the code that plays them (CODE), carried by every live snapshot: a page
     * whose snapshot at load had another one runs older code than the server and reloads itself between two rallies
     * (resources/js/pong/live.js), instead of computing rallies the referee no longer agrees with. A file that cannot
     * be read counts as empty, so the fingerprint stays stable rather than failing the snapshot.
     */
    public function version(): string
    {
        /** @var array<string, string> $versions once per process and rule set */
        static $versions = [];
        $rules = json_encode($this->toArray(), JSON_THROW_ON_ERROR);

        return $versions[$rules] ??= substr(hash('sha256', $rules.'|'.implode('|', array_map(
            fn (string $file): string => is_file(base_path($file)) ? (string) hash_file('sha256', base_path($file)) : '',
            self::CODE,
        ))), 0, 16);
    }

    /**
     * The event of rally `$rally` (1 = the first rally), or null for a plain rally.
     */
    public function eventOf(int $seed, int $rally): ?string
    {
        if ($rally < 1) {
            return null;
        }

        $block = intdiv($rally - 1, $this->eventBlock);

        return $this->blocks($seed, $block + 1)[$block][($rally - 1) % $this->eventBlock] ?? null;
    }

    /**
     * The first `$count` blocks of a game: per block, position in the block (0 = its first rally) => event. One
     * generator for the whole game, block after block, so a block knows the event that closed the one before.
     *
     * @return list<array<int, string>>
     */
    public function blocks(int $seed, int $count): array
    {
        $rng = HyperRng::seeded(($seed ^ self::EVENT_SALT) & self::MASK);
        $events = count(self::EVENTS);
        $perBlock = min($events, $this->eventBlock);
        $previous = null;
        $blocks = [];

        for ($b = 0; $b < $count; $b++) {
            $order = $rng->shuffle(self::EVENTS);

            if ($order[0] === $previous) {
                // The block would open with the event that closed the last one: swap it with a later one.
                $swap = 1 + $rng->below($perBlock > 1 ? $perBlock - 1 : $events - 1);
                [$order[0], $order[$swap]] = [$order[$swap], $order[0]];
            }

            $positions = array_slice($rng->shuffle(range(0, $this->eventBlock - 1)), 0, $perBlock);
            sort($positions);
            $blocks[] = array_combine($positions, array_slice($order, 0, $perBlock));
            $previous = $order[$perBlock - 1];
        }

        return $blocks;
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
