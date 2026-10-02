<?php

namespace App\Console\Commands;

use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Support\Tmnf\GbxException;
use App\Support\Tmnf\TmnfConnector;
use App\Support\Tmnf\TmnfListener;
use App\Support\TwentyOne\Stream\Backoff;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The league's listener on its TMNF dedicated server (plan "Trackmania und
 * Restposten", P1), a long-running process (supervisor/Forge daemon, one at a
 * time): connects over XML-RPC, logs in, enables callbacks and hands every
 * callback to TmnfListener, which turns a finish into a run and a chat code
 * into a linked login.
 *
 * - Reconnects with a backoff (`esports.tmnf.listener`): from
 *   backoff_initial_seconds doubling to backoff_max_seconds while the server
 *   is away; back to the start once a session ran.
 * - Each callback on its own: one that fails is reported, the next is read.
 * - Stops on SIGTERM/SIGINT after the callback at hand; `--seconds` stops
 *   after that long, `--attempts` after that many connections (tests, a
 *   manual check).
 * - Off (`ESPORTS_TMNF`), it says so and exits at once.
 */
#[Signature('tmnf:listen
    {--seconds=0 : Stop after this many seconds (0: run until stopped)}
    {--attempts=0 : Stop after this many connection attempts (0: no limit)}')]
#[Description('Listen to the TMNF dedicated server: finishes become runs, chat codes link logins')]
class TmnfListenCommand extends Command
{
    private bool $stopping = false;

    public function handle(TmnfConnector $connector, TmnfListener $listener): int
    {
        if (! app(GameRegistry::class)->find(TrackmaniaNationsForever::SLUG) instanceof TrackmaniaNationsForever) {
            $this->warn('TMNF is switched off (ESPORTS_TMNF): nothing to listen to.');

            return self::SUCCESS;
        }

        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
        });

        $seconds = max(0, (int) $this->option('seconds'));
        $deadline = $seconds > 0 ? microtime(true) + $seconds : null;
        $backoff = new Backoff((int) config('esports.tmnf.listener.backoff_initial_seconds', 1), (int) config('esports.tmnf.listener.backoff_max_seconds', 60));
        $attempts = max(0, (int) $this->option('attempts'));
        $made = 0;
        $over = fn (): bool => $this->stopping || ($deadline !== null && microtime(true) >= $deadline);

        while (! $over() && ($attempts === 0 || $made < $attempts)) {
            $made++;

            try {
                $server = $connector->open();
                $track = $listener->start($server);
                $backoff->reset();
                $this->info("Connected to the TMNF server, track {$track->name}.");

                while (! $over()) {
                    foreach ($server->callbacks(1.0) as $callback) {
                        try {
                            $line = $listener->handle($callback, $server);

                            if ($line !== null) {
                                $this->line($line);
                            }
                        } catch (GbxException $e) {
                            throw $e;
                        } catch (Throwable $e) {
                            // One callback that fails is reported; the next one is read.
                            report($e);
                            $this->error("{$callback->method} failed: {$e->getMessage()}");
                        }
                    }
                }

                $server->close();
            } catch (GbxException $e) {
                $wait = $backoff->next();
                $this->warn("TMNF server unavailable ({$e->getMessage()}); trying again in {$wait} s.");

                if (! $over() && ($attempts === 0 || $made < $attempts)) {
                    Sleep::for($deadline === null ? $wait : min($wait, max(0, (int) ceil($deadline - microtime(true)))))->seconds();
                }
            }
        }

        return self::SUCCESS;
    }
}
