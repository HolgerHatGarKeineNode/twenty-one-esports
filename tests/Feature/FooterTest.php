<?php

/*
 * The footer links the public source code on every page, desktop and mobile row.
 */
test('the footer links the open source repository in a new tab', function () {
    $html = $this->get(route('rules'))->assertOk()->getContent();

    expect(substr_count($html, 'href="https://github.com/HolgerHatGarKeineNode/twenty-one-esports" target="_blank" rel="noopener"'))->toBe(2)
        ->and($html)->toContain('Open source');
});
