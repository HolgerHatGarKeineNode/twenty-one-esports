<?php

namespace App\Support\Broadcast;

use App\Enums\BoardEndReason;
use App\Enums\BoardGameStatus;
use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\HyperMatchStatus;
use App\Enums\PayoutStatus;
use App\Enums\PongEndReason;
use App\Enums\PongMatchStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Events\LeagueFeedEvent;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use App\Games\ProofOfPong;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\PongMatch;
use App\Models\RankBadgeVersion;
use App\Models\ScoreRun;
use App\Models\SeasonPayout;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\TournamentRound;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Rating\RankTiers;
use App\Support\Tournaments\TournamentChampion;
use App\Support\TwentyOne\Stream\GameTitle;
use App\Support\TwentyOne\Stream\PublicName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The league's public live feed for the OBS overlays (plan "OBS-Broadcast-Overlays", P2): App\Events\LeagueFeedEvent
 * on the public `league.feed` channel whenever something happens an overlay may show, read where it is stored, as
 * model events, so every path that finishes a game is covered without touching it:
 *
 * - `win`: a decided chess game, board game, Proof of Pong match or Hyperbitcoinization match, a series with a
 *   result that stands (confirmed or decided by an admin); never a forfeit or a voided result, as the pride slides
 *   (PrideSlides) leave them out.
 * - `score`: a checked highscore attempt with a value (score_runs, Blockfill's verified runs included).
 * - `rank-up`: a rank badge stepping up a tier (the first reveal included, provisional never).
 * - `signup`: a tournament sign-up.
 * - `round`: a tournament round closing.
 * - `champion`: a tournament finishing with a single winner.
 * - `payout`: a tournament prize or a season payout paid.
 *
 * Names are the public names (PublicName::of(), as the stream shows them; any npub left in a name read elsewhere is
 * masked to `npub1…` on the way out); no key, npub, email, Lightning address or game account ever goes into an item.
 *
 * Series results and paid payouts are also written past the model (a query-builder update guarded by the status,
 * which fires no model event): those writers call seriesDecided() and payoutPaid() themselves. The model events stay
 * for the writers that save the model; one write never takes both paths, so nothing is sent twice. A source that throws reports and sends nothing: the feed never costs the
 * game its save (fail-open for the push, never for the result). Sent after the commit, so a rolled-back result is
 * never announced, and throttled to FEED_PER_MINUTE pushes: in a burst the rest is left to the overlays' snapshot
 * poll.
 */
final class LeagueFeed
{
    public const CHANNEL = 'league.feed';

    /** Pushes a minute at most, over all sources: one public channel, every overlay listening. */
    public const FEED_PER_MINUTE = 30;

    private const LIMITER = 'league-feed';

    public static function register(): void
    {
        ChessGame::updated(fn (ChessGame $game) => self::guard(fn (): ?array => self::chess($game)));
        BoardGame::updated(fn (BoardGame $game) => self::guard(fn (): ?array => self::board($game)));
        SeriesMatch::updated(fn (SeriesMatch $match) => self::guard(fn (): ?array => self::series($match)));
        PongMatch::updated(fn (PongMatch $match) => self::guard(fn (): ?array => self::pong($match)));
        HyperMatch::updated(fn (HyperMatch $match) => self::guard(fn (): ?array => self::hyper($match)));
        ScoreRun::saved(fn (ScoreRun $run) => self::guard(fn (): ?array => self::score($run)));
        RankBadgeVersion::created(fn (RankBadgeVersion $version) => self::guard(fn (): ?array => self::rankUp($version)));
        TournamentSignup::created(fn (TournamentSignup $signup) => self::guard(fn (): ?array => self::signup($signup)));
        TournamentRound::updated(fn (TournamentRound $round) => self::guard(fn (): ?array => self::round($round)));
        Tournament::updated(fn (Tournament $tournament) => self::guard(fn (): ?array => self::champion($tournament)));
        TournamentPayout::updated(fn (TournamentPayout $payout) => self::guard(fn (): ?array => self::tournamentPayout($payout)));
        SeasonPayout::updated(fn (SeasonPayout $payout) => self::guard(fn (): ?array => self::seasonPayout($payout)));
    }

    /**
     * Sends one item after the commit (right away without a transaction), within the throttle.
     *
     * @param  array<string, scalar|list<string>|null>  $item
     */
    public static function push(array $item): void
    {
        $item = self::masked($item);
        $item['at'] = now()->getTimestampMs();

        DB::afterCommit(function () use ($item): void {
            try {
                if (RateLimiter::attempt(self::LIMITER, self::FEED_PER_MINUTE, fn (): bool => true, 60) === false) {
                    return;
                }

                event(new LeagueFeedEvent([$item]));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * A series just decided by a query-builder update (SeriesService: a confirmation, an admin's decision, the
     * league's deadline decision), read as stored: a win when its result stands. Call inside the write's
     * transaction; sent after the commit like every item.
     */
    public static function seriesDecided(int $seriesId): void
    {
        self::guard(function () use ($seriesId): ?array {
            $match = SeriesMatch::query()->find($seriesId);

            return $match === null ? null : self::seriesWin($match);
        });
    }

    /**
     * A payout just marked paid by a query-builder update (PayoutRunner::markPaid()), read as stored.
     */
    public static function payoutPaid(TournamentPayout|SeasonPayout $payout): void
    {
        self::guard(function () use ($payout): ?array {
            $fresh = $payout::query()->find($payout->id);

            return match (true) {
                $fresh === null || $fresh->status !== PayoutStatus::Paid => null,
                $fresh instanceof SeasonPayout => self::seasonPayoutItem($fresh),
                default => self::tournamentPayoutItem($fresh),
            };
        });
    }

    /**
     * @param  callable(): (array<string, scalar|list<string>|null>|null)  $read
     */
    private static function guard(callable $read): void
    {
        try {
            $item = $read();

            if ($item !== null) {
                self::push($item);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** The public name of a player as the stream prints it: never the truncated npub of User::displayName(). */
    private static function name(?User $user): string
    {
        return $user === null ? '' : PublicName::of($user);
    }

    /**
     * Every string of an item with its npubs masked (PublicName::maskNpubs()).
     *
     * @param  array<string, scalar|list<string>|null>  $item
     * @return array<string, scalar|list<string>|null>
     */
    private static function masked(array $item): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            is_string($value) => PublicName::maskNpubs($value),
            is_array($value) => array_map(fn (string $entry): string => PublicName::maskNpubs($entry), $value),
            default => $value,
        }, $item);
    }

    /** Whether an update just moved `status` to `$status`. */
    private static function became(Model $model, string $attribute, mixed $value): bool
    {
        return $model->wasChanged($attribute) && $model->getAttribute($attribute) === $value;
    }

    /**
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function chess(ChessGame $game): ?array
    {
        if (! self::became($game, 'status', ChessGameStatus::Finished) || ! in_array($game->result, ['1-0', '0-1'], true) || $game->end_reason === ChessEndReason::Forfeit) {
            return null;
        }

        [$winner, $loser] = $game->result === '1-0' ? [$game->white, $game->black] : [$game->black, $game->white];

        return self::win('chess', $game->mode, [self::name($winner)], [self::name($loser)], null, (bool) $game->rated);
    }

    /**
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function board(BoardGame $game): ?array
    {
        if (! self::became($game, 'status', BoardGameStatus::Finished) || ! in_array($game->result, ['1-0', '0-1'], true)
            || $game->end_reason === BoardEndReason::Forfeit->value || ! app(GameRegistry::class)->isBoard($game->game)) {
            return null;
        }

        [$winner, $loser] = $game->result === '1-0' ? [$game->white, $game->black] : [$game->black, $game->white];

        return self::win($game->game, $game->mode, [self::name($winner)], [self::name($loser)], null, (bool) $game->rated);
    }

    /**
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function series(SeriesMatch $match): ?array
    {
        return $match->wasChanged('status') ? self::seriesWin($match) : null;
    }

    /**
     * A series with a result that stands, as stored.
     *
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function seriesWin(SeriesMatch $match): ?array
    {
        if (! in_array($match->status, [SeriesStatus::Confirmed, SeriesStatus::Resolved], true)
            || ! in_array($match->winner, SeriesMatch::SIDES, true)
            || in_array($match->resolution, [SeriesResolution::Void, SeriesResolution::Forfeit], true)) {
            return null;
        }

        $side = (string) $match->winner;
        $other = SeriesMatch::otherSide($side);
        $score = SeriesMatch::seriesScore($match->result_games);

        return self::win($match->game, $match->mode, [self::sideName($match, $side)], [self::sideName($match, $other)],
            $score[$side] + $score[$other] > 0 ? $score[$side].'-'.$score[$other] : null, (bool) $match->rated);
    }

    /** A solo side is its player's public name (as name()), a lineup the name it played under. */
    private static function sideName(SeriesMatch $match, string $side): string
    {
        $ids = (array) ($match->sides[$side] ?? []);
        $lineup = $side === 'challenger' ? $match->challenger_lineup_id : $match->challenged_lineup_id;
        $user = $lineup === null && count($ids) === 1 ? User::query()->find((int) reset($ids)) : null;

        return $user !== null ? self::name($user) : PublicName::clean($match->sideName($side));
    }

    /**
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function pong(PongMatch $match): ?array
    {
        if (! self::became($match, 'status', PongMatchStatus::Finished) || $match->winner_id === null || $match->end_reason === PongEndReason::Forfeit) {
            return null;
        }

        $leftWon = $match->winner_id === $match->left_id;
        [$winner, $loser] = $leftWon ? [$match->left, $match->right] : [$match->right, $match->left];
        $score = $leftWon ? $match->score_left.'-'.$match->score_right : $match->score_right.'-'.$match->score_left;

        return self::win(ProofOfPong::SLUG, null, [self::name($winner)], [self::name($loser)], $score, (bool) $match->rated);
    }

    /**
     * Place 1 of a finished match: one player, or a team's players; a bot seat is named by nobody.
     *
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function hyper(HyperMatch $match): ?array
    {
        if (! self::became($match, 'status', HyperMatchStatus::Finished)) {
            return null;
        }

        $seats = $match->seats()->with('user')->get();
        $winners = $seats->filter(fn (HyperSeat $seat): bool => $seat->place === 1 && ! $seat->bot && $seat->user !== null);

        if ($winners->isEmpty()) {
            return null;
        }

        $losers = $seats->filter(fn (HyperSeat $seat): bool => $seat->place !== 1 && ! $seat->bot && $seat->user !== null);

        return self::win(Hyperbitcoinization::SLUG, $match->mode,
            array_values($winners->map(fn (HyperSeat $seat): string => self::name($seat->user))->all()),
            array_values($losers->map(fn (HyperSeat $seat): string => self::name($seat->user))->all()), null, (bool) $match->rated);
    }

    /**
     * @param  list<string>  $winners
     * @param  list<string>  $losers
     * @return array<string, scalar|list<string>|null>
     */
    private static function win(string $game, ?string $mode, array $winners, array $losers, ?string $score, bool $rated): array
    {
        return [
            'kind' => 'win',
            'game' => $game,
            'gameName' => app(GameRegistry::class)->find($game)?->name() ?? $game,
            'mode' => $mode,
            'winners' => array_values(array_filter($winners)),
            'losers' => array_values(array_filter($losers)),
            'score' => $score,
            'rated' => $rated,
        ];
    }

    /**
     * A checked attempt with a value: newly created checked (a server's finish, Blockfill's mirror) or just checked
     * by an admin. Never a director's correction, a rejected run or a run no player owns.
     *
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function score(ScoreRun $run): ?array
    {
        $justChecked = $run->wasRecentlyCreated ? $run->verified_at !== null : ($run->wasChanged('verified_at') && $run->getOriginal('verified_at') === null && $run->verified_at !== null);

        if (! $justChecked || $run->value === null || $run->rejected_at !== null || $run->user_id === null || $run->source === ScoreRun::DIRECTOR) {
            return null;
        }

        return [
            'kind' => 'score',
            'game' => $run->game,
            'gameName' => app(GameRegistry::class)->find($run->game)?->name() ?? $run->game,
            'mode' => $run->mode,
            'winners' => [self::name($run->user)],
            'score' => $run->formatted(),
        ];
    }

    /**
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function rankUp(RankBadgeVersion $version): ?array
    {
        $badge = $version->badge;

        if ($version->tier === RankTiers::Provisional || ! $version->isRankUp() || $badge->user === null) {
            return null;
        }

        return [
            'kind' => 'rank-up',
            'game' => $badge->game,
            'gameName' => GameTitle::ladder($badge->game, $badge->mode),
            'mode' => $badge->mode,
            'winners' => [self::name($badge->user)],
            'tier' => RankTiers::label($version->tier),
            'previous' => $version->previous_tier === null || $version->previous_tier === RankTiers::Provisional ? null : RankTiers::label($version->previous_tier),
            'rating' => $version->rating,
        ];
    }

    /**
     * A sign-up by a player (their public name) or a lineup (the name it signed up with, its clan's).
     *
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function signup(TournamentSignup $signup): ?array
    {
        $tournament = $signup->tournament;

        if ($tournament->isLeagueWeek()) {
            return null;
        }

        return self::forTournament($tournament, [
            'kind' => 'signup',
            'winners' => [$signup->lineup_id === null && $signup->user !== null ? self::name($signup->user) : PublicName::clean($signup->name)],
            'entries' => $tournament->signups()->whereNull('withdrawn_at')->whereNull('removed_at')->count(),
        ]);
    }

    /**
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function round(TournamentRound $round): ?array
    {
        if (! self::became($round, 'status', 'closed')) {
            return null;
        }

        return self::forTournament($round->stage->tournament, ['kind' => 'round', 'round' => $round->number]);
    }

    /**
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function champion(Tournament $tournament): ?array
    {
        if (! self::became($tournament, 'status', TournamentStatus::Finished) || $tournament->isLeagueWeek()) {
            return null;
        }

        $champion = app(TournamentChampion::class)->of($tournament);

        if ($champion === null) {
            return null;
        }

        $user = $champion->lineup_id === null && ! $champion->isMixTeam() ? $champion->user : null;

        return self::forTournament($tournament, ['kind' => 'champion', 'winners' => [$user !== null ? self::name($user) : PublicName::clean((string) $champion->name)]]);
    }

    /**
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function tournamentPayout(TournamentPayout $payout): ?array
    {
        return self::became($payout, 'status', PayoutStatus::Paid) ? self::tournamentPayoutItem($payout) : null;
    }

    /**
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function tournamentPayoutItem(TournamentPayout $payout): ?array
    {
        if ($payout->amount_sats <= 0) {
            return null;
        }

        return self::forTournament($payout->tournament, [
            'kind' => 'payout',
            'winners' => [$payout->user !== null ? self::name($payout->user) : PublicName::clean($payout->name)],
            'place' => $payout->place,
            'sats' => $payout->amount_sats,
        ]);
    }

    /**
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function seasonPayout(SeasonPayout $payout): ?array
    {
        return self::became($payout, 'status', PayoutStatus::Paid) ? self::seasonPayoutItem($payout) : null;
    }

    /**
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function seasonPayoutItem(SeasonPayout $payout): ?array
    {
        if ($payout->amount_sats <= 0) {
            return null;
        }

        return [
            'kind' => 'payout',
            'game' => null,
            'season' => $payout->season->slug,
            'winners' => [$payout->user !== null ? self::name($payout->user) : PublicName::clean($payout->name)],
            'blocks' => $payout->blocks,
            'sats' => $payout->amount_sats,
        ];
    }

    /**
     * The item with its tournament; null for a tournament that is not public (a draft, never published).
     *
     * @param  array<string, scalar|list<string>|null>  $item
     * @return array<string, scalar|list<string>|null>|null
     */
    private static function forTournament(Tournament $tournament, array $item): ?array
    {
        if ($tournament->published_at === null || $tournament->status === TournamentStatus::Draft) {
            return null;
        }

        return $item + [
            'game' => $tournament->game,
            'gameName' => app(GameRegistry::class)->find($tournament->game)?->name() ?? $tournament->game,
            'tournament' => PublicName::clean($tournament->name),
            'tournamentId' => $tournament->id,
        ];
    }
}
