<?php

namespace App\Support\Cards;

use App\Enums\TournamentFormat;
use App\Games\Blockfill;
use App\Games\BoardGame as BoardGameDefinition;
use App\Games\GameRegistry;
use App\Games\ScoreMetric;
use App\Games\TrackmaniaNationsForever;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\GameNames;
use App\Support\Nostr\NostrKeys;
use App\Support\Rating\RankTiers;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The link preview of every public page (P54): one 1200 × 630 PNG per page
 * and state, drawn with GD on the share cards' Canvas, in the house brand
 * (dark ground, Bitcoin orange, Unbounded and JetBrains Mono, the 21 mark).
 *
 * What a card shows are the page's facts ({@see PageCardFacts}); their hash
 * (the fingerprint) is the file name in the cache and the `v` of the card's
 * URL. So a card is drawn once per state, a change of the data (a result,
 * the pot, a new face) draws a new one and drops the old file, and a link
 * re-shared after the change points at the new picture even where a client
 * keeps previews per URL.
 *
 * Each card has one hero, the thing a preview of that page should carry:
 * the board and the result of a game, the places and the pot of a
 * tournament (its podium once it is over), a player's face and best rank,
 * the podium of a ladder, the supply of the season. The number that matters
 * sits in an orange block, the league's mined block. Every text is at least
 * 28 px, so it stays readable in a 500 px wide preview.
 */
final class PageCard
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    public const TYPES = ['game', 'tournament', 'player', 'clan', 'series', 'ladder', 'board', 'leaderboard', 'page'];

    /**
     * The fixed pages with a card of their own (type `page`); next to them a
     * series game's hub (`hub.<game>`), a board game's lobby
     * (`board.<game>`) and correspondence page (`board-daily.<game>`), a
     * score game's page (`scores.<game>`), Blockfill (`blockfill`) and its
     * replays (`blockfill-replays`).
     */
    public const PAGES = ['home', 'login', 'clans', 'matches', 'games', 'chess', 'tournaments', 'play', 'rules', 'protocol', 'mining', 'live', 'strongest'];

    /** Bump when a layout changes: every card gets a new file and URL. */
    private const LAYOUT = 1;

    /** The text sizes of the cards; nothing is drawn smaller than MIN. */
    private const MIN = 28;

    private const M = 64;

    private const RIGHT = self::WIDTH - self::M;

    private const LIVE = '#FF3B3B';

    private const WIN = '#4ADE80';

    private const LOSS = '#F87171';

    private const DARK = '#17120A';

    /** The least room between the places count and the pot on the line above the places. */
    public const SEATS_GAP = 64;

    private Canvas $c;

    /**
     * @param  array<string, mixed>  $facts
     */
    private function __construct(public readonly string $type, public readonly string $key, public readonly array $facts) {}

    public static function game(ChessGame $game): self
    {
        return new self('game', (string) $game->id, PageCardFacts::game($game));
    }

    public static function tournament(Tournament $tournament): self
    {
        return new self('tournament', (string) $tournament->id, PageCardFacts::tournament($tournament));
    }

    public static function player(User $user): self
    {
        return new self('player', (string) $user->npub, PageCardFacts::player($user));
    }

    public static function clan(Clan $clan): self
    {
        return new self('clan', $clan->slug, PageCardFacts::clan($clan));
    }

    public static function series(SeriesMatch $match): self
    {
        return new self('series', (string) $match->number, PageCardFacts::series($match));
    }

    public static function ladder(string $game, string $mode): self
    {
        return new self('ladder', $game.'.'.$mode, PageCardFacts::ladder($game, $mode));
    }

    /** A board game, live or finished (plan "Mühle und Dame"). */
    public static function boardGame(BoardGame $game): self
    {
        return new self('board', (string) $game->id, PageCardFacts::board($game));
    }

    /** A score leaderboard (`tournaments/<id>/scores`), a Blockfill week among them. */
    public static function leaderboard(Tournament $tournament): self
    {
        return new self('leaderboard', (string) $tournament->id, PageCardFacts::leaderboard($tournament));
    }

    /** A fixed page (PAGES), or a game's page: `hub.`, `board.`, `board-daily.` or `scores.` and its slug, or `blockfill` and `blockfill-replays`. */
    public static function page(string $page): self
    {
        $facts = match (true) {
            $page === 'mining' => PageCardFacts::mining(),
            $page === 'live' => PageCardFacts::live(),
            $page === 'strongest' => PageCardFacts::strongest(),
            $page === 'blockfill' => PageCardFacts::blockfill(),
            $page === 'blockfill-replays' => PageCardFacts::blockfillReplays(),
            str_starts_with($page, 'hub.') => PageCardFacts::hub(substr($page, 4)),
            str_starts_with($page, 'board.') => PageCardFacts::boardLobby(substr($page, 6), false),
            str_starts_with($page, 'board-daily.') => PageCardFacts::boardLobby(substr($page, 12), true),
            str_starts_with($page, 'scores.') => PageCardFacts::scoreGame(substr($page, 7)),
            in_array($page, self::PAGES, true) => PageCardFacts::page($page),
            default => throw new InvalidArgumentException("No page card for {$page}."),
        };

        return new self('page', $page, $facts);
    }

    /**
     * The card of a URL's type and key, null when there is no public page
     * behind it (a draft tournament, an unknown number): those are a 404.
     */
    public static function resolve(string $type, string $key): ?self
    {
        return match ($type) {
            'game' => ctype_digit($key) && ($game = ChessGame::query()->find((int) $key)) !== null ? self::game($game) : null,
            'tournament' => ctype_digit($key) && ($tournament = Tournament::query()->find((int) $key)) !== null && $tournament->isVisibleTo(null) && $tournament->published_at !== null
                ? self::tournament($tournament) : null,
            'player' => ($user = User::query()->where('npub', $key)->first()) !== null ? self::player($user) : null,
            'clan' => ($clan = Clan::query()->where('slug', $key)->first()) !== null ? self::clan($clan) : null,
            'series' => ctype_digit($key) && ($match = SeriesMatch::query()->where('number', (int) $key)->first()) !== null ? self::series($match) : null,
            'ladder' => str_contains($key, '.') && app(GameRegistry::class)->mode(...explode('.', $key, 2)) !== null ? self::ladder(...explode('.', $key, 2)) : null,
            'board' => ctype_digit($key) && ($board = BoardGame::query()->find((int) $key)) !== null && app(GameRegistry::class)->isBoard($board->game) ? self::boardGame($board) : null,
            'leaderboard' => ctype_digit($key) && ($tournament = Tournament::query()->find((int) $key)) !== null && self::hasPublicLeaderboard($tournament)
                ? self::leaderboard($tournament) : null,
            'page' => self::isPage($key) ? self::page($key) : null,
            default => null,
        };
    }

    /** Whether a page key names a page that exists now: a fixed one, or the page of a game that is switched on. */
    private static function isPage(string $key): bool
    {
        $games = app(GameRegistry::class);
        $slug = Str::after($key, '.');

        return match (true) {
            in_array($key, self::PAGES, true) => true,
            $key === 'blockfill', $key === 'blockfill-replays' => $games->find(Blockfill::SLUG) !== null,
            str_starts_with($key, 'hub.') => $games->isSeries($slug),
            str_starts_with($key, 'board.') => $games->isBoard($slug),
            str_starts_with($key, 'board-daily.') => $games->isBoard($slug) && $games->mode($slug, BoardGame::CORRESPONDENCE) !== null,
            str_starts_with($key, 'scores.') => $games->isScore($slug),
            default => false,
        };
    }

    /** A leaderboard a guest may see: published, public, of a score game that is switched on. */
    public static function hasPublicLeaderboard(Tournament $tournament): bool
    {
        return $tournament->published_at !== null && $tournament->isVisibleTo(null) && app(GameRegistry::class)->isScore($tournament->game);
    }

    /** The PNG bytes in the current locale, from the cache when the facts are unchanged. */
    public function png(): string
    {
        $directory = 'page-cards/'.$this->type.'/'.$this->key;
        $prefix = App::getLocale().'-';
        $path = $directory.'/'.$prefix.$this->fingerprint().'.png';
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $png = $this->render();

        // The previous state of this card in this language is gone for good: its URL now draws the new one.
        foreach ($disk->files($directory) as $old) {
            if (str_starts_with(basename($old), $prefix)) {
                $disk->delete($old);
            }
        }

        $disk->put($path, $png);

        return $png;
    }

    /** Everything the card shows: a change is a new file and a new `v`. */
    public function fingerprint(): string
    {
        return substr(hash('sha256', (string) json_encode([self::LAYOUT, $this->type, $this->key, App::getLocale(), $this->facts, config('app.url'), ...$this->coverVersions()])), 0, 16);
    }

    /**
     * The versions of the game covers the card may draw (its game, its board's, the next tournament's), so new art
     * is a new file and a new `v`; none for a card without a game.
     *
     * @return list<string>
     */
    private function coverVersions(): array
    {
        $games = app(GameRegistry::class);
        $slugs = array_filter([$this->facts['game'] ?? null, $this->facts['board']['game'] ?? null, $this->facts['next']['game'] ?? null], 'is_string');

        return array_values(array_filter(array_map(fn (string $slug): ?string => $games->coverVersion($slug), array_unique($slugs))));
    }

    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/cards/'.App::getLocale().'/page/'.$this->type.'/'.$this->key.'.png?v='.$this->fingerprint();
    }

    /** The drawn-out words of the card, for og:image:alt. */
    public function alt(): string
    {
        $f = $this->facts;

        return match ($this->type) {
            'game' => match ($f['result']) {
                '1-0' => __(':name won', ['name' => $f['white']['name']]),
                '0-1' => __(':name won', ['name' => $f['black']['name']]),
                default => $this->gameHeadline(),
            }.'. '.$f['white']['name'].' '.__('(white)').', '.$f['black']['name'].' '.__('(black)').'.',
            'tournament' => $f['name'].'. '.$this->tournamentStatus().'.'.($f['score_unit'] !== null ? ' '.$this->leaderLine().'.' : ''),
            'player' => $f['name'].($f['best'] !== null ? '. '.$this->bestLine() : '').'.',
            'clan' => $f['name'].'. '.trans_choice(':count player|:count players', (int) $f['members']).'.',
            'series' => $f['challenger']['name'].' vs '.$f['challenged']['name'].'.',
            'ladder' => $this->ladderTitle().'.',
            'board' => match ($f['result']) {
                '1-0' => __(':name won', ['name' => $this->sideName('white')]),
                '0-1' => __(':name won', ['name' => $this->sideName('black')]),
                default => $this->gameHeadline(),
            }.'. '.$this->sideName('white').' '.__('(white)').', '.$this->sideName('black').' '.__('(black)').'. '.GameNames::game((string) $f['game']).'.',
            'leaderboard' => $this->leaderboardTitle($f).'. '.$this->leaderboardStatus().'.',
            default => $this->pageTitle().'.',
        };
    }

    /* ---------- Drawing ---------------------------------------------------------------------------------------- */

    public function render(): string
    {
        $this->c = new Canvas(self::WIDTH, self::HEIGHT);

        match ($this->type) {
            'game' => $this->drawGame(),
            'tournament' => $this->drawTournament(),
            'player' => $this->drawPlayer(),
            'clan' => $this->drawClan(),
            'series' => $this->drawSeries(),
            'ladder' => $this->drawLadder(),
            'board' => $this->drawBoard(),
            'leaderboard' => $this->drawLeaderboard(),
            default => match (true) {
                $this->key === 'mining' => $this->drawMining(),
                $this->key === 'live' => $this->drawLive(),
                $this->key === 'strongest' => $this->drawStrongest(),
                $this->key === 'blockfill' => $this->drawBlockfill(),
                $this->key === 'blockfill-replays' => $this->drawBlockfillReplays(),
                str_starts_with($this->key, 'hub.') => $this->drawHub(),
                str_starts_with($this->key, 'board.'), str_starts_with($this->key, 'board-daily.') => $this->drawBoardLobby(),
                str_starts_with($this->key, 'scores.') => $this->drawScoreGame(),
                default => $this->drawPage(),
            },
        };

        $this->footer();

        return $this->c->png();
    }

    /**
     * The card for anything that fails: the mark, the name and the host,
     * drawn without a query.
     */
    public static function brand(): string
    {
        $card = new self('page', 'brand', ['page' => 'brand', 'figures' => [], 'next' => null, 'games' => []]);

        return $card->render();
    }

    /* ---------- Chess game ------------------------------------------------------------------------------------- */

    private function drawGame(): void
    {
        $f = $this->facts;
        $size = 446;
        $this->board((string) $f['fen'], $f['last'], self::M, 48, $size);

        $x = self::M + $size + 56;
        $width = self::RIGHT - $x;
        $result = $f['result'];
        $winner = match ($result) {
            '1-0' => 'white',
            '0-1' => 'black',
            default => null,
        };

        // Black at the top and white at the bottom, level with their side of the board.
        $this->gamePlayer($f['black'], 'black', $x, 48, $width, $winner, false);
        $this->gamePlayer($f['white'], 'white', $x, 48 + $size, $width, $winner, true);

        // The state: the result in the orange block, the live move, or the aborted game.
        $block = 96;
        $y = 176;
        [$face, $label, $ink] = match ($f['status']) {
            'active' => [self::LIVE, __('Live'), self::DARK],
            'aborted' => ['#3A3A42', '–', Canvas::INK],
            default => [Canvas::ORANGE, match ($result) {
                '1-0' => '1–0',
                '0-1' => '0–1',
                default => '½–½',
            }, self::DARK],
        };
        $this->c->block($x, $y + 8, $block, $face);
        $labelSize = $this->c->fitSize($label, 'display', [40, 34, 30], $block - 16);
        $this->c->text($label, 'display', $labelSize, $x + ($block - $this->c->width($label, 'display', $labelSize)) / 2, $y + 8 + $block / 2 + $labelSize * 0.36, $ink);

        // Beside the block: who won (by colour: the crown on the name says who) and the kind of game.
        $tx = $x + $block + 28;
        $headline = $this->gameHeadline();
        $headSize = $this->c->fitSize($headline, 'display', [40, 34, 30, 28], self::RIGHT - $tx);
        $this->c->text($this->c->fit($headline, 'display', $headSize, self::RIGHT - $tx), 'display', $headSize, $tx, $y + 50, $f['status'] === 'active' ? self::LIVE : Canvas::INK);
        $this->c->text($this->c->fit($this->gameKind(), 'mono', self::MIN, self::RIGHT - $tx), 'mono', self::MIN, $tx, $y + 92, Canvas::INK_3);

        // Under it, the full width of the column: how it ended (with the moves when that fits), and the tournament.
        [$long, $short] = $this->gameDetail();
        $detail = $this->c->width($long, 'mono', self::MIN) <= $width ? $long : $short;
        $this->c->text($this->c->fit($detail, 'mono', self::MIN, $width), 'mono', self::MIN, $x, $y + 146, Canvas::INK_2);

        if ($f['tournament'] !== null) {
            $this->c->text($this->c->fit((string) $f['tournament'], 'mono', self::MIN, $width), 'mono', self::MIN, $x, $y + 184, Canvas::ORANGE);
        }
    }

    /**
     * One player's row: face, name, rating. The winner's face is larger in
     * an orange frame with the crown; the loser's name steps back.
     *
     * @param  array<string, mixed>  $p
     */
    private function gamePlayer(array $p, string $colour, int $x, int $edge, int $width, ?string $winner, bool $bottom): void
    {
        $won = $winner === $colour;
        $lost = $winner !== null && ! $won;
        $size = $won ? 104 : 80;
        $y = $bottom ? $edge - $size : $edge;

        if ($won) {
            $this->c->rect($x - 7, $y - 7, $size + 14, $size + 14, Canvas::ORANGE);
            $this->c->rect($x - 3, $y - 3, $size + 6, $size + 6, Canvas::GROUND);
        }
        $this->c->avatar($this->drawable($p), $x, $y, $size);

        $tx = $x + $size + 28;
        $max = self::RIGHT - $tx;
        $nameSize = $won ? $this->c->fitSize((string) $p['name'], 'display', [40, 34, 30, 28], $max - 52) : 32;
        $nameFace = $won ? 'display' : 'mono-bold';
        $nameX = $tx;

        if ($won) {
            $this->crown($tx, $y + $size / 2 - 38, 40, 30);
            $nameX += 52;
        }
        $this->c->text($this->c->fit((string) $p['name'], $nameFace, $nameSize, self::RIGHT - $nameX), $nameFace, $nameSize, $nameX, $y + $size / 2 - 6, $lost ? Canvas::INK_2 : Canvas::INK);

        // The side's colour as a square, then the rating and this game's change.
        $line = $y + $size / 2 + 38;
        $this->c->rect($tx, $line - 22, 24, 24, $colour === 'white' ? '#F4F4F5' : '#5C5C60');
        $this->c->rect($tx + 2, $line - 20, 20, 20, $colour === 'white' ? '#F4F4F5' : '#0A0A0B');
        $delta = ($p['delta'] ?? null) !== null && $p['delta'] !== 0 ? ($p['delta'] > 0 ? '+' : '−').abs((int) $p['delta']) : null;
        // A board game has no rating on its card: the side's colour is named instead.
        $rating = ($p['rating'] ?? null) === null ? ($colour === 'white' ? __('White') : __('Black')) : __(':rating Elo', ['rating' => $p['rating']]);
        $provisional = __(':rating Elo, provisional', ['rating' => $p['rating']]);
        $room = $max - 40 - ($delta === null ? 0 : $this->c->width($delta, 'mono-bold', self::MIN) + 16);

        // "provisional" when there is room for it beside the change, else the rating alone.
        if (($p['provisional'] ?? false) && $this->c->width($provisional, 'mono', self::MIN) <= $room) {
            $rating = $provisional;
        }
        $this->c->text($rating, 'mono', self::MIN, $tx + 40, $line, $lost ? Canvas::INK_3 : Canvas::INK_2);

        if ($delta !== null) {
            $this->c->text($delta, 'mono-bold', self::MIN, $tx + 40 + $this->c->width($rating, 'mono', self::MIN) + 16, $line, $p['delta'] > 0 ? self::WIN : self::LOSS);
        }
    }

    private function gameHeadline(): string
    {
        $f = $this->facts;

        return match ($f['status']) {
            'active' => __('Move :move', ['move' => intdiv((int) $f['ply'], 2) + 1]),
            'aborted' => __('Aborted'),
            default => match ($f['result']) {
                '1-0' => __('White wins'),
                '0-1' => __('Black wins'),
                default => __('Draw'),
            },
        };
    }

    /**
     * How it ended, with and without the number of moves; for a live game
     * whose turn it is.
     *
     * @return array{0: string, 1: string}
     */
    private function gameDetail(): array
    {
        $f = $this->facts;
        $moves = (int) ceil($f['ply'] / 2);

        if ($f['status'] === 'active') {
            $side = str_contains((string) $f['fen'], ' b ') ? __('Black to move') : __('White to move');

            return [$side, $side];
        }

        if ($f['status'] === 'aborted') {
            return [__('Before both first moves'), __('Before both first moves')];
        }

        $reason = match ($f['reason']) {
            'checkmate' => __('Checkmate'),
            'resignation' => __('Resignation'),
            'timeout' => __('Out of time'),
            'agreement' => __('Draw by agreement'),
            'stalemate' => __('Stalemate'),
            'threefold_repetition' => __('Threefold repetition'),
            'fifty_move_rule' => __('50-move rule'),
            'insufficient_material' => __('Insufficient material'),
            'abandoned' => __('Opponent left'),
            'director' => __('Set by the director'),
            'forfeit' => __('Forfeit'),
            default => null,
        };
        $count = trans_choice(':count move|:count moves', $moves);

        return $reason === null ? [$count, $count] : [$reason.' '.trans_choice('after :count move|after :count moves', $moves), $reason];
    }

    /** "Rated blitz 5+3"; the game's number is in the preview's title, its tournament on a line of its own. */
    private function gameKind(): string
    {
        $f = $this->facts;
        $kind = match (true) {
            $f['rated'] && $f['daily'] => __('Rated daily chess'),
            $f['rated'] => __('Rated blitz 5+3'),
            $f['daily'] => __('Casual daily chess'),
            default => __('Casual blitz 5+3'),
        };

        return $kind;
    }

    /* ---------- Tournament ------------------------------------------------------------------------------------- */

    private function drawTournament(): void
    {
        $f = $this->facts;
        $podium = $f['status'] === 'finished' ? (array) $f['podium'] : [];
        $statusColour = match ($f['status']) {
            'open' => Canvas::ORANGE,
            'running' => self::LIVE,
            default => Canvas::INK_2,
        };
        $line = $this->tournamentLine();

        // Over: the podium is the picture, the header one line each above it.
        if ($podium !== []) {
            $max = self::RIGHT - self::M;
            $this->c->text($this->firstFitting($this->tournamentStatusForms(), 'mono-bold', self::MIN, $max), 'mono-bold', self::MIN, self::M, 76, $statusColour);
            $size = $this->c->fitSize((string) $f['name'], 'display', [48, 40, 34], $max);
            $this->c->text($this->c->fit((string) $f['name'], 'display', $size, $max), 'display', $size, self::M, 76 + 20 + $size, Canvas::INK);
            $this->c->text($this->c->fit($line, 'mono', self::MIN, $max), 'mono', self::MIN, self::M, 76 + 20 + $size + 44, Canvas::INK_2);

            $this->podium(array_map(fn (array $entry): array => [
                ...$entry,
                'line' => ($entry['paid'] ?? null) !== null ? __(':sats sats', ['sats' => ShareCard::sats((int) $entry['paid'])]) : null,
                'line_colour' => Canvas::ORANGE,
                'line2' => ($entry['paid'] ?? null) !== null ? __('paid') : null,
            ], self::rows($podium)), '', 24);

            return;
        }

        $this->tournamentCover(688, 48, 448, 210);
        $max = 580;
        $this->c->text($this->firstFitting($this->tournamentStatusForms(), 'mono-bold', self::MIN, $max), 'mono-bold', self::MIN, self::M, 84, $statusColour);
        $size = $this->c->fitSize((string) $f['name'], 'display', [52, 44, 38], $max);
        $after = $this->c->paragraph((string) $f['name'], 'display', $size, self::M, 84 + 24 + $size, $max, 2, Canvas::INK, 1.15);
        $after = $this->c->paragraph($line, 'mono', self::MIN, self::M, $after + 4, $max, 1, Canvas::INK_2);

        if ($f['starts_utc'] !== null) {
            // UTC for everyone, the local time of the league (a cup: of its region) beside it.
            $this->c->text($this->c->fit(__('Starts :utc UTC', ['utc' => $f['starts_utc']]), 'mono', self::MIN, $max), 'mono', self::MIN, self::M, $after + 2, Canvas::INK);
            $this->c->text($this->c->fit(__(':local local time', ['local' => $f['starts_local']]), 'mono', self::MIN, $max), 'mono', self::MIN, self::M, $after + 42, Canvas::INK_2);
        }

        if ($f['score_unit'] !== null) {
            $this->leader(self::M, 480, self::RIGHT - self::M);

            return;
        }

        $this->seats(self::M, 424, self::RIGHT - self::M, 56);
    }

    /** A score leaderboard's best so far and who holds it ("Best time 0:25.100 by Ada"), or that there is none yet. */
    private function leaderLine(): string
    {
        $leader = $this->facts['leader'];
        $time = $this->facts['score_unit'] === 'ms';

        if ($leader === null) {
            return $time ? __('No time yet') : __('No score yet');
        }

        return __($time ? 'Best time :value by :name' : 'Best score :value by :name', ['value' => $leader['value'], 'name' => $leader['name']]);
    }

    /** In place of the places on a score leaderboard: the best value large in orange, who holds it beside it. */
    private function leader(int $x, int $baseline, int $width): void
    {
        $leader = $this->facts['leader'];
        $time = $this->facts['score_unit'] === 'ms';

        if ($leader === null) {
            $this->c->text($time ? __('No time yet') : __('No score yet'), 'display', 44, $x, $baseline, Canvas::INK_2);

            return;
        }

        $this->c->text($time ? __('Best time') : __('Best score'), 'mono-bold', self::MIN, $x, $baseline - 62, Canvas::INK_2);
        $this->c->text($leader['value'], 'display', 52, $x, $baseline, Canvas::ORANGE);
        $after = $x + $this->c->width($leader['value'], 'display', 52) + 32;
        $this->c->text($this->c->fit((string) $leader['name'], 'display', 40, $x + $width - $after), 'display', 40, $after, $baseline, Canvas::INK);
    }

    /** "Chess Blitz 5+3, Swiss"; a lobby tournament (P10) "Age of Empires II: Definitive Edition, One lobby match". */
    private function tournamentLine(): string
    {
        $f = $this->facts;

        return $f['lobby']
            ? GameNames::game((string) $f['game']).', '.__('One lobby match')
            : GameNames::full((string) $f['game'], (string) $f['mode']).', '.TournamentFormat::from((string) $f['format'])->label();
    }

    private function tournamentStatus(): string
    {
        return $this->tournamentStatusForms()[0];
    }

    /**
     * The status line, longest form first: a casual cup with its region, the
     * cup without it ("Anmeldung geschlossen, Casual Cup US" is wider than the
     * column beside the cover), the status alone.
     *
     * @return non-empty-list<string>
     */
    private function tournamentStatusForms(): array
    {
        $f = $this->facts;
        $status = match ($f['status']) {
            'open' => __('Sign-up open'),
            'signup', 'drawing' => __('Sign-up closed'),
            'running' => __('Running now'),
            'finished' => __('Finished'),
            default => __('Called off'),
        };

        if (! $f['cup']) {
            return [$status];
        }

        $cup = __(':status, casual cup', ['status' => $status]);

        return $f['region'] !== null ? [__(':status, casual cup :region', ['status' => $status, 'region' => $f['region']]), $cup, $status] : [$cup, $status];
    }

    /**
     * The first of the forms (longest first) that fits on one line; the last one, cut, when none does.
     *
     * @param  non-empty-list<string>  $forms
     */
    private function firstFitting(array $forms, string $face, int $px, int $max): string
    {
        foreach ($forms as $form) {
            if ($this->c->width($form, $face, $px) <= $max) {
                return $form;
            }
        }

        return $this->c->fit((string) end($forms), $face, $px, $max);
    }

    /** The game's cover art, or the game's name on its colours. */
    private function tournamentCover(int $x, int $y, int $w, int $h): void
    {
        $game = (string) $this->facts['game'];
        $path = app(GameRegistry::class)->coverPath($game);

        if ($path !== null && $this->c->cover($path, $x, $y, $w, $h)) {
            return;
        }

        $this->c->rect($x, $y, $w, $h, '#1E1E24');
        $name = GameNames::game($game);
        $size = $this->c->fitSize($name, 'display', [48, 40, 32], $w - 48);
        $this->c->text($name, 'display', $size, $x + 24, $y + $h - 32, Canvas::INK);
    }

    /** The places as a row of cells, taken ones orange, the count above and the pot on the right. */
    private function seats(int $x, int $y, int $width, int $height): void
    {
        $f = $this->facts;
        $taken = (int) $f['taken'];
        $places = max(1, (int) $f['places']);
        $count = __(':taken of :places places taken', ['taken' => $taken, 'places' => $places]);
        $this->c->text($count, 'mono-bold', self::MIN, $x, $y - 22, Canvas::INK);

        if ($f['pot'] !== null) {
            // "X of Y sats": what is still to be won of the pot as the tournament sets it; the longest
            // form that keeps SEATS_GAP to the count on its left.
            $replace = ['left' => ShareCard::sats((int) $f['left']), 'total' => ShareCard::sats((int) $f['pot'])];
            $forms = (int) $f['left'] < (int) $f['pot']
                ? [__(':left of :total sats to win', $replace), __(':left of :total sats', $replace), __(':left sats to win', $replace)]
                : [__(':total sats to win', $replace), __(':total sats', $replace)];
            $room = $width - $this->c->width($count, 'mono-bold', self::MIN) - self::SEATS_GAP;
            $pot = end($forms);

            foreach ($forms as $form) {
                if ($this->c->width($form, 'mono-bold', self::MIN) <= $room) {
                    $pot = $form;

                    break;
                }
            }
            $this->c->textRight($pot, 'mono-bold', self::MIN, $x + $width, $y - 22, Canvas::ORANGE);
        }

        $cells = min($places, 32);
        $gap = $cells > 16 ? 4 : 8;
        $cell = ($width - $gap * ($cells - 1)) / $cells;
        $filled = (int) round($taken / $places * $cells);

        for ($i = 0; $i < $cells; $i++) {
            $cx = $x + $i * ($cell + $gap);

            if ($i < $filled) {
                $this->c->rect($cx, $y, $cell, $height, Canvas::ORANGE);
                $this->c->rect($cx, $y + $height - 6, $cell, 6, '#B9640A');
            } else {
                $this->c->rect($cx, $y, $cell, $height, '#3A3A42');
                $this->c->rect($cx + 2, $y + 2, $cell - 4, $height - 4, Canvas::GROUND);
            }
        }
    }

    /* ---------- Player ----------------------------------------------------------------------------------------- */

    private function drawPlayer(): void
    {
        $f = $this->facts;
        $face = 232;
        $this->c->avatar($this->drawable($f), self::M, 64, $face);

        $x = self::M + $face + 48;
        $max = self::RIGHT - $x;
        $size = $this->c->fitSize((string) $f['name'], 'display', [60, 52, 44, 36], $max);
        $this->c->text($this->c->fit((string) $f['name'], 'display', $size, $max), 'display', $size, $x, 64 + $size, Canvas::INK);
        $baseline = 64 + $size + 48;

        if ($f['clan'] !== null) {
            $this->c->text($this->c->fit(__('Clan :clan', ['clan' => $f['clan']]), 'mono', self::MIN, $max), 'mono', self::MIN, $x, $baseline, Canvas::INK_2);
        }

        // The best rank: the tier cube and its name, the rating, ladder and place.
        $best = $f['best'];
        $cubeY = 212;

        if (is_array($best)) {
            $tier = $best['tier'];
            $colour = $tier === null ? Canvas::INK_2 : RankTiers::colour((string) $tier);
            $this->c->rankCube($colour, $x, $cubeY, 76, $tier === null);
            $label = $tier === null ? __('Casual :rating', ['rating' => $best['rating']]) : RankTiers::label((string) $tier);
            $this->c->text($this->c->fit($label, 'display', 40, $max - 100), 'display', 40, $x + 100, $cubeY + 40, $tier === null ? Canvas::INK : $colour);
            $this->c->text($this->c->fit($this->bestLine(), 'mono', self::MIN, $max - 100), 'mono', self::MIN, $x + 100, $cubeY + 80, Canvas::INK_2);
        } else {
            $this->c->rankCube(Canvas::INK_2, $x, $cubeY, 76, true);
            $this->c->text(__('No results yet'), 'display', 32, $x + 100, $cubeY + 50, Canvas::INK_2);
        }

        // The record, then the latest win.
        $record = (array) $f['record'];
        $this->figures([[__('Wins'), (string) $record['wins']], [__('Draws'), (string) $record['draws']], [__('Losses'), (string) $record['losses']]], self::M, 400, 204);

        $win = $f['latest_win'];

        if (is_array($win)) {
            $text = __('Latest win :score against :opponent, :game', ['score' => $win['score'], 'opponent' => $win['opponent'], 'game' => $win['game']]);
            $this->c->paragraph($text, 'mono', self::MIN, 700, 372, self::RIGHT - 700, 3, Canvas::INK_2, 1.3);
        }
    }

    /** "1534 in Chess Blitz 5+3, place 3". */
    private function bestLine(): string
    {
        $best = (array) $this->facts['best'];
        $ladder = GameNames::full((string) $best['game'], (string) $best['mode']);
        $line = $best['tier'] === null ? $ladder : __(':rating in :ladder', ['rating' => $best['rating'], 'ladder' => $ladder]);

        return $best['place'] !== null ? __(':line, place :place', ['line' => $line, 'place' => $best['place']]) : $line;
    }

    /* ---------- Clan ------------------------------------------------------------------------------------------- */

    private function drawClan(): void
    {
        $f = $this->facts;
        $this->clanTile((string) $f['tag'], $f['logo'], self::M, 64, 232);

        $x = self::M + 232 + 48;
        $max = self::RIGHT - $x;
        $size = $this->c->fitSize((string) $f['name'], 'display', [60, 52, 44, 36], $max);
        $after = $this->c->paragraph((string) $f['name'], 'display', $size, $x, 64 + $size, $max, 2, Canvas::INK, 1.15);
        if ($f['logo'] !== null) {
            $this->c->text($this->c->fit('['.$f['tag'].']', 'mono-bold', 32, $max), 'mono-bold', 32, $x, $after + 8, Canvas::ORANGE);
        }

        $this->figures([[__('Players'), (string) $f['members']], [__('Lineups'), (string) $f['lineups']]], $x, 290, 220);

        foreach (array_slice((array) $f['faces'], 0, 6) as $index => $face) {
            $this->c->avatar($this->drawable($face), $x + $index * 104, 380, 88);
        }
    }

    /* ---------- Series match ----------------------------------------------------------------------------------- */

    private function drawSeries(): void
    {
        $f = $this->facts;
        $tile = 176;
        $winner = $f['winner'];
        $center = self::WIDTH / 2;

        foreach (['challenger' => 264 - $tile / 2, 'challenged' => 936 - $tile / 2] as $side => $x) {
            $s = $f[$side];
            $won = $winner === $side;

            if ($won) {
                $this->c->rect($x - 7, 64 - 7, $tile + 14, $tile + 14, Canvas::ORANGE);
                $this->c->rect($x - 3, 64 - 3, $tile + 6, $tile + 6, Canvas::GROUND);
            }
            $this->clanTile((string) $s['tag'], $s['logo'], $x, 64, $tile);
            $after = $this->textCenterLines((string) $s['name'], 'display', [30, 28], $x + $tile / 2, 64 + $tile + 56, $winner !== null && ! $won ? Canvas::INK_2 : Canvas::INK, 400, 2);

            if ($won) {
                $this->crown((int) ($x + $tile / 2 - 20), (int) $after - 14, 40, 30);
            }
        }

        $block = 156;
        $score = $f['score'];
        $label = is_array($score) ? $score[0].' : '.$score[1] : 'vs';
        $this->c->block($center - $block / 2, 72, $block, is_array($score) ? Canvas::ORANGE : '#3A3A42');
        $size = $this->c->fitSize($label, 'display', [52, 44, 36], $block - 24);
        $this->c->text($label, 'display', $size, $center - $this->c->width($label, 'display', $size) / 2, 72 + $block / 2 + $size * 0.36, is_array($score) ? self::DARK : Canvas::INK);

        $line = GameNames::full((string) $f['game'], (string) $f['mode']).', '.__('best of :n', ['n' => $f['best_of']]).', '.($f['rated'] ? __('rated') : __('casual'));
        $this->textCenter($line, 'mono', self::MIN, $center, 436, Canvas::INK_2, 1000);
        $this->textCenter($this->seriesStatus(), 'mono-bold', self::MIN, $center, 480, $f['status'] === 'accepted' ? Canvas::ORANGE : Canvas::INK, 1000);
    }

    private function seriesStatus(): string
    {
        $f = $this->facts;

        if ($f['winner'] !== null) {
            return __(':name won the series', ['name' => $f[$f['winner']]['name']]);
        }

        return match ($f['status']) {
            'accepted' => $f['at'] !== null ? __('Scheduled for :at UTC', ['at' => $f['at']]) : __('Accepted, not played yet'),
            'reported', 'disputed' => __('Result waiting for confirmation'),
            'open' => __('Open challenge'),
            default => __('No series played'),
        };
    }

    /* ---------- Ladders ---------------------------------------------------------------------------------------- */

    private function drawLadder(): void
    {
        $f = $this->facts;
        $cover = app(GameRegistry::class)->coverPath((string) $f['game']);

        if ($cover !== null) {
            $this->c->cover($cover, 856, 48, 280, 132);
        }

        $title = $this->ladderTitle();
        $size = $this->c->fitSize($title, 'display', [48, 40, 34], 740);
        $this->c->text($this->c->fit($title, 'display', $size, 740), 'display', $size, self::M, 48 + $size, Canvas::INK);
        $entries = trans_choice(':count entry|:count entries', (int) $f['entries']);
        $sub = $f['pool'] === 'rated' ? __('Rated ladder, :entries', ['entries' => $entries]) : __('Casual ladder, :entries', ['entries' => $entries]);
        $this->c->text($sub, 'mono', self::MIN, self::M, 48 + $size + 48, Canvas::INK_2);

        $this->podium($this->rated(self::rows($f['top'])), __('No results yet. The first result opens the ladder.'));
    }

    private function ladderTitle(): string
    {
        return __(':game ladder', ['game' => GameNames::full((string) $this->facts['game'], (string) $this->facts['mode'])]);
    }

    private function drawStrongest(): void
    {
        $f = $this->facts;
        $this->c->text(__('Strongest players'), 'display', 48, self::M, 96, Canvas::INK);
        $this->c->text(__('Global Rating across every game, :count ranked', ['count' => $f['ranked']]), 'mono', self::MIN, self::M, 144, Canvas::INK_2);
        $this->podium($this->rated(self::rows($f['top'])), __('Nobody is ranked yet this season.'));
    }

    /**
     * Places 1 to 3 as a podium: the winner in the middle, raised and larger
     * in the orange frame, each face with its place in an orange block, the
     * names on one line and each entry's own line below (a rating, a prize).
     *
     * @param  list<array<array-key, mixed>>  $top
     */
    private function podium(array $top, string $empty, int $shift = 0): void
    {
        if ($top === []) {
            $this->c->paragraph($empty, 'display', 36, self::M, 320, 900, 2, Canvas::INK_2, 1.25);

            return;
        }

        $slots = [2 => 250, 1 => 600, 3 => 950];
        $used = [];

        foreach ($top as $entry) {
            $place = (int) $entry['place'];
            // A shared place takes the next free slot.
            $free = array_values(array_diff(array_keys($slots), array_keys($used)));
            $slot = isset($slots[$place]) && ! isset($used[$place]) ? $place : ($free[0] ?? null);

            if ($slot === null) {
                continue;
            }

            $used[$slot] = true;
            $cx = $slots[$slot];
            $first = $place === 1;
            $size = $first ? 136 : 112;
            $y = ($first ? 196 : 228) + $shift;
            $x = $cx - $size / 2;

            if ($first) {
                $this->c->rect($x - 7, $y - 7, $size + 14, $size + 14, Canvas::ORANGE);
                $this->c->rect($x - 3, $y - 3, $size + 6, $size + 6, Canvas::GROUND);
            }

            if (($entry['pubkey'] ?? null) === null) {
                $this->clanTile((string) ($entry['tag'] ?? '?'), $entry['logo'] ?? null, (int) $x, $y, $size);
            } else {
                $this->c->avatar($this->drawable($entry), $x, $y, $size);
            }

            // The place in an orange block at the face's lower right corner.
            $b = 48;
            $bx = $x + $size - $b / 2;
            $by = $y + $size - $b / 2 - 4;
            $this->c->block($bx, $by, $b);
            $digit = (string) $place;
            $this->c->text($digit, 'display', 30, $bx + ($b - $this->c->width($digit, 'display', 30)) / 2, $by + $b / 2 + 11, self::DARK);

            // Names and lines share one baseline across the podium.
            $baseline = 406 + $shift;
            $nameSize = $this->c->fitSize((string) $entry['name'], 'mono-bold', [30, 28], 340);
            $this->textCenter((string) $entry['name'], 'mono-bold', $nameSize, $cx, $baseline, Canvas::INK, 340);

            if (($entry['line'] ?? null) !== null) {
                $this->textCenter((string) $entry['line'], 'mono', self::MIN, $cx, $baseline + 40, (string) ($entry['line_colour'] ?? Canvas::INK_2), 330);
            }

            if (($entry['line2'] ?? null) !== null) {
                $this->textCenter((string) $entry['line2'], 'mono', self::MIN, $cx, $baseline + 76, Canvas::INK_2, 330);
            }
        }
    }

    /**
     * A ladder's entries with their rating (and tier on a rated ladder) as the line under the name.
     *
     * @param  list<array<array-key, mixed>>  $top
     * @return list<array<array-key, mixed>>
     */
    private function rated(array $top): array
    {
        return array_map(fn (array $entry): array => [
            ...$entry,
            // A rated entry: its tier in the tier's colour, the rating below; a casual one: the rating alone.
            'line' => ($entry['tier'] ?? null) !== null ? RankTiers::label((string) $entry['tier']) : (string) $entry['rating'],
            'line_colour' => ($entry['tier'] ?? null) !== null ? RankTiers::colour((string) $entry['tier']) : Canvas::INK_2,
            'line2' => ($entry['tier'] ?? null) !== null ? (string) $entry['rating'] : null,
        ], $top);
    }

    /* ---------- Score leaderboards and Blockfill --------------------------------------------------------------- */

    /** A leaderboard: its state, name and game above, the first three with their values as the podium. */
    private function drawLeaderboard(): void
    {
        $f = $this->facts;
        $colour = match ($f['status']) {
            'open' => Canvas::ORANGE,
            'running' => self::LIVE,
            default => Canvas::INK_2,
        };
        // A Blockfill week by its own blocks ("Blockfill 60 blocks"), every other board by its game and mode.
        $mode = is_int($f['goal'] ?? null) ? GameNames::game((string) $f['game']).' '.trans_choice(':count block|:count blocks', $f['goal']) : GameNames::full((string) $f['game'], (string) $f['mode']);
        $line = $mode.', '.trans_choice(':count player placed|:count players placed', (int) $f['placed']);

        $this->scoreBoard([$this->leaderboardStatus()], $colour, $this->leaderboardTitle($f), $line, $f, __('No values yet. The first valid value takes first place.'));
    }

    /** Blockfill's page: this week's first three with their times. */
    private function drawBlockfill(): void
    {
        $f = $this->facts;
        $board = is_array($f['board']) ? $f['board'] : ['game' => Blockfill::SLUG, 'unit' => 'ms', 'top' => [], 'placed' => 0];
        $week = __('Blockfill Week :week, :year', ['week' => $f['week'][0], 'year' => $f['week'][1]]);
        // With the count when it fits beside the cover, else the week alone.
        $state = (int) $board['placed'] > 0 ? [$week.', '.trans_choice(':count player placed|:count players placed', (int) $board['placed']), $week] : [$week];

        $blocks = ['count' => (int) ($f['goal'] ?? 40)];
        $this->scoreBoard($state, Canvas::ORANGE, 'Blockfill', __('Mine :count blocks as fast as you can.', $blocks), $board, __('No times yet this week. Mine :count blocks and take first place.', $blocks));
    }

    /** Blockfill's replays: the last ended week's first three, whose replays everybody may watch. */
    private function drawBlockfillReplays(): void
    {
        $f = $this->facts;
        $board = is_array($f['board']) ? $f['board'] : ['game' => Blockfill::SLUG, 'unit' => 'ms', 'top' => [], 'placed' => 0];
        $state = is_array($f['week']) ? [__('Top replays of :week', ['week' => __('Week :week, :year', ['week' => $f['week'][0], 'year' => $f['week'][1]])])] : [__('Replays')];

        $this->scoreBoard($state, Canvas::ORANGE, __('Blockfill replays'), __('Every run played again, block by block.'), $board,
            __('No week has ended yet. Its first ten show up here.'));
    }

    /**
     * The header of a leaderboard (state, title, line; the game's cover on
     * the right) and its first three as a podium, each with its value.
     *
     * @param  non-empty-list<string>  $states  the state line, longest form first: the first that fits is drawn
     * @param  array<string, mixed>  $board  `game`, `unit` and `top` as PageCardFacts::leaderboard() reads them
     */
    private function scoreBoard(array $states, string $stateColour, string $title, string $line, array $board, string $empty): void
    {
        $cover = app(GameRegistry::class)->coverPath((string) $board['game']);
        $max = $cover !== null && $this->c->cover($cover, 856, 48, 280, 132) ? 760 : self::RIGHT - self::M;
        $this->c->text($this->firstFitting($states, 'mono-bold', self::MIN, $max), 'mono-bold', self::MIN, self::M, 76, $stateColour);
        $size = $this->c->fitSize($title, 'display', [48, 40, 34], $max);
        $this->c->text($this->c->fit($title, 'display', $size, $max), 'display', $size, self::M, 76 + 20 + $size, Canvas::INK);
        $this->c->text($this->c->fit($line, 'mono', self::MIN, $max), 'mono', self::MIN, self::M, 76 + 20 + $size + 44, Canvas::INK_2);

        $metric = $board['unit'] === 'points' ? ScoreMetric::points() : ScoreMetric::time();
        $this->podium(array_map(fn (array $entry): array => [
            ...$entry,
            'line' => $metric->format((int) $entry['value']),
            'line_colour' => (int) $entry['place'] === 1 ? Canvas::ORANGE : Canvas::INK,
        ], self::rows($board['top'])), $empty, 24);
    }

    /**
     * "Blockfill Week 40, 2026" or "TMNF Week 40, 2026" in the card's language, else the leaderboard's name.
     *
     * @param  array<string, mixed>  $f
     */
    private function leaderboardTitle(array $f): string
    {
        if (! is_array($f['week'])) {
            return (string) $f['name'];
        }

        return ($f['game'] ?? null) === TrackmaniaNationsForever::SLUG
            ? __('TMNF Week :week, :year', ['week' => $f['week'][0], 'year' => $f['week'][1]])
            : __('Blockfill Week :week, :year', ['week' => $f['week'][0], 'year' => $f['week'][1]]);
    }

    private function leaderboardStatus(): string
    {
        $f = $this->facts;

        return match ($f['status']) {
            'open' => __('Sign-up open'),
            'signup', 'drawing' => __('Sign-up closed'),
            'running' => __('Running until :utc UTC', ['utc' => $f['ends_utc']]),
            'finished' => __('Finished'),
            default => __('Called off'),
        };
    }

    /** A score game's page: its name, what it is, and its leaderboards open, running and finished. */
    private function drawScoreGame(): void
    {
        $f = $this->facts;
        $this->gameCover((string) $f['game']);
        $title = __(':game leaderboards', ['game' => GameNames::game((string) $f['game'])]);
        $size = $this->c->fitSize($title, 'display', [56, 48, 40], 500);
        $after = $this->c->paragraph($title, 'display', $size, self::M, 48 + $size, 500, 3, Canvas::INK, 1.12);
        $this->c->paragraph(__('Everyone plays alone for the best value, and a points ladder.'), 'mono', self::MIN, self::M, $after + 16, 500, 3, Canvas::INK_2, 1.3);
        $this->figures([
            [$this->figureLabel('tournaments-open', (int) $f['open']), (string) $f['open']],
            [$this->figureLabel('tournaments-running', (int) $f['running']), (string) $f['running']],
            [$this->figureLabel('tournaments-finished', (int) $f['finished']), (string) $f['finished']],
        ], self::M, 440, 352);
    }

    /* ---------- Board games ------------------------------------------------------------------------------------ */

    /** A board game: the position on the left, the players beside it (black above, white below) and the state between. */
    private function drawBoard(): void
    {
        $f = $this->facts;
        $size = 446;
        $this->boardPosition((string) $f['game'], (string) $f['position'], self::M, 48, $size);

        $x = self::M + $size + 56;
        $width = self::RIGHT - $x;
        $winner = match ($f['result']) {
            '1-0' => 'white',
            '0-1' => 'black',
            default => null,
        };
        $side = fn (string $colour): array => [...$f[$colour], 'name' => $this->sideName($colour), 'rating' => null];
        $this->gamePlayer($side('black'), 'black', $x, 48, $width, $winner, false);
        $this->gamePlayer($side('white'), 'white', $x, 48 + $size, $width, $winner, true);

        $block = 96;
        $y = 176;
        [$face, $label, $ink] = match ($f['status']) {
            'active' => [self::LIVE, __('Live'), self::DARK],
            'aborted' => ['#3A3A42', '–', Canvas::INK],
            default => [Canvas::ORANGE, match ($f['result']) {
                '1-0' => '1–0',
                '0-1' => '0–1',
                default => '½–½',
            }, self::DARK],
        };
        $this->c->block($x, $y + 8, $block, $face);
        $labelSize = $this->c->fitSize($label, 'display', [40, 34, 30], $block - 16);
        $this->c->text($label, 'display', $labelSize, $x + ($block - $this->c->width($label, 'display', $labelSize)) / 2, $y + 8 + $block / 2 + $labelSize * 0.36, $ink);

        $tx = $x + $block + 28;
        $headline = $this->gameHeadline();
        $headSize = $this->c->fitSize($headline, 'display', [40, 34, 30, 28], self::RIGHT - $tx);
        $this->c->text($this->c->fit($headline, 'display', $headSize, self::RIGHT - $tx), 'display', $headSize, $tx, $y + 50, $f['status'] === 'active' ? self::LIVE : Canvas::INK);
        $this->c->text($this->c->fit(GameNames::game((string) $f['game']), 'mono', self::MIN, self::RIGHT - $tx), 'mono', self::MIN, $tx, $y + 92, Canvas::INK_3);

        // The full width of the column: rated or casual, the mode and the state when that fits, else the state alone.
        $detail = $this->boardDetail();
        $long = implode(' · ', [$f['rated'] ? __('Rated') : __('Casual'), GameNames::mode((string) $f['game'], (string) $f['mode']), $detail]);
        $detail = $this->c->width($long, 'mono', self::MIN) <= $width ? $long : $detail;
        // A rules' reason can take two lines ("25 moves without a capture or a man moving"); the tournament then stays off.
        $after = $this->c->paragraph($detail, 'mono', self::MIN, $x, $y + 146, $width, 2, Canvas::INK_2, 1.25);

        if ($f['tournament'] !== null && $after <= $y + 146 + self::MIN * 1.25) {
            $this->c->text($this->c->fit((string) $f['tournament'], 'mono', self::MIN, $width), 'mono', self::MIN, $x, $y + 184, Canvas::ORANGE);
        }
    }

    /** A side's name; a deleted account has none of its own. */
    private function sideName(string $colour): string
    {
        return (string) ($this->facts[$colour]['name'] ?? __('Deleted player'));
    }

    /** Whose turn it is, or how the game ended (the rules' own reasons translated as the board page does). */
    private function boardDetail(): string
    {
        $f = $this->facts;

        if ($f['status'] === 'active') {
            return $f['turn'] === 'b' ? __('Black to move') : __('White to move');
        }

        $definition = app(GameRegistry::class)->find((string) $f['game']);
        $reasons = $definition instanceof BoardGameDefinition ? $definition->rules()->reasons() : [];
        // A rules' reason is a sentence of lang/de.json; a key that names a group would give an array, and no reason.
        $label = is_string($f['reason']) && isset($reasons[$f['reason']]) ? __($reasons[$f['reason']]) : null;
        $reason = match ($f['reason']) {
            null => null,
            'resignation' => __('Resignation'),
            'timeout' => __('Out of time'),
            'agreement' => __('Draw by agreement'),
            'aborted' => __('Aborted'),
            'forfeit' => __('No first move'),
            'voided' => __('Voided by the league'),
            default => is_string($label) ? $label : null,
        };
        $count = trans_choice(':count move|:count moves', (int) ceil($f['ply'] / 2));

        return is_string($reason) ? $reason : $count;
    }

    /**
     * A board game's position as its rules show it (BoardRules::view()): the
     * cells, the lines, the points without a piece and the pieces, scaled
     * into a square with the chess board's frame. A king carries an orange
     * ring.
     */
    private function boardPosition(string $game, string $position, int $x, int $y, int $size): void
    {
        $definition = app(GameRegistry::class)->find($game);
        $this->c->rect($x, $y, $size, $size, '#3A3A42');
        $border = 6;
        $inner = $size - 2 * $border;

        if (! $definition instanceof BoardGameDefinition) {
            return;
        }

        $rules = $definition->rules();
        $view = $rules->view($rules->deserialize($position));
        $scale = $inner / max(1, $view['width'], $view['height']);
        $ox = $x + $border;
        $oy = $y + $border;
        $s = $this->c->scale;
        $image = $this->c->image;
        // A board of cells (checkers) is light with dark cells to play on; a board of lines (morris) is wood-dark.
        $this->c->rect($ox, $oy, $inner, $inner, $view['cells'] !== [] ? '#CFCFD4' : '#2A2A30');

        foreach ($view['cells'] as $cell) {
            $this->c->rect($ox + $cell['x'] * $scale, $oy + $cell['y'] * $scale, $cell['size'] * $scale + 0.5, $cell['size'] * $scale + 0.5, '#62626C');
        }

        imagesetthickness($image, (int) max(2, round(4 * $s)));

        foreach ($view['lines'] as [$x1, $y1, $x2, $y2]) {
            imageline($image, (int) round(($ox + $x1 * $scale) * $s), (int) round(($oy + $y1 * $scale) * $s), (int) round(($ox + $x2 * $scale) * $s), (int) round(($oy + $y2 * $scale) * $s), $this->c->color(Canvas::INK_2));
        }

        imagesetthickness($image, 1);

        // The distance between neighbouring points sets the size of a piece: a cell, or the grid of the lines.
        $unit = $view['cells'] !== [] ? (float) $view['cells'][0]['size'] : (float) $view['width'];

        foreach ($view['points'] as $a) {
            foreach ($view['points'] as $b) {
                $d = abs($a['x'] - $b['x']);

                if ($d > 0 && $d < $unit) {
                    $unit = $d;
                }
            }
        }

        $radius = $unit * $scale * 0.38;

        foreach ($view['points'] as $point) {
            $cx = (int) round(($ox + $point['x'] * $scale) * $s);
            $cy = (int) round(($oy + $point['y'] * $scale) * $s);
            $piece = $view['pieces'][$point['id']] ?? null;

            if ($piece === null) {
                if ($view['cells'] === []) {
                    imagefilledellipse($image, $cx, $cy, (int) round($radius * 0.5 * $s), (int) round($radius * 0.5 * $s), $this->c->color(Canvas::INK_2));
                }

                continue;
            }

            $white = $piece['side'] === 'w';
            $d = (int) round(2 * $radius * $s);
            imagefilledellipse($image, $cx, $cy, $d + 2 * $s, $d + 2 * $s, $this->c->color($white ? '#0A0A0B' : '#F4F4F5'));
            imagefilledellipse($image, $cx, $cy, $d, $d, $this->c->color($white ? '#F4F4F5' : '#0A0A0B'));

            if ($piece['kind'] === 'king') {
                imagefilledellipse($image, $cx, $cy, (int) round($d * 0.56), (int) round($d * 0.56), $this->c->color(Canvas::ORANGE));
                imagefilledellipse($image, $cx, $cy, (int) round($d * 0.36), (int) round($d * 0.36), $this->c->color($white ? '#F4F4F5' : '#0A0A0B'));
            }
        }
    }

    /** A board game's lobby or its correspondence page: the game's cover, what the page is for, and its games. */
    private function drawBoardLobby(): void
    {
        $f = $this->facts;
        $game = GameNames::game((string) $f['game']);
        $this->gameCover((string) $f['game']);
        $title = $f['daily'] ? __(':game correspondence', ['game' => $game]) : $game;
        $size = $this->c->fitSize($title, 'display', [56, 48, 40], 500);
        $after = $this->c->paragraph($title, 'display', $size, self::M, 48 + $size, 500, 2, Canvas::INK, 1.12);
        $line = $f['daily'] ? __('One move a day, a reminder before your deadline. The server checks every move.') : __('Blitz 5+3 live against Bitcoiners. The server checks every move.');
        $this->c->paragraph($line, 'mono', self::MIN, self::M, $after + 16, 500, 3, Canvas::INK_2, 1.3);
        $running = (int) $f['running'];
        $this->figures([
            [$this->figureLabel($f['daily'] ? 'daily-games' : 'live-games', $running), (string) $running],
            [$this->figureLabel('games-played', (int) $f['played']), (string) $f['played']],
        ], self::M, 440, 352);
    }

    /** A game's cover art in the upper right, or an empty panel when it has none. */
    private function gameCover(string $game): void
    {
        $cover = app(GameRegistry::class)->coverPath($game);

        if ($cover === null || ! $this->c->cover($cover, 600, 48, 536, 252)) {
            $this->c->rect(600, 48, 536, 252, '#1E1E24');
        }
    }

    /* ---------- Season chain ----------------------------------------------------------------------------------- */

    private function drawMining(): void
    {
        $f = $this->facts;

        if ($f['season'] === null) {
            $this->c->text(__('Season'), 'display', 56, self::M, 120, Canvas::INK);
            $this->c->paragraph(__('The chain starts at Block 0. Every fair rated win after that mines a block.'), 'mono', 32, self::M, 200, 900, 3, Canvas::INK_2, 1.35);
            $this->chainStrip(0, 21, 400);

            return;
        }

        $season = BadgeCopy::season((string) $f['season']);
        $this->c->text($season, 'display', 52, self::M, 108, Canvas::INK);
        $state = $f['live'] ? __('Live, ends :date', ['date' => $f['ends']]) : __('Ended :date', ['date' => $f['ends']]);
        $this->c->text($state, 'mono', self::MIN, self::M, 160, $f['live'] ? Canvas::ORANGE : Canvas::INK_2);

        // The tip of the chain in the orange block.
        $block = 150;
        $this->c->block(self::RIGHT - $block - 12, 60, $block);
        $height = (string) $f['height'];
        $this->c->text(__('Block'), 'mono', self::MIN, self::RIGHT - $block, 104, self::DARK);
        $hs = $this->c->fitSize($height, 'display', [56, 48, 40, 32], $block - 28);
        $this->c->text($height, 'display', $hs, self::RIGHT - $block, 176, self::DARK);

        $supply = max(1, (int) $f['supply']);
        $mined = (int) $f['mined'];
        $this->c->text(__(':mined of :supply sats mined', ['mined' => ShareCard::sats($mined), 'supply' => ShareCard::sats($supply)]), 'mono-bold', 32, self::M, 300, Canvas::INK);
        $this->chainStrip($mined, $supply, 336);
    }

    /**
     * The supply as 21 blocks, as many orange as the share mined (the last
     * one partly), the rest empty.
     */
    private function chainStrip(int $mined, int $supply, int $y): void
    {
        $count = 21;
        $gap = 8;
        $cell = (self::RIGHT - self::M - $gap * ($count - 1)) / $count;
        $share = $supply > 0 ? min(1, $mined / $supply) * $count : 0;

        for ($i = 0; $i < $count; $i++) {
            $x = self::M + $i * ($cell + $gap);
            $this->c->rect($x, $y, $cell, 96, '#3A3A42');
            $this->c->rect($x + 2, $y + 2, $cell - 4, 92, Canvas::GROUND);
            $fill = max(0, min(1, $share - $i));

            if ($fill > 0) {
                $this->c->rect($x, $y + 96 * (1 - $fill), $cell, 96 * $fill, Canvas::ORANGE);
            }
        }
    }

    /* ---------- Live ------------------------------------------------------------------------------------------- */

    private function drawLive(): void
    {
        $f = $this->facts;
        $slide = PageCardFacts::streamSlide();
        $shown = $f['slide'] !== null && $this->c->cover($slide, 600, 48, 536, 302);

        if (! $shown) {
            $this->c->picture(public_path('icon-512.png'), 748, 79, 240, 44);
        }

        $onAir = (bool) $f['on_air'];

        if ($onAir) {
            imagefilledellipse($this->c->image, (int) (self::M + 14) * 2, 92 * 2, 28 * 2, 28 * 2, $this->c->color(self::LIVE));
        }
        $this->c->text($onAir ? __('Live now') : __('Stream off air'), 'display', 52, self::M + ($onAir ? 44 : 0), 112, $onAir ? self::LIVE : Canvas::INK_2);
        $this->c->text(trans_choice(':count chess game live|:count chess games live', (int) $f['games']), 'mono-bold', 32, self::M, 184, Canvas::INK);

        $first = $f['first'];

        if (is_array($first)) {
            $this->c->avatar($this->drawable($first['white']), self::M, 224, 72);
            $this->c->avatar($this->drawable($first['black']), self::M + 88, 224, 72);
            $this->c->paragraph($first['white']['name'].' vs '.$first['black']['name'], 'mono', self::MIN, self::M + 184, 254, 330, 2, Canvas::INK_2, 1.3);
        }

        $tournaments = (array) $f['tournaments'];

        if ($tournaments !== []) {
            $this->c->text($this->c->fit(__('Tournament running: :names', ['names' => $tournaments[0]]), 'mono', self::MIN, self::RIGHT - self::M), 'mono', self::MIN, self::M, 420, Canvas::ORANGE);
        } else {
            $this->c->text($this->c->fit(__('Chess, tournaments and music, around the clock'), 'mono', self::MIN, self::RIGHT - self::M), 'mono', self::MIN, self::M, 420, Canvas::INK_2);
        }
    }

    /* ---------- Fixed pages ------------------------------------------------------------------------------------ */

    private function drawPage(): void
    {
        $f = $this->facts;
        $title = $this->pageTitle();
        $max = 740;
        // As large as fits in two lines; a long title (German compounds) takes a third line at the smallest size.
        [$size, $lines] = [40, 3];

        foreach ([60, 52, 46, 40] as $candidate) {
            $count = $this->c->lineCount($title, 'display', $candidate, $max);

            if ($count !== null && $count <= 2) {
                [$size, $lines] = [$candidate, 2];

                break;
            }
        }
        $after = $this->c->paragraph($title, 'display', $size, self::M, 64 + $size, $max, $lines, Canvas::INK, 1.12);
        $after = $this->c->paragraph($this->pageLine(), 'mono', 30, self::M, $after + 16, $max, 3, Canvas::INK_2, 1.35);

        if (is_array($f['next'])) {
            $this->c->paragraph(__('Next: :name, :utc UTC', ['name' => $f['next']['name'], 'utc' => $f['next']['starts_utc']]), 'mono-bold', self::MIN, self::M, $after + 8, 800, 2, Canvas::ORANGE, 1.3);
        }

        $this->pageMotif(896, 64, 240);

        $figures = [];

        foreach ((array) $f['figures'] as [$label, $value]) {
            $figures[] = [$this->figureLabel((string) $label, (int) $value), ShareCard::sats((int) $value)];
        }

        if ($figures !== []) {
            $this->figures($figures, self::M, 440, 352);
        }
    }

    /**
     * The picture of a fixed page in its 240 px square: the newest live
     * board on the chess pages, the biggest clans on the clan list, the
     * next tournament's game on the tournament list, else the 21 mark.
     */
    private function pageMotif(int $x, int $y, int $size): void
    {
        $f = $this->facts;

        if (is_array($f['board'] ?? null)) {
            $this->board((string) $f['board']['fen'], $f['board']['last'], $x, $y, $size);

            return;
        }

        $clans = (array) ($f['clans'] ?? []);

        if ($clans !== []) {
            $tile = (int) floor(($size - 16) / 2);

            foreach (array_slice($clans, 0, 4) as $index => $clan) {
                $this->clanTile((string) $clan['tag'], $clan['logo'], $x + ($index % 2) * ($tile + 16), $y + intdiv($index, 2) * ($tile + 16), $tile);
            }

            return;
        }

        $cover = is_array($f['next'] ?? null) && $this->key === 'tournaments' ? app(GameRegistry::class)->coverPath((string) $f['next']['game']) : null;

        if ($cover !== null && $this->c->cover($cover, $x, $y, $size, $size)) {
            return;
        }

        $this->c->picture(public_path('icon-512.png'), $x, $y, $size, (int) round($size * 0.18));
    }

    private function drawHub(): void
    {
        $f = $this->facts;
        $game = GameNames::game((string) $f['game']);
        $cover = app(GameRegistry::class)->coverPath((string) $f['game']);

        if ($cover === null || ! $this->c->cover($cover, 600, 48, 536, 252)) {
            $this->c->rect(600, 48, 536, 252, '#1E1E24');
        }

        $size = $this->c->fitSize($game, 'display', [56, 48, 40], 500);
        $after = $this->c->paragraph($game, 'display', $size, self::M, 48 + $size, 500, 2, Canvas::INK, 1.12);
        $modes = implode(', ', array_map(fn (string $mode): string => GameNames::mode((string) $f['game'], $mode), (array) $f['modes']));
        $this->c->paragraph(__('Clan series in :modes', ['modes' => $modes]), 'mono', self::MIN, self::M, $after + 16, 500, 2, Canvas::INK_2, 1.3);
        $this->figures([[trans_choice('lineup|lineups', (int) $f['lineups']), (string) $f['lineups']], [trans_choice('series played|series played', (int) $f['series']), (string) $f['series']]], self::M, 440, 300);
    }

    private function pageTitle(): string
    {
        return match (true) {
            $this->key === 'home', $this->key === 'brand' => __('Chess and Rocket League ladder for Bitcoiners'),
            $this->key === 'login' => __('Log in'),
            $this->key === 'clans' => __('Clans'),
            $this->key === 'matches' => __('Matches'),
            $this->key === 'games' => __('Live games'),
            $this->key === 'chess' => __('Chess'),
            $this->key === 'tournaments' => __('Tournaments'),
            $this->key === 'play' => __('All games and modes'),
            $this->key === 'rules' => __('Rules'),
            $this->key === 'protocol' => __('Open protocol'),
            $this->key === 'mining' => __('Season'),
            $this->key === 'live' => __('Live stream'),
            $this->key === 'strongest' => __('Strongest players'),
            str_starts_with($this->key, 'hub.') => GameNames::game(substr($this->key, 4)),
            $this->key === 'blockfill' => 'Blockfill',
            $this->key === 'blockfill-replays' => __('Blockfill replays'),
            str_starts_with($this->key, 'board.') => GameNames::game(substr($this->key, 6)),
            str_starts_with($this->key, 'board-daily.') => __(':game correspondence', ['game' => GameNames::game(substr($this->key, 12))]),
            str_starts_with($this->key, 'scores.') => __(':game leaderboards', ['game' => GameNames::game(substr($this->key, 7))]),
            default => 'TWENTY ONE esports',
        };
    }

    /** One line under the title, what the page is for. */
    private function pageLine(): string
    {
        // A name without its subtitle ("Age of Empires II", not "…: Definitive Edition"): the line lists every game and must fit the card.
        $games = implode(', ', array_map(fn (string $game): string => Str::before(GameNames::game($game), ':'), (array) ($this->facts['games'] ?? [])));
        $named = __(':games, every mode in one place.', ['games' => $games]);

        return match ($this->key) {
            'clans' => __('Every clan of the league with its players, lineups and Clan Rating.'),
            'matches' => __('Every series and chess game by match number: live, scheduled and done.'),
            'games' => __('Every chess game running now. Watch without logging in.'),
            'chess' => __('Blitz 5+3 live or daily chess against Bitcoiners.'),
            'tournaments' => __('Open sign-ups, running brackets and results, drawn from a Bitcoin block.'),
            // With every game switched on the names outgrow the three lines of drawPage(): then the line names none.
            'play' => ($this->c->lineCount($named, 'mono', 30, 740) ?? 4) <= 3 ? $named : __('Every game of the league, every mode in one place.'),
            'rules' => __('Casual and rated, games and modes, tournaments, prize pots and fair play.'),
            'protocol' => __('What the league publishes on Nostr, and how to check every result yourself.'),
            'login' => __('With Google or Nostr, then play against Bitcoiners in every game of the league.'),
            default => __('The esports league of the Bitcoin community EINUNDZWANZIG.'),
        };
    }

    private function figureLabel(string $label, int $value): string
    {
        return match ($label) {
            'players' => trans_choice('player|players', $value),
            'live-games' => trans_choice('game live now|games live now', $value),
            'daily-games' => trans_choice('daily game running|daily games running', $value),
            'games-played' => trans_choice('game played|games played', $value),
            'series-played' => trans_choice('series played|series played', $value),
            'tournaments-open' => trans_choice('open sign-up|open sign-ups', $value),
            'tournaments-running' => trans_choice('running now|running now', $value),
            'tournaments-finished' => trans_choice('finished|finished', $value),
            'clans' => trans_choice('clan|clans', $value),
            'clan-players' => trans_choice('player in a clan|players in clans', $value),
            'games' => trans_choice('game|games', $value),
            'modes' => trans_choice('mode|modes', $value),
            'relays' => trans_choice('relay|relays', $value),
            default => $label,
        };
    }

    /* ---------- Pieces ----------------------------------------------------------------------------------------- */

    /**
     * Value over label, side by side: the value large in Unbounded, the label
     * in the mono face below it.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    private function figures(array $pairs, int $x, int $baseline, int $column): void
    {
        foreach (array_slice($pairs, 0, 3) as $index => [$label, $value]) {
            $cx = $x + $index * $column;
            $this->c->text($value, 'display', 48, $cx, $baseline, Canvas::INK);
            $this->c->text($this->c->fit($label, 'mono', self::MIN, $column - 12), 'mono', self::MIN, $cx, $baseline + 44, Canvas::INK_2);
        }
    }

    /**
     * The mark, the name and the host, on every card: 28 px, readable in a
     * small preview.
     */
    private function footer(): void
    {
        $y = 536;
        $tile = 52;
        $this->c->picture(public_path('icon-512.png'), self::M, $y, $tile, 10);
        $baseline = $y + 37;
        $this->c->text('TWENTY ONE', 'display', self::MIN, self::M + $tile + 20, $baseline, Canvas::INK);
        $this->c->text('esports', 'mono', self::MIN, self::M + $tile + 20 + $this->c->width('TWENTY ONE', 'display', self::MIN) + 14, $baseline, Canvas::INK_2);
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $this->c->textRight($host, 'mono', self::MIN, self::RIGHT, $baseline, Canvas::INK_2);
    }

    /** A crown in orange, the winner's mark. */
    private function crown(int|float $x, int|float $y, int $w, int $h): void
    {
        $this->c->polygon([
            $x, $y + $h,
            $x, $y + $h * 0.25,
            $x + $w * 0.28, $y + $h * 0.6,
            $x + $w * 0.5, $y,
            $x + $w * 0.72, $y + $h * 0.6,
            $x + $w, $y + $h * 0.25,
            $x + $w, $y + $h,
        ], Canvas::ORANGE);
    }

    /**
     * A position from its FEN, white at the bottom, in the board kit's
     * "house" colours; the squares of the last move tinted orange.
     */
    private function board(string $fen, mixed $last, int $x, int $y, int $size): void
    {
        $rows = explode('/', explode(' ', $fen)[0]);
        $marked = [];

        if (is_string($last) && preg_match('/^([a-h])([1-8])([a-h])([1-8])/', $last, $m) === 1) {
            $marked[(8 - (int) $m[2]).(ord($m[1]) - 97)] = true;
            $marked[(8 - (int) $m[4]).(ord($m[3]) - 97)] = true;
        }

        $this->c->rect($x, $y, $size, $size, '#3A3A42');
        $border = 6;
        $cell = ($size - 2 * $border) / 8;
        $s = $this->c->scale;
        $font = resource_path('fonts/og/DejaVuSans-Chess.ttf');
        $pt = $cell * $s * 0.9 * 0.75;

        for ($r = 0; $r < 8; $r++) {
            $file = 0;

            foreach (str_split($rows[$r] ?? '8') as $char) {
                if (ctype_digit($char)) {
                    for ($k = 0; $k < (int) $char && $file < 8; $k++, $file++) {
                        $this->square($x + $border + $file * $cell, $y + $border + $r * $cell, $cell, ($r + $file) % 2 === 0, isset($marked[$r.$file]));
                    }

                    continue;
                }

                if ($file > 7) {
                    break;
                }

                $cx = $x + $border + $file * $cell;
                $cy = $y + $border + $r * $cell;
                $this->square($cx, $cy, $cell, ($r + $file) % 2 === 0, isset($marked[$r.$file]));
                $index = strpos('kqrbnp', strtolower($char));

                if ($index !== false) {
                    $white = $char !== strtolower($char);
                    $solid = mb_chr(0x265A + $index);
                    $box = imagettfbbox($pt, 0, $font, $solid) ?: [0, 0, 0, 0, 0, 0, 0, 0];
                    $gx = (int) round($cx * $s + ($cell * $s - ($box[2] - $box[0])) / 2 - $box[0]);
                    $gy = (int) round($cy * $s + $cell * $s * 0.86);
                    imagettftext($this->c->image, $pt, 0, $gx, $gy, $this->c->color($white ? '#FFFFFF' : '#0A0A0B'), $font, $solid);

                    if ($white) {
                        imagettftext($this->c->image, $pt, 0, $gx, $gy, $this->c->color('#0A0A0B'), $font, mb_chr(0x2654 + $index));
                    }
                }
                $file++;
            }
        }
    }

    private function square(float $x, float $y, float $cell, bool $light, bool $marked): void
    {
        $colour = $light ? '#CFCFD4' : '#62626C';
        $this->c->rect($x, $y, $cell + 0.5, $cell + 0.5, $marked ? $this->c->mix($colour, Canvas::ORANGE, 0.55) : $colour);
    }

    /** A clan's logo when it uploaded one, else its tag on the orange tile. */
    private function clanTile(string $tag, mixed $logo, int $x, int $y, int $size): void
    {
        if (is_string($logo) && Storage::disk('public')->exists($logo)) {
            $this->c->picture(Storage::disk('public')->path($logo), $x, $y, $size, (int) round($size * 0.14));

            return;
        }

        $this->c->rect($x, $y, $size, $size, Canvas::ORANGE);
        $this->c->rect($x, $y + $size - 10, $size, 10, '#B9640A');
        $px = $this->c->fitSize($tag, 'display', [(int) round($size * 0.3), (int) round($size * 0.24), (int) round($size * 0.18)], $size - 24);
        $this->c->text($tag, 'display', max(self::MIN, $px), $x + ($size - $this->c->width($tag, 'display', max(self::MIN, $px))) / 2, $y + $size / 2 + $px * 0.36, self::DARK);
    }

    /**
     * Centred text as large as one of $sizes fits in $maxLines lines; returns the baseline after the last line.
     *
     * @param  list<int>  $sizes  largest first
     */
    private function textCenterLines(string $text, string $face, array $sizes, int|float $center, int|float $baseline, string $colour, int $max, int $maxLines): float
    {
        $size = (int) end($sizes);

        foreach ($sizes as $candidate) {
            $count = $this->c->lineCount($text, $face, $candidate, $max);

            if ($count !== null && $count <= $maxLines) {
                $size = $candidate;

                break;
            }
        }

        foreach ($this->c->lines($text, $face, $size, $max, $maxLines) as $line) {
            $this->textCenter($line, $face, $size, $center, $baseline, $colour, $max);
            $baseline += $size * 1.2;
        }

        return $baseline;
    }

    private function textCenter(string $text, string $face, int $px, int|float $center, int|float $baseline, string $colour, int $max): void
    {
        $text = $this->c->fit($text, $face, $px, $max);
        $this->c->text($text, $face, $px, $center - $this->c->width($text, $face, $px) / 2, $baseline, $colour);
    }

    /**
     * The entries of a list in the facts (a podium, a ladder's top), each an array.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function rows(mixed $rows): array
    {
        return array_values(array_filter(is_array($rows) ? $rows : [], is_array(...)));
    }

    /**
     * A user for drawing the avatar: the uploaded picture or the Blockpile of the key.
     *
     * @param  array<string, mixed>  $person
     */
    private function drawable(array $person): User
    {
        return (new User)->forceFill([
            'pubkey' => NostrKeys::isHexPubkey($person['pubkey'] ?? null) ? $person['pubkey'] : str_repeat('0', 64),
            'avatar_path' => $person['avatar_path'] ?? null,
        ]);
    }
}
