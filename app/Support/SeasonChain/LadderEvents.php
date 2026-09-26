<?php

namespace App\Support\SeasonChain;

use App\Games\GameRegistry;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\Season;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\Rating\RankTiers;
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
            foreach ($game->modes() as $mode) {
                $events[] = $this->publishOne($season, $league, $trustKey, $game->slug(), $mode->slug, $content);
            }
        }

        return $events;
    }

    private function publishOne(Season $season, LeagueKey $league, string $trustKey, string $game, string $mode, string $content): NostrEvent
    {
        $d = $game.'/'.$mode.'/'.$season->slug;
        $first = NostrEvent::query()->where(['kind' => Ladders::KIND, 'pubkey' => $league->pubkey(), 'd' => $d])->orderBy('signed_at')->orderBy('id')->first();
        $latest = NostrEvent::query()->where(['kind' => Ladders::KIND, 'pubkey' => $league->pubkey(), 'd' => $d])->orderByDesc('signed_at')->orderByDesc('id')->first();

        $stored = $first === null ? null : SignedEvent::fromInput($first->payload());
        $frozen = $stored === null ? $this->parameters($season, $game, $mode) : array_values(array_filter(
            $stored->tags,
            fn (array $tag): bool => in_array($tag[0] ?? null, self::FROZEN, true),
        ));

        $tags = [['d', $d], ...$frozen, ['trust', $trustKey, (string) $season->minimum_trust]];
        $tags[] = ['e', $season->genesisId(), ''];

        $last = $season->attestations()->where('ladder_address', Ladders::KIND.':'.$league->pubkey().':'.$d)->orderByDesc('id')->value('event_id');

        if (is_string($last)) {
            $tags[] = ['e', $last, ''];
        }

        $standings = $this->standings($season, $game, $mode);

        foreach ($standings as $row) {
            $tags[] = $row['rates'] === 'player' ? ['p', $row['entity']] : ['a', $row['entity'], ''];
        }

        foreach ($standings as $index => $row) {
            $standing = ['standing', (string) ($index + 1), $row['entity'], (string) $row['rating'], (string) $row['wins'], (string) $row['losses'], $row['tier']];

            if ($row['draws'] > 0) {
                $standing[] = (string) $row['draws'];
            }

            $tags[] = $standing;
        }

        $tags[] = ['alt', "Esports ladder: {$game} {$mode}, {$season->slug}"];

        // A new version must be newer than the one relays keep (NIP-01, rule "monotonic").
        $createdAt = max(now()->getTimestamp(), ($latest->signed_at ?? 0) + 1);

        return $league->publish(Ladders::KIND, $tags, $content, $createdAt);
    }

    /**
     * The season parameters of a first version, from the game registry and
     * config/season.php.
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

        $tags[] = ['rating', 'elo', (string) config('season.rating.start'), (string) config('season.rating.k'), (string) config('season.rating.scale')];

        foreach ((array) config('season.tiers') as $tier => $minimum) {
            $tags[] = ['tier', (string) $tier, (string) $minimum];
        }

        $tags[] = ['provisional', (string) config('season.rating.provisional'), (string) config('season.rating.provisional_k')];
        $tags[] = ['hashrate', ...array_map(strval(...), array_values((array) config('season.hashrate')))];

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
        $tiers = RankTiers::fromConfig();
        $rows = Rating::query()->where(['pool' => Rating::RATED, 'season' => $season->slug, 'game' => $game, 'mode' => $mode])
            ->where('results', '>', 0)->orderByDesc('rating')->orderBy('id')->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->filter())->pluck('pubkey', 'id');
        $lineups = Lineup::query()->with('clan')->whereIn('id', $rows->pluck('lineup_id')->filter())->get()->keyBy('id');
        $standings = [];

        foreach ($rows as $row) {
            $entity = $row->user_id !== null ? $users->get($row->user_id) : $lineups->get((int) $row->lineup_id)?->address();

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
