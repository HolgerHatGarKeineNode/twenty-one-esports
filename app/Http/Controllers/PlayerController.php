<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Nostr\PlayerProfile;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The player page (its header per PlayerHeader.dc.html; the rest of the page
 * is still to come) and the player card a hovered or tapped name opens
 * (ProfileHovercard.dc.html), as an HTML fragment for resources/js/profiles.js.
 */
class PlayerController extends Controller
{
    public function show(string $npub): View
    {
        return view('pages.players.show', ['profile' => PlayerProfile::for($this->player($npub))]);
    }

    public function card(string $npub): Response
    {
        return response()
            ->view('pages.players.card', ['profile' => PlayerProfile::for($this->player($npub))])
            ->header('Cache-Control', 'no-cache, private');
    }

    private function player(string $npub): User
    {
        return User::query()->where('npub', $npub)->with('clanMember.clan')->firstOrFail();
    }
}
