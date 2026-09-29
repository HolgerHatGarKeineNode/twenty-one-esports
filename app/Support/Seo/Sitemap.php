<?php

namespace App\Support\Seo;

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\GameNames;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * What /sitemap.xml lists (P14): the public pages that describe themselves
 * (App\Support\PageMeta::describe()), never a private one. Every section is
 * split into files of PER_FILE pages; each page appears once per language,
 * with its hreflang alternates, so a file holds PER_FILE times the number of
 * locales <url> entries (well under the protocol's 50,000).
 *
 *   pages     the fixed public pages (the tournament list among them) and every ladder
 *   players   every player page
 *   clans     every clan page
 *   matches   Rocket League series that were accepted (scheduled, played or
 *             decided); open, declined, withdrawn and expired challenges are
 *             left out: no series was ever played there
 *   games     finished chess games (live ones change every move, aborted ones never started)
 *   tournaments  published tournaments, whatever became of them since (a
 *             called-off one keeps its page); drafts never, like their pages
 *             (pages/tournaments/show), which describe themselves only once published
 */
final class Sitemap
{
    public const PER_FILE = 1000;

    public const SECTIONS = ['pages', 'players', 'clans', 'matches', 'games', 'tournaments'];

    /** Series statuses from the accept on. */
    public const LISTED_SERIES = [SeriesStatus::Accepted, SeriesStatus::Reported, SeriesStatus::Disputed, SeriesStatus::Confirmed, SeriesStatus::Resolved];

    public function __construct(private readonly int $perFile = self::PER_FILE) {}

    /**
     * Number of files per section; a section without pages has none.
     *
     * @return array<string, int>
     */
    public function files(): array
    {
        $files = ['pages' => 1];

        foreach (['players', 'clans', 'matches', 'games', 'tournaments'] as $section) {
            $files[$section] = (int) ceil($this->query($section)->count() / $this->perFile);
        }

        return $files;
    }

    /**
     * The pages of one file, null when the file does not exist.
     *
     * @return list<array{url: string, lastmod: CarbonInterface|null}>|null
     */
    public function entries(string $section, int $file): ?array
    {
        if (! in_array($section, self::SECTIONS, true) || $file < 1) {
            return null;
        }

        if ($section === 'pages') {
            return $file === 1 ? $this->fixedPages() : null;
        }

        $rows = $this->query($section)->orderBy('id')->forPage($file, $this->perFile)->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $entries = [];

        foreach ($rows as $row) {
            $lastmod = $row->getAttribute('updated_at');
            $entries[] = ['url' => $this->urlOf($section, $row), 'lastmod' => $lastmod instanceof CarbonInterface ? $lastmod : null];
        }

        return $entries;
    }

    /**
     * @return list<array{url: string, lastmod: CarbonInterface|null}>
     */
    private function fixedPages(): array
    {
        $urls = [route('home'), route('clans.index'), route('matches.index'), route('chess.lobby'), route('games.rocket-league'), route('mining'), route('tournaments.index'), route('play')];

        foreach (array_keys(app(GameRegistry::class)->series()) as $series) {
            $urls[] = GameNames::page($series);
        }

        $urls = array_values(array_unique($urls));

        foreach (app(GameRegistry::class)->all() as $game) {
            // Board games open their ladders in P5 of plan "Mühle und Dame".
            if ($game->kind() === GameKind::Board) {
                continue;
            }

            foreach ($game->modes() as $mode) {
                $urls[] = route('ladder.show', [$game->slug(), $mode->slug]);
            }
        }

        $urls[] = route('ladder.strongest');

        return array_map(fn (string $url): array => ['url' => $url, 'lastmod' => null], $urls);
    }

    /**
     * @return Builder<covariant Model>
     */
    private function query(string $section): Builder
    {
        return match ($section) {
            'players' => User::query()->select(['id', 'npub', 'updated_at']),
            'clans' => Clan::query()->select(['id', 'slug', 'updated_at']),
            'matches' => SeriesMatch::query()->select(['id', 'number', 'updated_at'])->whereIn('status', self::LISTED_SERIES),
            'games' => ChessGame::query()->select(['id', 'updated_at'])->where('status', ChessGameStatus::Finished),
            'tournaments' => Tournament::query()->select(['id', 'updated_at'])->where('status', '!=', TournamentStatus::Draft)->whereNotNull('published_at'),
            default => throw new InvalidArgumentException("Unknown sitemap section [{$section}]."),
        };
    }

    private function urlOf(string $section, Model $row): string
    {
        return match ($section) {
            'players' => route('players.show', $row->getAttribute('npub')),
            'clans' => route('clans.show', $row->getAttribute('slug')),
            'matches' => route('matches.show', $row->getAttribute('number')),
            'games' => route('games.show', $row->getKey()),
            'tournaments' => route('tournaments.show', $row->getKey()),
            default => throw new InvalidArgumentException("Unknown sitemap section [{$section}]."),
        };
    }
}
