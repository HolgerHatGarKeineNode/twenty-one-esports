<?php

namespace App\Http\Controllers;

use App\Support\Pong\PongBot;
use App\Support\Pong\PongRules;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Proof of Pong's pages (plan "Proof of Pong", P1; routes/pong.php, only registered while `esports.pong.enabled` is
 * on): the lobby in the league's shell and a game against a bot on its own full-screen page.
 *
 * The bot game runs in the browser on the same deterministic core as the server (App\Support\Pong,
 * resources/js/pong): the page gets its seed, the bot's level and the rules. P1 stores no result of a game against a
 * bot (it is casual and has no Elo); P2's live games are the ones the server checks and rates.
 */
class PongController extends Controller
{
    /** The texts the page's script shows, translated into the viewer's language (resources/js/pong/game.js t()). */
    public const array TEXTS = [
        'Rally :n', 'Meme event', 'Halving', 'The ball is half the size, its point counts double.', 'Brrr', 'The ball flies faster.',
        'Pizza Day', 'Two balls at once.', 'Difficulty Adjustment', 'Both paddles are shorter.', 'Point for you', 'Point for :name',
        'You win!', ':name wins',
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
}
