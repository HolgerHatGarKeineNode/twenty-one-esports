@php
    /** @var \App\Models\BoardGame $boardGame */
    // A board game row (plan "Mempool-Streifen", P2), as a chess row. Only listed while its route is registered
    // (MempoolStrip::boardSlugs()), so route('board.show') always exists.
    $live = $boardGame->status === \App\Enums\BoardGameStatus::Active;
    $aborted = $boardGame->status === \App\Enums\BoardGameStatus::Aborted;
    $winner = match ($boardGame->result) { '1-0' => 'w', '0-1' => 'b', default => null };
    $chip = \App\Support\Series\SeriesPresenter::chipFor($live ? 'live' : ($aborted ? 'closed' : 'done'), $live ? __('live') : ($aborted ? __('aborted') : __('done')));
    $gameName = \App\Support\GameNames::game($boardGame->game);
    $format = $gameName.' '.($boardGame->isCorrespondence() ? __('Correspondence') : \App\Support\GameNames::mode($boardGame->game, $boardGame->mode));
    $at = $boardGame->ended_at ?? $boardGame->created_at;
@endphp

{{-- A rated game carries its league match number; a casual one has none (Art says "casual"). --}}
<x-mempool.row :href="route('board.show', $boardGame)" wire:key="b-{{ $boardGame->id }}" data-test="board-row" data-game="{{ $boardGame->game }}"
               :number="$boardGame->number !== null || $boardGame->series_match_id !== null ? $boardGame->number() : null" :art="$boardGame->rated ? __('rated') : __('casual')" :format="$format" :chip="$chip"
               :when="$at?->diffForHumans()" :score="$boardGame->result ? str_replace(['1/2', '-'], ['½', '–'], $boardGame->result) : '–'">
    <x-slot:sides>
        <span class="flex min-w-0 items-center gap-2">
            @if ($boardGame->white?->exists)<x-avatar :user="$boardGame->white" :size="20" class="rounded-xs max-lg:hidden" data-test="board-row-face" />@endif
            <span @class(['truncate', 'font-bold' => $winner === 'w', 'text-ink-2' => $winner === 'b'])>{{ $boardGame->white?->displayName() ?? __('Deleted player') }}</span>
        </span>
        <span class="text-ink-3">vs</span>
        <span class="flex min-w-0 items-center gap-2">
            @if ($boardGame->black?->exists)<x-avatar :user="$boardGame->black" :size="20" class="rounded-xs max-lg:hidden" data-test="board-row-face" />@endif
            <span @class(['truncate', 'font-bold' => $winner === 'b', 'text-ink-2' => $winner === 'w'])>{{ $boardGame->black?->displayName() ?? __('Deleted player') }}</span>
        </span>
    </x-slot:sides>
</x-mempool.row>
