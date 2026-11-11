{{--
    The start vote's fields (TournamentStartVote, App\Livewire\Concerns\EditsStartVote), shared by
    pages::admin.tournament-create and pages::admin.tournament-edit: deferred like every field there, shown at once.
    $testId: the block's data-test ('start-vote-create' or 'start-vote-edit').
--}}
<div class="flex flex-col gap-3" x-data="{ vote: $wire.$entangle('voteStart') }" data-test="{{ $testId }}">
    <x-ui.check :checked="$voteStart" x-bind:aria-checked="vote.toString()" x-on:click="vote = ! vote" class="self-start text-[13px]" data-test="start-vote-toggle">{{ __('Let the entries vote on the start') }}</x-ui.check>
    <div x-show="vote" @unless ($voteStart) style="display: none" @endunless class="flex flex-col gap-3" data-test="start-vote-fields">
        <p class="m-0 max-w-[80ch] text-xs leading-normal text-ink-2" data-test="start-vote-help">{{ __('Entries tick every start they can make until the vote closes, at least an hour before sign-up; the start most of them can make wins, the earlier one on a tie. An entry that does not vote counts for the earliest start.') }}</p>
        <div class="grid items-start gap-4 @min-[640px]/admin:grid-cols-2">
            {{-- The start above is the 1st; these are the 2nd to 4th. --}}
            @foreach ($startOptions as $index => $option)
                <x-berlin-datetime-input :model="'startOptions.'.$index" :label="[__('2nd start (optional)'), __('3rd start (optional)'), __('4th start (optional)')][$index] ?? __('Another possible start (optional)')" :value="$option" :test="'start-option-'.$index" />
            @endforeach
        </div>
        {{-- On its own row: the close is not one of the starts. --}}
        <div class="grid items-start gap-4 @min-[640px]/admin:grid-cols-2">
            <x-berlin-datetime-input model="voteUntil" :label="__('Vote closes')" :value="$voteUntil" test="start-vote-until" />
        </div>
    </div>
</div>
