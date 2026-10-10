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
            'win' => __('Beats :losers, :game'),
            'winAlone' => __('Wins in :game'),
            'score' => __(':score in :game'),
            'rankUp' => __('Climbs to :tier, :game'),
            'signup' => __('Signs up for :tournament'),
            'round' => __('Round :round is complete'),
            'champion' => __('Wins :tournament'),
            'payout' => __(':sats sats for place :place'),
            'seasonPayout' => __(':sats sats for :blocks mined blocks'),
        ];
    }
}
