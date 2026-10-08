<?php

namespace App\Support\Hyper;

use App\Models\HyperAction;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use Closure;
use RuntimeException;

/**
 * A finished Hyperbitcoinization match played again (plan "Hyperbitcoinization", P3): from its seed and its
 * action log (hyper_actions) through the rules core, never from a stored state. Every ply's events come
 * out of HyperGame again, and the state at any ply is computed on demand, so the replay page and the stored
 * match must agree (tests/Feature/Hyper/HyperReplayTest.php: the replay of a played match ends on the stored
 * state, byte for byte).
 *
 * The seats start as they began: a seat is a bot from the start when it is one now and no player's seat was
 * taken over (`takeover`). A takeover changes no rule, so the replay sets those seats to bots at the end.
 * A server `round_limit` action sets the limit at the same ply as in the match (HyperMatches::capBotsOnly).
 *
 * The replay hides nothing: every hand, every drawn card and the seed are the viewer's, as at a table after
 * the match.
 */
final class HyperReplay
{
    /** @var list<array{ply: int, seat: int, source: string, events: list<array<string, mixed>>}> */
    private array $plies = [];

    private HyperGame $game;

    private int $ply = 0;

    /**
     * Replays the match up to `upTo` (all of it by default). `observe` sees every ply as it is replayed: the
     * game before it, the game after it and the ply with its events (the setup as ply 0, with no game before);
     * HyperStats reads the match's course this way instead of replaying it a second time.
     *
     * @param  (Closure(?HyperGame, HyperGame, array{ply: int, seat: int, source: string, events: list<array<string, mixed>>}): void)|null  $observe
     *
     * @throws RuntimeException when the log does not replay (a match whose state was written by hand)
     */
    public function __construct(private HyperMatch $match, ?int $upTo = null, ?Closure $observe = null)
    {
        $match->loadMissing('seats');
        $specs = array_values($match->seats->map(fn (HyperSeat $seat): array => ['faction' => $seat->faction, 'bot' => $seat->bot && $seat->takeover === null])->all());
        $step = HyperGame::start($specs, $match->round_limit, $match->seed);
        $this->game = $step->game;
        $this->plies[] = ['ply' => 0, 'seat' => $step->game->currentSeat(), 'source' => HyperAction::SERVER, 'events' => $step->events];

        if ($observe !== null) {
            $observe(null, $this->game, $this->plies[0]);
        }

        foreach ($match->actions()->where('ply', '>', 0)->when($upTo !== null, fn ($query) => $query->where('ply', '<=', $upTo))->get() as $action) {
            if ($action->ply !== $this->ply + 1) {
                throw new RuntimeException("Ply {$action->ply} follows ply {$this->ply}.");
            }

            $events = [];
            $before = $this->game;

            if (($action->action['type'] ?? null) === 'round_limit') {
                $this->game = HyperGame::fromArray([...$this->game->toArray(), 'limit' => (int) $action->action['round']]);
            } else {
                try {
                    $taken = $this->game->apply($action->seat, $action->action);
                } catch (HyperRuleViolation $violation) {
                    throw new RuntimeException("Ply {$action->ply} does not replay: {$violation->reason}.", 0, $violation);
                }

                $this->game = $taken->game;
                $events = $taken->events;
            }

            $this->ply = $action->ply;
            $this->plies[] = ['ply' => $action->ply, 'seat' => $action->seat, 'source' => $action->source, 'events' => $events];

            if ($observe !== null) {
                $observe($before, $this->game, $this->plies[count($this->plies) - 1]);
            }
        }

        if ($upTo === null || $upTo >= $match->ply) {
            foreach ($match->seats as $seat) {
                if ($seat->takeover !== null) {
                    $this->game = $this->game->withBot($seat->seat);
                }
            }
        }
    }

    public function game(): HyperGame
    {
        return $this->game;
    }

    public function ply(): int
    {
        return $this->ply;
    }

    /**
     * Every ply after the setup with its events, in order.
     *
     * @return list<array{ply: int, seat: int, source: string, events: list<array<string, mixed>>}>
     */
    public function plies(): array
    {
        return array_slice($this->plies, 1);
    }

    /**
     * The match page's snapshot at the replayed ply: the stored match's seats (names, avatars) and the
     * replayed state with every hand open; no clock, no legal actions, nobody's seat.
     *
     * @param  array<string, mixed>  $base  HyperMatches::snapshot() of the match for a guest
     * @return array<string, mixed>
     */
    public function snapshot(array $base): array
    {
        $state = $this->game->toArray();
        unset($state['rng'], $state['deck']);
        $state['seats'] = array_map(fn (array $seat): array => [...$seat, 'hand' => array_values($seat['hand']), 'hand_count' => count($seat['hand'])], $state['seats']);
        $over = $this->game->isOver();

        return [
            ...$base,
            'replay' => true,
            'status' => $over ? 'finished' : 'active',
            'ply' => $this->ply,
            'round' => $this->game->round(),
            'seat' => $over ? null : $this->game->currentSeat(),
            'phase' => $this->game->phase(),
            'turn_started_ms' => null,
            'deadline_ms' => null,
            'winner' => $this->game->winner(),
            'end_reason' => $over ? $base['end_reason'] : null,
            'me' => null,
            'legal' => null,
            'state' => $state,
            'seats' => array_map(fn (array $seat): array => [...$seat, 'connected' => null, 'place' => $over ? $seat['place'] : null, 'loot' => $over ? $seat['loot'] : 0], $base['seats']),
        ];
    }
}
