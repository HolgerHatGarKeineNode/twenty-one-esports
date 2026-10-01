<?php

namespace App\Support\Scores;

use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Models\ScoreAccountClaim;
use App\Models\ScoreRun;
use App\Models\User;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The private link between a league player and their account in a score
 * game (plan "AoE2 und Trackmania", P4). The account id is stored like a
 * gamer tag (users.gamer_tags, under ScoreGame::accountService()) and, like
 * one, never leaves the settings: no public page, no Nostr event names it.
 * The league reads it only to ask a game's API or to map a server's finish.
 *
 * A player's own claim is not proven (a later phase logs in with the game),
 * so (security gate F4):
 * - an id an admin confirmed for one player is that player's, whoever else
 *   stores it (ScoreAccountClaim);
 * - otherwise an id maps to a player only while exactly one player stores
 *   it, in every path: the server ingest and the pollers alike;
 * - a run already pending is never handed over by a player's own claim:
 *   an admin confirms the claim on /admin/scores (confirm());
 * - when a second player stores an id that is not confirmed, the runs mapped
 *   to it go back to pending for an admin (settle()), instead of the first
 *   claimer keeping them and the next finishes going nowhere unseen.
 */
final class ScoreAccounts
{
    /**
     * The league player this account id of the game belongs to: the confirmed
     * claim, else the one player who stored it; null when nobody, or more than
     * one player without a confirmed claim, did.
     */
    public static function userFor(ScoreGame $game, string $accountId): ?int
    {
        $accountId = trim($accountId);

        if ($game->accountService() === null || $accountId === '') {
            return null;
        }

        $confirmed = ScoreAccountClaim::query()->where(['game' => $game->slug(), 'account_id' => $accountId])->value('user_id');

        if ($confirmed !== null) {
            return (int) $confirmed;
        }

        $ids = self::claimers($game, $accountId, 2);

        return count($ids) === 1 ? $ids[0] : null;
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
     * After a player saved their tags: an account id of theirs that another
     * player stored too, and that no admin confirmed, is contested. Every run
     * mapped to it goes back to pending (and shows on /admin/scores), nobody
     * keeps it. Returns how many runs went back.
     */
    public static function settle(User $user): int
    {
        $moved = 0;

        foreach (app(GameRegistry::class)->scores() as $game) {
            $service = $game->accountService();
            $accountId = $service === null ? '' : trim((string) ($user->gamer_tags[$service] ?? ''));

            if ($accountId === '' || count(self::claimers($game, $accountId, 2)) < 2
                || ScoreAccountClaim::query()->where(['game' => $game->slug(), 'account_id' => $accountId])->exists()) {
                continue;
            }

            $moved += ScoreRun::query()->whereNotNull('user_id')->where(['game' => $game->slug(), 'account_id' => $accountId])->update(['user_id' => null]);
        }

        return $moved;
    }

    /**
     * An admin confirms that the account id is this player's: it maps to the
     * player from now on, and every pending run of it is handed over. Only a
     * player who stored the id can get it, and no admin confirms their own.
     *
     * @throws TournamentRuleViolation
     */
    public static function confirm(ScoreGame $game, string $accountId, User $player, User $admin): int
    {
        $accountId = trim($accountId);

        if (! $admin->isAdmin()) {
            throw new TournamentRuleViolation('not_admin', __('Only an admin confirms an account.'));
        }

        if ($player->id === $admin->id) {
            throw new TournamentRuleViolation('interested', __('This is your own account, so another admin has to confirm it.'));
        }

        if (! in_array($player->id, self::claimers($game, $accountId), true)) {
            throw new TournamentRuleViolation('not_claimed', __('This player has not stored that account id.'));
        }

        return DB::transaction(function () use ($game, $accountId, $player, $admin): int {
            ScoreAccountClaim::query()->updateOrCreate(['game' => $game->slug(), 'account_id' => $accountId], ['user_id' => $player->id, 'confirmed_by_id' => $admin->id]);

            $moved = 0;

            foreach (ScoreRun::query()->whereNull('user_id')->where(['game' => $game->slug(), 'account_id' => $accountId])->orderBy('id')->get() as $run) {
                try {
                    $run->forceFill(['user_id' => $player->id])->save();
                    $moved++;
                } catch (UniqueConstraintViolationException) {
                    // The player already has this very record (same source, course, value and time): a second copy counts nothing.
                    $run->delete();
                }
            }

            return $moved;
        });
    }

    /**
     * The pending runs of each game account id, for the admin review: the
     * game, the id, how many runs wait, and who stored the id.
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
}
