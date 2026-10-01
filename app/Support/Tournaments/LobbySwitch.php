<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Switch the lobby games' tournaments that are not drawn yet to lobbies
 * (plan "AoE2 und Trackmania", P10, user 2026-10-01: "die Altbestände auf
 * dem Live Server entsprechend einstellen"): every draft, open or drawing
 * tournament of a lobby game (Lobbies::isLobbyGame()) in another format,
 * casual cups included, gets Free for All with the game's lobby options;
 * a cup also gets the lobby cup's places (Lobbies::cupCapacity()). A
 * published one gets a new version of its calendar event (31923) whose
 * summary and content say the new format; the stream bot replaces its note
 * on its next run (TournamentNotes::stale(): the note's game line names the
 * lobby match).
 *
 * Left alone: a tournament with participants or matches (drawn), every
 * other game, and a team mode (2v2, 3v3) of a lobby game, whose sign-ups
 * are lineups: a lobby is played one player each, so that one is logged
 * for an admin and not converted.
 *
 * A calendar event that cannot be republished (no league key) is reported
 * and logged; the switch stays, as it runs on deploy.
 *
 * Idempotent: a switched tournament is Free for All, so a second run finds
 * nothing to do. One log line per switched or skipped tournament.
 */
final class LobbySwitch
{
    public function __construct(private TournamentPublisher $publisher) {}

    /**
     * @return array{switched: list<int>, skipped: list<int>}
     */
    public function run(): array
    {
        $games = array_values(array_filter(array_keys((array) config('esports.series.lobby_rules', [])), fn (mixed $game): bool => is_string($game) && Lobbies::isLobbyGame($game)));
        $done = ['switched' => [], 'skipped' => []];

        if ($games === []) {
            return $done;
        }

        $tournaments = Tournament::query()->whereIn('game', $games)
            ->whereIn('status', [TournamentStatus::Draft, TournamentStatus::Signup, TournamentStatus::Drawing])
            ->where('format', '!=', TournamentFormat::FreeForAll)
            ->orderBy('id')->get();

        foreach ($tournaments as $tournament) {
            if ($tournament->profile()->teamSize > 1) {
                // Once per tournament, however often the switch runs (a cache marker that never expires).
                if (Cache::add('lobby-switch:team-mode-logged:'.$tournament->id, true)) {
                    Log::warning('Lobby switch: a team-mode tournament of a lobby game is left as it is', ['id' => $tournament->id, 'name' => $tournament->name, 'mode' => $tournament->mode]);
                }

                $done['skipped'][] = $tournament->id;

                continue;
            }

            $switched = DB::transaction(function () use ($tournament): bool {
                $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($tournament->id);

                // Checked again under the lock: drawn meanwhile, or switched by a run alongside.
                if (! in_array($locked->status, [TournamentStatus::Draft, TournamentStatus::Signup, TournamentStatus::Drawing], true)
                    || $locked->format === TournamentFormat::FreeForAll || $locked->participants()->exists() || $locked->matches()->exists()) {
                    return false;
                }

                $from = $locked->format->value;
                $locked->forceFill([
                    'format' => TournamentFormat::FreeForAll,
                    'options' => FormatOptions::fromArray(Lobbies::options($locked->game), $locked->profile())->toArray(),
                    'capacity' => $locked->isCasualCup() ? Lobbies::cupCapacity($locked->game) : max($locked->capacity, Lobbies::minEntries($locked->game)),
                ])->save();

                Log::info('Lobby switch: tournament switched to one lobby match', ['id' => $locked->id, 'name' => $locked->name, 'from' => $from, 'capacity' => $locked->capacity]);

                return true;
            });

            if (! $switched) {
                continue;
            }

            $done['switched'][] = $tournament->id;

            // A new version of the 31923 names the lobby match (a draft has none yet). A refusal (no league key, a
            // relay hiccup) is reported and leaves the switch in place: it runs on deploy and must not stop it.
            try {
                DB::transaction(fn () => $this->publisher->republish(Tournament::query()->with('event')->lockForUpdate()->findOrFail($tournament->id)));
            } catch (Throwable $e) {
                report($e);
                Log::error('Lobby switch: the calendar event was not republished', ['id' => $tournament->id, 'error' => $e->getMessage()]);
            }
        }

        return $done;
    }
}
