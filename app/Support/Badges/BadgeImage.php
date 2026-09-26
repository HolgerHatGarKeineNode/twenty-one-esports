<?php

namespace App\Support\Badges;

use App\Games\GameRegistry;
use App\Support\Cards\Canvas;
use App\Support\Rating\RankTiers;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

/**
 * The artwork of a rank badge (NIP "Image URL per rank"): one square image
 * per game and tier, 1024 × 1024 (`image`) and 256 × 256 (`thumb`), shared by
 * every player of that tier. The rank cube of the app's rank badge in the
 * tier colour, the rank's name and the game. Always English: it is part of a
 * public Nostr event.
 *
 * Cached per game, tier, size and artwork version (`esports.badges.artwork`,
 * also in the URL), so a tier is drawn once.
 */
final class BadgeImage
{
    public const SIZES = [1024, 256];

    public function __construct(private GameRegistry $games) {}

    public function exists(string $game, string $tier): bool
    {
        return $this->games->find($game) !== null && array_key_exists($tier, (array) config('season.tiers'));
    }

    public function png(string $game, string $tier, int $size): string
    {
        $path = 'badge-art/v'.(int) config('esports.badges.artwork').'/'.$game.'-'.$tier.'-'.$size.'.png';
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $png = $this->render($game, $tier, $size);
        $disk->put($path, $png);

        return $png;
    }

    public function render(string $game, string $tier, int $size): string
    {
        $locale = App::getLocale();
        App::setLocale('en');

        try {
            // Laid out and drawn at 1024; the thumb is scaled down from it.
            $canvas = new Canvas(1024, 1024);
            $colour = RankTiers::colour($tier);

            $canvas->rect(0, 0, 1024, 1024, '#111113');
            $canvas->rankCube($canvas->mix($colour, '#0A0A0B', 0.75), 212, 62, 600);
            $canvas->rankCube($colour, 262, 112, 500);

            $label = RankTiers::label($tier);
            $labelSize = $canvas->fitSize($label, 'display', [118, 104, 92, 80], 920);
            $canvas->text($label, 'display', $labelSize, (1024 - $canvas->width($label, 'display', $labelSize)) / 2, 790, Canvas::INK);

            $name = strtoupper($this->games->find($game)?->name() ?? $game);
            $canvas->text($name, 'mono-bold', 52, (1024 - $canvas->width($name, 'mono-bold', 52)) / 2, 890, $colour);
            $canvas->text('TWENTY ONE esports', 'mono', 40, (1024 - $canvas->width('TWENTY ONE esports', 'mono', 40)) / 2, 960, Canvas::INK_2);

            $png = $canvas->png();
        } finally {
            App::setLocale($locale);
        }

        if ($size === 1024) {
            return $png;
        }

        $source = imagecreatefromstring($png);
        $side = max(1, $size);
        $out = imagecreatetruecolor($side, $side);

        if ($source === false) {
            return $png;
        }

        imagecopyresampled($out, $source, 0, 0, 0, 0, $side, $side, 1024, 1024);
        ob_start();
        imagepng($out, null, 9);

        return (string) ob_get_clean();
    }
}
