<?php

namespace App\Support\Nostr;

use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\Lineup;

/**
 * Structural validation rules of `docs/nips/esports.md` ("Validation rules")
 * for the clan kinds, independent of any league state:
 *
 *  2. `alt` present      3. no `expiration`
 *  7. 32150 Clan         8. 32151 Lineup (structure; clan membership of the
 *                           listed players is checked by the caller's template)
 *  9. 12150 Clan Membership (at most one clan, lineups of that clan)
 *
 * Returns an error code or null. Signature, clock, replay and authorship are
 * checked in {@see SignedEventGate}.
 */
final class EsportsEventRules
{
    public const MEMBERSHIP_KIND = 12150;

    public function __construct(private GameRegistry $games) {}

    public function check(SignedEvent $event): ?string
    {
        if ($event->tag('alt') === null) {
            return 'alt_missing';
        }

        if ($event->tagsNamed('expiration') !== []) {
            return 'expiration_present';
        }

        return match ($event->kind) {
            Clan::KIND => $this->clan($event),
            Lineup::KIND => $this->lineup($event),
            self::MEMBERSHIP_KIND => $this->membership($event),
            default => 'kind_not_allowed',
        };
    }

    private function clan(SignedEvent $event): ?string
    {
        if (preg_match(Clan::SLUG_PATTERN, (string) $event->tag('d')) !== 1) {
            return 'clan_d';
        }

        $name = $event->tag('name');

        if ($name === null || trim($name) === '' || mb_strlen($name) > 64) {
            return 'clan_name';
        }

        $clantag = $event->tag('clantag');

        if ($clantag !== null && preg_match(Clan::TAG_PATTERN, $clantag) !== 1) {
            return 'clan_tag';
        }

        $listed = [];

        foreach ($event->tagsNamed('p') as $p) {
            if (! NostrKeys::isHexPubkey($p[0] ?? null) || ! in_array($p[2] ?? null, ['captain', 'member'], true)) {
                return 'clan_p';
            }

            if (isset($listed[$p[0]])) {
                return 'clan_p_twice';
            }

            $listed[$p[0]] = $p[2];
        }

        // The owner is always a captain (NIP "Clan").
        if (($listed[$event->pubkey] ?? 'captain') !== 'captain') {
            return 'clan_owner_not_captain';
        }

        return null;
    }

    private function lineup(SignedEvent $event): ?string
    {
        $parts = explode('/', (string) $event->tag('d'));

        if (count($parts) !== 3) {
            return 'lineup_d';
        }

        [$clan, $game, $mode] = $parts;
        $gameMode = $this->games->mode($game, $mode);

        if ($gameMode === null || $event->tag('game') !== $game || $event->tag('mode') !== $mode) {
            return 'lineup_game_mode';
        }

        $clans = $event->tagsNamed('a');

        if (count($clans) !== 1 || $clans[0][0] !== Clan::KIND.':'.$event->pubkey.':'.$clan) {
            return 'lineup_clan';
        }

        if ($event->content !== '') {
            return 'lineup_content';
        }

        $counted = 0;
        $listed = [];

        foreach ($event->tagsNamed('p') as $p) {
            if (! NostrKeys::isHexPubkey($p[0] ?? null) || ! in_array($p[2] ?? null, ['captain', 'player', 'substitute'], true) || isset($listed[$p[0]])) {
                return 'lineup_p';
            }

            $listed[$p[0]] = true;
            $counted += $p[2] === 'substitute' ? 0 : 1;
        }

        return $counted >= $gameMode->lineupMinimum() ? null : 'lineup_too_small';
    }

    private function membership(SignedEvent $event): ?string
    {
        if ($event->content !== '') {
            return 'membership_content';
        }

        $clans = [];
        $lineups = [];

        foreach ($event->tagsNamed('a') as $a) {
            $address = explode(':', $a[0] ?? '', 3);

            match ((int) $address[0]) {
                Clan::KIND => $clans[] = $address,
                Lineup::KIND => $lineups[] = $address,
                default => $clans[] = ['invalid'],
            };
        }

        if (count($clans) > 1) {
            return 'membership_two_clans';
        }

        if ($clans === []) {
            return $lineups === [] ? null : 'membership_lineup_without_clan';
        }

        $clan = $clans[0];

        if (count($clan) !== 3 || ! NostrKeys::isHexPubkey($clan[1])) {
            return 'membership_clan_address';
        }

        foreach ($lineups as $lineup) {
            if (count($lineup) !== 3 || $lineup[1] !== $clan[1] || ! str_starts_with($lineup[2], $clan[2].'/')) {
                return 'membership_foreign_lineup';
            }
        }

        return null;
    }
}
