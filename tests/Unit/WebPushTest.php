<?php

use App\Support\Notifications\WebPush;

/*
 * Web Push is composed from OpenSSL primitives here (no library without the
 * user's approval), so it is held to the RFC's own numbers byte for byte.
 */
test('message encryption reproduces the example of RFC 8291, byte for byte', function () {
    $key = fn (string $base64url) => WebPush::base64UrlDecode($base64url);

    // RFC 8291, Section 5 and Appendix A (public test values, not secrets).
    $body = WebPush::encrypt(
        'When I grow up, I want to be a watermelon',
        $key('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
        $key('BTBZMqHH6r4Tts7J_aSIgg'),
        $key('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
        $key('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'),
        $key('DGv6ra1nlYgDCS1FRnbzlw'),
    );

    expect(WebPush::base64UrlEncode($body))->toBe(
        'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN'
    );
});

test('the VAPID header is an ES256 JWT for the push service origin, verifiable with the public key', function () {
    [$public, $private] = WebPush::generateKeyPair();
    $push = new WebPush(WebPush::base64UrlEncode($public), WebPush::base64UrlEncode($private), 'mailto:ops@esports.test');

    $header = $push->vapidHeader('https://fcm.googleapis.com/fcm/send/abc:123', 1_900_000_000);
    expect($header)->toStartWith('vapid t=')->toEndWith(', k='.WebPush::base64UrlEncode($public));

    [$jwtHeader, $claims, $signature] = explode('.', substr($header, 8, strpos($header, ',') - 8));
    $raw = WebPush::base64UrlDecode($signature);

    // Back to DER for OpenSSL: SEQUENCE { INTEGER r, INTEGER s }.
    $integer = fn (string $bytes) => ($bytes = ltrim($bytes, "\x00")) === '' ? "\x02\x01\x00" : "\x02".chr(strlen(ord($bytes[0]) > 0x7F ? "\x00".$bytes : $bytes)).(ord($bytes[0]) > 0x7F ? "\x00".$bytes : $bytes);
    $sequence = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));
    $der = "\x30".chr(strlen($sequence)).$sequence;
    $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$public), 64, "\n")."-----END PUBLIC KEY-----\n";

    expect(strlen($raw))->toBe(64)
        ->and(json_decode(WebPush::base64UrlDecode($jwtHeader), true))->toBe(['typ' => 'JWT', 'alg' => 'ES256'])
        ->and(json_decode(WebPush::base64UrlDecode($claims), true))->toBe(['aud' => 'https://fcm.googleapis.com', 'exp' => 1_900_000_000, 'sub' => 'mailto:ops@esports.test'])
        ->and(openssl_verify($jwtHeader.'.'.$claims, $der, $pem, OPENSSL_ALGO_SHA256))->toBe(1);
});

test('an ECDSA signature with a short r and a sign-padded s becomes 64 raw bytes', function () {
    // r = 1 (one byte in DER), s = 0x80…01 (DER prepends 0x00 because the high bit is set).
    $s = "\x80".str_repeat("\x00", 30)."\x01";
    $der = "\x30\x26"."\x02\x01\x01"."\x02\x21\x00".$s;

    expect(bin2hex(WebPush::derToRaw($der)))->toBe(str_repeat('00', 31).'01'.bin2hex($s));
});
