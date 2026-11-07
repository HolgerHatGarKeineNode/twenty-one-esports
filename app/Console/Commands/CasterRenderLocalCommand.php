<?php

namespace App\Console\Commands;

use App\Models\CasterScript;
use App\Support\Caster\Blocks\BlockFetcher;
use App\Support\Caster\Blocks\BlockRenderer;
use App\Support\Caster\Blocks\DayStock;
use App\Support\Caster\Blocks\JingleLibrary;
use App\Support\Caster\Blocks\StockScripts;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\Idents;
use App\Support\Caster\Pcm;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * The workstation's own timeless-programme factory (user 2026-10-16: "ich will
 * endlich eine lokale Lösung haben!"): writes the stock programme's text with
 * the LOCAL opencode gateway (no tunnel, no server in the text's way), voices
 * it with the local VoiceStudio and uploads the finished block straight into
 * the server's stock library. Runs until stopped, next to `caster:fetch`
 * (which keeps serving the fresh scripts); one programme per pass, a short
 * wait when the writer has nothing or the library holds its target.
 */
#[Signature('caster:render-local {--once : Render at most one programme, then exit}')]
#[Description('Write, voice and upload timeless stock programmes locally (workstation)')]
class CasterRenderLocalCommand extends Command
{
    public function handle(CasterFetcher $fetcher): int
    {
        $stopping = false;
        $this->trap([SIGTERM, SIGINT], function () use (&$stopping, $fetcher): void {
            $stopping = true;
            $fetcher->stop();
        });

        $fetcher->log = fn (string $line) => $this->line(now()->toDateTimeString().' '.$line);

        do {
            // The library holds its target: nothing to render.
            if (DayStock::fromConfig()->isFull()) {
                $this->line(now()->toDateTimeString().' library full, waiting');
                $this->wait(300, $stopping);

                continue;
            }

            // One programme is one continuous text, written in a single pass (user 2026-10-16: "nicht
            // zusammenhangslos zusammenkleben - der Textgenerator muss kontextuell längere Programmtexte
            // schreiben"): the writer's prompt carries the target length; nothing is glued on.
            $talk = null;

            try {
                $talk = app(StockScripts::class)->write();
            } catch (Throwable $e) {
                $this->line(now()->toDateTimeString().' writer failed: '.mb_substr($e->getMessage(), 0, 160));
            }

            if ($talk === null) {
                $this->wait(30, $stopping);

                continue;
            }

            $batch = [[
                'id' => 0,
                'kind' => CasterScript::SCENE,
                'scene' => StockScripts::SCENE,
                'topic' => StockScripts::TOPIC_PREFIX.$talk['format'],
                'sentences' => array_values(array_filter(array_map('trim', explode("\n", $talk['text'])), fn (string $line): bool => $line !== '')),
                'speakers' => $talk['speakers'],
                'estimated_seconds' => ceil(mb_strlen($talk['text']) / 12),
            ]];

            $renderer = new BlockRenderer($fetcher, JingleLibrary::fromConfig(), Idents::fromConfig(), null, null);
            $block = $renderer->render($batch, [], CarbonImmutable::now(), evergreen: true);
            $opus = $block === null ? null : $fetcher->encode($block['pcm'], $block['rate']);
            $seconds = $block === null ? 0.0 : round(Pcm::seconds($block['pcm'], $block['rate']), 3);

            if ($block === null || $opus === null) {
                $this->line(now()->toDateTimeString().' rendering failed');

                continue;
            }

            $name = DayStock::fromConfig()->store($opus, $batch,
                ['ends' => $block['ends'], 'boundaries' => $block['boundaries'], 'steps' => $block['steps'], 'seconds' => $seconds], CarbonImmutable::now());

            if ($name === null) {
                $this->line(now()->toDateTimeString().' not kept');

                continue;
            }

            $formats = implode('+', array_unique(array_map(fn (array $script): string => $script['topic'], $batch)));
            $this->line(now()->toDateTimeString().sprintf(' programme kept: %s (%.1f s, %d talks, %s)', $name, $seconds, count($batch), $formats));

            // Straight up into the server's library (the one upload this machine makes).
            try {
                $upload = app(BlockFetcher::class)->uploadLibrary($fetcher);
                $this->line(now()->toDateTimeString().' library upload: '.(is_string($upload) ? $upload : 'done'));
            } catch (Throwable $e) {
                $this->line(now()->toDateTimeString().' library upload failed: '.mb_substr($e->getMessage(), 0, 160));
            }
        } while (! $this->option('once') && ! $stopping && ! $fetcher->stopping());

        return self::SUCCESS;
    }

    private function wait(int $seconds, bool $stopping): void
    {
        for ($waited = 0; $waited < $seconds && ! $stopping; $waited++) {
            sleep(1);
        }
    }
}
