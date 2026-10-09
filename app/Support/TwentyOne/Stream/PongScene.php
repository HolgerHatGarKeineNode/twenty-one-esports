<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\PongEndReason;
use App\Enums\PongMatchStatus;
use App\Games\GameRegistry;
use App\Games\ProofOfPong;
use App\Models\PongMatch;
use App\Support\Pong\PongCast;
use App\Support\Pong\PongMatches;

/**
 * The stream slide of a won Proof of Pong match (p1, plan "Proof of Pong", P4): the winner's victory pose, both
 * players with the figure they picked, the final score, the Elo change, and a still of the arena (a real three.js
 * frame, public/pong/art/still-arena.jpg) with the way to play. No live gameplay on the stream (user, 2026-10-09: the
 * encoder takes no live frame rate): only results.
 *
 * For the rotation (RotationPlanner, PONG): entries() lists the matches a player won within the last
 * `pong_recent_hours` (24), newest first, at most MAX_MATCHES, as the mempool counts them: two players, a winner, a
 * match that was played (a tournament no-show that never started is none). Nothing while the game is off.
 *
 * Names as the league's other slides show them (a display name, cut to fit); faces from StreamImages (the cached
 * picture or the Blockpile). No profile link, no gamer tag, no address. The texts are English, as on every slide.
 */
final class PongScene
{
    public const SCENE = 'p1';

    /** Results the rotation takes turns with. */
    public const MAX_MATCHES = 3;

    /** The short way to play the slide names. */
    public const URL = 'esports.einundzwanzig.space/proof-of-pong';

    /** @var array<string, string|null> file => its data URI, read once */
    private array $files = [];

    public function __construct(private StreamImages $images) {}

    /** Whether the game is switched on (the registry, read each time, knows it only then). */
    public function on(): bool
    {
        return (bool) config('esports.pong.enabled') && app(GameRegistry::class)->find(ProofOfPong::SLUG) !== null;
    }

    /**
     * The recent won matches the rotation shows, newest first; none while the game is off.
     *
     * @return list<array{id: int}>
     */
    public function entries(): array
    {
        if (! $this->on()) {
            return [];
        }

        $hours = max(1, (int) config('twentyone.stream.rotation.pong_recent_hours', 24));

        return array_values(PongMatch::query()->where('status', PongMatchStatus::Finished)
            ->whereNotNull('winner_id')->whereNotNull('left_id')->whereNotNull('right_id')->whereNotNull('started_at')
            ->where('ended_at', '>=', now()->subHours($hours))
            ->orderByDesc('ended_at')->orderByDesc('id')->limit(self::MAX_MATCHES)->pluck('id')
            ->map(fn (mixed $id): array => ['id' => (int) $id])->all());
    }

    /**
     * The slide's data, as resources/views/stream/rotation/p1-pong.blade.php describes it; `pong` null when the
     * match is not there, not won, or the game is off.
     *
     * @return array<string, mixed>
     */
    public function data(?int $matchId): array
    {
        $match = $matchId === null || ! $this->on() ? null : PongMatch::query()->with(['left', 'right'])->find($matchId);

        return [
            'pong' => $match === null || $match->status !== PongMatchStatus::Finished || $match->winner_id === null ? null : $this->result($match),
            'art' => ['plate' => $this->file(resource_path('stream/pong/plate.jpg'), 'image/jpeg'), 'still' => $this->file(public_path('pong/art/still-arena.jpg'), 'image/jpeg')],
            'url' => self::URL,
            'backdrop' => $this->images->backdrop(ProofOfPong::SLUG) ?? $this->images->backdrop(StreamImages::BRAND),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function result(PongMatch $match): array
    {
        $winner = $match->winner_id === $match->left_id ? 0 : 1;
        $figures = PongMatches::figuresOf($match);
        // The cast's English names: the stream speaks English.
        $names = array_column(PongCast::data()['players'], 'name', 'id');
        $ratings = [[$match->left_rating_before, $match->left_rating_after], [$match->right_rating_before, $match->right_rating_after]];
        $sides = [];

        foreach ([$winner, 1 - $winner] as $side) {
            $user = $side === 0 ? $match->left : $match->right;
            $figure = $figures[$side] ?? null;
            $known = is_string($figure) && PongCast::isPlayer($figure);
            [$before, $after] = $ratings[$side];

            $sides[] = [
                'name' => $user?->displayName() ?? 'Deleted account',
                'avatar' => $this->images->forUser($user),
                'figure' => $known ? ($names[$figure] ?? null) : null,
                // The winner's victory pose, the loser's portrait.
                'picture' => ! $known ? null : ($side === $winner
                    ? $this->file(resource_path('stream/pong/win-'.$figure.'.png'), 'image/png')
                    : $this->file(resource_path('stream/pong/por-'.$figure.'.jpg'), 'image/jpeg')),
                'points' => $match->score()[$side],
                'elo' => $before === null || $after === null ? null : ['before' => (int) $before, 'after' => (int) $after],
            ];
        }

        return [
            'id' => (int) $match->id,
            'rated' => $match->rated,
            'reason' => $match->end_reason === PongEndReason::Score ? null : $match->end_reason?->value,
            'ago' => $match->ended_at === null ? null : (int) max(0, $match->ended_at->diffInMinutes(now(), true)),
            // Winner first.
            'sides' => $sides,
        ];
    }

    /**
     * "Elo 1016 (+16)", empty without a change. ASCII only: the stream's fonts have no arrow and no minus sign.
     *
     * @param  array{before: int, after: int}|null  $elo
     */
    public static function eloLine(?array $elo): string
    {
        if ($elo === null) {
            return '';
        }

        $delta = $elo['after'] - $elo['before'];

        return 'Elo '.$elo['after'].' ('.($delta >= 0 ? '+' : '-').abs($delta).')';
    }

    /** A picture as a data URI, read once; null when it is missing. */
    private function file(string $path, string $mime): ?string
    {
        if (! array_key_exists($path, $this->files)) {
            $this->files[$path] = is_file($path) ? 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path)) : null;
        }

        return $this->files[$path];
    }
}
