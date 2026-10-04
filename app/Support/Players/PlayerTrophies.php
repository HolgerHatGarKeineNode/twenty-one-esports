<?php

namespace App\Support\Players;

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Payouts\TournamentPlacements;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * The trophies on a player's page (user, 2026-10-04: "mehr Stolz-Momente, zb
 * auch die Turniersiege (die letzten besonders mit Animation) hervorgehoben"):
 * every place 1 to 3 the player (alone or in a lineup) took in a finished,
 * published tournament, newest first, with the prize the league paid for it.
 *
 * Places are TournamentPlacements', read from the stored bracket. A bracket
 * read costs about six queries, so the places of a tournament are cached,
 * keyed by its id and a fingerprint of its matches (count and newest
 * change), which one grouped query reads for all tournaments at once: a
 * corrected result changes the key, and every player of that tournament
 * shares the entry. Warm, the trophies cost four queries however many
 * tournaments the player won; cold, each tournament not yet cached adds its
 * bracket read once. At most the newest {@see self::SCAN} finished
 * tournaments of the player are read.
 *
 * Public by design, like PlayerStats: a place is a league record, a prize
 * is what the league paid (never a pot or wallet balance).
 *
 * @phpstan-type Trophy array{tournament: Tournament, place: int, shared: bool, prize: int|null, at: CarbonInterface|null, recent: bool}
 */
final class PlayerTrophies
{
    /** Finished tournaments of the player read at most, newest first. */
    public const SCAN = 24;

    /** A win this many days old or newer is the recent one the page celebrates. */
    public const RECENT_DAYS = 30;

    /** How long the places of one tournament (and fingerprint) stay cached. */
    private const CACHE_SECONDS = 86_400;

    public function __construct(private TournamentPlacements $placements) {}

    /**
     * @return array{trophies: list<Trophy>, wins: int, podiums: int}
     */
    public function of(User $user): array
    {
        $me = $user->id;
        $entered = fn ($query) => $query->where(fn ($query) => $query->where('user_id', $me)->orWhereJsonContains('members', $me));

        $tournaments = Tournament::query()->where('status', TournamentStatus::Finished)->whereNotNull('published_at')->exceptLeagueWeeks()
            ->whereHas('participants', $entered)
            ->withCount('participants')->orderByDesc('starts_at')->orderByDesc('id')->limit(self::SCAN)->get();

        if ($tournaments->isEmpty()) {
            return ['trophies' => [], 'wins' => 0, 'podiums' => 0];
        }

        $ids = $tournaments->modelKeys();
        $entries = $entered(TournamentParticipant::query()->whereIn('tournament_id', $ids))
            ->get(['id', 'tournament_id'])->groupBy('tournament_id')
            ->map(fn ($rows): array => $rows->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $prints = TournamentMatch::query()->whereIn('tournament_id', $ids)
            ->select('tournament_id')->selectRaw('count(*) as n, max(updated_at) as changed')->groupBy('tournament_id')
            ->toBase()->get()->keyBy('tournament_id');

        $trophies = [];

        foreach ($tournaments as $tournament) {
            $print = $prints->get($tournament->id);
            $key = 'players.trophies.places.'.$tournament->id.'.'.md5(($print->n ?? 0).'|'.($print->changed ?? '').'|'.$tournament->updated_at?->getTimestamp());
            $places = Cache::remember($key, self::CACHE_SECONDS, fn (): array => $this->placements->of($tournament) ?? []);
            $mine = $entries->get($tournament->id, []);

            foreach ($places as $group) {
                if ($group['place'] > 3) {
                    break;
                }

                if (array_intersect($mine, $group['participants']) !== []) {
                    $trophies[] = [
                        'tournament' => $tournament,
                        'place' => (int) $group['place'],
                        'shared' => count($group['participants']) > 1,
                        'prize' => null,
                        'at' => $tournament->starts_at,
                        'recent' => false,
                    ];
                    break;
                }
            }
        }

        if ($trophies === []) {
            return ['trophies' => [], 'wins' => 0, 'podiums' => 0];
        }

        $paid = TournamentPayout::query()->where('user_id', $me)->where('status', PayoutStatus::Paid)
            ->whereIn('tournament_id', array_map(fn (array $trophy): int => $trophy['tournament']->id, $trophies))
            ->select('tournament_id')->selectRaw('sum(amount_sats) as sats')->groupBy('tournament_id')
            ->toBase()->pluck('sats', 'tournament_id');
        $recentFrom = now()->subDays(self::RECENT_DAYS);
        $celebrated = false;

        foreach ($trophies as $index => $trophy) {
            $id = $trophy['tournament']->id;
            $trophies[$index]['prize'] = isset($paid[$id]) ? (int) $paid[$id] : null;

            // Only the newest win is celebrated, and only while it is recent.
            if (! $celebrated && $trophy['place'] === 1) {
                $celebrated = true;
                $trophies[$index]['recent'] = $trophy['at']->gte($recentFrom);
            }
        }

        $wins = count(array_filter($trophies, fn (array $trophy): bool => $trophy['place'] === 1));

        return ['trophies' => $trophies, 'wins' => $wins, 'podiums' => count($trophies) - $wins];
    }
}
