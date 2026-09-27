<?php

namespace Tests\Support;

use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Context;
use Pest\Browser\Playwright\Page;
use ReflectionProperty;
use RuntimeException;

/**
 * Slows a page's CPU down through the Chrome DevTools protocol
 * (Emulation.setCPUThrottlingRate), so a test meets the timing of a busy
 * phone or a loaded CI machine on every run instead of by chance. The
 * plugin's Page has no CDP access; the Playwright protocol does
 * (BrowserContext.newCDPSession, CDPSession.send).
 */
final class BrowserThrottle
{
    public static function cpu(Page $page, float $rate): void
    {
        $contextGuid = (new ReflectionProperty(Context::class, 'guid'))->getValue($page->context());
        $pageGuid = (new ReflectionProperty(Page::class, 'guid'))->getValue($page);
        $session = null;

        foreach (Client::instance()->execute($contextGuid, 'newCDPSession', ['page' => ['guid' => $pageGuid]]) as $message) {
            $session ??= $message['result']['session']['guid'] ?? null;
        }

        if (! is_string($session)) {
            throw new RuntimeException('No CDP session for the page.');
        }

        foreach (Client::instance()->execute($session, 'send', ['method' => 'Emulation.setCPUThrottlingRate', 'params' => ['rate' => $rate]]) as $message) {
            // Drained: the call returns once Chromium applied the rate.
        }
    }
}
