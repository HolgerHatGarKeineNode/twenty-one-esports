<?php

namespace App\Http\Controllers;

use App\Support\Cards\PageCard;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Throwable;

/**
 * The link preview of a public page (P54, PageCard) as PNG: public, no
 * session, rate-limited per IP like the share cards. The locale is part of
 * the URL, so a preview keeps the language of the page that linked it; the
 * `v` query is the state it was linked in and only busts caches on the way.
 *
 * Fail-safe: a page that has no public card is a 404, anything that goes
 * wrong while reading or drawing is the brand card with a 200 (and a report),
 * never a 500 a messenger would show as a broken preview.
 */
class PageCardController extends Controller
{
    public function __invoke(string $locale, string $type, string $key): Response
    {
        App::setLocale($locale);

        try {
            $card = PageCard::resolve($type, $key);
        } catch (Throwable $e) {
            report($e);

            return $this->png(self::fallback(), 300);
        }

        abort_if($card === null, 404);

        try {
            return $this->png($card->png(), 3600);
        } catch (Throwable $e) {
            report($e);

            return $this->png(self::fallback(), 300);
        }
    }

    /** The brand card, drawn without a query; the static copy when even that fails. */
    public static function fallback(): string
    {
        try {
            return PageCard::brand();
        } catch (Throwable $e) {
            report($e);

            return (string) file_get_contents(public_path('images/og/fallback.png'));
        }
    }

    private function png(string $bytes, int $maxAge): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age='.$maxAge,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
