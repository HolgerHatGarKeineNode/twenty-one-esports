<?php

use Amp\CancelledException;
use Amp\Socket;
use Amp\TimeoutCancellation;
use Pest\Browser\ServerManager;

use function Amp\async;

/*
| The browser tests' in-process server must send a 204 as headers alone (see
| tests/Support/BrowserBodylessFraming.php: amphp frames a length-less 204 as
| chunked and writes its closing "0\r\n\r\n" in a second write, which
| Chromium then reads as the start of the NEXT response on the reused socket
| and fails that fetch with net::ERR_INVALID_HTTP_RESPONSE). Read here over a
| raw socket, because a browser test would only fail now and then, when the
| second write happens to land behind the page's next request.
*/

/**
 * One request over a raw keep-alive socket to the plugin's server: the head the
 * server answered with, whatever followed it in the same read, and whatever
 * arrived within `$quietSeconds` afterwards.
 *
 * @return array{head: string, rest: string, late: string}
 */
function wireAnswer(string $request, float $quietSeconds = 0.3): array
{
    $http = ServerManager::instance()->http();
    $http->bootstrap();

    return async(function () use ($http, $request, $quietSeconds): array {
        $socket = Socket\connect("127.0.0.1:{$http->port}");
        $socket->write(str_replace('{host}', "127.0.0.1:{$http->port}", $request));

        $buffer = '';
        while (! str_contains($buffer, "\r\n\r\n")) {
            $chunk = $socket->read(new TimeoutCancellation(5));
            expect($chunk)->not->toBeNull('the server closed the socket before it finished its answer');
            $buffer .= $chunk;
        }
        [$head, $rest] = explode("\r\n\r\n", $buffer, 2);

        $late = '';
        try {
            while (($chunk = $socket->read(new TimeoutCancellation($quietSeconds))) !== null) {
                $late .= $chunk;
            }
        } catch (CancelledException) {
            // quiet: nothing more came, which is what a bodyless answer looks like
        }
        $socket->close();

        return ['head' => $head, 'rest' => $rest, 'late' => $late];
    })->await();
}

test('a 204 goes over the wire as headers alone, with no chunked terminator behind it', function () {
    // A guest's presence ping is answered 204 (OnSitePingController); same-origin by its Sec-Fetch-Site header.
    $answer = wireAnswer("POST /presence/ping HTTP/1.1\r\nHost: {host}\r\nSec-Fetch-Site: same-origin\r\nContent-Length: 0\r\nConnection: keep-alive\r\n\r\n");

    expect($answer['head'])->toStartWith('HTTP/1.1 204')
        ->and(strtolower($answer['head']))->not->toContain('transfer-encoding')
        ->and(strtolower($answer['head']))->toContain('content-length: 0')
        ->and($answer['rest'])->toBe('')
        ->and($answer['late'])->toBe('');
});

test('a 200 with a body keeps its own length and is untouched by the 204 rule', function () {
    // Negative control: the rule is about the status, not about every response.
    $answer = wireAnswer("GET /up HTTP/1.1\r\nHost: {host}\r\nConnection: keep-alive\r\n\r\n");

    expect($answer['head'])->toStartWith('HTTP/1.1 200')
        ->and(strtolower($answer['head']))->not->toContain('content-length: 0');
});
