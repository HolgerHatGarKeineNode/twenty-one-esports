<?php

namespace App\Support\Invites;

use App\Enums\InviteLinkType;
use App\Enums\NotificationKind;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanJoinRequest;
use App\Models\ClanMember;
use App\Models\InviteLink;
use App\Models\InviteLinkUse;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\ChessTransaction;
use App\Support\Chess\DailyChallenges;
use App\Support\Clans\ClanJoinRequests;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Notifications\ChessNotifications;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Invite deep links `/i/{code}` (P6b, plan "Einladungs-Deep-Links"):
 *
 *  - game links (blitz, daily chess, a Rocket League series) are OPEN:
 *    whoever accepts plays. A one-time link goes to the first who accepts;
 *    a link for "several times" starts one game per player who takes it;
 *  - clan links only send a join request a captain confirms
 *    (ClanJoinRequests);
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
    ) {}

    /* ---------- Making a link ------------------------------------------------------------------------------------ */

    /**
     * @param  array{uses?: string, hours?: int, color?: string, clan?: Clan, lineup_id?: int, best_of?: int, proposals?: list<int>, respond_by?: int, message?: string|null}  $options
     *
     * @throws InviteLinkRefused
     */
    public function create(User $inviter, InviteLinkType $type, array $options = []): InviteLink
    {
        $open = InviteLink::query()->where('inviter_id', $inviter->id)->whereNull('revoked_at')->where('expires_at', '>', now())->count();

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

                $this->claim($link);

                $use = new InviteLinkUse(['invite_link_id' => $link->id, 'inviter_id' => $link->inviter_id, 'user_id' => $user->id, 'was_new' => $wasNew]);

                $made = match ($link->type) {
                    InviteLinkType::Blitz, InviteLinkType::Daily => $this->startGame($link, $user),
                    InviteLinkType::Series => $this->startSeries($link, $user, $choice),
                    InviteLinkType::Clan => $this->requestJoin($link, $user),
                };

                $use->chess_game_id = $made instanceof ChessGame ? $made->id : null;
                $use->series_match_id = $made instanceof SeriesMatch ? $made->id : null;
                $use->save();

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
        $blitz = $link->type === InviteLinkType::Blitz;

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

        return $this->games->start($white, $black, $blitz ? 'blitz' : ChessGame::CORRESPONDENCE);
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

        if ($made instanceof ChessGame) {
            $this->notifier->send($inviter, NotificationKind::InviteAccepted, new Notice(
                __(':name took your blitz invite', ['name' => $user->displayName()], $locale),
                __('Blitz 5+3 · Casual · the board is open.', [], $locale),
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
                    'mode' => 'Rocket League '.$made->mode,
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
