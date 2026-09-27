<?php

/**
 * Local Nostr posting bridge for the promo kit.
 *
 * Run from the repo root:
 *   php -S 127.0.0.1:8919 docs/promo/src/nostr-bridge.php
 *
 * Serves ONLY on localhost. Boots the Laravel app to reuse the project signer
 * (TWENTYONE_NOSTR_NSEC from .env — the secret never appears in output, logs
 * or any committed file). Endpoints:
 *
 *   GET  /status                  -> { npub, relays, blossom }
 *   POST /publish {content, image?} -> signs kind 1 (with optional Blossom
 *                                      image upload), publishes to the
 *                                      configured public relays, returns
 *                                      per-relay results.
 *
 * No app code is modified; this file is a promo tool only.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$REPO = dirname(__DIR__, 3);
$PROMO = dirname(__DIR__);

function json_response(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    json_response(204, []);
}

// Bind guard: loopback only.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (! in_array($remote, ['127.0.0.1', '::1'], true)) {
    json_response(403, ['error' => 'loopback only']);
}

require $REPO.'/vendor/autoload.php';
$app = require $REPO.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Support\Nostr\NostrKeys;
use App\Support\TwentyOne\RelayPublisher;
use App\Support\TwentyOne\TwentyOneSigner;
use Illuminate\Contracts\Console\Kernel;
use swentel\nostr\Event\Event;

$BLOSSOM_SERVERS = ['https://blossom.einundzwanzig.space', 'https://blossom.primal.net'];

/**
 * Upload a file to a Blossom server, trying servers in order.
 * Auth: kind 24242 event (expiration, type, t=upload, x=sha256), signed by the
 * project key. Returns the public blob URL or null.
 */
function blossom_upload(TwentyOneSigner $signer, string $file, array $servers): ?string
{
    $mime = mime_content_type($file) ?: 'application/octet-stream';
    $bytes = file_get_contents($file);
    if ($bytes === false) {
        return null;
    }
    $hash = hash('sha256', $bytes);

    $event = new Event;
    $event->setKind(24242);
    $event->setContent('');
    $event->setTags([
        ['expiration', (string) (time() + 60)],
        ['type', $mime],
        ['t', 'upload'],
        ['x', $hash],
    ]);
    $event->setCreatedAt(time());
    $signed = $signer->sign($event);
    $auth = base64_encode(json_encode($signed));

    foreach ($servers as $server) {
        $ctx = stream_context_create(['http' => [
            'method' => 'PUT',
            'header' => "Content-Type: {$mime}\r\nAuthorization: Nostr {$auth}\r\nContent-Length: ".strlen($bytes)."\r\n",
            'content' => $bytes,
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        $res = @file_get_contents($server.'/upload', false, $ctx);
        $status = $http_response_header[0] ?? '';
        if ($res !== false && str_contains($status, '200')) {
            $body = json_decode($res, true);
            if (is_array($body) && isset($body['url']) && is_string($body['url'])) {
                return $body['url'];
            }
        }
    }

    return null;
}

try {
    $signer = TwentyOneSigner::fromConfig();
} catch (RuntimeException $e) {
    json_response(500, ['error' => 'signer unavailable: '.$e->getMessage()]);
}

$npub = NostrKeys::hexToNpub($signer->pubkey);
$relays = RelayPublisher::relayUrls(config('twentyone.relays.public'));

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($uri === '/status' || $uri === '/status/')) {
    json_response(200, [
        'npub' => $npub,
        'relays' => $relays,
        'blossom' => $BLOSSOM_SERVERS,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($uri === '/upload' || $uri === '/upload/')) {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (! is_array($data) || empty($data['image']) || ! is_string($data['image'])) {
        json_response(400, ['error' => 'image required']);
    }
    $rel = str_replace(['..', "\0"], '', $data['image']); // stays inside docs/promo
    $file = $PROMO.'/'.ltrim($rel, '/');
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (! is_file($file) || ! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'mp4'], true)) {
        json_response(400, ['error' => 'image not found or unsupported: '.$rel]);
    }
    $url = blossom_upload($signer, $file, $BLOSSOM_SERVERS);
    if ($url === null) {
        json_response(502, ['error' => 'blossom upload failed on all servers']);
    }
    json_response(200, ['url' => $url]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($uri === '/publish' || $uri === '/publish/')) {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (! is_array($data) || ! isset($data['content']) || ! is_string($data['content']) || trim($data['content']) === '') {
        json_response(400, ['error' => 'content required']);
    }
    $content = trim($data['content']);
    if (mb_strlen($content) > 5000) {
        json_response(400, ['error' => 'content too long (max 5000 chars)']);
    }

    $tags = [
        ['client', 'twentyone-promo-gallery'],
        ['alt', mb_substr(trim((string) preg_replace('/\s+/u', ' ', $content)), 0, 220)],
    ];
    $imageUrl = null;

    if (! empty($data['image']) && is_string($data['image'])) {
        $rel = str_replace(['..', "\0"], '', $data['image']); // stays inside docs/promo
        $file = $PROMO.'/'.ltrim($rel, '/');
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (! is_file($file) || ! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'mp4'], true)) {
            json_response(400, ['error' => 'image not found or unsupported: '.$rel]);
        }
        $imageUrl = blossom_upload($signer, $file, $BLOSSOM_SERVERS);
        if ($imageUrl === null) {
            json_response(502, ['error' => 'blossom upload failed on all servers']);
        }
        $tags[] = ['url', $imageUrl];
        [$w, $h] = getimagesize($file) ?: [0, 0];
        if ($w && $h) {
            $tags[] = ['imeta', "url {$imageUrl} dim {$w}x{$h}"];
        }
    }

    $event = new Event;
    $event->setKind(1);
    $event->setContent($content);
    $event->setTags($tags);
    $event->setCreatedAt(time());

    $signed = $signer->sign($event);

    $results = [];
    $publisher = new RelayPublisher;
    foreach ($publisher->publish($signed, $relays, (int) config('twentyone.nostr.publish_timeout_seconds', 5)) as $result) {
        $results[] = ['relay' => $result->relay, 'accepted' => $result->accepted, 'message' => $result->message];
    }

    json_response(200, [
        'id' => $signed['id'] ?? null,
        'npub' => $npub,
        'image' => $imageUrl,
        'relays' => $results,
        'accepted' => count(array_filter($results, fn ($r) => $r['accepted'])) > 0,
    ]);
}

json_response(404, ['error' => 'not found', 'endpoints' => ['GET /status', 'POST /publish']]);
