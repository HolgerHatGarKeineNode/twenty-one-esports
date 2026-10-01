<?php

namespace App\Support\Scores;

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ScoreAccountChange;
use App\Models\ScoreAccountClaim;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
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
     * Remember when the player stored (or changed) the account id of each
     * score game, from their gamer tags as just saved: a leaderboard waits
     * only for an id stored before its window closed (round-3 S1).
     */
    public static function recordStored(User $user): void
    {
        foreach (app(GameRegistry::class)->scores() as $game) {
            $service = $game->accountService();

            if ($service === null) {
                continue;
            }

            $accountId = trim((string) ($user->gamer_tags[$service] ?? ''));
            $row = DB::table('score_account_tags')->where(['user_id' => $user->id, 'game' => $game->slug()]);

            if ($accountId === '') {
                $row->delete();
            } elseif ($row->value('account_id') !== $accountId) {
                DB::table('score_account_tags')->updateOrInsert(['user_id' => $user->id, 'game' => $game->slug()], ['account_id' => $accountId, 'stored_at' => now()]);
            }
        }
    }

    /**
     * What keeps a leaderboard from ending: the account ids its active entries
     * (not disqualified, not withdrawn) stored before its window closed, that
     * no admin dismissed for that entry, and that have a pending finish on its
     * course inside the window.
     *
     * @return list<array{account: string, user_id: int}>
     */
    public static function blockingFor(Tournament $tournament, ScoreGame $game): array
    {
        if ($game->accountService() === null) {
            return [];
        }

        $window = ScoreWindow::of($tournament);
        $active = TournamentParticipant::query()->where('tournament_id', $tournament->id)->whereNull('disqualified_at')
            ->whereIn('user_id', User::query()->select('id'))->pluck('user_id')->map(intval(...))->all();
        $tags = DB::table('score_account_tags')->where('game', $game->slug())->whereIn('user_id', $active)->where('stored_at', '<', $window->end)
            ->get(['user_id', 'account_id']);
        $blocking = [];

        foreach ($tags as $tag) {
            $dismissed = ScoreAccountChange::query()->where(['game' => $game->slug(), 'account_id' => $tag->account_id, 'action' => 'dismiss', 'from_user_id' => $tag->user_id])->exists();
            $waits = ! $dismissed && ScoreRun::query()->whereNull('user_id')
                ->where(['game' => $game->slug(), 'mode' => $tournament->mode, 'course' => (string) $tournament->score_course, 'account_id' => $tag->account_id])
                ->where('achieved_at', '>=', $window->start)->where('achieved_at', '<', $window->end)->exists();

            if ($waits) {
                $blocking[] = ['account' => (string) $tag->account_id, 'user_id' => (int) $tag->user_id];
            }
        }

        return $blocking;
    }

    public static function waitsFor(Tournament $tournament, ScoreGame $game): bool
    {
        return self::blockingFor($tournament, $game) !== [];
    }

    /**
     * The (game, account id) pairs that keep a running or open leaderboard
     * of a registered score game from ending, keyed "game|account".
     *
     * @return array<string, true>
     */
    public static function blocking(): array
    {
        $keys = [];

        foreach (app(GameRegistry::class)->scores() as $game) {
            foreach (Tournament::query()->where('game', $game->slug())->where('status', TournamentStatus::Running)->get() as $tournament) {
                foreach (self::blockingFor($tournament, $game) as $item) {
                    $keys[$game->slug().'|'.$item['account']] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * The pending runs of each game account id, for the admin review: those
     * that keep a leaderboard from ending first, then those an entrant of a
     * running or open leaderboard stored, then the others by id (round-3
     * S2); unclaimed groups an admin dismissed are left out. `$total` gets
     * how many groups there are in all.
     *
     * @return list<array{game: ScoreGame, account: string, runs: int, blocks: bool, claimers: list<User>}>
     */
    public static function pending(int $limit = 20, int &$total = 0): array
    {
        $blocking = self::blocking();
        $entrants = self::entrantTags();
        $dismissed = ScoreAccountChange::query()->where('action', 'dismiss')->whereNull('from_user_id')->get(['game', 'account_id'])
            ->mapWithKeys(fn (ScoreAccountChange $change): array => [$change->game.'|'.$change->account_id => true])->all();
        $rows = ScoreRun::query()->whereNull('user_id')->whereNotNull('account_id')
            ->selectRaw('game, account_id, count(*) as runs')->groupBy('game', 'account_id')->orderBy('game')->orderBy('account_id')->get();
        $groups = [];

        foreach ($rows as $row) {
            $game = app(GameRegistry::class)->find((string) $row->game);

            if (! $game instanceof ScoreGame) {
                continue;
            }

            $account = (string) $row->getAttribute('account_id');
            $key = $game->slug().'|'.$account;

            // A dismissed id comes back once a player stores it.
            if (isset($dismissed[$key]) && self::claimers($game, $account) === []) {
                continue;
            }

            $groups[] = ['game' => $game, 'account' => $account, 'runs' => (int) $row->getAttribute('runs'), 'blocks' => isset($blocking[$key]),
                'tier' => isset($blocking[$key]) ? 0 : (isset($entrants[$key]) ? 1 : 2)];
        }

        usort($groups, fn (array $a, array $b): int => [$a['tier'], $a['game']->slug(), $a['account']] <=> [$b['tier'], $b['game']->slug(), $b['account']]);
        $total = count($groups);

        return array_map(fn (array $group): array => ['game' => $group['game'], 'account' => $group['account'], 'runs' => $group['runs'], 'blocks' => $group['blocks'],
            'claimers' => array_values(User::query()->whereKey(self::claimers($group['game'], $group['account']))->orderBy('id')->get()->all())],
            array_slice($groups, 0, max(1, $limit)));
    }

    /**
     * The (game, account id) pairs stored by a player of a running score
     * leaderboard or signed up to an open one, keyed "game|account".
     *
     * @return array<string, bool>
     */
    private static function entrantTags(): array
    {
        $games = array_keys(app(GameRegistry::class)->scores());
        $running = TournamentParticipant::query()->whereIn('tournament_id', Tournament::query()->whereIn('game', $games)->where('status', TournamentStatus::Running)->select('id'))
            ->pluck('user_id')->filter()->all();
        $open = TournamentSignup::query()->whereIn('tournament_id', Tournament::query()->whereIn('game', $games)->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing])->select('id'))
            ->active()->pluck('members')->flatten()->all();

        return DB::table('score_account_tags')->whereIn('game', $games)->whereIn('user_id', array_values(array_unique(array_map(intval(...), [...$running, ...$open]))))
            ->get(['game', 'account_id'])->mapWithKeys(fn (object $tag): array => [$tag->game.'|'.$tag->account_id => true])->all();
    }

    /**
     * The admin nav badge: the account ids that keep a leaderboard from ending.
     */
    public static function pendingAccounts(): int
    {
        return count(self::blocking());
    }

    /**
     * The confirmed claims, newest first, for the admin review; `$total` gets how many there are.
     *
     * @return Collection<int, ScoreAccountClaim>
     */
    public static function confirmed(int $limit = 20, int &$total = 0): Collection
    {
        $total = ScoreAccountClaim::query()->count();

        return ScoreAccountClaim::query()->with('user')->latest('updated_at')->latest('id')->limit(max(1, $limit))->get();
    }

    /**
     * An admin says a waiting id is not this entry's ("not this player's"):
     * the entry no longer holds its leaderboard for that id, and nothing is
     * mapped. With no entry: an id nobody stored leaves the review list
     * until somebody stores it.
     *
     * @throws TournamentRuleViolation
     */
    public static function dismiss(ScoreGame $game, string $accountId, ?User $entrant, User $admin, string $reason): void
    {
        $accountId = trim($accountId);
        $reason = self::reason($reason);
        self::assertMayDecide($game, $accountId, $entrant === null ? [] : [$entrant->id], $admin);

        if ($entrant === null && self::claimers($game, $accountId) !== []) {
            throw new TournamentRuleViolation('claimed', __('Players stored this id: dismiss it for one of them, or confirm it.'));
        }

        self::log($game, $accountId, 'dismiss', $entrant?->id, null, $admin, $reason, 0);
    }

    /**
     * The league ends a leaderboard whose review time is over although ids
     * of its entries still wait (round-3 S1: its end never depends on
     * anyone): each is logged as left out.
     */
    public static function leaveOut(Tournament $tournament, ScoreGame $game): void
    {
        foreach (self::blockingFor($tournament, $game) as $item) {
            self::log($game, $item['account'], 'left_out', $item['user_id'], null, null, 'left out: pending (leaderboard '.$tournament->id.' ended after its review time)', 0);
        }
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

        $running = Tournament::query()->where(['game' => $game->slug(), 'status' => TournamentStatus::Running])
            ->whereHas('participants', fn ($query) => $query->whereIn('user_id', $players))->get();
        // Round-3 S6: an open board counts too, through its active sign-ups.
        $open = Tournament::query()->where('game', $game->slug())->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing])->get();

        foreach ($running as $tournament) {
            if (ScoreLeaderboards::interested($tournament, $admin)) {
                throw new TournamentRuleViolation('interested', __('You have an interest in a running leaderboard of this game that a claimer plays in, so another admin has to decide this account.'));
            }
        }

        foreach ($open as $tournament) {
            $signedUp = TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->pluck('members')->flatten()->map(intval(...))->unique()->values()->all();

            if (array_intersect($players, $signedUp) !== [] && self::sharesStake($admin, $signedUp, $players)) {
                throw new TournamentRuleViolation('interested', __('You have an interest in a running leaderboard of this game that a claimer plays in, so another admin has to decide this account.'));
            }
        }
    }

    /**
     * Whether the admin is signed up to that board, or shares a clan with a
     * claimer signed up to it (the stakes of the tournament gate P8b before
     * there is a board to read them from).
     *
     * @param  array<int, int>  $signedUp
     * @param  array<int, int>  $players
     */
    private static function sharesStake(User $admin, array $signedUp, array $players): bool
    {
        if (in_array($admin->id, $signedUp, true)) {
            return true;
        }

        $claimersIn = array_values(array_intersect($players, $signedUp));
        $clans = ClanMember::query()->whereIn('user_id', $claimersIn)->pluck('clan_id')->merge(Clan::query()->whereIn('owner_id', $claimersIn)->pluck('id'))->unique()->all();

        return $clans !== [] && (ClanMember::query()->where('user_id', $admin->id)->whereIn('clan_id', $clans)->exists()
            || Clan::query()->where('owner_id', $admin->id)->whereKey($clans)->exists());
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

    /**
     * One line of the account log, with the pubkeys of the admin and the
     * players at the time (round-3 S5): a deleted account keeps its line.
     */
    private static function log(ScoreGame $game, string $accountId, string $action, ?int $from, ?int $to, ?User $admin, string $reason, int $moved): void
    {
        $pubkeys = User::query()->whereKey(array_filter([$from, $to]))->pluck('pubkey', 'id');

        ScoreAccountChange::query()->create(['game' => $game->slug(), 'account_id' => $accountId, 'action' => $action, 'from_user_id' => $from, 'to_user_id' => $to,
            'admin_id' => $admin?->id, 'admin_pubkey' => $admin?->pubkey, 'from_pubkey' => $from === null ? null : $pubkeys->get($from), 'to_pubkey' => $to === null ? null : $pubkeys->get($to),
            'reason' => $reason, 'runs_moved' => $moved, 'created_at' => now()]);
    }
}
