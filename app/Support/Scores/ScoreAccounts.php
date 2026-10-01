<?php

namespace App\Support\Scores;

use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Models\ScoreRun;
use App\Models\User;

/**
 * The private link between a league player and their account in a score
 * game (plan "AoE2 und Trackmania", P4). The account id is stored like a
 * gamer tag (users.gamer_tags, under ScoreGame::accountService()) and, like
 * one, never leaves the settings: no public page, no Nostr event names it.
 * The league reads it only to ask a game's API or to map a server's finish.
 *
 * A player's claim is not proven yet (a later phase logs in with the game);
 * so an id that more than one player stored maps to nobody.
 */
final class ScoreAccounts
{
    /**
     * The league player who stored this account id for the game, or null
     * when nobody, or more than one player, did.
     */
    public static function userFor(ScoreGame $game, string $accountId): ?int
    {
        $service = $game->accountService();
        $accountId = trim($accountId);

        if ($service === null || $accountId === '') {
            return null;
        }

        $ids = User::query()->where("gamer_tags->{$service}", $accountId)->limit(2)->pluck('id')->all();

        return count($ids) === 1 ? (int) $ids[0] : null;
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
     * Hand the pending runs of the player's stored account ids to the player,
     * where the id is theirs alone. Returns how many runs moved.
     */
    public static function claim(User $user): int
    {
        $moved = 0;

        foreach (app(GameRegistry::class)->scores() as $game) {
            $service = $game->accountService();
            $accountId = $service === null ? '' : trim((string) ($user->gamer_tags[$service] ?? ''));

            if ($accountId === '' || self::userFor($game, $accountId) !== $user->id) {
                continue;
            }

            $moved += ScoreRun::query()->whereNull('user_id')->where(['game' => $game->slug(), 'account_id' => $accountId])->update(['user_id' => $user->id]);
        }

        return $moved;
    }
}
