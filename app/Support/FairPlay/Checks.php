<?php

namespace App\Support\FairPlay;

use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonParameterChange;
use App\Models\TrustExclusion;
use App\Models\TrustRank;
use App\Models\TrustReportConfirmation;
use App\Models\TrustReportDismissal;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Nostr\NostrKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The admin checking view (/admin/checks): which players of the live season
 * look conspicuous, as short chips with the number that triggered each. It
 * only shows; nothing here bars, voids or ranks anybody, and no player ever
 * sees it. An admin who wants to act goes on to the bar form of
 * /admin/fair-play ({@see Bars}).
 *
 * One row per player who mined or tried to mine in the live season: a winner
 * of a block candidate (`season_attestations.candidate`), mined or refused.
 * The signals, each with a threshold in `esports.fair_play.checks`:
 *
 * 1. `dominant`: the share of the player's blocks won against one opponent;
 *    `pair-limit`: the pairings of the player that reached the season pair
 *    limit (the pairing of consensus rule 8: per game, both directions).
 * 2. `limits`: the player's wins refused for the pair limit (day or season)
 *    or the daily limit.
 * 3. `short-wins`: the player's chess and board blocks whose game row
 *    (`chess_games`, `board_games`) ended by `resignation` or `timeout` with
 *    a `ply` at most `short_win_margin_plies` above twice the minimum move
 *    count in force now.
 * 4. `mutual`: an opponent the player mined blocks from who mined as many
 *    from the player.
 * 5. `forfeits`: rated Hyperbitcoinization matches left since Block 0
 *    ({@see FairPlay::forfeitCounts()}).
 * 6. `thin-trust`: the published trust assertion (`trust_ranks`) names one
 *    other member as the source of the whole trust; `no-trust`: the rank is
 *    below the season's minimum now although the player mined.
 * 7. `new-account`: the account (`users.created_at`) is only days older than
 *    its first block.
 * 8. `reports`: reports against the player that wait for an admin, counted
 *    as the admin nav counts them. Barred, locked and linked are states, not
 *    signals: they never put a player on the list by themselves.
 *
 * Cost: a fixed number of queries for any number of players. The candidates
 * of the season are read once (the newest `read_limit` of them) and walked
 * in one pass; the game rows, trust ranks, accounts, forfeits, reports and
 * bars each come in one query for all players.
 *
 * @phpstan-type Chip array{key: string, label: string}
 * @phpstan-type Opponent array{name: string, won: int, lost: int}
 * @phpstan-type Row array{pubkey: string, name: string, npub: string|null, blocks: int, sats: int, signals: list<Chip>, states: list<Chip>, opponents: list<Opponent>}
 */
final class Checks
{
    /** The refusal reasons (ConsensusRules) of a win that ran into a limit. */
    public const LIMIT_REASONS = ['pairing-daily-limit', 'pairing-season-limit', 'player-daily-limit'];

    /** How a handed-over board game ends: the loser resigns or lets the clock run out. */
    private const SHORT_ENDS = ['resignation', 'timeout'];

    /** The kind of a league report (NIP-56), as the trust job stores it. */
    private const REPORT_KIND = 1984;

    /** The season whose [Block 0, ends) contains now: the only one the view reads. */
    public static function season(): ?Season
    {
        $now = CarbonImmutable::now();

        return Season::query()->where('genesis_at', '<=', $now)->where('ends_at', '>', $now)->latest('genesis_at')->first();
    }

    /**
     * The players of the season with their chips, the most chips first.
     * `rows` holds the conspicuous ones (every player with `$all`), at most
     * `esports.fair_play.checks.rows`; `players` and `flagged` count all of
     * them. `truncated`: the season holds more candidates than were read.
     *
     * @return array{rows: list<Row>, players: int, flagged: int, truncated: bool}
     */
    public function read(Season $season, bool $all = false): array
    {
        $limits = self::thresholds();
        $rows = SeasonAttestation::query()->toBase()->where('season_id', $season->id)->whereNotNull('candidate')
            ->orderByDesc('id')->limit($limits['read_limit'] + 1)
            ->get(['id', 'source', 'source_id', 'height', 'reason', 'subject', 'reward_per_player', 'attested_at', 'candidate']);
        $truncated = $rows->count() > $limits['read_limit'];
        $parameters = $this->parameters($season);

        // One pass, oldest first: what each winner mined, from whom, and what was refused.
        $players = [];
        $beat = [];
        $pairBlocks = [];
        $pairsOf = [];
        $boardWins = ['chess' => [], 'board' => []];

        foreach ($rows->take($limits['read_limit'])->reverse() as $row) {
            $candidate = (array) json_decode((string) $row->candidate, true);
            $winners = self::pubkeys($candidate['winners'] ?? []);
            $losers = self::pubkeys($candidate['losers'] ?? []);
            $mined = $row->height !== null;

            foreach ($winners as $winner) {
                $players[$winner] ??= ['blocks' => 0, 'sats' => 0, 'refused' => 0, 'first' => null, 'board' => []];

                if (! $mined) {
                    // The daily limit names the player who reached it; the pair limits are the whole side's.
                    $own = $row->reason !== 'player-daily-limit' || ! in_array($row->subject, $winners, true) || $row->subject === $winner;
                    $players[$winner]['refused'] += in_array($row->reason, self::LIMIT_REASONS, true) && $own ? 1 : 0;

                    continue;
                }

                $players[$winner]['blocks']++;
                $players[$winner]['sats'] += (int) $row->reward_per_player;
                $players[$winner]['first'] ??= (string) $row->attested_at;

                foreach ($losers as $loser) {
                    $beat[$winner][$loser] = ($beat[$winner][$loser] ?? 0) + 1;
                }

                if (isset($boardWins[$row->source])) {
                    $players[$winner]['board'][] = [(string) $row->source, (int) $row->source_id];
                    $boardWins[$row->source][(int) $row->source_id] = true;
                }
            }

            if ($mined && ! isset($candidate['solo'])) {
                foreach (self::pairings($candidate, $winners, $losers) as $key => $members) {
                    $pairBlocks[$key] = ($pairBlocks[$key] ?? 0) + 1;

                    foreach ($members as $member) {
                        $pairsOf[$member][$key] = true;
                    }
                }
            }
        }

        if ($players === []) {
            return ['rows' => [], 'players' => 0, 'flagged' => 0, 'truncated' => $truncated];
        }

        $pubkeys = array_map(strval(...), array_keys($players));
        $games = $this->games($boardWins);
        /** @var array<string, TrustRank> $ranks */
        $ranks = TrustRank::query()->whereIn('pubkey', $pubkeys)->get(['pubkey', 'rank', 'anchor', 'anchor_share'])->keyBy('pubkey')->all();
        $barred = FairPlay::barred($pubkeys);
        $named = [...$pubkeys, ...array_merge(...array_values(array_map(array_keys(...), $beat)))];

        foreach ($ranks as $rank) {
            $named[] = (string) $rank->anchor;
        }

        foreach ($barred as $bar) {
            $named[] = (string) $bar['main'];
        }

        // Never more of an account than its name needs: no profile field, no payout address.
        /** @var array<string, User> $users */
        $users = User::query()->whereIn('pubkey', array_values(array_unique(array_map(strval(...), $named))))->get(['id', 'pubkey', 'npub', 'name', 'created_at'])->keyBy('pubkey')->all();
        $ids = [];

        foreach ($pubkeys as $pubkey) {
            if (isset($users[$pubkey])) {
                $ids[$users[$pubkey]->id] = $pubkey;
            }
        }

        $forfeits = FairPlay::forfeitCounts(array_keys($ids), CarbonImmutable::instance($season->genesis_at));
        $reports = $this->openReports($season, $pubkeys, $limits['read_limit']);
        $name = fn (string $pubkey): string => isset($users[$pubkey]) ? $users[$pubkey]->displayName() : (NostrKeys::isHexPubkey($pubkey) ? Str::limit(NostrKeys::hexToNpub($pubkey), 16, '…') : '–');
        $shortPly = 2 * $parameters['moves'] + $limits['short_win_margin_plies'];
        $list = [];

        foreach ($players as $pubkey => $player) {
            $pubkey = (string) $pubkey;
            $user = $users[$pubkey] ?? null;
            $won = $beat[$pubkey] ?? [];
            $signals = [];

            // 1. One opponent dominates, and pairings at the season pair limit.
            arsort($won);
            $top = array_key_first($won);

            if ($top !== null && $player['blocks'] >= $limits['dominant_min_blocks'] && $won[$top] * 100 >= $limits['dominant_share'] * $player['blocks']) {
                $signals[] = self::chip('dominant', __(':share % of the blocks against :name (:won of :blocks)', [
                    'share' => intdiv($won[$top] * 100, $player['blocks']), 'name' => $name((string) $top), 'won' => $won[$top], 'blocks' => $player['blocks'],
                ]));
            }

            $atLimit = count(array_filter(array_keys($pairsOf[$pubkey] ?? []), fn (string $key): bool => $pairBlocks[$key] >= $parameters['pair_limit']));

            if ($atLimit >= $limits['pairs_at_limit']) {
                $signals[] = self::chip('pair-limit', __('Pairings at the season limit: :count', ['count' => $atLimit]));
            }

            // 2. Hits the limits.
            if ($player['refused'] >= $limits['limit_refusals']) {
                $signals[] = self::chip('limits', __('Wins refused at a limit: :count', ['count' => $player['refused']]));
            }

            // 3. Short wins.
            $short = count(array_filter($player['board'], function (array $game) use ($games, $shortPly): bool {
                $row = $games[$game[0]][$game[1]] ?? null;

                return $row !== null && in_array($row['end'], self::SHORT_ENDS, true) && $row['ply'] <= $shortPly;
            }));

            if ($short >= $limits['short_win_min'] && $short * 100 >= $limits['short_win_share'] * count($player['board'])) {
                $signals[] = self::chip('short-wins', __('Short wins: :short of :wins', ['short' => $short, 'wins' => count($player['board'])]));
            }

            // 4. Mutual wins, one chip per partner.
            foreach ($won as $opponent => $blocks) {
                $back = $beat[$opponent][$pubkey] ?? 0;

                if ($blocks >= $limits['mutual_min_each'] && $back >= $limits['mutual_min_each']) {
                    $signals[] = self::chip('mutual', __('Mutual wins with :name: :won to :lost', ['name' => $name((string) $opponent), 'won' => $blocks, 'lost' => $back]));
                }
            }

            // 5. Left matches.
            $left = $user === null ? 0 : ($forfeits[$user->id] ?? 0);

            if ($left >= $limits['forfeits']) {
                $signals[] = self::chip('forfeits', __('Rated matches left: :count', ['count' => $left]));
            }

            // 6. Thin trust.
            $rank = $ranks[$pubkey] ?? null;

            if ($player['blocks'] > 0 && ($rank->rank ?? 0) < $season->minimum_trust) {
                $signals[] = self::chip('no-trust', __('Trust below the minimum now: :rank', ['rank' => $rank->rank ?? 0]));
            } elseif ($rank !== null && $rank->rank >= $season->minimum_trust && $rank->anchor !== null && $rank->anchor !== $pubkey && (int) $rank->anchor_share >= $limits['thin_trust_share']) {
                $signals[] = self::chip('thin-trust', __('Trust through :name alone: :share %', ['name' => $name($rank->anchor), 'share' => (int) $rank->anchor_share]));
            }

            // 7. New account.
            if ($user?->created_at !== null && $player['first'] !== null) {
                $age = CarbonImmutable::parse($player['first'])->getTimestamp() - $user->created_at->getTimestamp();

                if ($age <= $limits['new_account_days'] * 86400) {
                    $signals[] = self::chip('new-account', __('New account: :days days before its first block', ['days' => max(0, intdiv($age, 86400))]));
                }
            }

            // 8. Reported.
            $reported = (int) ($reports[$pubkey] ?? 0);

            if ($reported >= $limits['reports']) {
                $signals[] = self::chip('reports', __('Open reports: :count', ['count' => $reported]));
            }

            $opponents = [];

            foreach (array_unique([...array_keys($won), ...array_keys(array_filter($beat, fn (array $from): bool => isset($from[$pubkey])))]) as $opponent) {
                $opponents[] = ['name' => $name((string) $opponent), 'won' => $won[$opponent] ?? 0, 'lost' => $beat[$opponent][$pubkey] ?? 0];
            }

            usort($opponents, fn (array $a, array $b): int => [$b['won'] + $b['lost'], $a['name']] <=> [$a['won'] + $a['lost'], $b['name']]);

            $list[] = [
                'pubkey' => $pubkey,
                'name' => $name($pubkey),
                'npub' => $user?->npub,
                'blocks' => $player['blocks'],
                'sats' => $player['sats'],
                'signals' => $signals,
                'states' => self::states($barred[$pubkey] ?? null, $name),
                'opponents' => $opponents,
            ];
        }

        usort($list, fn (array $a, array $b): int => [count($b['signals']), $b['blocks'], $a['name']] <=> [count($a['signals']), $a['blocks'], $b['name']]);
        $flagged = count(array_filter($list, fn (array $row): bool => $row['signals'] !== []));

        return [
            'rows' => array_slice($all ? $list : array_slice($list, 0, $flagged), 0, $limits['rows']),
            'players' => count($list),
            'flagged' => $flagged,
            'truncated' => $truncated,
        ];
    }

    /**
     * The thresholds (`esports.fair_play.checks`), none below 1.
     *
     * @return array{dominant_share: int, dominant_min_blocks: int, pairs_at_limit: int, limit_refusals: int, short_win_margin_plies: int, short_win_min: int, short_win_share: int, mutual_min_each: int, forfeits: int, thin_trust_share: int, new_account_days: int, reports: int, rows: int, read_limit: int}
     */
    public static function thresholds(): array
    {
        $limits = [];

        foreach (['dominant_share', 'dominant_min_blocks', 'pairs_at_limit', 'limit_refusals', 'short_win_min', 'short_win_share', 'mutual_min_each', 'forfeits', 'thin_trust_share', 'reports', 'rows', 'read_limit'] as $key) {
            $limits[$key] = max(1, (int) config('esports.fair_play.checks.'.$key));
        }

        foreach (['short_win_margin_plies', 'new_account_days'] as $key) {
            $limits[$key] = max(0, (int) config('esports.fair_play.checks.'.$key));
        }

        /** @var array{dominant_share: int, dominant_min_blocks: int, pairs_at_limit: int, limit_refusals: int, short_win_margin_plies: int, short_win_min: int, short_win_share: int, mutual_min_each: int, forfeits: int, thin_trust_share: int, new_account_days: int, reports: int, rows: int, read_limit: int} $limits */
        return $limits;
    }

    /**
     * The season pair limit and the minimum move count in force now: the
     * genesis and every logged change that took effect, read from the rows.
     *
     * @return array{pair_limit: int, moves: int}
     */
    private function parameters(Season $season): array
    {
        $pairLimit = (int) ($season->parameters['pairlimit'][1] ?? 3);
        $moves = (int) ($season->parameters['moves'] ?? 20);

        foreach (SeasonParameterChange::query()->where('season_id', $season->id)->where('effective_at', '<=', now())
            ->orderBy('effective_at')->orderBy('signed_at')->orderBy('id')->get(['parameters']) as $change) {
            $pairLimit = (int) ($change->parameters['pairlimit'][1] ?? $pairLimit);
            $moves = (int) ($change->parameters['moves'] ?? $moves);
        }

        return ['pair_limit' => max(1, $pairLimit), 'moves' => max(0, $moves)];
    }

    /**
     * The reports against these players that wait for an admin: signed since
     * Block 0 of the season, neither confirmed nor dismissed, their author not
     * excluded (the count of the admin nav's Trust item, per player). The
     * target is the report's first `p` tag, which only its JSON holds: the
     * waiting reports are read once, the newest `read_limit` of them.
     *
     * @param  list<string>  $pubkeys
     * @return array<string, int> pubkey => reports; players without one are absent
     */
    private function openReports(Season $season, array $pubkeys, int $limit): array
    {
        $wanted = array_fill_keys($pubkeys, true);
        $reports = [];
        $waiting = NostrEvent::query()->toBase()->where('kind', self::REPORT_KIND)->where('signed_at', '>=', $season->genesis_at->getTimestamp())
            ->whereNotIn('pubkey', TrustExclusion::query()->select('pubkey'))
            ->whereNotIn('event_id', TrustReportConfirmation::query()->select('event_id'))
            ->whereNotIn('event_id', TrustReportDismissal::query()->select('event_id'))
            ->orderByDesc('id')->limit($limit)->pluck('raw');

        foreach ($waiting as $raw) {
            $event = json_decode((string) $raw, true);

            foreach (is_array($event) && is_array($event['tags'] ?? null) ? $event['tags'] : [] as $tag) {
                if (is_array($tag) && ($tag[0] ?? null) === 'p') {
                    $target = $tag[1] ?? null;

                    if (is_string($target) && isset($wanted[$target])) {
                        $reports[$target] = ($reports[$target] ?? 0) + 1;
                    }

                    break;
                }
            }
        }

        return $reports;
    }

    /**
     * How the mined chess and board games ended, from their rows.
     *
     * @param  array{chess: array<int, true>, board: array<int, true>}  $ids
     * @return array<string, array<int, array{ply: int, end: string}>> source => game id => its end
     */
    private function games(array $ids): array
    {
        $games = ['chess' => [], 'board' => []];

        foreach (['chess' => ChessGame::query()->toBase(), 'board' => BoardGame::query()->toBase()] as $source => $query) {
            foreach ($ids[$source] === [] ? [] : $query->whereIn('id', array_keys($ids[$source]))->get(['id', 'ply', 'end_reason']) as $row) {
                $games[$source][(int) $row->id] = ['ply' => (int) $row->ply, 'end' => (string) $row->end_reason];
            }
        }

        return $games;
    }

    /**
     * The pairings a mined candidate counts in (consensus rules 4 and 8) and
     * who belongs to each: the two rated entities per game, or at a table
     * the winner with each loser.
     *
     * @param  array<mixed>  $candidate
     * @param  list<string>  $winners
     * @param  list<string>  $losers
     * @return array<string, list<string>> pairing key => its players
     */
    private static function pairings(array $candidate, array $winners, array $losers): array
    {
        $game = is_string($candidate['game'] ?? null) ? $candidate['game'] : '';

        if (! isset($candidate['table'])) {
            $entities = array_map(strval(...), array_values((array) ($candidate['pairing'] ?? [])));
            sort($entities);

            return [$game.'|'.implode('|', $entities) => [...$winners, ...$losers]];
        }

        $pairings = [];

        foreach ($losers as $loser) {
            $pair = [$winners[0] ?? '', $loser];
            sort($pair);
            $pairings[$game.'|'.implode('|', $pair)] = [$winners[0] ?? '', $loser];
        }

        return $pairings;
    }

    /**
     * Barred, locked or linked now: what the admin should know before
     * deciding, never a reason to list the player.
     *
     * @param  array{kind: string, until: CarbonImmutable|null, main: string|null}|null  $bar
     * @param  \Closure(string): string  $name
     * @return list<Chip>
     */
    private static function states(?array $bar, \Closure $name): array
    {
        if ($bar === null) {
            return [];
        }

        $until = $bar['until'] === null ? '' : LeagueTime::stamp($bar['until']);

        return [match ($bar['kind']) {
            'linked' => self::chip('linked', __('Linked to :name', ['name' => $name((string) $bar['main'])])),
            'barred' => self::chip('barred', __('Barred until :time', ['time' => $until])),
            default => self::chip('locked', __('Locked until :time', ['time' => $until])),
        }];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private static function pubkeys($value): array
    {
        return array_values(array_unique(array_filter((array) $value, fn (mixed $pubkey): bool => is_string($pubkey) && $pubkey !== '')));
    }

    /** @return Chip */
    private static function chip(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label];
    }
}
