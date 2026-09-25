<?php

namespace App\Support\TwentyOne\Stream;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Scene data → SVG (resources/views/stream/scene.blade.php) → PNG bytes.
 *
 * `rsvg-convert` reads the SVG on stdin and writes the PNG to stdout, with a
 * private fontconfig that knows only the committed brand fonts
 * (resources/fonts/stream), so the server needs no font installed and renders
 * like any other machine. The child gets no secrets from the environment.
 * The last SVG and its PNG are kept: a second with nothing changed costs no
 * render.
 */
final class SceneRenderer
{
    private ?string $lastSvg = null;

    private ?string $lastPng = null;

    public function __construct(
        private string $rsvgConvert,
        private string $fontsDir,
        private string $workDir,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('twentyone.stream.scene.rsvg_convert'),
            (string) config('twentyone.stream.scene.fonts_dir'),
            (string) config('twentyone.stream.scene.work_dir'),
        );
    }

    /**
     * @param  array<string, mixed>  $scene  the view's data contract
     */
    public function svg(array $scene): string
    {
        return view('stream.scene', $scene)->render();
    }

    /**
     * @param  array<string, mixed>  $scene
     *
     * @throws RuntimeException when rsvg-convert fails
     */
    public function png(array $scene): string
    {
        $svg = $this->svg($scene);

        if ($svg === $this->lastSvg && $this->lastPng !== null) {
            return $this->lastPng;
        }

        $result = Process::timeout(10)
            ->env([...ChildEnvironment::withoutSecrets(), 'FONTCONFIG_FILE' => $this->fontconfig()])
            ->input($svg)
            ->run([$this->rsvgConvert, '--format', 'png']);

        if ($result->failed() || ! str_starts_with($result->output(), "\x89PNG")) {
            throw new RuntimeException('rsvg-convert failed: '.trim(substr($result->errorOutput(), 0, 300)));
        }

        $this->lastSvg = $svg;

        return $this->lastPng = $result->output();
    }

    /**
     * Write (once) and return the private fontconfig file: only the brand
     * fonts, no /etc/fonts include, a cache of its own.
     */
    public function fontconfig(): string
    {
        $path = rtrim($this->workDir, '/').'/fonts.conf';
        $contents = '<?xml version="1.0"?><!DOCTYPE fontconfig SYSTEM "fonts.dtd"><fontconfig>'
            .'<dir>'.htmlspecialchars(realpath($this->fontsDir) ?: $this->fontsDir, ENT_XML1).'</dir>'
            .'<cachedir>'.htmlspecialchars(rtrim($this->workDir, '/').'/fontcache', ENT_XML1).'</cachedir>'
            .'</fontconfig>'."\n";

        if (! is_file($path) || file_get_contents($path) !== $contents) {
            File::ensureDirectoryExists($this->workDir);
            PlaylistWriter::writeAtomically($path, $contents);
        }

        return $path;
    }
}
