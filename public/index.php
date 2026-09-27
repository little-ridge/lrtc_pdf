<?php

declare(strict_types=1);

use PdfApi\Api;
use PdfApi\HttpException;
use PdfApi\Presets;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$requestId = requestId();
header('X-Request-Id: ' . $requestId);
header('Cache-Control: no-store');

set_exception_handler(static function (Throwable $e) use ($requestId): void {
    if ($e instanceof HttpException) {
        sendException($e, $requestId);
        return;
    }
    error_log($e::class . ': ' . $e->getMessage());
    respondJson([
        'error' => 'internal',
        'message' => 'Internal error',
        'request_id' => $requestId,
        'details' => null,
    ], 500);
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path) || $path === '') {
    $path = '/';
}
if (strlen($path) > 1 && str_ends_with($path, '/')) {
    $path = substr($path, 0, -1);
}

try {
    if ($method === 'GET' && $path === '/health') {
        respondJson(['ok' => true], 200);
    }
    if (!str_starts_with($path, '/v1/')) {
        throw new HttpException(404, 'not_found', 'Not found');
    }
    $raw = null;
    if ($method === 'POST' || $method === 'PUT') {
        $raw = readRawBody();
    }
    $response = Api::fromEnvironment()->dispatch(
        $method,
        $path,
        $raw,
        $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    );
    foreach ($response->headers as $name => $value) {
        header($name . ': ' . $value);
    }
    if ($response->pdf !== null) {
        http_response_code($response->status);
        if (!isset($response->headers['Content-Type'])) {
            header('Content-Type: application/pdf');
        }
        echo $response->pdf;
        exit;
    }
    respondJson($response->json ?? [], $response->status);
} catch (HttpException $e) {
    sendException($e, $requestId);
}

function readRawBody(): string
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > Presets::maxBodyBytes()) {
        throw new HttpException(413, 'payload_too_large', 'Request body exceeds the size limit', [
            'max_bytes' => Presets::maxBodyBytes(),
        ]);
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > Presets::maxBodyBytes()) {
        throw new HttpException(413, 'payload_too_large', 'Request body exceeds the size limit', [
            'max_bytes' => Presets::maxBodyBytes(),
        ]);
    }

    return $raw;
}

function sendException(HttpException $e, string $requestId): never
{
    foreach ($e->headers as $name => $value) {
        header($name . ': ' . $value);
    }
    respondJson([
        'error' => $e->error,
        'message' => $e->getMessage(),
        'request_id' => $requestId,
        'details' => $e->details,
    ], $e->status);
}

/**
 * @param array<string, mixed> $payload
 */
function respondJson(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function requestId(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}
