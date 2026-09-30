<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\BoardGameStatus;
use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\PayoutStatus;
use App\Enums\SeriesResolution;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\InviteLinkUse;
use App\Models\RankBadgeVersion;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonBlockVoid;
use App\Models\SeasonPayout;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Prizes\PrizePool;
use App\Support\Rating\RankTiers;
use App\Support\Rating\StrongestList;
use App\Support\Tournaments\TournamentChampion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * The data of the pride and prize slides (e1-e9, and the top inviters of
 * d3): single players named for what they did, and the sats a tournament
 * pays. Everything is read as the site shows it, nothing is estimated; a
 * slide without data says so. A voided result (two accounts of one person,
 * FairPlayVoid; a void series; a voided season block) is never a pride
 * moment: voided chess and board games are aborted without a result, voided
 * series carry resolution `void`, voided blocks a SeasonBlockVoid row.
 *
 * - `win`: the latest decided result of the last week (else the latest
 *   ever) over chess, the board games that are switched on (plan "Mühle und
 *   Dame", P7) and the Rocket League / EA Sports FC series: winner, loser,
 *   the game and mode, the winner's casual Elo change (`delta`) or rated
 *   one (`ratedDelta`), the block the win mined (`block`) and the result's
 *   page (`url`); a series names the score and, for a team, the clan
 *   (`winnerLogoRef`, `team` faces; no `winnerRef`, so no single player is
 *   tagged for a team's win). A win that won its winner a finished
 *   tournament names it (`tournament`, `final` when a knockout's final
 *   decided it) and links it.
 * - `climbers`: the three biggest Elo gains of the last seven days over
 *   every player ladder of every game (casual and rated; sum of the live
 *   rating changes, gains only), with the games they came from.
 * - `signups`: the six newest sign-ups of tournaments open for sign-up
 *   (withdrawn and removed ones left out), with the tournament and its pot.
 * - `prizes`: the open tournament with the biggest pot, and what each place
 *   wins (PrizePool::projection(), after the fee reserve) and its sponsors.
 * - `block`: the newest block of the season chain that stands (not voided):
 *   its height, its miners and whom they beat, the reward per miner, the
 *   ladder, and the blocks the season and the first miner have mined.
 * - `strongest`: the top five of the cross-game Strongest list (P40) of the
 *   live season, with the games each played.
 * - `rankUps`: the three newest rank-ups (a step up of a rank badge, the
 *   first reveal included) of the last seven days, one per player.
 * - `streaks`: the three longest win streaks still running over chess, the
 *   board games and the series (a player's side of a Rocket League, EA FC
 *   or Age of Empires II series; a forfeit counts neither way) of the last
 *   STREAK_DAYS days, STREAK wins or more.
 * - `payouts`: the latest season that paid its miners: total, players, and
 *   the three biggest payouts.
 * - `inviters`: the three players who brought the most new players in the
 *   last INVITE_DAYS days (InviteLinkUse `was_new`).
 *
 * Cached like StreamStats (plain arrays with picture refs); frame() turns the
 * refs into data URIs from the daemon's memory.
 */
class PrideSlides
{
    public const CACHE_KEY = 'twentyone.stream.pride';

    /** Days a win, a climb or a rank-up counts as recent. */
    public const DAYS = 7;

    /** Days a win streak and an invite are read back. */
    public const STREAK_DAYS = 30;

    public const INVITE_DAYS = 30;

    /** Wins in a row that make a streak (as a clan's streak, ClanPride::STREAK). */
    public const STREAK = 3;

    /** Games read at most (newest first) for the streaks. */
    private const STREAK_GAMES = 2000;

    public function __construct(private StreamImages $images, private GameRegistry $games, private PrizePool $pools) {}

    /**
     * @return array<string, mixed> the keys of read(), pictures as data URIs
     */
    public function all(): array
    {
        $seconds = max(1, (int) config('twentyone.stream.stats.cache_seconds', 15));

        try {
            $data = Cache::remember(self::CACHE_KEY, $seconds, fn (): array => $this->read());
        } catch (Throwable $e) {
            report($e);

            $data = $this->read();
        }

        return $this->frame($data);
    }

    /**
     * @return array{win: array<string, mixed>|null, climbers: list<array<string, mixed>>, signups: list<array<string, mixed>>, prizes: array<string, mixed>|null, block: array<string, mixed>|null, strongest: array<string, mixed>|null, rankUps: list<array<string, mixed>>, streaks: list<array<string, mixed>>, payouts: array<string, mixed>|null, inviters: list<array<string, mixed>>}
     */
    public function read(): array
    {
        return [
            'win' => $this->win(), 'climbers' => $this->climbers(), 'signups' => $this->signups(), 'prizes' => $this->prizes(),
            // The season chain, the rank badges and the invites are younger than the slides: a failing read costs its slide only.
            'block' => $this->guarded('block', null), 'strongest' => $this->guarded('strongest', null), 'rankUps' => $this->guarded('rankUps', []),
            'streaks' => $this->guarded('streaks', []), 'payouts' => $this->guarded('payouts', null), 'inviters' => $this->guarded('inviters', []),
        ];
    }

    /**
     * One of the readers below, its `$empty` value when it throws.
     *
     * @template T
     *
     * @param  T  $empty
     * @return mixed|T
     */
    private function guarded(string $reader, mixed $empty): mixed
    {
        try {
            return $this->{$reader}();
        } catch (Throwable $e) {
            report($e);

            return $empty;
        }
    }

    /**
     * The latest decided result: of the recent ones (DAYS) the latest, else
     * the latest ever, over chess, the board games and the series.
     *
     * @return array<string, mixed>|null
     */
    private function win(): ?array
    {
        $since = now()->subDays(self::DAYS);
        $candidates = array_filter([
            $this->latestChess($since),
            // A failing board game or series read (their tables, their tournament) costs that win only, never the pride slides.
            $this->guarded('latestBoard', null),
            $this->guarded('latestSeries', null),
        ]);

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => [$b[0]->gte($since), $b[0]->getTimestamp()] <=> [$a[0]->gte($since), $a[0]->getTimestamp()]);

        try {
            return $this->describe($candidates[0][1]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return array{0: CarbonInterface, 1: ChessGame}|null
     */
    private function latestChess(CarbonInterface $since): ?array
    {
        $decided = fn () => ChessGame::query()->where('status', ChessGameStatus::Finished)->whereIn('result', ['1-0', '0-1'])->where($this->notForfeited(...))
            ->whereNotNull('white_id')->whereNotNull('black_id')->whereNotNull('ended_at')->with(['white', 'black'])->latest('ended_at')->latest('id');
        $game = $decided()->where('ended_at', '>=', $since)->first() ?? $decided()->first();

        return $game === null || $game->ended_at === null ? null : [$game->ended_at, $game];
    }

    /**
     * The latest decided board game of a board game that is switched on; null while none is or its page is not routed.
     *
     * @return array{0: CarbonInterface, 1: BoardGame}|null
     */
    private function latestBoard(): ?array
    {
        $slugs = array_keys($this->games->boards());

        if ($slugs === [] || ! Route::has('board.show')) {
            return null;
        }

        $since = now()->subDays(self::DAYS);
        $decided = fn () => BoardGame::query()->where('status', BoardGameStatus::Finished)->whereIn('result', ['1-0', '0-1'])->where($this->notForfeited(...))->whereIn('game', $slugs)
            ->whereNotNull('white_id')->whereNotNull('black_id')->whereNotNull('ended_at')->with(['white', 'black'])->latest('ended_at')->latest('id');
        $game = $decided()->where('ended_at', '>=', $since)->first() ?? $decided()->first();

        return $game === null || $game->ended_at === null ? null : [$game->ended_at, $game];
    }

    /**
     * The latest series with a winner that stands (StreamStats::decidedSeries()) of a registered series game.
     *
     * @return array{0: CarbonInterface, 1: SeriesMatch}|null
     */
    private function latestSeries(): ?array
    {
        $slugs = array_keys($this->games->series());

        if ($slugs === []) {
            return null;
        }

        $since = now()->subDays(self::DAYS);
        // A forfeit (a no-show) is a result, but nothing to be proud of: no pride for it.
        $decided = fn () => StreamStats::decidedSeries()->whereIn('winner', SeriesMatch::SIDES)->whereIn('game', $slugs)->whereNotNull('finished_at')
            ->where(fn ($query) => $query->whereNull('resolution')->orWhere('resolution', '!=', SeriesResolution::Forfeit))
            ->with(['challengerLineup.clan', 'challengedLineup.clan', 'latestReport'])->latest('finished_at')->latest('id');
        $match = $decided()->where('finished_at', '>=', $since)->first() ?? $decided()->first();

        return $match === null || $match->finished_at === null ? null : [$match->finished_at, $match];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describe(ChessGame|BoardGame|SeriesMatch $result): ?array
    {
        if ($result instanceof SeriesMatch) {
            return $this->seriesWin($result);
        }

        [$winner, $loser] = $result->result === '1-0' ? [$result->white, $result->black] : [$result->black, $result->white];

        if ($winner === null || $loser === null) {
            return null;
        }

        $chess = $result instanceof ChessGame;
        $source = $chess ? RatingChange::CHESS : RatingChange::BOARD;
        $tournament = $this->wonTournament($result, $winner);
        $url = $chess ? route('games.show', $result) : route('board.show', $result);

        return [
            'kind' => $chess ? 'chess' : 'board',
            'gameId' => $result->id,
            'winner' => PublicName::clean($winner->displayName()),
            'winnerRef' => StreamImages::avatarRef($winner),
            'loser' => PublicName::clean($loser->displayName()),
            'loserRef' => StreamImages::avatarRef($loser),
            // "Blitz chess", "Daily chess"; "Checkers blitz 5+3" for a board game: the pride note writes it in lower case.
            'mode' => $chess ? ($result->isCorrespondence() ? 'Daily chess' : 'Blitz chess')
                : $this->games->name($result->game).' '.mb_strtolower($this->games->mode($result->game, $result->mode)->name ?? $result->mode),
            'delta' => $this->delta($source, $result->id, 'user:'.$winner->id, Rating::CASUAL),
            'ratedDelta' => $this->delta($source, $result->id, 'user:'.$winner->id, Rating::RATED),
            'block' => $this->minedBlock($chess ? SeasonAttestation::CHESS : SeasonAttestation::BOARD, $result->id),
            'ago' => $result->ended_at?->diffForHumans(),
            'tournament' => $tournament === null ? null : PublicName::clean($tournament->name),
            // Won in a final (a knockout): this game decided it. A table (Swiss, round robin) was only its last game.
            'final' => $tournament !== null && $tournament->format->hasFinal(),
            'url' => $tournament === null ? $url : route('tournaments.show', $tournament),
        ];
    }

    /**
     * A won series: the winning side (a player on a player ladder, else the
     * lineup's clan with its players' faces), whom it beat, the score.
     *
     * @return array<string, mixed>
     */
    private function seriesWin(SeriesMatch $match): array
    {
        $side = (string) $match->winner;
        $other = SeriesMatch::otherSide($side);
        $ids = static fn (string $want): array => array_values(array_map(fn (array $seat): int => (int) $seat['user_id'], array_filter($match->countedRoster(), fn (array $seat): bool => $seat['side'] === $want)));
        $winners = User::query()->whereKey($ids($side) ?: $match->rosterSide($side))->get();
        $losers = User::query()->whereKey($ids($other) ?: $match->rosterSide($other))->get();
        $solo = $match->gameMode()->teamSize === 1;
        $winner = $solo ? $winners->first() : null;
        $loser = $solo ? $losers->first() : null;
        $clan = $solo ? null : $match->sideClan($side);
        $score = SeriesMatch::seriesScore($match->result_games);
        $subject = $solo && $winner !== null ? 'user:'.$winner->id : ($match->lineup($side) === null ? null : 'lineup:'.$match->lineup($side)->id);
        $tournament = $this->wonTournament($match, $winners->first());

        return [
            'kind' => 'series',
            'gameId' => $match->id,
            'winner' => PublicName::clean($winner?->displayName() ?? $match->sideName($side)),
            'winnerRef' => $winner === null ? null : StreamImages::avatarRef($winner),
            'winnerLogoRef' => $clan === null ? null : StreamImages::logoRef($clan),
            'winnerTag' => $solo ? null : $match->sideTag($side),
            'team' => $solo ? [] : array_values(array_filter($winners->take(4)->map(fn (User $user): ?array => StreamImages::avatarRef($user))->all())),
            'loser' => PublicName::clean($loser?->displayName() ?? $match->sideName($other)),
            'loserRef' => $loser === null ? null : StreamImages::avatarRef($loser),
            // The pride note (StreamBot\PrideNotes) words the win with `mode`; the slide prints the game's short title.
            'mode' => $this->games->name($match->game).' '.$match->mode,
            'shownMode' => GameTitle::of($match->game).' '.$match->mode,
            'score' => $score[$side] + $score[$other] > 0 ? $score[$side].'-'.$score[$other] : null,
            'delta' => $subject === null ? null : $this->delta(RatingChange::SERIES, $match->id, $subject, Rating::CASUAL),
            'ratedDelta' => $subject === null ? null : $this->delta(RatingChange::SERIES, $match->id, $subject, Rating::RATED),
            'block' => $this->minedBlock(SeasonAttestation::SERIES, $match->id),
            'ago' => $match->finished_at?->diffForHumans(),
            'tournament' => $tournament === null ? null : PublicName::clean($tournament->name),
            'final' => $tournament !== null && $tournament->format->hasFinal(),
            'url' => $tournament === null ? route('matches.show', $match) : route('tournaments.show', $tournament),
        ];
    }

    /**
     * The winner's live Elo change for this result in `$pool`, a gain only.
     */
    private function delta(string $source, int $sourceId, string $subject, string $pool): ?int
    {
        $delta = RatingChange::query()->where(['source' => $source, 'source_id' => $sourceId])
            ->whereHas('rating', fn ($rating) => $rating->where(['subject' => $subject, 'pool' => $pool]))
            ->value('delta');

        return is_numeric($delta) && (int) $delta > 0 ? (int) $delta : null;
    }

    /**
     * The height of the block this result mined, null when it mined none or the block was voided.
     */
    private function minedBlock(string $source, int $sourceId): ?int
    {
        try {
            $height = SeasonAttestation::query()->where(['source' => $source, 'source_id' => $sourceId])->whereNotNull('height')
                ->whereNotIn('id', SeasonBlockVoid::query()->select('season_attestation_id'))->orderByDesc('id')->value('height');
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return is_numeric($height) ? (int) $height : null;
    }

    /**
     * The tournament this result won its winner: a finished tournament whose
     * champion (TournamentChampion) the winner is part of, and this result
     * their last in it. Null for a casual result and every other tournament result.
     */
    private function wonTournament(ChessGame|BoardGame|SeriesMatch $result, ?User $winner): ?Tournament
    {
        $tournament = $result->tournamentMatch?->tournament;

        if ($winner === null || $tournament === null || $tournament->status !== TournamentStatus::Finished) {
            return null;
        }

        $matches = $tournament->matches()->select('id');
        $last = match (true) {
            $result instanceof SeriesMatch => SeriesMatch::query()->whereIn('tournament_match_id', $matches)->whereNotNull('finished_at')->latest('finished_at')->latest('id')->value('id'),
            $result instanceof ChessGame => ChessGame::query()->whereIn('tournament_match_id', $matches)->playedBy($winner)->whereNotNull('ended_at')->latest('ended_at')->latest('id')->value('id'),
            default => BoardGame::query()->whereIn('tournament_match_id', $matches)->playedBy($winner)->whereNotNull('ended_at')->latest('ended_at')->latest('id')->value('id'),
        };
        $champion = app(TournamentChampion::class)->of($tournament);

        return $last === $result->id && $champion !== null && in_array($winner->id, $champion->memberIds(), true) ? $tournament : null;
    }

    /**
     * The biggest Elo gains of the week over every player ladder, both pools.
     *
     * @return list<array<string, mixed>>
     */
    private function climbers(): array
    {
        $rows = $this->weekChanges()
            ->groupBy('ratings.user_id')
            ->selectRaw('ratings.user_id as user_id, sum(rating_changes.delta) as gain, count(*) as games')
            ->havingRaw('sum(rating_changes.delta) > 0')
            ->orderByDesc('gain')->orderByDesc('games')->orderBy('ratings.user_id')
            ->limit(3)->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $users = User::query()->whereIn('id', $ids)->get()->keyBy('id');
        // The ladders each climb came from, biggest gain first (the games for the slide, the top ladder for the note's link).
        $from = $this->weekChanges()->whereIn('ratings.user_id', $ids)
            ->groupBy('ratings.user_id', 'ratings.game', 'ratings.mode')
            ->selectRaw('ratings.user_id as user_id, ratings.game as game, ratings.mode as mode, sum(rating_changes.delta) as gain')
            ->orderByDesc('gain')->orderBy('ratings.game')->orderBy('ratings.mode')->toBase()->get()
            ->groupBy('user_id');
        $climbers = [];

        foreach ($rows as $row) {
            $userId = (int) $row->getAttribute('user_id');
            $user = $users->get($userId);

            if ($user !== null) {
                $climbers[] = [
                    'name' => PublicName::clean($user->displayName()), 'ref' => StreamImages::avatarRef($user),
                    'gain' => (int) $row->getAttribute('gain'), 'games' => (int) $row->getAttribute('games'),
                    'from' => array_values(array_unique(array_map(fn ($entry): string => GameTitle::of((string) $entry->game), ($from->get($userId) ?? collect())->all()))),
                    'ladders' => array_values(array_map(fn ($entry): string => $entry->game.'/'.$entry->mode, ($from->get($userId) ?? collect())->all())),
                ];
            }
        }

        return $climbers;
    }

    /**
     * A chess or board game not decided by forfeit (a missed first move, a
     * withdrawal: ChessEndReason / BoardEndReason `forfeit`): a result, but
     * no win to be proud of, and no link in a streak either.
     *
     * @param  Builder<ChessGame>|Builder<BoardGame>  $query
     */
    private function notForfeited(Builder $query): void
    {
        $query->whereNull('end_reason')->orWhere('end_reason', '!=', ChessEndReason::Forfeit->value);
    }

    /**
     * The live rating changes of the last DAYS days on the player ladders of
     * the registered games (a board game switched off is not one), without
     * the results a fair play link voided: AccountLinks keeps the casual Elo
     * of a voided game, but it is no climb.
     *
     * @return Builder<RatingChange>
     */
    private function weekChanges(): Builder
    {
        return RatingChange::query()->where('rating_changes.created_at', '>=', now()->subDays(self::DAYS))
            ->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
            ->whereNotNull('ratings.user_id')
            ->whereIn('ratings.game', array_keys($this->games->all()))
            ->whereNotExists(fn ($void) => $void->selectRaw('1')->from('fair_play_voids')
                ->whereColumn('fair_play_voids.source', 'rating_changes.source')
                ->whereColumn('fair_play_voids.source_id', 'rating_changes.source_id'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function signups(): array
    {
        $signups = TournamentSignup::query()->whereNull('withdrawn_at')->whereNull('removed_at')->whereNotNull('user_id')
            ->whereHas('tournament', fn ($tournament) => $tournament->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now()))
            ->with(['user', 'tournament'])->latest('created_at')->latest('id')->limit(6)->get();
        $rows = [];

        foreach ($signups as $signup) {
            if ($signup->user === null) {
                continue;
            }

            $rows[] = [
                'name' => PublicName::clean($signup->user->displayName()),
                'ref' => StreamImages::avatarRef($signup->user),
                'tournament' => PublicName::clean($signup->tournament->name),
                'pot' => $this->pools->shownPotSats($signup->tournament),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function prizes(): ?array
    {
        $best = null;

        foreach (Tournament::query()->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())->get() as $tournament) {
            $pot = $this->pools->shownPotSats($tournament);

            if ($pot !== null && ($best === null || $pot > $best[1])) {
                $best = [$tournament, $pot];
            }
        }

        if ($best === null) {
            return null;
        }

        [$tournament, $pot] = $best;
        $timezone = (string) config('twentyone.stream.stats.timezone', 'Europe/Berlin');

        return [
            'name' => PublicName::clean($tournament->name),
            'game' => GameTitle::of($tournament->game),
            'mode' => $this->games->mode($tournament->game, $tournament->mode)->name ?? $tournament->mode,
            'pot' => $pot,
            'places' => array_map(fn (array $place): array => ['place' => $place['place'], 'sats' => $place['sats']], $this->pools->projection($tournament)),
            'sponsors' => $tournament->sponsors()->orderBy('id')->limit(3)->pluck('name')->map(fn ($name): string => PublicName::clean((string) $name))->all(),
            'startsAt' => $tournament->starts_at->copy()->timezone($timezone)->format('D j M, H:i T'),
            // A full https link: the pride note needs one (StreamBotCopy::violations), the slide drops the scheme.
            'url' => route('tournaments.show', $tournament),
        ];
    }

    /**
     * The newest block that stands, of the newest season with one.
     *
     * @return array<string, mixed>|null
     */
    private function block(): ?array
    {
        $voided = SeasonBlockVoid::query()->select('season_attestation_id');
        // Only a registered game's block: a board game switched off shows none.
        $block = SeasonAttestation::query()->whereNotNull('height')->whereNotIn('id', $voided)->whereIn('game', array_keys($this->games->all()))
            ->with('season')->orderByDesc('season_id')->orderByDesc('height')->orderByDesc('id')->first();

        if ($block === null) {
            return null;
        }

        $pubkeys = [...$block->winners(), ...array_map(strval(...), (array) ($block->candidate['losers'] ?? []))];
        $users = User::query()->whereIn('pubkey', $pubkeys)->get()->keyBy('pubkey');
        $person = fn (string $pubkey): array => ['name' => PublicName::clean($users->get($pubkey)?->displayName() ?? 'npub1…'.substr($pubkey, -4)), 'ref' => StreamImages::avatarRef($users->get($pubkey))];
        $miners = array_map($person, array_slice($block->winners(), 0, 3));
        $first = $block->winners()[0] ?? null;
        $mined = SeasonAttestation::query()->where('season_id', $block->season_id)->whereNotNull('height')->whereNotIn('id', $voided);

        return [
            'height' => (int) $block->height,
            'season' => $block->season->slug,
            'reward' => $block->reward_per_player,
            'label' => $block->label,
            'ladder' => GameTitle::ladder($block->game, $block->mode),
            'miners' => $miners,
            'beat' => array_map(fn (array $loser): string => $loser['name'], array_map($person, array_slice(array_map(strval(...), (array) ($block->candidate['losers'] ?? [])), 0, 3))),
            'seasonBlocks' => $mined->count(),
            // The first miner's blocks this season, this one included.
            'minerBlocks' => $first === null ? null : (clone $mined)->where('candidate', 'like', '%'.$first.'%')->get(['candidate'])
                ->filter(fn (SeasonAttestation $row): bool => in_array($first, $row->winners(), true))->count(),
            'ago' => $block->attested_at->diffForHumans(),
            'live' => ! $block->season->ends_at->isPast(),
        ];
    }

    /**
     * The top five of the Strongest list of the live season.
     *
     * @return array<string, mixed>|null
     */
    private function strongest(): ?array
    {
        $list = StrongestList::current();

        if ($list->season === null) {
            return null;
        }

        $top = $list->top(5);
        $rows = [];

        foreach ($top['rows'] as $row) {
            arsort($row['games']);
            $rows[] = [
                'place' => $row['place'],
                'name' => PublicName::clean($row['user']->displayName()),
                'ref' => StreamImages::avatarRef($row['user']),
                'rating' => $row['rating'],
                // The registered games only: a board game switched off is not named.
                'games' => array_values(array_map(fn (string $slug): string => GameTitle::of($slug), array_filter(array_keys($row['games']), fn (string $slug): bool => $this->games->find($slug) !== null))),
                'clan' => $row['user']->clanMember?->clan?->clantag,
            ];
        }

        return ['season' => $list->season, 'ranked' => $top['ranked'], 'waiting' => $top['waiting'], 'rows' => $rows];
    }

    /**
     * The newest rank-ups of the week, one per player.
     *
     * @return list<array<string, mixed>>
     */
    private function rankUps(): array
    {
        $versions = RankBadgeVersion::query()->where('signed_at', '>=', now()->subDays(self::DAYS)->getTimestamp())
            ->with('badge.user')->orderByDesc('signed_at')->orderByDesc('id')->limit(40)->get();
        $rows = [];
        $order = array_flip(array_keys(RankTiers::fromConfig()->ascending()));

        $seen = [];

        foreach ($versions as $version) {
            $user = $version->badge->user;
            // Each badge's newest version decides: a step down after a rank-up leaves nothing to show.
            $newest = ! isset($seen[$version->rank_badge_id]);
            $seen[$version->rank_badge_id] = true;

            if (! $newest || $user === null || isset($rows[$user->id]) || $version->tier === RankTiers::Provisional || ! $version->isRankUp()
                // A board game switched off has no ladder to show.
                || $this->games->mode($version->badge->game, $version->badge->mode) === null
                // Only a tier the player still holds: a rank-up a correction took back is gone from the slide.
                || ($order[$version->badge->tier] ?? -1) < ($order[$version->tier] ?? PHP_INT_MAX)) {
                continue;
            }

            $rows[$user->id] = [
                'name' => PublicName::clean($user->displayName()),
                'ref' => StreamImages::avatarRef($user),
                'tier' => RankTiers::label($version->tier),
                'colour' => RankTiers::colour($version->tier),
                'previous' => $version->previous_tier === null || $version->previous_tier === RankTiers::Provisional ? null : RankTiers::label($version->previous_tier),
                'ladder' => GameTitle::ladder($version->badge->game, $version->badge->mode),
                'rating' => $version->rating,
            ];

            if (count($rows) === 3) {
                break;
            }
        }

        return array_values($rows);
    }

    /**
     * The longest win streaks still running (a draw or a loss ends one) over chess, the board games switched on and
     * the series: a series counts for the players seated on its sides (`series_match_players`), a forfeit and a
     * voided series not at all, as on the latest-win slide.
     *
     * @return list<array<string, mixed>>
     */
    private function streaks(): array
    {
        $since = now()->subDays(self::STREAK_DAYS);
        $columns = ['white_id', 'black_id', 'result', 'ended_at'];
        $chess = ChessGame::query()->where('status', ChessGameStatus::Finished)->whereIn('result', ['1-0', '0-1', '1/2-1/2'])->where($this->notForfeited(...))->where('ended_at', '>=', $since)
            ->orderByDesc('ended_at')->orderByDesc('id')->limit(self::STREAK_GAMES)->toBase()->get([...$columns]);
        $slugs = array_keys($this->games->boards());
        $boards = $slugs === [] ? collect() : BoardGame::query()->where('status', BoardGameStatus::Finished)->whereIn('game', $slugs)->whereIn('result', ['1-0', '0-1', '1/2-1/2'])->where($this->notForfeited(...))
            ->where('ended_at', '>=', $since)->orderByDesc('ended_at')->orderByDesc('id')->limit(self::STREAK_GAMES)->toBase()->get([...$columns, 'game']);
        /** @var list<array{at: string, game: string, players: array<int, bool>}> $results a result each: who played it, and whether they won */
        $results = [];

        foreach ($chess->concat($boards) as $game) {
            $players = [];

            foreach (['1-0' => [$game->white_id, $game->black_id], '0-1' => [$game->black_id, $game->white_id], '1/2-1/2' => [$game->white_id, $game->black_id]][$game->result] as $index => $userId) {
                if ($userId !== null) {
                    $players[(int) $userId] = $game->result !== '1/2-1/2' && $index === 0;
                }
            }

            $results[] = ['at' => (string) $game->ended_at, 'game' => (string) ($game->game ?? 'chess'), 'players' => $players];
        }

        $series = array_keys($this->games->series());
        $matches = $series === [] ? collect() : StreamStats::decidedSeries()->whereIn('winner', SeriesMatch::SIDES)->whereIn('game', $series)->where('finished_at', '>=', $since)
            ->where(fn ($query) => $query->whereNull('resolution')->orWhere('resolution', '!=', SeriesResolution::Forfeit))
            ->orderByDesc('finished_at')->orderByDesc('id')->limit(self::STREAK_GAMES)->toBase()->get(['id', 'game', 'winner', 'finished_at']);
        $seats = $matches->isEmpty() ? collect() : DB::table('series_match_players')->whereIn('series_match_id', $matches->pluck('id')->all())->get(['series_match_id', 'user_id', 'side'])->groupBy('series_match_id');

        foreach ($matches as $match) {
            $players = [];

            foreach ($seats->get($match->id) ?? [] as $seat) {
                $players[(int) $seat->user_id] = $seat->side === $match->winner;
            }

            if ($players !== []) {
                $results[] = ['at' => (string) $match->finished_at, 'game' => (string) $match->game, 'players' => $players];
            }
        }

        usort($results, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
        /** @var array<int, array{run: int, open: bool, at: string, games: array<string, true>}> $streaks */
        $streaks = [];

        foreach ($results as $result) {
            foreach ($result['players'] as $userId => $won) {
                $streak = $streaks[$userId] ?? ['run' => 0, 'open' => true, 'at' => $result['at'], 'games' => []];

                if ($streak['open']) {
                    $streak['open'] = $won;

                    if ($won) {
                        $streak['run']++;
                        $streak['games'][GameTitle::of($result['game'])] = true;
                    }
                }

                $streaks[$userId] = $streak;
            }
        }

        $streaks = array_filter($streaks, fn (array $streak): bool => $streak['run'] >= self::STREAK);
        uksort($streaks, fn (int $a, int $b): int => [$streaks[$b]['run'], $streaks[$b]['at'], $a] <=> [$streaks[$a]['run'], $streaks[$a]['at'], $b]);
        $top = array_slice($streaks, 0, 3, true);
        $users = User::query()->whereKey(array_keys($top))->get()->keyBy('id');
        $rows = [];

        foreach ($top as $userId => $streak) {
            $user = $users->get($userId);

            if ($user !== null) {
                $rows[] = ['name' => PublicName::clean($user->displayName()), 'ref' => StreamImages::avatarRef($user), 'wins' => $streak['run'], 'games' => array_keys($streak['games'])];
            }
        }

        return $rows;
    }

    /**
     * The latest season that paid its miners: the paid sum, the players paid, the three biggest payouts.
     *
     * @return array<string, mixed>|null
     */
    private function payouts(): ?array
    {
        $seasonId = SeasonPayout::query()->where('status', PayoutStatus::Paid)->max('season_id');
        $season = $seasonId === null ? null : Season::query()->whereKey($seasonId)->first();

        if ($season === null) {
            return null;
        }

        $paid = SeasonPayout::query()->where('season_id', $season->id)->where('status', PayoutStatus::Paid);
        $top = (clone $paid)->with('user')->orderByDesc('amount_sats')->orderBy('id')->limit(3)->get();

        return [
            'season' => $season->slug,
            'total' => (int) (clone $paid)->sum('amount_sats'),
            'players' => (clone $paid)->count(),
            'rows' => array_values($top->values()->map(fn (SeasonPayout $payout, int $index): array => [
                'rank' => $index + 1,
                'name' => PublicName::clean($payout->user?->displayName() ?? $payout->name),
                'ref' => StreamImages::avatarRef($payout->user),
                'sats' => $payout->amount_sats,
                'blocks' => $payout->blocks,
            ])->all()),
        ];
    }

    /**
     * The players who brought the most new players with their links.
     *
     * @return list<array<string, mixed>>
     */
    private function inviters(): array
    {
        $rows = InviteLinkUse::query()->where('was_new', true)->where('created_at', '>=', now()->subDays(self::INVITE_DAYS))
            ->groupBy('inviter_id')->selectRaw('inviter_id, count(*) as brought')
            ->orderByDesc('brought')->orderBy('inviter_id')->limit(3)->toBase()->get();
        $users = User::query()->whereKey($rows->pluck('inviter_id')->all())->get()->keyBy('id');
        $inviters = [];

        foreach ($rows as $row) {
            $user = $users->get((int) $row->inviter_id);

            if ($user !== null) {
                $inviters[] = ['name' => PublicName::clean($user->displayName()), 'ref' => StreamImages::avatarRef($user), 'brought' => (int) $row->brought];
            }
        }

        return $inviters;
    }

    /**
     * read() data (fresh or cached) with its picture refs turned into data URIs, as the views take it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function framed(array $data): array
    {
        return $this->frame($data);
    }

    /**
     * The cached data with its picture refs turned into data URIs: `ref` of
     * every row becomes `avatar`, the win's refs `winnerAvatar`,
     * `loserAvatar`, `winnerLogo` and `teamAvatars`. Data cached before a
     * key existed (just after a deploy) gives that slide's empty state.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function frame(array $data): array
    {
        $win = is_array($data['win'] ?? null) ? $data['win'] : null;

        if ($win !== null) {
            $win['winnerAvatar'] = $this->images->avatar($win['winnerRef'] ?? null);
            $win['loserAvatar'] = $this->images->avatar($win['loserRef'] ?? null);
            $win['winnerLogo'] = $this->images->logo(is_string($win['winnerLogoRef'] ?? null) ? $win['winnerLogoRef'] : null);
            $win['teamAvatars'] = array_map(fn (mixed $ref): ?string => $this->images->avatar($ref), is_array($win['team'] ?? null) ? $win['team'] : []);
            unset($win['winnerRef'], $win['loserRef'], $win['winnerLogoRef'], $win['team']);
        }

        $block = is_array($data['block'] ?? null) ? $data['block'] : null;

        if ($block !== null) {
            $block['miners'] = $this->pictured(is_array($block['miners'] ?? null) ? $block['miners'] : []);
        }

        $strongest = is_array($data['strongest'] ?? null) ? $data['strongest'] : null;

        if ($strongest !== null) {
            $strongest['rows'] = $this->pictured(is_array($strongest['rows'] ?? null) ? $strongest['rows'] : []);
        }

        $payouts = is_array($data['payouts'] ?? null) ? $data['payouts'] : null;

        if ($payouts !== null) {
            $payouts['rows'] = $this->pictured(is_array($payouts['rows'] ?? null) ? $payouts['rows'] : []);
        }

        return [
            'win' => $win,
            'climbers' => $this->pictured(is_array($data['climbers'] ?? null) ? $data['climbers'] : []),
            'signups' => $this->pictured(is_array($data['signups'] ?? null) ? $data['signups'] : []),
            'prizes' => is_array($data['prizes'] ?? null) ? $data['prizes'] : null,
            'block' => $block,
            'strongest' => $strongest,
            'rankUps' => $this->pictured(is_array($data['rankUps'] ?? null) ? $data['rankUps'] : []),
            'streaks' => $this->pictured(is_array($data['streaks'] ?? null) ? $data['streaks'] : []),
            'payouts' => $payouts,
            'inviters' => $this->pictured(is_array($data['inviters'] ?? null) ? $data['inviters'] : []),
        ];
    }

    /**
     * Rows with their `ref` turned into `avatar`.
     *
     * @param  array<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function pictured(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $row['avatar'] = $this->images->avatar($row['ref'] ?? null);
            unset($row['ref']);
            $out[] = $row;
        }

        return $out;
    }
}
