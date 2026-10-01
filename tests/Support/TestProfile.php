<?php

/*
|--------------------------------------------------------------------------
| Per-test timings, on demand
|--------------------------------------------------------------------------
|
| TEST_PROFILE_FILE=/tmp/profile.jsonl vendor/bin/pest --parallel
| php scripts/test-profile.php /tmp/profile.jsonl
|
| appends one JSON line per finished test: the end time, the worker's pid, the
| class and the test name. Without the variable this file does nothing.
|
| Why not --profile or --log-junit: --profile does not run with --parallel, and
| the junit log of the whole suite cannot be merged (a test puts the character
| U+FFFF into a message: "PCDATA invalid Char value 65535"). The analyser takes
| a test's duration as the gap since the previous test ended in the same worker,
| so setUp (migrations, factories) and tearDown count, which a timer around the
| test body would miss.
*/

if (getenv('TEST_PROFILE_FILE') && ! isset($GLOBALS['__test_profile'])) {
    $GLOBALS['__test_profile'] = true;

    pest()->afterEach(function (): void {
        file_put_contents(
            (string) getenv('TEST_PROFILE_FILE'),
            json_encode(['e' => microtime(true), 'p' => getmypid(), 'f' => $this::class, 'n' => $this->name()], JSON_INVALID_UTF8_SUBSTITUTE)."\n",
            FILE_APPEND | LOCK_EX,
        );
    });
}
