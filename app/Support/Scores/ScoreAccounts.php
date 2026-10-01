<?php

namespace App\Support\Scores;

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Models\ScoreAccountChange;
use App\Models\ScoreAccountClaim;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The private link between a league player and their account in a score
 * game (plan "AoE2 und Trackmania", P4). A player stores the account id like
 * a gamer tag (users.gamer_tags, under ScoreGame::accountService()); like
 * one, it never leaves the settings: no public page, no Nostr event names it.
 *
 * A stored id is a claim, nothing more: it is not proven (re-audit F4,
 * decided 2026-10-01). So:
 * - only a claim an admin confirmed maps: a server's finish and a poller's
 *   read go to a player only through a ScoreAccountClaim. An unconfirmed
 *   claim, single or contested, maps nothing; such finishes stay pending
 *   with their account id and show on /admin/scores;
 * - an admin confirms, reassigns or revokes a claim with a reason, every
 *   claimer of the id side by side; reassigning or revoking moves the runs
 *   already mapped with it (to the new owner, or back to pending);
 * - no admin decides an id they claim themselves, or one claimed by a player
 *   of a running leaderboard of the game they have an interest in
 *   (ScoreLeaderboards::interested(): they play in it, a clan in it, ...);
 * - every decision is a line in score_account_changes.
 */
final class ScoreAccounts
{
    /**
     * The player this account id of the game was confirmed for, or null.
     */
    public static function userFor(ScoreGame $game, string $accountId): ?int
    {
        $accountId = trim($accountId);

        if ($game->accountService() === null || $accountId === '') {
            return null;
        }

        $confirmed = ScoreAccountClaim::query()->where(['game' => $game->slug(), 'account_id' => $accountId])->value('user_id');

        return $confirmed === null ? null : (int) $confirmed;
    }

    /**
     * The players who stored this account id for the game in their settings.
     *
     * @return list<int>
     */
    public static function claimers(ScoreGame $game, string $accountId, ?int $limit = null): array
    {
        $service = $game->accountService();

        if ($service === null || trim($accountId) === '') {
            return [];
        }

        return array_values(User::query()->where("gamer_tags->{$service}", trim($accountId))->orderBy('id')
            ->when($limit !== null, fn ($query) => $query->limit((int) $limit))->pluck('id')->map(intval(...))->all());
    }

    /**
     * The account services of the registered score games: key => game name.
     *
     * @return array<string, string>
     */
    public static function services(): array
    {
        $services = [];

        foreach (app(GameRegistry::class)->scores() as $game) {
            $service = $game->accountService();

            if ($service !== null) {
                $services[$service] = $game->name();
            }
        }

        return $services;
    }

    /**
     * The gamer tag services of the settings page: `esports.gamer_tags`, plus
     * one account id per registered score game that reads accounts.
     *
     * @return array<string, string> key => label
     */
    public static function tagServices(): array
    {
        $services = (array) config('esports.gamer_tags', []);

        foreach (self::services() as $service => $game) {
            $services[$service] ??= __(':game account ID', ['game' => __($game)]);
        }

        return $services;
    }

    /**
     * Confirm an unconfirmed account id as one claimer's: it maps to them from
     * now on, and its pending runs are handed over.
     *
     * @throws TournamentRuleViolation
     */
    public static function confirm(ScoreGame $game, string $accountId, User $player, User $admin, string $reason): int
    {
        $accountId = trim($accountId);
        $reason = self::reason($reason);
        self::assertMayDecide($game, $accountId, [$player->id], $admin);

        if (! in_array($player->id, self::claimers($game, $accountId), true)) {
            throw new TournamentRuleViolation('not_claimed', __('This player has not stored that account id.'));
        }

        return DB::transaction(function () use ($game, $accountId, $player, $admin, $reason): int {
            if (ScoreAccountClaim::query()->where(['game' => $game->slug(), 'account_id' => $accountId])->lockForUpdate()->exists()) {
                throw new TournamentRuleViolation('confirmed', __('This account is confirmed already. Reassign or revoke it instead.'));
            }

            ScoreAccountClaim::query()->create(['game' => $game->slug(), 'account_id' => $accountId, 'user_id' => $player->id, 'confirmed_by_id' => $admin->id]);
            $moved = self::move($game, $accountId, null, $player->id);
            self::log($game, $accountId, 'confirm', null, $player->id, $admin, $reason, $moved);

            return $moved;
        });
    }

    /**
     * Move a confirmed account id to another of its claimers, with every run
     * mapped with it and every pending one.
     *
     * @throws TournamentRuleViolation
     */
    public static function reassign(ScoreGame $game, string $accountId, User $player, User $admin, string $reason): int
    {
        $accountId = trim($accountId);
        $reason = self::reason($reason);
        $claim = self::claim($game, $accountId);
        self::assertMayDecide($game, $accountId, [$claim->user_id, $player->id], $admin);

        if ($claim->user_id === $player->id) {
            throw new TournamentRuleViolation('same', __('The account is confirmed for this player already.'));
        }

        if (! in_array($player->id, self::claimers($game, $accountId), true)) {
            throw new TournamentRuleViolation('not_claimed', __('This player has not stored that account id.'));
        }

        return DB::transaction(function () use ($game, $accountId, $player, $admin, $reason, $claim): int {
            $from = $claim->user_id;
            $claim->forceFill(['user_id' => $player->id, 'confirmed_by_id' => $admin->id])->save();
            $moved = self::move($game, $accountId, $from, $player->id) + self::move($game, $accountId, null, $player->id);
            self::log($game, $accountId, 'reassign', $from, $player->id, $admin, $reason, $moved);

            return $moved;
        });
    }

    /**
     * Take a confirmation back: the id maps to nobody again, and the runs
     * mapped with it go back to pending.
     *
     * @throws TournamentRuleViolation
     */
    public static function revoke(ScoreGame $game, string $accountId, User $admin, string $reason): int
    {
        $accountId = trim($accountId);
        $reason = self::reason($reason);
        $claim = self::claim($game, $accountId);
        self::assertMayDecide($game, $accountId, [$claim->user_id], $admin);

        return DB::transaction(function () use ($game, $accountId, $admin, $reason, $claim): int {
            $from = $claim->user_id;
            $claim->delete();
            $moved = ScoreRun::query()->where(['game' => $game->slug(), 'account_id' => $accountId, 'user_id' => $from])->update(['user_id' => null]);
            self::log($game, $accountId, 'revoke', $from, null, $admin, $reason, $moved);

            return $moved;
        });
    }

    /**
     * The pending runs of each game account id, for the admin review: the
     * game, the id, how many runs wait, and every player who stored the id.
     *
     * @return list<array{game: ScoreGame, account: string, runs: int, claimers: list<User>}>
     */
    public static function pending(): array
    {
        $groups = [];
        $rows = ScoreRun::query()->whereNull('user_id')->whereNotNull('account_id')
            ->selectRaw('game, account_id, count(*) as runs')->groupBy('game', 'account_id')->orderBy('game')->orderBy('account_id')->limit(100)->get();

        foreach ($rows as $row) {
            $game = app(GameRegistry::class)->find((string) $row->game);

            if (! $game instanceof ScoreGame) {
                continue;
            }

            $account = (string) $row->getAttribute('account_id');
            $groups[] = ['game' => $game, 'account' => $account, 'runs' => (int) $row->getAttribute('runs'),
                'claimers' => array_values(User::query()->whereKey(self::claimers($game, $account))->orderBy('id')->get()->all())];
        }

        return $groups;
    }

    /**
     * How many account ids have finishes waiting for an admin (the admin nav badge).
     */
    public static function pendingAccounts(): int
    {
        return ScoreRun::query()->whereNull('user_id')->whereNotNull('account_id')->distinct()->count('account_id');
    }

    /**
     * The confirmed claims, newest first, for the admin review.
     *
     * @return Collection<int, ScoreAccountClaim>
     */
    public static function confirmed(): Collection
    {
        return ScoreAccountClaim::query()->with('user')->latest('updated_at')->latest('id')->limit(100)->get();
    }

    /**
     * Whether a finish of an id a player of this leaderboard stored waits for
     * an admin inside its window: the leaderboard is not ended before (an
     * unconfirmed claim maps nothing, so the end would leave that value out).
     */
    public static function waitsFor(Tournament $tournament, ScoreGame $game): bool
    {
        $service = $game->accountService();

        if ($service === null) {
            return false;
        }

        $ids = User::query()->whereIn('id', TournamentParticipant::query()->where('tournament_id', $tournament->id)->pluck('user_id')->filter()->all())
            ->get(['id', 'gamer_tags'])->map(fn (User $user): string => trim((string) ($user->gamer_tags[$service] ?? '')))->filter()->unique()->values()->all();
        $window = ScoreWindow::of($tournament);

        return $ids !== [] && ScoreRun::query()->whereNull('user_id')
            ->where(['game' => $game->slug(), 'mode' => $tournament->mode, 'course' => (string) $tournament->score_course])
            ->whereIn('account_id', $ids)->where('achieved_at', '>=', $window->start)->where('achieved_at', '<', $window->end)->exists();
    }

    /**
     * No admin decides an id they claim, nor one claimed by a player of a
     * running leaderboard of this game they have an interest in.
     *
     * @param  list<int>  $involved  the players the decision moves runs from or to
     *
     * @throws TournamentRuleViolation
     */
    private static function assertMayDecide(ScoreGame $game, string $accountId, array $involved, User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new TournamentRuleViolation('not_admin', __('Only an admin decides an account.'));
        }

        $players = array_values(array_unique([...$involved, ...self::claimers($game, $accountId)]));

        if (in_array($admin->id, $players, true)) {
            throw new TournamentRuleViolation('interested', __('You stored this account id yourself, so another admin has to decide it.'));
        }

        $tournaments = Tournament::query()->where(['game' => $game->slug(), 'status' => TournamentStatus::Running])
            ->whereHas('participants', fn ($query) => $query->whereIn('user_id', $players))->get();

        foreach ($tournaments as $tournament) {
            if (ScoreLeaderboards::interested($tournament, $admin)) {
                throw new TournamentRuleViolation('interested', __('You have an interest in a running leaderboard of this game that a claimer plays in, so another admin has to decide this account.'));
            }
        }
    }

    /**
     * @throws TournamentRuleViolation
     */
    private static function claim(ScoreGame $game, string $accountId): ScoreAccountClaim
    {
        return ScoreAccountClaim::query()->where(['game' => $game->slug(), 'account_id' => $accountId])->first()
            ?? throw new TournamentRuleViolation('unconfirmed', __('This account is not confirmed for anybody.'));
    }

    /**
     * @throws TournamentRuleViolation
     */
    private static function reason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new TournamentRuleViolation('reason', __('Give a reason of 3 to 500 characters: how you know whose account it is. It is kept in the log.'));
        }

        return $reason;
    }

    /**
     * Move the runs of an account id from one player (null: pending) to
     * another; a run the target has already (same source, course, value and
     * time) is a second copy and goes.
     */
    private static function move(ScoreGame $game, string $accountId, ?int $from, int $to): int
    {
        $moved = 0;
        $runs = ScoreRun::query()->where(['game' => $game->slug(), 'account_id' => $accountId])
            ->when($from === null, fn ($query) => $query->whereNull('user_id'), fn ($query) => $query->where('user_id', $from))->orderBy('id')->get();

        foreach ($runs as $run) {
            try {
                $run->forceFill(['user_id' => $to])->save();
                $moved++;
            } catch (UniqueConstraintViolationException) {
                $run->delete();
            }
        }

        return $moved;
    }

    private static function log(ScoreGame $game, string $accountId, string $action, ?int $from, ?int $to, User $admin, string $reason, int $moved): void
    {
        ScoreAccountChange::query()->create(['game' => $game->slug(), 'account_id' => $accountId, 'action' => $action, 'from_user_id' => $from, 'to_user_id' => $to,
            'admin_id' => $admin->id, 'reason' => $reason, 'runs_moved' => $moved, 'created_at' => now()]);
    }
}
