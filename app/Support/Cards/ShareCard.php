<?php

namespace App\Support\Cards;

use App\Models\RankBadgeVersion;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Nostr\NostrKeys;
use App\Support\Rating\RankTiers;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The share cards of P11 (ShareCards.dc.html): "Rank up", "Block mined",
 * "Tournament win" and "Season Wrapped", rendered on the server with GD like
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

    public const TYPES = ['rank-up', 'block', 'tournament', 'wrapped'];

    /** Bump when a layout changes: every card gets a new file and URL. */
    private const LAYOUT = 1;

    private Canvas $c;

    /**
     * @param  'rank-up'|'block'|'tournament'|'wrapped'  $type
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

    public static function wrapped(Season $season, User $user): self
    {
        return new self('wrapped', $season->slug.'-'.$user->npub, ShareMoments::wrapped($season, $user));
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
        return substr(hash('sha256', (string) json_encode([self::LAYOUT, $this->type, $this->key, $format, App::getLocale(), $this->facts, config('app.url')])), 0, 16);
    }

    /** The public URL of the card in the current locale, with its fingerprint. */
    public function url(string $format): string
    {
        $path = match ($this->type) {
            'rank-up' => 'rank-up/'.$this->key,
            'block' => 'block/'.str_replace('-npub', '/npub', $this->key),
            'tournament' => 'tournament/'.$this->key,
            'wrapped' => 'wrapped/'.preg_replace('/-(npub1[0-9a-z]+)$/', '/$1', $this->key),
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
            'wrapped' => $story ? $this->wrappedStory() : $this->wrappedWide(),
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
        $this->c->text($this->c->fit($f['label'].' · '.self::inline($f['ladder']), 'mono', (int) round(20 * $k), $size - (int) round(52 * $k)), 'mono', (int) round(20 * $k), $inner, $y + (int) round(320 * $k), '#3A2A12');
    }

    private function beat(): string
    {
        $f = $this->facts;
        $opponents = (array) $f['opponents'];

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

    /** 195000 → "195 000", with a no-break space. */
    public static function sats(int $amount): string
    {
        return number_format($amount, 0, '.', "\u{00A0}");
    }
}
