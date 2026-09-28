{{--
    The three first steps into the league (/me): a real sequence, so numbered.
    A step that is done carries a tick and stays readable; the next open
    step is the one orange button. A brand-new player sees this first
    ($lead), everyone else under "Needs you now" until all three are done.

    $steps: PlayerHub::steps(), $done: how many are done, $lead: bool.
--}}
@php
    $next = collect($steps)->first(fn (array $step): bool => ! $step['done']);
@endphp

<section aria-labelledby="me-steps-h" @class(['flex flex-col gap-4 rounded-card p-4 lg:p-6', 'bg-btc-chip shadow-ring-btc' => $lead, 'bg-card shadow-ring' => ! $lead]) data-test="me-steps">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 id="me-steps-h" @class(['m-0 font-display font-bold', 'text-xl lg:text-2xl' => $lead, 'text-lg lg:text-xl' => ! $lead])>{{ $lead ? __('Your first steps') : __('First steps') }}</h2>
        <span class="text-xs text-ink-2" data-test="me-steps-done">{{ __(':done of :total done', ['done' => $done, 'total' => count($steps)]) }}</span>
    </div>
    <ol class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($steps as $index => $step)
            @php($isNext = $next !== null && $next['key'] === $step['key'])
            <li class="min-w-0">
                <a href="{{ $step['href'] }}" @class([
                    'flex h-full min-h-16 items-center gap-3 rounded-card px-3 py-2.5 text-[13px] hover:text-ink lg:min-h-20',
                    'bg-btc font-bold text-on-btc hover:bg-btc-hi hover:text-on-btc' => $isNext,
                    'bg-ground text-ink shadow-ring hover:bg-row-hover' => ! $isNext && ! $step['done'],
                    'bg-ground text-ink-2 shadow-ring-hairline hover:bg-row-hover' => $step['done'],
                ]) data-test="me-step" data-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? 'true' : 'false' }}">
                    @if ($step['done'])
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-tag bg-win-tint text-win shadow-ring-win"><x-icon name="check" :size="16" /></span>
                    @else
                        <span @class(['flex size-7 shrink-0 items-center justify-center rounded-tag text-[13px] font-bold', 'bg-on-btc text-btc' => $isNext, 'bg-raised text-ink' => ! $isNext])>{{ $index + 1 }}</span>
                    @endif
                    <span class="min-w-0 break-words">{{ $step['label'] }}@if ($step['done'])<span class="sr-only">, {{ __('done') }}</span>@endif</span>
                </a>
            </li>
        @endforeach
    </ol>
</section>
