{{--
    A lesson of Go's practice in the practice panel (components/board/practice, design pass 2026-11-14): its head (which
    lesson, the rail of the eight), what it explains, the task and the verdict, the buttons. On a phone the head, the
    task and the verdict with its next action stand above the board (partials/go-lesson-head, go-lesson-task), in view
    with it; the card under the board then holds what the lesson explains and the two quiet ways out.
--}}
<section x-cloak x-show="mode === 'learn'" aria-labelledby="lesson-h" class="flex flex-col gap-3 rounded-lg bg-card p-4" data-test="lesson">
    <div class="max-lg:hidden">@include('pages.board.partials.go-lesson-head', ['phone' => false])</div>
    <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2" x-text="lesson.text" data-test="lesson-text"></p>
    <div class="max-lg:hidden">@include('pages.board.partials.go-lesson-task', ['phone' => false])</div>
    <p class="m-0 text-[13px] text-ink-2" x-cloak x-show="lessonState === 'task' && turnLine && state.turn !== color" x-text="turnLine"></p>
    <div class="flex flex-wrap gap-2">
        <x-button class="max-lg:hidden" x-cloak x-show="lessonState === 'done'" x-on:click="nextLesson()" data-test="lesson-next"><span x-text="lessonIndex < cfg.lessons.length - 1 ? cfg.labels.nextLesson : cfg.labels.toBots"></span></x-button>
        <x-button class="max-lg:hidden" x-cloak x-show="lessonState === 'retry'" x-on:click="openLesson(lessonIndex)" data-test="lesson-retry">{{ __('Start again') }}</x-button>
        <x-button variant="quiet" x-cloak x-show="lessonState === 'done' || (lessonState === 'task' && state.ply > 0)" x-on:click="openLesson(lessonIndex)" data-test="lesson-again">{{ __('Start again') }}</x-button>
        <x-button variant="secondary" x-cloak x-show="lessonState !== 'done'" x-on:click="nextLesson()" data-test="lesson-skip">{{ __('Skip') }}</x-button>
    </div>
</section>
