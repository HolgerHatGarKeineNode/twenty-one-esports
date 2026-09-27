<?php

namespace App\Support\Tournaments;

use App\Enums\ChessGameStatus;
use App\Enums\LineupRole;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\MatchNumber;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\RatedChess;
use App\Support\SeasonChain\GatePin;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\Series\SeriesEvents;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Tournament matches are played as normal matches (NIP: "Tournament matches
 * are ordinary challenges"; plan: they count for Elo): a Rocket League
 * series in the match room, or a chess game on the league's board, each
 * carrying its tournament match (`tournament_match_id`).
 *
 * The league pairs the two sides, so the pairing is their accept, as in a
 * queue pairing (NIP "Queue pairings"; sign-up is the consent). A pairing is
 * rated while the ladder is open and the trust gate passes on rank: a
 * league-made pairing skips the mutual opponent listing, not the rank (NIP
 * "Trust", item 4). Otherwise it is casual and moves the casual Elo.
 *
 * A Rocket League series is rated under the same gate in both results
 * modes; a mix team (a roster side of several players) is never rated (NIP:
 * mix teams are unrated), an RL 1v1 player is (rev. 7.1, a player ladder).
 * The rated subjects are pinned at the pairing (`rated_subjects`,
 * `gate_at_accept`, `clans_at_accept`), so a lineup or an account gone
 * later is still rated (security gate F3).
 *
 * - Director mode: the result is the league's (resolution `admin`), entered
 *   by a disinterested director (TournamentInterest); nothing is signed.
 * - Players mode (NIP rev. 8.1): the league key signs the challenge (2150,
 *   `pairing` `tournament`) at the pairing, the players' sign-up consent
 *   (22150) being their agreement; no answer follows. The captains then
 *   report (2152) and the other side confirms (2153) as in a ladder series,
 *   and a dispute goes to an admin. A pairing whose two sides share a clan
 *   stays casual (NIP state machine: a challenge needs different clans; the
 *   confirmation would come from the same party). Without the league key or
 *   the tournament's address no challenge can be signed: casual.
 *
 * Chess in director mode is played over the board: no game is started; the
 * finished game record is written when the round closes (TournamentRunner).
 */
final class TournamentMatchMaker
{
    public function __construct(
        private ChessGameService $chess,
        private RatedTrustGate $gate,
        private GameRegistry $games,
    ) {}

    /**
     * Start the normal match of every tournament match that is ready and
     * has none yet. A chess player still busy in another live game is left
     * for the next run (the scheduler tries again every minute).
     */
    public function startReady(Tournament $tournament): void
    {
        if ($tournament->status !== TournamentStatus::Running) {
            return;
        }

        $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'ready')
            ->where('bracket', '!=', 'bye')->whereNull('result')
            ->with(['round.stage', 'slots.participant', 'seriesMatch', 'chessGame'])->orderBy('id')->get();
        $current = TournamentRunner::currentRound($tournament);

        foreach ($matches as $match) {
            if ($tournament->isDirectorMode() && $match->tournament_round_id !== $current?->id) {
                continue;
            }

            [$a, $b] = [$match->slots[0]->participant ?? null, $match->slots[1]->participant ?? null];

            if ($a === null || $b === null) {
                continue;
            }

            try {
                if ($tournament->profile()->isChess()) {
                    if ($tournament->isDirectorMode()) {
                        $this->pinPairing($tournament, $match, $a, $b);
                    } elseif (self::needsGame($match)) {
                        $this->startGame($tournament, $match, $a, $b);
                    }

                    continue;
                }

                if ($match->seriesMatch === null) {
                    DB::transaction(fn () => $this->createSeries($tournament, $match, $a, $b));
                }
            } catch (UniqueConstraintViolationException) {
                // A concurrent run started this match first: it has its series or game.
            } catch (TournamentRuleViolation $violation) {
                report($violation);
            }
        }
    }

    /**
     * A chess match needs a (new) game when it has none, or when its last
     * game was drawn in a knockout, where a draw decides nothing: it is
     * replayed with the colours swapped.
     */
    public static function needsGame(TournamentMatch $match): bool
    {
        $game = $match->chessGame;

        return $game === null || ($game->status === ChessGameStatus::Finished && $game->result === '1/2-1/2' && ! TournamentRunner::allowsDraw($match));
    }

    /**
     * Director chess is played over the board and gets its game record only
     * when the round closes; what the league reads at the pairing (the trust
     * gate on the frozen ladder, each player's clan) is kept on the match now
     * (NIP "Director results": everything "at the accept" is read at the pairing).
     */
    private function pinPairing(Tournament $tournament, TournamentMatch $match, TournamentParticipant $a, TournamentParticipant $b): void
    {
        if ($match->pairing !== null) {
            return;
        }

        $white = User::query()->find($a->memberIds()[0] ?? 0);
        $black = User::query()->find($b->memberIds()[0] ?? 0);
        $pin = null;
        $clans = [];

        if ($white !== null && $black !== null) {
            $pin = $this->chessPin($tournament, $white, $black);
            $clans = $pin === null ? [] : RatedChess::clans($white, $black);
        }

        // The ladder is pinned with the gate: the game counts only while it is still open (NIP rule 16).
        $match->forceFill(['pairing' => ['gate' => $pin?->toArray(), 'clans' => $clans, 'ladder' => $pin === null ? null : $tournament->openLadder()]])->save();
    }

    private function startGame(Tournament $tournament, TournamentMatch $match, TournamentParticipant $a, TournamentParticipant $b): void
    {
        $first = User::query()->find($a->memberIds()[0] ?? 0);
        $second = User::query()->find($b->memberIds()[0] ?? 0);

        if ($first === null || $second === null) {
            return;
        }

        // Slot 0 has White; a knockout replay after a draw swaps the colours.
        [$white, $black] = $match->chessGame !== null && $match->chessGame->white_id === $first->id ? [$second, $first] : [$first, $second];

        try {
            $this->chess->start($white, $black, $tournament->mode, null, $this->chessPin($tournament, $white, $black), $match->id,
                ChessGame::query()->where('tournament_match_id', $match->id)->count() + 1);
        } catch (ChessRuleViolation) {
            // Busy in another live game: the next run tries again.
        }
    }

    /**
     * The gate of a rated tournament game: open ladder, trust ranks, both at
     * or above the minimum. Null = casual.
     */
    public function chessPin(Tournament $tournament, User $white, User $black): ?GatePin
    {
        if ($tournament->openLadder() === null || ! $this->gate->isAvailable()) {
            return null;
        }

        $pin = $this->gate->pin([$white->pubkey, $black->pubkey], [$white->pubkey, $black->pubkey]);

        return $pin->isEligible($white->pubkey) && $pin->isEligible($black->pubkey) ? $pin : null;
    }

    public function createSeries(Tournament $tournament, TournamentMatch $match, TournamentParticipant $a, TournamentParticipant $b): SeriesMatch
    {
        $lineups = [$this->lineup($a), $this->lineup($b)];
        $mode = $this->games->mode($tournament->game, $tournament->mode);
        $bestOf = $this->bestOf($tournament, $match, $mode === null ? [3, 5] : $mode->bestOf);
        // The number is recorded for a player who still has an account (a mix team can lose some).
        $numberOwner = User::query()->whereIn('id', [...$a->memberIds(), ...$b->memberIds()])->orderBy('id')->value('id')
            ?? throw new TournamentRuleViolation('no_players', "Tournament match {$match->id} has no player with an account left.");
        // RL 1v1 entries are rated as players (rev. 7.1), team modes as lineups; a mix team never.
        $players = $this->singlePlayers($tournament, $a, $b, $lineups);
        $pin = $players === null ? $this->seriesPin($tournament, $lineups[0], $lineups[1]) : $this->playersPin($tournament, $players[0], $players[1], $lineups);
        $clans = $pin === null ? null : ($players === null ? $this->clans($lineups[0], $lineups[1]) : RatedChess::clans($players[0], $players[1]));
        // Players mode signs a league challenge (rev. 8.1); it needs the league key, the published
        // tournament and two sides of different clans. Otherwise the pairing is casual.
        $league = $pin !== null && ! $tournament->isDirectorMode() ? LeagueKey::fromConfig() : null;

        if (! $tournament->isDirectorMode() && ($league === null || $tournament->address() === null || self::sharesClan($clans ?? [], $players, $lineups))) {
            $pin = null;
            $clans = null;
        }

        $now = now();

        $sides = [];

        foreach (['challenger' => [$a, $lineups[0]], 'challenged' => [$b, $lineups[1]]] as $side => [$participant, $lineup]) {
            if ($lineup === null) {
                $sides[$side] = $participant->memberIds();
            }
        }

        $series = SeriesMatch::query()->create([
            'number' => MatchNumber::query()->create(['user_id' => $numberOwner, 'used_at' => $now])->id,
            'game' => $tournament->game,
            'mode' => $tournament->mode,
            'best_of' => $bestOf,
            'rated' => $pin !== null,
            'challenger_lineup_id' => $lineups[0]?->id,
            'challenged_lineup_id' => $lineups[1]?->id,
            'challenger_name' => mb_substr($lineups[0]?->clan->name ?? $a->name, 0, 255),
            'challenged_name' => mb_substr($lineups[1]?->clan->name ?? $b->name, 0, 255),
            'challenger_tag' => $lineups[0]?->clan->clantag ?? self::tag($a),
            'challenged_tag' => $lineups[1]?->clan->clantag ?? self::tag($b),
            'challenger_lineup_address' => $lineups[0]?->address() ?? '',
            'challenged_lineup_address' => $lineups[1]?->address() ?? '',
            'ladder_address' => $pin !== null ? $tournament->openLadder() : null,
            'status' => SeriesStatus::Accepted,
            'proposals' => [$now->getTimestamp()],
            'respond_by' => $now,
            'start_at' => $now,
            'answered_at' => $now,
            'clans_at_accept' => $clans,
            'gate_at_accept' => $pin?->toArray(),
            'rated_subjects' => match (true) {
                $pin === null => null,
                $players !== null => ['challenger' => 'user:'.$players[0]->id, 'challenged' => 'user:'.$players[1]->id],
                default => ['challenger' => 'lineup:'.$lineups[0]?->id, 'challenged' => 'lineup:'.$lineups[1]?->id],
            },
            'tournament_match_id' => $match->id,
            'sides' => $sides === [] ? null : $sides,
        ]);

        if ($league !== null && $pin !== null) {
            $template = SeriesEvents::tournamentChallenge($series, $tournament, [
                'challenger' => $lineups[0] === null && $players !== null ? $players[0]->pubkey : null,
                'challenged' => $lineups[1] === null && $players !== null ? $players[1]->pubkey : null,
            ], array_values(array_filter([$lineups[0]?->clan->owner_pubkey, $lineups[1]?->clan->owner_pubkey])), $now->getTimestamp());
            $event = $league->publish($template['kind'], $template['tags'], $template['content'], $template['created_at']);
            $series->forceFill(['challenge_event_id' => $event->id])->save();
        }

        return $series;
    }

    /**
     * Whether a player of one side was, at the pairing, in the clan of a
     * player of the other (or of the other side's lineup).
     *
     * @param  array<string, string>  $clans  pubkey => clan address at the pairing
     * @param  array{0: User, 1: User}|null  $players
     * @param  array{0: Lineup|null, 1: Lineup|null}  $lineups
     */
    private static function sharesClan(array $clans, ?array $players, array $lineups): bool
    {
        $sideClans = [];

        foreach ([0, 1] as $index) {
            $pubkeys = $players !== null ? [$players[$index]->pubkey] : array_map(fn (LineupSeat $seat): string => $seat->user->pubkey, $lineups[$index]?->activeSeats() ?? []);
            $sideClans[$index] = array_filter([$lineups[$index]?->clan->address(), ...array_map(fn (string $pubkey): ?string => $clans[$pubkey] ?? null, $pubkeys)]);
        }

        return array_intersect($sideClans[0], $sideClans[1]) !== [];
    }

    /**
     * The gate of a rated director-mode series: open ladder, trust ranks,
     * both lineups, the clan owners at or above the minimum and enough
     * eligible players on each side. Null = casual.
     */
    /**
     * The two players of an RL 1v1 pairing (NIP rev. 7.1, a player ladder):
     * a solo entry's one player, or a clan 1v1 lineup's one regular player
     * among those entered (captain or player seat, not a substitute). Both
     * kinds of side can meet. Null in a team mode, for a mix team, or when a
     * side has no single such player.
     *
     * @param  array{0: Lineup|null, 1: Lineup|null}  $lineups
     * @return array{0: User, 1: User}|null
     */
    private function singlePlayers(Tournament $tournament, TournamentParticipant $a, TournamentParticipant $b, array $lineups): ?array
    {
        if ($tournament->teamSize() !== 1 || $a->isMixTeam() || $b->isMixTeam()) {
            return null;
        }

        $players = [];

        foreach ([[$a, $lineups[0]], [$b, $lineups[1]]] as [$participant, $lineup]) {
            $ids = $lineup === null
                ? $participant->memberIds()
                : array_values(array_map(fn (LineupSeat $seat): int => $seat->user_id, array_filter(
                    $lineup->activeSeats(),
                    fn (LineupSeat $seat): bool => $seat->role !== LineupRole::Substitute && in_array($seat->user_id, $participant->memberIds(), true),
                )));
            $user = count($ids) === 1 ? User::query()->find($ids[0]) : null;

            if ($user === null) {
                return null;
            }

            $players[] = $user;
        }

        return [$players[0], $players[1]];
    }

    /**
     * The gate of a rated 1v1 series: like a rated chess game, both players
     * at or above the minimum on the frozen ladder while it is open, each
     * side pinned with its one eligible player (their seat role on a lineup
     * side). Null = casual.
     *
     * @param  array{0: Lineup|null, 1: Lineup|null}  $lineups
     */
    private function playersPin(Tournament $tournament, User $a, User $b, array $lineups): ?GatePin
    {
        $pin = $this->chessPin($tournament, $a, $b);
        $entry = fn (User $user, ?Lineup $lineup): array => [['user_id' => $user->id, 'pubkey' => $user->pubkey, 'name' => $user->displayName(),
            'role' => $lineup?->activeSeatOf($user)?->role->value ?? 'player']];

        return $pin?->withSides(['challenger' => $entry($a, $lineups[0]), 'challenged' => $entry($b, $lineups[1])]);
    }

    private function seriesPin(Tournament $tournament, ?Lineup $a, ?Lineup $b): ?GatePin
    {
        if ($a === null || $b === null || $tournament->openLadder() === null || ! $this->gate->isAvailable()) {
            return null;
        }

        $players = array_values(array_unique(array_map(fn (LineupSeat $seat): string => $seat->user->pubkey, [...$a->activeSeats(), ...$b->activeSeats()])));
        $pin = $this->gate->pin($players, [$a->clan->owner_pubkey, $b->clan->owner_pubkey]);

        foreach ($pin->gatekeepers as $gatekeeper) {
            if (! $pin->isEligible($gatekeeper)) {
                return null;
            }
        }

        if (RatedTrustGate::sidesRefusal($pin, $a, $b) !== null) {
            return null;
        }

        $entries = fn (Lineup $lineup): array => array_values(array_map(
            fn (LineupSeat $seat): array => ['user_id' => $seat->user_id, 'pubkey' => $seat->user->pubkey, 'name' => $seat->user->displayName(), 'role' => $seat->role->value],
            array_filter($lineup->activeSeats(), fn (LineupSeat $seat): bool => $pin->isEligible($seat->user->pubkey)),
        ));

        return $pin->withSides(['challenger' => $entries($a), 'challenged' => $entries($b)]);
    }

    /**
     * @return array<string, string>
     */
    private function clans(?Lineup $a, ?Lineup $b): array
    {
        $clans = [];

        foreach ([$a, $b] as $lineup) {
            foreach ($lineup === null ? [] : $lineup->activeSeats() as $seat) {
                $clans[$seat->user->pubkey] = $lineup->clan->address();
            }
        }

        return $clans;
    }

    private function lineup(TournamentParticipant $participant): ?Lineup
    {
        return $participant->lineup_id === null ? null : Lineup::query()->with(['clan', 'seats.user.clanMember'])->find($participant->lineup_id);
    }

    /**
     * The best-of of this match: the final best-of for the last round of a
     * knockout, the tournament's otherwise, kept to what the mode allows.
     *
     * @param  list<int>  $allowed
     */
    private function bestOf(Tournament $tournament, TournamentMatch $match, array $allowed): int
    {
        $options = $tournament->formatOptions();
        $wanted = TournamentRunner::isFinal($match) ? $options->finalBestOf : $options->bestOf;

        if (in_array($wanted, $allowed, true) || $allowed === []) {
            return $wanted;
        }

        usort($allowed, fn (int $x, int $y): int => abs($x - $wanted) <=> abs($y - $wanted) ?: $x <=> $y);

        return $allowed[0];
    }

    private static function tag(TournamentParticipant $participant): string
    {
        return $participant->isMixTeam() ? 'MIX' : mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $participant->name) ?: 'P', 0, 4));
    }
}
