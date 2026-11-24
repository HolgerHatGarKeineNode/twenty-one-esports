@props(['players' => [], 'viewer' => null])

{{--
    After a finished game (plan "Season vs. Casual", P5): the opponent list grows where people play. For each human
    opponent the signed-in player gets the existing add, accept or "mutual" state (livewire:opponent-button, inline,
    with its profile-standard warning, D17): "Add :name as opponent", "Accept :name's request" when they listed the
    player already, or "Mutual opponents, rated next time". Nothing is added on its own (the list is the sybil guard).

    `players`: the opponents of the game (users or null; a bot seat or a deleted account is null and left out, as is
    the viewer). `viewer`: another viewer than the signed-in one (tests only). Renders nothing for a guest, a
    spectator (the caller passes no players), without a human opponent, or before opponent lists open.

    "Rated next time" only for a viewer who meets every other step of the path (design-lead review, finding 2): a
    viewer below the trust minimum or the profile standard reads what is missing ("You still need: Trust rank 0/50",
    to the opponents page's #trust) under the heading "Your opponent list", and the mutual state says "You list each
    other" (SeasonPath::missing()). The same for an opponent the gate would refuse (third review, finding 2): below the
    trust minimum or barred, their line reads ":name is not Trusted yet" (no rank number of another player) and their
    mutual state "You list each other"; "Rated next time?" heads the card only while one opponent could play rated.
--}}
@php
    $viewer ??= auth()->user();
    $opponents = $viewer instanceof \App\Models\User && \App\Support\SeasonChain\OpponentLists::forLeague() !== null
        ? collect($players)->filter(fn ($player): bool => $player instanceof \App\Models\User && $player->exists && $player->id !== $viewer->id && filled($player->pubkey))->unique('id')->values()
        : collect();
    $path = app(\App\Support\SeasonChain\SeasonPath::class);
    $missing = $opponents->isNotEmpty() ? $path->missing($viewer) : null;
    // Per opponent: what the pair gate refuses on their side (null: rated next time once the two list each other).
    $blocked = $missing === null ? $opponents->mapWithKeys(fn (\App\Models\User $opponent): array => [$opponent->id => $path->opponentMissing($viewer, $opponent)])->filter()->all() : [];
    $ratedNext = $missing === null && count($blocked) < $opponents->count();
@endphp

@if ($opponents->isNotEmpty())
    <section aria-labelledby="after-game-opponents-h" {{ $attributes->class('flex min-w-0 flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6') }} data-test="after-game-opponents" data-rated-next="{{ $ratedNext ? 'true' : 'false' }}">
        <span class="flex min-w-0 flex-col gap-1">
            <h2 id="after-game-opponents-h" class="m-0 font-display text-base leading-[1.25] font-bold">{{ $ratedNext ? __('Rated next time?') : __('Your opponent list') }}</h2>
            @if ($ratedNext)
                <span class="max-w-[68ch] text-[13px] leading-normal text-ink-2">{{ __(':season counts only between players who list each other as opponents.', ['season' => \App\Support\Badges\BadgeCopy::season(\App\Support\Series\Ladders::season() ?? 'pre-season')]) }}</span>
            @elseif ($missing !== null && $missing['href'] !== null)
                <a href="{{ $missing['href'] }}" class="inline-flex min-h-11 max-w-[68ch] items-center gap-2 self-start text-[13px] leading-normal text-ink underline decoration-edge underline-offset-4 hover:decoration-btc" data-test="after-game-missing" data-key="{{ $missing['key'] }}">
                    <x-icon name="alert" :size="16" class="shrink-0 text-loss" /><span class="min-w-0">{{ $missing['text'] }}</span>
                </a>
            @elseif ($missing !== null)
                <span class="flex max-w-[68ch] items-start gap-2 text-[13px] leading-normal text-ink-2" data-test="after-game-missing" data-key="{{ $missing['key'] }}">
                    <x-icon name="clock" :size="16" class="mt-0.5 shrink-0" /><span class="min-w-0">{{ $missing['text'] }}</span>
                </span>
            @endif
            @foreach ($blocked as $opponentMissing)
                <span class="flex max-w-[68ch] items-start gap-2 text-[13px] leading-normal text-ink-2" data-test="after-game-missing" data-key="{{ $opponentMissing['key'] }}">
                    <x-icon name="clock" :size="16" class="mt-0.5 shrink-0" /><span class="min-w-0">{{ $opponentMissing['text'] }}</span>
                </span>
            @endforeach
        </span>
        @foreach ($opponents as $opponent)
            <livewire:opponent-button :player="$opponent" :inline="true" :after-game="true" :rated-next="$missing === null && ! isset($blocked[$opponent->id])" :key="'after-game-'.$opponent->id" />
        @endforeach
    </section>
@endif
