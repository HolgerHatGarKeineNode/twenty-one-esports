<?php

namespace App\Support\Notifications;

/**
 * Where one DM to one player goes (NIP "Notifications", rev. 9.7): what the
 * lookup of the player's lists found ({@see DmRelays}).
 *
 * `known` is true only when EVERY lookup relay answered to EOSE: a relay
 * that did not answer may hold the player's newest list, so only a complete
 * answer counts as "has no DM relay list" (P45 audit F3). `dmRelays` may be
 * filled while `known` is false: a list some relay returned is used. `dmRelays` are the relays of the newest `10050`,
 * `inboxRelays` the read relays of the newest NIP-65 list (`10002`).
 * `namesDmRelays`: the newest `10050` names at least one relay, usable by the
 * server or not (a host without `wss://`): such a player reads NIP-17 and
 * never gets a kind 4 (user, 2026-10-04: "nur verwenden, wenn das Profil
 * dafür nicht ausgelegt ist (weil INBOX-Relay fehlt)").
 */
final readonly class DmRoute
{
    public const NIP17 = 'nip17';

    public const NIP04 = 'nip04';

    /**
     * @param  list<string>  $dmRelays
     * @param  list<string>  $inboxRelays
     */
    public function __construct(
        public bool $known,
        public array $dmRelays = [],
        public array $inboxRelays = [],
        public bool $namesDmRelays = false,
    ) {}

    public static function unknown(): self
    {
        return new self(false);
    }

    /**
     * NIP-17 unless every lookup relay answered and none has a `10050` naming
     * a relay: then NIP-04, the only DM a client without NIP-17 reads (P45,
     * the user's decisions of 2026-09-28 and 2026-10-04). An unanswered
     * lookup stays NIP-17, the format that shows the least to the relays.
     *
     * @return 'nip17'|'nip04'
     */
    public function format(): string
    {
        return $this->known && $this->dmRelays === [] && ! $this->namesDmRelays ? self::NIP04 : self::NIP17;
    }

    /**
     * The relays the player named for this format: the DM relays for NIP-17,
     * the NIP-65 inbox for NIP-04.
     *
     * @return list<string>
     */
    public function playerRelays(): array
    {
        return $this->format() === self::NIP17 ? $this->dmRelays : $this->inboxRelays;
    }
}
