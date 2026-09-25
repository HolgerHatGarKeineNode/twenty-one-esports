<?php

namespace App\Support\Chess;

/**
 * A player's chess and notification preferences (ChessSettings.dc.html),
 * stored as JSON on the user. Unknown or malformed values fall back to the
 * defaults, so an old or hand-edited row never breaks a page.
 *
 * Notifications: `push` and `dm` are the two channels (browser push, NIP-17
 * DM from the league's notification key); `triggers` switches each event on
 * or off; `remindHours` is how long before a daily-move deadline the reminder
 * goes out.
 */
final readonly class ChessSettings
{
    public const BOARDS = ['house', 'wood', 'slate', 'orange'];

    public const REMIND_HOURS = [2, 6, 12];

    public const TRIGGERS = ['your_move', 'reminder', 'challenge', 'game_over'];

    /**
     * @param  array<string, bool>  $triggers
     */
    public function __construct(
        public string $board = 'house',
        public bool $coordinates = true,
        public bool $alwaysQueen = false,
        public bool $doubleCheck = true,
        public bool $push = true,
        public bool $dm = false,
        public int $remindHours = 6,
        public array $triggers = ['your_move' => true, 'reminder' => true, 'challenge' => true, 'game_over' => true],
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $defaults = new self;
        $bool = fn (string $key, bool $default): bool => is_bool($values[$key] ?? null) ? $values[$key] : $default;

        $triggers = $defaults->triggers;
        $stored = is_array($values['triggers'] ?? null) ? $values['triggers'] : [];

        foreach (self::TRIGGERS as $trigger) {
            if (is_bool($stored[$trigger] ?? null)) {
                $triggers[$trigger] = $stored[$trigger];
            }
        }

        return new self(
            board: in_array($values['board'] ?? null, self::BOARDS, true) ? $values['board'] : $defaults->board,
            coordinates: $bool('coordinates', $defaults->coordinates),
            alwaysQueen: $bool('alwaysQueen', $defaults->alwaysQueen),
            doubleCheck: $bool('doubleCheck', $defaults->doubleCheck),
            push: $bool('push', $defaults->push),
            dm: $bool('dm', $defaults->dm),
            remindHours: in_array($values['remindHours'] ?? null, self::REMIND_HOURS, true) ? $values['remindHours'] : $defaults->remindHours,
            triggers: $triggers,
        );
    }

    /**
     * @return array{board: string, coordinates: bool, alwaysQueen: bool, doubleCheck: bool, push: bool, dm: bool, remindHours: int, triggers: array<string, bool>}
     */
    public function toArray(): array
    {
        return [
            'board' => $this->board,
            'coordinates' => $this->coordinates,
            'alwaysQueen' => $this->alwaysQueen,
            'doubleCheck' => $this->doubleCheck,
            'push' => $this->push,
            'dm' => $this->dm,
            'remindHours' => $this->remindHours,
            'triggers' => $this->triggers,
        ];
    }

    public function wants(string $trigger): bool
    {
        return $this->triggers[$trigger] ?? false;
    }
}
