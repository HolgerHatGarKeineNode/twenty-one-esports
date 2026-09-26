<?php

namespace App\Support\Nostr;

use App\Games\GameMode;
use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\Tournament;
use App\Support\SeasonChain\OpponentLists;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\Series\Ladders;
use App\Support\Series\SeriesEvents;

/**
 * Structural validation rules of `docs/nips/esports.md` ("Validation rules")
 * for the clan kinds, independent of any league state:
 *
 *  2. `alt` present      3. no `expiration`
 *  7. 32150 Clan         8. 32151 Lineup (structure; clan membership of the
 *                           listed players is checked by the caller's template)
 *  9. 12150 Clan Membership (at most one clan, lineups of that clan)
 * 15. 64 Game Record, the structure of a casual game note: one `p` White and
 *     one `p` Black, the author is one of them, `e` references are event
 *     ids, the content is PGN. Legality, headers and the move chain are the
 *     league's own record (App\Support\Chess\GameRecords builds the
 *     template from the server-checked game, and the signed note must equal it).
 *
 * 11-14 2150-2153, the structure of the rated series flow (P6a; built and
 *    tested now, used once a ladder is open). League state (captaincy,
 *    transitions, reserved match number) is checked by SeriesService.
 * 18. 30000 opponent list of this league, a player's own (P7e): `d` is
 *     `esports/<league key>`, content empty, the `p` entries distinct hex
 *     pubkeys other than the author.
 * 29. 1985 with `release-block-0`, an admin's release of Block 0 (P7c).
 *
 * Returns an error code or null. Signature, clock, replay and authorship are
 * checked in {@see SignedEventGate}.
 */
final class EsportsEventRules
{
    public const MEMBERSHIP_KIND = 12150;

    public const GAME_RECORD_KIND = 64;

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
            self::GAME_RECORD_KIND => $this->gameRecord($event),
            SeriesEvents::CHALLENGE => $this->challenge($event),
            SeriesEvents::ANSWER => $this->answer($event),
            SeriesEvents::REPORT => $this->report($event),
            SeriesEvents::RESPONSE => $this->response($event),
            SeasonRelease::LABEL => $this->releaseLabel($event),
            OpponentLists::KIND => $this->opponentList($event),
            NostrLogin::KIND => $this->tournamentConsent($event),
            default => 'kind_not_allowed',
        };
    }

    /**
     * A tournament sign-up or withdrawal (P8b). The NIP keeps registration
     * off the relays ("Registration is deliberately not an event"), so the
     * consent is a NIP-98-style event (27235) the league stores and never
     * publishes: `u` the tournament page, `method` POST, one `a` to the
     * tournament's `31923`, `action` signup or withdraw. Who may enter what is
     * league state (App\Support\Tournaments\TournamentSignups).
     */
    private function tournamentConsent(SignedEvent $event): ?string
    {
        $addresses = $event->tagsNamed('a');

        if (count($event->tagsNamed('u')) !== 1 || $event->tag('method') !== 'POST') {
            return 'consent_request';
        }

        if (count($addresses) !== 1 || ! str_starts_with($addresses[0][0] ?? '', Tournament::CALENDAR_EVENT.':')) {
            return 'consent_tournament';
        }

        return in_array($event->tag('action'), ['signup', 'withdraw'], true) ? null : 'consent_action';
    }

    /**
     * Rule 11, the structure of a lineup challenge: one challenger and one
     * challenged lineup of the ladder's game and mode, one ladder, a `bo` the
     * registry allows, one to three `start` after `created_at`, `respond_by`
     * after `created_at` and within 7 days, a positive `match`.
     * Captaincy, clans, season window and the reserved number are league state
     * ({@see SeriesService}).
     */
    private function challenge(SignedEvent $event): ?string
    {
        $lineups = ['challenger' => [], 'challenged' => []];
        $ladders = [];

        foreach ($event->tagsNamed('a') as $a) {
            $role = $a[2] ?? '';

            if (isset($lineups[$role]) && str_starts_with($a[0] ?? '', Lineup::KIND.':')) {
                $lineups[$role][] = $a[0];
            } elseif ($role === '' && str_starts_with($a[0] ?? '', Ladders::KIND.':')) {
                $ladders[] = $a[0];
            } else {
                return 'challenge_a';
            }
        }

        if (count($lineups['challenger']) !== 1 || count($lineups['challenged']) !== 1 || count($ladders) !== 1) {
            return 'challenge_sides';
        }

        $mode = $this->ladderMode($ladders[0]);

        if ($mode === null) {
            return 'challenge_ladder';
        }

        foreach ($lineups as [$address]) {
            if (! str_ends_with($address, '/'.$mode[0].'/'.$mode[1])) {
                return 'challenge_lineup_mode';
            }
        }

        if (! $mode[2]->allowsBestOf((int) $event->tag('bo')) || (string) (int) $event->tag('bo') !== $event->tag('bo')) {
            return 'challenge_bo';
        }

        $starts = $event->tagsNamed('start');

        if ($starts === [] || count($starts) > 3) {
            return 'challenge_start';
        }

        foreach ($starts as $start) {
            if ((int) ($start[0] ?? 0) <= $event->createdAt) {
                return 'challenge_start';
            }
        }

        $respondBy = (int) $event->tag('respond_by');

        if ($respondBy <= $event->createdAt || $respondBy > $event->createdAt + 7 * 86400) {
            return 'challenge_respond_by';
        }

        return preg_match('/^[1-9][0-9]*$/', (string) $event->tag('match')) === 1 ? null : 'challenge_match';
    }

    /**
     * Rule 29 (rev. 5), a release label: `L` the league namespace, `l`
     * `release-block-0` in it, one `a` to a season announcement (`31923`),
     * one 64-character hex `x`. That the signer is on the admin list and the
     * digest is the genesis' is league state ({@see SeasonRelease}).
     */
    private function releaseLabel(SignedEvent $event): ?string
    {
        $labels = $event->tagsNamed('l');
        $announcements = $event->tagsNamed('a');
        $digests = $event->tagsNamed('x');

        if ($event->tag('L') !== SeasonRelease::NAMESPACE || count($labels) !== 1
            || ($labels[0][0] ?? null) !== SeasonRelease::RELEASE_LABEL || ($labels[0][1] ?? null) !== SeasonRelease::NAMESPACE) {
            return 'label_value';
        }

        if (count($announcements) !== 1 || ! str_starts_with($announcements[0][0] ?? '', SeasonRelease::ANNOUNCEMENT.':')) {
            return 'label_announcement';
        }

        return count($digests) === 1 && preg_match('/^[0-9a-f]{64}$/', $digests[0][0] ?? '') === 1 ? null : 'label_digest';
    }

    /**
     * Rule 18, a player's opponent list as the app writes it. The other
     * `30000` of this NIP (anchor and admin lists) are signed by league keys
     * and never pass here.
     */
    private function opponentList(SignedEvent $event): ?string
    {
        $lists = OpponentLists::forLeague();

        if ($lists === null || $event->tag('d') !== $lists->d()) {
            return 'opponent_list_d';
        }

        if ($event->content !== '') {
            return 'opponent_list_content';
        }

        $entries = array_map(fn (array $tag): ?string => $tag[0] ?? null, $event->tagsNamed('p'));

        foreach ($entries as $entry) {
            if (! NostrKeys::isHexPubkey($entry) || $entry === $event->pubkey) {
                return 'opponent_list_entry';
            }
        }

        return count($entries) === count(array_unique($entries)) ? null : 'opponent_list_entry';
    }

    /**
     * Rule 12, structure: one `e`, a known `status`, exactly one `start` when
     * accepted and none otherwise, three `a` references.
     */
    private function answer(SignedEvent $event): ?string
    {
        $status = $event->tag('status');

        if (count($event->tagsNamed('e')) !== 1 || ! in_array($status, ['accepted', 'declined', 'withdrawn'], true)) {
            return 'answer_status';
        }

        if (count($event->tagsNamed('start')) !== ($status === 'accepted' ? 1 : 0)) {
            return 'answer_start';
        }

        return count($event->tagsNamed('a')) === 3 ? null : 'answer_a';
    }

    /**
     * Rule 13: `score` numbered from 1 and valid for the registry's series
     * rules, both goal values or none, a roster with side and lineup role,
     * each side at least the mode's team size, no pubkey twice.
     */
    private function report(SignedEvent $event): ?string
    {
        $ladder = null;

        foreach ($event->tagsNamed('a') as $a) {
            if (str_starts_with($a[0] ?? '', Ladders::KIND.':')) {
                $ladder = $a[0];
            }
        }

        $mode = $ladder === null ? null : $this->ladderMode($ladder);

        if ($mode === null || count($event->tagsNamed('e')) !== 1 || count($event->tagsNamed('a')) !== 3) {
            return 'report_references';
        }

        $games = [];

        foreach ($event->tagsNamed('score') as $index => $score) {
            if (($score[0] ?? null) !== (string) ($index + 1)) {
                return 'report_score_number';
            }

            $points = [$score[2] ?? '', $score[3] ?? ''];

            if (($points[0] === '') !== ($points[1] === '')) {
                return 'report_score_points';
            }

            foreach ($points as $point) {
                if ($point !== '' && preg_match('/^(0|[1-9][0-9]*)$/', $point) !== 1) {
                    return 'report_score_points';
                }
            }

            $games[] = [
                'winner' => $score[1] ?? null,
                'challenger' => $points[0] === '' ? null : (int) $points[0],
                'challenged' => $points[1] === '' ? null : (int) $points[1],
                'flags' => array_slice($score, 4),
            ];
        }

        // The report does not repeat `bo`: a series valid for any allowed length is structurally fine.
        $valid = false;

        foreach ($mode[2]->bestOf as $bo) {
            $valid = $valid || $this->games->get($mode[0])->validateResult($mode[2], ['bo' => $bo, 'games' => $games]) === [];
        }

        if (! $valid) {
            return 'report_series';
        }

        $sides = ['challenger' => 0, 'challenged' => 0];
        $seen = [];

        foreach ($event->tagsNamed('p') as $p) {
            if (! NostrKeys::isHexPubkey($p[0] ?? null) || isset($seen[$p[0]])) {
                return 'report_p';
            }

            $seen[$p[0]] = true;

            if (! isset($p[2])) {
                continue;
            }

            if (! isset($sides[$p[2]]) || ! in_array($p[3] ?? null, ['captain', 'player', 'substitute'], true)) {
                return 'report_roster';
            }

            $sides[$p[2]]++;
        }

        return min($sides) >= $mode[2]->teamSize ? null : 'report_roster_short';
    }

    /**
     * Rule 14, structure: `e` report and `e` challenge, a known `status`,
     * three `a` references.
     */
    private function response(SignedEvent $event): ?string
    {
        if (count($event->tagsNamed('e')) !== 2 || ! in_array($event->tag('status'), ['confirmed', 'disputed'], true)) {
            return 'response_status';
        }

        return count($event->tagsNamed('a')) === 3 ? null : 'response_a';
    }

    /**
     * Game, mode and registry entry of a ladder address `32152:<pk>:<game>/<mode>/<season>`.
     *
     * @return array{0: string, 1: string, 2: GameMode}|null
     */
    private function ladderMode(string $address): ?array
    {
        $parts = explode(':', $address, 3);
        $d = explode('/', $parts[2] ?? '');

        if (count($parts) !== 3 || ! NostrKeys::isHexPubkey($parts[1]) || count($d) !== 3 || $d[2] === '') {
            return null;
        }

        $mode = $this->games->mode($d[0], $d[1]);

        return $mode === null || $mode->bestOf === [] ? null : [$d[0], $d[1], $mode];
    }

    private function gameRecord(SignedEvent $event): ?string
    {
        $colors = [];

        foreach ($event->tagsNamed('p') as $p) {
            if (! NostrKeys::isHexPubkey($p[0] ?? null) || ! in_array($p[2] ?? null, ['white', 'black'], true) || isset($colors[$p[2]])) {
                return 'record_p';
            }

            $colors[$p[2]] = $p[0];
        }

        if (count($colors) !== 2 || ! in_array($event->pubkey, $colors, true)) {
            return 'record_players';
        }

        foreach ($event->tagsNamed('e') as $e) {
            if (preg_match('/^[0-9a-f]{64}$/', $e[0] ?? '') !== 1) {
                return 'record_e';
            }
        }

        if (! str_starts_with(ltrim($event->content), '[')) {
            return 'record_pgn';
        }

        return null;
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
