<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\HyperMatch;
use App\Models\PongMatch;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchSlot;
use App\Models\TournamentParticipant;
use App\Models\TournamentRound;
use App\Models\TournamentStage;
use App\Support\Tournaments\Engine\Elimination;
use App\Support\Tournaments\Engine\MatchResult;
use App\Support\Tournaments\Engine\Slot;
use App\Support\Tournaments\Engine\StandingRow;
use App\Support\Tournaments\Engine\Standings;

/**
 * A tie for place 1 of a final table that points and every tie-break leave (DerCaddy, 2026-11-05: Blitz Blockli #55,
 * 6:2 against 6:2 and 2:2 head-to-head, went to the higher seed; 2026-11-06: "entweder Stichkampf oder geteilter erster
 * Platz, soll der Turnierleiter im Editor auswählen können (und dann auch irgendwo sichtbar sein)").
 *
 * The tournament's final table is a round robin or Swiss (a two-stage tournament ends in a knockout). The organizer
 * picks what a tie at its top comes to (FormatOptions::$firstPlaceTie):
 *
 * - `playoff`: once the table is complete, the runner opens stage 2, a single-elimination knockout among the tied, in
 *   seed order ({@see openPlayoff()}); its winner is the champion (TournamentChampion reads the last stage as usual), its
 *   knockout order their places, everyone else keeps the table's place (TournamentPlacements). The knockout plays by its
 *   own rules: no draw, a Blockli pairing two legs and a decider;
 * - `shared`: the tied share place 1 and the prizes of the places they hold, split equally (TournamentPlacements,
 *   PayoutPlan; TournamentChampion has no single champion);
 * - `seed`: the seed order splits it, as before the choice; options stored before it and casual cups.
 *
 * Only place 1: a tie further down keeps the seed order, as before.
 *
 * A result of the table corrected after the knockout opened (TournamentControl::setResult(), until the payouts are
 * approved) can leave another tie at the top, or none ({@see isStale()}). Nobody has played the knockout yet: the runner
 * takes it back and opens it again for the table as it stands ({@see dropStalePlayoff()}). Under way: it stays, and
 * neither the places nor the champion are read from it until the table matches it again; the control and director
 * pages say so.
 */
final class FirstPlaceTie
{
    /** The key prefix of the tie-break knockout's matches, apart from the table's. */
    public const PREFIX = 'tb-';

    /** Whether the tournament ends in a table whose tie for place 1 the rule covers: a round robin or Swiss. */
    public static function applies(Tournament $tournament): bool
    {
        return in_array($tournament->format, [TournamentFormat::RoundRobin, TournamentFormat::Swiss], true);
    }

    /**
     * The rule this tournament keeps ({@see FormatOptions::$firstPlaceTie}); `seed` where it does not apply.
     *
     * @return 'playoff'|'shared'|'seed'
     */
    public static function mode(Tournament $tournament): string
    {
        $mode = self::applies($tournament) ? $tournament->formatOptions()->firstPlaceTie : FormatOptions::FIRST_PLACE_TIE_SEED;

        return in_array($mode, FormatOptions::FIRST_PLACE_TIES, true) ? $mode : FormatOptions::FIRST_PLACE_TIE_SEED;
    }

    /** Whether stage `$number` of the tournament is its tie-break knockout: any stage after the table of a round robin or Swiss. */
    public static function isPlayoffStage(Tournament $tournament, int $number): bool
    {
        return $number > 1 && self::applies($tournament);
    }

    public static function playoffStage(Tournament $tournament): ?TournamentStage
    {
        return self::applies($tournament)
            ? TournamentStage::query()->where('tournament_id', $tournament->id)->where('number', '>', 1)->orderBy('number')->first()
            : null;
    }

    /**
     * The final table of a round robin or Swiss as the tournament page ranks it (TournamentView::table()): its points,
     * ranking and tie-breaks, a disqualified entry below everyone who stayed in. `$entrants` seed 1 first.
     *
     * @param  list<int>  $entrants
     * @param  list<array{0: int, 1: int|null, 2: MatchResult}>  $games
     * @return list<StandingRow>
     */
    public static function table(Tournament $tournament, array $entrants, array $games): array
    {
        $options = $tournament->formatOptions();
        $swiss = $tournament->format === TournamentFormat::Swiss;
        $custom = $options->rankBy === 'custom';
        $rows = Standings::table(
            $entrants,
            $games,
            $swiss || $custom ? $options->pointsWin : 1.0,
            $swiss || $custom ? $options->pointsTie : 0.5,
            $swiss ? $options->pointsBye : 0.0,
            $swiss ? 'points' : $options->rankBy,
            $swiss ? $options->swissTieBreaks : $options->roundRobinTieBreaks,
            // A round robin's two legs game by game where the organizer chose so (FormatOptions::$legPoints).
            ! $swiss && $options->perLeg(),
        );

        return Standings::outLast($rows, array_values(TournamentParticipant::query()->whereKey($entrants)->whereNotNull('disqualified_at')->pluck('id')->map(intval(...))->all()));
    }

    /**
     * The entrants level on place 1 of a table (Standings::table(), after Standings::outLast()) once the ranking and
     * every tie-break are through, in table order; empty when place 1 is clear. A disqualified entry is never among them.
     *
     * @param  list<StandingRow>  $rows
     * @return list<int>
     */
    public static function leaders(array $rows, ?Tournament $tournament = null): array
    {
        $first = $rows[0] ?? null;

        if ($first === null || $first->tiedWith === []) {
            return [];
        }

        $out = $tournament === null ? [] : array_values(TournamentParticipant::query()->whereKey([$first->entrant, ...$first->tiedWith])->whereNotNull('disqualified_at')->pluck('id')->map(intval(...))->all());

        if (in_array($first->entrant, $out, true)) {
            return [];
        }

        $level = array_values(array_filter($rows, fn (StandingRow $row): bool => $row->entrant === $first->entrant || (in_array($row->entrant, $first->tiedWith, true) && ! in_array($row->entrant, $out, true))));

        return count($level) > 1 ? array_map(fn (StandingRow $row): int => $row->entrant, $level) : [];
    }

    /**
     * Who still stands level on place 1 of the tournament's table (stage 1) as its finished games stand now: the entry
     * on top and everyone level with it, without the disqualified, sorted; one entry when place 1 is clear. Unlike
     * {@see leaders()} it keeps a single survivor of a tie, so a disqualification during the knockout (which changes
     * no table result) leaves the comparison in {@see isStale()} as it was.
     *
     * @param  list<int>  $disqualified
     * @return list<int>
     */
    private static function standingLevel(Tournament $tournament, array $disqualified): array
    {
        $entrants = array_values($tournament->participants()->whereNotNull('seed')->orderBy('seed')->pluck('id')->map(intval(...))->all());
        $first = self::table($tournament, $entrants, app(TournamentRunner::class)->games($tournament, stage: 1))[0] ?? null;
        $level = $first === null ? [] : array_values(array_diff([$first->entrant, ...$first->tiedWith], $disqualified));
        sort($level);

        return $level;
    }

    /**
     * The entrants the tie-break knockout was opened for: every slot it seeded with an entrant (a bye's too), sorted.
     *
     * @return list<int>
     */
    public static function playoffEntrants(TournamentStage $stage): array
    {
        $matches = TournamentMatch::query()->whereIn('tournament_round_id', TournamentRound::query()->where('tournament_stage_id', $stage->id)->select('id'))->select('id');
        $entrants = [];

        foreach (TournamentMatchSlot::query()->whereIn('tournament_match_id', $matches)->get(['source']) as $slot) {
            if (($slot->source['take'] ?? null) === 'entrant') {
                $entrants[] = (int) ($slot->source['entrant'] ?? 0);
            }
        }

        $entrants = array_values(array_unique($entrants));
        sort($entrants);

        return $entrants;
    }

    /**
     * Whether the tournament's tie-break knockout no longer answers its table: a result of stage 1 corrected since it
     * opened left another tie for place 1, a clear place 1, or a match of the table open again. False without a knockout.
     *
     * Both sides are compared without the disqualified: an entrant of the knockout disqualified during it loses its
     * matches by forfeit, and the others play on for place 1 (review of PR #21: a disqualification made a correct
     * knockout stale, so the tournament ended with no champion and no places).
     */
    public static function isStale(Tournament $tournament): bool
    {
        $stage = self::playoffStage($tournament);

        if ($stage === null) {
            return false;
        }

        $open = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNotIn('status', ['done', 'skipped'])
            ->whereHas('round.stage', fn ($query) => $query->where('number', 1))->exists();

        if ($open) {
            return true;
        }

        $disqualified = array_values(TournamentParticipant::query()->where('tournament_id', $tournament->id)->whereNotNull('disqualified_at')->pluck('id')->map(intval(...))->all());

        return self::standingLevel($tournament, $disqualified) !== array_values(array_diff(self::playoffEntrants($stage), $disqualified));
    }

    /**
     * Whether any match of the tie-break knockout is under way: a result, a result on hold, or a series, game, table or
     * Pong match started for it (those carry played games, signed events and notices, and the database keeps them from
     * going with their match).
     */
    public static function hasStarted(TournamentStage $stage): bool
    {
        $matches = TournamentMatch::query()->whereIn('tournament_round_id', TournamentRound::query()->where('tournament_stage_id', $stage->id)->select('id'));

        if ((clone $matches)->where(fn ($query) => $query->whereNotNull('result')->orWhereNotNull('held'))->exists()) {
            return true;
        }

        $ids = (clone $matches)->pluck('id');

        foreach ([SeriesMatch::class, ChessGame::class, BoardGame::class, HyperMatch::class, PongMatch::class] as $played) {
            if ($played::query()->whereIn('tournament_match_id', $ids)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Take back a tie-break knockout the table no longer asks for ({@see isStale()}) while nobody has played it
     * ({@see hasStarted()}): its stage goes with its rounds, matches and slots, and openPlayoff() opens it again for the
     * table as it stands, or not at all when place 1 is clear now (TournamentRunner::sync(), in its lock). A knockout
     * under way stays; TournamentPlacements and TournamentChampion read nothing from it until the table matches it
     * again. True when it went.
     */
    public static function dropStalePlayoff(Tournament $tournament): bool
    {
        $stage = self::playoffStage($tournament);

        if ($stage === null || ! self::isStale($tournament) || self::hasStarted($stage)) {
            return false;
        }

        $rounds = TournamentRound::query()->where('tournament_stage_id', $stage->id)->select('id');
        $matches = TournamentMatch::query()->whereIn('tournament_round_id', $rounds)->select('id');

        TournamentMatchSlot::query()->whereIn('tournament_match_id', $matches)->delete();
        TournamentMatch::query()->whereIn('tournament_round_id', $rounds)->delete();
        TournamentRound::query()->where('tournament_stage_id', $stage->id)->delete();
        $stage->delete();

        return true;
    }

    /**
     * Open the tie-break knockout of a tournament whose table just completed (TournamentRunner::sync(), in its lock):
     * stage 2, single elimination, the tied entrants in seed order (byes to the best seeds when they are not a power of
     * two). Nothing when the tournament does not play one, already has it, or place 1 is clear. True when it opened.
     *
     * @param  list<array{0: int, 1: int|null, 2: MatchResult}>  $games  the finished games of the table (stage 1)
     */
    public static function openPlayoff(Tournament $tournament, array $games): bool
    {
        if (self::mode($tournament) !== 'playoff' || self::playoffStage($tournament) !== null) {
            return false;
        }

        $entrants = array_values($tournament->participants()->whereNotNull('seed')->orderBy('seed')->pluck('id')->map(intval(...))->all());
        $tied = self::leaders(self::table($tournament, $entrants, $games), $tournament);

        if (count($tied) < 2) {
            return false;
        }

        // Level on everything: the table lists them in seed order already, which seeds the knockout.
        $matches = Elimination::single(array_map(fn (int $entrant): Slot => Slot::entrant($entrant), $tied), false, self::PREFIX, 2);
        $stage = TournamentStage::query()->create(['tournament_id' => $tournament->id, 'number' => 2, 'format' => TournamentFormat::SingleElimination]);
        $rounds = [];

        foreach ($matches as $match) {
            $rounds[$match->round] ??= TournamentRound::query()->create(['tournament_stage_id' => $stage->id, 'number' => $match->round]);
            $known = array_all($match->slots, fn (Slot $slot): bool => $slot->isKnown());
            $row = $tournament->matches()->create([
                'tournament_round_id' => $rounds[$match->round]->id,
                'key' => $match->key,
                'group' => null,
                'bracket' => $match->bracket,
                'position' => $match->position,
                'if_needed' => false,
                'status' => $known ? 'ready' : 'waiting',
            ]);

            foreach ($match->slots as $index => $slot) {
                $row->slots()->create(['slot' => $index, 'source' => $slot->toArray(), 'tournament_participant_id' => $slot->isKnown() ? $slot->entrant : null]);
            }
        }

        return true;
    }
}
