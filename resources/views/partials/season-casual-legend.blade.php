{{--
    The one legend sentence for the two ratings (plan "Season vs. Casual", P2): the live Season by its name is rated
    and counts for the ladder and mining, Casual is for fun with an Elo of its own. Shown next to every list that
    labels its ratings "Season 1" or "Casual". $class: extra classes for the paragraph.
--}}
@php
    $liveSeason = \App\Support\Series\Ladders::season();
@endphp
<p @class(['m-0 max-w-[68ch] text-xs leading-normal text-ink-2', $class ?? '']) data-test="season-casual-legend">{{ __(':season: rated, counts for the ladder and mining. Casual: for fun, with its own Elo.', ['season' => $liveSeason === null ? __('Season') : \App\Support\Badges\BadgeCopy::season($liveSeason)]) }}</p>
