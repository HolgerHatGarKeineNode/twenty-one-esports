@props(['tournament'])

{{--
    A tournament's start on a card or in a list row: <x-league-time>, or while
    its start is put to a vote "Start by vote" (TournamentStartVote::cardStart()),
    never the earliest offered start as if it were set.
--}}
@if ($tournament->hasOpenStartVote())
    <span {{ $attributes }} data-start-vote>{{ __('Start by vote') }}</span>
@else
    <x-league-time :at="$tournament->starts_at" {{ $attributes }} />
@endif
