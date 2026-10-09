<?php

namespace App\Support\Seo;

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
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
use Illuminate\Support\Facades\Route;
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
 *             (pages/tournaments/show), which describe themselves only once published;
 *             never a Blockfill week (plan "Blockfill", P6), whose boards are on
 *             Blockfill's own pages (in `pages` while it is switched on)
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

        $registry = app(GameRegistry::class);

        // Every game's pages in the registry's display order (user 2026-10-03: Nine Men's Morris and Checkers last).
        foreach ($registry->all() as $game) {
            if ($registry->isSeries($game->slug())) {
                $urls[] = GameNames::page($game->slug());
            }

            // A board game's lobby (plan "Mühle und Dame", P5), while its route is there.
            if ($game->kind() === GameKind::Board && Route::has('board.lobby')) {
                $urls[] = route('board.lobby', $game->slug());
            }

            // A score game (plan "AoE2 und Trackmania", P4) has no Elo ladder: its leaderboards' page instead.
            // Blockfill (plan "Blockfill", P6) has a page of its own besides them.
            if ($game->kind() === GameKind::Score) {
                $urls[] = GameNames::page($game->slug());

                if (Route::has('scores.show')) {
                    $urls[] = route('scores.show', $game->slug());
                }

                // Blockfill's replays: an ended week's first ten are public.
                if ($game->slug() === Blockfill::SLUG && Route::has('stacker.replays')) {
                    $urls[] = route('stacker.replays');
                }

                continue;
            }

            // Hyperbitcoinization (plan "Hyperbitcoinization", P6): its lobby and its own season ladder, while routed;
            // no Rating ladder and no single match (a match page is full-screen and noindex, as board games list none).
            if ($game->kind() === GameKind::Strategy) {
                if (Route::has('hyper.index') && Route::has('hyper.ladder')) {
                    $urls[] = route('hyper.index');
                    $urls[] = route('hyper.ladder');
                }

                continue;
            }

            foreach ($game->modes() as $mode) {
                $urls[] = route('ladder.show', [$game->slug(), $mode->slug]);
            }
        }

        $urls[] = route('ladder.strongest');

        return array_map(fn (string $url): array => ['url' => $url, 'lastmod' => null], array_values(array_unique($urls)));
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
            // Blockfill's weekly boards (plan "Blockfill", P6) are listed on its own pages, not as tournaments.
            'tournaments' => Tournament::query()->select(['id', 'updated_at'])->where('status', '!=', TournamentStatus::Draft)->whereNotNull('published_at')->exceptLeagueWeeks(),
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
