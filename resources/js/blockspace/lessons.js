/*
 * What a Blockspace lesson asks for (App\\Support\\Board\\BlockspacePractice): the verdict on the player's move, apart from
 * the page, so tests/js/blockspace/lessons.check.mjs can hold every lesson to its goal.
 */
import { C, cellOf, fee, moves } from './engine.js';

/** Whether a lesson's goal is met by this move, played from `before` and leaving `after` (the goals of BlockspacePractice). */
export function lessonVerdict(lesson, before, after, move, placed) {
    if (lesson.goal === 'pass') return move === 'pass' ? 'done' : 'retry';
    if (move === 'pass') return 'retry';
    const [slot, names] = move.split(':');
    const cells = names.split(',').map(cellOf);
    if (lesson.goal === 'slot') return Number(slot) === lesson.slot ? 'done' : 'retry';
    if (lesson.goal === 'fee') {
        const best = Math.max(...moves(before, before.side).map((option) => option.fee));

        return fee(before, Number(slot)) === best ? 'done' : 'retry';
    }
    if (lesson.goal === 'touch') {
        const near = (cell) => [cell - C, cell + C, cell % C ? cell - 1 : -1, cell % C < C - 1 ? cell + 1 : -1];

        return cells.some((cell) => near(cell).some((other) => after.board[other] === 2)) ? 'done' : 'retry';
    }

    return placed >= (lesson.count ?? 1) ? 'done' : 'going';
}
