<?php

namespace App\Support\Chess;

use App\Enums\ChessInviteStatus;
use App\Models\ChessChallenge;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Notifications\ChessNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Daily chess challenges (ChessChallenge, ChessOverlays "Daily challenge
 * received"): one player challenges another, the other accepts or declines
 * within `esports.chess.challenge_hours`, the challenger may withdraw while
 * it is open. Accepting starts a daily game with the chosen colours.
 *
 * Casual until Elo exists (P7): league data only, no 2150/2151 on Nostr.
 *
 * A challenge reaches the challenged player's Nostr inbox by default, so a
 * player may send only so many per day, in total and to the same player
 * (esports.chess.challenges_per_day, challenges_per_recipient_per_day). The
 * count is taken first (RateLimiter::hit is one atomic increment), then
 * compared: two requests at once cannot both slip under the limit.
 *
 * A player receives at most `challenge_dms_per_recipient_per_day` challenge
 * notifications off the page (DM, push) from all challengers together, so a
 * second account does not reopen the inbox. Past that the challenge is still
 * stored and shows in the bell and on the daily games page: refusing it
 * would let one spammer lock everyone else out of challenging that player.
 */
final class DailyChallenges
{
    public const COLORS = ['random', 'white', 'black'];

    public function __construct(private ChessGameService $games, private ChessNotifications $notifications) {}

    /**
     * @param  string  $color  the challenger's colour: random, white or black (checked here, it comes from a form)
     *
     * @throws ChessRuleViolation
     */
    public function challenge(User $challenger, User $challenged, string $color = 'random', ?string $message = null): ChessChallenge
    {
        if ($challenger->is($challenged)) {
            throw new ChessRuleViolation('challenge_self');
        }

        if (! in_array($color, self::COLORS, true)) {
            throw new ChessRuleViolation('challenge_color');
        }

        $message = trim((string) $message);

        if (mb_strlen($message) > 140) {
            throw new ChessRuleViolation('challenge_message');
        }

        $open = ChessChallenge::query()
            ->where('status', ChessInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('challenger_id', $challenger->id)->where('challenged_id', $challenged->id))
                ->orWhere(fn ($query) => $query->where('challenger_id', $challenged->id)->where('challenged_id', $challenger->id)))
            ->exists();

        if ($open) {
            throw new ChessRuleViolation('challenge_open');
        }

        $counted = [];

        foreach (self::limits($challenger, $challenged) as [$key, $max]) {
            $counted[] = $key;

            if (RateLimiter::hit($key, 86_400) > $max) {
                // A refused challenge does not count: take back this call's hits.
                foreach ($counted as $taken) {
                    RateLimiter::decrement($taken, 86_400);
                }

                throw new ChessRuleViolation('challenge_limit', 'available in '.RateLimiter::availableIn($key).' s');
            }
        }

        $challenge = ChessChallenge::query()->create([
            'challenger_id' => $challenger->id,
            'challenged_id' => $challenged->id,
            'mode' => ChessGame::CORRESPONDENCE,
            'color' => $color,
            'message' => $message === '' ? null : $message,
            'status' => ChessInviteStatus::Pending,
            'expires_at' => now()->addHours((int) config('esports.chess.challenge_hours')),
        ]);

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
     * Rate limiter key and maximum per 24 hours: all challenges of the
     * challenger, and those to this one player.
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function limits(User $challenger, User $challenged): array
    {
        return [
            ['daily-challenges-day:'.$challenger->id, max(1, (int) config('esports.chess.challenges_per_day'))],
            ['daily-challenges-pair:'.$challenger->id.':'.$challenged->id, max(1, (int) config('esports.chess.challenges_per_recipient_per_day'))],
        ];
    }

    /**
     * @throws ChessRuleViolation
     */
    public function accept(ChessChallenge $challenge, User $challenged): ChessGame
    {
        return DB::transaction(function () use ($challenge, $challenged): ChessGame {
            $challenge = ChessChallenge::query()->lockForUpdate()->findOrFail($challenge->id);

            if ($challenge->challenged_id !== $challenged->id || ! $challenge->isOpen()) {
                throw new ChessRuleViolation('challenge_closed');
            }

            $challengerWhite = match ($challenge->color) {
                'white' => true,
                'black' => false,
                default => random_int(0, 1) === 0,
            };

            [$white, $black] = $challengerWhite ? [$challenge->challenger, $challenged] : [$challenged, $challenge->challenger];
            $game = $this->games->start($white, $black, ChessGame::CORRESPONDENCE);

            $challenge->forceFill(['status' => ChessInviteStatus::Accepted, 'chess_game_id' => $game->id])->save();
            $this->notifications->gameStarted($game, $challenge->challenger);

            return $game;
        });
    }

    /**
     * The challenged player declines, or the challenger withdraws.
     *
     * @throws ChessRuleViolation
     */
    public function close(ChessChallenge $challenge, User $user): void
    {
        $status = match ($user->id) {
            $challenge->challenged_id => ChessInviteStatus::Declined,
            $challenge->challenger_id => ChessInviteStatus::Withdrawn,
            default => throw new ChessRuleViolation('challenge_closed'),
        };

        if ($challenge->status === ChessInviteStatus::Pending) {
            $challenge->forceFill(['status' => $status])->save();
        }
    }

    /**
     * @return Collection<int, ChessChallenge>
     */
    public function incoming(User $user): Collection
    {
        return ChessChallenge::query()
            ->where('challenged_id', $user->id)
            ->where('status', ChessInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('challenger')
            ->latest('id')
            ->get();
    }

    /**
     * @return Collection<int, ChessChallenge>
     */
    public function outgoing(User $user): Collection
    {
        return ChessChallenge::query()
            ->where('challenger_id', $user->id)
            ->where('status', ChessInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('challenged')
            ->latest('id')
            ->get();
    }
}
