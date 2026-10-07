<?php

namespace App\Support\Invites;

use App\Enums\InviteLinkType;
use App\Enums\NotificationKind;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanJoinRequest;
use App\Models\ClanMember;
use App\Models\InviteLink;
use App\Models\InviteLinkUse;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Board\BoardChallenges;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessModes;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\ChessTransaction;
use App\Support\Chess\DailyChallenges;
use App\Support\Clans\ClanJoinRequests;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Engagement\Cosmetics;
use App\Support\GameNames;
use App\Support\Notifications\BoardNotifications;
use App\Support\Notifications\ChessNotifications;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\CupMatchNow;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Invite deep links `/i/{code}` (P6b, plan "Einladungs-Deep-Links"):
 *
 *  - game links (blitz, rapid, daily chess, a Rocket League series) are OPEN:
 *    whoever accepts plays. A one-time link goes to the first who accepts;
 *    a link for "several times" starts one game per player who takes it;
 *  - board links (nine men's morris, checkers) are open as well and start
 *    a casual board game in the link's mode;
 *  - clan links only send a join request a captain confirms
 *    (ClanJoinRequests);
 *  - score links ("beat my time", Blockfill) are never taken: the landing
 *    opens the game with the inviter's best, one open link per game
 *    ({@see scoreLink()});
 *  - named invites (a player picked by name) are not links and stay direct.
 *
 * Everything happens at acceptance, nothing when the link is made: the game
 * starts, the series takes its league match number (SeriesService reserves
 * it while the challenge is built), the request is stored.
 *
 * The one-time rule is a single conditional UPDATE (`uses < max_uses`)
 * inside the acceptance transaction, before anything else is written. Under
 * Postgres the loser of a race waits on the row lock and then matches no row;
 * any refusal later in the transaction rolls the claim back.
 *
 * Every link game is casual and a referral never counts toward ratings,
 * blocks or rewards: links are the easiest thing to farm.
 *
 * A tournament link (P47) is different: every player has at most one per
 * tournament ({@see forTournament()}), it is never "taken" on the landing
 * (the landing opens the tournament page and remembers the link), and the
 * referral is credited only when the invited player signs up
 * ({@see creditTournamentSignup()}), because a sign-up is signed by the
 * player (NIP rev. 7, 22150) and cannot happen on someone else's click.
 */
final class InviteLinks
{
    /** Open links one player may have at a time, so nobody floods the table. */
    public const MAX_OPEN_PER_INVITER = 20;

    public function __construct(
        private ChessGameService $games,
        private SeriesService $series,
        private ClanJoinRequests $joinRequests,
        private ChessNotifications $chessNotifications,
        private Notifier $notifier,
        private GameRegistry $registry,
        private BoardGameService $boards,
        private BoardNotifications $boardNotifications,
    ) {}

    /* ---------- Making a link ------------------------------------------------------------------------------------ */

    /**
     * @param  array{uses?: string, hours?: int, color?: string, clan?: Clan, lineup_id?: int, best_of?: int, proposals?: list<int>, respond_by?: int, message?: string|null, game?: string, mode?: string}  $options
     *
     * @throws InviteLinkRefused
     */
    public function create(User $inviter, InviteLinkType $type, array $options = []): InviteLink
    {
        if ($type === InviteLinkType::Tournament) {
            throw new InviteLinkRefused('tournament', __('A tournament link comes from the tournament page.'));
        }

        // Before the cap: an open score link is handed out again, so asking twice never counts twice.
        if ($type === InviteLinkType::Score) {
            return $this->scoreLink($inviter, (string) ($options['game'] ?? ''));
        }

        // Tournament links are one per tournament (forTournament()) and do not count against the cap.
        $open = InviteLink::query()->where('inviter_id', $inviter->id)->where('type', '!=', InviteLinkType::Tournament)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->count();

        if ($open >= self::MAX_OPEN_PER_INVITER) {
            throw new InviteLinkRefused('too_many', __('You have :n open invite links. Cancel one before you make another.', ['n' => $open]));
        }

        $uses = $options['uses'] ?? ($type === InviteLinkType::Clan ? 'several' : 'once');

        if (! in_array($uses, ['once', 'several'], true)) {
            throw new InviteLinkRefused('uses', __('Pick who can use the link.'));
        }

        $attributes = [
            'code' => InviteLink::newCode(),
            'type' => $type,
            'inviter_id' => $inviter->id,
            'max_uses' => $uses === 'once' ? 1 : null,
            'options' => [],
        ];

        if ($type === InviteLinkType::Series) {
            [$attributes['options'], $expires] = $this->seriesOptions($inviter, $options);
            $attributes['expires_at'] = $expires;
        } else {
            $hours = (int) ($options['hours'] ?? $type->defaultExpiryHours());

            if (! in_array($hours, $type->expiryChoices(), true)) {
                throw new InviteLinkRefused('hours', __('Pick how long the link works.'));
            }

            $attributes['expires_at'] = now()->addHours($hours);
        }

        if ($type === InviteLinkType::Daily) {
            $color = $options['color'] ?? 'random';

            if (! in_array($color, DailyChallenges::COLORS, true)) {
                throw new InviteLinkRefused('color', __('Pick a colour.'));
            }

            $attributes['options'] = ['color' => $color];
        }

        if ($type === InviteLinkType::Board) {
            $attributes['options'] = $this->boardOptions($options);
        }

        if ($type === InviteLinkType::Clan) {
            $clan = $options['clan'] ?? null;

            if (! $clan instanceof Clan || ! $clan->isCaptain($inviter)) {
                throw new InviteLinkRefused('not_captain', __('Only a captain can make a join link for the clan.'));
            }

            $attributes['clan_id'] = $clan->id;
        }

        return InviteLink::query()->create($attributes);
    }

    /**
     * A board link: the board game, one of its modes a link can start, and
     * for correspondence the inviter's colour (as a daily chess link).
     *
     * @param  array<string, mixed>  $options
     * @return array{game: string, mode: string, color?: string}
     */
    private function boardOptions(array $options): array
    {
        $slug = (string) ($options['game'] ?? '');
        $mode = (string) ($options['mode'] ?? '');

        if (! $this->registry->isBoard($slug) || ! in_array($mode, app(InviteGames::class)->find($slug)['modes'] ?? [], true)) {
            throw new InviteLinkRefused('game', __('Pick a game.'));
        }

        if ($mode !== BoardGame::CORRESPONDENCE) {
            return ['game' => $slug, 'mode' => $mode];
        }

        $color = (string) ($options['color'] ?? 'random');

        if (! in_array($color, BoardChallenges::COLORS, true)) {
            throw new InviteLinkRefused('color', __('Pick a colour.'));
        }

        return ['game' => $slug, 'mode' => $mode, 'color' => $color];
    }

    /**
     * "Beat my time" in a score game: one open link per player and game, for
     * a week, made on first use and handed out again after, so a player who
     * asks twice shares the same link. A link with less than a day left is
     * not handed out again: a fresh one is made.
     *
     * @throws InviteLinkRefused
     */
    private function scoreLink(User $inviter, string $slug): InviteLink
    {
        if (! $this->registry->isScore($slug)) {
            throw new InviteLinkRefused('game', __('Pick a game.'));
        }

        $open = InviteLink::query()->where(['inviter_id' => $inviter->id, 'type' => InviteLinkType::Score])
            ->where('options->game', $slug)->whereNull('revoked_at')->where('expires_at', '>', now()->addDay())
            ->latest('id')->first();

        return $open ?? InviteLink::query()->create([
            'code' => InviteLink::newCode(),
            'type' => InviteLinkType::Score,
            'inviter_id' => $inviter->id,
            'options' => ['game' => $slug],
            'max_uses' => null,
            'expires_at' => now()->addHours(InviteLinkType::Score->defaultExpiryHours()),
        ]);
    }

    /**
     * A series link: the captain's lineup, format and proposed starts. The
     * link works until the reply deadline (never past the first start).
     *
     * @param  array<string, mixed>  $options
     * @return array{0: array<string, mixed>, 1: CarbonInterface}
     */
    private function seriesOptions(User $inviter, array $options): array
    {
        $lineup = Lineup::query()->with('clan')->find((int) ($options['lineup_id'] ?? 0));

        if ($lineup === null || ! $lineup->isActingCaptain($inviter)) {
            throw new InviteLinkRefused('not_captain', __('Only a captain of the lineup can send a challenge.'));
        }

        $mode = $this->registry->mode($lineup->game, $lineup->mode);
        $bestOf = (int) ($options['best_of'] ?? 0);

        if ($mode === null || ! $mode->allowsBestOf($bestOf)) {
            throw new InviteLinkRefused('best_of', __('This format is not allowed.'));
        }

        $now = now()->getTimestamp();
        $proposals = array_values(array_unique(array_map(intval(...), (array) ($options['proposals'] ?? []))));
        sort($proposals);
        $planLimit = $now + (int) config('esports.series.plan_max_days', 14) * 86400;

        if ($proposals === [] || count($proposals) > 3) {
            throw new InviteLinkRefused('proposals', __('Suggest one to three times.'));
        }

        foreach ($proposals as $start) {
            if ($start <= $now || $start > $planLimit) {
                throw new InviteLinkRefused('proposals', __('Every suggested time has to lie in the future, at most :days days ahead.', ['days' => (int) config('esports.series.plan_max_days', 14)]));
            }
        }

        $respondBy = (int) ($options['respond_by'] ?? 0);
        $respondLimit = $now + (int) config('esports.series.respond_max_days', 7) * 86400;

        if ($respondBy <= $now || $respondBy > $respondLimit || $respondBy > $proposals[0]) {
            throw new InviteLinkRefused('respond_by', __('The reply deadline has to be in the future, at the latest at the first suggested time.'));
        }

        $message = trim((string) ($options['message'] ?? ''));

        if (mb_strlen($message) > 140) {
            throw new InviteLinkRefused('message', __('The message can be at most 140 characters.'));
        }

        return [[
            'lineup_id' => $lineup->id,
            'game' => $lineup->game,
            'mode' => $lineup->mode,
            'best_of' => $bestOf,
            'proposals' => $proposals,
            'message' => $message === '' ? null : $message,
        ], now()->setTimestamp($respondBy)];
    }

    /**
     * The player's personal link to a tournament (P47): one per player and
     * tournament, made on first use and the same ever after, so the "I'm in"
     * post and every invite DM carry the same link. Open until sign-up
     * closes; offered while sign-up is open or the draw waits, for a
     * published tournament everyone can see.
     *
     * @throws InviteLinkRefused
     */
    public function forTournament(User $inviter, Tournament $tournament): InviteLink
    {
        if ($tournament->published_at === null || ! $tournament->isVisibleTo(null)
            || ! in_array($tournament->status, [TournamentStatus::Signup, TournamentStatus::Drawing], true)) {
            throw new InviteLinkRefused('tournament_closed', __('Sign-up for this tournament is not open.'));
        }

        $closes = $tournament->signup_closes_at ?? $tournament->starts_at;

        // One row per (inviter, tournament): two first uses at once meet the unique index, and the loser reads the winner's row.
        $link = InviteLink::query()->createOrFirst(
            ['inviter_id' => $inviter->id, 'tournament_id' => $tournament->id],
            ['code' => InviteLink::newCode(), 'type' => InviteLinkType::Tournament, 'options' => [], 'max_uses' => null, 'expires_at' => $closes],
        );

        // Sign-up was extended (a casual cup's one extension): the link follows it.
        if (! $link->wasRecentlyCreated && ! $link->expires_at->equalTo($closes)) {
            $link->forceFill(['expires_at' => $closes])->save();
        }

        return $link;
    }

    /**
     * The viewer's personal tournament link, or null for a guest or while
     * the tournament takes no sign-ups (then the page link is the invite).
     */
    public function tournamentUrlFor(?User $viewer, Tournament $tournament): ?string
    {
        if ($viewer === null) {
            return null;
        }

        try {
            return $this->forTournament($viewer, $tournament)->url();
        } catch (InviteLinkRefused) {
            return null;
        }
    }

    /**
     * What the tournament landing remembers of a tournament link for the
     * sign-up that credits it. Nothing for the inviter's own link.
     *
     * @return array{code: string, tournament_id: int, seen_at: int}|null
     */
    public function rememberTournamentLink(InviteLink $link, ?User $viewer): ?array
    {
        if ($link->type !== InviteLinkType::Tournament || $link->tournament_id === null || ($viewer !== null && $viewer->id === $link->inviter_id)) {
            return null;
        }

        return ['code' => $link->code, 'tournament_id' => $link->tournament_id, 'seen_at' => now()->getTimestamp()];
    }

    /**
     * Credit the referral of a tournament link once the invited player is in:
     * the use (inviter, player, whether the account is new) and the "Brought
     * a friend" frame for both, as for every other link. Only for an open
     * link of this tournament that is not the player's own, and once per
     * player and link (the unique use). Returns the new use, or null when
     * nothing was credited.
     *
     * @param  array<string, mixed>|null  $remembered  what {@see rememberTournamentLink()} returned
     */
    public function creditTournamentSignup(Tournament $tournament, User $user, ?array $remembered): ?InviteLinkUse
    {
        if ($remembered === null || (int) ($remembered['tournament_id'] ?? 0) !== $tournament->id || ! is_string($remembered['code'] ?? null)) {
            return null;
        }

        $link = InviteLink::query()->where('code', $remembered['code'])->where('type', InviteLinkType::Tournament)
            ->where('tournament_id', $tournament->id)->first();

        if ($link === null || $link->inviter_id === $user->id || $link->isRevoked() || $link->isExpired()) {
            return null;
        }

        $wasNew = $user->created_at !== null && $user->created_at->getTimestamp() >= (int) ($remembered['seen_at'] ?? PHP_INT_MAX);

        return DB::transaction(function () use ($link, $user, $wasNew): ?InviteLinkUse {
            $use = InviteLinkUse::query()->createOrFirst(
                ['invite_link_id' => $link->id, 'user_id' => $user->id],
                ['inviter_id' => $link->inviter_id, 'was_new' => $wasNew],
            );

            if (! $use->wasRecentlyCreated) {
                return null;
            }

            InviteLink::query()->whereKey($link->id)->increment('uses');
            app(Cosmetics::class)->creditInvite($use);

            return $use;
        });
    }

    public function revoke(InviteLink $link, User $user): void
    {
        if ($link->inviter_id !== $user->id) {
            throw new InviteLinkRefused('not_yours', __('Only the player who made the link can cancel it.'));
        }

        InviteLink::query()->whereKey($link->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /* ---------- What the landing shows --------------------------------------------------------------------------- */

    /**
     * The link's state for this viewer:
     * open | own | taken (by this viewer: their game, request) | expired |
     * used_up | revoked | member (already in the clan) | requested.
     */
    public function state(InviteLink $link, ?User $viewer): string
    {
        if ($viewer !== null && $this->useOf($link, $viewer) !== null) {
            return 'taken';
        }

        if ($viewer !== null && $link->inviter_id === $viewer->id) {
            return $link->isRevoked() ? 'revoked' : ($link->isExpired() ? 'expired' : ($link->isUsedUp() ? 'used_up' : 'own'));
        }

        if ($link->type === InviteLinkType::Clan && $viewer !== null && $link->clan !== null) {
            if (ClanMember::query()->where(['clan_id' => $link->clan_id, 'user_id' => $viewer->id])->exists()) {
                return 'member';
            }

            if ($this->joinRequests->openFor($link->clan, $viewer) !== null) {
                return 'requested';
            }
        }

        return match (true) {
            $link->isRevoked() => 'revoked',
            $link->isExpired() => 'expired',
            $link->isUsedUp() => 'used_up',
            default => 'open',
        };
    }

    public function useOf(InviteLink $link, User $user): ?InviteLinkUse
    {
        return InviteLinkUse::query()->where(['invite_link_id' => $link->id, 'user_id' => $user->id])->first();
    }

    /* ---------- Accepting ---------------------------------------------------------------------------------------- */

    /**
     * Take the link. Returns what it made: the chess game, the series match,
     * or the clan join request.
     *
     * @param  array{lineup_id?: int, start?: int}  $choice  series only: the taker's lineup and one proposed start
     * @param  bool  $wasNew  the account was made by the login the player started on this invite (referral)
     *
     * @throws InviteLinkRefused
     */
    public function accept(InviteLink $link, User $user, array $choice = [], bool $wasNew = false): Model
    {
        try {
            $result = ChessTransaction::run(function () use ($link, $user, $choice, $wasNew): Model {
                $link = InviteLink::query()->with(['inviter', 'clan'])->lockForUpdate()->findOrFail($link->id);

                if ($link->inviter_id === $user->id) {
                    throw new InviteLinkRefused('own_link', __('This is your own link. Send it to a friend.'));
                }

                if ($this->useOf($link, $user) !== null) {
                    throw new InviteLinkRefused('already_taken', __('You already took this invite.'));
                }

                // The casual lock (user, 2026-10-03): an open cup match in a running round comes first; a clan or tournament link is no game.
                $cup = in_array($link->type, [InviteLinkType::Blitz, InviteLinkType::Rapid, InviteLinkType::Daily, InviteLinkType::Board, InviteLinkType::Series], true)
                    ? CupMatchNow::refusal($user, $link->inviter) : null;

                if ($cup !== null) {
                    throw new InviteLinkRefused($cup['reason'], $cup['message']);
                }

                $this->claim($link);

                $use = new InviteLinkUse(['invite_link_id' => $link->id, 'inviter_id' => $link->inviter_id, 'user_id' => $user->id, 'was_new' => $wasNew]);

                $made = match ($link->type) {
                    InviteLinkType::Blitz, InviteLinkType::Rapid, InviteLinkType::Daily => $this->startGame($link, $user),
                    InviteLinkType::Board => $this->startBoardGame($link, $user),
                    InviteLinkType::Series => $this->startSeries($link, $user, $choice),
                    InviteLinkType::Clan => $this->requestJoin($link, $user),
                    // Credited at sign-up (creditTournamentSignup()); the landing only opens the tournament.
                    InviteLinkType::Tournament => throw new InviteLinkRefused('tournament', __('Sign up on the tournament page to take this invite.')),
                    // Nothing to take: the landing opens the game, where the time is beaten.
                    InviteLinkType::Score => throw new InviteLinkRefused('score', __('Play the game to beat this time.')),
                };

                $use->chess_game_id = $made instanceof ChessGame ? $made->id : null;
                $use->series_match_id = $made instanceof SeriesMatch ? $made->id : null;
                $use->save();

                // "Invite a friend, a cosmetic for both" (P10), in the same transaction as the use.
                app(Cosmetics::class)->creditInvite($use);

                return $made;
            });
        } catch (ChessRuleViolation $violation) {
            throw new InviteLinkRefused($violation->reason, $violation->reason === 'lost_race'
                ? __('Someone else was faster. Please try again.')
                : __('That did not work, please try again.'));
        }

        $this->announce($link->refresh(), $user, $result);

        return $result;
    }

    /**
     * One conditional UPDATE: open, not expired, not used up. Zero rows means
     * the link closed since the page was loaded (or a racing stranger won).
     */
    private function claim(InviteLink $link): void
    {
        $claimed = InviteLink::query()
            ->whereKey($link->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where(fn ($query) => $query->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'))
            ->increment('uses');

        if ($claimed === 1) {
            return;
        }

        $link->refresh();

        throw match (true) {
            $link->isRevoked() => new InviteLinkRefused('revoked', __('This invite was cancelled.')),
            $link->isExpired() => new InviteLinkRefused('expired', __('This invite has run out.')),
            default => new InviteLinkRefused('used_up', __('This invite is already taken.')),
        };
    }

    private function startGame(InviteLink $link, User $user): ChessGame
    {
        $inviter = $link->inviter;
        $blitz = $link->type->isLiveChess();

        if ($blitz && $this->games->activeGameOf($inviter) !== null) {
            throw new InviteLinkRefused('inviter_busy', __(':name is in another live game right now. Try again in a few minutes.', ['name' => $inviter->displayName()]));
        }

        if ($blitz && $this->games->activeGameOf($user) !== null) {
            throw new InviteLinkRefused('you_busy', __('You are in a live game. One live game at a time: finish it, then take the invite.'));
        }

        $inviterWhite = match ($blitz ? 'random' : (string) $link->option('color', 'random')) {
            'white' => true,
            'black' => false,
            default => random_int(0, 1) === 0,
        };

        [$white, $black] = $inviterWhite ? [$inviter, $user] : [$user, $inviter];

        return $this->games->start($white, $black, (string) $link->type->chessMode());
    }

    /**
     * A casual board game in the link's mode. The board game core refuses a
     * live game while either player is in one; that refusal rolls the claim
     * back, so the link stays open for later.
     */
    private function startBoardGame(InviteLink $link, User $user): BoardGame
    {
        $inviter = $link->inviter;
        $mode = (string) $link->option('mode', 'blitz');

        // A link made for a mode the game no longer offers (the board games' blitz, dropped 2026-10-07) starts nothing.
        if ($this->registry->mode((string) $link->option('game'), $mode) === null) {
            throw new InviteLinkRefused('board_mode_gone', __('This invite is for a mode the game no longer offers. Ask for a new link.'));
        }

        $inviterWhite = match ($mode === BoardGame::CORRESPONDENCE ? (string) $link->option('color', 'random') : 'random') {
            'white' => true,
            'black' => false,
            default => random_int(0, 1) === 0,
        };

        [$white, $black] = $inviterWhite ? [$inviter, $user] : [$user, $inviter];

        try {
            return $this->boards->start((string) $link->option('game'), $white, $black, $mode);
        } catch (BoardRuleViolation $violation) {
            $inviterBusy = $this->boards->activeGameOf($inviter) !== null || $this->games->activeGameOf($inviter) !== null;

            throw match ($violation->reason) {
                'already_playing', 'playing_elsewhere' => $inviterBusy
                    ? new InviteLinkRefused('inviter_busy', __(':name is in another live game right now. Try again in a few minutes.', ['name' => $inviter->displayName()]))
                    : new InviteLinkRefused('you_busy', __('You are in a live game. One live game at a time: finish it, then take the invite.')),
                default => new InviteLinkRefused('board_'.$violation->reason, __('That did not work, please try again.')),
            };
        }
    }

    /**
     * The board game a player started by taking a board link. A use stores
     * no board game of its own (its columns name a chess game or a series),
     * so it is this board game between the two that started with the use:
     * in the same transaction, so within the same second or two.
     */
    public function boardGameOf(InviteLinkUse $use): ?BoardGame
    {
        $link = $use->link;

        if ($link->type !== InviteLinkType::Board || $use->created_at === null) {
            return null;
        }

        return BoardGame::query()->where('game', (string) $link->option('game'))
            ->where(fn ($query) => $query->where(['white_id' => $use->inviter_id, 'black_id' => $use->user_id])
                ->orWhere(fn ($query) => $query->where(['white_id' => $use->user_id, 'black_id' => $use->inviter_id])))
            ->where('created_at', '>=', $use->created_at->copy()->subSeconds(2))
            ->oldest('id')->first();
    }

    /**
     * The inviter's lineup challenges the taker's lineup (casual: nothing to
     * sign) and the taker accepts one of the proposed starts, in one go.
     *
     * @param  array{lineup_id?: int, start?: int}  $choice
     */
    private function startSeries(InviteLink $link, User $user, array $choice): SeriesMatch
    {
        $lineup = Lineup::query()->with('clan')->find((int) ($choice['lineup_id'] ?? 0));

        if ($lineup === null || ! $lineup->isActingCaptain($user)) {
            throw new InviteLinkRefused('no_lineup', __('Take the challenge with a lineup you captain.'));
        }

        $draft = new ChallengeDraft(
            (int) $link->option('lineup_id'),
            $lineup->id,
            (int) $link->option('best_of'),
            false,
            array_values(array_map(intval(...), (array) $link->option('proposals', []))),
            $link->expires_at->getTimestamp(),
            $link->option('message'),
        );

        try {
            $match = $this->series->challenge($link->inviter, $draft, []);
            $this->series->answer($match, $user, 'accepted', isset($choice['start']) ? (int) $choice['start'] : null, []);
        } catch (SeriesRuleViolation $violation) {
            throw new InviteLinkRefused('series_'.$violation->reason, $violation->getMessage());
        }

        return $match->refresh();
    }

    private function requestJoin(InviteLink $link, User $user): ClanJoinRequest
    {
        if ($link->clan === null) {
            throw new InviteLinkRefused('revoked', __('This invite was cancelled.'));
        }

        try {
            return $this->joinRequests->request($link->clan, $user, $link);
        } catch (ClanRuleViolation $violation) {
            throw new InviteLinkRefused('clan', $violation->getMessage());
        }
    }

    /**
     * Tell the inviter. Daily games and join requests have their own notices
     * (DailyChallenges' "accepted your daily challenge", the captains'
     * "wants to join").
     */
    private function announce(InviteLink $link, User $user, Model $made): void
    {
        $inviter = $link->inviter;
        $locale = $inviter->locale ?? (string) config('app.locale');

        if ($made instanceof ChessGame && $link->type === InviteLinkType::Daily) {
            $this->chessNotifications->gameStarted($made, $inviter);

            return;
        }

        if ($made instanceof BoardGame && $made->isCorrespondence()) {
            $this->boardNotifications->gameStarted($made, $inviter);

            return;
        }

        if ($made instanceof BoardGame) {
            $this->notifier->send($inviter, NotificationKind::InviteAccepted, new Notice(
                __(':name took your :game invite', ['name' => $user->displayName(), 'game' => GameNames::game($made->game)], $locale),
                __(':game blitz 5+3 · Casual · the board is open.', ['game' => GameNames::game($made->game)], $locale),
                BoardNotifications::gameUrl($made),
                $made->id,
                __('Play now', [], $locale),
            ));

            return;
        }

        if ($made instanceof ChessGame) {
            $this->notifier->send($inviter, NotificationKind::InviteAccepted, new Notice(
                __(':name took your :mode invite', ['name' => $user->displayName(), 'mode' => ChessModes::short($made->mode)], $locale),
                __(':mode · Casual · the board is open.', ['mode' => ChessModes::label($made->mode)], $locale),
                route('games.show', $made),
                $made->id,
                __('Play now', [], $locale),
            ));

            return;
        }

        if ($made instanceof SeriesMatch) {
            $this->notifier->send($inviter, NotificationKind::InviteLinkTaken, new Notice(
                __(':clan took your challenge link', ['clan' => $made->challenged_name], $locale),
                __(':mode, best of :bo, match :number, starts :time.', [
                    'mode' => app(GameRegistry::class)->name($made->game).' '.$made->mode,
                    'bo' => $made->best_of,
                    'number' => $made->label(),
                    'time' => $made->start_at?->copy()->timezone($inviter->timezone ?? config('esports.preseason.display_timezone'))->format('D H:i') ?? '',
                ], $locale),
                route('matches.room', $made),
                $made->number,
            ));
        }
    }
}
