<?php

namespace App\Http\Controllers;

use App\Enums\HyperMatchStatus;
use App\Models\HyperMatch;
use App\Models\User;
use App\Support\Hyper\HyperEmotes;
use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperRuleViolation;
use App\Support\Hyper\HyperTableChat;
use App\Support\Hyper\HyperTexts;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A Hyperbitcoinization match over JSON (plan "Hyperbitcoinization", P2; routes/hyper.php, only registered
 * while `esports.hyper.enabled` is on): the page, its snapshot and event catch-up for everybody (players,
 * spectators, guests: each sees what HyperView lets them), and for a seated player the actions, emotes and
 * leaving. The rules live in HyperMatches and the core.
 *
 * A refusal answers `{reason, message}`: 403 for a viewer who has no say (`not_seated`, `seat_taken_over`,
 * `already_left`), 409 when the match moved on (`out_of_sync`, `turn_timed_out`, `game_over`; the page
 * reloads the snapshot), 429 for a throttled emote (`retry_after` in seconds), 422 for an action the rules
 * refuse (the core's reason, e.g. `not_your_turn`, `wrong_phase`, `not_adjacent`).
 */
class HyperMatchController extends Controller
{
    /** Refusals of a viewer who has no say at this seat. */
    private const FORBIDDEN = ['not_seated', 'seat_taken_over', 'already_left'];

    /** Refusals because the match is not where the client thought it was. */
    private const CONFLICT = ['out_of_sync', 'turn_timed_out', 'game_over'];

    /**
     * The start page in the league's shell until the lobby comes (P3): the title art, the quick start against
     * bots (it opens the match in a new tab) and the viewer's running matches.
     */
    public function index(Request $request): View
    {
        $viewer = $this->viewer($request);
        $running = $viewer === null ? collect() : HyperMatch::query()
            ->where('status', HyperMatchStatus::Active)
            ->whereHas('seats', fn (Builder $seats): Builder => $seats->where('user_id', $viewer->id)->whereNull('left_at')->where('bot', false))
            ->with('seats')
            ->latest('id')
            ->limit(10)
            ->get();

        return view('pages.hyper.index', [
            'viewer' => $viewer,
            'running' => $running,
            'factions' => array_keys(HyperGame::FACTIONS),
            'limits' => HyperGame::LIMITS,
        ]);
    }

    /**
     * The match page, full-screen in its own document (resources/views/hyper/match.blade.php): the snapshot as
     * JSON, and what the page's script needs besides (endpoints, texts in the viewer's language, the soundboard
     * clips, the table chat).
     */
    public function show(Request $request, HyperMatch $match, HyperMatches $matches): View
    {
        $viewer = $this->viewer($request);

        return view('hyper.match', [
            'snapshot' => $matches->snapshot($match, $viewer),
            'back' => route('hyper.index', absolute: false),
            'config' => [
                'urls' => [
                    'snapshot' => route('hyper.snapshot', $match, false),
                    'events' => route('hyper.events', $match, false),
                    'act' => route('hyper.act', $match, false),
                    'emote' => route('hyper.emote', $match, false),
                    'leave' => route('hyper.leave', $match, false),
                ],
                'assets' => '/hyper/',
                'locale' => app()->getLocale(),
                'csrf' => csrf_token(),
                'texts' => HyperTexts::dictionary(),
                'clips' => HyperEmotes::clips(),
                'chat' => HyperTableChat::config($match, $viewer),
            ],
        ]);
    }

    /**
     * Names and avatars of the league accounts among `?keys=<hex>,<hex>` (at most 100): how the table chat
     * names a spectator who writes (HyperTableChat::people()).
     */
    public function people(Request $request): JsonResponse
    {
        $keys = explode(',', (string) $request->query('keys', ''));

        return response()->json((object) HyperTableChat::people(array_slice($keys, 0, 100)));
    }

    public function snapshot(Request $request, HyperMatch $match, HyperMatches $matches): JsonResponse
    {
        return response()->json($matches->snapshot($match, $this->viewer($request)));
    }

    /**
     * The events after `?after=<ply>` (default 0), grouped by ply.
     */
    public function events(Request $request, HyperMatch $match, HyperMatches $matches): JsonResponse
    {
        $after = max(0, (int) $request->query('after', '0'));

        return response()->json($matches->eventsSince($match, $this->viewer($request), $after));
    }

    /**
     * One action of the player's seat: `{"action": {...}, "ply": <the ply it becomes>}`.
     */
    public function act(Request $request, HyperMatch $match, HyperMatches $matches): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'array'],
            'action.type' => ['required', 'string', 'max:16'],
            'ply' => ['nullable', 'integer', 'min:1'],
        ]);
        // The whole action object: validated() keeps only the fields named in the rules; the core checks the rest.
        $action = (array) $request->input('action');

        return $this->refusing(function () use ($request, $match, $matches, $data, $action): JsonResponse {
            $user = $this->user($request);
            ['match' => $after, 'events' => $events] = $matches->act($match, $user, $action, isset($data['ply']) ? (int) $data['ply'] : null);

            return response()->json(['ply' => $after->ply, 'events' => $events, 'snapshot' => $matches->snapshot($after->refresh(), $user)]);
        });
    }

    /**
     * A sticker or a soundboard clip: `{"emote": "gg"}`.
     */
    public function emote(Request $request, HyperMatch $match, HyperEmotes $emotes): JsonResponse
    {
        $data = $request->validate(['emote' => ['required', 'string', 'max:64']]);

        return $this->refusing(fn (): JsonResponse => response()->json($emotes->send($match, $this->user($request), $data['emote'])));
    }

    public function leave(Request $request, HyperMatch $match, HyperMatches $matches): JsonResponse
    {
        return $this->refusing(function () use ($request, $match, $matches): JsonResponse {
            $user = $this->user($request);

            return response()->json(['snapshot' => $matches->snapshot($matches->leave($match, $user)->refresh(), $user)]);
        });
    }

    /**
     * A live match of the player and `bots` bots (1 to 5) until the lobby comes (P3): the player sits
     * first with the faction they chose (or a random one), the bots take the others. A JSON request gets
     * `{id, url}`; the start page's form (posted into a new tab) is sent on to the match.
     */
    public function quick(Request $request, HyperMatches $matches): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'bots' => ['required', 'integer', 'min:1', 'max:5'],
            'faction' => ['nullable', 'string', Rule::in(array_keys(HyperGame::FACTIONS))],
            'limit' => ['nullable', 'integer', Rule::in(HyperGame::LIMITS)],
        ]);
        $user = $this->user($request);
        $seats = [['user' => $user, 'faction' => $data['faction'] ?? null], ...array_fill(0, (int) $data['bots'], ['bot' => true])];
        $match = $matches->create($seats, (int) ($data['limit'] ?? 0), creator: $user);

        if (! $request->expectsJson()) {
            return redirect()->route('hyper.match', $match);
        }

        return response()->json(['id' => $match->ulid, 'url' => route('hyper.match', $match)], 201);
    }

    /**
     * @param  Closure(): JsonResponse  $answer
     */
    private function refusing(Closure $answer): JsonResponse
    {
        try {
            return $answer();
        } catch (HyperRuleViolation $violation) {
            $status = match (true) {
                in_array($violation->reason, self::FORBIDDEN, true) => 403,
                in_array($violation->reason, self::CONFLICT, true) => 409,
                $violation->reason === 'emote_throttled' => 429,
                default => 422,
            };
            $retry = $violation->reason === 'emote_throttled' ? max(1, (int) $violation->getMessage()) : null;

            return response()->json([
                'reason' => $violation->reason,
                'message' => $this->message($violation->reason, $retry),
                ...($retry !== null ? ['retry_after' => $retry] : []),
            ], $status);
        }
    }

    private function message(string $reason, ?int $retry): string
    {
        return match ($reason) {
            'not_seated' => __('You do not play in this match.'),
            'seat_taken_over' => __('A bot plays your seat now.'),
            'already_left' => __('You left this match.'),
            'out_of_sync' => __('The match moved on. Loading the current state.'),
            'turn_timed_out' => __('Your turn ran out.'),
            'game_over' => __('The match is over.'),
            'emote_throttled' => __('Not so fast. Try again in :seconds s.', ['seconds' => $retry]),
            'unknown_emote' => __('Unknown emote.'),
            default => __('That is not allowed right now.'),
        };
    }

    private function viewer(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
