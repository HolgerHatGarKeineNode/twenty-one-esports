<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Rating;
use App\Models\User;
use App\Support\Rating\Ratings;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The real numbers the stream's teaser scenes show, counted from the
 * database and kept for `twentyone.stream.stats.cache_seconds`, so a scene
 * change does not query every second. Plain arrays for the Blade scenes.
 *
 * Every count means what the site means by it: players, clans and games
 * played as the footer counts them (finished chess games), the casual
 * ladders as the ladder page orders them. Names are raw display names;
 * the scene views clean and escape them. Nothing here is estimated.
 *
 * The cached counts carry plain picture refs (StreamImages::avatarRef(),
 * ::logoRef()); all() turns them into data URIs from the daemon's memory:
 * every ladder row gets `avatar`, the clan spotlight `logo` (our redrawn
 * logo, else null).
 */
class StreamStats
{
    public function __construct(private StreamImages $images) {}

    public const CACHE_KEY = 'twentyone.stream.stats';

    /** Ladder slug => chess mode. */
    public const LADDERS = ['blitz' => 'blitz', 'daily' => ChessGame::CORRESPONDENCE];

    /**
     * @return array{players: int, clans: int, gamesPlayed: int, liveNow: int, gamesToday: int, ladders: array{blitz: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatar: string|null}>, daily: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatar: string|null}>}, clan: array{name: string, tag: string, members: int, games: int, founded: string|null, logoUrl: string|null, logo: string|null}|null}
     */
    public function all(): array
    {
        $seconds = max(1, (int) config('twentyone.stream.stats.cache_seconds', 15));

        // A failing cache store must not stop the scene: count directly then.
        try {
            $counts = Cache::remember(self::CACHE_KEY, $seconds, fn (): array => $this->count());
        } catch (Throwable $e) {
            report($e);

            $counts = $this->count();
        }

        return $this->withPictures($counts);
    }

    /**
     * count() with its refs turned into data URIs; a count cached before
     * the refs existed (just after a deploy) gets no picture, not an error.
     *
     * @param  array{players: int, clans: int, gamesPlayed: int, liveNow: int, gamesToday: int, ladders: array{blitz: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatarRef: array{id: int, pubkey: string, source: string|null}|null}>, daily: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatarRef: array{id: int, pubkey: string, source: string|null}|null}>}, clan: array{name: string, tag: string, members: int, games: int, founded: string|null, logoUrl: string|null, logoRef: string|null}|null}  $counts
     * @return array{players: int, clans: int, gamesPlayed: int, liveNow: int, gamesToday: int, ladders: array{blitz: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatar: string|null}>, daily: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatar: string|null}>}, clan: array{name: string, tag: string, members: int, games: int, founded: string|null, logoUrl: string|null, logo: string|null}|null}
     */
    private function withPictures(array $counts): array
    {
        $clan = $counts['clan'];

        return [
            'players' => $counts['players'],
            'clans' => $counts['clans'],
            'gamesPlayed' => $counts['gamesPlayed'],
            'liveNow' => $counts['liveNow'],
            'gamesToday' => $counts['gamesToday'],
            'ladders' => ['blitz' => $this->ladderWithAvatars($counts['ladders']['blitz']), 'daily' => $this->ladderWithAvatars($counts['ladders']['daily'])],
            'clan' => $clan === null ? null : [
                'name' => $clan['name'], 'tag' => $clan['tag'], 'members' => $clan['members'], 'games' => $clan['games'],
                'founded' => $clan['founded'], 'logoUrl' => $clan['logoUrl'], 'logo' => $this->images->logo($clan['logoRef'] ?? null),
            ],
        ];
    }

    /**
     * @return array{players: int, clans: int, gamesPlayed: int, liveNow: int, gamesToday: int, ladders: array{blitz: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatarRef: array{id: int, pubkey: string, source: string|null}|null}>, daily: list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatarRef: array{id: int, pubkey: string, source: string|null}|null}>}, clan: array{name: string, tag: string, members: int, games: int, founded: string|null, logoUrl: string|null, logoRef: string|null}|null}
     */
    public function count(): array
    {
        $timezone = (string) config('twentyone.stream.stats.timezone', 'Europe/Berlin');

        return [
            'players' => User::query()->count(),
            'clans' => Clan::query()->count(),
            'gamesPlayed' => ChessGame::query()->where('status', ChessGameStatus::Finished)->count(),
            'liveNow' => ChessGame::query()->where('status', ChessGameStatus::Active)->count(),
            // ended_at is stored in UTC; the day starts at local midnight.
            'gamesToday' => ChessGame::query()->where('status', ChessGameStatus::Finished)
                ->where('ended_at', '>=', now($timezone)->startOfDay()->utc())->count(),
            'ladders' => [
                'blitz' => $this->ladder(self::LADDERS['blitz']),
                'daily' => $this->ladder(self::LADDERS['daily']),
            ],
            'clan' => $this->clanSpotlight(),
        ];
    }

    /**
     * @param  list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatarRef: array{id: int, pubkey: string, source: string|null}|null}>  $rows
     * @return list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatar: string|null}>
     */
    private function ladderWithAvatars(array $rows): array
    {
        $ladder = [];

        foreach ($rows as $row) {
            $ladder[] = [
                'rank' => $row['rank'], 'name' => $row['name'], 'elo' => $row['elo'], 'games' => $row['games'],
                'wins' => $row['wins'], 'draws' => $row['draws'], 'losses' => $row['losses'],
                'avatar' => $this->images->avatar($row['avatarRef'] ?? null),
            ];
        }

        return $ladder;
    }

    /**
     * The top of a casual chess ladder, in the ladder page's order: rating,
     * then more results, then first rated; only players with a result.
     *
     * @return list<array{rank: int, name: string, elo: int, games: int, wins: int, draws: int, losses: int, avatarRef: array{id: int, pubkey: string, source: string|null}|null}>
     */
    private function ladder(string $mode): array
    {
        return array_values(Rating::query()
            ->where(['pool' => Rating::CASUAL, 'season' => Ratings::season(Rating::CASUAL, 'chess', $mode), 'game' => 'chess', 'mode' => $mode])
            ->where('results', '>', 0)
            ->with('user')
            ->orderByDesc('rating')->orderByDesc('results')->orderBy('id')
            ->limit(max(1, (int) config('twentyone.stream.stats.ladder_rows', 4)))
            ->get()
            ->values()
            ->map(fn (Rating $row, int $index): array => [
                'rank' => $index + 1,
                // The ladder page's name, "Deleted account" in its English form.
                'name' => $row->user?->displayName() ?? 'Deleted account',
                'elo' => $row->rating,
                'games' => $row->results,
                'wins' => $row->wins,
                'draws' => $row->draws,
                'losses' => $row->losses,
                'avatarRef' => StreamImages::avatarRef($row->user),
            ])
            ->all());
    }

    /**
     * One clan, taking turns every `clan_spotlight_seconds` in founding
     * order. Games are finished chess games a current member played in.
     *
     * @return array{name: string, tag: string, members: int, games: int, founded: string|null, logoUrl: string|null, logoRef: string|null}|null
     */
    private function clanSpotlight(): ?array
    {
        $count = Clan::query()->count();

        if ($count === 0) {
            return null;
        }

        $turn = intdiv(now()->getTimestamp(), max(1, (int) config('twentyone.stream.stats.clan_spotlight_seconds', 600))) % $count;
        $clan = Clan::query()->withCount('members')->orderBy('id')->skip($turn)->first();

        if ($clan === null) {
            return null;
        }

        $members = $clan->members()->pluck('user_id');

        return [
            'name' => $clan->name,
            'tag' => $clan->clantag,
            'members' => (int) $clan->getAttribute('members_count'),
            'games' => ChessGame::query()->where('status', ChessGameStatus::Finished)
                ->where(fn ($query) => $query->whereIn('white_id', $members)->orWhereIn('black_id', $members))
                ->count(),
            'founded' => $clan->created_at?->format('M j, Y'),
            'logoUrl' => filled($clan->picture) ? $clan->picture : null,
            'logoRef' => StreamImages::logoRef($clan),
        ];
    }
}
