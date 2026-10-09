@php
    /** @var \App\Models\PongMatch $pongMatch */
    // A Proof of Pong row (plan "Proof of Pong", P2): one row per finished live match, the winner alone with the score
    // (winner's points first), as the league lists every multi-side game (user 2026-10-09). The match page is
    // full-screen, so the row opens a new tab. Only listed while its route is registered (MempoolStrip::pong()).
    $winner = $pongMatch->winner;
    [$left, $right] = $pongMatch->score();
    $score = $pongMatch->winner_id === $pongMatch->right_id ? $right.':'.$left : $left.':'.$right;
    $chip = \App\Support\Series\SeriesPresenter::chipFor('done', __('done'));
    $gameName = \App\Support\GameNames::game(\App\Games\ProofOfPong::SLUG);
    $resultText = $viewer !== null && $pongMatch->winner_id === $viewer->id ? __('you won') : __(':name won', ['name' => $winner?->displayName() ?? '–']);
@endphp

<a href="{{ route('pong.match', $pongMatch) }}" target="_blank" wire:key="p-{{ $pongMatch->id }}" data-test="pong-row" data-game="{{ \App\Games\ProofOfPong::SLUG }}"
   class="tr grid min-h-11 grid-cols-[64px_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-sm px-2 py-2 text-[13px] text-ink hover:text-ink lg:h-11 lg:grid-cols-[96px_minmax(0,1fr)_88px_120px_150px_200px_120px] lg:gap-4 lg:py-0">
    {{-- No league match number: Proof of Pong's Elo is its own, so the row says "casual" like every match that mines nothing. --}}
    <span><span class="rounded-xs border border-line px-1 text-[10px] text-ink-2">{{ __('casual') }}</span></span>
    <span class="flex min-w-0 flex-col gap-1 whitespace-nowrap lg:flex-row lg:items-center lg:gap-2">
        <span class="flex min-w-0 items-center gap-2">
            @if ($winner?->exists)<x-avatar :user="$winner" :size="20" class="rounded-xs" data-test="pong-row-face" />@endif
            <span class="truncate font-bold" data-test="pong-row-winner">{{ $winner?->displayName() ?? '–' }}</span>
        </span>
    </span>
    <span class="flex flex-col leading-tight"><b data-test="pong-row-score">{{ $score }}</b></span>
    <span class="col-span-2 flex items-center gap-1.5 text-ink-2 max-lg:col-start-2 max-lg:text-xs lg:col-span-1"><x-game-cover :game="\App\Games\ProofOfPong::SLUG" size="thumb" class="w-8 rounded-xs" :title="$gameName" data-test="match-row-cover" /><span class="sr-only">{{ $gameName }}</span><span class="truncate">{{ __('Live') }} · 1v1</span></span>
    <span class="max-lg:hidden">
        <span class="inline-flex h-[26px] items-center gap-1.5 rounded-sm px-2.5 text-xs font-bold" style="background: {{ $chip['bg'] }}; color: {{ $chip['color'] }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $chip['icon'] }}"></path></svg>{{ $chip['label'] }}
        </span>
    </span>
    <span class="relative block h-6 overflow-hidden rounded-sm bg-raised max-lg:col-span-3">
        <span class="absolute inset-y-0 left-0" style="width: 100%; background: #1F4D2E"></span>
        <span class="relative flex h-6 items-center justify-center truncate px-2 text-xs font-bold">{{ $resultText }}</span>
    </span>
    <span class="text-right text-ink-2 max-lg:hidden">{{ $pongMatch->ended_at?->diffForHumans() }}</span>
</a>
