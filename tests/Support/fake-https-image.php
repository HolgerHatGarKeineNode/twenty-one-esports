<?php

/*
 * A small HTTPS picture server for the ImageFetcher transport tests.
 *
 * Usage: php fake-https-image.php <answers> <hosts> <dir>
 * Makes a self-signed certificate for the comma-separated <hosts> (written to
 * <dir>/ca.pem for the client), listens on 127.0.0.1 and prints its port.
 * <answers> is a "|"-separated list: one answer per connection, in order;
 * each connection is awaited up to 15 s, and the server ends after the last.
 * Every accepted TCP connection appends "<Host header> <path>" to
 * <dir>/connections (the TLS handshake failing appends "-").
 *   image:        answers a 1x1 PNG as image/png
 *   big:<bytes>:  answers <bytes> bytes as image/png without a Content-Length
 *   trickle:      answers the headers of a 1000-byte image/png, then one byte
 *                 every 2 s (gives up after 20 s)
 *   gzip2:<mb>:   a gzip bomb (P47 re-audit N1): <mb> MB of zeros, gzipped
 *                 twice, sent as `Content-Encoding: gzip, gzip` (JSON for a
 *                 `.well-known` path, else image/png); a few hundred bytes
 *                 on the wire, <mb> MB once a client inflates it
 *   lnurl:<host>: a LUD-06 payRequest whose callback is https://<host>:{port}/cb
 *   redirect:<seconds>:<status>:<location>: waits <seconds>, then answers
 *                 <status> with that Location ("{port}" is this server's port)
 */

[, $answers, $hosts, $dir] = $argv;

$names = array_map(fn (string $host): string => 'DNS:'.$host, explode(',', $hosts));
$config = $dir.'/openssl.cnf';
file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[san]\nsubjectAltName=".implode(',', $names)."\nbasicConstraints=CA:TRUE\n");
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => explode(',', $hosts)[0]], $key, ['config' => $config, 'digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $config, 'x509_extensions' => 'san', 'digest_alg' => 'sha256']);
openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem, null, ['config' => $config]);
file_put_contents($dir.'/ca.pem', $certPem);
file_put_contents($dir.'/server.pem', $certPem.$keyPem);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create([
    'ssl' => ['local_cert' => $dir.'/server.pem', 'verify_peer' => false],
]));
$port = (int) parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT);
echo $port."\n";
fflush(STDOUT);

foreach (explode('|', $answers) as $answer) {
    $client = @stream_socket_accept($server, 15);

    if ($client === false) {
        exit(0);
    }

    if (! @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        file_put_contents($dir.'/connections', "-\n", FILE_APPEND);
        fclose($client);

        continue;
    }

    stream_set_timeout($client, 5);
    $request = '';

    while (! str_contains($request, "\r\n\r\n") && ! feof($client)) {
        $request .= (string) fread($client, 8192);
    }

    $path = explode(' ', strtok($request, "\r\n") ?: '')[1] ?? '?';
    $host = preg_match('/^Host:\s*(\S+)/mi', $request, $match) === 1 ? $match[1] : '?';
    file_put_contents($dir.'/connections', $host.' '.$path."\n", FILE_APPEND);

    if ($answer === 'image') {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: image/png\r\nContent-Length: ".strlen($png)."\r\nConnection: close\r\n\r\n".$png);
    } elseif (str_starts_with($answer, 'big:')) {
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: image/png\r\nConnection: close\r\n\r\n");

        for ($left = (int) substr($answer, 4); $left > 0 && @fwrite($client, str_repeat('x', min(65536, $left))) !== false; $left -= 65536) {
            fflush($client);
        }
    } elseif (str_starts_with($answer, 'lnurl:')) {
        $json = (string) json_encode(['tag' => 'payRequest', 'callback' => 'https://'.substr($answer, 6).':'.$port.'/cb', 'minSendable' => 1000, 'maxSendable' => 100_000_000,
            'metadata' => '[["text/plain","tip"]]', 'allowsNostr' => true, 'nostrPubkey' => str_repeat('ab', 32)], JSON_UNESCAPED_SLASHES);
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($json)."\r\nConnection: close\r\n\r\n".$json);
    } elseif (str_starts_with($answer, 'gzip2:')) {
        // The inner gzip is built in steps, so the server never holds the inflated size.
        $inner = deflate_init(ZLIB_ENCODING_GZIP);
        $packed = '';
        $chunk = str_repeat("\0", 1024 * 1024);

        for ($mb = (int) substr($answer, 6); $mb > 0; $mb--) {
            $packed .= deflate_add($inner, $chunk, ZLIB_NO_FLUSH);
        }

        $packed .= deflate_add($inner, '', ZLIB_FINISH);
        $wire = (string) gzencode($packed);
        $type = str_contains($path, '.well-known') ? 'application/json' : 'image/png';
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: {$type}\r\nContent-Encoding: gzip, gzip\r\nContent-Length: ".strlen($wire)."\r\nConnection: close\r\n\r\n".$wire);
    } elseif (str_starts_with($answer, 'redirect:')) {
        [, $seconds, $status, $location] = explode(':', $answer, 4);
        usleep((int) ((float) $seconds * 1_000_000));
        fwrite($client, "HTTP/1.1 {$status} Found\r\nLocation: ".str_replace('{port}', (string) $port, $location)."\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    } else {
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: image/png\r\nContent-Length: 1000\r\nConnection: close\r\n\r\n");
        $until = microtime(true) + 20;

        while (microtime(true) < $until && @fwrite($client, 'x') !== false) {
            fflush($client);
            sleep(2);
        }
    }

    fclose($client);
}
