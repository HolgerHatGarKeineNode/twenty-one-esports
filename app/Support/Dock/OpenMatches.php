<?php

namespace App\Support\Dock;

use App\Enums\ChessGameStatus;
use App\Enums\ChessInviteStatus;
use App\Enums\InviteStatus;
use App\Enums\ReportStatus;
use App\Enums\SeriesStatus;
use App\Models\ChessChallenge;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Series\SeriesPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What a player has open, for the match dock (P5f, MatchDock.dc.html
 * "How the dock behaves"). This class is THE definition of an open item and
 * of "needs you": the dock counts with it, and any other count of open
 * matches (the Dashboard's "Open items", once that page exists) must call
 * summary() instead of counting on its own.
 *
 * What gets a tab: live blitz games, daily games in both directions, blitz
 * invites and daily challenges received, clan invites received, Rocket League
 * series that are live, that start within the next hour, that wait for this
 * player's answer or acceptance, or whose result waits for the other side or
 * an admin. Not on the dock: invites and challenges this player sent, and
 * scheduled series more than an hour away.
 *
 * Order: live first, then everything on this player by deadline, then what
 * waits for the other side by deadline. One query per kind, each capped at
 * KIND_LIMIT rows.
 */
final class OpenMatches
{
    public const KIND_LIMIT = 20;

    /** Scheduled series join the dock this long before their start. */
    public const STARTS_SOON_MS = 3_600_000;

    /** A daily clock turns red under 8 hours, a blitz clock under 30 seconds. */
    private const DAILY_RED_MS = 8 * 3_600_000;

    private const BLITZ_RED_MS = 30_000;

    private const GROUP_ORDER = ['live' => 0, 'need' => 1, 'wait' => 2];

    /**
     * @param  int|null  $excludeGame  the chess game on screen, which never has a tab
     * @param  int|null  $excludeSeries  the series (league match number) on screen
     * @return Collection<int, DockItem>
     */
    public function for(User $user, ?int $excludeGame = null, ?int $excludeSeries = null): Collection
    {
        $nowMs = (int) now()->getTimestampMs();

        $items = collect()
            ->concat($this->games($user, $excludeGame, $nowMs))
            ->concat($this->blitzInvites($user))
            ->concat($this->dailyChallenges($user))
            ->concat($this->clanInvites($user))
            ->concat($this->series($user, $excludeSeries, $nowMs));

        return $items
            ->sortBy(fn (DockItem $item) => [self::GROUP_ORDER[$item->group], $item->deadlineMs ?? PHP_INT_MAX, $item->key])
            ->values();
    }

    /**
     * The counts every "open items" figure uses.
     *
     * @return array{open: int, need: int, wait: int}
     */
    public function summary(User $user, ?int $excludeGame = null, ?int $excludeSeries = null): array
    {
        return self::count($this->for($user, $excludeGame, $excludeSeries));
    }

    /**
     * @param  Collection<int, DockItem>  $items
     * @return array{open: int, need: int, wait: int}
     */
    public static function count(Collection $items): array
    {
        $need = $items->filter(fn (DockItem $item) => $item->needsYou)->count();

        return ['open' => $items->count(), 'need' => $need, 'wait' => $items->count() - $need];
    }

    /**
     * The game or series on the current page, which the dock leaves out.
     * Reads the route, so it works for the page request only.
     *
     * @return array{game: int|null, series: int|null}
     */
    public static function onScreen(?Request $request): array
    {
        $route = $request?->route();
        $name = $route?->getName();

        $id = function (string $parameter, string $field) use ($route): ?int {
            $value = $route?->parameter($parameter);
            $value = is_object($value) ? ($value->{$field} ?? null) : $value;

            return is_numeric($value) ? (int) $value : null;
        };

        return [
            'game' => $name === 'games.show' ? $id('game', 'id') : null,
            'series' => in_array($name, ['matches.show', 'matches.room'], true) ? $id('match', 'number') : null,
        ];
    }

    /**
     * "7 h 28" (format hm) or "4:05" (format clock); the browser counts the
     * same way (resources/js/matchDock.js).
     */
    public static function format(int $ms, string $format): string
    {
        $ms = max(0, $ms);

        if ($format === 'clock') {
            $seconds = (int) ceil($ms / 1000);

            return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
        }

        $minutes = intdiv($ms, 60_000);
        $hours = intdiv($minutes, 60);

        return $hours > 0
            ? __(':h h :m', ['h' => $hours, 'm' => str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT)])
            : __(':m min', ['m' => $minutes]);
    }

    /**
     * @param  'live'|'need'|'wait'  $group
     * @param  'accept'|'answer'|'dispute'|'invite'|'live'|'starts'|'their_move'|'waiting'|'your_move'  $phase
     * @param  array{endsAt: int, format: 'clock'|'hm', total: int, redUnder: int}|null  $tick
     */
    private function seriesDockItem(SeriesMatch $match, string $other, string $group, string $phase, bool $needsYou, string $state, string $trailing, string $line, ?string $action, ?int $deadline, ?array $tick = null): DockItem
    {
        return new DockItem(
            key: 'series-'.$match->number,
            kind: 'series',
            group: $group,
            phase: $phase,
            needsYou: $needsYou,
            name: $match->sideName($other),
            face: null,
            tag: $match->sideTag($other),
            number: $match->label(),
            href: route('matches.room', $match),
            title: $match->ladder_address !== null ? self::text('Ladder series') : self::text('Series'),
            state: $state,
            trailing: $trailing,
            line: $line,
            sentence: self::text('Series :number against :name, :state', ['number' => $match->label(), 'name' => $match->sideName($other), 'state' => mb_strtolower($state)]),
            action: $action,
            deadlineMs: $deadline,
            tick: $tick,
            model: $match,
        );
    }

    /**
     * A translated line as a string (a translation file could map a key to an array).
     *
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    /**
     * @return list<DockItem>
     */
    private function games(User $user, ?int $exclude, int $nowMs): array
    {
        $games = ChessGame::query()
            ->playedBy($user)
            ->where('status', ChessGameStatus::Active)
            ->when($exclude !== null, fn ($query) => $query->whereKeyNot($exclude))
            ->with(['white', 'black'])
            ->latest('id')
            ->limit(self::KIND_LIMIT)
            ->get();

        return array_values($games->map(fn (ChessGame $game) => $game->isCorrespondence() ? $this->daily($game, $user, $nowMs) : $this->blitz($game, $user, $nowMs))->all());
    }

    private function blitz(ChessGame $game, User $user, int $nowMs): DockItem
    {
        $color = (string) $game->colorOf($user);
        $opponent = $game->opponentOf($user);
        $mine = $game->turn() === $color;
        $myMs = $color === 'w' ? $game->white_ms : $game->black_ms;

        // Before both first moves no clock runs, only the first-move timer.
        $endsAt = match (true) {
            ! $mine => null,
            $game->clocksRunning() => $game->turn_started_ms + $myMs,
            default => $game->deadline_ms,
        };
        $left = $endsAt === null ? $myMs : $endsAt - $nowMs;
        $state = $mine ? __('Your move') : __('Their move');
        $name = $opponent?->displayName() ?? '';

        return new DockItem(
            key: 'game-'.$game->id,
            kind: 'blitz',
            group: 'live',
            phase: $mine ? 'your_move' : 'their_move',
            needsYou: $mine,
            name: $name,
            face: $opponent,
            tag: null,
            number: $game->number(),
            href: route('games.show', $game),
            title: __('Blitz'),
            state: $state,
            trailing: self::format($left, 'clock'),
            line: __('Blitz :number, :state', ['number' => $game->number(), 'state' => mb_strtolower($state)]),
            sentence: __('Blitz :number against :name, :state, :left on your clock', ['number' => $game->number(), 'name' => $name, 'state' => mb_strtolower($state), 'left' => self::format($left, 'clock')]),
            action: $mine ? self::text('Play') : null,
            deadlineMs: $endsAt ?? $nowMs + $myMs,
            tick: $endsAt === null ? null : ['endsAt' => $endsAt, 'format' => 'clock', 'total' => max(1, $game->initial_ms), 'redUnder' => self::BLITZ_RED_MS],
            model: $game,
        );
    }

    private function daily(ChessGame $game, User $user, int $nowMs): DockItem
    {
        $color = (string) $game->colorOf($user);
        $opponent = $game->opponentOf($user);
        $mine = $game->turn() === $color;
        $deadline = $game->deadline_ms;
        $name = $opponent?->displayName() ?? '';
        $moveNumber = intdiv($game->ply, 2) + 1;

        if ($mine) {
            $left = $deadline === null ? null : self::format($deadline - $nowMs, 'hm');

            return new DockItem(
                key: 'game-'.$game->id,
                kind: 'daily',
                group: 'need',
                phase: 'your_move',
                needsYou: true,
                name: $name,
                face: $opponent,
                tag: null,
                number: $game->number(),
                href: route('games.show', $game),
                title: __('Daily chess'),
                state: __('Your move'),
                trailing: $left ?? '',
                line: __('Daily :number, your move', ['number' => $game->number()]),
                sentence: $left === null
                    ? __('Daily chess :number against :name, your move', ['number' => $game->number(), 'name' => $name])
                    : __('Daily chess :number against :name, your move, :left left', ['number' => $game->number(), 'name' => $name, 'left' => $left]),
                action: __('Play'),
                deadlineMs: $deadline,
                tick: $deadline === null ? null : ['endsAt' => $deadline, 'format' => 'hm', 'total' => max(1, $game->initial_ms), 'redUnder' => self::DAILY_RED_MS],
                model: $game,
            );
        }

        return new DockItem(
            key: 'game-'.$game->id,
            kind: 'daily',
            group: 'wait',
            phase: 'their_move',
            needsYou: false,
            name: $name,
            face: $opponent,
            tag: null,
            number: $game->number(),
            href: route('games.show', $game),
            title: __('Daily chess'),
            state: __('Their move'),
            trailing: __('move :n', ['n' => $moveNumber]),
            line: __('Daily :number, their move', ['number' => $game->number()]),
            sentence: __('Daily chess :number against :name, their move', ['number' => $game->number(), 'name' => $name]),
            action: null,
            deadlineMs: $deadline,
            tick: null,
            model: $game,
        );
    }

    /**
     * @return list<DockItem>
     */
    private function blitzInvites(User $user): array
    {
        $invites = ChessInvite::query()
            ->where('invitee_id', $user->id)
            ->where('status', ChessInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('inviter')
            ->latest('id')
            ->limit(self::KIND_LIMIT)
            ->get();

        return array_values($invites->map(function (ChessInvite $invite) {
            $endsAt = (int) $invite->expires_at->getTimestampMs();
            $name = $invite->inviter->displayName();
            $total = (int) config('esports.chess.invite_seconds', 120) * 1000;

            return new DockItem(
                key: 'invite-'.$invite->id,
                kind: 'blitz_invite',
                group: 'need',
                phase: 'answer',
                needsYou: true,
                name: $name,
                face: $invite->inviter,
                tag: null,
                number: '',
                href: route('chess.lobby'),
                title: __('Blitz invite'),
                state: __('Answer'),
                trailing: self::format($endsAt - (int) now()->getTimestampMs(), 'clock'),
                line: __('Blitz invite, answer now'),
                sentence: __(':name invites you to a blitz game', ['name' => $name]),
                action: __('Answer'),
                deadlineMs: $endsAt,
                tick: ['endsAt' => $endsAt, 'format' => 'clock', 'total' => max(1, $total), 'redUnder' => self::BLITZ_RED_MS],
                model: $invite,
            );
        })->all());
    }

    /**
     * @return list<DockItem>
     */
    private function dailyChallenges(User $user): array
    {
        $challenges = ChessChallenge::query()
            ->where('challenged_id', $user->id)
            ->where('status', ChessInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('challenger')
            ->latest('id')
            ->limit(self::KIND_LIMIT)
            ->get();

        return array_values($challenges->map(function (ChessChallenge $challenge) {
            $endsAt = (int) $challenge->expires_at->getTimestampMs();
            $name = $challenge->challenger->displayName();
            $left = self::format($endsAt - (int) now()->getTimestampMs(), 'hm');

            return new DockItem(
                key: 'challenge-'.$challenge->id,
                kind: 'daily_challenge',
                group: 'need',
                phase: 'answer',
                needsYou: true,
                name: $name,
                face: $challenge->challenger,
                tag: null,
                number: '',
                href: route('me.correspondence'),
                title: __('Daily challenge'),
                state: __('Answer'),
                trailing: $left,
                line: __('Daily challenge, answer within :left', ['left' => $left]),
                sentence: __(':name challenges you to daily chess, :left left to answer', ['name' => $name, 'left' => $left]),
                action: __('Answer'),
                deadlineMs: $endsAt,
                tick: ['endsAt' => $endsAt, 'format' => 'hm', 'total' => max(1, (int) config('esports.chess.challenge_hours', 48) * 3_600_000), 'redUnder' => self::DAILY_RED_MS],
                model: $challenge,
            );
        })->all());
    }

    /**
     * @return list<DockItem>
     */
    private function clanInvites(User $user): array
    {
        $invites = ClanInvite::query()
            ->where('invitee_id', $user->id)
            ->where('status', InviteStatus::Pending)
            ->with('clan')
            ->latest('id')
            ->limit(self::KIND_LIMIT)
            ->get();

        return array_values($invites->map(fn (ClanInvite $invite) => new DockItem(
            key: 'clan-invite-'.$invite->id,
            kind: 'clan_invite',
            group: 'need',
            phase: 'invite',
            needsYou: true,
            name: $invite->clan->name,
            face: null,
            tag: $invite->clan->clantag,
            number: '',
            href: route('invites.show', $invite),
            title: __('Clan invite'),
            state: __('Invited'),
            trailing: $invite->clan->clantag,
            line: __('Clan invite, answer it'),
            sentence: __(':clan invites you to join the clan', ['clan' => $invite->clan->name]),
            action: __('Answer'),
            deadlineMs: null,
            tick: null,
            model: $invite,
        ))->all());
    }

    /**
     * @return list<DockItem>
     */
    private function series(User $user, ?int $exclude, int $nowMs): array
    {
        $lineups = LineupSeat::query()->where('user_id', $user->id)->whereNotNull('accepted_at')->pluck('lineup_id')
            ->concat(Lineup::query()->whereIn('clan_id', Clan::query()->where('owner_id', $user->id)->select('id'))->pluck('id'))
            ->unique()
            ->values();

        if ($lineups->isEmpty()) {
            return [];
        }

        $user->loadMissing('clanMember');

        $matches = SeriesMatch::query()
            ->whereIn('status', [SeriesStatus::Open, SeriesStatus::Accepted, SeriesStatus::Reported, SeriesStatus::Disputed])
            ->where(fn ($query) => $query->whereIn('challenger_lineup_id', $lineups)->orWhereIn('challenged_lineup_id', $lineups))
            ->when($exclude !== null, fn ($query) => $query->where('number', '!=', $exclude))
            // Scheduled series join an hour before their start.
            ->where(fn ($query) => $query->where('status', '!=', SeriesStatus::Accepted)->orWhereNull('start_at')->orWhere('start_at', '<=', now()->addMilliseconds(self::STARTS_SOON_MS)))
            ->with(['challengerLineup.seats', 'challengerLineup.clan', 'challengedLineup.seats', 'challengedLineup.clan', 'latestReport'])
            ->latest('id')
            ->limit(self::KIND_LIMIT)
            ->get();

        // participantSideOf() reads this player's seat, its user and their
        // clan: those seats get the player loaded above (clanMember included)
        // instead of two lazy queries per series. The other seats' users are
        // never read here, so they are not loaded at all.
        foreach ($matches as $match) {
            foreach ([$match->challengerLineup, $match->challengedLineup] as $lineup) {
                $lineup?->seats->where('user_id', $user->id)->each(fn (LineupSeat $seat) => $seat->setRelation('user', $user));
            }
        }

        return array_values($matches->map(fn (SeriesMatch $match) => $this->seriesItem($match, $user, $nowMs))->filter()->values()->all());
    }

    private function seriesItem(SeriesMatch $match, User $user, int $nowMs): ?DockItem
    {
        $side = $match->participantSideOf($user);

        if ($side === null) {
            return null;
        }

        $captainSide = $match->captainSideOf($user);
        $other = SeriesMatch::otherSide($side);
        $score = SeriesMatch::seriesScore($match->currentGames());
        $scoreText = $score[$side].' : '.$score[$other];
        $report = $match->latestReport;

        return match ($match->status) {
            SeriesStatus::Open => $captainSide === 'challenged'
                ? $this->seriesDockItem($match, $other, 'need', 'answer', true, __('Answer'), self::format((int) $match->respond_by->getTimestampMs() - $nowMs, 'hm'),
                    __('Challenge :number, :format', ['number' => $match->label(), 'format' => SeriesPresenter::format($match)]), __('Answer'),
                    (int) $match->respond_by->getTimestampMs(),
                    ['endsAt' => (int) $match->respond_by->getTimestampMs(), 'format' => 'hm', 'total' => max(1, (int) $match->respond_by->getTimestampMs() - (int) ($match->created_at ?? now())->getTimestampMs()), 'redUnder' => self::DAILY_RED_MS])
                : null,
            SeriesStatus::Accepted => $match->start_at !== null && $match->start_at->isFuture()
                ? $this->seriesDockItem($match, $other, 'need', 'starts', true, __('Starts'), SeriesPresenter::time($match->start_at, $user, 'H:i'),
                    __('Series :number starts at :time', ['number' => $match->label(), 'time' => SeriesPresenter::time($match->start_at, $user, 'H:i')]), __('View'),
                    (int) $match->start_at->getTimestampMs())
                : $this->seriesDockItem($match, $other, 'live', 'live', true, __('Live'), $scoreText,
                    __('Series :number, live :score', ['number' => $match->label(), 'score' => $scoreText]), __('View'),
                    (int) ($match->start_at ?? $match->created_at ?? now())->getTimestampMs()),
            SeriesStatus::Reported => $report !== null && $report->status === ReportStatus::Open && $captainSide !== null && $captainSide !== $report->side
                ? $this->seriesDockItem($match, $other, 'need', 'accept', true, __('Accept result'), $scoreText,
                    // A result has no answer-by time: after everything that has one.
                    __('Series :number, they sent :score', ['number' => $match->label(), 'score' => $scoreText]), __('Accept'),
                    null)
                : $this->seriesDockItem($match, $other, 'wait', 'waiting', false, __('Waiting'), $scoreText,
                    __('Series :number, :score waiting for them to accept', ['number' => $match->label(), 'score' => $scoreText]), null,
                    (int) ($report->created_at ?? $match->updated_at ?? now())->getTimestampMs()),
            SeriesStatus::Disputed => $this->seriesDockItem($match, $other, 'wait', 'dispute', false, __('Admin decides'), $scoreText,
                __('Series :number, an admin decides', ['number' => $match->label()]), null,
                (int) ($match->updated_at ?? now())->getTimestampMs()),
            default => null,
        };
    }
}
