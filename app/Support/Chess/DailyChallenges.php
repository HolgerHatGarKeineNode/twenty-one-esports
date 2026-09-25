<?php

namespace App\Support\Chess;

use App\Enums\ChessInviteStatus;
use App\Models\ChessChallenge;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Notifications\ChessNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daily chess challenges (ChessChallenge, ChessOverlays "Daily challenge
 * received"): one player challenges another, the other accepts or declines
 * within `esports.chess.challenge_hours`, the challenger may withdraw while
 * it is open. Accepting starts a daily game with the chosen colours.
 *
 * Casual until Elo exists (P7): league data only, no 2150/2151 on Nostr.
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

        $challenge = ChessChallenge::query()->create([
            'challenger_id' => $challenger->id,
            'challenged_id' => $challenged->id,
            'mode' => ChessGame::CORRESPONDENCE,
            'color' => $color,
            'message' => $message === '' ? null : $message,
            'status' => ChessInviteStatus::Pending,
            'expires_at' => now()->addHours((int) config('esports.chess.challenge_hours')),
        ]);

        $this->notifications->challengeReceived($challenge);

        return $challenge;
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
