<?php

namespace App\Support\Chess;

use App\Enums\NotificationKind;

/**
 * A player's chess and notification preferences (ChessSettings.dc.html),
 * stored as JSON on the user. Unknown or malformed values fall back to the
 * defaults, so an old or hand-edited row never breaks a page.
 *
 * Notifications: `push` and `dm` are the two channels (browser push, NIP-17
 * DM from the league's notification key); `triggers` switches each event on
 * or off; `remindHours` is how long before a daily-move deadline the reminder
 * goes out.
 *
 * Triggers (P5c): one switch per NotificationKind. Off means nothing at all
 * for that event: no bell entry, no toast, no push, no DM. A kind added
 * later is on until the player turns it off.
 *
 * Sounds (P5c): `sound` on or off and `volume` in percent, played by the
 * page (resources/js/sounds.js); on at a moderate volume by default.
 */
final readonly class ChessSettings
{
    public const BOARDS = ['house', 'wood', 'slate', 'orange'];

    public const REMIND_HOURS = [2, 6, 12];

    public const DEFAULT_VOLUME = 60;

    /**
     * @param  array<string, bool>  $triggers  missing kinds count as on
     */
    public function __construct(
        public string $board = 'house',
        public bool $coordinates = true,
        public bool $pieceNames = true,
        public bool $alwaysQueen = false,
        public bool $doubleCheck = true,
        public bool $push = true,
        public bool $dm = false,
        public int $remindHours = 6,
        public array $triggers = [],
        public bool $sound = true,
        public int $volume = self::DEFAULT_VOLUME,
    ) {}

    /**
     * @return list<string>
     */
    public static function triggers(): array
    {
        return NotificationKind::values();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $defaults = new self;
        $bool = fn (string $key, bool $default): bool => is_bool($values[$key] ?? null) ? $values[$key] : $default;

        $triggers = [];
        $stored = is_array($values['triggers'] ?? null) ? $values['triggers'] : [];

        foreach (self::triggers() as $trigger) {
            $triggers[$trigger] = is_bool($stored[$trigger] ?? null) ? $stored[$trigger] : true;
        }

        $volume = $values['volume'] ?? null;

        return new self(
            board: in_array($values['board'] ?? null, self::BOARDS, true) ? $values['board'] : $defaults->board,
            coordinates: $bool('coordinates', $defaults->coordinates),
            pieceNames: $bool('pieceNames', $defaults->pieceNames),
            alwaysQueen: $bool('alwaysQueen', $defaults->alwaysQueen),
            doubleCheck: $bool('doubleCheck', $defaults->doubleCheck),
            push: $bool('push', $defaults->push),
            dm: $bool('dm', $defaults->dm),
            remindHours: in_array($values['remindHours'] ?? null, self::REMIND_HOURS, true) ? $values['remindHours'] : $defaults->remindHours,
            triggers: $triggers,
            sound: $bool('sound', $defaults->sound),
            volume: is_int($volume) && $volume >= 0 && $volume <= 100 ? $volume : $defaults->volume,
        );
    }

    /**
     * @return array{board: string, coordinates: bool, pieceNames: bool, alwaysQueen: bool, doubleCheck: bool, push: bool, dm: bool, remindHours: int, triggers: array<string, bool>, sound: bool, volume: int}
     */
    public function toArray(): array
    {
        return [
            'board' => $this->board,
            'coordinates' => $this->coordinates,
            'pieceNames' => $this->pieceNames,
            'alwaysQueen' => $this->alwaysQueen,
            'doubleCheck' => $this->doubleCheck,
            'push' => $this->push,
            'dm' => $this->dm,
            'remindHours' => $this->remindHours,
            'triggers' => $this->triggers,
            'sound' => $this->sound,
            'volume' => $this->volume,
        ];
    }

    public function wants(string $trigger): bool
    {
        // Unknown to the stored row (a kind added later): on, like the default.
        return $this->triggers[$trigger] ?? in_array($trigger, self::triggers(), true);
    }
}
