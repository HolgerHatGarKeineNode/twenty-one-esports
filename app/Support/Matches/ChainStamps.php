<?php

namespace App\Support\Matches;

use App\Models\SeasonAttestation;
use App\Models\SeasonBlockVoid;
use App\Support\SeasonChain\ChainOverview;

/**
 * Where finished rated matches stand in the season chain, for the mempool
 * strip: the block a match mined ("Block 812", linked to /mining), a block
 * the season review voided, or a win that mined none and why. Read from the
 * league's attestations (`2154`, SeasonAttestation) only; a match without an
 * attestation, or with one that is no candidate (a draw, a void result), has
 * no stamp. Before Block 0 there are no attestations, so there is no stamp.
 * Two queries for any number of matches: the attestations, then the voids of
 * the mined ones (none without a mined block).
 */
final class ChainStamps
{
    /**
     * @param  array<string, list<int>>  $ids  source (SeasonAttestation::SERIES, CHESS, BOARD) => ids
     * @return array<string, array{state: 'mined'|'void'|'none', height: int|null, href: string|null, text: string, title: string}> keyed "source:id"
     */
    public static function for(array $ids): array
    {
        $ids = array_filter($ids, fn (array $list): bool => $list !== []);

        if ($ids === []) {
            return [];
        }

        $query = SeasonAttestation::query()->select(['id', 'source', 'source_id', 'height', 'reason']);
        $query->where(function ($query) use ($ids): void {
            foreach ($ids as $source => $list) {
                $query->orWhere(fn ($query) => $query->where('source', $source)->whereIn('source_id', $list));
            }
        });

        // A match attested on several boards shows its first mined block, else its first row.
        $rows = $query->orderByRaw('height is null')->orderBy('height')->orderBy('id')->get()
            ->unique(fn (SeasonAttestation $row): string => $row->source.':'.$row->source_id);
        $mined = $rows->filter(fn (SeasonAttestation $row): bool => $row->height !== null);
        $voids = $mined->isEmpty() ? collect() : SeasonBlockVoid::query()->whereIn('season_attestation_id', $mined->pluck('id'))->get(['season_attestation_id', 'reason'])->keyBy('season_attestation_id');

        $stamps = [];

        foreach ($rows as $row) {
            $stamp = self::stamp($row, $voids->get($row->id));

            if ($stamp !== null) {
                $stamps[$row->source.':'.$row->source_id] = $stamp;
            }
        }

        return $stamps;
    }

    /**
     * @return array{state: 'mined'|'void'|'none', height: int|null, href: string|null, text: string, title: string}|null
     */
    private static function stamp(SeasonAttestation $row, ?SeasonBlockVoid $void): ?array
    {
        if ($row->height !== null) {
            $href = route('mining').'#block-'.$row->height;

            return $void === null
                ? ['state' => 'mined', 'height' => $row->height, 'href' => $href, 'text' => __('Block :height', ['height' => $row->height]), 'title' => __('Mined as block :height of the season chain', ['height' => $row->height])]
                : ['state' => 'void', 'height' => $row->height, 'href' => $href, 'text' => __('Block :height void', ['height' => $row->height]), 'title' => $void->reason];
        }

        // Attested without a candidate (a draw, a void result): nothing to mine, nothing to say.
        if ($row->reason === null) {
            return null;
        }

        return ['state' => 'none', 'height' => null, 'href' => null, 'text' => __('no block'), 'title' => __('No block: :reason', ['reason' => ChainOverview::reasonLabel($row->reason)])];
    }
}
