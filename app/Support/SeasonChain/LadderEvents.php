<?php

namespace App\Support\SeasonChain;

use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\Season;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\Rating\RankTiers;
use App\Support\Rating\RatingSettings;
use App\Support\Rating\SoftReset;
use App\Support\Series\Ladders;

/**
 * The ladders of a chain season (NIP "Ladder (`32152`)", "League-wide
 * seasons"): one addressable event per rated game and mode, `d` =
 * `<game>/<mode>/<season>`, signed by the league key.
 *
 * Published when Block 0 is released (the first version, with no
 * standings, so the ladder address the challenges point at exists from the
 * start) and again after every parameter change of the season, with the
 * standings so far and an `e` to the last attestation of the ladder.
 *
 * The season parameters are frozen: every later version copies the frozen
 * tags of the first one verbatim (NIP: game, mode, season, starts, rates,
 * time_control, variant, rating, tier, provisional, hashrate, override,
 * reset, seed), so a config edit during the season cannot change them.
 * `trust` is present from the first version on (its presence is frozen) and
 * names the trust key and the season's minimum.
 *
 * A season that continues another (P38, NIP "Season transition") carries
 * `reset` (the ladder of the season before, its last attestation, the
 * factor) and one `seed` per carried-over entity (SoftReset) in the first
 * version of each ladder that had a rated result; every seeded entity is
 * also a plain `a` (lineup) or `p` (player) tag in every version. Before
 * that, the release closes the ladders of the season before with a last
 * version that carries `ends` (close()).
 */
final class LadderEvents
{
    /** Tags every version copies from the first one. */
    private const FROZEN = ['game', 'mode', 'season', 'starts', 'rates', 'time_control', 'variant', 'rating', 'tier', 'provisional', 'hashrate', 'override', 'reset', 'seed'];

    public function __construct(private GameRegistry $games) {}

    /**
     * Sign and store a version of every ladder of the season. Call inside the
     * transaction that writes what the ladders reflect.
     *
     * @return list<NostrEvent>
     */
    public function publish(Season $season, LeagueKey $league, string $trustKey, string $content = ''): array
    {
        $events = [];

        foreach ($this->games->all() as $game) {
            // Board games get no ladder until they are rated (plan "Mühle und Dame", P5) and mine nothing until P6.
            if ($game->kind() === GameKind::Board) {
                continue;
            }

            foreach ($game->modes() as $mode) {
                $events[] = $this->publishOne($season, $league, $trustKey, $game->slug(), $mode->slug, $content);
            }
        }

        return $events;
    }

    /**
     * The last version of every ladder of an ended season, with `ends` (NIP
     * "Season transition", step 2): the ladder a `reset` names must carry it.
     * A ladder that never opened, or that carries `ends` already, is left as
     * it is.
     *
     * @return list<NostrEvent>
     */
    public function close(Season $season, LeagueKey $league, string $trustKey, string $content = ''): array
    {
        $events = [];

        foreach ($this->games->all() as $game) {
            foreach ($game->modes() as $mode) {
                $latest = NostrEvent::query()->where(['kind' => Ladders::KIND, 'pubkey' => $league->pubkey(), 'd' => $game->slug().'/'.$mode->slug.'/'.$season->slug])
                    ->orderByDesc('signed_at')->orderByDesc('id')->first();

                if ($latest === null || SignedEvent::fromInput($latest->payload())?->tag('ends') !== null) {
                    continue;
                }

                $events[] = $this->publishOne($season, $league, $trustKey, $game->slug(), $mode->slug, $content, $season->ends_at->getTimestamp());
            }
        }

        return $events;
    }

    private function publishOne(Season $season, LeagueKey $league, string $trustKey, string $game, string $mode, string $content, ?int $ends = null): NostrEvent
    {
        $d = $game.'/'.$mode.'/'.$season->slug;
        $first = NostrEvent::query()->where(['kind' => Ladders::KIND, 'pubkey' => $league->pubkey(), 'd' => $d])->orderBy('signed_at')->orderBy('id')->first();
        $latest = NostrEvent::query()->where(['kind' => Ladders::KIND, 'pubkey' => $league->pubkey(), 'd' => $d])->orderByDesc('signed_at')->orderByDesc('id')->first();

        $stored = $first === null ? null : SignedEvent::fromInput($first->payload());
        $frozen = $stored === null ? $this->parameters($season, $game, $mode) : array_values(array_filter(
            $stored->tags,
            fn (array $tag): bool => in_array($tag[0] ?? null, self::FROZEN, true),
        ));

        $tags = [['d', $d], ...$frozen, self::trustTag($trustKey, $season->minimum_trust)];
        $tags[] = ['e', $season->genesisId(), ''];

        $last = $season->attestations()->where('ladder_address', Ladders::KIND.':'.$league->pubkey().':'.$d)->orderByDesc('id')->value('event_id');

        if (is_string($last)) {
            $tags[] = ['e', $last, ''];
        }

        $standings = $this->standings($season, $game, $mode);
        $rates = $this->games->mode($game, $mode)->rates ?? 'lineup';
        $listed = [];

        // Every entity with a standing or a seed, once, as a plain tag (NIP "Ladder").
        foreach ([...array_column($standings, 'entity'), ...array_map(fn (array $tag): string => (string) ($tag[1] ?? ''), array_values(array_filter($frozen, fn (array $tag): bool => ($tag[0] ?? null) === 'seed')))] as $entity) {
            if ($entity === '' || isset($listed[$entity])) {
                continue;
            }

            $listed[$entity] = true;
            $tags[] = $rates === 'player' ? ['p', $entity] : ['a', $entity, ''];
        }

        foreach ($standings as $index => $row) {
            $standing = ['standing', (string) ($index + 1), $row['entity'], (string) $row['rating'], (string) $row['wins'], (string) $row['losses'], $row['tier']];

            if ($row['draws'] > 0) {
                $standing[] = (string) $row['draws'];
            }

            $tags[] = $standing;
        }

        if ($ends !== null) {
            $tags[] = ['ends', (string) $ends];
        }

        $tags[] = ['alt', "Esports ladder: {$game} {$mode}, {$season->slug}".($ends !== null ? ', closed' : '')];

        // A new version must be newer than the one relays keep (NIP-01, rule "monotonic").
        $createdAt = max(now()->getTimestamp(), ($latest->signed_at ?? 0) + 1);

        return $league->publish(Ladders::KIND, $tags, $content, $createdAt);
    }

    /**
     * The season parameters of a first version, from the game registry and
     * the rating values the season froze at Block 0 (RatingSettings).
     *
     * @return list<list<string>>
     */
    private function parameters(Season $season, string $game, string $mode): array
    {
        $registry = $this->games->mode($game, $mode);
        $tags = [
            ['game', $game],
            ['mode', $mode],
            ['season', $season->slug],
            ['starts', (string) $season->genesis_at->getTimestamp()],
            ['rates', $registry->rates ?? 'lineup'],
        ];

        if ($registry?->timeControl !== null) {
            $tags[] = ['time_control', $registry->timeControl];
        }

        if ($game === 'chess') {
            $tags[] = ['variant', 'standard'];
        }

        return [...$tags, ...self::ratingTags(RatingSettings::forSeason($season)), ...$this->resetTags($season, $game, $mode)];
    }

    /**
     * The rating values every ladder of a season signs (`rating`, `tier`,
     * `provisional`, `hashrate`), from RatingSettings: what a first version
     * carries, and what the admin page shows before Block 0 ("What Block 0
     * signs").
     *
     * @param  array{rating: array{start: int, k: int, provisional_k: int, provisional: int, scale: int, daily_pair_limit: int|null}, tiers: array<string, int>, hashrate: array{win: int, draw: int, loss: int, team_win_bonus: int}}  $settings
     * @return list<list<string>>
     */
    public static function ratingTags(array $settings): array
    {
        $tags = [['rating', 'elo', (string) $settings['rating']['start'], (string) $settings['rating']['k'], (string) $settings['rating']['scale']]];

        foreach ($settings['tiers'] as $tier => $minimum) {
            $tags[] = ['tier', (string) $tier, (string) $minimum];
        }

        $tags[] = ['provisional', (string) $settings['rating']['provisional'], (string) $settings['rating']['provisional_k']];
        $tags[] = ['hashrate', ...array_map(strval(...), [$settings['hashrate']['win'], $settings['hashrate']['draw'], $settings['hashrate']['loss'], $settings['hashrate']['team_win_bonus']])];

        return $tags;
    }

    /**
     * The ladder's trust gate: the trust key and the minimum rank (NIP
     * "Trust gate").
     *
     * @return list<string>
     */
    public static function trustTag(string $trustKey, int $minimum): array
    {
        return ['trust', $trustKey, (string) $minimum];
    }

    /**
     * `reset` and the `seed` rows of a season that continues another, for
     * one ladder; none without a previous season or when this ladder had no
     * rated result there (NIP "Season transition": `reset` and `seed` appear
     * together or not at all).
     *
     * @return list<list<string>>
     *
     * @throws SeasonReleaseRefused when a seeded ladder has no attestation to pin
     */
    private function resetTags(Season $season, string $game, string $mode): array
    {
        $previous = $season->previousSeason;

        if ($previous === null || $season->reset_factor_milli === null) {
            return [];
        }

        $seeds = SoftReset::seeds($previous, $season)[$game.'/'.$mode] ?? [];

        if ($seeds === []) {
            return [];
        }

        $address = Ladders::KIND.':'.$previous->league_pubkey.':'.$game.'/'.$mode.'/'.$previous->slug;
        $last = $previous->attestations()->where('ladder_address', $address)->orderByDesc('id')->value('event_id');

        // A rated result without its attestation is a league error; never sign a `reset` that pins nothing.
        if (! is_string($last) || $last === '') {
            throw new SeasonReleaseRefused(__('The ladder :ladder of :season has rated results but no attestation to carry them over from. Nothing was released.', ['ladder' => $game.'/'.$mode, 'season' => $previous->slug]));
        }

        $tags = [['reset', $address, $last, SeasonRelease::factor($season->reset_factor_milli)]];

        foreach ($seeds as $seed) {
            $tags[] = ['seed', $seed['entity'], (string) $seed['seed']];
        }

        return $tags;
    }

    /**
     * The rated standings of this ladder, best first.
     *
     * @return list<array{entity: string, rates: string, rating: int, wins: int, losses: int, draws: int, tier: string}>
     */
    private function standings(Season $season, string $game, string $mode): array
    {
        $rates = $this->games->mode($game, $mode)->rates ?? 'lineup';
        $settings = RatingSettings::forSeason($season);
        $tiers = new RankTiers($settings['tiers'], $settings['rating']['provisional']);
        $rows = Rating::query()->where(['pool' => Rating::RATED, 'season' => $season->slug, 'game' => $game, 'mode' => $mode])
            ->where('results', '>', 0)->orderByDesc('rating')->orderBy('id')->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->filter())->pluck('pubkey', 'id');
        $lineups = Lineup::query()->with('clan')->whereIn('id', $rows->pluck('lineup_id')->filter())->get()->keyBy('id');
        $standings = [];

        foreach ($rows as $row) {
            // An entity of the ladder's own kind only: a player ladder lists pubkeys, a lineup ladder
            // lineup addresses, never a mix (NIP rev. 7.1; no `['a', <pubkey>, '']`, no `['p', <address>]`).
            $entity = $rates === 'player'
                ? ($row->user_id !== null ? $users->get($row->user_id) : null)
                : ($row->lineup_id !== null ? $lineups->get((int) $row->lineup_id)?->address() : null);

            if (! is_string($entity)) {
                continue; // a deleted player or lineup keeps its attestations, not a standing
            }

            $standings[] = [
                'entity' => $entity,
                'rates' => $rates,
                'rating' => $row->rating,
                'wins' => $row->wins,
                'losses' => $row->losses,
                'draws' => $row->draws,
                'tier' => $tiers->tierFor($row->rating, $row->results),
            ];
        }

        return $standings;
    }
}
