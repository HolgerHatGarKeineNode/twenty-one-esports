<?php

namespace App\Support\Players;

use App\Enums\HyperMatchStatus;
use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use App\Games\ProofOfPong;
use App\Models\Clan;
use App\Models\ClanDeparture;
use App\Models\HyperSeat;
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
use App\Support\Hyper\HyperCups;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Pong\PongLadder;
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
 * @phpstan-type Ladder array{key: string, game: string, mode: string, name: string, lineup: Lineup|null, rating: Summary, form: list<'win'|'draw'|'loss'>, peak: int, place: int|null, of: int, href: string}
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
        $places = $this->places($rows);

        $ladders = $rows->map(function (Rating $row) use ($form, $peaks, $lineups, $places): array {
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
                'place' => $places[$row->id]['place'] ?? null,
                'of' => $places[$row->id]['of'] ?? 0,
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
     * Everything this player has a standing in, game by game in the
     * registry's order: the ladder cards of a versus game (ladders()), the
     * cards of a score game (PlayerScores), the Hyperbitcoinization card
     * (hyper()) and the Proof of Pong card (pong()). A game registered later shows
     * here with no change to the page; a game the player never played has no card.
     *
     * @return list<array{kind: 'ladder', game: string, ladder: Ladder}|array{kind: 'score', game: string, score: array<string, mixed>}|array{kind: 'hyper', game: string, hyper: array{sats: float, matches: int, wins: int}}|array{kind: 'pong', game: string, pong: array<string, mixed>}>
     */
    public function games(): array
    {
        $order = array_flip(array_keys(app(GameRegistry::class)->all()));
        $cards = [
            ...array_map(fn (array $ladder): array => ['kind' => 'ladder', 'game' => $ladder['game'], 'ladder' => $ladder], $this->ladders()),
            ...array_map(fn (array $score): array => ['kind' => 'score', 'game' => $score['game'], 'score' => $score], (new PlayerScores($this->user))->cards()),
            ...array_map(fn (array $hyper): array => ['kind' => 'hyper', 'game' => Hyperbitcoinization::SLUG, 'hyper' => $hyper], $this->hyper()),
            ...array_map(fn (array $pong): array => ['kind' => 'pong', 'game' => ProofOfPong::SLUG, 'pong' => $pong], $this->pong()),
        ];

        // A stable sort: within a game the cards keep the order of their own list (modes, then lineups).
        usort($cards, fn (array $a, array $b): int => ($order[$a['game']] ?? PHP_INT_MAX) <=> ($order[$b['game']] ?? PHP_INT_MAX));

        return $cards;
    }

    /**
     * The Hyperbitcoinization card (plan "Hyperbitcoinization", P3): the sats this player collected as loot
     * in finished matches ("gesammelte Sats", game points only: no money, no payout), how many they played
     * and won. One aggregate query; no card while the game is switched off or never finished here.
     *
     * @return list<array{sats: float, matches: int, wins: int}>
     */
    public function hyper(): array
    {
        if (app(GameRegistry::class)->find(Hyperbitcoinization::SLUG) === null) {
            return [];
        }

        $row = HyperSeat::query()->where('user_id', $this->user->id)
            ->whereHas('match', fn ($match) => $match->where('status', HyperMatchStatus::Finished))
            ->selectRaw('count(*) as matches, coalesce(sum(loot), 0) as sats, sum(case when place = 1 then 1 else 0 end) as wins')
            ->toBase()->first();
        $matches = (int) ($row->matches ?? 0);

        // P5: the weekend cups won, the winner badge on the card.
        return $matches === 0 ? [] : [['sats' => round((float) $row->sats, 1), 'matches' => $matches, 'wins' => (int) $row->wins, 'cups' => HyperCups::winsOf($this->user)]];
    }

    /**
     * The Proof of Pong card (plan "Proof of Pong", P4, PongLadder::card()): Elo, place, wins and losses of the
     * finished live matches and the figure picked most. No card while the game is switched off or never finished here.
     *
     * @return list<array<string, mixed>>
     */
    public function pong(): array
    {
        if (app(GameRegistry::class)->find(ProofOfPong::SLUG) === null) {
            return [];
        }

        $card = PongLadder::card($this->user);

        return $card === null ? [] : [$card];
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
        $entered = fn ($query) => $query->where(fn ($query) => $query->where('user_id', $me)->orWhereJsonContains('members', $me));
        // A Blockfill week (plan "Blockfill", P6) is a game's leaderboard, not a tournament played.
        $played = Tournament::query()->whereIn('status', [TournamentStatus::Running, TournamentStatus::Finished])->whereNotNull('published_at')->exceptLeagueWeeks()
            ->whereHas('participants', $entered);

        // Three aggregates and two bounded reads, however many tournaments the player has.
        $count = (clone $played)->count();
        $tournaments = $played->withCount('participants')->orderByDesc('starts_at')->orderByDesc('id')->limit(self::TOURNAMENTS)->get();
        $ids = $tournaments->modelKeys();
        $entries = $ids === [] ? collect() : $entered(TournamentParticipant::query()->whereIn('tournament_id', $ids))->orderBy('id')->get()->unique('tournament_id')->keyBy('tournament_id');
        $paid = TournamentPayout::query()->where('user_id', $me)->where('status', PayoutStatus::Paid);
        $prizes = (int) (clone $paid)->sum('amount_sats');
        $perRow = $ids === [] || $prizes === 0 ? collect() : $paid->whereIn('tournament_id', $ids)
            ->select('tournament_id')->selectRaw('sum(amount_sats) as sats')->groupBy('tournament_id')
            ->toBase()->pluck('sats', 'tournament_id');
        $placements = app(TournamentPlacements::class);
        $rows = [];

        foreach ($tournaments as $tournament) {
            $entry = $entries->get($tournament->id);
            $place = null;

            // Places are read from the stored bracket, so only for the tournaments listed.
            foreach ($entry === null ? [] : ($placements->of($tournament) ?? []) as $group) {
                if (in_array($entry->id, $group['participants'], true)) {
                    $place = $group['place'];
                    break;
                }
            }

            $rows[] = [
                'tournament' => $tournament,
                'place' => $place,
                'of' => (int) $tournament->getAttribute('participants_count'),
                'prize' => isset($perRow[$tournament->id]) ? (int) $perRow[$tournament->id] : null,
            ];
        }

        return ['rows' => $rows, 'count' => $count, 'prizes' => $prizes];
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
     * The place of each rating in its ladder and how many rows that ladder
     * has, as the ladder page counts them (rating, then more results, then
     * first rated; every row with a result). One query for all ladders.
     *
     * @param  EloquentCollection<int, Rating>  $rows
     * @return array<int, array{place: int, of: int}>
     */
    private function places(EloquentCollection $rows): array
    {
        $ranked = Rating::query()->select('id')
            ->selectRaw('row_number() over (partition by pool, season, game, mode order by rating desc, results desc, id) as place')
            ->selectRaw('count(*) over (partition by pool, season, game, mode) as total')
            ->where('results', '>', 0)
            ->where(function ($query) use ($rows): void {
                foreach ($rows->unique(fn (Rating $row): string => $row->pool.'|'.$row->season.'|'.$row->game.'|'.$row->mode) as $row) {
                    $query->orWhere(fn ($one) => $one->where(['pool' => $row->pool, 'season' => $row->season, 'game' => $row->game, 'mode' => $row->mode]));
                }
            });

        return Rating::query()->withoutGlobalScopes()->fromSub($ranked, 'ratings')->whereIn('id', $rows->modelKeys())->toBase()->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => ['place' => (int) $row->place, 'of' => (int) $row->total]])
            ->all();
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
