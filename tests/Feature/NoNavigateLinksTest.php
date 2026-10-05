<?php

/*
| No wire:navigate links until the page entries survive a navigate swap (performance plan P6). A page entry
| (resources/js/push.js, chess.js, matchRoom.js …) registers its Alpine components on alpine:init, which a
| wire:navigate swap does not fire again: on 2026-10-05 the settings tab Account → Notifications left the
| "Reminders by browser push" toggle dead (7 console errors, "pushToggle is not defined").
*/

test('no view links with wire:navigate while page entries register on alpine:init only', function () {
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)) as $file) {
        foreach (file($file->getPathname()) ?: [] as $number => $line) {
            if (preg_match('/<a\b[^>]*\swire:navigate\b/', $line) === 1) {
                $found[] = str_replace(resource_path('views').'/', '', $file->getPathname()).':'.($number + 1);
            }
        }
    }

    expect($found)->toBe([]);
});
