<?php

namespace Tests\Support;

use Amp\Websocket\Client\WebsocketConnection;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Playwright;
use Pest\Browser\ServerManager;
use ReflectionProperty;

use function Amp\Websocket\Client\connect;

/**
 * Software WebGL for the browser tests of ONE file (plan "Proof of Pong", P3: the three.js arena has to be measured,
 * not its 2D fallback). The suite's Chromium has no WebGL: headless without a GPU, and scripts/link-host-chromium.sh
 * launches it with --disable-gpu --disable-software-rasterizer, which kill SwiftShader as well.
 *
 * Pest's Playwright server launches one browser per connection, with the launch options of the connection's URL,
 * refuses to launch another on it and drops launch `args`; a `channel` it passes on. So on() closes Pest's connection
 * (and its browser) and opens a new one asking for CHANNEL, which scripts/link-host-chromium.sh points at the host's
 * Chromium with SwiftShader's switches (Playwright 1.65 dropped the old chromium-tip-of-tree channel). The next visit() initialises Playwright on the new connection. off() swaps
 * back to a plain connection, so a file that runs later in the same process gets the browser it always had. The file using it also runs in pest
 * processes of its own in scripts/test-browser.sh (split entries after two shards that end early), so the normal run never shares a process with it.
 *
 * Use: `beforeEach(fn () => BrowserWebGL::on())` and `afterAll(fn () => BrowserWebGL::off())`.
 */
final class BrowserWebGL
{
    /**
     * Playwright's alias for its full "chromium" executable. scripts/link-host-chromium.sh shims that one with
     * SwiftShader's switches (the headless shell, which a connection without a channel gets, keeps the GPU-off ones).
     */
    public const CHANNEL = 'chrome-for-testing';

    private static bool $on = false;

    public static function on(): void
    {
        if (! self::$on) {
            self::reconnect(self::CHANNEL);
            self::$on = true;
        }
    }

    public static function off(): void
    {
        if (self::$on) {
            self::reconnect(null);
            self::$on = false;
        }
    }

    private static function reconnect(?string $channel): void
    {
        $client = Client::instance();
        $connection = new ReflectionProperty($client, 'websocketConnection');
        $old = $connection->getValue($client);

        Playwright::close();

        if ($old instanceof WebsocketConnection) {
            $old->close();
        }

        $options = ['headless' => Playwright::isHeadless(), 'ignoreHTTPSErrors' => true, 'bypassCSP' => true];

        if ($channel !== null) {
            $options['channel'] = $channel;
        }

        $browser = Playwright::defaultBrowserType()->toPlaywrightName();
        $url = ServerManager::instance()->playwright()->url();
        $connection->setValue($client, connect("ws://{$url}?browser={$browser}&launch-options=".rawurlencode((string) json_encode($options))));
    }
}
