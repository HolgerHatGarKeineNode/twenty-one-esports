<?php

namespace App\Support\Matches;

use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonBlockVoid;
use App\Support\Badges\BadgeCopy;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\Seasons;

/**
 * Where finished rated matches stand in the season chain, for the mempool
 * strip: the block a match mined ("Block 812", linked to /mining), a block
 * the season review voided, or a win that mined none and why. Read from the
 * league's attestations (`2154`, SeasonAttestation) only; a match without an
 * attestation, or with one that is no candidate (a draw, a void result), has
 * no stamp. Before Block 0 there are no attestations, so there is no stamp.
 * A fixed number of queries for any number of matches: the attestations with
 * their seasons, the voids of the mined ones, and the season /mining shows.
 *
 * Heights count per season, and /mining shows one season (the live one, else
 * the latest ended one): a block links to its row there only when it belongs
 * to that season. A block of another season names its season and links
 * nowhere, so "Block 3" of the Pre-Season never lands on Block 3 of the next.
 * The shape: `text` is the stamp, `note` a second line (void, or the season
 * of an older block), `reason` why a win mined none, `spoken` all of it for
 * a screen reader.
 */
final class ChainStamps
{
    /**
     * @param  array<string, list<int>>  $ids  source (SeasonAttestation::SERIES, CHESS, BOARD) => ids
     * @return array<string, array{state: 'mined'|'void'|'none', height: int|null, href: string|null, text: string, note: string|null, reason: string|null, title: string, spoken: string}> keyed "source:id"
     */
    public static function for(array $ids): array
    {
        $ids = array_filter($ids, fn (array $list): bool => $list !== []);

        if ($ids === []) {
            return [];
        }

        $query = SeasonAttestation::query()->with('season:id,slug')->select(['id', 'season_id', 'source', 'source_id', 'height', 'reason']);
        $query->where(function ($query) use ($ids): void {
            foreach ($ids as $source => $list) {
                $query->orWhere(fn ($query) => $query->where('source', $source)->whereIn('source_id', $list));
            }
        });

        // A match attested on several boards shows its first mined block, else its first row.
        $rows = $query->orderByRaw('height is null')->orderBy('height')->orderBy('id')->get()
            ->unique(fn (SeasonAttestation $row): string => $row->source.':'.$row->source_id);

        if ($rows->isEmpty()) {
            return [];
        }

        $mined = $rows->filter(fn (SeasonAttestation $row): bool => $row->height !== null);
        $voids = $mined->isEmpty() ? collect() : SeasonBlockVoid::query()->whereIn('season_attestation_id', $mined->pluck('id'))->get(['season_attestation_id', 'reason'])->keyBy('season_attestation_id');
        $shown = $mined->isEmpty() ? null : self::shownSeason();

        $stamps = [];

        foreach ($rows as $row) {
            $stamp = self::stamp($row, $voids->get($row->id), $shown);

            if ($stamp !== null) {
                $stamps[$row->source.':'.$row->source_id] = $stamp;
            }
        }

        return $stamps;
    }

    /** The season /mining shows (pages/⚡mining: the live one, else the latest ended one). */
    public static function shownSeason(): ?Season
    {
        return Seasons::live() ?? Seasons::latest();
    }

    /**
     * @return array{state: 'mined'|'void'|'none', height: int|null, href: string|null, text: string, note: string|null, reason: string|null, title: string, spoken: string}|null
     */
    private static function stamp(SeasonAttestation $row, ?SeasonBlockVoid $void, ?Season $shown): ?array
    {
        if ($row->height !== null) {
            $here = $shown !== null && $row->season_id === $shown->id;
            $season = BadgeCopy::season($row->season->slug);
            $text = self::line('Block :height', ['height' => $row->height]);
            $note = match (true) {
                $void !== null => self::line('void'),
                $here => null,
                default => $season,
            };
            $title = match (true) {
                $void !== null => $void->reason,
                $here => self::line('Mined as block :height of the season chain', ['height' => $row->height]),
                default => self::line('Block :height of :season', ['height' => $row->height, 'season' => $season]),
            };

            return ['state' => $void === null ? 'mined' : 'void', 'height' => $row->height, 'href' => $here ? route('mining').'#block-'.$row->height : null,
                'text' => $text, 'note' => $note, 'reason' => null, 'title' => $title, 'spoken' => $text.($note === null ? '' : ' '.$note)];
        }

        // Attested without a candidate (a draw, a void result): nothing to mine, nothing to say.
        if ($row->reason === null) {
            return null;
        }

        $reason = ChainOverview::reasonLabel($row->reason);

        return ['state' => 'none', 'height' => null, 'href' => null, 'text' => self::line('no block'), 'note' => null, 'reason' => $reason,
            'title' => self::line('No block: :reason', ['reason' => $reason]), 'spoken' => self::line('No block: :reason', ['reason' => $reason])];
    }

    /**
     * A translated line as a string: `__()` may hand back an array for a key
     * that names a group, which a stamp can never show.
     *
     * @param  array<string, string|int>  $replace
     */
    private static function line(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
