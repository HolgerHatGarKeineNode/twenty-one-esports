<?php

namespace App\Http\Controllers;

use App\Enums\PongMatchStatus;
use App\Models\PongInvite;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\Cards\PageCard;
use App\Support\Pong\PongCast;
use App\Support\Pong\PongInvites;
use App\Support\Pong\PongLadder;
use App\Support\Pong\PongMatches;
use App\Support\Pong\PongPhysics;
use App\Support\Pong\PongRules;
use App\Support\Pong\PongRuleViolation;
use App\Support\Rating\EloRating;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

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
        'You win!', ':name wins', 'Taxation is Theft', 'A tax office patrols the centre line; the ball bounces off it.', 'Capital Controls',
        'A border wall with a moving gap; hit the wall and the ball comes back.', 'Few understand',
        'The ball is invisible in the middle of the field.', 'Proof of Work', 'Each of your hits makes your paddle longer.',
        'Job Centre – Please wait', 'At the centre line the ball draws a number and waits a second.', 'Stamped',
        'Your waiting number: :n', 'Markus Turm knows his way around here.',
    ];

    /** The live match page's texts (resources/js/pong/live.js t()). */
    public const array LIVE_TEXTS = [
        ...self::TEXTS, 'Match aborted', 'by resignation', 'by forfeit', 'Elo :before → :after (:delta)', 'Waiting for :name …',
        'Accept rematch', 'Rematch', 'Reconnecting …', ':name is gone', 'Click again to resign',
    ];

    public function index(Request $request): View
    {
        $viewer = $request->user();

        return view('pages.pong.index', [
            'bots' => PongCast::bots(),
            'figures' => PongCast::players(),
            'rules' => PongRules::fromConfig((array) config('esports.pong')),
            // The viewer's latest won live match, to share (P4, SharePosts `pong`).
            // The phone's segmented control starts on the live 1v1 when something waits there for the viewer (P9).
            'liveFirst' => $viewer instanceof User && ($viewer->looking_to_play === PongInvites::LOOKING || app(PongInvites::class)->incoming($viewer)->isNotEmpty() || PongMatches::activeMatchOf($viewer) !== null),
            'lastWin' => $viewer instanceof User ? PongMatch::query()->where('status', PongMatchStatus::Finished)->where('winner_id', $viewer->id)->with(['left', 'right'])->latest('id')->first() : null,
        ]);
    }

    /**
     * The Elo ladder in the league's shell (P4, PongLadder): every player with a rated live match, by Elo; the
     * viewer's own place above the table.
     */
    public function ladder(Request $request): View
    {
        $viewer = $request->user();

        return view('pages.pong.ladder', [
            'rows' => PongLadder::standings(),
            'mine' => $viewer instanceof User ? PongLadder::card($viewer) : null,
            'start' => EloRating::fromConfig('casual')->start,
        ]);
    }

    /**
     * A game against a bot: `bot` names it (the cast's bots, App\Support\Pong\PongCast), else `level` (1 to 4, default
     * 1) picks that level's own. `figure` is the player's paddle from the lobby; without it the page shows the
     * browser's last pick. `seed` replays a game (the browser test's handle); without it every game draws its own.
     */
    public function bot(Request $request): View
    {
        $data = $request->validate([
            'level' => ['sometimes', 'integer', 'between:1,4'],
            'bot' => ['sometimes', 'string', 'in:'.implode(',', PongCast::botIds())],
            'figure' => ['sometimes', 'string', 'in:'.implode(',', PongCast::playerIds())],
            'seed' => ['sometimes', 'integer', 'between:0,4294967295'],
        ]);
        $bot = PongCast::bot($data['bot'] ?? null, (int) ($data['level'] ?? 1));
        $level = $bot['level'];
        $seed = isset($data['seed']) ? (int) $data['seed'] : random_int(0, 0xFFFFFFFF);
        $figure = $data['figure'] ?? null;
        $arena = collect(PongCast::data()['bots'])->firstWhere('id', $bot['id'])['arena'] ?? 'studio';

        return view('pong.match', [
            'figures' => PongCast::players(),
            'config' => [
                'seed' => $seed,
                'level' => $level,
                'bot' => $bot['name'],
                'botTagline' => $bot['tagline'],
                'botFigure' => $bot['id'],
                'figure' => $figure ?? PongCast::playerIds()[0],
                'figurePicked' => $figure,
                'arena' => $arena,
                'rules' => PongRules::fromConfig((array) config('esports.pong'))->toArray(),
                'texts' => array_combine(self::TEXTS, array_map(fn (string $key): string => __($key), self::TEXTS)),
                'castTexts' => PongCast::texts(),
                'urls' => [
                    'lobby' => route('pong.index', absolute: false),
                    'again' => route('pong.bot', array_filter(['bot' => $bot['id'], 'figure' => $figure]), false),
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

        $arenas = PongCast::data()['arenas'];

        return view('pong.live', [
            'match' => $match,
            'me' => $me,
            'names' => $names,
            'figures' => PongCast::players(),
            'preview' => $this->preview($match),
            'config' => [
                'id' => $match->ulid,
                'me' => $me,
                'names' => $names,
                'csrf' => csrf_token(),
                'snapshot' => $matches->snapshot($match, $viewer instanceof User ? $viewer : null),
                'texts' => array_combine(self::LIVE_TEXTS, array_map(fn (string $key): string => __($key), self::LIVE_TEXTS)),
                'castTexts' => PongCast::texts(),
                'arena' => $arenas[$match->seed % count($arenas)],
                'urls' => [
                    'lobby' => route('pong.index', absolute: false),
                    'sync' => route('pong.sync', $match, false),
                    'figure' => route('pong.figure', $match, false),
                    'report' => route('pong.report', $match, false),
                    'resign' => route('pong.resign', $match, false),
                    'rematch' => route('pong.rematch', $match, false),
                ],
            ],
        ]);
    }

    /**
     * The link preview of a match (P4): its card (PageCard::pong(), the score, live or final), a title and a line.
     * The page stays out of search (noindex); a preview that fails to build is reported and left out.
     *
     * @return array{title: string, description: string, image: string, alt: string}|null
     */
    private function preview(PongMatch $match): ?array
    {
        try {
            $card = PageCard::pong($match);

            return ['title' => 'Proof of Pong', 'description' => $card->alt(), 'image' => $card->url(), 'alt' => $card->alt()];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
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

    /** A player's figure for the match (P3): one of the cast's players, before the first point. */
    public function figure(Request $request, PongMatch $match, PongMatches $matches): JsonResponse
    {
        $this->player($request, $match);
        $data = $request->validate([
            'figure' => ['required', 'string', 'in:'.implode(',', PongCast::playerIds())],
        ]);

        return response()->json($matches->figure($match, $request->user(), $data['figure']));
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
