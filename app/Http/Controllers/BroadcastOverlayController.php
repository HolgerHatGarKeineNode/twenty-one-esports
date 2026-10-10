<?php

namespace App\Http\Controllers;

use App\Models\OverlayPreset;
use App\Support\Broadcast\OverlaySnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * An OBS overlay (plan "OBS-Broadcast-Overlays", P2): `/broadcast/{token}` is the browser source, the overlay of
 * its preset's variant on a transparent page running on the broadcast engine (resources/js/broadcast/overlay.js),
 * and `/broadcast/{token}/snapshot.json` its data (OverlaySnapshot), polled every 20 s next to the public
 * `league.feed` channel.
 *
 * No session, no cookie, no CSRF (outside the `web` group): OBS keeps no login. The token is the only key; a wrong or
 * rotated one answers 404 and nothing else, looked up by its SHA-256 alone. Every answer says noindex and sends no
 * referrer, so the URL never leaves through a link or a font request; neither route is in the sitemap.
 */
class BroadcastOverlayController extends Controller
{
    public function __construct(private OverlaySnapshot $snapshots) {}

    public function show(string $token): Response
    {
        $preset = $this->preset($token);
        app()->setLocale($preset->locale);
        $snapshot = $this->snapshots->for($preset);

        return response()->view('broadcast.overlay', [
            'preset' => $preset,
            'config' => [
                'variant' => $preset->variant->value,
                'tournamentId' => $preset->variant->needsTournament() ? $preset->tournament_id : null,
                'snapshotUrl' => route('broadcast.snapshot', ['token' => $token]),
                'pollMs' => 20_000,
                'art' => collect(BroadcastStyleguideController::ART)->mapWithKeys(fn (string $id): array => [$id => '/broadcast/art/'.$id.'.webp'])->all(),
                'snapshot' => $snapshot,
                'texts' => $this->texts(),
            ],
        ])->withHeaders(self::headers());
    }

    public function snapshot(string $token): JsonResponse
    {
        return response()->json($this->snapshots->for($this->preset($token)))->withHeaders(self::headers());
    }

    private function preset(string $token): OverlayPreset
    {
        $preset = OverlayPreset::findByToken($token);
        abort_if($preset === null, 404);

        return $preset;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return ['X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store, private'];
    }

    /**
     * The overlay's words in the preset's language; `:name` style placeholders are filled on the page.
     *
     * @return array<string, string>
     */
    private function texts(): array
    {
        return [
            'live' => __('Live'),
            'onAir' => __('On air'),
            // Corner moments: the name is the headline, these are the line under it and its context.
            'beats' => __('Beats :loser'),
            'winsIn' => __('Wins in :game'),
            'sets' => __('Sets :score'),
            'scoreContext' => __(':game, checked highscore'),
            'climbsTo' => __('Climbs to :tier'),
            'gains' => __('Gains :gain Elo this week'),
            'streak' => __(':wins wins in a row'),
            'winsTournament' => __('Wins the tournament'),
            'reachesFinal' => __('Reaches the final'),
            'upsetContext' => __('Seed :winner beats seed :loser, :round'),
            'placePrize' => __(':sats sats for place :place'),
            'seasonPrize' => __(':sats sats for :blocks mined blocks'),
            // Lower third.
            'signsUp' => __('Signs up for :tournament'),
            'roundDone' => __('Round :round is complete'),
            'joinName' => __('Play with us in the league'),
            'joinLine' => __('Scan the code or go to :host'),
            'statsGames' => __(':count games played today'),
            'statsGame' => __(':count game played today'),
            'statsPlayers' => __(':count players in the league'),
            'statsLine' => __(':live live now, :total games so far'),
            'statsLineQuiet' => __(':total games so far'),
            'nextCupLine' => __('Next cup, starts :when, :taken of :places places taken'),
            'potLine' => __(':sats sats prize pot, starts :when'),
            'spotLine' => __('In the league, free to play at :host'),
            // Ticker news from the feed.
            'tickerBeats' => __(':winner beats :loser'),
            'tickerWins' => __(':winner wins'),
            'tickerRankUp' => __(':name reaches :tier'),
            // Tournament board.
            'boardSignup' => __('Sign-up open'),
            'boardDrawing' => __('Drawing the pairings'),
            'startsIn' => __('Starts in'),
            'starts' => __('Starts'),
            'entries' => __(':count signed up'),
            'entry' => __(':count signed up'),
            'scan' => __('Scan to sign up'),
            'standings' => __('Standings'),
            'prizePot' => __('Prize pot'),
        ];
    }
}
