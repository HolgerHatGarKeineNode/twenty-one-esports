<?php

namespace App\Http\Controllers;

use App\Models\InviteLink;
use App\Support\Invites\InviteCard;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * The preview image of an invite link (og:image), for messengers and
 * crawlers: no session, no JavaScript. Drawn in the inviter's language,
 * since the preview stands for their message. Kept out of search results
 * like the landing itself.
 */
class InviteCardController extends Controller
{
    public function __invoke(string $code, string $format): Response
    {
        $link = InviteLink::query()->with(['inviter', 'clan'])->where('code', $code)->firstOrFail();

        $locale = $link->inviter->locale;

        if (is_string($locale) && in_array($locale, config('app.supported_locales', []), true)) {
            App::setLocale($locale);
        }

        return response((new InviteCard($link))->png($format), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
