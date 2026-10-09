@php
    /** @var \App\Models\HyperMatch $hyperMatch */
    // A Hyperbitcoinization row (plan "Hyperbitcoinization", P6), as a board row: its cover, the first two seats by
    // place with "+N" for the rest, the round, the result. The match page is full-screen, so the row opens a new tab.
    // Only listed while its route is registered (MempoolStrip::hyper()), so route('hyper.match') always exists.
    $live = $hyperMatch->status === \App\Enums\HyperMatchStatus::Active;
    $aborted = $hyperMatch->status === \App\Enums\HyperMatchStatus::Aborted;
    $seats = \App\Support\Hyper\HyperNames::ordered($hyperMatch);
    $more = max(0, count($seats) - 2);
    $chip = \App\Support\Series\SeriesPresenter::chipFor($live ? 'live' : ($aborted ? 'closed' : 'done'), $live ? __('live') : ($aborted ? __('aborted') : __('done')));
    $gameName = \App\Support\GameNames::game(\App\Games\Hyperbitcoinization::SLUG);
    $format = ($hyperMatch->isCorrespondence() ? __('Correspondence') : __('Live')).' · '.($hyperMatch->isTeamMatch() ? intdiv(count($seats), 2).'v'.intdiv(count($seats), 2) : trans_choice(':count seat|:count seats', count($seats)));
    $mine = $hyperMatch->seatOf($viewer);
    $winner = \App\Support\Hyper\HyperNames::winner($hyperMatch);
    $resultText = match (true) {
        $live => $hyperMatch->isCorrespondence() ? __('in progress') : __('playing'),
        $aborted => __('aborted'),
        $mine !== null && $mine->place !== null => $mine->place === 1 ? __('you won') : __('place :place', ['place' => $mine->place]),
        $winner !== null => __(':name won', ['name' => $winner]),
        default => '–',
    };
    $fill = $live ? 1 : ($aborted ? 0 : 2);
    $at = $hyperMatch->ended_at ?? $hyperMatch->created_at;
@endphp

<a href="{{ route('hyper.match', $hyperMatch) }}" target="_blank" wire:key="h-{{ $hyperMatch->id }}" data-test="hyper-row" data-game="{{ \App\Games\Hyperbitcoinization::SLUG }}"
   class="tr grid min-h-11 grid-cols-[64px_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-sm px-2 py-2 text-[13px] text-ink hover:text-ink lg:h-11 lg:grid-cols-[96px_minmax(0,1fr)_88px_120px_150px_200px_120px] lg:gap-4 lg:py-0">
    {{-- No league match number: a rated match says "rated", a casual one "casual". --}}
    <span><span class="rounded-xs border border-line px-1 text-[10px] text-ink-2">{{ $hyperMatch->rated ? __('rated') : __('casual') }}</span></span>
    <span class="flex min-w-0 flex-col gap-1 whitespace-nowrap lg:flex-row lg:items-center lg:gap-2">
        @foreach (array_slice($seats, 0, 2) as $index => $seat)
            <span class="flex min-w-0 items-center gap-2">
                @if ($index === 1)<span class="text-ink-3">vs</span>@endif
                @if ($seat->user?->exists)<x-avatar :user="$seat->user" :size="20" class="rounded-xs" data-test="hyper-row-face" />@endif
                <span @class(['truncate', 'font-bold' => $seat->place === 1, 'text-ink-2' => ! $live && $seat->place !== 1])>{{ \App\Support\Hyper\HyperNames::seat($seat) }}</span>
                @if ($index === 1 && $more > 0)<span class="shrink-0 text-ink-3" data-test="hyper-row-more">+{{ $more }}</span>@endif
            </span>
        @endforeach
    </span>
    <span class="flex flex-col leading-tight"><b>{{ __('Round :round', ['round' => (int) ($hyperMatch->state['round'] ?? 1)]) }}</b></span>
    <span class="col-span-2 flex items-center gap-1.5 text-ink-2 max-lg:col-start-2 max-lg:text-xs lg:col-span-1"><x-game-cover :game="\App\Games\Hyperbitcoinization::SLUG" size="thumb" class="w-8 rounded-xs" :title="$gameName" data-test="match-row-cover" /><span class="sr-only">{{ $gameName }}</span><span class="truncate">{{ $format }}</span></span>
    <span class="max-lg:hidden">
        <span class="inline-flex h-[26px] items-center gap-1.5 rounded-sm px-2.5 text-xs font-bold" style="background: {{ $chip['bg'] }}; color: {{ $chip['color'] }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $chip['icon'] }}"></path></svg>{{ $chip['label'] }}
        </span>
    </span>
    <span class="relative block h-6 overflow-hidden rounded-sm bg-raised max-lg:col-span-3">
        <span class="absolute inset-y-0 left-0" style="width: {{ $fill * 50 }}%; background: {{ $fill === 2 ? '#1F4D2E' : '#7A4A0E' }}"></span>
        <span class="relative flex h-6 items-center justify-center truncate px-2 text-xs font-bold">{{ $resultText }}</span>
    </span>
    <span class="text-right text-ink-2 max-lg:hidden">{{ $at?->diffForHumans() }}</span>
</a>
