<?php

/*
 * An HTTPS picture host for the browser tests of the stream chat's custom
 * emoji (tests/Browser/LiveChatTest.php): NIP-30 emoji must be https images,
 * so a plain http test server cannot stand in for one.
 *
 * Usage: php emoji-image-server.php <dir>
 * Makes a self-signed certificate for 127.0.0.1 in <dir>, listens on
 * 127.0.0.1, prints its port, and answers every request with a 16x16 orange
 * PNG until killed (one request per connection). The browser test opens its
 * contexts with `ignoreHTTPSErrors`. Never part of the app.
 */

[, $dir] = $argv;

$config = $dir.'/openssl.cnf';
file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[san]\nsubjectAltName=IP:127.0.0.1\n");
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['config' => $config, 'digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $config, 'x509_extensions' => 'san', 'digest_alg' => 'sha256']);
openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem, null, ['config' => $config]);
file_put_contents($dir.'/server.pem', $certPem.$keyPem);

$image = imagecreatetruecolor(16, 16);
imagefill($image, 0, 0, (int) imagecolorallocate($image, 247, 147, 26));
ob_start();
imagepng($image);
$png = (string) ob_get_clean();

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create([
    'ssl' => ['local_cert' => $dir.'/server.pem', 'verify_peer' => false],
]));
echo parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT)."\n";
fflush(STDOUT);

while (true) {
    $connection = @stream_socket_accept($server, 60);

    if ($connection === false) {
        continue;
    }

    stream_set_timeout($connection, 5);

    if (@stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
        fclose($connection);

        continue;
    }

    $head = '';
    while (! str_contains($head, "\r\n\r\n") && ! feof($connection)) {
        $line = fgets($connection);
        if ($line === false) {
            break;
        }
        $head .= $line;
    }

    @fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: image/png\r\nContent-Length: ".strlen($png)."\r\nCache-Control: max-age=3600\r\nAccess-Control-Allow-Origin: *\r\nConnection: close\r\n\r\n".$png);
    fclose($connection);
}
