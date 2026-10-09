<?php

namespace App\Http\Controllers;

use App\Models\PongInvite;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\Pong\PongBot;
use App\Support\Pong\PongInvites;
use App\Support\Pong\PongMatches;
use App\Support\Pong\PongPhysics;
use App\Support\Pong\PongRules;
use App\Support\Pong\PongRuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Proof of Pong's pages (plan "Proof of Pong", P1; routes/pong.php, only registered while `esports.pong.enabled` is
 * on): the lobby in the league's shell and a game against a bot on its own full-screen page.
 *
 * The bot game runs in the browser on the same deterministic core as the server (App\Support\Pong,
 * resources/js/pong): the page gets its seed, the bot's level and the rules. P1 stores no result of a game against a
 * bot (it is casual and has no Elo); P2's live games are the ones the server checks and rates.
 *
 * A live match (P2) has its own full-screen page (match()), the referee's endpoints for its two players (sync(),
 * report(), resign(), rematch(); App\Support\Pong\PongMatches) and the lobby's accept (accept()), a form that opens
 * the match in a new tab.
 */
class PongController extends Controller
{
    /** The texts the page's script shows, translated into the viewer's language (resources/js/pong/game.js t()). */
    public const array TEXTS = [
        'Rally :n', 'Meme event', 'Halving', 'The ball is half the size, its point counts double.', 'Brrr', 'The ball flies faster.',
        'Pizza Day', 'Two balls at once.', 'Difficulty Adjustment', 'Both paddles are shorter.', 'Point for you', 'Point for :name',
        'You win!', ':name wins',
    ];

    /** The live match page's texts (resources/js/pong/live.js t()). */
    public const array LIVE_TEXTS = [
        ...self::TEXTS, 'Match aborted', 'by resignation', 'by forfeit', 'Elo :before → :after (:delta)', 'Waiting for :name …',
        'Accept rematch', 'Rematch', 'Reconnecting …', ':name is gone', 'Click again to resign',
    ];

    public function index(): View
    {
        return view('pages.pong.index', [
            'levels' => PongBot::LEVELS,
            'rules' => PongRules::fromConfig((array) config('esports.pong')),
        ]);
    }

    /**
     * A game against bot level `level` (1 to 4, default 1). `seed` replays a game (the browser test's handle); without
     * it every game draws its own.
     */
    public function bot(Request $request): View
    {
        $data = $request->validate([
            'level' => ['sometimes', 'integer', 'between:1,4'],
            'seed' => ['sometimes', 'integer', 'between:0,4294967295'],
        ]);
        $level = (int) ($data['level'] ?? 1);
        $seed = isset($data['seed']) ? (int) $data['seed'] : random_int(0, 0xFFFFFFFF);

        return view('pong.match', [
            'config' => [
                'seed' => $seed,
                'level' => $level,
                'bot' => __(PongBot::LEVELS[$level]['name']),
                'rules' => PongRules::fromConfig((array) config('esports.pong'))->toArray(),
                'texts' => array_combine(self::TEXTS, array_map(fn (string $key): string => __($key), self::TEXTS)),
                'urls' => [
                    'lobby' => route('pong.index', absolute: false),
                    'again' => route('pong.bot', ['level' => $level], false),
                ],
            ],
        ]);
    }

    /**
     * A live match, full-screen: for its two players the match itself, for anybody else its score (no live view yet).
     */
    public function match(Request $request, PongMatch $match, PongMatches $matches): View
    {
        $match->loadMissing(['left', 'right']);
        $viewer = $request->user();
        $me = $match->sideOf($viewer instanceof User ? $viewer : null);
        $names = [$match->left?->displayName() ?? '?', $match->right?->displayName() ?? '?'];

        return view('pong.live', [
            'match' => $match,
            'me' => $me,
            'names' => $names,
            'config' => [
                'id' => $match->ulid,
                'me' => $me,
                'names' => $names,
                'csrf' => csrf_token(),
                'snapshot' => $matches->snapshot($match, $viewer instanceof User ? $viewer : null),
                'texts' => array_combine(self::LIVE_TEXTS, array_map(fn (string $key): string => __($key), self::LIVE_TEXTS)),
                'urls' => [
                    'lobby' => route('pong.index', absolute: false),
                    'sync' => route('pong.sync', $match, false),
                    'report' => route('pong.report', $match, false),
                    'resign' => route('pong.resign', $match, false),
                    'rematch' => route('pong.rematch', $match, false),
                ],
            ],
        ]);
    }

    /** A player's page is here (its heartbeat): the snapshot, and the referee's clock moves on. */
    public function sync(Request $request, PongMatch $match, PongMatches $matches): JsonResponse
    {
        $this->player($request, $match);

        return response()->json($matches->sync($match, $request->user()));
    }

    /**
     * The defender's report on a contact: `hit` with its paddle's centre `y`, or `goal`. Answered with the referee's
     * verdict and the snapshot; a report the referee does not take changes nothing.
     */
    public function report(Request $request, PongMatch $match, PongMatches $matches): JsonResponse
    {
        $this->player($request, $match);
        $data = $request->validate([
            'rally' => ['required', 'integer', 'min:1'],
            'ball' => ['required', 'integer', 'between:0,1'],
            'tick' => ['required', 'integer', 'between:1,'.PongPhysics::RALLY_TICK_CAP],
            'kind' => ['required', 'string', 'in:hit,goal'],
            'y' => ['nullable', 'integer', 'between:0,'.PongPhysics::HEIGHT],
        ]);

        $answer = $matches->report($match, $request->user(), [
            'rally' => (int) $data['rally'],
            'ball' => (int) $data['ball'],
            'tick' => (int) $data['tick'],
            'kind' => $data['kind'],
            'y' => isset($data['y']) ? (int) $data['y'] : null,
        ]);

        return response()->json($answer);
    }

    public function resign(Request $request, PongMatch $match, PongMatches $matches): JsonResponse
    {
        $this->player($request, $match);

        return response()->json($matches->resign($match, $request->user()));
    }

    public function rematch(Request $request, PongMatch $match, PongMatches $matches): JsonResponse
    {
        $this->player($request, $match);

        return response()->json($matches->rematch($match, $request->user()));
    }

    /**
     * The lobby's "Accept" (a form that opens a new tab): the match starts and this tab shows it. A refusal goes back
     * to the lobby with its message.
     */
    public function accept(Request $request, PongInvite $invite, PongInvites $invites): RedirectResponse
    {
        try {
            $match = $invites->accept($invite, $request->user());
        } catch (PongRuleViolation $violation) {
            return redirect()->route('pong.index')->with('pong-error', $violation->reason);
        }

        return redirect()->route('pong.match', $match);
    }

    /** Only the match's two players reach the referee. */
    private function player(Request $request, PongMatch $match): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $match->sideOf($user) !== null, 403);
    }
}
