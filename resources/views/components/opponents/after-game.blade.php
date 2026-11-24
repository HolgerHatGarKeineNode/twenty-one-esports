@props(['players' => [], 'viewer' => null])

{{--
    After a finished game (plan "Season vs. Casual", P5): the opponent list grows where people play. For each human
    opponent the signed-in player gets the existing add, accept or "mutual" state (livewire:opponent-button, inline,
    with its profile-standard warning, D17): "Add :name as opponent", "Accept :name's request" when they listed the
    player already, or "Mutual opponents, rated next time". Nothing is added on its own (the list is the sybil guard).

    `players`: the opponents of the game (users or null; a bot seat or a deleted account is null and left out, as is
    the viewer). `viewer`: another viewer than the signed-in one (tests only). Renders nothing for a guest, a
    spectator (the caller passes no players), without a human opponent, or before opponent lists open.
--}}
@php
    $viewer ??= auth()->user();
    $opponents = $viewer instanceof \App\Models\User && \App\Support\SeasonChain\OpponentLists::forLeague() !== null
        ? collect($players)->filter(fn ($player): bool => $player instanceof \App\Models\User && $player->exists && $player->id !== $viewer->id && filled($player->pubkey))->unique('id')->values()
        : collect();
@endphp

@if ($opponents->isNotEmpty())
    <section aria-labelledby="after-game-opponents-h" {{ $attributes->class('flex min-w-0 flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6') }} data-test="after-game-opponents">
        <span class="flex min-w-0 flex-col gap-1">
            <h2 id="after-game-opponents-h" class="m-0 font-display text-base leading-[1.25] font-bold">{{ __('Rated next time?') }}</h2>
            <span class="max-w-[68ch] text-[13px] leading-normal text-ink-2">{{ __(':season counts only between players who list each other as opponents.', ['season' => \App\Support\Badges\BadgeCopy::season(\App\Support\Series\Ladders::season() ?? 'pre-season')]) }}</span>
        </span>
        @foreach ($opponents as $opponent)
            <livewire:opponent-button :player="$opponent" :inline="true" :after-game="true" :key="'after-game-'.$opponent->id" />
        @endforeach
    </section>
@endif
