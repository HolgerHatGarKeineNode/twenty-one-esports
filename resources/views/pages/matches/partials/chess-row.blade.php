@php
    /** @var \App\Models\ChessGame $chessGame */
    $live = $chessGame->status === \App\Enums\ChessGameStatus::Active;
    $aborted = $chessGame->status === \App\Enums\ChessGameStatus::Aborted;
    $winner = match ($chessGame->result) { '1-0' => 'w', '0-1' => 'b', default => null };
    $chip = \App\Support\Series\SeriesPresenter::chipFor($live ? 'live' : ($aborted ? 'closed' : 'done'), $live ? __('live') : ($aborted ? __('aborted') : __('done')));
    $format = $chessGame->isCorrespondence()
        ? __('Daily chess')
        : __('Blitz :minutes+:seconds', ['minutes' => intdiv($chessGame->initial_ms, 60_000), 'seconds' => intdiv($chessGame->increment_ms, 1_000)]);
    $mine = $viewer !== null ? $chessGame->colorOf($viewer) : null;
    $resultText = match (true) {
        $live => $chessGame->isCorrespondence() ? __('in progress') : __('playing'),
        $aborted => __('aborted'),
        $chessGame->result === '1/2-1/2' => __('draw'),
        $mine !== null && $winner !== null => $winner === $mine ? __('you won') : __('you lost'),
        $winner === 'w' => __('White won'),
        $winner === 'b' => __('Black won'),
        default => '–',
    };
    $fill = $live ? 1 : ($aborted ? 0 : 2);
    $at = $chessGame->ended_at ?? $chessGame->created_at;
@endphp

<a href="{{ route('games.show', $chessGame) }}" wire:key="c-{{ $chessGame->id }}" data-test="chess-row"
   class="tr grid min-h-11 grid-cols-[64px_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-sm px-2 py-2 text-[13px] text-ink hover:text-ink lg:h-11 lg:grid-cols-[96px_minmax(0,1fr)_88px_120px_150px_200px_120px] lg:gap-4 lg:py-0">
    <span class="font-bold text-btc">{{ $chessGame->number() }}</span>
    <span class="flex min-w-0 items-center gap-2 whitespace-nowrap">
        <span @class(['truncate', 'font-bold' => $winner === 'w', 'text-ink-2' => $winner === 'b'])>{{ $chessGame->white?->displayName() }}</span>
        <span class="text-ink-3">vs</span>
        <span @class(['truncate', 'font-bold' => $winner === 'b', 'text-ink-2' => $winner === 'w'])>{{ $chessGame->black?->displayName() }}</span>
    </span>
    <span class="flex flex-col leading-tight"><b>{{ $chessGame->result ? str_replace(['1/2', '-'], ['½', '–'], $chessGame->result) : '–' }}</b></span>
    <span class="col-span-2 flex items-center gap-1.5 text-ink-2 max-lg:col-start-2 max-lg:text-xs lg:col-span-1"><x-icon name="chess" :size="14" />{{ $format }}</span>
    <span class="max-lg:hidden">
        <span class="inline-flex h-[26px] items-center gap-1.5 rounded-sm px-2.5 text-xs font-bold" style="background: {{ $chip['bg'] }}; color: {{ $chip['color'] }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $chip['icon'] }}"></path></svg>{{ $chip['label'] }}
        </span>
    </span>
    <span class="relative block h-6 overflow-hidden rounded-sm bg-raised max-lg:col-span-3">
        <span class="absolute inset-y-0 left-0" style="width: {{ $fill * 50 }}%; background: {{ $fill === 2 ? '#1F4D2E' : '#7A4A0E' }}"></span>
        <span class="relative flex h-6 items-center justify-center text-xs font-bold">{{ $resultText }}</span>
    </span>
    <span class="text-right text-ink-2 max-lg:hidden">{{ $at?->diffForHumans() }}</span>
</a>
