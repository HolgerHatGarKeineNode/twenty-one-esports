<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Notifications\NotificationDmOptOut;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Turn off these DMs" from the end of every notification DM: a signed link
 * (route middleware `signed`, no login), so it also works for a player who
 * never logged in. The page shows what is on; turning off is a POST to the
 * same signed URL.
 */
class NotificationDmOptOutController extends Controller
{
    public function show(Request $request, User $user): View
    {
        $this->useLocaleOf($request, $user);

        $done = $request->query('done');

        return view('pages.notifications.dm-off', [
            'user' => $user,
            'settings' => $user->chessSettings(),
            'done' => in_array($done, NotificationDmOptOut::SCOPES, true) ? $done : null,
            'action' => NotificationDmOptOut::url($user),
        ]);
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        $scope = $request->validate(['scope' => ['required', Rule::in(NotificationDmOptOut::SCOPES)]])['scope'];

        NotificationDmOptOut::apply($user, $scope);

        // The outcome rides in the signed URL, not in a session flash: the
        // confirmation needs no session state of a visitor who has none.
        return redirect()->to(NotificationDmOptOut::url($user, done: $scope));
    }

    /**
     * The recipient's language, unless the visitor picked one (SetLocale).
     */
    private function useLocaleOf(Request $request, User $user): void
    {
        if ($request->session()->get('locale') === null && in_array($user->locale, config('app.supported_locales'), true)) {
            app()->setLocale((string) $user->locale);
        }
    }
}
