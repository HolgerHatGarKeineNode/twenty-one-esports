<?php

namespace App\Support\Pong;

/**
 * The server's referee of a live Proof of Pong match (plan "Proof of Pong", P2, Ansatz "Verteidiger entscheidet,
 * Server prüft"): it keeps the rally in play as each ball's last checked state and decides every paddle contact from
 * the defending player's report, so a client can bend nothing but its own paddle.
 *
 * Between two contacts a ball's path depends on nothing but the ball and the tick (PongPhysics::step(): the walls, and
 * the obstacles and the queue of the P7 events, all from the rally's seed), so the referee knows, for each ball, the
 * tick in which it next crosses a paddle's face, which face, and where it is then. A ball that an obstacle sends back
 * meets the face of the side that played it, and that side defends it. Only the DEFENDING side reports
 * that crossing: `hit` with its paddle's centre, or `goal` (it missed). A hit counts only if
 *
 * - the paddle lies on the field (its centre within the paddle's half length of the walls),
 * - the paddle could get there at the player's top speed (PongPhysics::PLAYER_SPEED per tick) from the positions the
 *   referee accepted for that side nearest in time before AND after the contact (the rally's centre at tick 0, then
 *   each accepted hit): two balls at one face may be reported out of tick order, so a later contact can already be
 *   accepted, and the budget is the tick distance either way, never negative, and
 * - it meets the ball in that tick (PongPhysics::meets()), exactly as PongRally decides a hit, with the side's paddle
 *   length then (PongPhysics::halfOf(): Proof of Work grows it with that side's accepted hits).
 *
 * Anything else is a goal for the attacker. A report from the attacking side changes nothing (nobody can report a goal
 * for themselves); a report for a contact already decided is answered as it was (idempotent by ball and tick); a
 * report for a tick that is not the next contact changes nothing either (the client is out of step and re-syncs). A
 * defender who stays silent loses the ball once the contact is GRACE_MS past on the server's clock (due()).
 *
 * The clock: tick 0 of a rally is `servedAt` (Unix ms, the server's), and `speed` ticks of the game run per 1/60 s
 * (1 in play; the browser test runs faster). A pause (a player gone) holds the clock: resume() moves `servedAt` on.
 *
 * Goals count in the order their balls reach the goal line, as in PongRally (a Pizza Day rally has two balls): the
 * score is the score at the serve plus this rally's goals in that order, cut at the first that wins the game, and a
 * win waits while another ball could still reach a goal line before it. A rally that would run past
 * PongPhysics::RALLY_TICK_CAP ends there without a point for the balls still in play.
 */
final class PongReferee
{
    /** Before the first serve: three seconds once both players are there. */
    public const int START_TICKS = 180;

    /** As resources/js/pong/game.js: the pause on a point, a meme event's announcement, the serve's countdown. */
    public const int POINT_TICKS = 70;

    public const int ANNOUNCE_TICKS = 110;

    public const int SERVE_TICKS = 50;

    /** How late a defender's report may come before the contact counts as a miss (server's clock). */
    public const int GRACE_MS = 4000;

    public const string LIVE = 'live';

    public const string GOAL = 'goal';

    public const string VOID = 'void';

    public int $rally;

    public ?string $event;

    /** Unix ms of the rally's tick 0. */
    public int $servedAt;

    /** @var list<array{b: array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}, t: int, hits: int, state: string, goal: array{int, int, int}|null}> goal: [tick it falls, scorer, contact tick] */
    public array $balls = [];

    /** @var array{int, int} each side's accepted hits in the rally in play (Proof of Work) */
    public array $sideHits = [0, 0];

    /** @var array{array{int, int}, array{int, int}} each side's accepted paddle centre with the latest tick, and that tick */
    public array $paddles;

    /** @var array{list<array{int, int}>, list<array{int, int}>} per side, every accepted paddle centre of the rally and its tick */
    public array $samples = [[], []];

    /** @var array{int, int} the score at this rally's serve */
    public array $scoreBefore;

    /** @var array{int, int} */
    public array $score;

    public ?int $winner = null;

    public ?int $pausedAt = null;

    public int $version = 0;

    /** @var list<array<int|string, int|string|null>> the decisions since the last read (takeLog()) */
    private array $fresh = [];

    private PongRally $serve;

    public function __construct(public readonly int $seed, public readonly PongRules $rules, public readonly int $speed = 1)
    {
        $this->paddles = [[PongPhysics::HEIGHT >> 1, 0], [PongPhysics::HEIGHT >> 1, 0]];
        $this->scoreBefore = [0, 0];
        $this->score = [0, 0];
    }

    /**
     * A match's referee before its first rally, served `START_TICKS` (and a meme event's announcement) after `$now`.
     */
    public static function start(int $seed, PongRules $rules, int $now, int $speed = 1): self
    {
        $referee = new self($seed, $rules, $speed);
        $referee->serveRally(1, $now + $referee->ms(self::START_TICKS - self::SERVE_TICKS));

        return $referee;
    }

    /**
     * @param  array<string, mixed>  $state  toArray()
     */
    public static function fromArray(int $seed, PongRules $rules, array $state, int $speed = 1): self
    {
        $referee = new self($seed, $rules, $speed);
        $referee->rally = (int) $state['rally'];
        $referee->event = $state['event'];
        $referee->servedAt = (int) $state['servedAt'];
        $referee->balls = $state['balls'];
        $referee->paddles = $state['paddles'];
        // A state stored before the samples has only the latest position per side.
        $referee->samples = $state['samples'] ?? [[$state['paddles'][0]], [$state['paddles'][1]]];
        $referee->scoreBefore = $state['scoreBefore'];
        $referee->score = $state['score'];
        $referee->winner = $state['winner'];
        $referee->pausedAt = $state['pausedAt'];
        $referee->version = (int) $state['version'];
        // A state stored before P7 has no hits per side; no rally then grew a paddle.
        $referee->sideHits = $state['sideHits'] ?? [0, 0];
        // The serve follows the stored event, never the rules' current draw: a match running across a deploy that
        // changed the event order keeps judging its rally as it was served (review 2026-10-10).
        $referee->serve = new PongRally(PongRules::rallySeed($seed, $referee->rally), $referee->event, [PongPhysics::PLAYER_SPEED, PongPhysics::PLAYER_SPEED]);

        return $referee;
    }

    /**
     * @return array{rally: int, event: string|null, servedAt: int, balls: list<array<string, mixed>>, paddles: array{array{int, int}, array{int, int}}, samples: array{list<array{int, int}>, list<array{int, int}>}, sideHits: array{int, int}, scoreBefore: array{int, int}, score: array{int, int}, winner: int|null, pausedAt: int|null, version: int, half: int, halves: array{int, int}, points: int}
     */
    public function toArray(): array
    {
        return [
            'rally' => $this->rally,
            'event' => $this->event,
            'servedAt' => $this->servedAt,
            'balls' => $this->balls,
            'paddles' => $this->paddles,
            'samples' => $this->samples,
            'sideHits' => $this->sideHits,
            'scoreBefore' => $this->scoreBefore,
            'score' => $this->score,
            'winner' => $this->winner,
            'pausedAt' => $this->pausedAt,
            'version' => $this->version,
            // Read by the page only (the serve decides them): the paddles' half length and a goal's points.
            'half' => $this->serve->half,
            'halves' => [$this->halfOf(0), $this->halfOf(1)],
            'points' => $this->serve->points,
        ];
    }

    /**
     * What happened since the last call, for the match's log.
     *
     * @return list<array<int|string, int|string|null>>
     */
    public function takeLog(): array
    {
        [$log, $this->fresh] = [$this->fresh, []];

        return $log;
    }

    /** Milliseconds of `$ticks` game ticks on the server's clock. */
    public function ms(int $ticks): int
    {
        return intdiv($ticks * 1000, PongPhysics::TICKS_PER_SECOND * $this->speed);
    }

    /** The server's moment (Unix ms) of tick `$tick` of the rally in play. */
    public function timeOf(int $tick): int
    {
        return $this->servedAt + $this->ms($tick);
    }

    /** Side `$side`'s paddle half length in the rally in play, after its accepted hits. */
    public function halfOf(int $side): int
    {
        return PongPhysics::halfOf($this->event, $this->sideHits[$side]);
    }

    /**
     * Ball `$index`'s next contact: the defending side, the tick in which it crosses that side's face and the ball
     * then (before any hit). Null for a ball no longer in play, or one whose contact would come after the tick cap.
     *
     * @return array{side: int, tick: int, ball: array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}}|null
     */
    public function contact(int $index): ?array
    {
        $entry = $this->balls[$index] ?? null;

        if ($entry === null || $entry['state'] !== self::LIVE) {
            return null;
        }

        $ball = $entry['b'];

        for ($tick = $entry['t'] + 1; $tick <= PongPhysics::RALLY_TICK_CAP; $tick++) {
            // The side it runs towards before this tick's step (an obstacle may turn it, as in PongRally).
            $side = $ball[2] < 0 ? 0 : 1;

            if (! PongPhysics::approaches($ball, $side)) {
                return null;
            }

            $ball = PongPhysics::step($ball, $tick, $this->event, $this->serve->seed);

            if (PongPhysics::crossed($ball, $side)) {
                return ['side' => $side, 'tick' => $tick, 'ball' => $ball];
            }
        }

        return null;
    }

    /**
     * The defender's report on ball `$index` at `$tick`: `hit` with its paddle's centre `$paddle`, or `goal`.
     *
     * @return string `hit` or `miss` (decided now), `duplicate` (decided before, as it was), `stale` (that ball is out
     *                of play), `not_defender` (the attacker reported: nothing changes), `desync` (not that ball's
     *                next contact: nothing changes)
     */
    public function report(int $side, int $index, int $tick, string $kind, ?int $paddle = null): string
    {
        $entry = $this->balls[$index] ?? null;

        if ($entry === null || $this->winner !== null) {
            return 'stale';
        }

        // Asked again about a contact already decided: the answer stands.
        if ($entry['t'] === $tick && $tick > 0) {
            return 'duplicate';
        }

        if ($entry['state'] !== self::LIVE) {
            return $entry['goal'] !== null && $entry['goal'][2] === $tick ? 'duplicate' : 'stale';
        }

        $contact = $this->contact($index);

        if ($contact === null) {
            return 'stale';
        }

        if ($contact['side'] !== $side) {
            return 'not_defender';
        }

        if ($contact['tick'] !== $tick) {
            return $tick < $entry['t'] ? 'duplicate' : 'desync';
        }

        if ($kind !== 'hit' || $paddle === null) {
            $this->miss($index, $contact, 'conceded');

            return 'miss';
        }

        $reason = $this->refusal($side, $paddle, $contact);

        if ($reason !== null) {
            $this->miss($index, $contact, $reason, $paddle);

            return 'miss';
        }

        $hits = $entry['hits'] + 1;
        $speed = PongPhysics::speedAfter($hits, $this->serve->base, $this->serve->speedup, $this->serve->max);
        $this->balls[$index] = ['b' => PongPhysics::bounce($contact['ball'], $side, $paddle, $this->halfOf($side), $speed), 't' => $tick, 'hits' => $hits, 'state' => self::LIVE, 'goal' => null];
        $this->sideHits = $side === 0 ? [$this->sideHits[0] + 1, $this->sideHits[1]] : [$this->sideHits[0], $this->sideHits[1] + 1];
        $this->samples = $side === 0 ? [[...$this->samples[0], [$paddle, $tick]], $this->samples[1]] : [$this->samples[0], [...$this->samples[1], [$paddle, $tick]]];

        if ($tick >= $this->paddles[$side][1]) {
            $this->paddles = $side === 0 ? [[$paddle, $tick], $this->paddles[1]] : [$this->paddles[0], [$paddle, $tick]];
        }
        $this->fresh[] = ['hit', $this->rally, $tick, $side, $index, $paddle, $contact['ball'][1]];
        $this->settle();

        return 'hit';
    }

    /**
     * Why a claimed hit does not count, or null: off the field, out of reach from the accepted positions nearest in
     * time before and after the contact, or not meeting the ball.
     *
     * @param  array{side: int, tick: int, ball: array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}}  $contact
     */
    public function refusal(int $side, int $paddle, array $contact): ?string
    {
        $half = $this->halfOf($side);

        return match (true) {
            $paddle < $half || $paddle > PongPhysics::HEIGHT - $half => 'range',
            ! $this->reachable($side, $paddle, $contact['tick']) => 'speed',
            ! PongPhysics::meets($contact['ball'], $paddle, $half) => 'geometry',
            default => null,
        };
    }

    /**
     * Whether side `$side`'s paddle can be at `$paddle` in tick `$tick`, given the accepted positions nearest in time
     * before and after it (the latest position `paddles` counts as one, so a state from before the samples is judged
     * as it was).
     */
    private function reachable(int $side, int $paddle, int $tick): bool
    {
        $before = null;
        $after = null;

        foreach ([...$this->samples[$side], $this->paddles[$side]] as $sample) {
            if ($sample[1] <= $tick && ($before === null || $sample[1] > $before[1])) {
                $before = $sample;
            }

            if ($sample[1] >= $tick && ($after === null || $sample[1] < $after[1])) {
                $after = $sample;
            }
        }

        foreach ([$before, $after] as $sample) {
            if ($sample !== null && abs($paddle - $sample[0]) > PongPhysics::PLAYER_SPEED * abs($tick - $sample[1])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The contacts nobody reported in time, as misses (the server's clock decides, never the attacker), and a rally
     * that ran into the tick cap. Nothing while paused.
     *
     * @return int how many contacts it decided
     */
    public function due(int $now): int
    {
        if ($this->pausedAt !== null || $this->winner !== null) {
            return 0;
        }

        $decided = 0;

        foreach (array_keys($this->balls) as $index) {
            $contact = $this->contact($index);

            if ($contact !== null && $now > $this->timeOf($contact['tick']) + self::GRACE_MS) {
                $this->miss($index, $contact, 'timeout');
                $decided++;
            }
        }

        return $decided;
    }

    /** Holds the clock (a player is gone). */
    public function pause(int $now): void
    {
        if ($this->pausedAt === null && $this->winner === null) {
            $this->pausedAt = $now;
            $this->version++;
        }
    }

    /** Runs the clock again, from where it stopped. */
    public function resume(int $now): void
    {
        if ($this->pausedAt !== null) {
            $this->servedAt += max(0, $now - $this->pausedAt);
            $this->pausedAt = null;
            $this->version++;
        }
    }

    /**
     * Whether the rally in play has ended (every ball scored, or has no contact left before the tick cap) and the
     * next one was served, `$now` being the moment. The caller asks after every decision.
     */
    public function nextIfOver(int $now): bool
    {
        if ($this->winner !== null) {
            return false;
        }

        foreach (array_keys($this->balls) as $index) {
            if ($this->contact($index) !== null) {
                return false;
            }
        }

        // A ball still flying without a contact before the cap ends with the rally at the cap.
        $ends = [0, ...array_map(fn (array $ball): int => $ball['state'] === self::GOAL ? $ball['goal'][0] : PongPhysics::RALLY_TICK_CAP, $this->balls)];
        $event = $this->rules->eventOf($this->seed, $this->rally + 1);
        $this->scoreBefore = $this->score;
        $pause = self::POINT_TICKS + ($event !== null ? self::ANNOUNCE_TICKS : 0) + self::SERVE_TICKS;
        $this->serveRally($this->rally + 1, max($now, $this->timeOf(max($ends))) + $this->ms($pause));

        return true;
    }

    /**
     * A contact the defender missed (or that did not count): the ball goes on to the goal line, and the attacker
     * scores there, unless the tick cap comes first.
     *
     * @param  array{side: int, tick: int, ball: array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}}  $contact
     */
    private function miss(int $index, array $contact, string $reason, ?int $paddle = null): void
    {
        $ball = $contact['ball'];
        $tick = $contact['tick'];

        while ($ball[0] > 0 && $ball[0] < PongPhysics::WIDTH) {
            $tick++;
            $ball = PongPhysics::step($ball, $tick, $this->event, $this->serve->seed);
        }

        $scorer = 1 - $contact['side'];
        $entry = $this->balls[$index];
        $this->balls[$index] = $tick > PongPhysics::RALLY_TICK_CAP
            ? ['b' => $contact['ball'], 't' => $contact['tick'], 'hits' => $entry['hits'], 'state' => self::VOID, 'goal' => null]
            // The goal: the tick it falls, the scorer, and the contact's tick (a report about it again is a duplicate).
            : ['b' => $contact['ball'], 't' => $contact['tick'], 'hits' => $entry['hits'], 'state' => self::GOAL, 'goal' => [$tick, $scorer, $contact['tick']]];
        $this->fresh[] = ['goal', $this->rally, $contact['tick'], $contact['side'], $index, $paddle, $reason];
        $this->settle();
    }

    /**
     * The score from this rally's goals in the order they fall, and the winner once no ball still in play could
     * score before the winning goal.
     */
    private function settle(): void
    {
        $this->version++;
        $goals = [];

        foreach ($this->balls as $index => $ball) {
            if ($ball['state'] === self::GOAL) {
                $goals[] = [$ball['goal'][0], $index, $ball['goal'][1]];
            }
        }

        sort($goals);
        $score = $this->scoreBefore;
        $winner = null;
        $winningTick = null;

        foreach ($goals as [$tick, , $scorer]) {
            $score = $scorer === 0 ? [$score[0] + $this->serve->points, $score[1]] : [$score[0], $score[1] + $this->serve->points];
            $winner = $this->rules->winner($score);

            if ($winner !== null) {
                $winningTick = $tick;
                break;
            }
        }

        $this->score = $score;

        if ($winner === null) {
            return;
        }

        foreach (array_keys($this->balls) as $index) {
            $contact = $this->contact($index);

            // Another ball reaches a face first: its goal could come before this one.
            if ($contact !== null && $contact['tick'] <= $winningTick) {
                return;
            }
        }

        $this->winner = $winner;
    }

    private function serveRally(int $number, int $servedAt): void
    {
        $this->rally = $number;
        $this->serve = $this->rallyOf($number);
        $this->event = $this->serve->event;
        $this->servedAt = $servedAt;
        $this->paddles = [[PongPhysics::HEIGHT >> 1, 0], [PongPhysics::HEIGHT >> 1, 0]];
        $this->samples = [[$this->paddles[0]], [$this->paddles[1]]];
        $this->sideHits = [0, 0];
        $this->balls = array_map(fn (array $ball): array => ['b' => $ball, 't' => 0, 'hits' => 0, 'state' => self::LIVE, 'goal' => null], $this->serve->balls);
        $this->fresh[] = ['serve', $number, $servedAt, $this->event];
        $this->version++;
    }

    private function rallyOf(int $number): PongRally
    {
        return new PongRally(PongRules::rallySeed($this->seed, $number), $this->rules->eventOf($this->seed, $number), [PongPhysics::PLAYER_SPEED, PongPhysics::PLAYER_SPEED]);
    }
}
