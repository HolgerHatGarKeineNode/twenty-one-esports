<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchLocaleController extends Controller
{
    /**
     * Store the chosen locale in the session and return to the previous page.
     */
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $request->session()->put('locale', $locale);

        return redirect()->back(fallback: route('home'));
    }
}
