<?php

namespace App\Support\Tournaments;

use App\Enums\NotificationKind;
use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Games\BoardGame as BoardGameDefinition;
use App\Games\GameRegistry;
use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Switching a board game tournament between its time controls (user,
 * 2026-10-11: "Turniere bei Blockli sollten auf Rapid Modus umstellbar sein,
 * auch schon existierende"): Blockli's blitz, rapid and correspondence.
 *
 * In a draft, during sign-up and while the draw is pending the switch is
 * free. In a running tournament it reaches the games not started yet: the
 * match maker starts every game in the tournament's mode at its start
 * (TournamentMatchMaker::startBoardGame), so a game already under way
 * finishes in its own. The two-leg rule stays; a decider plays the mode the
 * tournament has when it starts. The night pause follows the profile of the
 * new mode by itself (NightPause::applies(): a minute game pauses, a daily
 * one does not).
 *
 * The change goes through TournamentEditor (logged, republished, the frozen
 * ladder re-derived for the new mode). Once players are involved (from the
 * sign-up on) each of them gets one tournament news per switch: a repeated
 * call for the same switch sends nothing again.
 */
final class TournamentModeSwitch
{
    /** How long the "told" mark of a switch is kept: longer than any retry of the same switch. */
    private const TOLD_TTL_DAYS = 30;

    public function __construct(private TournamentEditor $editor, private Notifier $notifier, private GameRegistry $registry) {}

    /**
     * The modes the tournament's game can be switched between, in the
     * registry's order (blitz, rapid, correspondence); empty for a game
     * without live modes.
     *
     * @return list<string>
     */
    public function modes(Tournament $tournament): array
    {
        $game = $this->registry->find($tournament->game);

        if (! $game instanceof BoardGameDefinition || $game->liveModes() === []) {
            return [];
        }

        // A combined tournament (FormatOptions::$games) switches only to a mode every game of its sequence has: any other
        // would drop games from the sequence mid-run and shift the legs still to play.
        $sequence = $tournament->formatOptions()->sequence() ? $tournament->formatOptions()->games : [$tournament->game];

        return array_values(array_filter(array_keys($game->modes()), fn (string $mode): bool => array_all(
            $sequence,
            fn (string $slug): bool => TournamentGames::keyOf($slug, $mode) !== null,
        )));
    }

    /** Whether the tournament's time control can still be switched: a board game with live modes, not a cup, not over. */
    public function canSwitch(Tournament $tournament): bool
    {
        return count($this->modes($tournament)) > 1 && ! $tournament->isCasualCup()
            && ! in_array($tournament->status, [TournamentStatus::Finished, TournamentStatus::Cancelled], true);
    }

    /**
     * Switch to `$mode`. False when it is the mode already.
     *
     * @throws TournamentRuleViolation
     */
    public function switch(Tournament $tournament, User $actor, string $mode): bool
    {
        if (! Gate::forUser($actor)->allows('manage-tournament', $tournament)) {
            throw new TournamentRuleViolation('not_manager', __('Only the organizer of this tournament or an admin can do this.'));
        }

        if (! $this->canSwitch($tournament) || ! in_array($mode, $this->modes($tournament), true)) {
            throw new TournamentRuleViolation('mode_switch', __('This tournament cannot be switched to that time control.'));
        }

        if ($this->editor->update($tournament, $actor, ['mode' => $mode]) === []) {
            return false;
        }

        $this->announce($tournament->refresh());

        return true;
    }

    /**
     * After the mode was saved (switch(), or the edit page's save with the
     * other changes): a published tournament's players get the message, and
     * the open pages reload. A draft is not public yet, so nothing is said.
     */
    public function announce(Tournament $tournament): void
    {
        if ($tournament->status === TournamentStatus::Draft) {
            return;
        }

        $this->tell($tournament);
        Broadcasts::send(new TournamentChanged($tournament->id, 'edited'));
    }

    /**
     * The last switch of the time control, for the tournament page's line: from, to and when; null if it never
     * changed after it was published.
     *
     * @return array{id: int, from: string, to: string, at: CarbonInterface}|null
     */
    public function lastSwitch(Tournament $tournament): ?array
    {
        // Only a published board game with more than one time control can have switched: no query for every other page.
        if ($tournament->published_at === null || count($this->modes($tournament)) < 2) {
            return null;
        }

        $entry = TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->where('action', 'edited')
            ->latest('id')->limit(100)->get()
            ->first(fn (TournamentModerationEntry $entry): bool => is_array($entry->details['mode'] ?? null) && is_string($entry->details['mode'][0] ?? null) && is_string($entry->details['mode'][1] ?? null));

        if (! $entry instanceof TournamentModerationEntry || $entry->created_at->lessThan($tournament->published_at)) {
            return null;
        }

        return ['id' => $entry->id, 'from' => (string) $entry->details['mode'][0], 'to' => (string) $entry->details['mode'][1], 'at' => $entry->created_at];
    }

    /** One tournament news to each player per switch, in their language; a second call for the same switch sends nothing. */
    private function tell(Tournament $tournament): void
    {
        $switch = $this->lastSwitch($tournament);

        if ($switch === null) {
            return;
        }

        foreach (User::query()->whereKey($this->players($tournament))->get() as $player) {
            if (! Cache::add('tournament-mode-told:'.$switch['id'].':'.$player->id, true, now()->addDays(self::TOLD_TTL_DAYS))) {
                continue;
            }

            $locale = $player->locale ?? (string) config('app.locale');
            $name = fn (string $mode): string => __((string) ($this->registry->mode($tournament->game, $mode)->name ?? $mode), [], $locale);
            $this->notifier->send($player, NotificationKind::TournamentNews, new Notice(
                __(':tournament now plays :mode', ['tournament' => $tournament->name, 'mode' => $name($switch['to'])], $locale),
                __('The organizer switched the time control from :from to :to. Games already under way finish as they started; every game from now on plays :to.', [
                    'from' => $name($switch['from']), 'to' => $name($switch['to']),
                ], $locale),
                route('tournaments.show', $tournament),
                null,
                __('Open tournament', [], $locale),
            ));
        }
    }

    /**
     * Every player of the tournament with an account: its entries' members after the draw, the active sign-ups' before it.
     *
     * @return list<int>
     */
    private function players(Tournament $tournament): array
    {
        $ids = $tournament->participants()->exists()
            ? $tournament->participants()->get()->flatMap(fn (TournamentParticipant $participant): array => $participant->memberIds())->all()
            : TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->get()->flatMap(fn (TournamentSignup $signup): array => TournamentModeration::entrants($signup))->all();

        return array_values(array_map(intval(...), User::query()->whereKey(array_unique($ids))->pluck('id')->all()));
    }
}
