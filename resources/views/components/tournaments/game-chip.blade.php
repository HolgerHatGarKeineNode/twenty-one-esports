{{--
    What a tournament game counts for, in place of a player's casual rating
    on its page (user, 2026-10-03): the casual Elo is not what is at stake there.
--}}
<span {{ $attributes->class('inline-flex items-center gap-1 whitespace-nowrap text-btc-hi') }} data-test="tournament-chip"><x-icon name="trophy" :size="12" />{{ __('Counts for the tournament') }}</span>
