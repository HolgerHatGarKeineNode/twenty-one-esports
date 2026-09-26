<?php

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Browser tests only. The in-process server of pest-plugin-browser
 * (Pest\Browser\Drivers\LaravelHttpServer::handleRequest) builds the request
 * from the raw body and passes no files (`[], // @TODO files...`), so a
 * Livewire upload arrives with an empty `files` field and _finishUpload()
 * dies on `$tmpPath[0]` (measured: 500 on /livewire-…/update). PHP's own
 * multipart parsing is what this stands in for: it reads the raw
 * multipart/form-data body into the request's fields and files, and leaves
 * any request that already carries files alone.
 *
 * Registered per test as a global middleware (tests/Browser/ClanEditTest.php).
 */
final class ParseMultipartBody
{
    public function handle(Request $request, Closure $next): mixed
    {
        $contentType = (string) $request->headers->get('content-type');

        if ($request->files->count() === 0 && preg_match('/^multipart\/form-data;.*boundary="?([^";]+)"?/i', $contentType, $match) === 1) {
            $this->parse($request, (string) $request->getContent(), $match[1]);
        }

        return $next($request);
    }

    private function parse(Request $request, string $body, string $boundary): void
    {
        foreach (explode('--'.$boundary, $body) as $part) {
            $part = ltrim($part, "\r\n");

            if ($part === '' || str_starts_with($part, '--') || ! str_contains($part, "\r\n\r\n")) {
                continue;
            }

            [$head, $content] = explode("\r\n\r\n", $part, 2);
            $content = substr($content, 0, -2); // the CRLF before the next boundary

            if (preg_match('/name="([^"]*)"/i', $head, $name) !== 1) {
                continue;
            }

            $key = $name[1];
            $isList = str_ends_with($key, '[]');
            $key = $isList ? substr($key, 0, -2) : $key;

            if (preg_match('/filename="([^"]*)"/i', $head, $filename) === 1) {
                $type = preg_match('/content-type:\s*([^\r\n]+)/i', $head, $typeMatch) === 1 ? trim($typeMatch[1]) : null;
                $path = (string) tempnam(sys_get_temp_dir(), 'mp');
                file_put_contents($path, $content);
                $file = new UploadedFile($path, $filename[1], $type, UPLOAD_ERR_OK, true);

                $request->files->set($key, $isList ? [...(array) $request->files->get($key, []), $file] : $file);

                continue;
            }

            $request->request->set($key, $isList ? [...(array) $request->request->all($key), $content] : $content);
        }
    }
}
