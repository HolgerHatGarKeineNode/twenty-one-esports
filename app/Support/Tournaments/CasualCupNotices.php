<?php

namespace App\Support\Tournaments;

use App\Enums\NotificationKind;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRound;
use App\Models\User;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use Carbon\CarbonInterface;

/**
 * What the league tells the players of its casual cups (P25, CasualCups),
 * in their language and on the channels they chose (Notifier, kind
 * `tournament_news`, which may go out as a DM: a player away from the site
 * has to hear that their match is open or starting).
 */
final class CasualCupNotices
{
    public function __construct(private Notifier $notifier) {}

    /**
     * @param  list<int>  $userIds
     */
    public function calledOff(Tournament $cup, array $userIds): void
    {
        foreach (User::query()->whereKey($userIds)->get() as $player) {
            $locale = $this->locale($player);

            $this->send($player, $cup, __(':tournament was called off', ['tournament' => $cup->name], $locale),
                __('Not enough players signed up. The next cup opens in a day.', [], $locale), $locale);
        }
    }

    /**
     * Each player of a match that can be played now: whom, until when, and
     * when the league starts it on its own.
     */
    public function roundOpened(Tournament $cup, TournamentRound $round): void
    {
        $matches = TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'ready')
            ->where('bracket', '!=', 'bye')->with('slots.participant')->get();

        foreach ($matches as $match) {
            foreach ($match->slots as $slot) {
                $opponent = $match->slots->firstWhere('slot', 1 - $slot->slot)?->participant;
                $player = User::query()->find($slot->participant?->memberIds()[0] ?? 0);

                if ($player === null || $opponent === null || $round->window_ends_at === null) {
                    continue;
                }

                $locale = $this->locale($player);
                $body = $cup->profile()->isChess()
                    ? __('Play :opponent by :deadline: when you are both online, start it from the cup page. Otherwise the league starts your game at :slot.', [
                        'opponent' => $opponent->name,
                        'deadline' => $this->time($round->window_ends_at, $player),
                        'slot' => $this->time(CasualCups::autoSlot($round->window_ends_at), $player),
                    ], $locale)
                    : __('Play :opponent by :deadline.', ['opponent' => $opponent->name, 'deadline' => $this->time($round->window_ends_at, $player)], $locale);

                $this->send($player, $cup, __(':tournament: your match is open', ['tournament' => $cup->name], $locale), $body, $locale);
            }
        }
    }

    /**
     * The opponent asks to play the cup match now.
     */
    public function invited(Tournament $cup, ChessInvite $invite): void
    {
        $player = $invite->invitee;
        $locale = $this->locale($player);

        $this->send($player, $cup, __(':name wants to play your cup match now', ['name' => $invite->inviter->displayName()], $locale),
            __(':tournament · open the cup page to accept.', ['tournament' => $cup->name], $locale), $locale);
    }

    /**
     * A cup game started (accepted invite, the auto slot, a replay): both
     * players, wherever they are.
     */
    public function gameStarted(Tournament $cup, ChessGame $game): void
    {
        foreach ([$game->white, $game->black] as $player) {
            $locale = $this->locale($player);

            $this->notifier->send($player, NotificationKind::TournamentNews, new Notice(
                __(':tournament: your game is on', ['tournament' => $cup->name], $locale),
                __('You play :color against :name. Make your first move in time.', [
                    'color' => $game->colorOf($player) === 'w' ? __('White', [], $locale) : __('Black', [], $locale),
                    'name' => $game->opponentOf($player)?->displayName() ?? '',
                ], $locale),
                route('games.show', $game),
                $game->id,
                __('Play now', [], $locale),
            ), $game);
        }
    }

    private function send(User $player, Tournament $cup, string $title, string $body, string $locale): void
    {
        $this->notifier->send($player, NotificationKind::TournamentNews, new Notice($title, $body, route('tournaments.show', $cup), null, __('Open', [], $locale)));
    }

    private function time(CarbonInterface $at, User $player): string
    {
        return $at->copy()->setTimezone($player->timezone ?? (string) config('esports.casual_cups.timezone', 'Europe/Berlin'))->format('D j M, H:i T');
    }

    private function locale(User $user): string
    {
        return $user->locale ?? (string) config('app.locale');
    }
}
