<?php

/*
 * A one-connection HTTPS picture server for the ImageFetcher transport tests.
 *
 * Usage: php fake-https-image.php <mode> <host> <dir>
 * Makes a self-signed certificate for <host> (written to <dir>/ca.pem for the
 * client), listens on 127.0.0.1, prints its port, and accepts ONE connection
 * within 15 s. Every accepted TCP connection is noted in <dir>/connections.
 *   image:   answers a 1x1 PNG as image/png
 *   big:     answers a 3 MB image/png without a Content-Length
 *   trickle: answers the headers of a 1000-byte image/png, then one byte every
 *            2 s (gives up after 20 s)
 */

[, $mode, $host, $dir] = $argv;

$config = $dir.'/openssl.cnf';
file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[san]\nsubjectAltName=DNS:{$host}\nbasicConstraints=CA:TRUE\n");
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => $host], $key, ['config' => $config, 'digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $config, 'x509_extensions' => 'san', 'digest_alg' => 'sha256']);
openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem, null, ['config' => $config]);
file_put_contents($dir.'/ca.pem', $certPem);
file_put_contents($dir.'/server.pem', $certPem.$keyPem);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create([
    'ssl' => ['local_cert' => $dir.'/server.pem', 'verify_peer' => false],
]));
echo parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT)."\n";
fflush(STDOUT);

$client = @stream_socket_accept($server, 15);

if ($client === false) {
    exit(0);
}

file_put_contents($dir.'/connections', "connected\n", FILE_APPEND);

if (! @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
    exit(0);
}

stream_set_timeout($client, 5);
$request = '';

while (! str_contains($request, "\r\n\r\n") && ! feof($client)) {
    $request .= (string) fread($client, 8192);
}

if ($mode === 'image') {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: image/png\r\nContent-Length: ".strlen($png)."\r\nConnection: close\r\n\r\n".$png);
} elseif ($mode === 'big') {
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: image/png\r\nConnection: close\r\n\r\n");

    for ($i = 0; $i < 48 && @fwrite($client, str_repeat('x', 65536)) !== false; $i++) {
        fflush($client);
    }
} else {
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: image/png\r\nContent-Length: 1000\r\nConnection: close\r\n\r\n");
    $until = microtime(true) + 20;

    while (microtime(true) < $until && @fwrite($client, 'x') !== false) {
        fflush($client);
        sleep(2);
    }
}

fclose($client);
