<?php

namespace Tests\Support;

use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Context;
use Pest\Browser\Playwright\Page;
use ReflectionProperty;
use RuntimeException;

/**
 * What a page's main thread has worked, on the thread's own CPU clock instead
 * of the wall clock: Chromium's Performance counters, read through the
 * DevTools protocol with `timeDomain: threadTicks`. A thread that waits for
 * a core, or for anything else, does not advance that clock, so a budget
 * judged on it says how much work a page did, not how busy the machine was.
 *
 * Measured 2026-11-13 on GameChannelTest's 63 KB message: 250 ms spent
 * waiting inside the chat's own task (a synchronous XHR) moved the long
 * animation frame's script time from ~60 to 318 ms and `script` here from
 * 67 to 72 ms. It is not blind to a slower CPU: under
 * Emulation.setCPUThrottlingRate 4 (which spins the thread) `script` read
 * 316 ms.
 *
 * The plugin's Page has no CDP access; the Playwright protocol does
 * (BrowserContext.newCDPSession, CDPSession.send), as in BrowserThrottle.
 */
final class BrowserMainThread
{
    /** Chromium's names of the counters read, in seconds. */
    private const COUNTERS = ['script' => 'ScriptDuration', 'style' => 'RecalcStyleDuration', 'layout' => 'LayoutDuration', 'tasks' => 'TaskDuration'];

    private function __construct(private string $session) {}

    /** Starts counting on the page; spent() reads the counters from then on. */
    public static function of(Page $page): self
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

        $thread = new self($session);
        $thread->send('Performance.enable', ['timeDomain' => 'threadTicks']);

        return $thread;
    }

    /**
     * CPU milliseconds of the main thread since of(): `script` in JavaScript,
     * `style` and `layout` in style recalculation and layout, `tasks` in all
     * of its tasks together. Take it before and after, and subtract.
     *
     * @return array{script: float, style: float, layout: float, tasks: float}
     */
    public function spent(): array
    {
        $metrics = [];

        foreach ($this->send('Performance.getMetrics')['metrics'] ?? [] as $metric) {
            $metrics[(string) $metric['name']] = (float) $metric['value'];
        }

        $spent = [];

        foreach (self::COUNTERS as $name => $counter) {
            // A counter Chromium no longer reports must not read as "no work".
            if (! array_key_exists($counter, $metrics)) {
                throw new RuntimeException("Chromium reports no {$counter} for the page.");
            }

            $spent[$name] = round($metrics[$counter] * 1000, 1);
        }

        return $spent;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function send(string $method, array $params = []): array
    {
        $result = [];

        foreach (Client::instance()->execute($this->session, 'send', ['method' => $method, 'params' => (object) $params]) as $message) {
            if (is_array($message['result']['result'] ?? null)) {
                $result = $message['result']['result'];
            }
        }

        return $result;
    }
}
