<?php

namespace App\Support\Cards;

use App\Enums\TournamentFormat;
use App\Games\GameRegistry;
use App\Games\ScoreMetric;
use App\Games\TrackmaniaNationsForever;
use App\Models\RankBadgeVersion;
use App\Models\ScoreRun;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\GameNames;
use App\Support\Nostr\NostrKeys;
use App\Support\Rating\RankTiers;
use App\Support\Stacker\BlockfillMoments;
use App\Support\Stacker\BlockfillWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The share cards of P11 (ShareCards.dc.html): "Rank up", "Block mined",
 * "Tournament win" and "Season Wrapped", and a Blockfill moment (a personal
 * best, a new first place of the week, a week place), rendered on the server with GD like
 * the invite card, as a link preview (`wide`, 1200 × 630) and a story
 * (`story`, 1080 × 1920). Text stays inside a 48 px safe margin.
 *
 * A card is drawn from facts read once ({@see ShareMoments}) and cached per
 * card, format, locale and facts (the fingerprint, also the `v` of its URL):
 * an unchanged card is never redrawn, a new name or picture is a new file.
 * A rank-up card is drawn from one signed version of the badge, a block card
 * from one attestation: what was posted keeps showing what happened.
 */
final class ShareCard
{
    public const FORMATS = ['wide' => [1200, 630], 'story' => [1080, 1920]];

    public const TYPES = ['rank-up', 'block', 'tournament', 'wrapped', 'tournament-invite', 'blockfill', 'tmnf'];

    /** Blockfill's fee colours (resources/js/stacker/palette.js FEE_SCALE), low to high. */
    private const FEES = ['#7383A6', '#3B82E0', '#0FA394', '#5AAE3C', '#F2D45C', '#F7931A', '#F9A8D4'];

    /** Bump when a layout changes: every card gets a new file and URL. */
    private const LAYOUT = 2;

    private Canvas $c;

    /**
     * @param  'rank-up'|'block'|'tournament'|'wrapped'|'tournament-invite'|'blockfill'|'tmnf'  $type
     * @param  string  $key  the card's own part of its file name and URL
     * @param  array<string, mixed>  $facts
     */
    private function __construct(public readonly string $type, public readonly string $key, public readonly array $facts) {}

    public static function rankUp(RankBadgeVersion $version): self
    {
        return new self('rank-up', (string) $version->id, ShareMoments::rankUp($version));
    }

    public static function block(SeasonAttestation $block, User $miner): self
    {
        return new self('block', $block->id.'-'.$miner->npub, ShareMoments::block($block, $miner));
    }

    public static function tournament(Tournament $tournament, TournamentParticipant $winner): self
    {
        return new self('tournament', (string) $tournament->id, ShareMoments::tournament($tournament, $winner));
    }

    /**
     * A published tournament's own card, its link preview and invite image:
     * cover, name, start and the places taken. Not a moment of a player, so
     * it is never a share post (SharePosts only takes a player's moments).
     */
    public static function tournamentInvite(Tournament $tournament): self
    {
        return new self('tournament-invite', (string) $tournament->id, ShareMoments::tournamentInvite($tournament));
    }

    public static function wrapped(Season $season, User $user): self
    {
        return new self('wrapped', $season->slug.'-'.$user->npub, ShareMoments::wrapped($season, $user));
    }

    /**
     * A Blockfill moment of a verified run (App\Support\Stacker\BlockfillMoments::of()).
     *
     * @param  array{kind: string, place: int|null, final: bool, pb: bool, first: bool, week: string}  $moment
     */
    public static function blockfill(StackerRun $run, array $moment): self
    {
        return new self('blockfill', (string) $run->id, ShareMoments::blockfill($run, $moment));
    }

    /**
     * A TMNF moment of a counted finish (App\Support\Tmnf\TmnfMoments::of()).
     *
     * @param  array{kind: string, place: int|null, final: bool, pb: bool, first: bool, week: string, track: string}  $moment
     */
    public static function tmnf(ScoreRun $run, array $moment): self
    {
        return new self('tmnf', (string) $run->id, ShareMoments::tmnf($run, $moment));
    }

    /** The PNG bytes in the current locale, from the cache when unchanged. */
    public function png(string $format): string
    {
        if (! isset(self::FORMATS[$format])) {
            throw new InvalidArgumentException("Unknown share card format {$format}.");
        }

        // One directory per card (gate F4): a miss lists only this card's files. The file name is the
        // fingerprint of what the card shows; nothing from the request (a `?v`) reaches it.
        $directory = 'share-cards/'.$this->type.'/'.$this->key;
        $prefix = $format.'-'.App::getLocale().'-';
        $path = $directory.'/'.$prefix.$this->fingerprint($format).'.png';
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $png = $this->render($format);

        foreach ($disk->files($directory) as $old) {
            if (str_starts_with(basename($old), $prefix)) {
                $disk->delete($old);
            }
        }

        $disk->put($path, $png);

        return $png;
    }

    /**
     * Everything the card shows; a change is a new file and a new `v`.
     */
    public function fingerprint(string $format): string
    {
        // The cover a card draws is part of what it shows: new art is a new file and a new `v`.
        $game = $this->type === 'tmnf' ? TrackmaniaNationsForever::SLUG : ($this->facts['game'] ?? null);
        $cover = is_string($game) ? app(GameRegistry::class)->coverVersion($game) : null;

        return substr(hash('sha256', (string) json_encode([self::LAYOUT, $this->type, $this->key, $format, App::getLocale(), $this->facts, config('app.url'), ...($cover === null ? [] : [$cover])])), 0, 16);
    }

    /** The public URL of the card in the current locale, with its fingerprint. */
    public function url(string $format): string
    {
        $path = match ($this->type) {
            'rank-up' => 'rank-up/'.$this->key,
            'block' => 'block/'.str_replace('-npub', '/npub', $this->key),
            'tournament' => 'tournament/'.$this->key,
            'tournament-invite' => 'tournament-invite/'.$this->key,
            'wrapped' => 'wrapped/'.preg_replace('/-(npub1[0-9a-z]+)$/', '/$1', $this->key),
            'blockfill' => 'blockfill/'.$this->key,
            'tmnf' => 'tmnf/'.$this->key,
        };

        return rtrim((string) config('app.url'), '/').'/cards/'.App::getLocale().'/'.$path.'-'.$format.'.png?v='.$this->fingerprint($format);
    }

    /** The card's path on this site, for the page's own previews and downloads. */
    public function path(string $format): string
    {
        return substr($this->url($format), strlen(rtrim((string) config('app.url'), '/')));
    }

    public function render(string $format): string
    {
        [$width, $height] = self::FORMATS[$format] ?? throw new InvalidArgumentException("Unknown share card format {$format}.");
        $this->c = new Canvas($width, $height);
        $story = $format === 'story';

        match ($this->type) {
            'rank-up' => $story ? $this->rankUpStory() : $this->rankUpWide(),
            'block' => $story ? $this->blockStory() : $this->blockWide(),
            'tournament' => $story ? $this->tournamentStory() : $this->tournamentWide(),
            'tournament-invite' => $story ? $this->inviteStory() : $this->inviteWide(),
            'wrapped' => $story ? $this->wrappedStory() : $this->wrappedWide(),
            'blockfill' => $story ? $this->blockfillStory() : $this->blockfillWide(),
            'tmnf' => $story ? $this->tmnfStory() : $this->tmnfWide(),
        };

        if ($story) {
            $this->c->footer(72, 1780, 64, 1008);
        } else {
            $this->c->footer(64, 540, 44, 1136);
        }

        return $this->c->png();
    }

    /* ---------- Rank up ------------------------------------------------------------------------------------------ */

    private function rankUpWide(): void
    {
        $f = $this->facts;
        $colour = RankTiers::colour((string) $f['tier']);
        $this->glow($colour, 216, 250, 230);
        $this->c->rankCube($colour, 86, 120, 260);

        $x = 430;
        $this->person($x, 96, 44, 32, null, __('ranked up'));
        $label = RankTiers::label((string) $f['tier']);
        $size = $this->c->fitSize($label, 'display', [104, 88, 76, 64], 700);
        $this->c->text($label, 'display', $size, $x, 270, $colour);
        $this->previousAndRating($x, 336, 30);
        $this->c->paragraph($this->rankUpNote(), 'mono', 24, $x, 400, 700, 3, Canvas::INK_2);
    }

    private function rankUpStory(): void
    {
        $f = $this->facts;
        $colour = RankTiers::colour((string) $f['tier']);
        $this->kicker(__('Rank up'), 72, 150);
        $this->glow($colour, 540, 640, 420);
        $this->c->rankCube($colour, 290, 390, 500);

        $this->person(72, 1030, 64, 40, null, __('ranked up'));
        $label = RankTiers::label((string) $f['tier']);
        $size = $this->c->fitSize($label, 'display', [150, 128, 108, 92], 936);
        $this->c->text($label, 'display', $size, 72, 1240, $colour);
        $this->previousAndRating(72, 1330, 40);
        $this->c->paragraph($this->rankUpNote(), 'mono', 32, 72, 1420, 936, 3, Canvas::INK_2);
    }

    /** "Provisional → 1034 in chess blitz", the old tier struck through. */
    private function previousAndRating(int $x, int $baseline, int $px): void
    {
        $f = $this->facts;
        $previous = $f['previous'] === null ? __('Provisional') : RankTiers::label((string) $f['previous']);
        $this->c->text($previous, 'mono', $px, $x, $baseline, Canvas::INK_3);
        $w = $this->c->width($previous, 'mono', $px);
        $this->c->rect($x, $baseline - $px * 0.33, $w, max(2, $px / 14), Canvas::INK_3);
        $this->c->text(__(':rating in :ladder', ['rating' => (int) $f['rating'], 'ladder' => self::inline($f['ladder'])]), 'mono-bold', $px, $x + $w + $px * 0.6, $baseline, Canvas::INK);
    }

    private function rankUpNote(): string
    {
        return __(':season of the TWENTY ONE Esports league. The badge is on Nostr, signed by the league.', ['season' => BadgeCopy::season((string) $this->facts['season'])]);
    }

    /* ---------- Block mined -------------------------------------------------------------------------------------- */

    private function blockWide(): void
    {
        $this->blockCube(72, 96, 330, 1.0);

        $x = 470;
        $this->c->text(__('Block mined'), 'mono-bold', 28, $x, 130, Canvas::ORANGE);
        $this->person($x, 156, 40, 28, __('Miner'));
        $this->c->paragraph($this->beat(), 'mono', 26, $x, 260, 660, 2, Canvas::INK);
        $this->stats($x, 360, [[__('Block Height'), (string) $this->facts['personal_height']], [__('Era'), (string) $this->facts['era']]], 30, 180);
        $this->pill($x, 440, $this->blockStatus(), 22);
    }

    private function blockStory(): void
    {
        $this->kicker(__('Block mined'), 72, 150);
        $this->blockCube(240, 280, 560, 1.7);
        $this->person(72, 1000, 64, 40, __('Miner'));
        $this->c->paragraph($this->beat(), 'mono', 38, 72, 1150, 936, 3, Canvas::INK);
        $this->stats(72, 1370, [[__('Block Height'), (string) $this->facts['personal_height']], [__('Era'), (string) $this->facts['era']]], 44, 320);
        $this->pill(72, 1520, $this->blockStatus(), 32);
    }

    /** The orange block with its height, reward and result label. */
    private function blockCube(int $x, int $y, int $size, float $k): void
    {
        $f = $this->facts;
        $this->c->block($x, $y + (int) round($size * 0.08), $size);
        $inner = $x + (int) round(26 * $k);
        $this->c->text(__('Block'), 'mono', (int) round(28 * $k), $inner, $y + (int) round(76 * $k), '#17120A');
        $height = (string) $f['height'];
        $size2 = $this->c->fitSize($height, 'display', array_map(fn (int $s): int => (int) round($s * $k), [150, 120, 96, 80]), $size - (int) round(52 * $k));
        $this->c->text($height, 'display', $size2, $inner, $y + (int) round(214 * $k), '#17120A');
        $this->c->text('+'.self::sats((int) $f['reward']).' sats', 'mono-bold', (int) round(30 * $k), $inner, $y + (int) round(288 * $k), '#17120A');
        // A score window's block (plan "Blockfill", P7): the winning value first (a long window name is cut, not the
        // value), then the window; no ladder.
        $detail = isset($f['window']) ? implode(' · ', array_filter([(string) ($f['value'] ?? ''), (string) $f['window']])) : $f['label'].' · '.self::inline($f['ladder']);
        $this->c->text($this->c->fit($detail, 'mono', (int) round(20 * $k), $size - (int) round(52 * $k)), 'mono', (int) round(20 * $k), $inner, $y + (int) round(320 * $k), '#3A2A12');
    }

    /**
     * The card's sentence under the miner: whom the win beat on which ladder,
     * or for a score window's block (plan "Blockfill", P7) the window, the
     * winning value and the next places.
     */
    public function beat(): string
    {
        $f = $this->facts;
        $opponents = (array) $f['opponents'];

        if (isset($f['window'])) {
            $mined = ['height' => $f['height'], 'window' => $f['window'], 'value' => (string) ($f['value'] ?? '–')];

            return $opponents === []
                ? __('Mined block :height in :window with :value', $mined)
                : __('Mined block :height in :window with :value, ahead of :opponents', [...$mined, 'opponents' => self::listing($opponents)]);
        }

        return $opponents === []
            ? __('Won in :ladder', ['ladder' => self::inline($f['ladder'])])
            : __('Beat :opponents in :ladder', ['opponents' => implode(', ', $opponents), 'ladder' => self::inline($f['ladder'])]);
    }

    private function blockStatus(): string
    {
        return $this->facts['pending'] ? __('mined · pending season review') : __('mined');
    }

    /* ---------- Tournament win ----------------------------------------------------------------------------------- */

    private function tournamentWide(): void
    {
        $f = $this->facts;
        $this->c->picture(public_path('icon-512.png'), 90, 130, 240, 44);

        $x = 400;
        $this->c->text($this->c->fit(__(':tournament winners', ['tournament' => $f['tournament']]), 'mono-bold', 28, 740), 'mono-bold', 28, $x, 130, Canvas::ORANGE);
        $size = $this->c->fitSize((string) $f['winner'], 'display', [80, 68, 56, 48], 740);
        $this->c->text($this->c->fit((string) $f['winner'], 'display', $size, 740), 'display', $size, $x, 130 + $size + 24, Canvas::INK);
        $after = $this->c->paragraph((string) $f['detail'], 'mono', 26, $x, 300, 740, 2, Canvas::INK_2);
        $this->members($x, (int) $after + 8, 40, 26, 740, 2);
    }

    private function tournamentStory(): void
    {
        $f = $this->facts;
        $this->kicker(__('Tournament win'), 72, 150);
        $this->c->picture(public_path('icon-512.png'), 330, 310, 420, 76);
        $this->c->paragraph(__(':tournament winners', ['tournament' => $f['tournament']]), 'mono-bold', 40, 72, 890, 936, 2, Canvas::ORANGE);
        $size = $this->c->fitSize((string) $f['winner'], 'display', [112, 96, 80, 64], 936);
        $this->c->text($this->c->fit((string) $f['winner'], 'display', $size, 936), 'display', $size, 72, 1080, Canvas::INK);
        $this->c->paragraph((string) $f['detail'], 'mono', 34, 72, 1170, 936, 2, Canvas::INK_2);
        $this->members(72, 1280, 64, 36, 936, 1);
    }

    /**
     * The winning players with avatar and name, up to four.
     */
    private function members(int $x, int $y, int $avatar, int $px, int $max, int $columns): void
    {
        $members = array_slice((array) $this->facts['members'], 0, 4);

        // A solo winner's name is the headline already.
        if (count($members) === 1 && $members[0]['name'] === $this->facts['winner']) {
            return;
        }
        $column = (int) floor($max / $columns);

        foreach ($members as $index => $member) {
            $cx = $x + ($index % $columns) * $column;
            $cy = $y + intdiv($index, $columns) * ($avatar + 18);
            $this->c->avatar($this->drawable($member), $cx, $cy, $avatar);
            $this->c->text($this->c->fit((string) $member['name'], 'mono-bold', $px, $column - $avatar - 32), 'mono-bold', $px, $cx + $avatar + 14, $cy + $avatar * 0.7, Canvas::INK);
        }
    }

    /* ---------- Tournament invite -------------------------------------------------------------------------------- */

    private function inviteWide(): void
    {
        $f = $this->facts;
        $this->inviteCover(640, 64, 496, 232);
        $this->kicker($this->inviteStatus(), 64, 92, 26);
        // One line as large as it fits, else two lines at the smallest size.
        $size = $this->c->fitSize((string) $f['tournament'], 'display', [56, 48, 40], 536);
        $after = $this->c->paragraph((string) $f['tournament'], 'display', $size, 64, 164, 536, 2, Canvas::INK, 1.12);
        $this->c->paragraph($this->inviteLine(), 'mono', 26, 64, $after + 6, 536, 2, Canvas::INK_2);
        $this->inviteSeats(64, 400, 1072, 48, 30);
    }

    private function inviteStory(): void
    {
        $f = $this->facts;
        $this->inviteCover(72, 160, 936, 438);
        $this->kicker($this->inviteStatus(), 72, 700, 40);
        $after = $this->c->paragraph((string) $f['tournament'], 'display', 88, 72, 810, 936, 3, Canvas::INK, 1.1);
        $this->c->paragraph($this->inviteLine(), 'mono', 36, 72, $after + 16, 936, 3, Canvas::INK_2);
        $this->inviteSeats(72, 1400, 936, 72, 40);
    }

    /** The game's cover, or its name on the game's colours. */
    private function inviteCover(int $x, int $y, int $w, int $h): void
    {
        $path = app(GameRegistry::class)->coverPath((string) $this->facts['game']);

        if ($path !== null && $this->c->cover($path, $x, $y, $w, $h)) {
            return;
        }

        $chess = $this->facts['game'] === 'chess';
        [$from, $to] = $chess ? ['#0E7490', '#134E4A'] : ['#C2410C', '#9D174D'];

        for ($row = 0; $row < $h; $row += 4) {
            $this->c->rect($x, $y + $row, $w, 4, $this->c->mix($from, $to, $row / $h));
        }

        $name = GameNames::game((string) $this->facts['game']);
        $size = $this->c->fitSize($name, 'display', [64, 52, 40], $w - 64);
        $this->c->text($name, 'display', $size, $x + 32, $y + $h - 36, Canvas::INK);
    }

    private function inviteStatus(): string
    {
        return match ($this->facts['status']) {
            'open' => __('Sign-up open'),
            'signup', 'drawing' => __('Sign-up closed'),
            'running' => __('Running'),
            'finished' => __('Finished'),
            default => __('Called off'),
        };
    }

    /** Game and mode, format, start; a lobby tournament (P10) its game and "One lobby match". */
    private function inviteLine(): string
    {
        $f = $this->facts;
        $game = $f['lobby'] ? GameNames::game((string) $f['game']) : GameNames::full((string) $f['game'], (string) $f['mode']);
        $format = $f['lobby'] ? __('One lobby match') : TournamentFormat::from((string) $f['format'])->label();

        return __(':game, :format. Starts :date.', ['game' => $game, 'format' => $format, 'date' => $f['starts']]);
    }

    /** One block per place, taken ones orange, and the count. */
    private function inviteSeats(int $x, int $y, int $width, int $height, int $px): void
    {
        $taken = (int) $this->facts['taken'];
        $places = max(1, (int) $this->facts['places']);
        $this->c->text(__(':taken of :places spots taken', ['taken' => $taken, 'places' => $places]), 'mono-bold', $px, $x, $y - 18, Canvas::INK);

        // The prize pot on the right of the same line (P9), when the tournament has one.
        if (($this->facts['pot'] ?? null) !== null) {
            $pot = ($this->facts['first_prize'] ?? null) !== null
                ? __('1st place wins :sats sats', ['sats' => self::sats((int) $this->facts['first_prize'])])
                : __(':sats sats prize pot', ['sats' => self::sats((int) $this->facts['pot'])]);
            $this->c->text($pot, 'mono-bold', $px, $x + $width - $this->c->width($pot, 'mono-bold', $px), $y - 18, Canvas::ORANGE);
        }
        $cells = min($places, 48);
        $gap = $cells > 24 ? 3 : 6;
        $cell = ($width - $gap * ($cells - 1)) / $cells;
        $filled = (int) round($taken / $places * $cells);

        for ($i = 0; $i < $cells; $i++) {
            $cx = $x + $i * ($cell + $gap);

            if ($i < $filled) {
                $this->c->rect($cx, $y, $cell, $height, Canvas::ORANGE);
                $this->c->rect($cx, $y + $height - 5, $cell, 5, '#B9640A');
            } else {
                $this->c->rect($cx, $y, $cell, $height, '#3A3A42');
                $this->c->rect($cx + 1, $y + 1, $cell - 2, $height - 2, Canvas::GROUND);
            }
        }
    }

    /* ---------- Season Wrapped ----------------------------------------------------------------------------------- */

    private function wrappedWide(): void
    {
        $f = $this->facts;
        $this->kicker($this->wrappedKicker(), 64, 84, 24);
        $title = __(':name, you mined', ['name' => $f['name']]);
        $titleSize = $this->c->fitSize($title, 'display', [40, 34, 29], 560);
        $this->c->text($this->c->fit($title, 'display', $titleSize, 560), 'display', $titleSize, 64, 150, Canvas::INK);
        $blocks = (string) $f['blocks'];
        $this->c->text($blocks, 'display', 150, 60, 318, Canvas::ORANGE);
        $this->c->text(__('Block Height'), 'mono', 28, 72 + $this->c->width($blocks, 'display', 150), 318, Canvas::INK_2);
        $this->c->text(self::sats((int) $f['sats']), 'display', 48, 64, 400, '#F9B25F');
        $this->c->text(__('sats mined'), 'mono', 26, 76 + $this->c->width(self::sats((int) $f['sats']), 'display', 48), 400, Canvas::INK_2);

        $x = 700;
        $this->c->rect($x - 36, 96, 2, 360, '#26262B');
        $this->bestTier($x, 196, 34, 60);
        $this->stats($x, 370, [[__('Rated wins'), (string) $f['wins']], [__('Tournament wins'), (string) $f['tournaments']]], 30, 220);
    }

    private function wrappedStory(): void
    {
        $f = $this->facts;
        $this->kicker($this->wrappedKicker(), 72, 150);
        $this->c->paragraph(__(':name, you mined', ['name' => $f['name']]), 'display', 76, 72, 300, 936, 2, Canvas::INK, 1.1);
        $blocks = (string) $f['blocks'];
        $this->c->text($blocks, 'display', 260, 64, 640, Canvas::ORANGE);
        $this->c->text(__('Block Height'), 'mono', 40, 80 + $this->c->width($blocks, 'display', 260), 640, Canvas::INK_2);
        $this->c->text(self::sats((int) $f['sats']), 'display', 84, 72, 790, '#F9B25F');
        $this->c->text(__('sats mined'), 'mono', 36, 90 + $this->c->width(self::sats((int) $f['sats']), 'display', 84), 790, Canvas::INK_2);

        $this->c->rect(72, 880, 936, 2, '#26262B');
        $this->bestTier(72, 1080, 48, 96);
        $this->stats(72, 1380, [[__('Rated wins'), (string) $f['wins']], [__('Tournament wins'), (string) $f['tournaments']]], 48, 440);
        $this->c->paragraph(__('Every rated win that passed the rules is a block on the season chain.'), 'mono', 32, 72, 1560, 936, 3, Canvas::INK_2);
    }

    private function wrappedKicker(): string
    {
        $season = BadgeCopy::season((string) $this->facts['season']);

        return $this->facts['live'] ? __(':season so far', ['season' => $season]) : __(':season wrapped', ['season' => $season]);
    }

    /** The best rank of the season: cube, name and the ladder, or "no rank yet". */
    private function bestTier(int $x, int $baseline, int $small, int $big): void
    {
        $best = $this->facts['best'];
        $this->c->text(__('Your best rank'), 'mono', $small, $x, $baseline - $big - 20, Canvas::INK_2);

        if (! is_array($best)) {
            $this->c->text(__('Provisional'), 'display', $big, $x, $baseline + 10, Canvas::INK_2);

            return;
        }

        $colour = RankTiers::colour((string) $best['tier']);
        $this->c->rankCube($colour, $x, $baseline - $big * 0.85, $big);
        $this->c->text(RankTiers::label((string) $best['tier']), 'display', $big, $x + $big + 18, $baseline, $colour);
        $this->c->text(__(':rating in :ladder', ['rating' => (int) $best['rating'], 'ladder' => self::inline($best['ladder'])]), 'mono', $small, $x, $baseline + $small + 24, Canvas::INK);
    }

    /* ---------- Blockfill moment -------------------------------------------------------------------------------- */

    private function blockfillWide(): void
    {
        $this->well(64, 76, 30, 10, 14);

        $x = 440;
        $max = 1136 - $x;
        $this->c->text($this->c->fit($this->blockfillHeadline(), 'mono-bold', 28, $max), 'mono-bold', 28, $x, 112, Canvas::ORANGE);
        $this->person($x, 140, 44, 30, null);
        $time = $this->blockfillTime();
        $size = $this->c->fitSize($time, 'display', [120, 104, 88], $max);
        $this->c->text($time, 'display', $size, $x, 330, Canvas::INK);
        $this->c->paragraph($this->blockfillLine(), 'mono', 26, $x, 392, $max, 2, Canvas::INK_2);
        $this->pill($x, 450, $this->blockfillStatus(), 22);
    }

    private function blockfillStory(): void
    {
        $this->kicker('Blockfill', 72, 150);
        $this->well(315, 220, 45, 10, 14);

        $this->c->paragraph($this->blockfillHeadline(), 'mono-bold', 44, 72, 960, 936, 2, Canvas::ORANGE);
        // The name gets the card's full width here (person() keeps 560 px for the wide cards' columns).
        $this->c->avatar($this->drawable($this->facts), 72, 1040, 64);
        $name = (string) $this->facts['name'];
        $nameSize = $this->c->fitSize($name, 'mono-bold', [40, 34, 30], 856);
        $this->c->text($this->c->fit($name, 'mono-bold', $nameSize, 856), 'mono-bold', $nameSize, 152, 1040 + 64 * 0.72, Canvas::INK);
        $time = $this->blockfillTime();
        $size = $this->c->fitSize($time, 'display', [200, 170, 140, 120, 104], 936);
        $this->c->text($time, 'display', $size, 72, 1340, Canvas::INK);
        $this->c->paragraph($this->blockfillLine(), 'mono', 36, 72, 1430, 936, 3, Canvas::INK_2);
        $this->pill(72, 1560, $this->blockfillStatus(), 30);
    }

    private function blockfillHeadline(): string
    {
        return BlockfillMoments::headline((string) $this->facts['kind'], $this->facts['place'] === null ? null : (int) $this->facts['place']);
    }

    private function blockfillTime(): string
    {
        return BlockfillMoments::time((int) $this->facts['ticks']);
    }

    /** "40 blocks mined · Blockfill Week 41, 2026" */
    private function blockfillLine(): string
    {
        return __('40 blocks mined').' · '.BlockfillMoments::weekTitle((string) $this->facts['week']);
    }

    /** The place a personal best or first place holds now; for a place so far, when it is decided. */
    private function blockfillStatus(): string
    {
        $f = $this->facts;

        return match (true) {
            $f['kind'] === 'place' => __('so far: the week ends Monday 00:00 Berlin'),
            $f['place'] !== null && ! $f['final'] => __('Place :place this week so far', ['place' => (int) $f['place']]),
            default => __('verified: the league replayed the run'),
        };
    }

    /* ---------- TMNF moment ------------------------------------------------------------------------------------- */

    private function tmnfWide(): void
    {
        $this->tmnfMark(64, 112, 352, 22);

        $x = 440;
        $max = 1136 - $x;
        $this->c->text($this->c->fit($this->tmnfHeadline(), 'mono-bold', 28, $max), 'mono-bold', 28, $x, 112, Canvas::ORANGE);
        $this->person($x, 140, 44, 30, null);
        $time = ScoreMetric::time()->format((int) $this->facts['ms']);
        $size = $this->c->fitSize($time, 'display', [120, 104, 88], $max);
        $this->c->text($time, 'display', $size, $x, 330, Canvas::INK);
        $this->c->paragraph($this->tmnfLine(), 'mono', 26, $x, 392, $max, 2, Canvas::INK_2);
        $this->pill($x, 450, $this->tmnfStatus(), 22);
    }

    private function tmnfStory(): void
    {
        // The cover carries the game's name in its own logo.
        $this->tmnfMark(72, 150, 936, 36);

        $this->c->paragraph($this->tmnfHeadline(), 'mono-bold', 44, 72, 960, 936, 2, Canvas::ORANGE);
        $this->c->avatar($this->drawable($this->facts), 72, 1040, 64);
        $name = (string) $this->facts['name'];
        $nameSize = $this->c->fitSize($name, 'mono-bold', [40, 34, 30], 856);
        $this->c->text($this->c->fit($name, 'mono-bold', $nameSize, 856), 'mono-bold', $nameSize, 152, 1040 + 64 * 0.72, Canvas::INK);
        $time = ScoreMetric::time()->format((int) $this->facts['ms']);
        $size = $this->c->fitSize($time, 'display', [200, 170, 140, 120, 104], 936);
        $this->c->text($time, 'display', $size, 72, 1340, Canvas::INK);
        $this->c->paragraph($this->tmnfLine(), 'mono', 36, 72, 1430, 936, 3, Canvas::INK_2);
        $this->pill(72, 1560, $this->tmnfStatus(), 30);
    }

    private function tmnfHeadline(): string
    {
        return BlockfillMoments::headline((string) $this->facts['kind'], $this->facts['place'] === null ? null : (int) $this->facts['place']);
    }

    /** "A01-Race · TMNF Week 41, 2026" */
    private function tmnfLine(): string
    {
        $local = CarbonImmutable::parse((string) $this->facts['week'], BlockfillWeeks::TIMEZONE);

        return ((string) $this->facts['track']).' · '.__('TMNF Week :week, :year', ['week' => $local->isoWeek(), 'year' => $local->isoWeekYear()]);
    }

    private function tmnfStatus(): string
    {
        $f = $this->facts;

        return match (true) {
            $f['kind'] === 'place' => __('so far: the week ends Monday 00:00 Berlin'),
            $f['place'] !== null && ! $f['final'] => __('Place :place this week so far', ['place' => (int) $f['place']]),
            default => __('timed by our own TMNF server'),
        };
    }

    /**
     * The TMNF cards' mark: the game's official cover (Steam store art, with
     * its logo) `$w` wide at 16:9, a chequered band of two rows of `$cell` px
     * under it; without a cover file the finish flag alone.
     */
    private function tmnfMark(int $x, int $y, int $w, int $cell): void
    {
        $h = (int) round($w * 9 / 16);
        $path = app(GameRegistry::class)->coverPath(TrackmaniaNationsForever::SLUG);

        if ($path === null || ! $this->c->cover($path, $x, $y, $w, $h)) {
            $this->finishFlag($x + 14, $y, intdiv($w - 14, 8), 8, 8);

            return;
        }

        $columns = intdiv($w, $cell);

        for ($row = 0; $row < 2; $row++) {
            for ($col = 0; $col < $columns; $col++) {
                $this->c->rect($x + $col * $cell, $y + $h + $cell + $row * $cell, $cell, $cell, ($row + $col) % 2 === 0 ? Canvas::ORANGE : '#0E0E11');
            }
        }
    }

    /**
     * A chequered finish flag on its pole, the TMNF cards' fallback mark: `$columns`
     * by `$rows` squares of `$cell` px in the league orange and black.
     */
    private function finishFlag(int $x, int $y, int $cell, int $columns, int $rows): void
    {
        $this->c->rect($x - 14, $y - 8, 10, $cell * ($rows + 1), '#3A3A42');

        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $columns; $col++) {
                $this->c->rect($x + $col * $cell, $y + $row * $cell, $cell, $cell, ($row + $col) % 2 === 0 ? Canvas::ORANGE : '#0E0E11');
            }
        }
    }

    /**
     * Blockfill's well: a stack of pieces in the fee colours, the top rows
     * open, one row lit as a mined block. The pattern follows the card's
     * key, so a moment always shows the same stack.
     */
    private function well(int $x, int $y, int $cell, int $columns, int $rows): void
    {
        $w = $cell * $columns;
        $h = $cell * $rows;
        $this->c->rect($x - 6, $y - 6, $w + 12, $h + 12, '#24242B');
        $this->c->rect($x - 2, $y - 2, $w + 4, $h + 4, '#0E0E11');
        $bytes = hash('sha256', 'blockfill-card-'.$this->key, true);
        $filled = (int) round($rows * 0.6);
        $lit = $rows - 2;

        for ($row = $rows - $filled; $row < $rows; $row++) {
            $gap = ord($bytes[$row % 32]) % $columns;

            for ($col = 0; $col < $columns; $col++) {
                $cx = $x + $col * $cell;
                $cy = $y + $row * $cell;

                if ($row === $lit) {
                    $this->c->rect($cx + 1, $cy + 1, $cell - 2, $cell - 2, '#F9B25F');
                    $this->c->rect($cx + 1, $cy + $cell - 5, $cell - 2, 4, Canvas::ORANGE);

                    continue;
                }

                // The top row of the stack is ragged; every other row leaves one gap.
                if ($col === $gap || ($row === $rows - $filled && ord($bytes[($col + 7) % 32]) % 3 === 0)) {
                    continue;
                }

                $fee = self::FEES[ord($bytes[($row * $columns + $col) % 32]) % count(self::FEES)];
                $this->c->rect($cx + 1, $cy + 1, $cell - 2, $cell - 2, $fee);
                $this->c->rect($cx + 1, $cy + 1, $cell - 2, max(2, intdiv($cell, 8)), $this->c->mix($fee, '#FFFFFF', 0.3));
            }
        }

        // The falling piece: an orange bar above the stack.
        $px = $x + $cell * (ord($bytes[31]) % ($columns - 3));
        $this->c->rect($px + 1, $y + $cell * 2 + 1, $cell * 4 - 2, $cell - 2, Canvas::ORANGE);
    }

    /* ---------- Pieces ------------------------------------------------------------------------------------------- */

    /** Avatar, then an optional label, the player's name and an optional tail in one row. */
    private function person(int $x, int $y, int $avatar, int $px, ?string $before, ?string $after = null): void
    {
        $f = $this->facts;
        $this->c->avatar($this->drawable($f), $x, $y, $avatar);
        $textX = $x + $avatar + 16;
        $baseline = $y + $avatar * 0.72;
        $gap = (int) round($px * 0.5);

        if ($before !== null) {
            $this->c->text($before, 'mono', $px, $textX, $baseline, Canvas::INK_2);
            $textX += $this->c->width($before, 'mono', $px) + $gap;
        }

        $name = $this->c->fit((string) $f['name'], 'mono-bold', $px, 560);
        $this->c->text($name, 'mono-bold', $px, $textX, $baseline, Canvas::INK);

        if ($after !== null) {
            $this->c->text($after, 'mono', $px, $textX + $this->c->width($name, 'mono-bold', $px) + $gap, $baseline, Canvas::INK_2);
        }
    }

    /**
     * Label over value, side by side.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    private function stats(int $x, int $baseline, array $pairs, int $px, int $column): void
    {
        foreach ($pairs as $index => [$label, $value]) {
            $cx = $x + $index * $column;
            $this->c->text($label, 'mono', (int) round($px * 0.7), $cx, $baseline - $px * 1.4, Canvas::INK_2);
            $this->c->text($value, 'display', (int) round($px * 1.3), $cx, $baseline + $px * 0.3, Canvas::INK);
        }
    }

    private function pill(int $x, int $y, string $text, int $px): void
    {
        $w = $this->c->width($text, 'mono', $px) + $px * 1.6;
        $h = $px * 2;
        $this->c->rect($x, $y, $w, $h, '#3A3A40');
        $this->c->rect($x + 2, $y + 2, $w - 4, $h - 4, Canvas::GROUND);
        $this->c->text($text, 'mono', $px, $x + $px * 0.8, $y + $h * 0.68, Canvas::INK_2);
    }

    private function kicker(string $text, int $x, int $baseline, int $px = 36): void
    {
        $this->c->text($text, 'mono', $px, $x, $baseline, Canvas::INK_2);
    }

    /** A soft glow of rings behind the motif. */
    private function glow(string $colour, int $cx, int $cy, int $radius): void
    {
        for ($i = 10; $i >= 1; $i--) {
            $r = $radius * (0.6 + 0.06 * $i);
            $shade = $this->c->mix(Canvas::GROUND, $colour, 0.018 * (11 - $i));
            imagefilledellipse($this->c->image, (int) round($cx * $this->c->scale), (int) round($cy * $this->c->scale), (int) round(2 * $r * $this->c->scale), (int) round(2 * $r * $this->c->scale), $this->c->color($shade));
        }
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

    /** A ladder name inside a sentence: lower case in English, as written in German (nouns keep their capital). */
    private static function inline(mixed $ladder): string
    {
        return App::getLocale() === 'en' ? mb_strtolower((string) $ladder) : (string) $ladder;
    }

    /**
     * Names in a sentence: "A", "A and B", "A, B and C".
     *
     * @param  array<mixed>  $names
     */
    private static function listing(array $names): string
    {
        $names = array_map(strval(...), array_values($names));
        $last = array_pop($names);

        return $names === [] ? (string) $last : __(':first and :last', ['first' => implode(', ', $names), 'last' => $last]);
    }

    /** 195000 → "195 000", with a no-break space. */
    public static function sats(int $amount): string
    {
        return number_format($amount, 0, '.', "\u{00A0}");
    }
}
