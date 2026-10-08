<?php

namespace App\Support\Chess;

use App\Enums\NotificationKind;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\HyperMatch;

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
 * `dm` has three states: true (on), false (off), and null for a player who
 * never chose, which counts as on. On, a DM goes out only for the kinds
 * NotificationKind::dmAllowed() names (audit 2026-09-30); before, a switched-on
 * DM covered every kind, live ones included. A stored false is never overridden.
 *
 * Triggers (P5c): one switch per NotificationKind. Off means nothing at all
 * for that event: no bell entry, no toast, no push, no DM. A kind added
 * later is on until the player turns it off. A page-only kind
 * (NotificationKind::pageOnly(): a live game calling its player) has no
 * switch and is always on; a stored off from before is ignored.
 *
 * Digest (P45): `digest` names the kinds whose Nostr DM waits for the daily
 * digest (App\Support\Notifications\DmDigest); a kind not named goes out at once.
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
     * @param  array<string, true>  $digest  kinds whose DM waits for the daily digest (P45); missing: at once
     */
    public function __construct(
        public string $board = 'house',
        public bool $coordinates = true,
        public bool $pieceNames = true,
        public bool $alwaysQueen = false,
        public bool $doubleCheck = true,
        public bool $push = true,
        public ?bool $dm = null,
        public int $remindHours = 6,
        public array $triggers = [],
        public bool $sound = true,
        public int $volume = self::DEFAULT_VOLUME,
        public array $digest = [],
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

        // The live cup calls went out as tournament news until they got their own kind: a player
        // who switched tournament news off and never chose the new switch keeps them off.
        if (! is_bool($stored[NotificationKind::CupGameNow->value] ?? null) && ($stored[NotificationKind::TournamentNews->value] ?? null) === false) {
            $triggers[NotificationKind::CupGameNow->value] = false;
        }

        $volume = $values['volume'] ?? null;
        $storedDigest = is_array($values['digest'] ?? null) ? $values['digest'] : [];
        $digest = [];

        foreach (self::triggers() as $trigger) {
            if (($storedDigest[$trigger] ?? null) === true) {
                $digest[$trigger] = true;
            }
        }

        return new self(
            board: in_array($values['board'] ?? null, self::BOARDS, true) ? $values['board'] : $defaults->board,
            coordinates: $bool('coordinates', $defaults->coordinates),
            pieceNames: $bool('pieceNames', $defaults->pieceNames),
            alwaysQueen: $bool('alwaysQueen', $defaults->alwaysQueen),
            doubleCheck: $bool('doubleCheck', $defaults->doubleCheck),
            push: $bool('push', $defaults->push),
            dm: is_bool($values['dm'] ?? null) ? $values['dm'] : null,
            remindHours: in_array($values['remindHours'] ?? null, self::REMIND_HOURS, true) ? $values['remindHours'] : $defaults->remindHours,
            triggers: $triggers,
            sound: $bool('sound', $defaults->sound),
            volume: is_int($volume) && $volume >= 0 && $volume <= 100 ? $volume : $defaults->volume,
            digest: $digest,
        );
    }

    /**
     * @return array{board: string, coordinates: bool, pieceNames: bool, alwaysQueen: bool, doubleCheck: bool, push: bool, dm: bool|null, remindHours: int, triggers: array<string, bool>, sound: bool, volume: int, digest: array<string, true>}
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
            'digest' => $this->digest,
        ];
    }

    /**
     * Whether this trigger goes out by Nostr DM: a kind that may
     * (NotificationKind::dmAllowed()), with the DM switch on.
     */
    public function dmFor(string $trigger): bool
    {
        return (NotificationKind::tryFrom($trigger)?->dmAllowed() ?? false) && $this->dmOn();
    }

    /**
     * Where this kind reaches the player while they are away, by the
     * account settings alone: the Notifier starts from this (a game's own
     * "Tell me when" choice may narrow it), and the pages that describe the
     * channels read it too, so what they say is what goes out.
     *
     * @return list<'push'|'dm'>
     */
    public function remoteChannels(NotificationKind $kind, ChessGame|BoardGame|HyperMatch|null $game = null): array
    {
        if (! $this->wants($kind->value)) {
            return [];
        }

        return array_values(array_filter([
            $this->push && $kind->pushAllowed($game) ? 'push' : null,
            $this->dmFor($kind->value) ? 'dm' : null,
        ]));
    }

    /**
     * The DM switch as the settings page shows it: on unless switched off.
     */
    public function dmOn(): bool
    {
        return $this->dm ?? true;
    }

    /**
     * P45: this kind's DM waits for the daily digest instead of going out at once.
     */
    public function digestFor(string $trigger): bool
    {
        return $this->digest[$trigger] ?? false;
    }

    public function wants(string $trigger): bool
    {
        if (NotificationKind::tryFrom($trigger)?->pageOnly() === true) {
            return true;
        }

        // Unknown to the stored row (a kind added later): on, like the default.
        return $this->triggers[$trigger] ?? in_array($trigger, self::triggers(), true);
    }
}
