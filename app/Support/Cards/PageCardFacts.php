<?php

namespace App\Support\Cards;

use App\Enums\ChessGameStatus;
use App\Enums\PayoutStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\ChessMove;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\Rating;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Clans\ClanLogos;
use App\Support\GameNames;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Players\PlayerStats;
use App\Support\Players\RecentResults;
use App\Support\Prizes\PrizePool;
use App\Support\Rating\RankTiers;
use App\Support\Rating\Ratings;
use App\Support\Rating\StrongestList;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\Seasons;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\TournamentSignups;
use App\Support\TwentyOne\LiveStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * What the link preview of each public page shows (P54, PageCard): the
 * facts are read here, the card only draws them, and their hash is the
 * card's file name and the `v` of its URL. A new result, a new pot, a new
 * face is a new hash, so a link shared after the game ended shows the end.
 *
 * Public by construction: only what the page itself shows a guest. Never
 * read here: a Lightning address, gamer tags, "looking to play", a wallet
 * connection or balance (a pot is the pot as the tournament sets it, and a
 * prize is what the league paid), any chat. PageCardsTest greps this file
 * and the card for those names.
 *
 * Raw values, no translated text: the card translates when it draws, so
 * both languages share one set of facts per page.
 */
final class PageCardFacts
{
    /** Faces of a podium, a lineup or a list. */
    public const FACES = 3;

    /**
     * Seconds the counts of a fixed page (home, lists, a game's hub) are
     * kept: the home page is rendered most, and a count a minute old is still
     * the right picture. The cards of a game, tournament, player, clan,
     * series or ladder read their facts fresh on every request.
     */
    public const COUNTS_TTL = 60;

    /* ---------- Chess game ------------------------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    public static function game(ChessGame $game): array
    {
        $game->loadMissing(['white', 'black', 'tournamentMatch.tournament']);
        $ratings = Ratings::forChessGame($game);
        $last = $game->ply > 0 ? ChessMove::query()->where('chess_game_id', $game->id)->orderByDesc('ply')->value('uci') : null;
        $tournament = $game->tournamentMatch?->tournament;

        $side = fn (User $user, array $rating): array => [
            ...self::person($user),
            'rating' => $rating['delta'] !== null && $rating['before'] !== null ? $rating['before'] + $rating['delta'] : $rating['rating'],
            'delta' => $rating['delta'],
            'provisional' => $rating['provisional'],
        ];

        return [
            'number' => $game->number !== null ? $game->number() : null,
            'daily' => $game->isCorrespondence(),
            'rated' => (bool) $game->rated,
            'status' => $game->status->value,
            'result' => $game->status === ChessGameStatus::Finished ? $game->result : null,
            'reason' => $game->status === ChessGameStatus::Active ? null : $game->end_reason?->value,
            'fen' => $game->fen,
            'last' => is_string($last) ? $last : null,
            'ply' => (int) $game->ply,
            'white' => $side($game->white, $ratings['w']),
            'black' => $side($game->black, $ratings['b']),
            'tournament' => $tournament !== null && $tournament->isVisibleTo(null) ? $tournament->name : null,
        ];
    }

    /* ---------- Tournament ------------------------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    public static function tournament(Tournament $tournament): array
    {
        $places = app(TournamentSignups::class)->places($tournament);
        // The pot as the tournament sets it (the fixed prizes' sum, else the target) and what is still to be won
        // after the prizes paid so far: the "X of Y sats" rule. Never the wallet's balance, not even as a fallback.
        $pot = $tournament->pool_opened_at === null || ! $tournament->hasOwnWallet() ? null
            : ($tournament->prizeMode() === Tournament::PRIZES_FIXED ? PrizePool::fixedTotal($tournament) : $tournament->prize_target_sats);
        $pot = $pot !== null && $pot > 0 ? (int) $pot : null;
        $cup = $tournament->isCasualCup();
        $zone = $cup ? CasualCups::timezoneOf($tournament) : (string) config('esports.preseason.display_timezone');
        $starts = $tournament->starts_at;

        return [
            'name' => $tournament->name,
            'game' => $tournament->game,
            'mode' => $tournament->mode,
            'format' => $tournament->format->value,
            'status' => $tournament->isSignupOpen() ? 'open' : $tournament->status->value,
            'cup' => $cup,
            'region' => $cup ? CasualCups::regionLabel($tournament) : null,
            'starts_utc' => $starts->copy()->utc()->format('Y-m-d H:i'),
            'starts_local' => $starts->copy()->timezone($zone)->format('H:i T'),
            'taken' => $places['taken'],
            'places' => $places['places'],
            'teams' => $tournament->profile()->entersTeams(),
            'cover' => ($cover = app(GameRegistry::class)->coverPath($tournament->game)) === null ? null : basename($cover),
            'pot' => $pot,
            'left' => $pot === null ? null : max(0, $pot - PrizePool::paidSats($tournament)),
            'podium' => $tournament->status === TournamentStatus::Finished ? self::podium($tournament) : [],
        ];
    }

    /**
     * The first three places of a finished tournament, with the prize the
     * league paid each entry (null when nothing was paid to it).
     *
     * @return list<array{place: int, name: string, pubkey: string, avatar_path: string|null, paid: int|null}>
     */
    private static function podium(Tournament $tournament): array
    {
        $places = app(TournamentPlacements::class)->of($tournament) ?? [];
        $ids = [];

        foreach ($places as $row) {
            foreach ($row['participants'] as $id) {
                if (count($ids) < self::FACES) {
                    $ids[$id] = $row['place'];
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $entries = TournamentParticipant::query()->whereKey(array_keys($ids))->with('user')->get()->keyBy('id');
        $paid = TournamentPayout::query()->where('tournament_id', $tournament->id)->where('status', PayoutStatus::Paid)
            ->whereIn('participant_id', array_keys($ids))->selectRaw('participant_id, sum(amount_sats) as sats')->groupBy('participant_id')
            ->pluck('sats', 'participant_id');
        $members = User::query()->whereKey($entries->flatMap(fn (TournamentParticipant $entry): array => array_slice($entry->memberIds(), 0, 1))->all())->get()->keyBy('id');
        $podium = [];

        foreach ($ids as $id => $place) {
            $entry = $entries->get($id);

            if (! $entry instanceof TournamentParticipant) {
                continue;
            }

            $face = $entry->user ?? $members->get($entry->memberIds()[0] ?? 0);
            $podium[] = [
                'place' => $place,
                'name' => $entry->name,
                'pubkey' => $face instanceof User ? $face->pubkey : str_repeat('0', 64),
                'avatar_path' => $face instanceof User ? self::avatar($face) : null,
                'paid' => isset($paid[$id]) && (int) $paid[$id] > 0 ? (int) $paid[$id] : null,
            ];
        }

        return $podium;
    }

    /* ---------- Player ----------------------------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    public static function player(User $user): array
    {
        $user->loadMissing('clanMember.clan');
        $stats = new PlayerStats($user);
        $ladders = $stats->ladders();
        $order = array_keys(RankTiers::fromConfig()->ascending());
        $best = null;
        [$wins, $draws, $losses] = [0, 0, 0];

        foreach ($ladders as $ladder) {
            $summary = $ladder['rating'];
            $wins += $summary['wins'];
            $draws += $summary['draws'];
            $losses += $summary['losses'];
            $rank = $summary['tier'] === null ? -1 : (int) array_search($summary['tier'], $order, true);

            if ($best === null || $rank > $best['rank'] || ($rank === $best['rank'] && $summary['rating'] > $best['rating'])) {
                $best = ['rank' => $rank, 'tier' => $summary['tier'], 'rating' => $summary['rating'], 'game' => $ladder['game'], 'mode' => $ladder['mode'],
                    'pool' => $summary['pool'], 'provisional' => $summary['provisional'], 'lineup' => $ladder['lineup']?->id];
            }
        }

        $latestWin = collect((new RecentResults($user))->latest(RecentResults::LIMIT))->firstWhere('outcome', 'win');

        return [
            ...self::person($user),
            'clan' => $user->clanMember?->clan?->name,
            'best' => $best === null ? null : [
                'tier' => $best['tier'],
                'rating' => $best['rating'],
                'game' => $best['game'],
                'mode' => $best['mode'],
                'provisional' => $best['provisional'],
                'place' => $best['lineup'] === null ? self::placeOf($user->id, $best['game'], $best['mode'], $best['pool'], $best['rating']) : null,
            ],
            'record' => ['wins' => $wins, 'draws' => $draws, 'losses' => $losses],
            'latest_win' => $latestWin === null ? null : [
                'opponent' => $latestWin['opponent'],
                'game' => $latestWin['game'],
                'score' => $latestWin['score'],
                'on' => $latestWin['at']?->format('Y-m-d'),
            ],
        ];
    }

    /** The player's place on a player ladder: rows rated higher, plus one. */
    private static function placeOf(int $userId, string $game, string $mode, string $pool, int $rating): ?int
    {
        $season = Ratings::season($pool, $game, $mode);

        if ($season === null) {
            return null;
        }

        $scope = Rating::query()->where(['pool' => $pool, 'season' => $season, 'game' => $game, 'mode' => $mode])->where('results', '>', 0)->whereNotNull('user_id');

        return (clone $scope)->where('rating', '>', $rating)->count() + 1;
    }

    /* ---------- Clan ------------------------------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    public static function clan(Clan $clan): array
    {
        $members = ClanMember::query()->where('clan_id', $clan->id)->with('user')->orderBy('id')->get();
        $logo = app(ClanLogos::class)->pathOf($clan->picture);

        return [
            'name' => $clan->name,
            'tag' => $clan->clantag,
            'logo' => $logo !== null && Storage::disk('public')->exists($logo) ? $logo : null,
            'members' => $members->count(),
            'faces' => array_values($members->take(6)->map(fn (ClanMember $member): array => self::person($member->user))->all()),
            'lineups' => $clan->lineups()->count(),
        ];
    }

    /* ---------- Series match ----------------------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    public static function series(SeriesMatch $match): array
    {
        $decided = $match->status->hasResult() && in_array($match->winner, ['challenger', 'challenged'], true);
        $score = $decided ? SeriesMatch::seriesScore($match->result_games) : null;
        $side = function (string $side) use ($match): array {
            $clan = $match->sideClan($side);
            $logo = app(ClanLogos::class)->pathOf($clan?->picture);

            return [
                'name' => $match->sideName($side),
                'tag' => $match->sideTag($side),
                'logo' => $logo !== null && Storage::disk('public')->exists($logo) ? $logo : null,
            ];
        };

        return [
            'number' => (int) $match->number,
            'game' => $match->game,
            'mode' => $match->mode,
            'rated' => (bool) $match->rated,
            'status' => $match->status->value,
            'best_of' => (int) ($match->best_of ?? 0),
            'challenger' => $side('challenger'),
            'challenged' => $side('challenged'),
            'winner' => $decided ? $match->winner : null,
            'score' => $score === null ? null : [(int) $score['challenger'], (int) $score['challenged']],
            'at' => $match->scheduledAt()?->copy()->utc()->format('Y-m-d H:i'),
        ];
    }

    /* ---------- Ladders ---------------------------------------------------------------------------------------- */

    /**
     * The top three of a ladder in the pool its page opens on.
     *
     * @return array<string, mixed>
     */
    public static function ladder(string $game, string $mode): array
    {
        $rated = Ratings::season(Rating::RATED, $game, $mode);
        $ratedRows = $rated !== null && Rating::query()->where(['pool' => Rating::RATED, 'season' => $rated, 'game' => $game, 'mode' => $mode])->where('results', '>', 0)->exists();
        $pool = $ratedRows ? Rating::RATED : Rating::CASUAL;
        $season = $ratedRows ? $rated : Ratings::season(Rating::CASUAL, $game, $mode);
        $rows = $season === null ? collect() : Rating::query()
            ->where(['pool' => $pool, 'season' => $season, 'game' => $game, 'mode' => $mode])->where('results', '>', 0)
            ->with(['user', 'lineup.clan'])->orderByDesc('rating')->orderByDesc('results')->orderBy('id')->limit(self::FACES)->get();
        $entries = $season === null ? 0 : Rating::query()->where(['pool' => $pool, 'season' => $season, 'game' => $game, 'mode' => $mode])->where('results', '>', 0)->count();
        $tiers = RankTiers::fromConfig();

        return [
            'game' => $game,
            'mode' => $mode,
            'pool' => $pool,
            'season' => $pool === Rating::RATED ? $season : null,
            'entries' => $entries,
            'cover' => ($cover = app(GameRegistry::class)->coverPath($game)) === null ? null : basename($cover),
            'top' => array_values($rows->map(function (Rating $row, int $index) use ($pool, $tiers): array {
                $clan = $row->user === null ? $row->lineup?->clan : null;
                $logo = app(ClanLogos::class)->pathOf($clan?->picture);

                return [
                    'place' => $index + 1,
                    'name' => $row->user?->displayName() ?? $clan->name ?? '?',
                    'pubkey' => $row->user instanceof User ? $row->user->pubkey : null,
                    'avatar_path' => $row->user instanceof User ? self::avatar($row->user) : null,
                    // A lineup ladder shows the clan: its logo, else its tag tile.
                    'tag' => $clan?->clantag,
                    'logo' => $logo !== null && Storage::disk('public')->exists($logo) ? $logo : null,
                    'rating' => (int) $row->rating,
                    'tier' => $pool === Rating::RATED ? $tiers->tierFor((int) $row->rating, (int) $row->results) : null,
                ];
            })->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function strongest(): array
    {
        $list = StrongestList::current();
        $top = $list->top(self::FACES);

        return [
            'season' => $list->season,
            'ranked' => $top['ranked'],
            'top' => array_map(fn (array $row): array => [
                'place' => $row['place'],
                ...self::person($row['user']),
                'rating' => $row['rating'],
                'tier' => null,
            ], $top['rows']),
        ];
    }

    /* ---------- Season chain ----------------------------------------------------------------------------------- */

    /**
     * The supply mined of the live season (else the last one, as it closed),
     * read from the replayed chain and cached per season and latest block.
     *
     * @return array<string, mixed>
     */
    public static function mining(): array
    {
        $season = Seasons::live() ?? Seasons::latest();

        if ($season === null) {
            return ['season' => null, 'state' => Seasons::state()];
        }

        $latest = (int) SeasonAttestation::query()->where('season_id', $season->id)->max('id');

        return Cache::remember('page-card:mining:'.$season->id.':'.$latest, now()->addDay(), function () use ($season): array {
            $chain = app(SeasonChains::class)->chain($season);
            $height = (int) SeasonAttestation::query()->where('season_id', $season->id)->whereNotNull('height')->max('height');

            return [
                'season' => $season->slug,
                'state' => Seasons::state(),
                'live' => ! $season->ends_at->isPast() && $season->genesis_at->isPast(),
                'supply' => (int) $chain->season->supply,
                'mined' => (int) $chain->mined(),
                'height' => $height,
                'genesis' => $season->genesis_at->copy()->utc()->format('Y-m-d H:i'),
                'ends' => $season->ends_at->copy()->utc()->format('Y-m-d'),
            ];
        });
    }

    /* ---------- Live ------------------------------------------------------------------------------------------- */

    /**
     * On air or not, the running games (the first one's players), the
     * running tournaments and the stream's current slide.
     *
     * @return array<string, mixed>
     */
    public static function live(): array
    {
        $games = ChessGame::query()->where('status', ChessGameStatus::Active)->with(['white', 'black'])->latest('id')->limit(1)->get();
        $cover = self::streamSlide();

        return [
            'on_air' => LiveStatus::current()->live,
            'games' => ChessGame::query()->where('status', ChessGameStatus::Active)->count(),
            'first' => $games->isEmpty() ? null : ['white' => self::person($games[0]->white), 'black' => self::person($games[0]->black)],
            'tournaments' => array_values(Tournament::query()->where('status', TournamentStatus::Running)->whereNotNull('published_at')->orderBy('starts_at')->limit(2)->pluck('name')->all()),
            // The slide the stream daemon last rendered (public at /stream/cover.png); its time is part of the facts, so a new slide is a new card.
            'slide' => is_file($cover) ? (int) filemtime($cover) : null,
        ];
    }

    /** The stream's current slide, the file /stream/cover.png serves. */
    public static function streamSlide(): string
    {
        return (string) config('twentyone.stream.cover.path');
    }

    /* ---------- The other pages -------------------------------------------------------------------------------- */

    /**
     * Figures of a fixed page, each a real count: [label key, value].
     *
     * @return array<string, mixed>
     */
    public static function page(string $page): array
    {
        return Cache::remember('page-card:page:'.$page, now()->addSeconds(self::COUNTS_TTL), fn (): array => self::countPage($page));
    }

    /**
     * @return array<string, mixed>
     */
    private static function countPage(string $page): array
    {
        $registry = app(GameRegistry::class);
        $liveGames = fn (): int => ChessGame::query()->where('status', ChessGameStatus::Active)->count();
        $players = fn (): int => User::query()->count();
        $next = fn (): ?Tournament => Tournament::query()->special()->whereNotNull('published_at')->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing])
            ->whereNotNull('starts_at')->where('starts_at', '>', now())->orderBy('starts_at')->first();

        $figures = match ($page) {
            'home' => [['players', $players()], ['live-games', $liveGames()], ['tournaments-open', self::openTournaments()]],
            'clans' => [['clans', Clan::query()->count()], ['clan-players', ClanMember::query()->count()]],
            'matches' => [['series-played', SeriesMatch::query()->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->count()], ['games-played', ChessGame::query()->where('status', ChessGameStatus::Finished)->count()]],
            'games', 'chess' => [['live-games', $liveGames()], ['daily-games', ChessGame::query()->daily()->where('status', ChessGameStatus::Active)->count()], ['games-played', ChessGame::query()->where('status', ChessGameStatus::Finished)->count()]],
            'tournaments' => [['tournaments-open', self::openTournaments()], ['tournaments-running', Tournament::query()->whereNotNull('published_at')->where('status', TournamentStatus::Running)->count()], ['tournaments-finished', Tournament::query()->whereNotNull('published_at')->where('status', TournamentStatus::Finished)->count()]],
            'play', 'rules' => [['games', count($registry->all())], ['modes', array_sum(array_map(fn ($game): int => count($game->modes()), $registry->all()))]],
            // Without configured relays there is nothing to count; the figure is left out rather than drawn as 0.
            'protocol' => count((array) config('esports.relays', [])) > 0 ? [['relays', count((array) config('esports.relays', []))]] : [],
            default => [],
        };

        $upcoming = in_array($page, ['home', 'tournaments'], true) ? $next() : null;
        $board = in_array($page, ['games', 'chess'], true)
            ? ChessGame::query()->where('status', ChessGameStatus::Active)->latest('id')->first(['id', 'fen', 'ply'])
            : null;

        return [
            'page' => $page,
            'figures' => $figures,
            'next' => $upcoming === null ? null : ['name' => $upcoming->name, 'game' => $upcoming->game, 'starts_utc' => $upcoming->starts_at->copy()->utc()->format('Y-m-d H:i')],
            'games' => $page === 'play' ? array_keys($registry->all()) : [],
            // The page's own picture: the newest live board (chess pages), the biggest clans (clan list).
            'board' => $board === null ? null : [
                'fen' => $board->fen,
                'last' => $board->ply > 0 ? ChessMove::query()->where('chess_game_id', $board->id)->orderByDesc('ply')->value('uci') : null,
            ],
            'clans' => $page === 'clans' ? self::biggestClans() : [],
        ];
    }

    /**
     * A series game's hub (games/rocket-league …): lineups and series played.
     *
     * @return array<string, mixed>
     */
    public static function hub(string $game): array
    {
        return Cache::remember('page-card:hub:'.$game, now()->addSeconds(self::COUNTS_TTL), fn (): array => [
            'game' => $game,
            'cover' => ($cover = app(GameRegistry::class)->coverPath($game)) === null ? null : basename($cover),
            'modes' => array_keys(app(GameRegistry::class)->get($game)->modes()),
            'lineups' => Lineup::query()->where('game', $game)->count(),
            'series' => SeriesMatch::query()->where('game', $game)->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->count(),
        ]);
    }

    /**
     * The four clans with the most players: tag and logo.
     *
     * @return list<array{tag: string, logo: string|null}>
     */
    private static function biggestClans(): array
    {
        return array_values(Clan::query()->withCount('members')->orderByDesc('members_count')->orderBy('id')->limit(4)->get()
            ->map(function (Clan $clan): array {
                $logo = app(ClanLogos::class)->pathOf($clan->picture);

                return ['tag' => $clan->clantag, 'logo' => $logo !== null && Storage::disk('public')->exists($logo) ? $logo : null];
            })->all());
    }

    private static function openTournaments(): int
    {
        return Tournament::query()->whereNotNull('published_at')->where('status', TournamentStatus::Signup)->count();
    }

    /* ---------- Pieces ----------------------------------------------------------------------------------------- */

    /**
     * Name, key and uploaded picture: what every face on a card is drawn from.
     *
     * @return array{name: string, pubkey: string, avatar_path: string|null}
     */
    public static function person(User $user): array
    {
        return ['name' => $user->displayName(), 'pubkey' => $user->pubkey ?? str_repeat('0', 64), 'avatar_path' => self::avatar($user)];
    }

    /** The uploaded picture when its file is there (a missing file draws the Blockpile). */
    private static function avatar(User $user): ?string
    {
        return $user->avatar_path !== null && Storage::disk('public')->exists($user->avatar_path) ? $user->avatar_path : null;
    }

    /** A game and mode in the card's words ("Chess Blitz 5+3"). */
    public static function gameLabel(string $game, string $mode): string
    {
        return GameNames::full($game, $mode);
    }
}
