<?php

namespace App\Support\Invites;

use App\Enums\InviteLinkType;
use App\Models\InviteLink;
use App\Models\Lineup;
use App\Support\GameNames;

/**
 * The words of one invite (InviteLanding.dc.html, ShareCards "Invite cards"):
 * landing headline, the question on the preview card, and the page title and
 * description messengers show. One place, so the preview never promises
 * something the landing does not say.
 */
final class InviteCopy
{
    public function __construct(private InviteLink $link) {}

    public function inviterName(): string
    {
        return $this->link->inviter->displayName();
    }

    public function clanName(): string
    {
        return match ($this->link->type) {
            InviteLinkType::Clan => (string) $this->link->clan?->name,
            InviteLinkType::Series => (string) $this->lineup()?->clan?->name,
            default => '',
        };
    }

    public function lineup(): ?Lineup
    {
        $id = $this->link->option('lineup_id');

        return $id === null ? null : Lineup::query()->with('clan')->find((int) $id);
    }

    /** "satsjäger challenges you to blitz chess" */
    public function headline(): string
    {
        return match ($this->link->type) {
            InviteLinkType::Blitz => __(':name challenges you to blitz chess', ['name' => $this->inviterName()]),
            InviteLinkType::Daily => __(':name challenges you to daily chess', ['name' => $this->inviterName()]),
            InviteLinkType::Series => __(':clan challenges your team to :game', ['clan' => $this->clanName(), 'game' => $this->seriesGame()]),
            InviteLinkType::Clan => __(':name invites you to join :clan', ['name' => $this->inviterName(), 'clan' => $this->clanName()]),
            InviteLinkType::Tournament => __(':name invites you to :tournament', ['name' => $this->inviterName(), 'tournament' => $this->tournamentName()]),
        };
    }

    public function subline(): string
    {
        return match ($this->link->type) {
            InviteLinkType::Blitz => __('Five minutes each, plus 3 seconds a move. Casual, so no rating is on the line.'),
            InviteLinkType::Daily => __('One move a day, at your pace. Casual, so no rating is on the line.'),
            InviteLinkType::Series => __(':mode, best of :bo. Casual, so no rating is on the line.', ['mode' => (string) $this->link->option('mode'), 'bo' => (int) $this->link->option('best_of')]),
            InviteLinkType::Clan => filled($this->link->clan?->description)
                ? (string) $this->link->clan->description
                : __('Ask to join the roster. A captain of :clan confirms.', ['clan' => $this->clanName()]),
            InviteLinkType::Tournament => __('A tournament on TWENTY ONE Esports. Sign up on its page.'),
        };
    }

    /** "Chess blitz, 5+3" */
    public function gameChip(): string
    {
        return match ($this->link->type) {
            InviteLinkType::Blitz => __('Chess blitz, 5+3'),
            InviteLinkType::Daily => __('Daily chess, 1 move a day'),
            InviteLinkType::Series => __(':game, :mode', ['game' => $this->seriesGame(), 'mode' => (string) $this->link->option('mode')]),
            InviteLinkType::Clan => __('Clan invite'),
            InviteLinkType::Tournament => $this->link->tournament === null ? __('Tournament') : GameNames::full($this->link->tournament->game, $this->link->tournament->mode),
        };
    }

    /** The big question on the preview card: "Beat me at blitz?" */
    public function cardQuestion(): string
    {
        return match ($this->link->type) {
            InviteLinkType::Blitz => __('Beat me at blitz?'),
            InviteLinkType::Daily => __('Your move?'),
            InviteLinkType::Series => __('Take on :clan?', ['clan' => $this->clanName()]),
            InviteLinkType::Clan => __('Join :clan?', ['clan' => $this->clanName()]),
            InviteLinkType::Tournament => __('Join me in :tournament?', ['tournament' => $this->tournamentName()]),
        };
    }

    public function cardSubline(): string
    {
        return match ($this->link->type) {
            InviteLinkType::Blitz => __('5+3 chess, casual. Tap to take the seat.'),
            InviteLinkType::Daily => __('Daily chess, one move a day, casual.'),
            InviteLinkType::Series => __(':game :mode, best of :bo.', ['game' => $this->seriesGame(), 'mode' => (string) $this->link->option('mode'), 'bo' => (int) $this->link->option('best_of')]),
            InviteLinkType::Clan => __('Ask to join. A captain confirms.'),
            InviteLinkType::Tournament => __('Sign up on the tournament page.'),
        };
    }

    /** The tournament of a tournament link (P47). */
    private function tournamentName(): string
    {
        return (string) $this->link->tournament?->name;
    }

    /** The game of a series link ("Rocket League", "EA Sports FC 27"). */
    private function seriesGame(): string
    {
        return GameNames::game((string) $this->link->option('game'));
    }

    /** Page title and og:title. */
    public function title(): string
    {
        return $this->headline();
    }

    /** Meta and og:description. */
    public function description(): string
    {
        return match ($this->link->type) {
            InviteLinkType::Blitz => __('Blitz chess 5+3, casual. Log in with Google or Nostr and you land right at the board.'),
            InviteLinkType::Daily => __('Daily chess, one move a day, casual. Log in with Google or Nostr and you land right in the game.'),
            InviteLinkType::Series => __(':game :mode, best of :bo, casual. Take the challenge with your team.', ['game' => $this->seriesGame(), 'mode' => (string) $this->link->option('mode'), 'bo' => (int) $this->link->option('best_of')]),
            InviteLinkType::Clan => __('Ask to join :clan on TWENTY ONE esports. A captain confirms your request.', ['clan' => $this->clanName()]),
            InviteLinkType::Tournament => __('A tournament on TWENTY ONE Esports. Sign up on its page.'),
        };
    }

    /** Alt text of the preview image. */
    public function cardAlt(): string
    {
        return __('Invite card: :name asks: :question', ['name' => $this->inviterName(), 'question' => $this->cardQuestion()]);
    }
}
