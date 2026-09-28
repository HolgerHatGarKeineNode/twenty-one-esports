{{--
    The check-in of a scheduled casual 1v1 (P23 S4, CasualMatches::checkIn()),
    inside the steps of the room: open from `checkin_before_minutes` before
    the agreed start until `ready_by`. Needs $m, $mySide, $myReady,
    $otherName and $at from partials/casual-steps.
--}}
@if ($myReady)
    <p class="m-0">{{ __('You are checked in. Waiting for :name.', ['name' => $otherName]) }}</p>
@elseif ($mySide !== null)
    @if ($m->checkInOpensAt()?->isFuture())
        <p class="m-0" data-test="casual-checkin-later">{{ __('The match starts :time. The check-in opens at :open.', ['time' => $at($m->scheduledAt()), 'open' => $at($m->checkInOpensAt())]) }}</p>
    @else
        <p class="m-0">{{ __('Check in by :time, or you lose by forfeit.', ['time' => $at($m->ready_by)]) }}</p>
        {{-- The primary action of the step, as big as Ready (partials/casual-steps $big). --}}
        <div><x-button icon="check" wire:click="casualCheckIn" class="{{ $big ?? '' }}" data-test="casual-checkin">{{ __('Check in') }}</x-button></div>
    @endif
@endif
