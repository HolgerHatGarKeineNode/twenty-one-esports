<?php

namespace App\Support\Players;

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\ClanDeparture;
use App\Models\Lineup;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\Season;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\GameNames;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Rating\Ratings;
use App\Support\Series\Ladders;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The public record of one player on their page (P31): where they stand in
 * every ladder they have played, their latest results, the tournaments they
 * played with place and prize, the season record and their clans.
 *
 * Public by design, so only what any visitor may see: ratings and results
 * are league records, a prize is the amount the league paid (never a pot or
 * wallet balance), a clan is its public name. Nothing about looking-to-play,
 * the Lightning address or casual chat is read here.
 *
 * A ladder shows the pool that counts now: the season ladder once it is
 * open, the casual one before Block 0 (Ratings::pool()). Only ladders with a
 * result in that pool are listed. Form and peak come from the rating's own
 * changes, one query for all ladders each.
 *
 * @phpstan-type Summary array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string}
 * @phpstan-type Ladder array{key: string, game: string, mode: string, name: string, lineup: Lineup|null, rating: Summary, form: list<'win'|'draw'|'loss'>, peak: int, href: string}
 * @phpstan-type Played array{tournament: Tournament, place: int|null, of: int, prize: int|null}
 * @phpstan-type SeasonRow array{key: string, name: string, casual: bool, results: int, wins: int, draws: int, losses: int}
 * @phpstan-type ClanRow array{clan: Clan|null, name: string, since: CarbonInterface|null, until: CarbonInterface|null}
 */
final class PlayerStats
{
    /** Results in a ladder's form. */
    public const FORM = 5;

    /** Tournaments listed; the count says how many there are in all. */
    public const TOURNAMENTS = 6;

    /** Former clans listed. */
    public const FORMER_CLANS = 4;

    private readonly RecentResults $results;

    /** @var EloquentCollection<int, Rating>|null */
    private ?EloquentCollection $ratings = null;

    public function __construct(private readonly User $user)
    {
        $this->results = new RecentResults($user);
    }

    /**
     * Every ladder this player (or a lineup they sit in) has a result in,
     * in the registry's order of games and modes.
     *
     * @return list<Ladder>
     */
    public function ladders(): array
    {
        $registry = app(GameRegistry::class);
        $games = array_keys($registry->all());
        $open = [];
        $rows = $this->ratings()->filter(function (Rating $row) use (&$open): bool {
            $open[$row->game.'/'.$row->mode] ??= Ladders::isOpen($row->game, $row->mode);

            return $row->results > 0 && $row->pool === Ratings::pool($open[$row->game.'/'.$row->mode]);
        })->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $form = $this->form($rows->modelKeys());
        $peaks = RatingChange::query()->whereIn('rating_id', $rows->modelKeys())
            ->select('rating_id')->selectRaw('max("after") as high_after, max("before") as high_before')->groupBy('rating_id')->get()
            ->mapWithKeys(fn (RatingChange $change): array => [(int) $change->rating_id => max((int) $change->getAttribute('high_after'), (int) $change->getAttribute('high_before'))]);
        $lineups = Lineup::query()->whereIn('id', $rows->pluck('lineup_id')->filter())->with('clan')->get()->keyBy('id');

        $ladders = $rows->map(function (Rating $row) use ($form, $peaks, $lineups): array {
            $summary = Ratings::summary($row, $row->pool);

            return [
                'key' => $row->game.'-'.$row->mode.'-'.$row->subject,
                'game' => $row->game,
                'mode' => $row->mode,
                'name' => GameNames::full($row->game, $row->mode),
                'lineup' => $row->lineup_id !== null ? $lineups->get($row->lineup_id) : null,
                'rating' => $summary,
                'form' => $form[$row->id] ?? [],
                'peak' => max($summary['rating'], (int) ($peaks[$row->id] ?? 0)),
                'href' => route('ladder.show', [$row->game, $row->mode]),
            ];
        });

        return array_values($ladders->sortBy(fn (array $ladder): array => [
            array_search($ladder['game'], $games, true),
            array_search($ladder['mode'], array_keys($registry->find($ladder['game'])?->modes() ?? []), true),
            $ladder['lineup'] !== null,
        ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function results(): array
    {
        return $this->results->latest();
    }

    /**
     * The tournaments this player played (running or finished), newest
     * first, with their place once finished and the prize the league paid.
     *
     * @return array{rows: list<Played>, count: int, prizes: int}
     */
    public function tournaments(): array
    {
        $me = $this->user->id;
        $entries = TournamentParticipant::query()
            ->where(fn ($query) => $query->where('user_id', $me)->orWhereJsonContains('members', $me))
            ->whereHas('tournament', fn ($query) => $query->whereIn('status', [TournamentStatus::Running, TournamentStatus::Finished])->whereNotNull('published_at'))
            ->with(['tournament' => fn ($query) => $query->withCount('participants')])->get()
            ->sortByDesc(fn (TournamentParticipant $entry): int => $entry->tournament->starts_at->getTimestamp())
            ->unique('tournament_id')->values();

        $paid = TournamentPayout::query()->where('user_id', $me)->where('status', PayoutStatus::Paid)
            ->get(['tournament_id', 'amount_sats'])->groupBy('tournament_id')
            ->map(fn (Collection $payouts): int => (int) $payouts->sum('amount_sats'));
        $placements = app(TournamentPlacements::class);
        $rows = [];

        // Places are read from the stored bracket, so only for the tournaments listed.
        foreach ($entries->take(self::TOURNAMENTS) as $entry) {
            $place = null;

            foreach ($placements->of($entry->tournament) ?? [] as $group) {
                if (in_array($entry->id, $group['participants'], true)) {
                    $place = $group['place'];
                    break;
                }
            }

            $rows[] = [
                'tournament' => $entry->tournament,
                'place' => $place,
                'of' => (int) $entry->tournament->getAttribute('participants_count'),
                'prize' => $paid[$entry->tournament_id] ?? null,
            ];
        }

        return ['rows' => $rows, 'count' => $entries->count(), 'prizes' => (int) $paid->sum()];
    }

    /**
     * Results per season: every released season's rated ladders, newest
     * first, then Season 0, the casual ladders that run with no season.
     *
     * @return list<SeasonRow>
     */
    public function seasons(): array
    {
        $totals = $this->ratings()->where('results', '>', 0)->groupBy(fn (Rating $row): string => $row->pool.'|'.$row->season);
        $row = function (string $key, string $name, bool $casual, ?Collection $ratings): array {
            return [
                'key' => $key,
                'name' => $name,
                'casual' => $casual,
                'results' => (int) ($ratings?->sum('results') ?? 0),
                'wins' => (int) ($ratings?->sum('wins') ?? 0),
                'draws' => (int) ($ratings?->sum('draws') ?? 0),
                'losses' => (int) ($ratings?->sum('losses') ?? 0),
            ];
        };

        $seasons = [];

        foreach (Season::query()->latest('genesis_at')->pluck('slug') as $slug) {
            $seasons[] = $row('rated-'.$slug, BadgeCopy::season($slug), false, $totals->get(Rating::RATED.'|'.$slug));
        }

        $seasons[] = $row('casual', __('Season 0'), true, $totals->get(Rating::CASUAL.'|'));

        return $seasons;
    }

    /**
     * The clan this player is in now, then the clans they left, latest first.
     *
     * @return list<ClanRow>
     */
    public function clans(): array
    {
        $this->user->loadMissing('clanMember.clan');
        $rows = [];

        if ($this->user->clanMember !== null) {
            $rows[] = ['clan' => $this->user->clanMember->clan, 'name' => $this->user->clanMember->clan->name, 'since' => $this->user->clanMember->joined_at, 'until' => null];
        }

        $former = ClanDeparture::query()->where('user_id', $this->user->id)->latest('left_at')->latest('id')->limit(self::FORMER_CLANS)->get();
        $clans = Clan::query()->whereIn('id', $former->pluck('clan_id'))->get()->keyBy('id');

        foreach ($former as $departure) {
            $clan = $clans->get($departure->clan_id);
            $rows[] = ['clan' => $clan, 'name' => $clan->name ?? (string) $departure->clan_name, 'since' => null, 'until' => $departure->left_at];
        }

        return $rows;
    }

    /**
     * Every rating row of this player's subjects in the casual ladders and
     * the rated ladders of all seasons, loaded once.
     *
     * @return EloquentCollection<int, Rating>
     */
    private function ratings(): EloquentCollection
    {
        return $this->ratings ??= Rating::query()->whereIn('subject', $this->results->subjects())->orderBy('id')->get();
    }

    /**
     * The last results of each rating, oldest first, as win/draw/loss.
     *
     * @param  array<int, mixed>  $ratingIds
     * @return array<int, list<'win'|'draw'|'loss'>>
     */
    private function form(array $ratingIds): array
    {
        $ranked = RatingChange::query()->select(['rating_id', 'score', 'id'])
            ->selectRaw('row_number() over (partition by rating_id order by id desc) as recent')
            ->whereIn('rating_id', $ratingIds);

        return RatingChange::query()->withoutGlobalScopes()->fromSub($ranked, 'rating_changes')
            ->where('recent', '<=', self::FORM)->orderBy('rating_id')->orderBy('id')->get()
            ->groupBy('rating_id')
            ->map(fn (Collection $changes): array => array_values(array_map(fn (RatingChange $change) => self::outcome($change->score), $changes->all())))
            ->all();
    }

    /**
     * @return 'win'|'draw'|'loss'
     */
    private static function outcome(float $score): string
    {
        return match (true) {
            $score >= 1.0 => 'win',
            $score <= 0.0 => 'loss',
            default => 'draw',
        };
    }
}
