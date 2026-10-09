<?php

namespace App\Http\Controllers;

use App\Enums\HyperMatchStatus;
use App\Models\HyperMatch;
use App\Models\HyperRating;
use App\Models\HyperTable;
use App\Models\User;
use App\Support\Hyper\HyperCups;
use App\Support\Hyper\HyperEmotes;
use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperLobby;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperPoll;
use App\Support\Hyper\HyperReplay;
use App\Support\Hyper\HyperRuleViolation;
use App\Support\Hyper\HyperSeason;
use App\Support\Hyper\HyperStats;
use App\Support\Hyper\HyperTableChat;
use App\Support\Hyper\HyperTeamChat;
use App\Support\Hyper\HyperTexts;
use App\Support\Rating\RatingSettings;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use RuntimeException;

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
    private const FORBIDDEN = ['not_seated', 'seat_taken_over', 'already_left', 'no_rematch', 'not_teammate'];

    /** Refusals because the match is not where the client thought it was. */
    private const CONFLICT = ['out_of_sync', 'turn_timed_out', 'game_over'];

    /**
     * The start page in the league's shell: the lobby (P3, the Livewire component `hyper-lobby`: open tables,
     * a new table, seats and factions, bots), the quick start against bots (it opens the match in a new tab)
     * and the viewer's running matches. `tables/{table}` is a table's own link: the same page with that
     * table first, how a player invites a friend.
     */
    public function index(Request $request, ?HyperTable $table = null): View
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
            'focus' => $table?->ulid,
            'running' => $running,
            'factions' => array_keys(HyperGame::FACTIONS),
            'limits' => HyperGame::LIMITS,
            'cup' => HyperCups::current(),
        ]);
    }

    /**
     * The season ladder in the league's shell (P5, HyperSeason): `?board=ffa` (default) the free-for-all points,
     * `duel` the 1v1 Elo, `team` the team Elo, of the live season; before Block 0 and between seasons the page says
     * when rated play starts. The viewer's own season stands above the table, the weekend cup's winners wear a badge.
     */
    public function ladder(Request $request, HyperSeason $season): View
    {
        $board = in_array($request->query('board'), HyperSeason::KINDS, true) ? (string) $request->query('board') : HyperSeason::FFA;
        $slug = HyperSeason::seasonFor();
        $viewer = $this->viewer($request);

        return view('pages.hyper.ladder', [
            'board' => $board,
            'season' => $slug,
            'rows' => $slug === null ? [] : ($board === HyperSeason::FFA
                ? $season->ffaStandings($slug)
                : $season->eloStandings($slug, $board)->values()->map(fn (HyperRating $rating, int $index): array => [
                    'rank' => $index + 1, 'user' => $rating->user, 'rating' => $rating->rating, 'results' => $rating->results, 'wins' => $rating->wins, 'losses' => $rating->losses,
                ])->all()),
            'mine' => $slug === null || $viewer === null ? null : $season->summaryOf($viewer, $slug),
            'cupWins' => HyperCups::wins(),
            'cup' => HyperCups::current(),
            'start' => (int) RatingSettings::inForce()['rating']['start'],
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
                    'rematch' => route('hyper.rematch', $match, false),
                    'replay' => route('hyper.replay', $match, false),
                    'stats' => route('hyper.stats', $match, false),
                ],
                'assets' => '/hyper/',
                'locale' => app()->getLocale(),
                'csrf' => csrf_token(),
                'texts' => HyperTexts::dictionary(),
                'clips' => HyperEmotes::clips(),
                'chat' => HyperTableChat::config($match, $viewer),
                // The team chat (P4): only its url; the members come from team() for a player of the team.
                'teamChat' => $this->teamChatConfig($match, $viewer),
                // The spectators' "Who wins?" (P5): a spectator of a rated or tournament match only, never a player.
                'poll' => HyperPoll::config($match, $viewer),
            ],
        ]);
    }

    /**
     * The team chat's members for a player of the team (HyperTeamChat::members()): `{match, since, relays,
     * lookupRelays, members}`. Anybody else is refused (403 `not_teammate`); a match without teams has none (404).
     */
    public function team(Request $request, HyperMatch $match): JsonResponse
    {
        try {
            return response()->json(HyperTeamChat::members($match, $this->user($request)));
        } catch (HyperRuleViolation $violation) {
            abort_if($violation->reason === 'no_teams', 404);

            return response()->json(['reason' => $violation->reason, 'message' => __('Only your team reads this chat.')], 403);
        }
    }

    /**
     * @return array{url: string}|null
     */
    private function teamChatConfig(HyperMatch $match, ?User $viewer): ?array
    {
        $seat = $match->isTeamMatch() ? $match->seatOf($viewer) : null;

        return $seat === null || $seat->left_at !== null ? null : ['url' => route('hyper.team', $match, false)];
    }

    /**
     * The replay of a finished match (P3), full-screen on the match page's table, read-only: rebuilt from the
     * seed and the action log (HyperReplay), every hand open. The page asks for the plies and the state at a
     * ply (replayData(), replayState()); the snapshot in the page is the table after the setup.
     */
    public function replay(HyperMatch $match, HyperMatches $matches): View
    {
        $replay = $this->replayOf($match, 0);

        return view('hyper.match', [
            'snapshot' => $replay->snapshot($matches->snapshot($match, null)),
            'back' => route('hyper.index', absolute: false),
            'config' => [
                'urls' => [
                    'snapshot' => route('hyper.replay.state', $match, false),
                    'events' => route('hyper.replay.data', $match, false),
                    'stats' => route('hyper.stats', $match, false),
                ],
                'replay' => ['data' => route('hyper.replay.data', $match, false), 'state' => route('hyper.replay.state', $match, false), 'last' => $match->ply],
                'assets' => '/hyper/',
                'locale' => app()->getLocale(),
                'csrf' => csrf_token(),
                'texts' => HyperTexts::dictionary(),
                'clips' => HyperEmotes::clips(),
                // The match's table chat, read as a guest reads it: the replay writes nothing.
                'chat' => HyperTableChat::config($match, null),
            ],
        ]);
    }

    /**
     * Every ply of a finished match with its events, replayed: `{ply, plies: [{ply, seat, source, events}]}`.
     */
    public function replayData(HyperMatch $match): JsonResponse
    {
        $replay = $this->replayOf($match);

        return response()->json(['ply' => $replay->ply(), 'plies' => $replay->plies()]);
    }

    /**
     * The table of a finished match at `?ply=<n>` (default the end), replayed: the match page's snapshot.
     */
    public function replayState(Request $request, HyperMatch $match, HyperMatches $matches): JsonResponse
    {
        $ply = min($match->ply, max(0, (int) $request->query('ply', (string) $match->ply)));

        return response()->json($this->replayOf($match, $ply)->snapshot($matches->snapshot($match, null)));
    }

    /**
     * The end-of-match statistics of a finished match (HyperStats: charts per seat over the rounds, leaderboards,
     * turning points, moments), computed from the replay and cached per match. A running match has none (404),
     * like the replay. A finished match whose log does not replay has none either: reported (it would be a defect),
     * answered 204, and the page shows no statistics instead of a broken sequence.
     */
    public function stats(HyperMatch $match): JsonResponse|Response
    {
        abort_if($match->status !== HyperMatchStatus::Finished, 404);

        try {
            return response()->json(HyperStats::of($match));
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->noContent();
        }
    }

    /**
     * The player says yes to a rematch of this finished match (HyperLobby::rematch()): `{table, ready,
     * waiting, url}`, the url once everybody said yes and the new match began.
     */
    public function rematch(Request $request, HyperMatch $match, HyperLobby $lobby): JsonResponse
    {
        return $this->refusing(function () use ($request, $match, $lobby): JsonResponse {
            ['table' => $table] = $lobby->rematch($match, $this->user($request));

            return response()->json($lobby->rematchPayload($table));
        });
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
            'no_rematch' => __('A rematch follows a finished match of yours.'),
            'unknown_emote' => __('Unknown emote.'),
            default => __('That is not allowed right now.'),
        };
    }

    /**
     * The replay of a finished match up to `upTo`; a running match, or one whose log does not replay, is no
     * replay (404; the second is reported, it would be a defect).
     */
    private function replayOf(HyperMatch $match, ?int $upTo = null): HyperReplay
    {
        abort_if($match->status !== HyperMatchStatus::Finished, 404);

        try {
            return new HyperReplay($match, $upTo);
        } catch (RuntimeException $exception) {
            report($exception);
            abort(404);
        }
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
