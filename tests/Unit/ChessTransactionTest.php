<?php

use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\ChessTransaction;
use Tests\TestCase;

// The app (for the DB facade) without RefreshDatabase: its wrapping
// transaction would make this one nested, and a nested one never retries.
pest()->extend(TestCase::class);

/**
 * A callback that loses a Postgres deadlock `$losses` times, then succeeds.
 *
 * @return array{0: Closure(): string, 1: Closure(): int}
 */
function deadlocking(int $losses): array
{
    $calls = 0;

    return [
        function () use (&$calls, $losses): string {
            if (++$calls <= $losses) {
                throw new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected');
            }

            return 'paired';
        },
        function () use (&$calls): int {
            return $calls;
        },
    ];
}

test('a transaction that loses a deadlock is run again', function () {
    [$callback, $calls] = deadlocking(ChessTransaction::ATTEMPTS - 1);

    expect(ChessTransaction::run($callback))->toBe('paired')
        ->and($calls())->toBe(ChessTransaction::ATTEMPTS);
});

test('a transaction that loses every attempt becomes the lost_race rule violation', function () {
    [$callback, $calls] = deadlocking(ChessTransaction::ATTEMPTS);

    expect(fn () => ChessTransaction::run($callback))
        ->toThrow(fn (ChessRuleViolation $violation) => expect($violation->reason)->toBe('lost_race'));
    expect($calls())->toBe(ChessTransaction::ATTEMPTS);
});

test('any other failure is thrown unchanged and not retried', function () {
    $calls = 0;

    expect(function () use (&$calls) {
        ChessTransaction::run(function () use (&$calls) {
            $calls++;

            throw new RuntimeException('broken');
        });
    })->toThrow(RuntimeException::class, 'broken');
    expect($calls)->toBe(1);
});
