<?php

namespace App\Http\Controllers;

use App\Support\Notifications\OnSite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /presence/ping: a logged-in page is visible (resources/js/onSite.js,
 * once a minute). While the player is on the site, notifications stay in the
 * bell and the toast instead of going out by push or DM (OnSite, Notifier).
 */
class OnSitePingController extends Controller
{
    public function __invoke(Request $request, OnSite $onSite): Response
    {
        $onSite->seen($request->user());

        return response()->noContent();
    }
}
