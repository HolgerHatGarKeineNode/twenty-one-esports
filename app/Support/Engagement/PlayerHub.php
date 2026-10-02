<?php

namespace App\Support\Engagement;

use App\Enums\ChessGameStatus;
use App\Enums\InviteStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Dock\DockItem;
use App\Support\Dock\OpenMatches;
use App\Support\GameNames;
use App\Support\Rating\Ratings;
use App\Support\Series\CasualLobby;
use App\Support\Series\CasualMatches;
use App\Support\Series\Ladders;
use App\Support\Series\SeriesPresenter;
use App\Support\Tournaments\MatchWait;
use App\Support\Tournaments\TournamentLanding;
use App\Support\Tournaments\TournamentWaits;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The player's own page, /me (P30): what needs them now, what runs and
 * what comes, where they stand, and who they play with. Read-only, and
 * built on the league's own definitions: the open matches are the match
 * dock's (OpenMatches, "needs you" included), a tournament match's
 * countdown is P18's (TournamentWaits), the ratings are the ladders'
 * (Ratings). Nothing here counts on its own.
 *
 * @phpstan-type Need array{item: DockItem|null, wait: MatchWait|null}
 * @phpstan-type Later array{key: string, href: string, name: string, face: User|null, clan: Clan|null, tag: string|null, title: string, state: string, at: CarbonInterface|null}
 * @phpstan-type Entry array{tournament: Tournament, state: 'signup'|'drawing'|'running', startsIn: array{ms: int, text: string}|null}
 * @phpstan-type Standing array{game: string, mode: string, label: string, lineup: Lineup|null, rating: array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string}, href: string}
 * @phpstan-type Result array{key: string, href: string, opponent: string, face: User|null, clan: Clan|null, tag: string|null, outcome: 'win'|'loss'|'draw', score: string, game: string, at: CarbonInterface|null, delta: int|null}
 * @phpstan-type Step array{key: string, label: string, href: string, done: bool}
 */
final class PlayerHub
{
    /** Recent results shown, newest first. */
    public const RESULTS = 10;

    /** @var Collection<int, DockItem>|null */
    private ?Collection $open = null;

    /** @var list<MatchWait>|null */
    private ?array $waits = null;

    /** @var Collection<int, int>|null */
    private ?Collection $lineups = null;

    public function __construct(private readonly User $user) {}

    /**
     * What is on this player now, in the dock's order, each with its
     * tournament countdown when the league decides it on its own (P18); a
     * tournament wait on them that has no tab of its own stands alone.
     *
     * @return list<Need>
     */
    public function needs(): array
    {
        [$attached, $loose] = $this->splitWaits();

        $needs = $this->open()->filter(fn (DockItem $item): bool => $item->needsYou)
            ->map(fn (DockItem $item): array => ['item' => $item, 'wait' => $attached[$item->href] ?? null])->values()->all();

        foreach ($loose as $wait) {
            if ($wait->waitsOn($this->user->id)) {
                $needs[] = ['item' => null, 'wait' => $wait];
            }
        }

        return array_values($needs);
    }

    /**
     * What runs without waiting on this player: live matches, and those
     * waiting for the other side or an admin, with their tournament countdown.
     *
     * @return list<Need>
     */
    public function going(): array
    {
        [$attached, $loose] = $this->splitWaits();

        $going = $this->open()->reject(fn (DockItem $item): bool => $item->needsYou)
            ->map(fn (DockItem $item): array => ['item' => $item, 'wait' => $attached[$item->href] ?? null])->values()->all();

        foreach ($loose as $wait) {
            if (! $wait->waitsOn($this->user->id)) {
                $going[] = ['item' => null, 'wait' => $wait];
            }
        }

        return array_values($going);
    }

    /**
     * What the dock leaves out: series scheduled more than an hour ahead
     * (casual 1v1s, cup matches, lineup series), and challenges this player
     * sent that wait for an answer. Soonest first, open challenges last.
     *
     * @return list<Later>
     */
    public function later(): array
    {
        $matches = OpenMatches::involving(SeriesMatch::query(), $this->user, $this->lineups())
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('status', SeriesStatus::Accepted)->where('start_at', '>', now()->addMilliseconds(OpenMatches::STARTS_SOON_MS)))
                ->orWhere('status', SeriesStatus::Open))
            ->with(['challengerLineup.clan', 'challengedLineup.clan'])
            ->orderBy('start_at')->orderBy('id')
            ->limit(OpenMatches::KIND_LIMIT)
            ->get();

        $faces = $this->rosterFaces($matches);
        $later = [];

        foreach ($matches as $match) {
            $side = $this->sideOf($match);

            if ($side === null) {
                continue;
            }

            $other = SeriesMatch::otherSide($side);
            $open = $match->status === SeriesStatus::Open;

            // An open challenge to this player is on the dock already ("Answer"); here only the ones they sent.
            if ($open && $side !== 'challenger') {
                continue;
            }

            $later[] = [
                'key' => 'later-'.$match->number,
                'href' => route($match->isCasualPairing() && ! $open ? 'matches.room' : 'matches.show', $match),
                'name' => $match->sideName($other),
                'face' => $faces[$match->rosterSide($other)[0] ?? 0] ?? null,
                'clan' => $match->sideClan($other),
                'tag' => $match->sideTag($other),
                'title' => $match->isCasualPairing() ? __('Casual 1v1').', '.GameNames::game($match->game) : GameNames::full($match->game, $match->mode),
                'state' => $open ? __('Waiting for their answer') : SeriesPresenter::time($match->start_at ?? now(), $this->user, 'D H:i'),
                'at' => $open ? null : $match->start_at,
            ];
        }

        return $later;
    }

    /**
     * The tournaments this player is in: signed up (sign-up or draw), or
     * playing now. Soonest first.
     *
     * @return list<Entry>
     */
    public function tournaments(): array
    {
        $signedUp = TournamentSignup::query()->active()
            ->where(fn ($query) => $query->where('user_id', $this->user->id)->orWhereJsonContains('members', $this->user->id))
            ->whereHas('tournament', fn ($query) => $query->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing]))
            ->pluck('tournament_id');

        $ids = $signedUp->concat($this->runningIds())->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return array_values(Tournament::query()->whereIn('id', $ids)->orderBy('starts_at')->get()
            ->map(fn (Tournament $tournament): array => [
                'tournament' => $tournament,
                'state' => match ($tournament->status) {
                    TournamentStatus::Running => 'running',
                    TournamentStatus::Drawing => 'drawing',
                    default => 'signup',
                },
                'startsIn' => (new TournamentLanding($tournament, $this->user))->startsIn(),
            ])
            ->sortBy(fn (array $entry): array => [$entry['state'] === 'running' ? 0 : 1, $entry['tournament']->starts_at->getTimestamp()])
            ->values()->all());
    }

    /**
     * Where this player stands in every game and mode they have results in:
     * the season ladder once it is open, the casual one before Block 0
     * (Ratings::headline()); a lineup ladder for each lineup they sit in.
     *
     * @return list<Standing>
     */
    public function ratings(): array
    {
        $registry = app(GameRegistry::class);
        $standings = [];

        // No score game (plan "AoE2 und Trackmania", P4): it has no Elo rating.
        foreach ($registry->versus() as $game) {
            foreach ($game->modes() as $mode) {
                if ($mode->rates !== 'player') {
                    continue;
                }

                $rating = Ratings::headline($this->user->id, $game->slug(), $mode->slug);

                if ($rating['results'] > 0) {
                    $standings[] = $this->standing($game->slug(), $mode->slug, null, $rating);
                }
            }
        }

        $lineups = Lineup::query()->whereIn('id', LineupSeat::query()->where('user_id', $this->user->id)->whereNotNull('accepted_at')->select('lineup_id'))
            ->with('clan')->get()
            ->filter(fn (Lineup $lineup): bool => $registry->mode($lineup->game, $lineup->mode)?->rates === 'lineup');

        foreach ($lineups as $lineup) {
            $pool = Ratings::pool(Ladders::isOpen($lineup->game, $lineup->mode));
            $rating = Ratings::forLineups([$lineup->id], $lineup->game, $lineup->mode, $pool)[$lineup->id] ?? Ratings::summary(null, $pool);

            if ($rating['results'] > 0) {
                $standings[] = $this->standing($lineup->game, $lineup->mode, $lineup, $rating);
            }
        }

        return $standings;
    }

    /**
     * The last results, chess games and series together, newest first, each
     * from this player's side with the opponent's face and, once a result
     * moved a rating, by how much.
     *
     * @return list<Result>
     */
    public function results(): array
    {
        $me = $this->user->id;

        $games = ChessGame::query()->where('status', ChessGameStatus::Finished)
            ->where(fn ($query) => $query->where('white_id', $me)->orWhere('black_id', $me))
            ->with(['white', 'black'])->latest('updated_at')->latest('id')->limit(self::RESULTS)->get();

        $series = OpenMatches::involving(SeriesMatch::query(), $this->user, $this->lineups())
            ->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->whereIn('winner', SeriesMatch::SIDES)
            ->with(['challengerLineup.clan', 'challengedLineup.clan'])
            ->latest('finished_at')->latest('id')->limit(self::RESULTS)->get();

        $deltas = $this->deltas(array_map(intval(...), $games->modelKeys()), array_map(intval(...), $series->modelKeys()));
        $faces = $this->rosterFaces($series);
        $results = [];

        foreach ($games as $game) {
            $white = $game->white_id === $me;
            $opponent = $white ? $game->black : $game->white;
            $outcome = match ($game->result) {
                '1-0' => $white ? 'win' : 'loss',
                '0-1' => $white ? 'loss' : 'win',
                default => 'draw',
            };

            $results[] = [
                'key' => 'chess-'.$game->id,
                'href' => route('games.show', $game),
                // A deleted player's side is ChessGame::deletedPlayer(), never null.
                'opponent' => $opponent->displayName(),
                'face' => $opponent,
                'clan' => null,
                'tag' => null,
                'outcome' => $outcome,
                'score' => match ($outcome) {
                    'win' => '1–0',
                    'loss' => '0–1',
                    default => '½–½',
                },
                'game' => GameNames::full('chess', $game->mode),
                'at' => $game->updated_at,
                'delta' => $deltas['chess:'.$game->id] ?? null,
            ];
        }

        foreach ($series as $match) {
            $side = $this->sideOf($match);

            if ($side === null) {
                continue;
            }

            $other = SeriesMatch::otherSide($side);
            $score = SeriesMatch::seriesScore($match->result_games);

            $results[] = [
                'key' => 'series-'.$match->number,
                'href' => route('matches.show', $match),
                'opponent' => $match->sideName($other),
                'face' => $faces[$match->rosterSide($other)[0] ?? 0] ?? null,
                'clan' => $match->sideClan($other),
                'tag' => $match->sideTag($other),
                'outcome' => $match->winner === $side ? 'win' : 'loss',
                'score' => $score[$side].' : '.$score[$other],
                'game' => $match->isCasualPairing() ? __('Casual 1v1').', '.GameNames::game($match->game) : GameNames::full($match->game, $match->mode),
                'at' => $match->finished_at ?? $match->updated_at,
                'delta' => $deltas['series:'.$match->id] ?? null,
            ];
        }

        usort($results, fn (array $a, array $b): int => ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0));

        return array_slice($results, 0, self::RESULTS);
    }

    /**
     * The clan this player is in with its lineups and their faces, else the
     * newest clan invite waiting for their answer.
     *
     * @return array{clan: Clan|null, captain: bool, lineups: Collection<int, Lineup>, invite: ClanInvite|null}
     */
    public function clan(): array
    {
        $clan = $this->user->clanMember?->clan;

        if ($clan === null) {
            return [
                'clan' => null, 'captain' => false, 'lineups' => collect(),
                'invite' => ClanInvite::query()->where('invitee_id', $this->user->id)->where('status', InviteStatus::Pending)->with('clan')->latest()->first(),
            ];
        }

        $clan->loadCount('members');
        $mine = LineupSeat::query()->where('user_id', $this->user->id)->whereNotNull('accepted_at')->pluck('lineup_id')->all();

        return [
            'clan' => $clan,
            'captain' => $clan->isCaptain($this->user),
            'lineups' => $clan->lineups()->with(['seats' => fn ($query) => $query->whereNotNull('accepted_at')->with('user')])->orderBy('game')->orderBy('mode')->get()
                ->sortBy(fn (Lineup $lineup): int => in_array($lineup->id, $mine, true) ? 0 : 1)->values(),
            'invite' => null,
        ];
    }

    /**
     * "Looking to play" per game that has it: chess blitz (the lobby) and
     * every game with casual 1v1 (its page). A player looks for one at a
     * time; the switch lives where the game is played.
     *
     * @return list<array{game: string, name: string, on: bool, href: string}>
     */
    public function looking(): array
    {
        $current = (string) $this->user->looking_to_play;
        $rows = [['game' => 'chess', 'name' => GameNames::full('chess', 'blitz'), 'on' => $current === 'chess/blitz', 'href' => route('chess.lobby')]];

        foreach (CasualLobby::games() as $game) {
            $rows[] = ['game' => $game, 'name' => GameNames::game($game), 'on' => $current === $game.'/'.CasualMatches::mode(), 'href' => GameNames::page($game).'#casual'];
        }

        return $rows;
    }

    /**
     * The three first steps into the league, each done once the player did it.
     * Gamer tags are no step (P51): they are optional, and keeping them private
     * is a choice, not an open task.
     *
     * @return list<Step>
     */
    public function steps(): array
    {
        $me = $this->user->id;
        $next = Tournament::query()->where('status', TournamentStatus::Signup)->whereNotNull('published_at')
            ->where('signup_closes_at', '>', now())->orderBy('starts_at')->first();

        return [
            ['key' => 'blitz', 'label' => __('Play a blitz game'), 'href' => route('chess.lobby'),
                'done' => ChessGame::query()->where('status', ChessGameStatus::Finished)->where(fn ($query) => $query->where('white_id', $me)->orWhere('black_id', $me))->exists()],
            ['key' => 'clan', 'label' => __('Join a clan'), 'href' => route('clans.index'),
                'done' => $this->user->clanMember !== null],
            ['key' => 'tournament', 'label' => __('Sign up for a tournament'), 'href' => $next !== null ? route('tournaments.show', $next) : route('tournaments.index'),
                'done' => TournamentSignup::query()->where(fn ($query) => $query->where('user_id', $me)->orWhereJsonContains('members', $me))->exists()
                    // A Blockfill week (P6) is joined by playing, not by signing up for a tournament.
                    || TournamentParticipant::query()->where(fn ($query) => $query->where('user_id', $me)->orWhereJsonContains('members', $me))
                        ->whereHas('tournament', fn ($query) => $query->exceptLeagueWeeks())->exists()],
        ];
    }

    /**
     * A countdown's first frame as the browser counts it
     * (resources/js/autoDecision.js): "04:12", "1:04:12", "2 d 04:12:00".
     */
    public static function clock(int $ms): string
    {
        $left = max(0, (int) ceil($ms / 1000));
        $clock = sprintf('%02d:%02d', intdiv($left % 3600, 60), $left % 60);

        return match (true) {
            $left >= 86400 => sprintf('%d d %02d:%s', intdiv($left, 86400), intdiv($left % 86400, 3600), $clock),
            $left >= 3600 => intdiv($left, 3600).':'.$clock,
            default => $clock,
        };
    }

    /**
     * @return Collection<int, DockItem>
     */
    private function open(): Collection
    {
        return $this->open ??= app(OpenMatches::class)->for($this->user);
    }

    /**
     * The tournament waits with a countdown, split into those that belong to
     * a dock tab (keyed by the tab's link) and those without one.
     *
     * @return array{0: array<string, MatchWait>, 1: list<MatchWait>}
     */
    private function splitWaits(): array
    {
        $hrefs = $this->open()->map(fn (DockItem $item): string => $item->href)->all();
        $attached = [];
        $loose = [];

        foreach ($this->waits() as $wait) {
            if (in_array($wait->url, $hrefs, true) && ! isset($attached[$wait->url])) {
                $attached[$wait->url] = $wait;
            } else {
                $loose[] = $wait;
            }
        }

        return [$attached, $loose];
    }

    /**
     * This player's open matches in running tournaments whose automatic
     * decision runs (the tournament page's own countdown, P18 slice 5).
     *
     * @return list<MatchWait>
     */
    private function waits(): array
    {
        if ($this->waits !== null) {
            return $this->waits;
        }

        $waits = [];

        foreach (Tournament::query()->whereIn('id', $this->runningIds())->get() as $tournament) {
            foreach (TournamentWaits::ofPlayer($tournament, $this->user) as $wait) {
                if ($wait->decidesAt !== null) {
                    $waits[] = $wait;
                }
            }
        }

        return $this->waits = $waits;
    }

    /**
     * @return Collection<int, int>
     */
    private function runningIds(): Collection
    {
        return TournamentParticipant::query()
            ->where(fn ($query) => $query->where('user_id', $this->user->id)->orWhereJsonContains('members', $this->user->id))
            ->whereHas('tournament', fn ($query) => $query->where('status', TournamentStatus::Running)->exceptLeagueWeeks())
            ->pluck('tournament_id')->map(intval(...))->unique()->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function lineups(): Collection
    {
        return $this->lineups ??= OpenMatches::lineupsOf($this->user);
    }

    /**
     * The side this player plays in a series: their roster side, else the
     * side of a lineup they sit in or whose clan they own.
     */
    private function sideOf(SeriesMatch $match): ?string
    {
        foreach (SeriesMatch::SIDES as $side) {
            if (in_array($this->user->id, $match->rosterSide($side), true)) {
                return $side;
            }
        }

        foreach (SeriesMatch::SIDES as $side) {
            $lineup = $side === 'challenger' ? $match->challenger_lineup_id : $match->challenged_lineup_id;

            if ($lineup !== null && $this->lineups()->contains((int) $lineup)) {
                return $side;
            }
        }

        return null;
    }

    /**
     * The users of every roster side of these series, loaded once.
     *
     * @param  Collection<int, SeriesMatch>  $matches
     * @return array<int, User>
     */
    private function rosterFaces(Collection $matches): array
    {
        $ids = $matches->flatMap(fn (SeriesMatch $match): array => [...$match->rosterSide('challenger'), ...$match->rosterSide('challenged')])->unique()->values();

        return $ids->isEmpty() ? [] : User::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    /**
     * What these results did to this player's ratings (their own, or their
     * lineup's), keyed `chess:<id>` / `series:<id>`.
     *
     * @param  array<int, int>  $games  chess game ids
     * @param  array<int, int>  $series  series match ids
     * @return array<string, int>
     */
    private function deltas(array $games, array $series): array
    {
        if ($games === [] && $series === []) {
            return [];
        }

        $subjects = ['user:'.$this->user->id, ...$this->lineups()->map(fn (int $id): string => 'lineup:'.$id)->all()];
        $ratings = Rating::query()->whereIn('subject', $subjects)->pluck('id');

        return RatingChange::query()->whereIn('rating_id', $ratings)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('source', RatingChange::CHESS)->whereIn('source_id', $games))
                ->orWhere(fn ($query) => $query->where('source', RatingChange::SERIES)->whereIn('source_id', $series)))
            ->get(['source', 'source_id', 'delta'])
            ->mapWithKeys(fn (RatingChange $change): array => [$change->source.':'.$change->source_id => (int) $change->delta])
            ->all();
    }

    /**
     * @param  array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string}  $rating
     * @return Standing
     */
    private function standing(string $game, string $mode, ?Lineup $lineup, array $rating): array
    {
        return [
            'game' => $game,
            'mode' => $mode,
            'label' => GameNames::full($game, $mode),
            'lineup' => $lineup,
            'rating' => $rating,
            'href' => route('ladder.show', ['game' => $game, 'mode' => $mode]),
        ];
    }
}
