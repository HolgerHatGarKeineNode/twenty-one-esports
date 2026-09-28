{{--
    The viewer's own open matches of a running tournament whose automatic
    decision runs (P18, slice 5): what the match waits for, the countdown
    to the league's decision and what the viewer has to do, with the way to
    the match. `$waits`: list<App\Support\Tournaments\MatchWait>.
--}}
@php
    $viewerId = auth()->id();
@endphp
<section aria-labelledby="my-waits-h" class="mx-4 flex flex-col gap-3 rounded-card bg-card px-4 py-4 shadow-[inset_0_0_0_1px_#F7931A] lg:mx-12 lg:px-6" data-test="my-waits">
    <h2 id="my-waits-h" class="sr-only">{{ __('Your match') }}</h2>
    @foreach ($waits as $wait)
        @php
            $mine = $wait->waitsOn((int) $viewerId);
        @endphp
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-6" wire:key="my-wait-{{ $wait->matchId }}" data-test="my-wait" data-state="{{ $wait->state }}">
            <div class="flex min-w-0 flex-col gap-1">
                <span class="text-[15px] font-bold [overflow-wrap:anywhere]">{{ __('Your match :label: :a vs :b', ['label' => $wait->label, 'a' => $wait->sides[0], 'b' => $wait->sides[1]]) }}</span>
                <x-tournaments.auto-decision :wait="$wait" class="text-[13px] text-ink" />
                @if ($mine && $wait->action !== null)
                    <span class="text-[13px] text-ink-2" data-test="my-wait-action">{{ $wait->actionText() }}</span>
                @elseif ($wait->waitingOn !== [])
                    <span class="text-[13px] text-ink-2">{{ __('Waiting on :names.', ['names' => implode(', ', array_column($wait->waitingOn, 'name'))]) }}</span>
                @endif
            </div>
            @if ($wait->url !== route('tournaments.show', $tournament))
                <x-button :href="$wait->url" class="shrink-0 self-start sm:self-center" data-test="my-wait-open">{{ __('Open match') }}</x-button>
            @endif
        </div>
    @endforeach
</section>
