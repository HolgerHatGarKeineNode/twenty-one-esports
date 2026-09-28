<?php

namespace App\Support\Tournaments;

use App\Enums\ChessGameStatus;
use App\Enums\NotificationKind;
use App\Enums\SeriesResolution;
use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchSlot;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentParticipant;
use App\Models\TournamentResultEntry;
use App\Models\TournamentRound;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\Rating\RatingService;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\Engine\Advancement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Control over a running tournament (P18, slice 4; the "Control" section
 * of the admin edit page). Admins, and the organizer of their own
 * tournament (gate `manage-tournament`), may:
 *
 * 1. **Set or correct a match result**, also after it was confirmed. Not in
 *    a match they have an interest in (TournamentInterest; an admin only
 *    with their own stake). Also on a finished tournament until its
 *    payouts are approved (P9): the places are read from the bracket, so
 *    they follow the correction; after the approval it is refused. In director mode a match of the open round
 *    goes through the director desk's own path (rated when the round
 *    closes). Everywhere else the result is the league's decision. A series
 *    or chess game still being played is closed with the result
 *    (resolution `admin`, `forfeit` for a no-show), unrated and attested
 *    without `elo` like the league's other decisions. A correction of a
 *    played result that moved rated Elo reverts that Elo and rates the
 *    corrected outcome ({@see RatingService::correct()}, delta only); a
 *    corrected forfeit reverts it and rates nothing. Casual Elo, a closed
 *    season and a result that moved no Elo stay as they are. The old
 *    attestation stays as it was (the NIP defines no rating correction in
 *    V1); the log records the Elo effect. The bracket then re-flows ({@see propagate()}): a later
 *    match whose sides change is re-paired if unplayed (its series or game
 *    under way is voided and a new one starts), and **held** if already
 *    played: its result is set aside and it waits until someone sets its
 *    result or restarts its round. Swiss and round-robin pairings stand;
 *    only the tables change.
 * 2. **Disqualify** an entry with a reason: a series or game of it under
 *    way is forfeited now, every later match as it becomes ready
 *    (TournamentRunner, unrated, `decided` = `disqualified`); in a knockout
 *    the opponent advances.
 * 3. **Pause and resume**: while paused no match starts and the tick
 *    applies no deadline; on resume every running series' deadlines move
 *    by the pause (SeriesMatch::pausedAfter()). A chess game under way
 *    keeps its own clock.
 * 4. **Restart a round** (players mode): every undecided match of it is
 *    started again; a series or game under way (also one reported but not
 *    yet confirmed) is voided, a held match released. Refused in director
 *    mode (the directors correct entries until they close the round), for
 *    a round with nothing undecided, and after the tournament ended.
 * 5. **Call the tournament off** with a reason: status cancelled, every
 *    series and game under way voided, nothing rated after
 *    (RatingService), a new version of the 31923 saying so.
 * 6. **Message all players** (the bell and the NIP-17 DM, Notifier),
 *    `esports.tournaments.messages_per_hour` per tournament.
 *
 * An entrant of the tournament neither disqualifies nor restarts a round
 * with a stake in it. Every action is authorised here (a direct call
 * cannot skip it), written to the append-only moderation log and followed
 * by the TournamentChanged broadcast. Each is safe against a double click:
 * the second request finds the state already changed (a DB condition
 * under the tournament's row lock) and changes nothing.
 */
final class TournamentControl
{
    public function __construct(
        private TournamentRunner $runner,
        private TournamentModeration $moderation,
        private TournamentPublisher $publisher,
        private TournamentBrackets $brackets,
        private SeriesService $series,
        private ChessGameService $chess,
        private Notifier $notifier,
        private RatingService $ratings,
    ) {}

    /* ---------- 1. Results ------------------------------------------------------------------------------------ */

    /**
     * Set or correct the result of a match with two known sides. The input
     * is the director desk's (TournamentRunner::enterResult()). False when
     * the match already has exactly this result.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws TournamentRuleViolation
     */
    public function setResult(Tournament $tournament, User $actor, int $matchId, array $input, string $reason): bool
    {
        $this->authorize($tournament, $actor);
        $reason = $this->reason($reason);
        $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->find($matchId)
            ?? throw new TournamentRuleViolation('no_match', __('This match is not part of the tournament.'));

        if ($tournament->isDirectorMode() && $this->inOpenDirectorRound($tournament, $match)) {
            return $this->enterAsDirector($tournament, $actor, $match, $input, $reason);
        }

        $changed = DB::transaction(function () use ($tournament, $actor, $matchId, $input, $reason): bool {
            $locked = $this->lock($tournament, [TournamentStatus::Running, TournamentStatus::Finished]);

            // The places are read from the bracket until the payouts are approved (P9); after that they are money.
            if ($locked->payouts_approved_at !== null) {
                throw new TournamentRuleViolation('payouts_approved', __('The payouts of this tournament are approved, so its results can no longer change: the places they were paid for are final. Correct it with the league directly if a payout was wrong.'));
            }

            $match = TournamentMatch::query()->where('tournament_id', $locked->id)->with(['round.stage', 'slots.participant', 'seriesMatch', 'chessGame'])
                ->lockForUpdate()->findOrFail($matchId);

            if ($match->bracket === 'bye' || count($match->slots) !== 2 || $match->slots->contains(fn (TournamentMatchSlot $slot): bool => $slot->participant === null)) {
                throw new TournamentRuleViolation('not_playable', __('This match has no two sides yet.'));
            }

            if (TournamentInterest::of($locked, $match, $actor, followAppointers: ! $actor->isAdmin())) {
                throw new TournamentRuleViolation('interested', __('You have an interest in this match (you play in it, belong to a clan in it, or were named by someone who does), so another organizer or an admin has to set its result.'));
            }

            $result = $this->runner->parseResult($locked, $match, $input);
            $current = $match->result;

            // A double click, or the same result again: nothing to change.
            if ($match->held === null && $current !== null && ($current['winner'] ?? null) === $result['winner'] && ($current['label'] ?? null) === $result['label']
                && ($current['games'] ?? null) === ($result['games'] ?? null) && (bool) ($current['forfeit'] ?? false) === (bool) $result['forfeit']) {
                return false;
            }

            $previous = $current ?? ($match->held['was'] ?? null);
            $now = now();
            $who = ['user_id' => $actor->id, 'name' => $actor->displayName(), 'at' => $now->toIso8601String()];
            $series = $this->current($match, $match->seriesMatch);
            $game = $this->current($match, $match->chessGame);
            $number = $series->number ?? $game->number ?? ($previous['number'] ?? null);
            // A played result that moved rated Elo: revert it, rate the correction (inside this transaction).
            [$played, $score, $swap] = $this->eloSubject($match, $series, $game, $result);
            $elo = self::inSlotOrder($played === null ? null : $this->ratings->correct($played, $score), $swap);
            // A correction of a correction with the same outcome moves nothing new; the Elo stays corrected.
            $standing = $elo ?? ($played !== null && ($previous['winner'] ?? false) === $result['winner'] && (bool) ($previous['forfeit'] ?? false) === (bool) $result['forfeit'] ? ($previous['elo'] ?? null) : null);
            $stored = $result + ['by' => 'control', 'unrated' => $standing === null, 'reason' => $reason]
                + ($standing === null ? [] : ['elo' => $standing]) + ($previous === null
                ? $who
                : ['user_id' => $previous['user_id'] ?? null, 'name' => $previous['name'] ?? __('the players'), 'at' => $previous['at'] ?? null, 'corrected' => $who, 'was' => (string) ($previous['label'] ?? '')])
                + ($number === null ? [] : ['number' => $number]);

            $before = $this->entrants($locked);
            $this->runner->store($match, $stored);

            TournamentResultEntry::query()->create([
                'tournament_id' => $locked->id,
                'tournament_match_id' => $match->id,
                'user_id' => $actor->id,
                'user_name' => mb_substr($actor->displayName(), 0, 80),
                'result' => $stored,
                'replaced' => $previous,
                'created_at' => $now,
            ]);

            // The series or game still being played ends with this result, as the league's decision.
            $this->closeWithResult($match, $series, $game, $stored, $reason, $actor);
            [$voided, $held] = $this->propagate($locked, $match->id, $before, $actor);

            if ($locked->status === TournamentStatus::Finished) {
                // Reopened: the next sync ends it again if nothing is left to play.
                $locked->forceFill(['status' => TournamentStatus::Running])->save();
            }

            $this->moderation->log($locked, $actor, 'result', subject: $this->label($match), reason: $reason, details: array_filter([
                'result' => [$previous['label'] ?? null, $stored['label']],
                'elo' => $elo === null ? null : [self::deltas($elo['reverted']), $elo['applied'] === null ? null : self::deltas($elo['applied'])],
                'voided' => $voided === [] ? null : [null, $voided],
                'held' => $held === [] ? null : [null, $held],
            ]));

            return true;
            // A transient lock (a double submit racing itself) retries the whole decision, re-read under the lock.
        }, 3);

        if ($changed) {
            $this->runner->sync($tournament->refresh(), 'result');
        }

        return $changed;
    }

    /**
     * What saving this result would do to the Elo, for the confirmation of
     * the result form: null when it moves none (nothing rated to correct,
     * the same outcome, or an input that does not parse yet).
     *
     * @param  array<string, mixed>  $input
     * @return array{reverted: array{0: int, 1: int}, applied: array{0: int, 1: int}|null}|null
     */
    public function eloPreview(Tournament $tournament, int $matchId, array $input): ?array
    {
        $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->with(['slots.participant', 'seriesMatch', 'chessGame'])->find($matchId);

        if ($match === null || count($match->slots) !== 2) {
            return null;
        }

        try {
            $result = $this->runner->parseResult($tournament, $match, $input);
        } catch (TournamentRuleViolation) {
            return null;
        }

        [$played, $score, $swap] = $this->eloSubject($match, $this->current($match, $match->seriesMatch), $this->current($match, $match->chessGame), $result);

        return self::inSlotOrder($played === null ? null : $this->ratings->correction($played, $score), $swap);
    }

    /**
     * An Elo effect (challenger or White first) in the order of the match's
     * slots, as the form and the bracket list the sides.
     *
     * @param  array{reverted: array{0: int, 1: int}, applied: array{0: int, 1: int}|null}|null  $effect
     * @return array{reverted: array{0: int, 1: int}, applied: array{0: int, 1: int}|null}|null
     */
    private static function inSlotOrder(?array $effect, bool $swap): ?array
    {
        if ($effect === null || ! $swap) {
            return $effect;
        }

        return [
            'reverted' => [$effect['reverted'][1], $effect['reverted'][0]],
            'applied' => $effect['applied'] === null ? null : [$effect['applied'][1], $effect['applied'][0]],
        ];
    }

    /**
     * The played series or chess game whose Elo a result set here corrects,
     * and the corrected score (the challenger's, White's; null for a
     * forfeit), and whether its challenger (White) sits in the second slot:
     * only one that is over, never one still being played (that one is
     * closed unrated, closeWithResult()). No subject when there is none, or
     * White's side cannot be told (fail closed: no Elo moves).
     *
     * @param  array<string, mixed>  $result
     * @return array{0: SeriesMatch|ChessGame|null, 1: float|null, 2: bool}
     */
    private function eloSubject(TournamentMatch $match, ?SeriesMatch $series, ?ChessGame $game, array $result): array
    {
        $winner = $result['winner'];
        $forfeit = (bool) ($result['forfeit'] ?? false);

        if ($series !== null && $series->status->hasResult()) {
            return [$series, $forfeit ? null : ($winner === 0 ? 1.0 : 0.0), false];
        }

        if ($game === null || $game->status !== ChessGameStatus::Finished) {
            return [null, null, false];
        }

        $first = $match->slots[0]->participant?->memberIds() ?? [];
        $second = $match->slots[1]->participant?->memberIds() ?? [];
        $whiteSlot = match (true) {
            in_array((int) $game->white_id, $first, true), in_array((int) $game->black_id, $second, true) => 0,
            in_array((int) $game->white_id, $second, true), in_array((int) $game->black_id, $first, true) => 1,
            default => null,
        };

        if ($whiteSlot === null) {
            return [null, null, false];
        }

        return [$game, match (true) {
            $forfeit => null,
            $winner === null => 0.5,
            $winner === $whiteSlot => 1.0,
            default => 0.0,
        }, $whiteSlot === 1];
    }

    /**
     * The Elo effect as the log and the form state it: `reverts +12/−12,
     * applies +9/−9`, or `reverts +12/−12, applies nothing` for a forfeit.
     *
     * @param  array{reverted: array{0: int, 1: int}, applied: array{0: int, 1: int}|null}  $effect
     */
    public static function describeElo(array $effect): string
    {
        return $effect['applied'] === null
            ? __('reverts :reverted, applies nothing', ['reverted' => self::deltas($effect['reverted'])])
            : __('reverts :reverted, applies :applied', ['reverted' => self::deltas($effect['reverted']), 'applied' => self::deltas($effect['applied'])]);
    }

    /**
     * The Elo effect a stored result carries (`elo`), or null when it has
     * none or it is not of that shape.
     *
     * @return array{reverted: array{0: int, 1: int}, applied: array{0: int, 1: int}|null}|null
     */
    public static function storedElo(mixed $stored): ?array
    {
        $pair = fn (mixed $value): ?array => is_array($value) && array_is_list($value) && count($value) === 2 && is_int($value[0]) && is_int($value[1]) ? [$value[0], $value[1]] : null;

        if (! is_array($stored) || ($reverted = $pair($stored['reverted'] ?? null)) === null) {
            return null;
        }

        $applied = $pair($stored['applied'] ?? null);

        return ($stored['applied'] ?? null) !== null && $applied === null ? null : ['reverted' => $reverted, 'applied' => $applied];
    }

    /**
     * @param  array{0: int, 1: int}  $deltas
     */
    private static function deltas(array $deltas): string
    {
        return implode('/', array_map(fn (int $delta): string => ($delta >= 0 ? '+' : '−').abs($delta), $deltas));
    }

    /**
     * The director desk's own path for a match of the open round (rated
     * when the round closes), logged here as well.
     *
     * @param  array<string, mixed>  $input
     */
    private function enterAsDirector(Tournament $tournament, User $actor, TournamentMatch $match, array $input, string $reason): bool
    {
        $before = $match->result;
        $this->runner->enterResult($match, $actor, $input);
        $after = $match->fresh()?->result;

        if ($after === $before) {
            return false;
        }

        DB::transaction(function () use ($tournament, $actor, $match, $before, $after, $reason): void {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            $this->moderation->log($locked, $actor, 'result', subject: $this->label($match), reason: $reason, details: ['result' => [$before['label'] ?? null, $after['label'] ?? null]]);
        });

        return true;
    }

    private function inOpenDirectorRound(Tournament $tournament, TournamentMatch $match): bool
    {
        return TournamentRunner::currentRound($tournament)?->id === $match->tournament_round_id;
    }

    /**
     * End the series or chess game of a match that is still being played
     * with the result just set: resolution `admin` (`forfeit` for a
     * no-show), unrated, attested as the league's other decisions.
     *
     * @param  array<string, mixed>  $result
     */
    private function closeWithResult(TournamentMatch $match, ?SeriesMatch $series, ?ChessGame $game, array $result, string $reason, User $actor): void
    {
        $winner = $result['winner'];
        $forfeit = (bool) ($result['forfeit'] ?? false);

        if ($series !== null && $series->status->isRunning()) {
            $this->series->leagueClose($series, [
                'resolution' => $forfeit ? SeriesResolution::Forfeit : SeriesResolution::Admin,
                'winner' => $winner === 0 ? 'challenger' : 'challenged',
                'games' => $forfeit ? null : ($result['games'] ?? null),
            ], $reason, $actor);

            return;
        }

        if ($game !== null && $game->status === ChessGameStatus::Active) {
            $whiteSlot = in_array((int) $game->white_id, $match->slots[0]->participant?->memberIds() ?? [], true) ? 0 : 1;
            $pgn = match (true) {
                $winner === null => '1/2-1/2',
                $winner === $whiteSlot => '1-0',
                default => '0-1',
            };

            try {
                $this->chess->adjudicate($game, $pgn);
            } catch (ChessRuleViolation) {
                // It ended on its own a moment ago; the result set here stands as a correction.
            }
        }
    }

    /**
     * Re-flow the bracket after a result changed: every other match whose
     * sides are no longer the ones it had ($before). Played under the old
     * sides, its result is set aside and it is held for a decision; not
     * played yet, a series or game under way is voided and it is paired
     * again with its new sides. Holding a match takes its winner away, so
     * this runs until nothing changes any more.
     *
     * @param  array<int, list<int|null>>  $before  match id => participant per slot
     * @return array{0: list<string>, 1: list<string>} the voided and the held matches
     */
    private function propagate(Tournament $tournament, int $changedId, array $before, User $actor): array
    {
        $voided = [];
        $held = [];

        do {
            $state = Advancement::resolve($this->brackets->load($tournament), $this->brackets->results($tournament), $tournament->formatOptions());
            $moved = false;
            $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
                ->whereKeyNot($changedId)->with(['seriesMatch', 'chessGame', 'round'])->orderBy('id')->get();

            foreach ($matches as $match) {
                if (self::ids($before[$match->id] ?? []) === self::ids($state[$match->key]['entrants'] ?? [])) {
                    continue;
                }

                if ($match->result !== null) {
                    $this->hold($match, $actor);
                    $held[] = $this->label($match);
                    $moved = true;

                    continue;
                }

                if ($this->voidPlay($match, 'Voided by the league: a result this match depended on was corrected.')) {
                    $voided[] = $this->label($match);
                }

                // A director chess pairing pinned for the old sides is read again for the new ones.
                if ($match->pairing !== null) {
                    $match->forceFill(['pairing' => null])->save();
                }
            }
        } while ($moved);

        return [array_values(array_unique($voided)), array_values(array_unique($held))];
    }

    /**
     * Set a played result aside: the match waits until someone sets its
     * result or restarts its round. The old series or game stays as it was
     * (rated, attested) and no longer counts here.
     */
    private function hold(TournamentMatch $match, User $actor): void
    {
        $match->forceFill([
            'result' => null,
            'held' => [
                'was' => $match->result,
                'reason' => 'A result this match depended on was corrected.',
                'at' => now()->toIso8601String(),
                'user_id' => $actor->id,
                'name' => $actor->displayName(),
            ],
            'replaced_through' => max((int) $match->replaced_through, (int) ($match->seriesMatch->id ?? $match->chessGame->id)),
        ])->save();
    }

    /**
     * Void the series or chess game a match is being played with (none
     * rated, the series' void attested as an admin's void); a newer one
     * starts once the bracket moves. True if one was under way.
     */
    private function voidPlay(TournamentMatch $match, string $reason): bool
    {
        $series = $this->current($match, $match->seriesMatch);

        if ($series !== null && $series->status->isRunning()) {
            $this->series->leagueClose($series, ['resolution' => SeriesResolution::Void, 'winner' => 'none', 'games' => null], $reason, null);
            $match->forceFill(['replaced_through' => $series->id])->save();

            return true;
        }

        $game = $this->current($match, $match->chessGame);

        if ($game !== null && $game->status === ChessGameStatus::Active) {
            try {
                $this->chess->void($game);
            } catch (ChessRuleViolation) {
                // It ended on its own a moment ago: superseded all the same.
            }

            $match->forceFill(['replaced_through' => $game->id])->save();

            return true;
        }

        return false;
    }

    /**
     * The series or game of a match that still counts: not voided or
     * superseded by the league.
     *
     * @template T of SeriesMatch|ChessGame
     *
     * @param  T|null  $played
     * @return T|null
     */
    private function current(TournamentMatch $match, SeriesMatch|ChessGame|null $played): SeriesMatch|ChessGame|null
    {
        return $played === null || $match->isReplaced($played->id) ? null : $played;
    }

    /**
     * @param  list<int|string|null>  $entrants
     * @return list<int|null>
     */
    private static function ids(array $entrants): array
    {
        return array_map(fn (int|string|null $id): ?int => $id === null ? null : (int) $id, $entrants);
    }

    /**
     * Who sits in which slot of every match now.
     *
     * @return array<int, list<int|null>>
     */
    private function entrants(Tournament $tournament): array
    {
        $entrants = [];

        foreach (TournamentMatchSlot::query()->whereIn('tournament_match_id', TournamentMatch::query()->where('tournament_id', $tournament->id)->select('id'))->orderBy('slot')->get() as $slot) {
            $entrants[$slot->tournament_match_id][] = $slot->tournament_participant_id;
        }

        return $entrants;
    }

    /* ---------- 2. Disqualification --------------------------------------------------------------------------- */

    /**
     * False when the entry is disqualified already.
     *
     * @throws TournamentRuleViolation
     */
    public function disqualify(Tournament $tournament, User $actor, int $participantId, string $reason): bool
    {
        $this->authorize($tournament, $actor);
        $reason = $this->reason($reason);

        $notify = DB::transaction(function () use ($tournament, $actor, $participantId, $reason): ?array {
            $locked = $this->lock($tournament, [TournamentStatus::Running]);
            $this->assertNoEntrant($locked, $actor, __('You play in this tournament, so another organizer or an admin has to disqualify an entry.'));

            $participant = TournamentParticipant::query()->where('tournament_id', $locked->id)->lockForUpdate()->find($participantId)
                ?? throw new TournamentRuleViolation('no_entry', __('This entry is not part of the tournament.'));

            if ($participant->isDisqualified()) {
                return null;
            }

            $participant->forceFill(['disqualified_at' => now(), 'disqualified_by_id' => $actor->id, 'disqualification_reason' => $reason])->save();
            $forfeited = $this->forfeitUnderWay($locked, $participant);

            $this->moderation->log($locked, $actor, 'disqualified', subject: $participant->name, reason: $reason,
                details: $forfeited === [] ? null : ['forfeited' => [null, $forfeited]]);

            return $participant->memberIds();
        });

        if ($notify === null) {
            return false;
        }

        // The rest of its matches are forfeited as they become ready (TournamentRunner::forfeitWithdrawn()).
        $this->runner->sync($tournament->refresh(), 'disqualified');
        $this->tell($tournament, $notify, NotificationKind::TournamentEntryRemoved, 'You were disqualified from :tournament', $reason, $actor);

        return true;
    }

    /**
     * The disqualified entry's series or game under way is forfeited now
     * (a report or a dispute included), unrated.
     *
     * @return list<string> the matches forfeited
     */
    private function forfeitUnderWay(Tournament $tournament, TournamentParticipant $participant): array
    {
        $forfeited = [];
        $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNull('result')->where('bracket', '!=', 'bye')
            ->whereHas('slots', fn ($slot) => $slot->where('tournament_participant_id', $participant->id))
            ->with(['slots.participant', 'seriesMatch', 'chessGame'])->get();

        foreach ($matches as $match) {
            $series = $this->current($match, $match->seriesMatch);
            $game = $this->current($match, $match->chessGame);
            $running = ($series !== null && $series->status->isRunning()) || ($game !== null && $game->status === ChessGameStatus::Active);

            if (! $running || count($match->slots) !== 2) {
                continue;
            }

            $winner = $match->slots[0]->tournament_participant_id === $participant->id ? 1 : 0;
            $this->runner->store($match, [
                'winner' => $winner,
                'games_won' => $winner === 0 ? [1.0, 0.0] : [0.0, 1.0],
                'points' => [],
                'forfeit' => true,
                'decided' => 'disqualified',
                'label' => __('forfeit'),
                'by' => 'league',
                'number' => $series->number ?? $game->number,
            ]);

            if ($series !== null) {
                $this->series->leagueClose($series, ['resolution' => SeriesResolution::Forfeit, 'winner' => $winner === 0 ? 'challenger' : 'challenged', 'games' => null],
                    'Disqualified from the tournament by its organizer or an admin.', null);
            } elseif ($game !== null) {
                $loser = User::query()->whereKey([$game->white_id, $game->black_id])->whereIn('id', $participant->memberIds())->first();

                try {
                    if ($loser !== null) {
                        $this->chess->forfeit($game, $loser);
                    }
                } catch (ChessRuleViolation) {
                    // It ended on its own a moment ago; the forfeit stored here decides the match.
                }
            }

            $forfeited[] = $this->label($match);
        }

        return $forfeited;
    }

    /* ---------- 3. Pause ------------------------------------------------------------------------------------- */

    /**
     * False when it is paused already.
     *
     * @throws TournamentRuleViolation
     */
    public function pause(Tournament $tournament, User $actor, string $reason = ''): bool
    {
        $this->authorize($tournament, $actor);
        $reason = trim($reason) === '' ? null : $this->reason($reason);

        $paused = DB::transaction(function () use ($tournament, $actor, $reason): bool {
            $locked = $this->lock($tournament, [TournamentStatus::Running]);

            if ($locked->isPaused()) {
                return false;
            }

            $locked->forceFill(['paused_at' => now()])->save();
            $this->moderation->log($locked, $actor, 'paused', reason: $reason);

            return true;
        });

        if ($paused) {
            Broadcasts::send(new TournamentChanged($tournament->id, 'paused'));
            $this->tell($tournament, $this->players($tournament), NotificationKind::TournamentNews, ':tournament is paused',
                $reason ?? 'No match starts and no deadline runs until it goes on. Your deadlines move by the pause.', $actor);
        }

        return $paused;
    }

    /**
     * Go on after a pause: every running series' deadlines move by it.
     * False when it is not paused.
     *
     * @throws TournamentRuleViolation
     */
    public function resume(Tournament $tournament, User $actor): bool
    {
        $this->authorize($tournament, $actor);

        $resumed = DB::transaction(function () use ($tournament, $actor): bool {
            // Finished while paused (the last results came in): the pause is lifted all the same.
            $locked = $this->lock($tournament, [TournamentStatus::Running, TournamentStatus::Finished]);

            if (! $locked->isPaused()) {
                return false;
            }

            $from = $locked->paused_at?->getTimestamp() ?? now()->getTimestamp();
            $to = now()->getTimestamp();
            $series = SeriesMatch::query()->whereNotNull('deadlines')
                ->whereIn('tournament_match_id', TournamentMatch::query()->where('tournament_id', $locked->id)->select('id'))
                ->lockForUpdate()->get()->filter(fn (SeriesMatch $match): bool => $match->status->isRunning());

            foreach ($series as $match) {
                $deadlines = (array) $match->deadlines;
                $deadlines['pauses'] = [...($deadlines['pauses'] ?? []), [$from, $to]];
                $match->forceFill(['deadlines' => $deadlines])->save();
            }

            $locked->forceFill(['paused_at' => null])->save();
            $minutes = (int) ceil(($to - $from) / 60);
            $this->moderation->log($locked, $actor, 'resumed', subject: trans_choice(':count minute|:count minutes', $minutes));

            return true;
        });

        if ($resumed) {
            $this->runner->sync($tournament->refresh(), 'resumed');

            // sync() only broadcasts for a running tournament; one that finished while paused is told too.
            if ($tournament->status !== TournamentStatus::Running) {
                Broadcasts::send(new TournamentChanged($tournament->id, 'resumed'));
            }

            $this->tell($tournament, $this->players($tournament), NotificationKind::TournamentNews, ':tournament goes on',
                'The pause is over: matches start again, and every running deadline moved by the length of the pause.', $actor);
        }

        return $resumed;
    }

    /* ---------- 4. Restarting a round ---------------------------------------------------------------------- */

    /**
     * Start every undecided match of a round again. `$restarts` is the
     * round's restart count the caller saw: a second click with the same
     * count finds it restarted and changes nothing (false).
     *
     * @throws TournamentRuleViolation
     */
    public function restartRound(Tournament $tournament, User $actor, int $roundId, int $restarts, string $reason): bool
    {
        $this->authorize($tournament, $actor);
        $reason = $this->reason($reason);

        $restarted = DB::transaction(function () use ($tournament, $actor, $roundId, $restarts, $reason): bool {
            $locked = $this->lock($tournament, [TournamentStatus::Running]);

            if ($locked->isDirectorMode()) {
                throw new TournamentRuleViolation('director_round', __('In director mode the directors correct each entry until they close the round, so a round is not restarted.'));
            }

            $round = TournamentRound::query()->whereHas('stage', fn ($stage) => $stage->where('tournament_id', $locked->id))->lockForUpdate()->find($roundId)
                ?? throw new TournamentRuleViolation('no_round', __('This round is not part of the tournament.'));

            if ($round->restarts !== $restarts) {
                return false;
            }

            $matches = TournamentMatch::query()->where('tournament_round_id', $round->id)->where('bracket', '!=', 'bye')
                ->where('status', 'ready')->whereNull('result')->with(['seriesMatch', 'chessGame', 'slots.participant'])->get();

            if ($matches->isEmpty()) {
                throw new TournamentRuleViolation('nothing_to_restart', __('Round :round has no undecided match with two known sides, so there is nothing to restart. Set or correct a result instead.', ['round' => $round->number]));
            }

            foreach ($matches as $match) {
                if (TournamentInterest::of($locked, $match, $actor, followAppointers: ! $actor->isAdmin())) {
                    throw new TournamentRuleViolation('interested', __('You have an interest in a match of this round, so another organizer or an admin has to restart it.'));
                }
            }

            $voided = [];

            foreach ($matches as $match) {
                if ($this->voidPlay($match, 'Voided by the league: the round was restarted.') || $match->held !== null) {
                    $voided[] = $this->label($match);
                }

                $match->forceFill(['held' => null])->save();
            }

            $round->forceFill(['restarts' => $round->restarts + 1, 'started_at' => null])->save();
            $this->moderation->log($locked, $actor, 'round_restarted', subject: __('Round :round', ['round' => $round->number]), reason: $reason,
                details: $voided === [] ? null : ['voided' => [null, $voided]]);

            return true;
        });

        if ($restarted) {
            $this->runner->sync($tournament->refresh(), 'round');
        }

        return $restarted;
    }

    /* ---------- 5. Calling it off ---------------------------------------------------------------------------- */

    /**
     * False when it is called off already.
     *
     * @throws TournamentRuleViolation
     */
    public function abort(Tournament $tournament, User $actor, string $reason): bool
    {
        $this->authorize($tournament, $actor);
        $reason = $this->reason($reason);

        $aborted = DB::transaction(function () use ($tournament, $actor, $reason): bool {
            $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->status === TournamentStatus::Cancelled) {
                return false;
            }

            if (! in_array($locked->status, [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running], true)) {
                throw new TournamentRuleViolation('not_abortable', $locked->status === TournamentStatus::Finished
                    ? __('This tournament has finished; it can no longer be called off.')
                    : __('A draft is not published yet; there is nothing to call off.'));
            }

            $locked->forceFill(['status' => TournamentStatus::Cancelled, 'paused_at' => null])->save();
            $voided = [];

            foreach (TournamentMatch::query()->where('tournament_id', $locked->id)->whereNull('result')->where('bracket', '!=', 'bye')->with(['seriesMatch', 'chessGame'])->get() as $match) {
                if ($this->voidPlay($match, 'Voided by the league: the tournament was called off.')) {
                    $voided[] = $this->label($match);
                }
            }

            // A new version of the 31923 says it is called off (NIP-52 has no status for it).
            $this->publisher->republish($locked);
            $this->moderation->log($locked, $actor, 'aborted', reason: $reason, details: $voided === [] ? null : ['voided' => [null, $voided]]);

            return true;
        });

        if ($aborted) {
            Broadcasts::send(new TournamentChanged($tournament->id, 'cancelled'));
            $this->tell($tournament, $this->players($tournament), NotificationKind::TournamentNews, ':tournament was called off', $reason, $actor);
        }

        return $aborted;
    }

    /* ---------- 6. Messages ---------------------------------------------------------------------------------- */

    /**
     * Send one message to every player of the tournament (its entries, or
     * before the draw its sign-ups). Returns how many it reached; 0 when
     * the same message went out a moment ago (a double click).
     *
     * @throws TournamentRuleViolation
     */
    public function message(Tournament $tournament, User $actor, string $text): int
    {
        $this->authorize($tournament, $actor);
        $text = $this->reason($text);
        $throttle = 'tournament-news:'.$tournament->id;
        $limit = max(1, (int) config('esports.tournaments.messages_per_hour', 5));

        $recipients = DB::transaction(function () use ($tournament, $actor, $text, $throttle, $limit): ?array {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->status === TournamentStatus::Draft) {
                throw new TournamentRuleViolation('draft', __('A draft has no players yet.'));
            }

            // The same message again within ten minutes is the same click.
            $repeated = TournamentModerationEntry::query()->where('tournament_id', $locked->id)->where('action', 'messaged')
                ->where('reason', $text)->where('created_at', '>=', now()->subMinutes(10))->exists();

            if ($repeated) {
                return null;
            }

            if (RateLimiter::tooManyAttempts($throttle, $limit)) {
                throw new TournamentRuleViolation('too_many', __('You can write to all players :count times an hour. Try again in :minutes min.', [
                    'count' => $limit, 'minutes' => max(1, (int) ceil(RateLimiter::availableIn($throttle) / 60)),
                ]));
            }

            $players = $this->players($locked);
            $recipients = array_values(array_diff($players, [$actor->id]));

            if ($recipients === []) {
                throw new TournamentRuleViolation('nobody', $players === []
                    ? __('Nobody plays in this tournament yet.')
                    : __('You are the only player so far, so there is nobody else to write to yet.'));
            }

            RateLimiter::hit($throttle, 3600);
            $this->moderation->log($locked, $actor, 'messaged', subject: trans_choice(':count player|:count players', count($recipients)), reason: $text);

            return $recipients;
        });

        if ($recipients === null) {
            return 0;
        }

        Broadcasts::send(new TournamentChanged($tournament->id, 'message'));
        $this->tell($tournament, $recipients, NotificationKind::TournamentNews, 'Message from :tournament', $text, $actor);

        return count($recipients);
    }

    /* ---------- Helpers -------------------------------------------------------------------------------------- */

    /**
     * Every player of the tournament with an account: its entries' members
     * after the draw, the active sign-ups' before it.
     *
     * @return list<int> user ids
     */
    private function players(Tournament $tournament): array
    {
        $ids = $tournament->participants()->exists()
            ? $tournament->participants()->get()->flatMap(fn (TournamentParticipant $participant): array => $participant->memberIds())->all()
            : TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->get()->flatMap(fn (TournamentSignup $signup): array => TournamentModeration::entrants($signup))->all();

        return array_values(array_map(intval(...), User::query()->whereKey(array_unique($ids))->pluck('id')->all()));
    }

    /**
     * Tell players, in their language. `$title` is an English key with
     * `:tournament`; `$body` is typed text (kept as typed) or an English key.
     *
     * @param  list<int>  $userIds
     */
    private function tell(Tournament $tournament, array $userIds, NotificationKind $kind, string $title, string $body, User $sender): void
    {
        foreach (User::query()->whereKey($userIds)->get() as $player) {
            $locale = $player->locale ?? (string) config('app.locale');

            $this->notifier->send($player, $kind, new Notice(
                __($title, ['tournament' => $tournament->name], $locale),
                __($body, [], $locale),
                route('tournaments.show', $tournament),
                null,
                __('Open tournament', [], $locale),
            ), sender: $sender);
        }
    }

    /**
     * @param  list<TournamentStatus>  $statuses
     *
     * @throws TournamentRuleViolation
     */
    private function lock(Tournament $tournament, array $statuses): Tournament
    {
        $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

        if (! in_array($locked->status, $statuses, true)) {
            throw new TournamentRuleViolation('not_running', __('This tournament is not running.'));
        }

        return $locked;
    }

    /**
     * @throws TournamentRuleViolation
     */
    private function assertNoEntrant(Tournament $tournament, User $actor, string $message): void
    {
        $plays = $tournament->participants()->get()->contains(fn (TournamentParticipant $participant): bool => in_array($actor->id, $participant->memberIds(), true));

        if ($plays) {
            throw new TournamentRuleViolation('entrant', $message);
        }
    }

    /**
     * @throws TournamentRuleViolation
     */
    private function authorize(Tournament $tournament, User $actor): void
    {
        if (! Gate::forUser($actor)->allows('manage-tournament', $tournament)) {
            throw new TournamentRuleViolation('not_manager', __('Only the organizer of this tournament or an admin can do this.'));
        }
    }

    /**
     * @throws TournamentRuleViolation
     */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new TournamentRuleViolation('reason', __('Give a reason of 3 to 500 characters. The players read it.'));
        }

        return $reason;
    }

    /** A match as the log names it: its league number, else its bracket key. */
    private function label(TournamentMatch $match): string
    {
        $played = $this->current($match, $match->seriesMatch) ?? $this->current($match, $match->chessGame);

        return $played !== null ? '#'.$played->number : strtoupper($match->key);
    }
}
