@props(['moment', 'chip' => true])

{{--
    One proud moment of a clan (App\Support\Clans\ClanPride) in words, with
    its icon: a tournament place, a won series (or a won Hyperbitcoinization
    clan match) with the other side's tag,
    a streak, the wins of the week, new players. The caller sets size and
    colour; the icon inherits the colour. `chip`: false drops the other
    side's tag chip (the spotlight headline names the side in words).
--}}
@php
    $type = $moment['type'];
    $place = (int) ($moment['place'] ?? 0);
    $player = $moment['player'] ?? null;
    $count = (int) ($moment['count'] ?? 0);

    $text = match ($type) {
        'tournament' => $player === null
            ? [1 => __('Won :tournament', ['tournament' => $moment['tournament']]), 2 => __('Second in :tournament', ['tournament' => $moment['tournament']]), 3 => __('Third in :tournament', ['tournament' => $moment['tournament']])][$place]
            : [1 => __(':player won :tournament', ['player' => $player, 'tournament' => $moment['tournament']]), 2 => __(':player came second in :tournament', ['player' => $player, 'tournament' => $moment['tournament']]), 3 => __(':player came third in :tournament', ['player' => $player, 'tournament' => $moment['tournament']])][$place],
        'series' => __('Beat :opponent :score in :mode', ['opponent' => $moment['opponent'], 'score' => $moment['score'], 'mode' => $moment['mode']]),
        'hyper' => __('Beat :opponent in Hyperbitcoinization :size', ['opponent' => $moment['opponent'], 'size' => $moment['size']]),
        'streak' => __(':player won :count games in a row', ['player' => $player, 'count' => $count]),
        'wins' => trans_choice(':count win this week|:count wins this week', $count),
        'joined' => trans_choice(':count player joined this week|:count players joined this week', $count),
        default => trans_choice('Founded this week, :count player already|Founded this week, :count players already', $count),
    };
    $icon = ['tournament' => 'trophy', 'series' => 'award', 'hyper' => 'award', 'streak' => 'bolt', 'wins' => 'check', 'joined' => 'user'][$type] ?? 'clans';
@endphp

<span {{ $attributes->class('flex min-w-0 items-start gap-2') }} data-test="clan-moment" data-moment="{{ $type }}">
    <x-icon :name="$icon" :size="16" class="mt-0.5 shrink-0" />
    <span class="min-w-0 break-words">@if (in_array($type, ['series', 'hyper'], true) && $chip)<x-clan-tag :tag="$moment['opponent_tag']" size="sm" class="mr-1.5 align-[-3px]" />@endif{{ $text }}</span>
</span>
