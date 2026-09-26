<?php

namespace App\Http\Controllers;

use App\Models\RankBadgeVersion;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Cards\ShareCard;
use App\Support\Tournaments\TournamentChampion;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * The share cards (P11) as PNG, for the posts and downloads that link them:
 * public, no session, no JavaScript, like the invite card. The locale is part
 * of the URL, so a posted card keeps the language of its post. Only moments
 * that happened are drawn: a rank-up version of a badge, a block the player
 * is a winner of, the champion of a finished tournament, a player's season.
 */
class ShareCardController extends Controller
{
    public function rankUp(string $locale, int $version, string $format): Response
    {
        $version = RankBadgeVersion::query()->with('badge.user')->find($version);
        abort_unless($version !== null && $version->isRankUp(), 404);

        return $this->png($locale, fn (): ShareCard => ShareCard::rankUp($version), $format);
    }

    public function block(string $locale, int $block, string $npub, string $format): Response
    {
        $block = SeasonAttestation::query()->with('season')->findOrFail($block);
        $miner = User::query()->where('npub', $npub)->first();
        abort_unless($miner !== null && $block->mines() && in_array($miner->pubkey, $block->winners(), true), 404);

        return $this->png($locale, fn (): ShareCard => ShareCard::block($block, $miner), $format);
    }

    public function tournament(string $locale, int $finished, string $format, TournamentChampion $champions): Response
    {
        $tournament = Tournament::query()->findOrFail($finished);
        $winner = $tournament->isVisibleTo(null) ? $champions->of($tournament) : null;
        abort_if($winner === null, 404);

        return $this->png($locale, fn (): ShareCard => ShareCard::tournament($tournament, $winner), $format);
    }

    public function wrapped(string $locale, string $season, string $npub, string $format): Response
    {
        $season = Season::query()->where('slug', $season)->firstOrFail();
        $user = User::query()->where('npub', $npub)->first();
        abort_if($user === null || $season->genesis_at->isFuture(), 404);

        return $this->png($locale, fn (): ShareCard => ShareCard::wrapped($season, $user), $format);
    }

    /**
     * @param  \Closure(): ShareCard  $card
     */
    private function png(string $locale, \Closure $card, string $format): Response
    {
        App::setLocale($locale);

        return response($card()->png($format), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
