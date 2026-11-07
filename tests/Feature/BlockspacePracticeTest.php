<?php

use App\Support\Board\BlockspacePractice;
use App\Support\Board\BlockspaceRules;
use Tests\Support\BlockspaceOn;

/*
| The Blockspace tutorial: the page opens for guests while the game is on, every lesson is a position of the server's
| rules, every goal can be reached in the browser's engine, and the lobby leads there.
*/

test('the tutorial is open to guests while Blockspace is on', function () {
    BlockspaceOn::play();

    $this->get(route('board.blockspace-practice'))->assertOk()->assertSee('Blockspace');
});

test('the lobby offers the tutorial', function () {
    BlockspaceOn::play();

    $this->get(route('board.lobby', 'blockspace'))->assertOk()->assertSee(route('board.blockspace-practice'));
});

test('every lesson is a position of the rules, with White to move', function () {
    $rules = new BlockspaceRules;

    expect(BlockspacePractice::LESSONS)->toHaveCount(9);

    foreach (BlockspacePractice::LESSONS as $lesson) {
        $position = $rules->deserialize(BlockspacePractice::position($lesson));

        expect($rules->turn($position))->toBe('w')
            ->and($rules->outcome($position, []))->toBeNull()
            ->and($rules->legalMoves($position))->not->toBeEmpty();
    }
});

test('every lesson reaches its goal in the browser\'s engine', function () {
    $lessons = array_map(fn (array $lesson): array => [...$lesson, 'position' => BlockspacePractice::position($lesson)], BlockspacePractice::LESSONS);
    $file = tempnam(sys_get_temp_dir(), 'lessons');
    file_put_contents($file, json_encode($lessons));

    nodeTest('tests/js/blockspace/lessons.check.mjs', 9, 60, ['BLOCKSPACE_LESSONS' => $file]);
    unlink($file);
});
