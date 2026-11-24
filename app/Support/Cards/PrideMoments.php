<?php

namespace App\Support\Cards;

use App\Enums\TournamentStatus;
use App\Models\Rating;
use App\Models\Season;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Players\PlayerStats;
use App\Support\Rating\Ratings;
use App\Support\SeasonChain\Seasons;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentTv;

/**
 * The facts of the pride pictures (plan "KI-Stimme der Liga", P4 "Stolz und
 * Bilder"): the player card, the Season Wrapped story and the tale of the
 * tape before a final. Real values only (rule R13): every number is read
 * from the ratings, the season chain or the bracket, nothing is levelled,
 * scored or invented. Arrays of scalars, so a card's fingerprint and the
 * queued voice job can carry them.
 *
 * A pubkey and an uploaded picture travel with each person for the avatar;
 * no gamer tag and no game account is read here (no public link between a
 * Nostr profile and a game account, user 2026-09-30).
 */
final class PrideMoments
{
    /**
     * The player card: the strongest ladder (rating, its tier, its name),
     * the record over every ladder the player's page lists, the highest
     * rating ever reached there, the blocks and sats of the current season
     * and the tournaments won.
     *
     * @return array{name: string, pubkey: string, avatar_path: string|null, rating: int|null, ladder: string|null, tier: string|null, provisional: bool, results: int, wins: int, draws: int, losses: int, winrate: int|null, peak: int|null, blocks: int, sats: int, tournaments: int, season: string|null, wrapped: bool}
     */
    public static function player(User $user, ?PlayerStats $stats = null): array
    {
        return self::readPlayer($user, $stats);
    }

    /**
     * @return array{name: string, pubkey: string, avatar_path: string|null, rating: int|null, ladder: string|null, tier: string|null, provisional: bool, results: int, wins: int, draws: int, losses: int, winrate: int|null, peak: int|null, blocks: int, sats: int, tournaments: int, season: string|null, wrapped: bool}
     */
    private static function readPlayer(User $user, ?PlayerStats $stats): array
    {
        $ladders = ($stats ?? new PlayerStats($user))->ladders();
        $best = null;

        foreach ($ladders as $ladder) {
            if ($best === null || $ladder['rating']['rating'] > $best['rating']['rating']) {
                $best = $ladder;
            }
        }

        $sum = fn (string $key): int => array_sum(array_map(fn (array $ladder): int => (int) $ladder['rating'][$key], $ladders));
        $results = $sum('results');
        $wins = $sum('wins');
        $season = Seasons::live() ?? Seasons::latest();
        $blocks = $season === null ? collect() : ShareMoments::minedBy($user, $season->id);

        return [
            'name' => $user->displayName(),
            'pubkey' => (string) $user->pubkey,
            'avatar_path' => $user->avatar_path,
            'rating' => $best === null ? null : (int) $best['rating']['rating'],
            'ladder' => $best === null ? null : BadgeCopy::ladder($best['game'], $best['mode']),
            'tier' => $best['rating']['tier'] ?? null,
            'provisional' => (bool) ($best['rating']['provisional'] ?? true),
            'results' => $results,
            'wins' => $wins,
            'draws' => $sum('draws'),
            'losses' => $sum('losses'),
            'winrate' => $results > 0 ? (int) round($wins / $results * 100) : null,
            'peak' => $ladders === [] ? null : max(array_map(fn (array $ladder): int => (int) $ladder['peak'], $ladders)),
            'blocks' => $blocks->count(),
            'sats' => (int) $blocks->sum('reward_per_player'),
            'tournaments' => count(ShareMoments::tournamentWins($user)),
            'season' => $season?->slug,
            // Whether that season has a Wrapped story of this player (the page links it); read with the card, kept with it.
            'wrapped' => $season !== null && ShareMoments::hasWrapped($season, $user),
        ];
    }

    /**
     * Whether a player has a card at all: a result in a ladder, a block of
     * the season or a tournament win. A card of zeros says nothing.
     *
     * @param  array<string, mixed>  $facts  player()
     */
    public static function hasCard(array $facts): bool
    {
        return $facts['results'] > 0 || $facts['blocks'] > 0 || $facts['tournaments'] > 0;
    }

    /**
     * The template title of a player card, from the card's own numbers (the
     * fallback whenever no model line is there): a tournament win first,
     * then the blocks, a strong share of wins, many results, any result.
     *
     * @param  array<string, mixed>  $facts  player()
     */
    public static function playerTitle(array $facts): string
    {
        return match (true) {
            $facts['tournaments'] > 0 => trans_choice('Tournament winner|:count× tournament winner', $facts['tournaments']),
            $facts['blocks'] >= 10 => __('Miner of the season'),
            $facts['results'] >= 10 && $facts['winrate'] >= 60 => __('Favourite'),
            $facts['results'] >= 25 => __('Regular'),
            $facts['results'] > 0 => __('Challenger'),
            default => __('Newcomer'),
        };
    }

    /**
     * Season Wrapped as a story: the card's facts (ShareMoments::wrapped())
     * plus the record split into wins, draws and losses, the number of
     * results and the names of the tournaments won (newest first, at most three).
     *
     * @return array<string, mixed>
     */
    public static function wrapped(Season $season, User $user): array
    {
        $facts = ShareMoments::wrapped($season, $user);
        $stats = (new PlayerStats($user))->seasons();
        $row = collect($stats)->firstWhere('key', 'rated-'.$season->slug) ?? ['results' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0];
        $won = ShareMoments::tournamentWins($user, $season);

        return [
            ...$facts,
            'results' => (int) $row['results'],
            'draws' => (int) $row['draws'],
            'losses' => (int) $row['losses'],
            'won' => array_map(fn (array $win): string => $win['tournament']->name, array_slice($won, 0, 3)),
        ];
    }

    /**
     * The template line of the Wrapped story's last page.
     *
     * @param  array<string, mixed>  $facts  wrapped()
     */
    public static function wrappedLine(array $facts): string
    {
        return match (true) {
            $facts['tournaments'] > 0 => __(':blocks blocks and a tournament win. That season stays on the chain.', ['blocks' => $facts['blocks']]),
            $facts['blocks'] > 0 => __(':blocks blocks on the season chain, every one a rated win.', ['blocks' => $facts['blocks']]),
            default => __(':results rated games played. The next block is yours.', ['results' => $facts['results']]),
        };
    }

    /**
     * The final a tale of the tape is about: the last match of a running
     * knockout (the final, or the grand final of a double elimination;
     * never the match for 3rd place or a reset), still to be played and
     * with both sides known.
     */
    public static function upcomingFinal(Tournament $tournament): ?TournamentMatch
    {
        if ($tournament->status !== TournamentStatus::Running || ! $tournament->isVisibleTo(null)) {
            return null;
        }

        // One query on every running tournament page (its query budget, PageQueryBudgetTest): once both sides of the
        // final are known, every earlier match is decided, so the final is the only open match left (a match for 3rd
        // place and a grand final's reset aside). Two or more open: no final yet, nothing else is read.
        $open = TournamentMatch::query()->where('tournament_id', $tournament->id)
            ->whereIn('status', ['waiting', 'ready'])->whereNotIn('bracket', ['third-place', 'reset', 'bye'])
            ->limit(2)->get();

        if ($open->count() !== 1) {
            return null;
        }

        $match = $open->first()->load(['round.stage', 'slots.participant']);
        $sides = $match->slots->sortBy('slot')->values();

        return $sides->count() === 2 && $sides->every(fn ($slot): bool => $slot->participant !== null) && TournamentRunner::isFinal($match) ? $match : null;
    }

    /**
     * Whether a tournament page's own bracket (TournamentView::stages(), already read for the page) has exactly one
     * open match left: the only state in which upcomingFinal() can find a final. Lets the page skip that query on
     * every other running tournament (its query budget, PageQueryBudgetTest).
     *
     * @param  list<array<string, mixed>>  $stages
     */
    public static function finalMayBeNext(array $stages): bool
    {
        $open = 0;

        foreach ($stages as $stage) {
            foreach (TournamentTv::boxesOf($stage) as $box) {
                if (in_array($box['status'] ?? null, ['waiting', 'ready'], true) && ! in_array($box['bracket'] ?? null, ['third-place', 'reset', 'bye'], true)) {
                    $open++;
                }
            }
        }

        return $open === 1;
    }

    /**
     * The tale of the tape: both finalists side by side. Per side the seed,
     * the rating and the record in the tournament's ladder (the Season once
     * a finalist has a result there, else casual; `pool` says which), the matches won in this tournament and the tournaments
     * won before (a player entry; a team has none of its own).
     *
     * @return array{tournament: string, tournament_id: int, match: int, ladder: string, pool: string, best_of: int|null, sides: list<array{id: int, name: string, pubkey: string, avatar_path: string|null, user_id: int|null, seed: int|null, rating: int, wins: int, draws: int, losses: int, results: int, path: int, titles: int|null}>}
     */
    public static function tape(Tournament $tournament, TournamentMatch $final): array
    {
        $final->loadMissing('slots.participant.user');
        // Both sides from one pool (plan "Season vs. Casual", P1): the Season once a finalist has a result there, else casual.
        $pairs = $final->slots->map(fn ($slot): array => $slot->participant->lineup_id !== null
            ? Ratings::pairForLineup($slot->participant->lineup_id, $tournament->game, $tournament->mode)
            : Ratings::pair($slot->participant->user_id, $tournament->game, $tournament->mode));
        $pool = $pairs->contains(fn (array $pair): bool => ($pair['season']['results'] ?? 0) > 0) ? Rating::RATED : Rating::CASUAL;
        $done = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'done')->where('id', '!=', $final->id)->with('slots')->get();
        $sides = [];

        foreach ($final->slots->sortBy('slot') as $key => $slot) {
            /** @var TournamentParticipant $entry */
            $entry = $slot->participant;
            $rating = $pool === Rating::RATED ? $pairs[$key]['season'] : $pairs[$key]['casual'];
            $path = $done->filter(function (TournamentMatch $match) use ($entry): bool {
                $winner = $match->matchResult()?->winner;
                $own = $match->slots->firstWhere('tournament_participant_id', $entry->id);

                return $winner !== null && $own !== null && $own->slot === $winner;
            })->count();
            $user = $entry->lineup_id === null ? $entry->user : null;

            $sides[] = [
                'id' => $entry->id,
                'name' => $entry->name,
                'pubkey' => (string) ($user->pubkey ?? hash('sha256', 'tape-entry-'.$entry->id)),
                'avatar_path' => $user?->avatar_path,
                'user_id' => $user?->id,
                'seed' => $entry->seed,
                'rating' => (int) $rating['rating'],
                'wins' => (int) $rating['wins'],
                'draws' => (int) $rating['draws'],
                'losses' => (int) $rating['losses'],
                'results' => (int) $rating['results'],
                'path' => $path,
                'titles' => $user === null ? null : count(ShareMoments::tournamentWins($user)),
            ];
        }

        $options = $tournament->formatOptions();

        return [
            'tournament' => $tournament->name,
            'tournament_id' => $tournament->id,
            'match' => $final->id,
            'ladder' => BadgeCopy::ladder($tournament->game, $tournament->mode),
            'pool' => $pool,
            'best_of' => $tournament->isDirectorMode() ? null : (int) $options->finalBestOf,
            'sides' => $sides,
        ];
    }

    /**
     * Which side leads a row of the tape: 0, 1, or null for a tie or a row
     * one side has no value in. Lower is better for the seed only.
     *
     * @param  array<string, mixed>  $facts  tape()
     */
    public static function tapeLead(array $facts, string $row): ?int
    {
        [$a, $b] = $facts['sides'];
        [$left, $right] = match ($row) {
            'record' => [self::share($a), self::share($b)],
            'seed' => [$a['seed'] === null ? null : -$a['seed'], $b['seed'] === null ? null : -$b['seed']],
            default => [$a[$row], $b[$row]],
        };

        if ($left === null || $right === null || $left === $right) {
            return null;
        }

        return $left > $right ? 0 : 1;
    }

    /**
     * The template line of the tape: who leads more rows, or that it is even.
     *
     * @param  array<string, mixed>  $facts  tape()
     */
    public static function tapeLine(array $facts): string
    {
        $leads = [0 => 0, 1 => 0];

        foreach (['rating', 'record', 'path', 'titles'] as $row) {
            $lead = self::tapeLead($facts, $row);

            if ($lead !== null) {
                $leads[$lead]++;
            }
        }

        if ($leads[0] === $leads[1]) {
            return __('Even on paper. The final decides.');
        }

        $favourite = $leads[0] > $leads[1] ? 0 : 1;

        return __(':name leads on paper. Finals are not played on paper.', ['name' => $facts['sides'][$favourite]['name']]);
    }

    /** @param array<string, mixed> $side */
    private static function share(array $side): ?float
    {
        return $side['results'] > 0 ? ($side['wins'] + $side['draws'] / 2) / $side['results'] : null;
    }
}
