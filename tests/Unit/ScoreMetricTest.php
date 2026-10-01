<?php

/*
| The metric of a score game (plan "AoE2 und Trackmania", P4): which way is
| better, how a value reads, and how a typed value is read back.
*/

use App\Games\ScoreDemo;
use App\Games\ScoreMetric;

test('a time is better lower, a score higher', function () {
    expect(ScoreMetric::time()->isBetter(59_000, 61_000))->toBeTrue()
        ->and(ScoreMetric::time()->isBetter(61_000, 59_000))->toBeFalse()
        ->and(ScoreMetric::points()->isBetter(61_000, 59_000))->toBeTrue()
        ->and(ScoreMetric::points()->compare(5, 5))->toBe(0)
        ->and(new ScoreMetric('ms', ScoreMetric::HIGHER))->lowerIsBetter()->toBeFalse();
});

test('values read as people read them, and typed values are read back', function () {
    $time = ScoreMetric::time();

    expect($time->format(83_456))->toBe('1:23.456')
        ->and($time->format(5_007))->toBe('0:05.007')
        ->and($time->format(3_723_004))->toBe('1:02:03.004')
        ->and(ScoreMetric::points()->format(1_234_567))->toBe("1\u{202F}234\u{202F}567")
        ->and($time->parse('1:23.456'))->toBe(83_456)
        ->and($time->parse('1:23,4'))->toBe(83_400)
        ->and($time->parse('59.5'))->toBe(59_500)
        ->and($time->parse('1:02:03.004'))->toBe(3_723_004)
        ->and($time->parse('83456'))->toBe(83_456)
        ->and($time->parse('1:75.000'))->toBeNull()
        ->and($time->parse('fast'))->toBeNull()
        ->and(ScoreMetric::points()->parse('1.234.567'))->toBe(1_234_567)
        ->and(ScoreMetric::points()->parse('12 345'))->toBe(12_345)
        ->and(ScoreMetric::points()->parse('-5'))->toBeNull();
});

test('an unknown unit or direction is refused', function () {
    expect(fn () => new ScoreMetric('seconds', ScoreMetric::LOWER))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ScoreMetric('ms', 'faster'))->toThrow(InvalidArgumentException::class);
});

test('the demo measures a time trial in ms and a highscore in points, and checks a reported value', function () {
    $demo = new ScoreDemo;

    expect($demo->metric($demo->modes()['time-trial']))->toEqual(ScoreMetric::time())
        ->and($demo->metric($demo->modes()['highscore']))->toEqual(ScoreMetric::points())
        ->and($demo->validateResult($demo->modes()['time-trial'], ['course' => 'demo-1', 'value' => 83_456, 'achieved_at' => 1_790_812_047]))->toBe([])
        ->and($demo->validateResult($demo->modes()['time-trial'], ['course' => 'bad course!', 'value' => -1, 'achieved_at' => 0]))->toBe(['course', 'value', 'achieved_at']);
});
