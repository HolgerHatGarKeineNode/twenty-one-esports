{{--
    One match on the TV bracket (P19): both sides with face, name and
    score. `data-sig` changes with the status, the result and who stands in
    it; the TV script compares it after every update to animate a new result
    (score slam, winner line, loser dimmed) and a side arriving from the box
    before. Winner and loser differ in weight, a check mark and brightness,
    never in colour alone.

    $box: TournamentTv::stages() box
--}}
@php
    $done = $box['status'] === 'done';
    $winnerKnown = $done && collect($box['sides'])->contains(fn (array $side): bool => $side['won']);
@endphp
<article @class(['tv-box', 'is-done' => $done, 'is-live' => $box['live'], 'is-waiting' => ! $done && ! $box['live'], 'is-skipped' => $box['status'] === 'skipped'])
         wire:key="tv-box-{{ $box['key'] }}" data-key="{{ $box['key'] }}" data-sig="{{ $box['sig'] }}" data-status="{{ $box['status'] }}"
         data-from="{{ implode(',', array_map(fn (?string $key): string => (string) $key, $box['from'])) }}" data-test="tv-box" @if ($box['live']) data-live @endif>
    @foreach ($box['sides'] as $index => $side)
        <div @class(['tv-side', 'is-won' => $side['won'], 'is-lost' => $winnerKnown && ! $side['won'], 'is-open' => ! $side['known']])
             data-side="{{ $index }}" data-pid="{{ $box['ids'][$index] ?? '' }}">
            @include('pages.tournaments.partials.tv-face', ['entry' => $side['entry']])
            <span class="tv-side-name">{{ $side['name'] }}</span>
            @if ($side['won'])
                <x-icon name="check" :size="16" class="tv-side-mark" />
            @endif
            @if ($side['score'] !== null)
                <span class="tv-side-score">{{ $side['score'] }}</span>
            @endif
        </div>
    @endforeach
    @if ($box['live'])
        <span class="tv-box-live"><span class="tv-dot"></span>{{ __('Live') }}</span>
    @endif
</article>
