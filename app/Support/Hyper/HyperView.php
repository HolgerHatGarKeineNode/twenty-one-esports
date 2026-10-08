<?php

namespace App\Support\Hyper;

/**
 * What one viewer of a Hyperbitcoinization match may see (plan "Hyperbitcoinization", P2): the one filter
 * for snapshots and for events alike, so a secret cannot leak through the path that forgot it.
 *
 * Hidden from everyone but the owning seat: the cards in a hand (others see `hand_count`), the card a seat
 * drew (`card_drawn.card`), and the card Lagarde's prophecy shows (`card_played.effect.next_card`).
 * Hidden from everyone while the match runs: the seed and the dice generator's state (both would tell the
 * next rolls) and the deck order. The seed shows once the match is over, so anyone can replay it; generator
 * state and deck never leave the server.
 *
 * `viewer` is the seat index of the viewer, null for a spectator or guest.
 */
final readonly class HyperView
{
    public function __construct(private ?int $viewer, private bool $over) {}

    /**
     * @param  array<string, mixed>  $state  HyperGame::toArray()
     * @return array<string, mixed>
     */
    public function state(array $state): array
    {
        unset($state['rng'], $state['deck']);
        $state['seed'] = $this->over ? $state['seed'] : null;
        $seats = [];

        foreach ((array) $state['seats'] as $index => $seat) {
            $hand = (array) $seat['hand'];
            $seats[] = [...$seat, 'hand' => $index === $this->viewer ? array_values($hand) : null, 'hand_count' => count($hand)];
        }

        $state['seats'] = $seats;

        return $state;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    public function events(array $events): array
    {
        return array_map($this->event(...), $events);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function event(array $event): array
    {
        $own = ($event['seat'] ?? null) === $this->viewer && $this->viewer !== null;

        return match ($event['type'] ?? null) {
            'game_started' => [...$event, 'seed' => $this->over ? $event['seed'] : null],
            'card_drawn' => [...$event, 'card' => $own ? $event['card'] : null],
            'card_played' => $own || ! is_array($event['effect'] ?? null) || ! array_key_exists('next_card', $event['effect'])
                ? $event
                : [...$event, 'effect' => array_diff_key($event['effect'], ['next_card' => true])],
            default => $event,
        };
    }

    /**
     * The events the seat sees differently from a spectator, in its own view: what the seat's private
     * channel carries next to the table's broadcast.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    public static function secretsOf(int $seat, array $events, bool $over): array
    {
        $own = new self($seat, $over);
        $table = new self(null, $over);

        return array_values(array_filter(array_map(
            fn (array $event): ?array => $own->event($event) === $table->event($event) ? null : $own->event($event),
            $events,
        )));
    }
}
