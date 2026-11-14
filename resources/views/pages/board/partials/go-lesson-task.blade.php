{{--
    What a lesson of Go's practice asks, and the verdict on the player's stone (design pass 2026-11-14): one box with an
    edge in the colour of what it is. The task in the league's orange (the player's action), with the reason once a
    forbidden point was tried; a wrong stone in red with one sentence that says why; a solved lesson in green, and it
    waits there for a click (R17: nothing moves on by itself). The mark before the verdict says the same without the
    colour. Above the board on a phone (`$phone`), with the next action under it so both keep the first screen; in the
    lesson's card from lg, where the card's own row holds the buttons.
--}}
@php($suffix = $phone ? '-phone' : '')

<div class="flex min-w-0 flex-col gap-2" data-test="lesson-say{{ $suffix }}">
    <p class="m-0 rounded-[4px] border border-btc-ring bg-feature py-2 pr-3 pl-4 text-[13px] leading-[18px] font-bold text-btc-hi shadow-[inset_3px_0_0_var(--color-btc)]"
       x-cloak x-show="lessonState === 'task'" x-text="lesson.task" data-test="lesson-task{{ $suffix }}"></p>
    <p class="m-0 text-[13px] leading-[18px] text-ink" x-cloak x-show="triedClosing && lessonState === 'task' && lesson.closingNote" x-text="lesson.closingNote" data-test="lesson-closing{{ $suffix }}"></p>
    <div role="status" aria-live="polite">
        <p class="go-verdict m-0 flex gap-2 rounded-[4px] border px-3 py-2 text-[13px] leading-[18px] text-ink" x-cloak x-show="lessonState !== 'task'"
           :class="lessonState === 'done' ? 'border-win-ring bg-win-tint shadow-[inset_3px_0_0_var(--color-win)]' : 'border-[#5A2A2E] bg-loss-tint shadow-[inset_3px_0_0_var(--color-loss)]'" data-test="lesson-verdict{{ $suffix }}">
            <svg viewBox="0 0 12 12" class="mt-[3px] size-3 shrink-0" :class="lessonState === 'done' ? 'text-win' : 'text-loss'" aria-hidden="true">
                <path x-show="lessonState === 'done'" d="M2 6.4 4.8 9 10 3.2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path x-show="lessonState !== 'done'" d="M3 3l6 6M9 3l-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <span class="min-w-0" x-text="lessonNote" data-test="lesson-note{{ $suffix }}"></span>
        </p>
    </div>
    @if ($phone)
        <x-button class="w-full whitespace-nowrap" x-cloak x-show="lessonState === 'done'" x-on:click="nextLesson()" data-test="lesson-next-phone"><span x-text="lessonIndex < cfg.lessons.length - 1 ? cfg.labels.nextLesson : cfg.labels.toBots"></span></x-button>
        <x-button class="w-full whitespace-nowrap" x-cloak x-show="lessonState === 'retry'" x-on:click="openLesson(lessonIndex)" data-test="lesson-again-phone">{{ __('Start again') }}</x-button>
    @endif
</div>
