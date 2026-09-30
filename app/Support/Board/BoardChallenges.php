<?php

namespace App\Support\Board;

use App\Enums\BoardInviteStatus;
use App\Games\BoardGame as BoardGameDefinition;
use App\Games\GameRegistry;
use App\Models\BoardChallenge;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Notifications\BoardNotifications;
use App\Support\SeasonChain\RatedTrustGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Challenges to a correspondence game of one board game other than chess
 * (plan "Mühle und Dame", P8), built after App\Support\Chess\DailyChallenges
 * and not on it, so chess stays untouched: one player challenges another,
 * the other accepts or declines within
 * `esports.board_games.correspondence.challenge_hours`, the challenger may
 * withdraw while it is open. Accepting starts the game with the chosen
 * colours. The open challenges of a player are listed in both directions.
 *
 * Casual by default. A rated challenge needs what the rated queue needs
 * (RatedBoard): the rated switch, the board game's ladder open, and two
 * Trusted players who list each other (consensus rule 1). It is refused
 * when sent if the challenger cannot play rated or the two do not qualify
 * now (`rated_pair` with the RatedTrustGate code, so the page shows the P57
 * "list each other" notice), and checked again at the accept, which pins
 * the gate; a pair that no longer qualifies then cannot accept it rated
 * (`rated_pair`), and the challenge stays open meanwhile.
 *
 * Limits as daily chess: so many per day in total and to the same player
 * (`challenges_per_day`, `challenges_per_recipient_per_day`), counted
 * first and compared after (RateLimiter::hit is one atomic increment).
 * Challenge notifications off the page share the recipient's daily cap with
 * daily chess (`daily-challenge-dms-in:`), so a second game does not reopen
 * the inbox.
 */
final class BoardChallenges
{
    public const COLORS = ['random', 'white', 'black'];

    public function __construct(
        private BoardGameService $games,
        private BoardNotifications $notifications,
        private GameRegistry $registry,
        private RatedBoard $rated,
    ) {}

    /**
     * Whether a challenge between the two to this board game is open, sent by
     * either: a second one is refused, and the challenge form says so as
     * soon as the other player is picked.
     */
    public function openBetween(User $one, User $other, string $slug): bool
    {
        return BoardChallenge::query()
            ->where('game', $slug)
            ->where('status', BoardInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('challenger_id', $one->id)->where('challenged_id', $other->id))
                ->orWhere(fn ($query) => $query->where('challenger_id', $other->id)->where('challenged_id', $one->id)))
            ->exists();
    }

    /**
     * @param  string  $color  the challenger's colour: random, white or black (checked here, it comes from a form)
     *
     * @throws BoardRuleViolation
     */
    public function challenge(User $challenger, User $challenged, string $slug, string $color = 'random', bool $rated = false, ?string $message = null): BoardChallenge
    {
        if ($challenger->is($challenged)) {
            throw new BoardRuleViolation('challenge_self');
        }

        $definition = $this->registry->find($slug);

        if (! $definition instanceof BoardGameDefinition || $definition->mode(BoardGame::CORRESPONDENCE) === null) {
            throw new BoardRuleViolation('unknown_game');
        }

        if (! in_array($color, self::COLORS, true)) {
            throw new BoardRuleViolation('challenge_color');
        }

        $message = trim((string) $message);

        if (mb_strlen($message) > 140) {
            throw new BoardRuleViolation('challenge_message');
        }

        if ($rated) {
            $this->assertRated($challenger, $challenged, $slug);
        }

        if ($this->openBetween($challenger, $challenged, $slug)) {
            throw new BoardRuleViolation('challenge_open');
        }

        $counted = [];

        foreach (self::limits($challenger, $challenged) as [$key, $max]) {
            $counted[] = $key;

            if (RateLimiter::hit($key, 86_400) > $max) {
                // A refused challenge does not count: take back this call's hits.
                foreach ($counted as $taken) {
                    RateLimiter::decrement($taken, 86_400);
                }

                throw new BoardRuleViolation('challenge_limit', 'available in '.RateLimiter::availableIn($key).' s');
            }
        }

        $challenge = BoardChallenge::query()->create([
            'challenger_id' => $challenger->id,
            'challenged_id' => $challenged->id,
            'game' => $slug,
            'mode' => BoardGame::CORRESPONDENCE,
            'color' => $color,
            'rated' => $rated,
            'message' => $message === '' ? null : $message,
            'status' => BoardInviteStatus::Pending,
            'expires_at' => now()->addHours((int) config('esports.board_games.correspondence.challenge_hours')),
        ]);

        // One inbox cap with daily chess (DailyChallenges): the same key and limit.
        $inbound = RateLimiter::hit('daily-challenge-dms-in:'.$challenged->id, 86_400);
        $remote = $inbound <= max(1, (int) config('esports.chess.challenge_dms_per_recipient_per_day'));

        $this->notifications->challengeReceived($challenge, $remote);

        return $challenge;
    }

    /**
     * Seconds until the challenger may send again, 0 when they may.
     */
    public static function availableIn(User $challenger, User $challenged): int
    {
        $waits = array_map(fn (array $limit): int => RateLimiter::tooManyAttempts($limit[0], $limit[1]) ? RateLimiter::availableIn($limit[0]) : 0, self::limits($challenger, $challenged));

        return max(0, ...$waits);
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private static function limits(User $challenger, User $challenged): array
    {
        return [
            ['board-challenges-day:'.$challenger->id, max(1, (int) config('esports.board_games.correspondence.challenges_per_day'))],
            ['board-challenges-pair:'.$challenger->id.':'.$challenged->id, max(1, (int) config('esports.board_games.correspondence.challenges_per_recipient_per_day'))],
        ];
    }

    /**
     * Why the challenger cannot ask this player for a rated game of this
     * board game now, or null: the challenger's own refusal (no season, the
     * switch off, not Trusted; a sentence) or the pair's (a RatedTrustGate
     * code; NOT_CONNECTED is the P57 "list each other" case).
     *
     * @return array{reason: string, message: string}|null
     */
    public function ratedRefusal(User $challenger, User $challenged, string $slug): ?array
    {
        $own = $this->rated->refusal($challenger, $slug, BoardGame::CORRESPONDENCE);

        if ($own !== null) {
            return ['reason' => 'rated_unavailable', 'message' => $own];
        }

        $pair = $this->rated->pairRefusal($challenger, $challenged);

        return $pair === null ? null : ['reason' => 'rated_pair', 'message' => $pair];
    }

    /**
     * @throws BoardRuleViolation
     */
    private function assertRated(User $challenger, User $challenged, string $slug): void
    {
        $refusal = $this->ratedRefusal($challenger, $challenged, $slug);

        if ($refusal !== null) {
            throw new BoardRuleViolation($refusal['reason'], $refusal['message']);
        }
    }

    /**
     * @throws BoardRuleViolation
     */
    public function accept(BoardChallenge $challenge, User $challenged): BoardGame
    {
        $game = DB::transaction(function () use ($challenge, $challenged): BoardGame {
            $challenge = BoardChallenge::query()->lockForUpdate()->findOrFail($challenge->id);

            if ($challenge->challenged_id !== $challenged->id || ! $challenge->isOpen()) {
                throw new BoardRuleViolation('challenge_closed');
            }

            $challengerWhite = match ($challenge->color) {
                'white' => true,
                'black' => false,
                default => random_int(0, 1) === 0,
            };

            [$white, $black] = $challengerWhite ? [$challenge->challenger, $challenged] : [$challenged, $challenge->challenger];
            $gate = null;

            if ($challenge->rated) {
                // The accept is the pairing: the gate is read and pinned now, never taken from the challenge.
                $this->assertRated($challenge->challenger, $challenged, $challenge->game);
                $gate = $this->rated->pin($white, $black) ?? throw new BoardRuleViolation('rated_pair', RatedTrustGate::NOT_CONNECTED);
            }

            $game = $this->games->start($challenge->game, $white, $black, BoardGame::CORRESPONDENCE, ratedGate: $gate);

            // A rated challenge whose ladder closed in between would start casual: refuse instead.
            if ($challenge->rated && ! $game->rated) {
                throw new BoardRuleViolation('rated_unavailable');
            }

            $challenge->forceFill(['status' => BoardInviteStatus::Accepted, 'board_game_id' => $game->id])->save();

            return $game;
        });

        $this->notifications->gameStarted($game, $game->white_id === $challenged->id ? $game->black : $game->white);

        return $game;
    }

    /**
     * The challenged player declines, or the challenger withdraws.
     *
     * @throws BoardRuleViolation
     */
    public function close(BoardChallenge $challenge, User $user): void
    {
        $status = match ($user->id) {
            $challenge->challenged_id => BoardInviteStatus::Declined,
            $challenge->challenger_id => BoardInviteStatus::Withdrawn,
            default => throw new BoardRuleViolation('challenge_closed'),
        };

        BoardChallenge::query()->whereKey($challenge->id)->where('status', BoardInviteStatus::Pending)
            ->update(['status' => $status, 'updated_at' => now()]);
    }

    /**
     * @return Collection<int, BoardChallenge>
     */
    public function incoming(User $user, ?string $slug = null): Collection
    {
        return BoardChallenge::query()
            ->where('challenged_id', $user->id)
            ->when($slug !== null, fn ($query) => $query->where('game', $slug))
            ->where('status', BoardInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('challenger')
            ->latest('id')
            ->get();
    }

    /**
     * @return Collection<int, BoardChallenge>
     */
    public function outgoing(User $user, ?string $slug = null): Collection
    {
        return BoardChallenge::query()
            ->where('challenger_id', $user->id)
            ->when($slug !== null, fn ($query) => $query->where('game', $slug))
            ->where('status', BoardInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('challenged')
            ->latest('id')
            ->get();
    }
}
