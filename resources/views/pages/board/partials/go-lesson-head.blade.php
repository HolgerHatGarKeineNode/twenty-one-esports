{{--
    The head of a lesson of Go's practice (pages/board/⚡go-practice, design pass 2026-11-14; before it eight numbered
    squares that wrapped into two rows under the board on a phone): which lesson this is, as a button of 44 px that
    opens the list of all eight by name, and the rail under it: a strip of the board's wood with a point for every
    lesson. A black stone lies on each lesson solved (the player is Black), a light ring marks the lesson shown, and the
    stone of a lesson just solved settles onto its point (go/board.css, R17; not with reduced motion). The rail only
    shows: eight targets of 44 px do not fit into 288 px, so the list is how a lesson is picked.

    Twice in the page (components/board/practice): above the board on a phone (`$phone`), in the lesson's card from lg.
--}}
@php($suffix = $phone ? '-phone' : '')

<div class="relative flex min-w-0 flex-col gap-1.5" x-data="{ open: false }" x-on:keydown.escape="if (open) { open = false; $refs.lessons.focus(); }" x-on:click.outside="open = false" data-test="lesson-head{{ $suffix }}">
    <h2 class="m-0 text-[13px] lg:text-[15px]" @if (! $phone) id="lesson-h" @endif>
        <button type="button" x-ref="lessons" class="btn-w flex h-11 w-full cursor-pointer items-center gap-2 rounded-[4px] border border-line bg-well px-3 text-left text-ink"
                aria-haspopup="true" :aria-expanded="open.toString()" x-on:click="open = !open" data-test="lesson-menu{{ $suffix }}">
            <span class="shrink-0 font-normal text-ink-2 tabular-nums" aria-hidden="true" x-text="(lessonIndex + 1) + '/' + cfg.lessons.length" data-test="lesson-count{{ $suffix }}"></span>
            <span class="sr-only" x-text="cfg.labels.lessonOf.replace(':n', lessonIndex + 1).replace(':total', cfg.lessons.length) + ':'"></span>
            <span class="min-w-0 grow truncate font-bold" x-text="lesson.title" data-test="lesson-title{{ $suffix }}"></span>
            <span class="sr-only" x-text="cfg.labels.solvedOf.replace(':n', solvedCount).replace(':total', cfg.lessons.length)"></span>
            <x-icon name="chevron-down" :size="14" class="shrink-0 text-ink-2" />
        </button>
    </h2>
    <ol class="absolute inset-x-0 top-12 z-30 m-0 flex list-none flex-col rounded-[4px] border border-edge bg-raised p-1 shadow-[0_16px_32px_rgba(10,10,11,.8)]" x-show="open" x-cloak data-test="lesson-list{{ $suffix }}">
        <template x-for="(item, i) in cfg.lessons" :key="item.id">
            <li>
                <button type="button" class="flex min-h-11 w-full cursor-pointer items-center gap-3 rounded-[4px] px-2 text-left text-[13px] text-ink hover:bg-row-hover" :class="i === lessonIndex ? 'bg-well font-bold' : ''"
                        :aria-current="i === lessonIndex ? 'step' : null" x-on:click="open = false; openLesson(i)" data-test="lesson-dot{{ $suffix }}">
                    <span class="w-4 shrink-0 text-right font-normal text-ink-2 tabular-nums" x-text="i + 1"></span>
                    <span class="min-w-0 grow" x-text="item.title"></span>
                    <span class="size-4 shrink-0" x-show="done.includes(item.id)" x-html="stoneGlyph('w')"></span>
                    <span class="sr-only" x-show="done.includes(item.id)">{{ __('Solved') }}</span>
                </button>
            </li>
        </template>
    </ol>
    <ol class="go-rail" :style="{ gridTemplateColumns: 'repeat(' + cfg.lessons.length + ', minmax(0, 1fr))' }" aria-hidden="true" data-test="lesson-progress{{ $suffix }}">
        <template x-for="(item, i) in cfg.lessons" :key="item.id">
            <li :data-current="i === lessonIndex ? '' : null" :data-solved="done.includes(item.id) ? '' : null">
                <span x-show="done.includes(item.id) || i === lessonIndex" :class="[done.includes(item.id) ? '' : 'is-ghost', lessonState === 'done' && i === lessonIndex ? 'go-rail-in' : '']" x-html="stoneGlyph('w')"></span>
            </li>
        </template>
    </ol>
</div>
